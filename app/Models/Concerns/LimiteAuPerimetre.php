<?php

namespace App\Models\Concerns;

use App\Domain\Identity\Perimetre;
use App\Models\Utilisateur;
use Illuminate\Database\Eloquent\Builder;

/**
 * Cloisonnement par internat d'un modèle.
 *
 * Chaque modèle déclare le chemin de relations qui mène à la table portant
 * `internat_id` (chaîne vide si la colonne est sur sa propre table).
 *
 * @method static Builder<static> visiblesPar(Utilisateur $utilisateur)
 */
trait LimiteAuPerimetre
{
    abstract public static function cheminInternat(): string;

    public function scopeVisiblesPar(Builder $requete, Utilisateur $utilisateur): Builder
    {
        $ids = app(Perimetre::class)->internatIds($utilisateur);

        if ($ids === null) {
            return $requete;
        }

        $chemin = static::cheminInternat();

        if ($chemin === '') {
            return $requete->whereIn($requete->qualifyColumn('internat_id'), $ids);
        }

        return $requete->whereHas(
            $chemin,
            fn (Builder $cible) => $cible->whereIn($cible->qualifyColumn('internat_id'), $ids)
        );
    }

    /** Internat auquel se rattache cette ligne. */
    public function internatId(): ?int
    {
        $cible = $this;

        foreach (array_filter(explode('.', static::cheminInternat())) as $relation) {
            $cible = $cible?->{$relation};
        }

        return $cible?->internat_id;
    }
}
