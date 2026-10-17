<?php

namespace App\Domain\Admissions;

use App\Domain\Applications\TransitionDemande;
use App\Domain\Notifications\Notifieur;
use App\Domain\Shared\RegleMetierException;
use App\Enums\RaisonDesistement;
use App\Enums\RoleCode;
use App\Enums\StatutDemande;
use App\Enums\StatutPaiement;
use App\Enums\TypeNotification;
use App\Models\Demande;
use App\Models\Desistement;
use App\Models\Paiement;
use Illuminate\Support\Facades\DB;

/**
 * Suites de l'admission côté candidat : confirmation de la place et désistement
 * motivé. Il n'y a aucun délai de confirmation : un admis confirme quand il le
 * souhaite, tant que sa place n'a pas été libérée autrement.
 */
class ConfirmationAdmission
{
    public function __construct(
        private readonly TransitionDemande $transition,
        private readonly ListeAttente $listeAttente,
        private readonly Notifieur $notifieur,
    ) {}

    public function confirmer(Demande $demande): Demande
    {
        return DB::transaction(function () use ($demande) {
            $demande = Demande::lockForUpdate()->findOrFail($demande->id);

            if ($demande->statut !== StatutDemande::Admis) {
                throw new RegleMetierException('Seul un candidat admis peut confirmer sa place.', 'non_admis');
            }

            $this->transition->appliquer($demande, StatutDemande::Confirme, 'Place confirmée par le stagiaire', [
                'date_confirmation' => now(),
            ]);

            $campagne = $demande->campagne;

            // Un seul paiement par dossier : en attente de l'autorisation du chef de pôle.
            Paiement::firstOrCreate(
                ['demande_id' => $demande->id],
                ['montant' => $campagne->frais ?? 0, 'statut' => StatutPaiement::EnAttente],
            );

            $this->notifieur->versRole(
                RoleCode::ChefPole,
                $campagne->internat_id,
                TypeNotification::Approbation,
                'Autorisation de paiement à délivrer',
                "{$demande->stagiaire->nomComplet()} (dossier {$demande->numero_dossier}) a confirmé sa place.",
                '/paiements?statut=en_attente',
                "paiement_a_autoriser:{$demande->id}",
                email: false,
            );

            $this->notifieur->versRole(
                RoleCode::Gestionnaire,
                $campagne->internat_id,
                TypeNotification::ChangementStatut,
                'Place confirmée',
                "{$demande->stagiaire->nomComplet()} (dossier {$demande->numero_dossier}) a confirmé sa place : dossier physique et affectation à prévoir.",
                '/hebergement/affectations',
                "confirmation:{$demande->id}",
                email: false,
            );

            return $demande;
        });
    }

    public function desister(Demande $demande, RaisonDesistement $raison, string $explication): Demande
    {
        return DB::transaction(function () use ($demande, $raison, $explication) {
            $demande = Demande::lockForUpdate()->findOrFail($demande->id);

            if (! in_array($demande->statut, [StatutDemande::Admis, StatutDemande::Confirme, StatutDemande::EnAttente], true)) {
                throw new RegleMetierException('Aucune place à laquelle renoncer pour ce dossier.', 'desistement_impossible');
            }

            $tenaitUnePlace = $demande->statut !== StatutDemande::EnAttente;
            $affectation = $demande->affectationActive()->first();

            Desistement::create([
                'demande_id' => $demande->id,
                'raison' => $raison,
                'explication' => $explication,
                'date_desistement' => now(),
            ]);

            $this->transition->appliquer($demande, StatutDemande::Desiste, $raison->libelle().' — '.$explication);

            $campagne = $demande->campagne;

            if ($affectation !== null) {
                // Le lit reste attribué jusqu'au départ constaté par le gestionnaire.
                $this->notifieur->versRole(
                    RoleCode::Gestionnaire,
                    $campagne->internat_id,
                    TypeNotification::ChangementStatut,
                    'Désistement d\'un résident',
                    "{$demande->stagiaire->nomComplet()} renonce à sa place. Enregistrez son départ pour libérer le lit.",
                    "/hebergement/residents/{$affectation->id}",
                    "desistement:{$demande->id}",
                );
            }

            if ($tenaitUnePlace) {
                $this->listeAttente->signalerPlaceLibre($campagne, "desistement-{$demande->id}");
            }

            return $demande;
        });
    }

}
