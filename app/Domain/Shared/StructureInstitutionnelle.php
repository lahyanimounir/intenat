<?php

namespace App\Domain\Shared;

use App\Domain\Audit\Journal;
use App\Enums\Genre;
use App\Models\Complexe;
use App\Models\Etablissement;
use App\Models\Internat;
use App\Models\Region;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Validation\Rule;

/**
 * Structure institutionnelle : Région → Complexe / CMC → Établissement → Internat.
 *
 * La table des établissements est aussi le référentiel des EFP : c'est dans
 * cette liste, tenue par l'administrateur, que le stagiaire choisit son
 * établissement à l'inscription — il ne saisit jamais un établissement libre.
 */
class StructureInstitutionnelle
{
    /** @var array<string, class-string<Model>> */
    public const NIVEAUX = [
        'regions' => Region::class,
        'complexes' => Complexe::class,
        'etablissements' => Etablissement::class,
        'internats' => Internat::class,
    ];

    public function __construct(private readonly Journal $journal) {}

    /**
     * Règles de saisie d'un élément.
     *
     * @param  array<string, mixed>  $saisie
     * @return array<string, mixed>
     */
    public static function regles(string $niveau, ?Model $existant, array $saisie): array
    {
        return match ($niveau) {
            'regions' => [
                'code' => ['required', 'string', 'max:20', Rule::unique('region', 'code')->ignore($existant?->getKey())],
                'nom' => ['required', 'string', 'max:150'],
            ],
            'complexes' => [
                'region_id' => ['required', 'integer', 'exists:region,id'],
                'code' => ['required', 'string', 'max:20', Rule::unique('complexe', 'code')->ignore($existant?->getKey())],
                'nom' => ['required', 'string', 'max:150'],
            ],
            'etablissements' => [
                'region_id' => ['required', 'integer', 'exists:region,id'],
                'complexe_id' => ['nullable', 'integer', Rule::exists('complexe', 'id')->where('region_id', $saisie['region_id'] ?? null)],
                'code_efp' => ['required', 'string', 'max:20', Rule::unique('etablissement', 'code_efp')->ignore($existant?->getKey())],
                'nom_efp' => ['required', 'string', 'max:190', Rule::unique('etablissement', 'nom_efp')->ignore($existant?->getKey())],
            ],
            'internats' => [
                'etablissement_id' => ['required', 'integer', 'exists:etablissement,id'],
                'genre' => [
                    'required', Rule::enum(Genre::class),
                    Rule::unique('internat', 'genre')->where('etablissement_id', $saisie['etablissement_id'] ?? null)->ignore($existant?->getKey()),
                ],
                'nom' => ['required', 'string', 'max:150'],
            ],
            default => abort(404),
        };
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'complexe_id.exists' => 'Le complexe doit appartenir à la région choisie.',
            'genre.unique' => 'Cet établissement a déjà un internat pour ce genre.',
        ];
    }

    /** @param array<string, mixed> $donnees données validées par `regles()` */
    public function enregistrer(string $niveau, ?Model $existant, array $donnees): Model
    {
        $classe = self::NIVEAUX[$niveau] ?? abort(404);
        $modele = $existant ?? new $classe;

        if ($modele instanceof Internat && $modele->exists && $modele->genre->value !== $donnees['genre'] && $modele->batiments()->exists()) {
            throw new RegleMetierException('Le genre d\'un internat qui possède déjà un référentiel ne peut pas être changé.', 'genre_verrouille');
        }

        $creation = ! $modele->exists;
        $modele->fill($donnees)->save();

        $this->journal->consigner(
            $creation ? 'organisation.creation' : 'organisation.modification',
            $modele,
            details: $creation ? $donnees : $this->journal->differences($modele),
        );

        return $modele;
    }

    /** Un élément ne se supprime que si rien ne lui est rattaché : la base le garantit. */
    public function supprimer(Model $modele): void
    {
        $table = $modele->getTable();
        $id = (int) $modele->getKey();
        $details = array_filter($modele->only(['code', 'nom', 'code_efp', 'nom_efp']));

        try {
            $modele->delete();
        } catch (QueryException) {
            throw new RegleMetierException('Suppression impossible : des données sont rattachées à cet élément.', 'element_utilise');
        }

        $this->journal->consigner('organisation.suppression', $table, $id, $details);
    }
}
