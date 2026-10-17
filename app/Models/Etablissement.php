<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Établissement de formation (EFP) et table de correspondance Nom EFP → Code EFP.
 * Rattaché à un complexe ; la province et la région se déduisent de la chaîne
 * complexe → province → région.
 */
class Etablissement extends Model
{
    use HasFactory;

    protected $table = 'etablissement';

    protected $fillable = [
        'complexe_id', 'code_efp', 'nom_efp',
    ];

    public function complexe(): BelongsTo
    {
        return $this->belongsTo(Complexe::class, 'complexe_id');
    }

    public function internats(): HasMany
    {
        return $this->hasMany(Internat::class, 'etablissement_id');
    }

    public function stagiaires(): HasMany
    {
        return $this->hasMany(Stagiaire::class, 'etablissement_id');
    }
}
