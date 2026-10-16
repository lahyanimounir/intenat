<?php

namespace App\Http\Controllers\Api;

use App\Domain\Admissions\CalculScore;
use App\Domain\Admissions\Classement;
use App\Domain\Admissions\Deliberation;
use App\Domain\Admissions\ListeAttente;
use App\Enums\ListeAdmission;
use App\Enums\StatutDemande;
use App\Enums\TypeDecision;
use App\Http\Controllers\Controller;
use App\Http\Resources\CampagneResource;
use App\Http\Resources\DemandeResource;
use App\Models\Campagne;
use App\Models\DecisionAdmission;
use App\Models\Demande;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Sélection et délibération. Réservé au chef de pôle scolarité : lui seul voit
 * la liste principale et la liste d'attente, et lui seul valide.
 */
class AdmissionController extends Controller
{
    /**
     * Classement d'une campagne. Avec la campagne de l'autre internat du même
     * établissement, on obtient les quatre listes filles / garçons.
     */
    public function classement(Request $request, Campagne $campagne, Deliberation $deliberation): JsonResponse
    {
        $this->autoriser($campagne, 'admissions.consulter');
        $campagne->load('internat.etablissement');

        $statuts = $campagne->estDeliberee()
            ? [StatutDemande::Admis, StatutDemande::Confirme, StatutDemande::EnAttente, StatutDemande::Refuse,
                StatutDemande::Desiste, StatutDemande::DelaiDepasse, StatutDemande::SejourTermine]
            : [StatutDemande::Complet];

        $requete = Demande::where('campagne_id', $campagne->id)
            ->whereIn('statut', array_map(fn ($s) => $s->value, $statuts))
            ->whereNotNull('rang')
            ->with('stagiaire')
            ->when($request->filled('liste'), fn ($q) => $q->where('liste', $request->string('liste')->toString()))
            ->when($request->filled('recherche'), function ($q) use ($request) {
                $terme = '%'.str_replace(['%', '_'], ['\%', '\_'], $request->string('recherche')->trim()).'%';
                $q->where(fn ($s) => $s->where('numero_dossier', 'like', $terme)
                    ->orWhereHas('stagiaire', fn ($st) => $st->where('nom', 'like', $terme)->orWhere('prenom', 'like', $terme)->orWhere('cef', 'like', $terme)));
            })
            ->orderBy('rang')->orderBy('date_soumission')->orderBy('id');

        $limite = Demande::where('campagne_id', $campagne->id)->where('liste', ListeAdmission::Principale->value)->min('score');
        $premierAttente = Demande::where('campagne_id', $campagne->id)->where('liste', ListeAdmission::Attente->value)
            ->where('statut', StatutDemande::Complet->value)->max('score');

        return response()->json([
            'campagne' => new CampagneResource($campagne),
            'apercu' => $deliberation->apercu($campagne) + [
                'score_limite' => $limite,
                'ex_aequo_a_la_limite' => ! $campagne->estDeliberee() && $limite !== null && $premierAttente !== null && (int) $limite === (int) $premierAttente,
                'places_libres' => $campagne->estDeliberee() ? $campagne->placesLibres() : null,
                'en_liste_principale' => Demande::where('campagne_id', $campagne->id)->where('liste', ListeAdmission::Principale->value)->count(),
                'en_liste_attente' => Demande::where('campagne_id', $campagne->id)->where('liste', ListeAdmission::Attente->value)->count(),
            ],
            'dossiers' => DemandeResource::collection($this->paginer($requete, $request, 50))->response()->getData(true),
        ]);
    }

    public function recalculer(Campagne $campagne, Classement $classement): JsonResponse
    {
        $this->autoriser($campagne, 'admissions.deliberer');

        return response()->json($classement->recalculer($campagne));
    }

    /** Détail du score : chaque critère, la déclaration, la pièce, les points. */
    public function score(Demande $demande, CalculScore $calcul): JsonResponse
    {
        $this->autoriser($demande, 'admissions.consulter');
        $demande->load('campagne.criteres.typePiece', 'pieces', 'stagiaire');
        $evaluation = $calcul->evaluer($demande);

        return response()->json([
            'demande' => new DemandeResource($demande),
            'total_enregistre' => $demande->score,
            'total_recalcule' => $evaluation['total'],
            'plafond' => (int) config('internat.score.bareme_maximum'),
            'version_grille' => $demande->version_grille,
            'version_grille_courante' => $demande->campagne->version_grille,
            'date_calcul' => $demande->date_calcul_score,
            'lignes' => collect($evaluation['lignes'])->map(fn (array $l) => [
                'critere_id' => $l['critere']->id,
                'categorie' => self::enum($l['critere']->categorie),
                'condition' => $l['critere']->condition_texte,
                'points_possibles' => $l['critere']->points,
                'declare' => $l['declare'],
                'piece' => $l['critere']->typePiece?->libelle,
                'piece_requise' => $l['piece_requise'],
                'piece_acceptee' => $l['piece_acceptee'],
                'points' => $l['points'],
            ]),
            'decisions' => DecisionAdmission::where('demande_id', $demande->id)->with('validateur:id,nom,prenom')
                ->orderByDesc('date_validation')->get()->map(fn (DecisionAdmission $d) => [
                    'origine' => self::enum($d->origine), 'decision' => self::enum($d->decision), 'motif' => $d->motif,
                    'date_validation' => $d->date_validation, 'validateur' => $d->validateur->nomComplet(),
                    'score_retenu' => $d->score_retenu, 'rang_retenu' => $d->rang_retenu,
                ]),
        ]);
    }

    public function deliberer(Request $request, Campagne $campagne, Deliberation $deliberation): CampagneResource
    {
        $this->autoriser($campagne, 'admissions.deliberer');

        $donnees = $request->validate([
            'arbitrage_confirme' => ['sometimes', 'boolean'],
            'ajustements' => ['sometimes', 'array'],
            'ajustements.*.demande_id' => ['required', 'integer', 'distinct'],
            'ajustements.*.decision' => ['required', Rule::enum(TypeDecision::class)],
            'ajustements.*.motif' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $ajustements = collect($donnees['ajustements'] ?? [])
            ->mapWithKeys(fn (array $a) => [(int) $a['demande_id'] => ['decision' => $a['decision'], 'motif' => $a['motif']]])
            ->all();

        $campagne = $deliberation->valider($campagne, $this->personnel(), $ajustements, (bool) ($donnees['arbitrage_confirme'] ?? false));

        return new CampagneResource($campagne->load('internat.etablissement'));
    }

    /** Places libres et candidats en attente des campagnes délibérées du périmètre. */
    public function listesAttente(): JsonResponse
    {
        $campagnes = Campagne::visiblesPar($this->personnel())
            ->whereNotNull('date_deliberation')->whereNull('archivee_le')
            ->with('internat.etablissement')->orderByDesc('annee')->orderBy('internat_id')->get();

        return response()->json($campagnes->map(fn (Campagne $c) => [
            'campagne' => new CampagneResource($c),
            'places_libres' => $c->placesLibres(),
            'en_attente' => $c->demandes()->where('statut', StatutDemande::EnAttente->value)->count(),
        ]));
    }

    public function promouvoir(Request $request, Demande $demande, ListeAttente $listeAttente): DemandeResource
    {
        $this->autoriser($demande, 'admissions.promouvoir');
        $motif = $request->validate(['motif' => ['nullable', 'string', 'max:1000']])['motif'] ?? null;

        return new DemandeResource($listeAttente->promouvoir($demande, $this->personnel(), $motif)->load('stagiaire'));
    }
}
