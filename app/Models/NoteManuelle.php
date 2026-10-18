<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Note d'un critère rectifiée à la main par le chef de pôle, après clôture.
 *
 * Elle prime sur le calcul automatique : {@see \App\Domain\Admissions\CalculScore}
 * la consulte pour un critère donné et retient ses points à la place de ceux
 * déduits de la déclaration et des pièces. Sa suppression rend la main au calcul.
 */
class NoteManuelle extends Model
{
    protected $table = 'demande_note_manuelle';

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = null;

    protected $fillable = ['demande_id', 'critere_id', 'points', 'motif', 'ajuste_par', 'ajuste_le'];

    protected function casts(): array
    {
        return ['points' => 'integer', 'ajuste_le' => 'datetime'];
    }

    public function demande(): BelongsTo
    {
        return $this->belongsTo(Demande::class, 'demande_id');
    }

    public function critere(): BelongsTo
    {
        return $this->belongsTo(Critere::class, 'critere_id');
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'ajuste_par');
    }
}
