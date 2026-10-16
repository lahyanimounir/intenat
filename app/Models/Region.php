<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Direction régionale : regroupe les établissements et borne le périmètre régional.
 */
class Region extends Model
{
    use HasFactory;

    protected $table = 'region';

    protected $fillable = [
        'code', 'nom',
    ];

    public function complexes(): HasMany
    {
        return $this->hasMany(Complexe::class, 'region_id');
    }

    public function etablissements(): HasMany
    {
        return $this->hasMany(Etablissement::class, 'region_id');
    }
}
