<?php

namespace App\Models;

use App\Models\Concerns\LimiteAuPerimetre;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pièce justificative exigée pour une campagne.
 */
class TypePiece extends Model
{
    use HasFactory, LimiteAuPerimetre;

    protected $table = 'type_piece';

    protected $fillable = [
        'campagne_id', 'libelle', 'obligatoire', 'formats_acceptes',
    ];

    protected function casts(): array
    {
        return [
            'obligatoire' => 'boolean',
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

    public function criteres(): HasMany
    {
        return $this->hasMany(Critere::class, 'type_piece_id');
    }
}
