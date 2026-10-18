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
use App\Models\Demande;
use App\Models\Paiement;
use App\Models\Utilisateur;
use App\Support\Affichage\Format;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Suivi administratif des frais d'internat. Aucun paiement en ligne : le
 * stagiaire téléverse son justificatif, le gestionnaire le contrôle.
 *
 * Les frais d'internat sont MENSUELS : le montant de la campagne est dû pour chaque mois de
 * séjour, et chaque mois fait l'objet d'un paiement distinct (dossier, période « AAAA-MM »).
 * Aucune autorisation préalable : une mensualité est à payer dès qu'elle est ouverte.
 *   1. ouverture de la mensualité ........ à l'affectation du lit (premier mois), puis le 1er de chaque mois
 *   2. paiement et dépôt du justificatif .. stagiaire
 *   3. contrôle du justificatif ........... gestionnaire
 * Le chef de pôle consulte et suit. Le paiement n'est jamais une condition de l'affectation,
 * de l'arrivée ou du séjour, ni de la restauration (qui se règle à part, avec de simples rappels).
 */
class SuiviPaiements
{
    /** Mensualités qui attendent encore un paiement du stagiaire (le statut « autorisé » est l'ancien nom de « en attente »). */
    public const A_REGLER = [StatutPaiement::EnAttente, StatutPaiement::Autorise, StatutPaiement::Refuse];

    public function __construct(
        private readonly Documents $documents,
        private readonly Notifieur $notifieur,
        private readonly Journal $journal,
    ) {}

    /**
     * Ouvre la mensualité d'un mois de séjour, au montant mensuel de la campagne, à payer aussitôt.
     * Rejouable : un mois déjà ouvert n'est pas recréé.
     */
    public function ouvrirMensualite(Demande $demande, CarbonInterface $mois, bool $automatique = false): Paiement
    {
        $demande->loadMissing('campagne', 'stagiaire');

        $paiement = Paiement::firstOrCreate(
            ['demande_id' => $demande->id, 'periode' => $mois->format('Y-m')],
            ['montant' => $demande->campagne->frais ?? 0, 'statut' => StatutPaiement::EnAttente, 'genere_auto' => $automatique],
        );

        if ($paiement->wasRecentlyCreated) {
            $this->notifieur->versStagiaire(
                $demande->stagiaire,
                TypeNotification::RappelPaiement,
                'Frais d\'internat de '.$this->mois($paiement),
                "Les frais d'internat de {$this->mois($paiement)} s'élèvent à {$this->montant($paiement)}. Réglez-les puis déposez votre justificatif de paiement.",
                '/stagiaire/paiements',
                "mensualite:{$paiement->id}",
            );
        }

        return $paiement;
    }

    public function deposerRecu(Paiement $paiement, UploadedFile $fichier): Paiement
    {
        if (! in_array($paiement->statut, self::A_REGLER, true)) {
            throw new RegleMetierException(
                $paiement->statut === StatutPaiement::Valide
                    ? 'Le paiement de ce mois est déjà validé.'
                    : 'Un justificatif est déjà en cours de vérification pour ce mois.',
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
                'Justificatif de paiement à vérifier',
                "{$demande->stagiaire->nomComplet()} a déposé son justificatif pour {$this->mois($paiement)}.",
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
     * Tâche planifiée du 1er du mois : pour chaque résident hébergé, la mensualité du mois, à payer,
     * au montant mensuel de sa campagne. L'unicité (dossier, période) rend la tâche rejouable : un
     * mois déjà ouvert (le premier, ouvert à l'affectation du lit) n'est pas recréé.
     */
    public function genererMensualites(?CarbonInterface $mois = null): int
    {
        $mois ??= now();
        $creees = 0;

        Affectation::actives()
            ->with('demande.campagne', 'demande.stagiaire')
            ->chunkById(200, function ($affectations) use ($mois, &$creees) {
                foreach ($affectations as $affectation) {
                    $creees += $this->ouvrirMensualite($affectation->demande, $mois, automatique: true)->wasRecentlyCreated ? 1 : 0;
                }
            });

        if ($creees > 0) {
            $this->journal->consigner('paiement.generation_mensuelle', 'paiement', details: ['periode' => $mois->format('Y-m'), 'crees' => $creees]);
        }

        return $creees;
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
                throw RegleMetierException::conflit('Aucun justificatif en attente de vérification pour ce paiement.', 'controle_impossible');
            }

            $paiement->forceFill([
                'statut' => $decision,
                'date_validation' => now(),
                'valide_par' => $gestionnaire->id,
                'motif_refus' => $motif,
            ])->save();

            $this->journal->consigner('paiement.controle_recu', $paiement, details: [
                'decision' => $decision->value, 'motif' => $motif, 'periode' => $paiement->periode,
            ]);

            $valide = $decision === StatutPaiement::Valide;

            $this->notifieur->versStagiaire(
                $paiement->demande->stagiaire,
                TypeNotification::RappelPaiement,
                $valide ? 'Paiement validé' : 'Justificatif rejeté',
                $valide
                    ? "Votre paiement de {$this->mois($paiement)} a été validé."
                    : "Votre justificatif pour {$this->mois($paiement)} a été rejeté : {$motif}. Déposez un justificatif corrigé.",
                '/stagiaire/paiements',
            );

            return $paiement;
        });
    }

    /** « octobre 2026 » */
    private function mois(Paiement $paiement): string
    {
        return Format::mois($paiement->periode);
    }

    private function montant(Paiement $paiement): string
    {
        return Format::montant($paiement->montant);
    }
}
