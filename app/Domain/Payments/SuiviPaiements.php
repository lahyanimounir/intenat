<?php

namespace App\Domain\Payments;

use App\Domain\Audit\Journal;
use App\Domain\Notifications\Notifieur;
use App\Domain\Shared\Documents;
use App\Domain\Shared\RegleMetierException;
use App\Enums\RoleCode;
use App\Enums\StatutPaiement;
use App\Enums\TypeNotification;
use App\Models\Affectation;
use App\Models\Paiement;
use App\Models\Utilisateur;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Suivi administratif des frais d'internat. Aucun paiement en ligne : le
 * stagiaire téléverse son reçu, le gestionnaire le contrôle.
 *
 * Les frais d'internat se règlent en UNE SEULE FOIS par campagne (non mensuel).
 * Trois notions restent distinctes :
 *   1. l'autorisation de paiement ........ chef de pôle
 *   2. le dépôt du reçu .................. stagiaire
 *   3. le contrôle du reçu ............... gestionnaire
 * Le paiement n'est jamais bloquant : ni pour l'hébergement, ni pour la
 * restauration (la restauration se règle à part, avec de simples rappels).
 */
class SuiviPaiements
{
    public function __construct(
        private readonly Documents $documents,
        private readonly Notifieur $notifieur,
        private readonly Journal $journal,
    ) {}

    public function autoriser(Paiement $paiement, Utilisateur $chefDePole, ?float $montant = null): Paiement
    {
        return DB::transaction(function () use ($paiement, $chefDePole, $montant) {
            $paiement = Paiement::lockForUpdate()->findOrFail($paiement->id);

            if ($paiement->statut !== StatutPaiement::EnAttente) {
                throw RegleMetierException::conflit('Ce paiement a déjà été autorisé.', 'deja_autorise');
            }

            if ($montant !== null) {
                $paiement->montant = $montant;
            }

            $paiement->forceFill([
                'statut' => StatutPaiement::Autorise,
                'date_autorisation' => now(),
                'autorise_par' => $chefDePole->id,
            ])->save();

            $this->journal->consigner('paiement.autorisation', $paiement, details: ['montant' => (string) $paiement->montant]);

            $this->notifierAutorisation($paiement);

            return $paiement;
        });
    }

    public function deposerRecu(Paiement $paiement, UploadedFile $fichier): Paiement
    {
        if (! in_array($paiement->statut, [StatutPaiement::Autorise, StatutPaiement::Refuse], true)) {
            throw new RegleMetierException(
                $paiement->statut === StatutPaiement::EnAttente
                    ? 'Le paiement n\'a pas encore été autorisé.'
                    : 'Un reçu a déjà été déposé.',
                'depot_impossible',
            );
        }

        $stocke = $this->documents->enregistrer(
            $fichier,
            "recus/{$paiement->demande_id}",
            config('internat.documents.extensions'),
            (int) config('internat.documents.taille_max_ko'),
        );

        return DB::transaction(function () use ($paiement, $stocke) {
            $paiement = Paiement::lockForUpdate()->findOrFail($paiement->id);
            $ancien = $paiement->recu_fichier;

            $paiement->forceFill([
                'recu_fichier' => $stocke['chemin'],
                'recu_nom_original' => $stocke['nom_original'],
                'recu_mime' => $stocke['mime'],
                'statut' => StatutPaiement::RecuDepose,
                'date_depot' => now(),
                'motif_refus' => null,
            ])->save();

            DB::afterCommit(fn () => $this->documents->supprimer($ancien));

            $demande = $paiement->demande;

            $this->notifieur->versRole(
                RoleCode::Gestionnaire,
                $demande->internatId(),
                TypeNotification::RappelPaiement,
                'Reçu de paiement à contrôler',
                "{$demande->stagiaire->nomComplet()} a déposé son reçu de paiement.",
                '/paiements?statut=recu_depose',
                email: false,
            );

            return $paiement;
        });
    }

    public function validerRecu(Paiement $paiement, Utilisateur $gestionnaire): Paiement
    {
        return $this->controler($paiement, $gestionnaire, StatutPaiement::Valide, null);
    }

    public function refuserRecu(Paiement $paiement, Utilisateur $gestionnaire, string $motif): Paiement
    {
        return $this->controler($paiement, $gestionnaire, StatutPaiement::Refuse, $motif);
    }

    /**
     * Tâche planifiée mensuelle : invite chaque résident à régler sa restauration
     * du mois. Simple rappel — aucun blocage, aucun suivi de reçu de restauration.
     */
    public function rappelerRestauration(): int
    {
        $rappels = 0;

        Affectation::actives()
            ->with('demande.stagiaire')
            ->chunkById(200, function ($affectations) use (&$rappels) {
                foreach ($affectations as $affectation) {
                    $stagiaire = $affectation->demande->stagiaire;

                    $envoyee = $this->notifieur->versStagiaire(
                        $stagiaire,
                        TypeNotification::RappelPaiement,
                        'Restauration : pensez à régler le mois',
                        'N\'oubliez pas de régler vos frais de restauration pour le mois en cours.',
                        '/stagiaire/paiements',
                        'rappel_restauration:'.$affectation->id.':'.now()->format('Y-m'),
                    );
                    $rappels += $envoyee ? 1 : 0;
                }
            });

        return $rappels;
    }

    private function controler(Paiement $paiement, Utilisateur $gestionnaire, StatutPaiement $decision, ?string $motif): Paiement
    {
        return DB::transaction(function () use ($paiement, $gestionnaire, $decision, $motif) {
            $paiement = Paiement::lockForUpdate()->findOrFail($paiement->id);

            if ($paiement->statut !== StatutPaiement::RecuDepose) {
                throw RegleMetierException::conflit('Aucun reçu en attente de contrôle pour ce paiement.', 'controle_impossible');
            }

            $paiement->forceFill([
                'statut' => $decision,
                'date_validation' => now(),
                'valide_par' => $gestionnaire->id,
                'motif_refus' => $motif,
            ])->save();

            $this->journal->consigner('paiement.controle_recu', $paiement, details: [
                'decision' => $decision->value, 'motif' => $motif,
            ]);

            $valide = $decision === StatutPaiement::Valide;

            $this->notifieur->versStagiaire(
                $paiement->demande->stagiaire,
                TypeNotification::RappelPaiement,
                $valide ? 'Reçu validé' : 'Reçu refusé',
                $valide
                    ? 'Votre reçu de paiement a été validé.'
                    : "Votre reçu de paiement a été refusé : {$motif}. Déposez un nouveau reçu.",
                '/stagiaire/paiements',
            );

            return $paiement;
        });
    }

    private function notifierAutorisation(Paiement $paiement): void
    {
        $this->notifieur->versStagiaire(
            $paiement->demande->stagiaire,
            TypeNotification::RappelPaiement,
            'Autorisation de paiement disponible',
            'Votre autorisation de paiement des frais d\'internat est disponible. Réglez les frais puis déposez votre reçu.',
            '/stagiaire/paiements',
            "autorisation_paiement:{$paiement->id}",
        );
    }
}
