<?php

namespace App\Livewire\Concerns;

use App\Domain\Identity\ComptesPersonnel;
use App\Models\Complexe;
use App\Models\Etablissement;
use App\Models\Internat;
use App\Models\Province;
use App\Models\Region;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/**
 * Hiérarchie administrative d'un écran du personnel : Région → Province → Complexe / CMC →
 * Établissement (→ Internat). Chaque niveau ne propose que les enfants du niveau choisi
 * au-dessus, dans le périmètre du compte connecté, et changer un niveau efface ceux d'en dessous.
 *
 * Sert aux filtres de liste (propriétés `region` … `etablissement`, reprises dans l'adresse) et,
 * par `cascade()`, aux formulaires qui parcourent la même hiérarchie.
 *
 * À utiliser dans un écran dérivé de PagePersonnel.
 */
trait AvecHierarchie
{
    #[Url(except: '')]
    public string $region = '';

    #[Url(except: '')]
    public string $province = '';

    #[Url(except: '')]
    public string $complexe = '';

    #[Url(except: '')]
    public string $etablissement = '';

    public function updatedRegion(): void
    {
        $this->reset('province', 'complexe', 'etablissement');
    }

    public function updatedProvince(): void
    {
        $this->reset('complexe', 'etablissement');
    }

    public function updatedComplexe(): void
    {
        $this->reset('etablissement');
    }

    /** @return list<string> propriétés des filtres hiérarchiques */
    protected function filtresHierarchiques(): array
    {
        return ['region', 'province', 'complexe', 'etablissement'];
    }

    /**
     * Listes de la hiérarchie pour les niveaux demandés (toujours consécutifs depuis la région).
     * Un choix étranger à son parent, ou hors du périmètre, est effacé avec ceux d'en dessous.
     *
     * @param  array<string, mixed>  $choix  niveau (voir ComptesPersonnel::NIVEAUX) => identifiant choisi
     * @param  list<string>  $niveaux
     * @return array{0: array<string, Collection<int, string>>, 1: array<string, string>} listes, choix épurés
     */
    protected function cascade(array $choix, array $niveaux): array
    {
        $etablissements = $this->perimetre()->etablissementIds($this->utilisateur());
        $internats = $this->perimetre()->internatIds($this->utilisateur());
        $dans = fn (Builder $q, string $chemin) => $etablissements === null ? $q
            : $q->whereHas($chemin, fn (Builder $e) => $e->whereIn('etablissement.id', $etablissements));

        $listes = [];
        $epures = [];
        $parent = null;

        foreach (array_intersect(ComptesPersonnel::NIVEAUX, $niveaux) as $niveau) {
            $liste = $parent === '' ? collect() : match ($niveau) {
                'region_id' => $dans(Region::query(), 'provinces.complexes.etablissements')->orderBy('nom')->pluck('nom', 'id'),
                'province_id' => $dans(Province::where('region_id', (int) $parent), 'complexes.etablissements')->orderBy('nom')->pluck('nom', 'id'),
                'complexe_id' => $dans(Complexe::where('province_id', (int) $parent), 'etablissements')->orderBy('nom')->pluck('nom', 'id'),
                'etablissement_id' => Etablissement::where('complexe_id', (int) $parent)
                    ->when($etablissements !== null, fn (Builder $q) => $q->whereIn('id', $etablissements))
                    ->orderBy('nom_efp')->get()->mapWithKeys(fn (Etablissement $e) => [$e->id => $e->nom_efp.' ('.$e->code_efp.')']),
                'internat_id' => Internat::where('etablissement_id', (int) $parent)
                    ->when($internats !== null, fn (Builder $q) => $q->whereIn('id', $internats))
                    ->orderBy('nom')->get()->mapWithKeys(fn (Internat $i) => [$i->id => $i->nom.' ('.$i->genre->libelle().')']),
            };

            $valeur = (string) ($choix[$niveau] ?? '');
            $epures[$niveau] = $parent !== '' && $liste->has((int) $valeur) ? $valeur : '';
            $listes[$niveau] = $liste;
            $parent = $epures[$niveau];
        }

        return [$listes, $epures];
    }

    /**
     * Listes des filtres hiérarchiques ; les choix incohérents (adresse modifiée à la main) sont effacés.
     *
     * @return array{regions: Collection<int, string>, provinces: Collection<int, string>, complexes: Collection<int, string>, etablissements: Collection<int, string>}
     */
    protected function listesDesFiltres(): array
    {
        [$listes, $choix] = $this->cascade([
            'region_id' => $this->region, 'province_id' => $this->province,
            'complexe_id' => $this->complexe, 'etablissement_id' => $this->etablissement,
        ], ['region_id', 'province_id', 'complexe_id', 'etablissement_id']);

        [$this->region, $this->province, $this->complexe, $this->etablissement] = array_values($choix);

        return [
            'regions' => $listes['region_id'], 'provinces' => $listes['province_id'],
            'complexes' => $listes['complexe_id'], 'etablissements' => $listes['etablissement_id'],
        ];
    }

    protected function hierarchieFiltree(): bool
    {
        return $this->region !== '';
    }

    /** Établissements couverts par le niveau le plus fin choisi, en sous-requête ; nul sans filtre. */
    protected function etablissementsFiltres(): ?Builder
    {
        return match (true) {
            $this->etablissement !== '' => Etablissement::whereKey((int) $this->etablissement)->select('id'),
            $this->complexe !== '' => Etablissement::where('complexe_id', (int) $this->complexe)->select('id'),
            $this->province !== '' => Etablissement::whereIn('complexe_id', Complexe::where('province_id', (int) $this->province)->select('id'))->select('id'),
            $this->region !== '' => Etablissement::whereHas('complexe.province', fn (Builder $p) => $p->where('region_id', (int) $this->region))->select('id'),
            default => null,
        };
    }

    /** Complexes couverts par le niveau choisi, quand il est au-dessus de l'établissement ; nul sinon. */
    protected function complexesFiltres(): ?Builder
    {
        return match (true) {
            $this->etablissement !== '' => null,
            $this->complexe !== '' => Complexe::whereKey((int) $this->complexe)->select('id'),
            $this->province !== '' => Complexe::where('province_id', (int) $this->province)->select('id'),
            $this->region !== '' => Complexe::whereHas('province', fn (Builder $p) => $p->where('region_id', (int) $this->region))->select('id'),
            default => null,
        };
    }
}
