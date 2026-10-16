<?php

namespace App\Models;

use App\Enums\TypeRegime;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Régime particulier déclaré par un stagiaire, avec son justificatif.
 */
class RegimeAlimentaire extends Model
{
    use HasFactory;

    protected $table = 'regime_alimentaire';

    protected $fillable = [
        'stagiaire_id', 'type', 'precision_regime', 'justificatif_fichier',
    ];

    protected $hidden = ['justificatif_fichier'];

    protected function casts(): array
    {
        return [
            'type' => TypeRegime::class,
        ];
    }

    public function stagiaire(): BelongsTo
    {
        return $this->belongsTo(Stagiaire::class, 'stagiaire_id');
    }
}
