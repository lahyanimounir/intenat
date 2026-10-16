<?php

namespace App\Models;

use App\Enums\StatutChambre;
use App\Enums\TypeChambre;
use App\Models\Concerns\LimiteAuPerimetre;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Deuxième niveau du référentiel : type de chambre et état de maintenance.
 */
class Chambre extends Model
{
    use HasFactory, LimiteAuPerimetre;

    protected $table = 'chambre';

    protected $fillable = [
        'batiment_id', 'numero', 'etage', 'type_chambre', 'capacite', 'equipements', 'statut',
    ];

    protected function casts(): array
    {
        return [
            'type_chambre' => TypeChambre::class,
            'statut' => StatutChambre::class,
            'etage' => 'integer',
            'capacite' => 'integer',
        ];
    }

    public static function cheminInternat(): string
    {
        return 'batiment';
    }

    public function batiment(): BelongsTo
    {
        return $this->belongsTo(Batiment::class, 'batiment_id');
    }

    public function lits(): HasMany
    {
        return $this->hasMany(Lit::class, 'chambre_id');
    }

    public function demandesMaintenance(): HasMany
    {
        return $this->hasMany(DemandeMaintenance::class, 'chambre_id');
    }
}
