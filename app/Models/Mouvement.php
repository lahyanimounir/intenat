<?php

namespace App\Models;

use App\Enums\TypeMouvement;
use App\Models\Concerns\LimiteAuPerimetre;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Événement du séjour : arrivée, départ, changement de chambre, exclusion, non présenté.
 */
class Mouvement extends Model
{
    use HasFactory, LimiteAuPerimetre;

    protected $table = 'mouvement';

    public $timestamps = false;

    protected $fillable = [
        'affectation_id', 'type', 'date_mouvement', 'motif', 'saisi_par',
    ];

    protected function casts(): array
    {
        return [
            'type' => TypeMouvement::class,
            'date_mouvement' => 'datetime',
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

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'saisi_par');
    }
}
