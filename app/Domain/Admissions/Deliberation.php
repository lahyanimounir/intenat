<?php

namespace App\Domain\Admissions;

use App\Domain\Applications\TransitionDemande;
use App\Domain\Audit\Journal;
use App\Domain\Shared\Parametres;
use App\Domain\Shared\RegleMetierException;
use App\Enums\ListeAdmission;
use App\Enums\OrigineDecision;
use App\Enums\StatutCampagne;
use App\Enums\StatutDemande;
use App\Enums\TypeDecision;
use App\Jobs\PublierResultats;
use App\Models\Campagne;
use App\Models\DecisionAdmission;
use App\Models\Demande;
use App\Models\Utilisateur;
use Illuminate\Support\Facades\DB;

/**
 * Délibération : le chef de pôle scolarité valide seul la liste des admis.
 *
 * L'application propose (classement et listes) ; le chef de pôle peut ajuster
 * une proposition par une décision motivée, puis valide. La validation crée une
 * décision par dossier, fixe les échéances de confirmation et publie les résultats.
 */
class Deliberation
{
    public function __construct(
        private readonly Classement $classement,
        private readonly TransitionDemande $transition,
        private readonly Parametres $parametres,
        private readonly Journal $journal,
    ) {}

    /**
     * État de la campagne avant délibération : effectifs et points bloquants.
     *
     * @return array<string, mixed>
     */
    public function apercu(Campagne $campagne): array
    {
        $parStatut = Demande::where('campagne_id', $campagne->id)
            ->selectRaw('statut, COUNT(*) as total')->groupBy('statut')->pluck('total', 'statut');

        $aInstruire = (int) ($parStatut[StatutDemande::Recu->value] ?? 0);
        $complets = (int) ($parStatut[StatutDemande::Complet->value] ?? 0);

        $blocages = [];

        if ($campagne->statut !== StatutCampagne::Cloturee) {
            $blocages[] = 'La campagne doit être clôturée avant la délibération.';
        }
        if ($aInstruire > 0) {
            $blocages[] = "{$aInstruire} dossier(s) reçu(s) n'ont pas encore été instruits.";
        }
        if ($complets === 0) {
            $blocages[] = 'Aucun dossier complet à classer.';
        }

        return [
            'deliberee' => $campagne->estDeliberee(),
            'places' => $campagne->nombre_places,
            'dossiers_complets' => $complets,
            'dossiers_a_instruire' => $aInstruire,
            'dossiers_en_rectification' => (int) ($parStatut[StatutDemande::RetourRectification->value] ?? 0),
            'blocages' => $campagne->estDeliberee() ? [] : $blocages,
        ];
    }

    /**
     * @param  array<int, array{decision: string, motif: string}>  $ajustements  Indexés par identifiant de dossier.
     */
    public function valider(Campagne $campagne, Utilisateur $chefDePole, array $ajustements, bool $arbitrageConfirme): Campagne
    {
        return DB::transaction(function () use ($campagne, $chefDePole, $ajustements, $arbitrageConfirme) {
            $campagne = Campagne::lockForUpdate()->findOrFail($campagne->id);

            if ($campagne->estDeliberee()) {
                throw RegleMetierException::conflit('Les admissions de cette campagne ont déjà été validées.', 'deja_deliberee');
            }

            $apercu = $this->apercu($campagne);

            if ($apercu['blocages'] !== []) {
                throw new RegleMetierException(implode(' ', $apercu['blocages']), 'deliberation_bloquee');
            }

            $etat = $this->classement->recalculer($campagne);

            $dossiers = Demande::where('campagne_id', $campagne->id)
                ->where('statut', StatutDemande::Complet->value)
                ->orderBy('rang')->orderBy('date_soumission')->orderBy('id')
                ->lockForUpdate()->get();

            $inconnus = array_diff(array_keys($ajustements), $dossiers->pluck('id')->all());

            if ($inconnus !== []) {
                throw new RegleMetierException('Un ajustement vise un dossier qui n\'est pas à délibérer.', 'ajustement_invalide');
            }

            $decisions = [];

            foreach ($dossiers as $dossier) {
                $proposee = $dossier->liste === ListeAdmission::Principale ? TypeDecision::Admis : TypeDecision::ListeAttente;
                $ajustement = $ajustements[$dossier->id] ?? null;
                $decision = $ajustement ? TypeDecision::from($ajustement['decision']) : $proposee;
                $motif = $ajustement['motif'] ?? null;

                if ($decision !== $proposee && blank($motif)) {
                    throw new RegleMetierException(
                        "Un motif est obligatoire pour s'écarter de la proposition (dossier {$dossier->numero_dossier}).",
                        'motif_requis',
                    );
                }

                $decisions[$dossier->id] = [$decision, $decision !== $proposee ? $motif : null];
            }

            $admis = collect($decisions)->filter(fn (array $d) => $d[0] === TypeDecision::Admis)->count();

            if ($admis > $campagne->nombre_places) {
                throw new RegleMetierException(
                    "{$admis} admis pour {$campagne->nombre_places} places offertes : réduisez la liste des admis.",
                    'capacite_depassee',
                );
            }

            if ($etat['ex_aequo_a_la_limite'] && ! $arbitrageConfirme) {
                throw new RegleMetierException(
                    'Des dossiers de même score chevauchent la limite des places. Aucune règle de départage n\'est arrêtée : confirmez votre arbitrage.',
                    'arbitrage_requis',
                    ['score_limite' => $etat['score_limite']],
                );
            }

            $maintenant = now();
            $limiteConfirmation = $maintenant->copy()->addDays($this->parametres->entier('confirmation.delai_admis_jours'));
            $limiteDossier = $maintenant->copy()->addDays($this->parametres->entier('dossier_physique.delai_jours'))->toDateString();

            foreach ($dossiers as $dossier) {
                [$decision, $motif] = $decisions[$dossier->id];

                DecisionAdmission::create([
                    'demande_id' => $dossier->id,
                    'origine' => OrigineDecision::SelectionInitiale,
                    'decision' => $decision,
                    'motif' => $motif,
                    'date_validation' => $maintenant,
                    'valide_par' => $chefDePole->id,
                    'score_retenu' => $dossier->score,
                    'rang_retenu' => $dossier->rang,
                ]);

                match ($decision) {
                    TypeDecision::Admis => $this->transition->appliquer($dossier, StatutDemande::Admis, $motif, [
                        'liste' => ListeAdmission::Principale,
                        'date_limite_confirmation' => $limiteConfirmation,
                        'date_limite_dossier_physique' => $limiteDossier,
                    ]),
                    TypeDecision::ListeAttente => $this->transition->appliquer($dossier, StatutDemande::EnAttente, $motif, [
                        'liste' => ListeAdmission::Attente,
                    ]),
                    TypeDecision::Refuse => $this->transition->appliquer($dossier, StatutDemande::Refuse, $motif, [
                        'liste' => null,
                    ]),
                };
            }

            $campagne->forceFill([
                'date_deliberation' => $maintenant,
                'delibere_par' => $chefDePole->id,
                'date_publication' => $maintenant,
            ])->save();

            $this->journal->consigner('admission.deliberation', $campagne, details: [
                'admis' => $admis,
                'liste_attente' => collect($decisions)->filter(fn (array $d) => $d[0] === TypeDecision::ListeAttente)->count(),
                'refuses' => collect($decisions)->filter(fn (array $d) => $d[0] === TypeDecision::Refuse)->count(),
                'ajustements' => count($ajustements),
                'arbitrage_ex_aequo' => $etat['ex_aequo_a_la_limite'],
                'version_grille' => $campagne->version_grille,
            ]);

            PublierResultats::dispatch($campagne->id)->afterCommit();

            return $campagne;
        });
    }
}
