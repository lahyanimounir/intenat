<?php

namespace App\Models;

use App\Domain\Identity\Perimetre;
use App\Enums\Genre;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * Maison des Stagiaires. Le genre est porté par l'internat, pas par ses
 * bâtiments : un établissement compte un internat filles et un internat garçons,
 * chacun avec son gestionnaire, son référentiel et ses listes.
 */
class Internat extends Model
{
    use HasFactory;

    protected $table = 'internat';

    protected $fillable = ['etablissement_id', 'genre', 'nom'];

    protected function casts(): array
    {
        return ['genre' => Genre::class];
    }

    public function scopeVisiblesPar(Builder $requete, Utilisateur $utilisateur): Builder
    {
        $ids = app(Perimetre::class)->internatIds($utilisateur);

        return $ids === null ? $requete : $requete->whereIn($requete->qualifyColumn('id'), $ids);
    }

    public function etablissement(): BelongsTo
    {
        return $this->belongsTo(Etablissement::class, 'etablissement_id');
    }

    public function batiments(): HasMany
    {
        return $this->hasMany(Batiment::class, 'internat_id');
    }

    public function chambres(): HasManyThrough
    {
        return $this->hasManyThrough(Chambre::class, Batiment::class, 'internat_id', 'batiment_id');
    }

    public function campagnes(): HasMany
    {
        return $this->hasMany(Campagne::class, 'internat_id');
    }

    public function gestionnaires(): HasMany
    {
        return $this->hasMany(Utilisateur::class, 'internat_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'internat_id');
    }

    public function menus(): HasMany
    {
        return $this->hasMany(Menu::class, 'internat_id');
    }
}
