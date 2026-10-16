<?php

namespace App\Models;

use App\Enums\RaisonDesistement;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Renoncement d'un stagiaire à sa place, avec explication obligatoire.
 */
class Desistement extends Model
{
    use HasFactory;

    protected $table = 'desistement';

    public $timestamps = false;

    protected $fillable = [
        'demande_id', 'raison', 'explication', 'date_desistement',
    ];

    protected function casts(): array
    {
        return [
            'raison' => RaisonDesistement::class,
            'date_desistement' => 'datetime',
        ];
    }

    public function demande(): BelongsTo
    {
        return $this->belongsTo(Demande::class, 'demande_id');
    }
}
