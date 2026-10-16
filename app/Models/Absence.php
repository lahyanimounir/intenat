<?php

namespace App\Models;

use App\Models\Concerns\LimiteAuPerimetre;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Absence constatée un jour donné par le gestionnaire.
 */
class Absence extends Model
{
    use HasFactory, LimiteAuPerimetre;

    protected $table = 'absence';

    protected $fillable = [
        'affectation_id', 'demande_sortie_id', 'date_absence', 'autorisee', 'saisi_par',
    ];

    protected function casts(): array
    {
        return [
            'date_absence' => 'date',
            'autorisee' => 'boolean',
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

    public function sortie(): BelongsTo
    {
        return $this->belongsTo(DemandeSortie::class, 'demande_sortie_id');
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'saisi_par');
    }
}
