<?php

namespace App\Models;

use App\Enums\StatutExport;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Export asynchrone demandé par un membre du personnel (extension documentée).
 */
class Export extends Model
{
    use HasFactory;

    protected $table = 'export';

    protected $fillable = [
        'utilisateur_id', 'type', 'format', 'filtres', 'statut', 'fichier', 'nom_fichier', 'lignes',
        'erreur', 'termine_le', 'expire_le',
    ];

    protected $hidden = ['fichier'];

    protected function casts(): array
    {
        return [
            'statut' => StatutExport::class,
            'filtres' => 'array',
            'termine_le' => 'datetime',
            'expire_le' => 'datetime',
            'lignes' => 'integer',
        ];
    }

    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'utilisateur_id');
    }
}
