<?php

namespace App\Domain\Admissions;

use App\Domain\Applications\TransitionDemande;
use App\Domain\Notifications\Notifieur;
use App\Domain\Shared\Parametres;
use App\Domain\Shared\RegleMetierException;
use App\Enums\OrigineDecision;
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
 * Suites de l'admission côté candidat : confirmation dans le délai, désistement
 * motivé, et expiration des échéances. Les délais sont contrôlés sur le serveur ;
 * l'horloge du navigateur n'entre jamais en ligne de compte.
 */
class ConfirmationAdmission
{
    public function __construct(
        private readonly TransitionDemande $transition,
        private readonly ListeAttente $listeAttente,
        private readonly Notifieur $notifieur,
        private readonly Parametres $parametres,
    ) {}

    public function confirmer(Demande $demande): Demande
    {
        return DB::transaction(function () use ($demande) {
            $demande = Demande::lockForUpdate()->findOrFail($demande->id);

            if ($demande->statut !== StatutDemande::Admis) {
                throw new RegleMetierException('Seul un candidat admis peut confirmer sa place.', 'non_admis');
            }

            if ($demande->date_limite_confirmation !== null && now()->greaterThan($demande->date_limite_confirmation)) {
                throw new RegleMetierException(
                    'Le délai de confirmation est dépassé : la place a été remise à disposition.',
                    'delai_depasse',
                );
            }

            $this->transition->appliquer($demande, StatutDemande::Confirme, 'Place confirmée par le stagiaire', [
                'date_confirmation' => now(),
            ]);

            $campagne = $demande->campagne;

            // Première période : en attente de l'autorisation de paiement du chef de pôle.
            Paiement::firstOrCreate(
                ['demande_id' => $demande->id, 'periode' => now()->format('Y-m')],
                ['montant' => $campagne->frais_mensuels ?? 0, 'statut' => StatutPaiement::EnAttente, 'genere_auto' => false],
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

    /**
     * Tâche planifiée : remet à disposition les places non confirmées dans le délai.
     * Sans effet si elle est rejouée (le statut a déjà changé).
     */
    public function expirerEcheances(): int
    {
        $expirees = 0;

        Demande::query()
            ->where('statut', StatutDemande::Admis->value)
            ->whereNotNull('date_limite_confirmation')
            ->where('date_limite_confirmation', '<', now())
            ->select('id')
            ->chunkById(100, function ($lots) use (&$expirees) {
                foreach ($lots as $ligne) {
                    $expirees += DB::transaction(function () use ($ligne) {
                        $demande = Demande::lockForUpdate()->find($ligne->id);

                        if ($demande === null
                            || $demande->statut !== StatutDemande::Admis
                            || now()->lessThanOrEqualTo($demande->date_limite_confirmation)) {
                            return 0;
                        }

                        $this->transition->appliquer(
                            $demande,
                            StatutDemande::DelaiDepasse,
                            'Place non confirmée dans le délai',
                            automatique: true,
                        );

                        $this->notifieur->versStagiaire(
                            $demande->stagiaire,
                            TypeNotification::ChangementStatut,
                            'Délai de confirmation dépassé',
                            'Vous n\'avez pas confirmé votre place dans le délai imparti : elle a été remise à disposition.',
                            '/stagiaire/dossier',
                            "delai_depasse:{$demande->id}",
                        );

                        $this->listeAttente->signalerPlaceLibre($demande->campagne, "delai-{$demande->id}");

                        return 1;
                    });
                }
            });

        return $expirees;
    }

    /** Tâche planifiée : rappelle l'échéance aux admis qui n'ont pas encore confirmé. */
    public function rappelerEcheances(): int
    {
        $rappels = 0;
        $seuilAdmis = $this->parametres->entier('confirmation.rappel_avant_heures');
        // Pour un candidat appelé de la liste d'attente (48 h), le rappel part à mi-délai.
        $seuilAttente = (int) floor($this->parametres->entier('confirmation.delai_liste_attente_heures') / 2);

        Demande::query()
            ->where('statut', StatutDemande::Admis->value)
            ->whereBetween('date_limite_confirmation', [now(), now()->addHours(max($seuilAdmis, $seuilAttente))])
            ->with(['stagiaire', 'decisions' => fn ($q) => $q->limit(1)])
            ->chunkById(100, function ($dossiers) use (&$rappels, $seuilAdmis, $seuilAttente) {
                foreach ($dossiers as $dossier) {
                    $depuisAttente = $dossier->decisions->first()?->origine === OrigineDecision::ListeAttente;
                    $seuil = $depuisAttente ? $seuilAttente : $seuilAdmis;

                    if (now()->diffInHours($dossier->date_limite_confirmation, false) > $seuil) {
                        continue;
                    }

                    $envoyee = $this->notifieur->versStagiaire(
                        $dossier->stagiaire,
                        TypeNotification::EcheanceConfirmation,
                        'Confirmez votre place',
                        'Votre place à l\'internat doit être confirmée avant le '
                            .$dossier->date_limite_confirmation->translatedFormat('j F Y à H\hi').'.',
                        '/stagiaire/dossier',
                        "rappel_confirmation:{$dossier->id}:{$dossier->date_limite_confirmation->timestamp}",
                    );

                    $rappels += $envoyee ? 1 : 0;
                }
            });

        return $rappels;
    }
}
