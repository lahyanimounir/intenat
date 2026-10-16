<?php

namespace App\Livewire\Concerns;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

/**
 * Liste paginée côté serveur : recherche, tri limité à une liste blanche de
 * colonnes, et retour à la première page dès qu'un critère change. Recherche,
 * tri et page sont repris dans l'adresse : une liste filtrée se partage et se
 * retrouve au retour d'une fiche.
 */
trait AvecTableau
{
    use WithPagination;

    #[Url(except: '')]
    public string $recherche = '';

    #[Url(except: '')]
    public string $tri = '';

    #[Url(except: 'asc')]
    public string $sens = 'asc';

    public function trier(string $cle): void
    {
        // Premier clic : croissant ; deuxième : décroissant ; troisième : tri par défaut.
        if ($this->tri !== $cle) {
            [$this->tri, $this->sens] = [$cle, 'asc'];
        } elseif ($this->sens === 'asc') {
            $this->sens = 'desc';
        } else {
            [$this->tri, $this->sens] = ['', 'asc'];
        }

        $this->resetPage();
    }

    /** Tout changement d'un critère de liste ramène à la première page. */
    public function updated(string $propriete): void
    {
        if ($propriete === 'recherche' || in_array($propriete, $this->filtres(), true)) {
            $this->resetPage();
        }
    }

    /**
     * Noms des propriétés qui filtrent la liste.
     *
     * @return list<string>
     */
    protected function filtres(): array
    {
        return [];
    }

    /**
     * @param  array<string, string>  $colonnes  clé publique => colonne SQL
     */
    protected function appliquerTri(Builder $requete, array $colonnes, string $defaut, string $sensDefaut = 'asc'): Builder
    {
        if (isset($colonnes[$this->tri])) {
            return $requete->orderBy($colonnes[$this->tri], $this->sens === 'desc' ? 'desc' : 'asc');
        }

        return $requete->orderBy($colonnes[$defaut] ?? $defaut, $sensDefaut);
    }

    protected function paginer(Builder $requete, int $parPage = 25, string $page = 'page'): LengthAwarePaginator
    {
        return $requete->paginate($parPage, pageName: $page);
    }
}
