<?php

namespace App\Models;

use App\Enums\StatutBatiment;
use App\Models\Concerns\LimiteAuPerimetre;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Premier niveau du référentiel ; hérite du genre de son internat.
 */
class Batiment extends Model
{
    use HasFactory, LimiteAuPerimetre;

    protected $table = 'batiment';

    protected $fillable = [
        'internat_id', 'code', 'nom', 'nombre_etages', 'statut', 'equipements_communs',
    ];

    protected function casts(): array
    {
        return [
            'statut' => StatutBatiment::class,
            'nombre_etages' => 'integer',
        ];
    }

    public static function cheminInternat(): string
    {
        return '';
    }

    public function internat(): BelongsTo
    {
        return $this->belongsTo(Internat::class, 'internat_id');
    }

    public function chambres(): HasMany
    {
        return $this->hasMany(Chambre::class, 'batiment_id');
    }
}
