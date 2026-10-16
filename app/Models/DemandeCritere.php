<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Détail du score : critère retenu pour un dossier et points obtenus.
 * Justifie le classement en cas de réclamation.
 */
class DemandeCritere extends Model
{
    protected $table = 'demande_critere';

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = null;

    protected $fillable = ['demande_id', 'critere_id', 'points_obtenus', 'version_grille'];

    protected function casts(): array
    {
        return ['points_obtenus' => 'integer', 'version_grille' => 'integer'];
    }

    public function demande(): BelongsTo
    {
        return $this->belongsTo(Demande::class, 'demande_id');
    }

    public function critere(): BelongsTo
    {
        return $this->belongsTo(Critere::class, 'critere_id');
    }
}
