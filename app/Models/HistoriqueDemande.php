<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Changement de statut d'un dossier : auteur, date, motif, avant, après.
 */
class HistoriqueDemande extends Model
{
    use HasFactory;

    protected $table = 'historique_demande';

    public $timestamps = false;

    protected $fillable = [
        'demande_id', 'ancien_statut', 'nouveau_statut', 'utilisateur_id', 'stagiaire_id', 'automatique',
        'motif', 'date_heure',
    ];

    protected function casts(): array
    {
        return [
            'automatique' => 'boolean',
            'date_heure' => 'datetime',
        ];
    }

    public function demande(): BelongsTo
    {
        return $this->belongsTo(Demande::class, 'demande_id');
    }

    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'utilisateur_id');
    }

    public function stagiaire(): BelongsTo
    {
        return $this->belongsTo(Stagiaire::class, 'stagiaire_id');
    }
}
