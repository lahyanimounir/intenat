<?php

namespace App\Models;

use App\Enums\StatutLit;
use App\Models\Concerns\LimiteAuPerimetre;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Dernier niveau du référentiel et unité d'affectation : un stagiaire reçoit un lit précis.
 */
class Lit extends Model
{
    use HasFactory, LimiteAuPerimetre;

    protected $table = 'lit';

    protected $fillable = ['chambre_id', 'numero', 'statut'];

    protected function casts(): array
    {
        return ['statut' => StatutLit::class];
    }

    public static function cheminInternat(): string
    {
        return 'chambre.batiment';
    }

    public function chambre(): BelongsTo
    {
        return $this->belongsTo(Chambre::class, 'chambre_id');
    }

    public function affectations(): HasMany
    {
        return $this->hasMany(Affectation::class, 'lit_id');
    }

    /** Occupant actuel : l'affectation dont la date de fin est vide. */
    public function affectationActive(): HasOne
    {
        return $this->hasOne(Affectation::class, 'lit_id')->whereNull('date_fin');
    }

    public function designation(): string
    {
        return "{$this->chambre?->numero} · lit {$this->numero}";
    }
}
