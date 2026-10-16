<?php

namespace App\Models;

use App\Enums\StatutSortie;
use App\Models\Concerns\LimiteAuPerimetre;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Demande de sortie déposée par le stagiaire ; acceptée, elle rend l'absence autorisée.
 */
class DemandeSortie extends Model
{
    use HasFactory, LimiteAuPerimetre;

    protected $table = 'demande_sortie';

    protected $fillable = [
        'affectation_id', 'date_depart', 'date_retour', 'motif', 'statut', 'traite_par', 'date_traitement',
        'motif_refus',
    ];

    protected function casts(): array
    {
        return [
            'statut' => StatutSortie::class,
            'date_depart' => 'datetime',
            'date_retour' => 'datetime',
            'date_traitement' => 'datetime',
        ];
    }

    public static function cheminInternat(): string
    {
        return 'affectation.demande.campagne';
    }

    public function affectation(): BelongsTo
    {
        return $this->belongsTo(Affectation::class, 'affectation_id');
    }

    public function traitant(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'traite_par');
    }
}
