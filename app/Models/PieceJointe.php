<?php

namespace App\Models;

use App\Enums\StatutPiece;
use App\Models\Concerns\LimiteAuPerimetre;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fichier téléversé par le stagiaire pour sa demande, avec son état de contrôle.
 */
class PieceJointe extends Model
{
    use HasFactory, LimiteAuPerimetre;

    protected $table = 'piece_jointe';

    protected $fillable = [
        'demande_id', 'type_piece_id', 'fichier', 'statut', 'date_depot', 'nom_original', 'mime', 'taille',
        'commentaire', 'controle_par', 'date_controle',
    ];

    protected $hidden = ['fichier'];

    protected function casts(): array
    {
        return [
            'statut' => StatutPiece::class,
            'date_depot' => 'datetime',
            'date_controle' => 'datetime',
            'taille' => 'integer',
        ];
    }

    public static function cheminInternat(): string
    {
        return 'demande.campagne';
    }

    public function demande(): BelongsTo
    {
        return $this->belongsTo(Demande::class, 'demande_id');
    }

    public function typePiece(): BelongsTo
    {
        return $this->belongsTo(TypePiece::class, 'type_piece_id');
    }

    public function controleur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'controle_par');
    }
}
