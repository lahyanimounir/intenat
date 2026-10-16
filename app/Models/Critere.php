<?php

namespace App\Models;

use App\Enums\CategorieCritere;
use App\Enums\DeclencheurCritere;
use App\Models\Concerns\LimiteAuPerimetre;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Critère de la grille de notation d'une campagne.
 */
class Critere extends Model
{
    use HasFactory, LimiteAuPerimetre;

    protected $table = 'critere';

    protected $fillable = [
        'campagne_id', 'type_piece_id', 'categorie', 'condition_texte', 'points', 'declencheur',
        'valeur_attendue',
    ];

    protected function casts(): array
    {
        return [
            'categorie' => CategorieCritere::class,
            'declencheur' => DeclencheurCritere::class,
            'points' => 'integer',
        ];
    }

    public static function cheminInternat(): string
    {
        return 'campagne';
    }

    public function campagne(): BelongsTo
    {
        return $this->belongsTo(Campagne::class, 'campagne_id');
    }

    public function typePiece(): BelongsTo
    {
        return $this->belongsTo(TypePiece::class, 'type_piece_id');
    }
}
