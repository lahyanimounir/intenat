<?php

namespace App\Http\Controllers;

use App\Domain\Identity\Perimetre;
use App\Models\Stagiaire;
use App\Models\Utilisateur;
use BackedEnum;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

abstract class Controller
{
    protected function personnel(): Utilisateur
    {
        $utilisateur = request()->user();
        abort_unless($utilisateur instanceof Utilisateur, 403);

        return $utilisateur;
    }

    protected function stagiaire(): Stagiaire
    {
        $stagiaire = request()->user();
        abort_unless($stagiaire instanceof Stagiaire, 403);

        return $stagiaire;
    }

    protected function perimetre(): Perimetre
    {
        return app(Perimetre::class);
    }

    /** Permission détenue ET ligne dans le périmètre de l'utilisateur. */
    protected function autoriser(Model $modele, string $permission): void
    {
        Gate::authorize('perimetre', [$modele, $permission]);
    }

    /** Pagination côté serveur, taille de page bornée. */
    protected function paginer(Builder $requete, Request $request, int $defaut = 25): LengthAwarePaginator
    {
        $parPage = min(100, max(5, (int) $request->integer('par_page', $defaut)));

        return $requete->paginate($parPage)->withQueryString();
    }

    /**
     * Tri limité à une liste blanche de colonnes.
     *
     * @param  array<string, string>  $colonnes  clé publique => colonne SQL
     */
    protected function trier(Builder $requete, Request $request, array $colonnes, string $defaut, string $sensDefaut = 'asc'): Builder
    {
        $cle = (string) $request->query('tri', '');
        $sens = $request->query('sens') === 'desc' ? 'desc' : ($request->query('sens') === 'asc' ? 'asc' : $sensDefaut);

        return $requete->orderBy($colonnes[$cle] ?? $colonnes[$defaut], isset($colonnes[$cle]) ? $sens : $sensDefaut);
    }

    /** @return array{valeur: string, libelle: string}|null */
    protected static function enum(?BackedEnum $cas): ?array
    {
        return $cas === null ? null : ['valeur' => $cas->value, 'libelle' => $cas->libelle()];
    }
}
