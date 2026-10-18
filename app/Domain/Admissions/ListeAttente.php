<?php

namespace App\Domain\Admissions;

use App\Domain\Applications\TransitionDemande;
use App\Domain\Audit\Journal;
use App\Domain\Notifications\Notifieur;
use App\Domain\Shared\Parametres;
use App\Domain\Shared\RegleMetierException;
use App\Enums\ListeAdmission;
use App\Enums\OrigineDecision;
use App\Enums\RoleCode;
use App\Enums\StatutDemande;
use App\Enums\TypeDecision;
use App\Enums\TypeNotification;
use App\Models\Campagne;
use App\Models\DecisionAdmission;
use App\Models\Demande;
use App\Models\Utilisateur;
use Illuminate\Support\Facades\DB;

/**
 * Liste d'attente. Elle n'est jamais promue automatiquement : quand une place
 * se libère, l'application alerte le chef de pôle, qui choisit et valide un
 * candidat. Celui-ci dispose alors de 48 heures pour confirmer.
 */
class ListeAttente
{
    public function __construct(
        private readonly TransitionDemande $transition,
        private readonly Notifieur $notifieur,
        private readonly Parametres $parametres,
        private readonly Journal $journal,
    ) {}

    /** Alerte les chefs de pôle si une place est libre et qu'un candidat attend. */
    public function signalerPlaceLibre(Campagne $campagne, string $origine): void
    {
        if (! $campagne->estDeliberee() || $campagne->estArchivee()) {
            return;
        }

        $libres = $campagne->placesLibres();
        $enAttente = $campagne->demandes()->where('statut', StatutDemande::EnAttente->value)->count();

        if ($libres === 0 || $enAttente === 0) {
            return;
        }

        $campagne->loadMissing('internat');

        $this->notifieur->versRole(
            // C'est le gestionnaire de l'internat qui appelle les candidats de la liste d'attente.
            RoleCode::Gestionnaire,
            $campagne->internat_id,
            TypeNotification::PlaceLibre,
            'Place libre à l\'internat',
            "{$campagne->internat->nom} : {$libres} place(s) libre(s), {$enAttente} candidat(s) en liste d'attente. Choisissez un candidat à appeler.",
            "/admissions/liste-attente?campagne={$campagne->id}",
            "place_libre:{$campagne->id}:{$origine}",
        );
    }

    /** Le chef de pôle appelle un candidat de la liste d'attente sur une place libre. */
    public function promouvoir(Demande $demande, Utilisateur $chefDePole, ?string $motif): Demande
    {
        return DB::transaction(function () use ($demande, $chefDePole, $motif) {
            $campagne = Campagne::lockForUpdate()->findOrFail($demande->campagne_id);
            $demande = Demande::lockForUpdate()->findOrFail($demande->id);

            if (! $campagne->estDeliberee() || $campagne->estArchivee()) {
                throw new RegleMetierException('La liste d\'attente de cette campagne n\'est pas active.', 'liste_inactive');
            }

            if ($demande->statut !== StatutDemande::EnAttente) {
                throw new RegleMetierException('Ce candidat n\'est pas en liste d\'attente.', 'hors_liste_attente');
            }

            if ($campagne->placesLibres() < 1) {
                throw new RegleMetierException('Aucune place n\'est libre pour cette campagne.', 'aucune_place');
            }

            $premier = Demande::where('campagne_id', $campagne->id)
                ->where('statut', StatutDemande::EnAttente->value)
                ->orderBy('rang')->orderBy('date_soumission')->orderBy('id')
                ->first();

            // S'écarter de l'ordre du classement reste possible, mais doit être motivé.
            if ($premier !== null && $premier->rang < $demande->rang && blank($motif)) {
                throw new RegleMetierException(
                    'Ce candidat n\'est pas le mieux classé de la liste d\'attente : indiquez le motif de votre choix.',
                    'motif_requis',
                );
            }

            DecisionAdmission::create([
                'demande_id' => $demande->id,
                'origine' => OrigineDecision::ListeAttente,
                'decision' => TypeDecision::Admis,
                'motif' => $motif,
                'date_validation' => now(),
                'valide_par' => $chefDePole->id,
                'score_retenu' => $demande->score,
                'rang_retenu' => $demande->rang,
            ]);

            $this->transition->appliquer($demande, StatutDemande::Admis, $motif ?? 'Appelé de la liste d\'attente', [
                'liste' => ListeAdmission::Principale,
            ]);

            $this->journal->consigner('admission.promotion_liste_attente', $demande, details: [
                'rang' => $demande->rang, 'motif' => $motif,
            ]);

            $this->notifieur->versStagiaire(
                $demande->stagiaire,
                TypeNotification::Resultat,
                'Une place vous est proposée',
                'Une place s\'est libérée à l\'internat. Confirmez-la pour la conserver.',
                '/stagiaire/dossier',
                "promotion:{$demande->id}",
            );

            return $demande;
        });
    }
}
