<?php

namespace App\Domain\Residence;

use App\Domain\Accommodation\AffectationLits;
use App\Domain\Audit\Journal;
use App\Domain\Notifications\Notifieur;
use App\Domain\Shared\Parametres;
use App\Domain\Shared\RegleMetierException;
use App\Enums\DomaineReclamation;
use App\Enums\RoleCode;
use App\Enums\StatutChambre;
use App\Enums\StatutMaintenance;
use App\Enums\StatutReclamation;
use App\Enums\StatutSortie;
use App\Enums\TypeNotification;
use App\Models\Absence;
use App\Models\Affectation;
use App\Models\Chambre;
use App\Models\DemandeMaintenance;
use App\Models\DemandeSortie;
use App\Models\Reclamation;
use App\Models\Stagiaire;
use App\Models\Utilisateur;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Vie quotidienne de l'internat : sorties, absences, maintenance
 * et réclamations. Chaque action enregistre le membre du personnel qui l'a faite.
 */
class VieInternat
{
    public function __construct(
        private readonly AffectationLits $affectations,
        private readonly Notifieur $notifieur,
        private readonly Parametres $parametres,
        private readonly Journal $journal,
    ) {}

    // ---------------------------------------------------------------- Sorties

    /** @param array{date_depart: string, date_retour: string, motif: string} $donnees */
    public function deposerSortie(Stagiaire $stagiaire, array $donnees): DemandeSortie
    {
        $affectation = $stagiaire->affectationActive();

        if ($affectation === null) {
            throw new RegleMetierException('Seul un résident hébergé peut demander une sortie.', 'non_resident');
        }

        $sortie = DemandeSortie::create([
            'affectation_id' => $affectation->id,
            'date_depart' => $donnees['date_depart'],
            'date_retour' => $donnees['date_retour'],
            'motif' => $donnees['motif'],
            'statut' => StatutSortie::EnAttente,
        ]);

        $this->notifieur->versRole(
            RoleCode::Gestionnaire,
            $affectation->internatId(),
            TypeNotification::Information,
            'Demande de sortie à traiter',
            "{$stagiaire->nomComplet()} demande une sortie du {$sortie->date_depart->translatedFormat('j F H\hi')} au {$sortie->date_retour->translatedFormat('j F H\hi')}.",
            '/vie-internat/presences',
            email: false,
        );

        return $sortie;
    }

    public function traiterSortie(DemandeSortie $sortie, bool $accepter, ?string $motifRefus, Utilisateur $gestionnaire): DemandeSortie
    {
        return DB::transaction(function () use ($sortie, $accepter, $motifRefus, $gestionnaire) {
            $sortie = DemandeSortie::lockForUpdate()->findOrFail($sortie->id);

            if ($sortie->statut !== StatutSortie::EnAttente) {
                throw RegleMetierException::conflit('Cette demande de sortie a déjà été traitée.', 'deja_traitee');
            }

            $sortie->forceFill([
                'statut' => $accepter ? StatutSortie::Acceptee : StatutSortie::Refusee,
                'traite_par' => $gestionnaire->id,
                'date_traitement' => now(),
                'motif_refus' => $accepter ? null : $motifRefus,
            ])->save();

            $this->journal->consigner('sortie.traitement', $sortie, details: ['decision' => $sortie->statut->value]);

            $message = $accepter
                ? 'Votre demande de sortie a été acceptée.'
                : "Votre demande de sortie a été refusée : {$motifRefus}";

            // Point non tranché par la documentation : une sortie acceptée n'annule pas les repas réservés.
            if ($accepter && ! $this->parametres->vrai('restauration.sortie_annule_repas')) {
                $message .= ' Pensez à annuler vous-même les repas réservés sur cette période.';
            }

            $this->notifieur->versStagiaire(
                $sortie->affectation->demande->stagiaire,
                TypeNotification::Information,
                $accepter ? 'Sortie acceptée' : 'Sortie refusée',
                $message,
                '/stagiaire/sorties',
            );

            return $sortie;
        });
    }

    // --------------------------------------------------------------- Absences

    /**
     * Saisie des absences du jour. Une absence couverte par une sortie acceptée
     * est autorisée ; sinon une alerte part dès la première journée.
     *
     * @param  list<int>  $affectationIds
     * @return array{creees: int, non_autorisees: int}
     */
    public function saisirAbsences(int $internatId, CarbonInterface $date, array $affectationIds, Utilisateur $gestionnaire): array
    {
        if ($date->isFuture()) {
            throw new RegleMetierException('Une absence ne se constate pas à l\'avance.', 'date_future');
        }

        return DB::transaction(function () use ($internatId, $date, $affectationIds, $gestionnaire) {
            $affectations = Affectation::query()
                ->whereIn('id', $affectationIds)
                ->whereDate('date_debut', '<=', $date)
                ->where(fn ($q) => $q->whereNull('date_fin')->orWhereDate('date_fin', '>=', $date))
                ->whereHas('demande.campagne', fn ($q) => $q->where('internat_id', $internatId))
                ->with('demande.stagiaire')
                ->get();

            if ($affectations->count() !== count(array_unique($affectationIds))) {
                throw new RegleMetierException('Un des résidents sélectionnés n\'appartient pas à cet internat à cette date.', 'resident_hors_internat');
            }

            $creees = 0;
            $nonAutorisees = [];

            foreach ($affectations as $affectation) {
                if (Absence::where('affectation_id', $affectation->id)->whereDate('date_absence', $date)->exists()) {
                    continue;
                }

                $sortie = DemandeSortie::where('affectation_id', $affectation->id)
                    ->where('statut', StatutSortie::Acceptee->value)
                    ->whereDate('date_depart', '<=', $date)
                    ->whereDate('date_retour', '>=', $date)
                    ->first();

                Absence::create([
                    'affectation_id' => $affectation->id,
                    'demande_sortie_id' => $sortie?->id,
                    'date_absence' => $date->toDateString(),
                    'autorisee' => $sortie !== null,
                    'saisi_par' => $gestionnaire->id,
                ]);
                $creees++;

                if ($sortie === null) {
                    $nonAutorisees[] = $affectation;
                }
            }

            foreach ($nonAutorisees as $affectation) {
                $this->alerterAbsence($affectation, $date, $internatId);
            }

            $this->journal->consigner('absence.saisie', 'absence', details: [
                'internat_id' => $internatId, 'date' => $date->toDateString(),
                'creees' => $creees, 'non_autorisees' => count($nonAutorisees),
            ]);

            return ['creees' => $creees, 'non_autorisees' => count($nonAutorisees)];
        });
    }

    // ------------------------------------------------------------ Maintenance

    public function signalerMaintenance(Chambre $chambre, string $description, bool $bloquer, Utilisateur $gestionnaire): DemandeMaintenance
    {
        return DB::transaction(function () use ($chambre, $description, $bloquer, $gestionnaire) {
            $chambre = Chambre::lockForUpdate()->findOrFail($chambre->id);

            $demande = DemandeMaintenance::create([
                'chambre_id' => $chambre->id,
                'description' => $description,
                'statut' => StatutMaintenance::Signalee,
                'date_demande' => now(),
                'saisi_par' => $gestionnaire->id,
                'bloque_chambre' => $bloquer,
            ]);

            if ($bloquer) {
                // La chambre sort de l'affectation ; ses occupants actuels ne sont pas déplacés.
                $chambre->forceFill(['statut' => StatutChambre::EnMaintenance])->save();
            }

            $this->journal->consigner('maintenance.signalement', $demande, details: [
                'chambre' => $chambre->numero, 'bloque_chambre' => $bloquer,
            ]);

            return $demande;
        });
    }

    public function avancerMaintenance(DemandeMaintenance $demande, StatutMaintenance $statut, ?string $resolution, Utilisateur $gestionnaire): DemandeMaintenance
    {
        return DB::transaction(function () use ($demande, $statut, $resolution, $gestionnaire) {
            $demande = DemandeMaintenance::lockForUpdate()->findOrFail($demande->id);

            $permis = match ($demande->statut) {
                StatutMaintenance::Signalee => [StatutMaintenance::EnCours, StatutMaintenance::Resolue],
                StatutMaintenance::EnCours => [StatutMaintenance::Resolue],
                StatutMaintenance::Resolue => [],
            };

            if (! in_array($statut, $permis, true)) {
                throw new RegleMetierException('Cette demande ne peut pas passer à ce statut.', 'transition_interdite');
            }

            $demande->statut = $statut;

            if ($statut === StatutMaintenance::Resolue) {
                $demande->forceFill([
                    'date_resolution' => now(),
                    'resolution' => $resolution,
                    'resolu_par' => $gestionnaire->id,
                ]);
            }

            $demande->save();

            if ($statut === StatutMaintenance::Resolue && $demande->bloque_chambre) {
                $this->remettreChambreEnService($demande->chambre_id);
            }

            $this->journal->consigner('maintenance.suivi', $demande, details: ['statut' => $statut->value]);

            return $demande;
        });
    }

    // ----------------------------------------------------------- Réclamations

    public function deposerReclamation(Stagiaire $stagiaire, DomaineReclamation $domaine, string $description): Reclamation
    {
        $internat = $stagiaire->affectationActive()?->lit->chambre->batiment->internat ?? $stagiaire->internat();

        if ($internat === null) {
            throw new RegleMetierException('Complétez votre profil avant de déposer une réclamation.', 'profil_incomplet');
        }

        $reclamation = Reclamation::create([
            'stagiaire_id' => $stagiaire->id,
            'internat_id' => $internat->id,
            'domaine' => $domaine,
            'description' => $description,
            'statut' => StatutReclamation::Ouverte,
            'date_creation' => now(),
        ]);

        $this->notifieur->versRole(
            self::roleDuDomaine($domaine),
            $internat->id,
            TypeNotification::Reclamation,
            'Nouvelle réclamation — '.$domaine->libelle(),
            "{$stagiaire->nomComplet()} a déposé une réclamation.",
            '/reclamations',
            email: false,
        );

        return $reclamation;
    }

    public function traiterReclamation(Reclamation $reclamation, StatutReclamation $statut, ?string $reponse, Utilisateur $traitant): Reclamation
    {
        if ($reclamation->statut === StatutReclamation::Traitee) {
            throw RegleMetierException::conflit('Cette réclamation est déjà traitée.', 'deja_traitee');
        }

        if ($statut === StatutReclamation::Traitee && blank($reponse)) {
            throw new RegleMetierException('Une réponse est requise pour clore la réclamation.', 'reponse_requise');
        }

        $reclamation->forceFill([
            'statut' => $statut,
            'reponse' => $reponse ?? $reclamation->reponse,
            'traite_par' => $traitant->id,
            'date_traitement' => $statut === StatutReclamation::Traitee ? now() : null,
        ])->save();

        $this->journal->consigner('reclamation.traitement', $reclamation, details: ['statut' => $statut->value]);

        if ($statut === StatutReclamation::Traitee) {
            $this->notifieur->versStagiaire(
                $reclamation->stagiaire,
                TypeNotification::Reclamation,
                'Réponse à votre réclamation',
                'Votre réclamation a reçu une réponse.',
                '/stagiaire/reclamations',
            );
        }

        return $reclamation;
    }

    /** Le domaine désigne le responsable : gestionnaire, responsable restauration ou chef de pôle. */
    public static function roleDuDomaine(DomaineReclamation $domaine): RoleCode
    {
        return match ($domaine) {
            DomaineReclamation::Hebergement => RoleCode::Gestionnaire,
            DomaineReclamation::Restauration => RoleCode::Restauration,
            DomaineReclamation::Selection => RoleCode::ChefPole,
        };
    }

    public static function domaineDuRole(?RoleCode $role): ?DomaineReclamation
    {
        return match ($role) {
            RoleCode::Gestionnaire => DomaineReclamation::Hebergement,
            RoleCode::Restauration => DomaineReclamation::Restauration,
            RoleCode::ChefPole => DomaineReclamation::Selection,
            default => null,
        };
    }

    private function alerterAbsence(Affectation $affectation, CarbonInterface $date, int $internatId): void
    {
        $stagiaire = $affectation->demande->stagiaire;
        $roles = $this->parametres->texte('absence.alerte_destinataires') === 'gestionnaire_et_chef_pole'
            ? [RoleCode::Gestionnaire, RoleCode::ChefPole]
            : [RoleCode::Gestionnaire];

        foreach ($roles as $role) {
            $this->notifieur->versRole(
                $role,
                $internatId,
                TypeNotification::AbsenceNonAutorisee,
                'Absence non autorisée',
                "{$stagiaire->nomComplet()} est absent le {$date->translatedFormat('j F Y')} sans sortie acceptée.",
                '/vie-internat/presences?onglet=absences',
                "absence:{$affectation->id}:{$date->toDateString()}:{$role->value}",
            );
        }
    }

    private function remettreChambreEnService(int $chambreId): void
    {
        $autreBlocage = DemandeMaintenance::where('chambre_id', $chambreId)
            ->where('bloque_chambre', true)
            ->where('statut', '!=', StatutMaintenance::Resolue->value)
            ->exists();

        if ($autreBlocage) {
            return;
        }

        $chambre = Chambre::lockForUpdate()->findOrFail($chambreId);
        $chambre->forceFill(['statut' => StatutChambre::Disponible])->save();
        $this->affectations->synchroniserChambre($chambre);
    }
}
