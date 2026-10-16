<?php

namespace App\Models;

use App\Enums\CategorieCritere;
use App\Enums\DeclencheurCritere;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Grille nationale de référence proposée par la Direction centrale (extension documentée).
 */
class CritereReference extends Model
{
    use HasFactory;

    protected $table = 'critere_reference';

    protected $fillable = [
        'categorie', 'declencheur', 'valeur_attendue', 'condition_texte', 'points', 'piece_libelle',
        'actif',
    ];

    protected function casts(): array
    {
        return [
            'categorie' => CategorieCritere::class,
            'declencheur' => DeclencheurCritere::class,
            'points' => 'integer',
            'actif' => 'boolean',
        ];
    }
}
