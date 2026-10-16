<?php

namespace App\Domain\Payments;

use App\Domain\Audit\Journal;
use App\Domain\Notifications\Notifieur;
use App\Domain\Shared\Documents;
use App\Domain\Shared\Parametres;
use App\Domain\Shared\RegleMetierException;
use App\Enums\RoleCode;
use App\Enums\StatutPaiement;
use App\Enums\TypeNotification;
use App\Models\Affectation;
use App\Models\Demande;
use App\Models\Paiement;
use App\Models\Utilisateur;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Suivi administratif des frais d'internat. Aucun paiement en ligne : le
 * stagiaire téléverse son reçu, le gestionnaire le contrôle.
 *
 * Quatre notions restent distinctes :
 *   1. l'autorisation de paiement ........ chef de pôle (ou suivi mensuel automatique)
 *   2. le dépôt du reçu .................. stagiaire
 *   3. le contrôle du reçu ............... gestionnaire
 *   4. la permission d'occuper ........... gestionnaire (portée par le dossier, voir AffectationLits)
 * Le paiement n'est pas bloquant pour l'hébergement, mais conditionne la restauration.
 */
class SuiviPaiements
{
    public function __construct(
        private readonly Documents $documents,
        private readonly Notifieur $notifieur,
        private readonly Parametres $parametres,
        private readonly Journal $journal,
    ) {}

    public function autoriser(Paiement $paiement, Utilisateur $chefDePole, ?string $periode = null, ?float $montant = null): Paiement
    {
        return DB::transaction(function () use ($paiement, $chefDePole, $periode, $montant) {
            $paiement = Paiement::lockForUpdate()->findOrFail($paiement->id);

            if ($paiement->statut !== StatutPaiement::EnAttente) {
                throw RegleMetierException::conflit('Ce paiement a déjà été autorisé.', 'deja_autorise');
            }

            if ($periode !== null && $periode !== $paiement->periode) {
                $doublon = Paiement::where('demande_id', $paiement->demande_id)->where('periode', $periode)->exists();

                if ($doublon) {
                    throw RegleMetierException::conflit("Un paiement existe déjà pour la période {$periode}.", 'periode_existante');
                }
                $paiement->periode = $periode;
            }

            if ($montant !== null) {
                $paiement->montant = $montant;
            }

            $paiement->forceFill([
                'statut' => StatutPaiement::Autorise,
                'date_autorisation' => now(),
                'autorise_par' => $chefDePole->id,
            ])->save();

            $this->journal->consigner('paiement.autorisation', $paiement, details: [
                'periode' => $paiement->periode, 'montant' => (string) $paiement->montant,
            ]);

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
                    : 'Un reçu a déjà été déposé pour cette période.',
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
                "{$demande->stagiaire->nomComplet()} a déposé son reçu pour la période {$paiement->periode}.",
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
     * Tâche planifiée du début de mois : une autorisation par résident et par
     * période. La contrainte d'unicité (dossier, période) rend la tâche rejouable.
     */
    public function genererAutorisationsMensuelles(?CarbonInterface $mois = null): int
    {
        $periode = ($mois ?? now())->format('Y-m');
        $creees = 0;

        Affectation::actives()
            ->with('demande.campagne')
            ->chunkById(200, function ($affectations) use ($periode, &$creees) {
                foreach ($affectations as $affectation) {
                    $demande = $affectation->demande;

                    $paiement = Paiement::firstOrCreate(
                        ['demande_id' => $demande->id, 'periode' => $periode],
                        [
                            'montant' => $demande->campagne->frais_mensuels ?? 0,
                            'statut' => StatutPaiement::Autorise,
                            'genere_auto' => true,
                            'date_autorisation' => now(),
                        ],
                    );

                    if ($paiement->wasRecentlyCreated) {
                        $creees++;
                        $this->notifierAutorisation($paiement);
                    }
                }
            });

        if ($creees > 0) {
            $this->journal->consigner('paiement.generation_mensuelle', 'paiement', details: ['periode' => $periode, 'crees' => $creees]);
        }

        return $creees;
    }

    /** Tâche planifiée : rappelle le reçu manquant aux résidents autorisés à payer. */
    public function rappelerRecusManquants(?CarbonInterface $mois = null): int
    {
        $periode = ($mois ?? now())->format('Y-m');
        $jourBlocage = $this->parametres->entier('restauration.jour_blocage');
        $rappels = 0;

        Paiement::where('periode', $periode)
            ->where('statut', StatutPaiement::Autorise->value)
            ->with('demande.stagiaire')
            ->chunkById(200, function ($paiements) use ($periode, $jourBlocage, &$rappels) {
                foreach ($paiements as $paiement) {
                    $envoyee = $this->notifieur->versStagiaire(
                        $paiement->demande->stagiaire,
                        TypeNotification::RappelPaiement,
                        'Reçu de paiement attendu',
                        "Déposez votre reçu de paiement pour {$periode}. À partir du {$jourBlocage} du mois, l'accès à la restauration peut être suspendu.",
                        '/stagiaire/paiements',
                        "rappel_paiement:{$paiement->id}",
                    );
                    $rappels += $envoyee ? 1 : 0;
                }
            });

        return $rappels;
    }

    /**
     * Blocage de la restauration (règle provisoire, paramétrable) : à partir du
     * jour fixé, un résident à qui une autorisation a été délivrée pour le mois
     * du repas, et dont le reçu manque, ne peut plus réserver.
     */
    public function restaurationBloquee(Demande $demande, CarbonInterface $dateRepas): bool
    {
        $critere = $this->parametres->texte('restauration.critere_blocage');

        if ($critere === 'aucun' || $dateRepas->day < $this->parametres->entier('restauration.jour_blocage')) {
            return false;
        }

        $paiement = Paiement::where('demande_id', $demande->id)->where('periode', $dateRepas->format('Y-m'))->first();

        if ($paiement === null || $paiement->statut === StatutPaiement::EnAttente) {
            return false;
        }

        return match ($critere) {
            'recu_non_valide' => $paiement->statut !== StatutPaiement::Valide,
            default => ! in_array($paiement->statut, [StatutPaiement::RecuDepose, StatutPaiement::Valide], true),
        };
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
                'decision' => $decision->value, 'motif' => $motif, 'periode' => $paiement->periode,
            ]);

            $valide = $decision === StatutPaiement::Valide;

            $this->notifieur->versStagiaire(
                $paiement->demande->stagiaire,
                TypeNotification::RappelPaiement,
                $valide ? 'Reçu validé' : 'Reçu refusé',
                $valide
                    ? "Votre reçu pour la période {$paiement->periode} a été validé."
                    : "Votre reçu pour la période {$paiement->periode} a été refusé : {$motif}. Déposez un nouveau reçu.",
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
            "Votre autorisation de paiement pour la période {$paiement->periode} est disponible. Réglez les frais puis déposez votre reçu.",
            '/stagiaire/paiements',
            "autorisation_paiement:{$paiement->id}",
        );
    }
}
