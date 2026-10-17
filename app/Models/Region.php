<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * Direction régionale : regroupe des provinces et borne le périmètre régional.
 * Hiérarchie : Région → Province → Complexe → Établissement.
 */
class Region extends Model
{
    use HasFactory;

    protected $table = 'region';

    protected $fillable = [
        'code', 'nom',
    ];

    public function provinces(): HasMany
    {
        return $this->hasMany(Province::class, 'region_id');
    }

    /** Complexes de la région, à travers ses provinces. */
    public function complexes(): HasManyThrough
    {
        return $this->hasManyThrough(Complexe::class, Province::class, 'region_id', 'province_id');
    }
}
