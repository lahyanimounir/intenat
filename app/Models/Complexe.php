<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Complexe / CMC : regroupe des établissements et se rattache à une province.
 * Hiérarchie : Région → Province → Complexe → Établissement.
 */
class Complexe extends Model
{
    use HasFactory;

    protected $table = 'complexe';

    protected $fillable = [
        'province_id', 'code', 'nom',
    ];

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class, 'province_id');
    }

    public function etablissements(): HasMany
    {
        return $this->hasMany(Etablissement::class, 'complexe_id');
    }
}
