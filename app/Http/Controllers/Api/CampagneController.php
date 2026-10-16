<?php

namespace App\Http\Controllers\Api;

use App\Domain\Admissions\CalculScore;
use App\Domain\Audit\Journal;
use App\Domain\Campaigns\GestionCampagne;
use App\Domain\Shared\RegleMetierException;
use App\Enums\CategorieCritere;
use App\Enums\DeclencheurCritere;
use App\Enums\NiveauFormation;
use App\Enums\StatutCampagne;
use App\Http\Controllers\Controller;
use App\Http\Resources\CampagneResource;
use App\Models\Campagne;
use App\Models\Critere;
use App\Models\CritereReference;
use App\Models\TypePiece;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CampagneController extends Controller
{
    public function __construct(
        private readonly GestionCampagne $campagnes,
        private readonly CalculScore $calcul,
        private readonly Journal $journal,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $requete = Campagne::visiblesPar($this->personnel())
            ->with('internat.etablissement', 'createur')->withCount(['demandes' => fn ($q) => $q->where('statut', '!=', 'brouillon')])
            ->when($request->filled('annee'), fn ($q) => $q->where('annee', $request->string('annee')->toString()))
            ->when($request->filled('statut'), fn ($q) => $q->where('statut', $request->string('statut')->toString()))
            ->when($request->filled('internat_id'), fn ($q) => $q->where('internat_id', $request->integer('internat_id')))
            ->when($request->query('archives') !== '1', fn ($q) => $q->whereNull('archivee_le'))
            ->orderByDesc('annee')->orderBy('internat_id');

        return CampagneResource::collection($this->paginer($requete, $request));
    }

    public function store(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'annee' => ['required', 'regex:/^\d{4}-\d{4}$/'],
            'date_ouverture' => ['required', 'date'],
            'date_cloture' => ['required', 'date', 'after_or_equal:date_ouverture'],
            'frais_mensuels' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'internats' => ['required', 'array', 'min:1'],
            'internats.*.internat_id' => ['required', 'integer', 'distinct', 'exists:internat,id'],
            'internats.*.nombre_places' => ['required', 'integer', 'min:1', 'max:5000'],
        ], ['annee.regex' => 'Année attendue au format 2026-2027.']);

        [$debut, $fin] = array_map('intval', explode('-', $donnees['annee']));

        if ($fin !== $debut + 1) {
            throw ValidationException::withMessages(['annee' => 'L\'année de formation couvre deux années consécutives (ex. 2026-2027).']);
        }

        $creees = $this->campagnes->creer($this->personnel(), $donnees);

        return CampagneResource::collection(collect($creees)->each->load('internat.etablissement'))
            ->response()->setStatusCode(201);
    }

    public function show(Campagne $campagne): JsonResponse
    {
        $this->autoriser($campagne, 'campagnes.consulter');
        $campagne->load('internat.etablissement', 'createur', 'typesPieces', 'criteres.typePiece');

        return response()->json([
            'campagne' => new CampagneResource($campagne),
            'types_pieces' => $campagne->typesPieces->sortBy('libelle')->values()->map(fn (TypePiece $t) => [
                'id' => $t->id, 'libelle' => $t->libelle, 'obligatoire' => $t->obligatoire, 'formats_acceptes' => $t->formats_acceptes,
            ]),
            'criteres' => $campagne->criteres->sortBy('id')->values()->map(fn (Critere $c) => $this->critere($c)),
            'bareme' => [
                'maximum_atteignable' => $this->calcul->maximumAtteignable($campagne),
                'plafond' => (int) config('internat.score.bareme_maximum'),
            ],
            'lits_eligibles' => $this->campagnes->litsEligibles($campagne->internat_id),
            'blocages_ouverture' => $campagne->statut === StatutCampagne::Preparee ? $this->campagnes->controlesOuverture($campagne) : [],
            'dossiers_par_statut' => $campagne->demandes()->selectRaw('statut, COUNT(*) as total')->groupBy('statut')->pluck('total', 'statut'),
        ]);
    }

    public function update(Request $request, Campagne $campagne): CampagneResource
    {
        $this->autoriser($campagne, 'campagnes.gerer');

        $donnees = $request->validate([
            'annee' => ['sometimes', 'regex:/^\d{4}-\d{4}$/'],
            'date_ouverture' => ['sometimes', 'date'],
            'date_cloture' => ['sometimes', 'date'],
            'nombre_places' => ['sometimes', 'integer', 'min:1', 'max:5000'],
            'frais_mensuels' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999'],
        ]);

        return new CampagneResource($this->campagnes->modifier($campagne, $donnees)->load('internat.etablissement'));
    }

    public function transition(Campagne $campagne, string $action): CampagneResource
    {
        $this->autoriser($campagne, 'campagnes.gerer');

        $campagne = match ($action) {
            'ouvrir' => $this->campagnes->ouvrir($campagne),
            'cloturer' => $this->campagnes->cloturer($campagne),
            'archiver' => $this->campagnes->archiver($campagne),
            default => abort(404),
        };

        return new CampagneResource($campagne->load('internat.etablissement'));
    }

    public function destroy(Campagne $campagne): JsonResponse
    {
        $this->autoriser($campagne, 'campagnes.gerer');
        $this->campagnes->supprimer($campagne);

        return response()->json(['message' => 'Campagne supprimée.']);
    }

    // ------------------------------------------------------- Pièces exigées

    public function enregistrerTypePiece(Request $request, Campagne $campagne, ?TypePiece $typePiece = null): JsonResponse
    {
        $this->autoriser($campagne, 'campagnes.gerer');
        $this->campagnes->exigerPreparation($campagne);
        abort_if($typePiece !== null && $typePiece->campagne_id !== $campagne->id, 404);

        $donnees = $request->validate([
            'libelle' => ['required', 'string', 'max:150', Rule::unique('type_piece', 'libelle')->where('campagne_id', $campagne->id)->ignore($typePiece?->id)],
            'obligatoire' => ['required', 'boolean'],
            'formats_acceptes' => ['required', 'string', 'max:100'],
        ]);

        $typePiece ??= new TypePiece(['campagne_id' => $campagne->id]);
        $creation = ! $typePiece->exists;
        $typePiece->fill($donnees)->save();
        $this->journal->consigner($creation ? 'type_piece.creation' : 'type_piece.modification', $typePiece, details: $donnees);

        return response()->json($typePiece, $creation ? 201 : 200);
    }

    public function supprimerTypePiece(Campagne $campagne, TypePiece $typePiece): JsonResponse
    {
        $this->autoriser($campagne, 'campagnes.gerer');
        $this->campagnes->exigerPreparation($campagne);
        abort_if($typePiece->campagne_id !== $campagne->id, 404);

        if ($typePiece->criteres()->exists()) {
            throw new RegleMetierException('Cette pièce est rattachée à un critère : modifiez d\'abord le critère.', 'piece_utilisee');
        }

        $this->journal->consigner('type_piece.suppression', $typePiece, details: ['libelle' => $typePiece->libelle]);
        $typePiece->delete();

        return response()->json(['message' => 'Pièce supprimée.']);
    }

    // -------------------------------------------------------------- Critères

    public function enregistrerCritere(Request $request, Campagne $campagne, ?Critere $critere = null): JsonResponse
    {
        $this->autoriser($campagne, 'criteres.gerer');
        $this->campagnes->exigerPreparation($campagne);
        abort_if($critere !== null && $critere->campagne_id !== $campagne->id, 404);

        $donnees = $this->validerCritere($request, [
            'type_piece_id' => ['nullable', 'integer', Rule::exists('type_piece', 'id')->where('campagne_id', $campagne->id)],
        ]);

        $doublon = Critere::where('campagne_id', $campagne->id)
            ->where('declencheur', $donnees['declencheur'])->where('valeur_attendue', $donnees['valeur_attendue'])
            ->when($critere, fn ($q) => $q->whereKeyNot($critere->id))->exists();

        if ($doublon) {
            throw ValidationException::withMessages(['declencheur' => 'Un critère existe déjà pour cette donnée du dossier.']);
        }

        $critere ??= new Critere(['campagne_id' => $campagne->id]);
        $creation = ! $critere->exists;
        $critere->fill($donnees)->save();
        $this->journal->consigner($creation ? 'critere.creation' : 'critere.modification', $critere, details: $donnees);

        return response()->json($this->critere($critere->load('typePiece')), $creation ? 201 : 200);
    }

    public function supprimerCritere(Campagne $campagne, Critere $critere): JsonResponse
    {
        $this->autoriser($campagne, 'criteres.gerer');
        $this->campagnes->exigerPreparation($campagne);
        abort_if($critere->campagne_id !== $campagne->id, 404);

        $this->journal->consigner('critere.suppression', $critere, details: ['condition' => $critere->condition_texte, 'points' => $critere->points]);
        $critere->delete();

        return response()->json(['message' => 'Critère supprimé.']);
    }

    /** Recopie la grille nationale de référence dans une campagne en préparation. */
    public function appliquerReference(Campagne $campagne): JsonResponse
    {
        $this->autoriser($campagne, 'criteres.gerer');
        $this->campagnes->exigerPreparation($campagne);

        return response()->json(['copies' => $this->campagnes->appliquerGrilleDeReference($campagne)]);
    }

    // ------------------------------------------------- Grille de référence

    public function grilleReference(): JsonResponse
    {
        return response()->json([
            'criteres' => CritereReference::orderBy('categorie')->orderBy('id')->get()->map(fn (CritereReference $c) => [
                'id' => $c->id, 'categorie' => self::enum($c->categorie), 'declencheur' => self::enum($c->declencheur),
                'valeur_attendue' => $c->valeur_attendue, 'condition' => $c->condition_texte, 'points' => $c->points,
                'piece_libelle' => $c->piece_libelle, 'actif' => $c->actif,
            ]),
            'maximum_atteignable' => $this->calcul->maximumDe(CritereReference::where('actif', true)->get()),
            'plafond' => (int) config('internat.score.bareme_maximum'),
            'referentiels' => [
                'categories' => CategorieCritere::options(),
                'declencheurs' => DeclencheurCritere::options(),
                'niveaux' => NiveauFormation::options(),
            ],
        ]);
    }

    public function enregistrerCritereReference(Request $request, ?CritereReference $critereReference = null): JsonResponse
    {
        $donnees = $this->validerCritere($request, [
            'piece_libelle' => ['nullable', 'string', 'max:150'],
            'actif' => ['sometimes', 'boolean'],
        ]);

        $critereReference ??= new CritereReference;
        $creation = ! $critereReference->exists;
        $critereReference->fill($donnees)->save();
        $this->journal->consigner($creation ? 'grille_reference.creation' : 'grille_reference.modification', $critereReference, details: $donnees);

        return response()->json($critereReference, $creation ? 201 : 200);
    }

    public function supprimerCritereReference(CritereReference $critereReference): JsonResponse
    {
        $this->journal->consigner('grille_reference.suppression', $critereReference, details: ['condition' => $critereReference->condition_texte]);
        $critereReference->delete();

        return response()->json(['message' => 'Critère de référence supprimé.']);
    }

    /**
     * @param  array<string, mixed>  $supplement
     * @return array<string, mixed>
     */
    private function validerCritere(Request $request, array $supplement): array
    {
        $donnees = $request->validate([
            'categorie' => ['required', Rule::enum(CategorieCritere::class)],
            'declencheur' => ['required', Rule::enum(DeclencheurCritere::class)],
            'valeur_attendue' => [
                Rule::requiredIf($request->input('declencheur') === DeclencheurCritere::NiveauFormation->value),
                'nullable', Rule::enum(NiveauFormation::class),
            ],
            'condition' => ['required', 'string', 'max:255'],
            'points' => ['required', 'integer', 'min:0', 'max:80'],
        ] + $supplement, ['valeur_attendue.required' => 'Précisez le niveau de formation visé par ce critère.']);

        $donnees['condition_texte'] = $donnees['condition'];
        $donnees['valeur_attendue'] = $donnees['declencheur'] === DeclencheurCritere::NiveauFormation->value
            ? (string) $donnees['valeur_attendue'] : '';
        unset($donnees['condition']);

        return $donnees;
    }

    /** @return array<string, mixed> */
    private function critere(Critere $c): array
    {
        return [
            'id' => $c->id,
            'categorie' => self::enum($c->categorie),
            'declencheur' => self::enum($c->declencheur),
            'valeur_attendue' => $c->valeur_attendue,
            'condition' => $c->condition_texte,
            'points' => $c->points,
            'type_piece' => $c->typePiece?->only(['id', 'libelle']),
        ];
    }
}
