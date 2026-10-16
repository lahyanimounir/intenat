<?php

namespace App\Http\Controllers\Api\Administration;

use App\Domain\Audit\Journal;
use App\Domain\Shared\RegleMetierException;
use App\Enums\Genre;
use App\Http\Controllers\Controller;
use App\Models\Complexe;
use App\Models\Etablissement;
use App\Models\Internat;
use App\Models\Region;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Structure institutionnelle : Région → Complexe / CMC → Établissement → Internat.
 * La table des établissements est aussi la correspondance Nom EFP → Code EFP.
 */
class OrganisationController extends Controller
{
    /** Arbre de l'organisation, limité au périmètre de l'utilisateur. */
    public function arbre(): JsonResponse
    {
        $utilisateur = $this->personnel();
        $ids = $this->perimetre()->internatIds($utilisateur);
        $complet = $ids === null;

        $internats = Internat::query()->when(! $complet, fn ($q) => $q->whereIn('id', $ids))
            ->with('etablissement.region', 'etablissement.complexe')->orderBy('nom')->get();

        $etablissements = $complet
            ? Etablissement::with('region', 'complexe')->orderBy('nom_efp')->get()
            : $internats->pluck('etablissement')->unique('id')->sortBy('nom_efp')->values();

        return response()->json([
            'regions' => ($complet ? Region::orderBy('nom')->get() : $etablissements->pluck('region')->unique('id')->values())
                ->map(fn (Region $r) => $r->only(['id', 'code', 'nom'])),
            'complexes' => ($complet ? Complexe::orderBy('nom')->get() : $etablissements->pluck('complexe')->filter()->unique('id')->values())
                ->map(fn (Complexe $c) => $c->only(['id', 'region_id', 'code', 'nom'])),
            'etablissements' => $etablissements->map(fn (Etablissement $e) => $e->only(['id', 'region_id', 'complexe_id', 'code_efp', 'nom_efp'])),
            'internats' => $internats->map(fn (Internat $i) => [
                'id' => $i->id, 'etablissement_id' => $i->etablissement_id, 'nom' => $i->nom,
                'genre' => self::enum($i->genre),
            ]),
        ]);
    }

    public function enregistrerRegion(Request $request, Journal $journal, ?Region $region = null): JsonResponse
    {
        $donnees = $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique('region', 'code')->ignore($region?->id)],
            'nom' => ['required', 'string', 'max:150'],
        ]);

        return $this->enregistrer($region ?? new Region, $donnees, $journal);
    }

    public function enregistrerComplexe(Request $request, Journal $journal, ?Complexe $complexe = null): JsonResponse
    {
        $donnees = $request->validate([
            'region_id' => ['required', 'integer', 'exists:region,id'],
            'code' => ['required', 'string', 'max:20', Rule::unique('complexe', 'code')->ignore($complexe?->id)],
            'nom' => ['required', 'string', 'max:150'],
        ]);

        return $this->enregistrer($complexe ?? new Complexe, $donnees, $journal);
    }

    public function enregistrerEtablissement(Request $request, Journal $journal, ?Etablissement $etablissement = null): JsonResponse
    {
        $donnees = $request->validate([
            'region_id' => ['required', 'integer', 'exists:region,id'],
            'complexe_id' => ['nullable', 'integer', Rule::exists('complexe', 'id')->where('region_id', $request->input('region_id'))],
            'code_efp' => ['required', 'string', 'max:20', Rule::unique('etablissement', 'code_efp')->ignore($etablissement?->id)],
            'nom_efp' => ['required', 'string', 'max:190', Rule::unique('etablissement', 'nom_efp')->ignore($etablissement?->id)],
        ], [
            'complexe_id.exists' => 'Le complexe doit appartenir à la région choisie.',
        ]);

        return $this->enregistrer($etablissement ?? new Etablissement, $donnees, $journal);
    }

    public function enregistrerInternat(Request $request, Journal $journal, ?Internat $internat = null): JsonResponse
    {
        $donnees = $request->validate([
            'etablissement_id' => ['required', 'integer', 'exists:etablissement,id'],
            'genre' => [
                'required', Rule::enum(Genre::class),
                Rule::unique('internat', 'genre')->where('etablissement_id', $request->input('etablissement_id'))->ignore($internat?->id),
            ],
            'nom' => ['required', 'string', 'max:150'],
        ], [
            'genre.unique' => 'Cet établissement a déjà un internat pour ce genre.',
        ]);

        if ($internat?->exists && $internat->genre->value !== $donnees['genre'] && $internat->batiments()->exists()) {
            throw new RegleMetierException('Le genre d\'un internat qui possède déjà un référentiel ne peut pas être changé.', 'genre_verrouille');
        }

        return $this->enregistrer($internat ?? new Internat, $donnees, $journal);
    }

    public function supprimer(string $niveau, int $id, Journal $journal): JsonResponse
    {
        $modele = match ($niveau) {
            'regions' => Region::findOrFail($id),
            'complexes' => Complexe::findOrFail($id),
            'etablissements' => Etablissement::findOrFail($id),
            'internats' => Internat::findOrFail($id),
            default => abort(404),
        };

        try {
            $journal->consigner('organisation.suppression', $modele, details: $modele->only(['code', 'nom', 'code_efp', 'nom_efp']));
            $modele->delete();
        } catch (QueryException) {
            throw new RegleMetierException(
                'Suppression impossible : des données sont rattachées à cet élément.',
                'element_utilise',
            );
        }

        return response()->json(['message' => 'Élément supprimé.']);
    }

    /** @param array<string, mixed> $donnees */
    private function enregistrer(Model $modele, array $donnees, Journal $journal): JsonResponse
    {
        $creation = ! $modele->exists;
        $modele->fill($donnees)->save();

        $journal->consigner(
            $creation ? 'organisation.creation' : 'organisation.modification',
            $modele,
            details: $creation ? $donnees : $journal->differences($modele),
        );

        return response()->json($modele->fresh(), $creation ? 201 : 200);
    }
}
