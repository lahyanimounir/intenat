<?php

namespace App\Models;

use App\Enums\TypeDocument;
use App\Models\Concerns\LimiteAuPerimetre;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Document utile mis à la disposition des stagiaires d'un internat.
 */
class Document extends Model
{
    use HasFactory, LimiteAuPerimetre;

    protected $table = 'document';

    protected $fillable = [
        'internat_id', 'titre', 'type', 'fichier', 'nom_original', 'mime', 'date_publication',
        'publie_par',
    ];

    protected $hidden = ['fichier'];

    protected function casts(): array
    {
        return [
            'type' => TypeDocument::class,
            'date_publication' => 'datetime',
        ];
    }

    public static function cheminInternat(): string
    {
        return '';
    }

    public function internat(): BelongsTo
    {
        return $this->belongsTo(Internat::class, 'internat_id');
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'publie_par');
    }
}
