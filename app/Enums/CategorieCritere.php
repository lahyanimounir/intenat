<?php

namespace App\Enums;

use App\Enums\Concerns\AvecLibelle;

/**
 * Catégories de la grille de notation sur 80.
 */
enum CategorieCritere: string
{
    use AvecLibelle;

    case Geographique = 'geographique';
    case Social = 'social';
    case Familial = 'familial';
    case Formation = 'formation';
    case Physique = 'physique';

    public function libelle(): string
    {
        return match ($this) {
            self::Geographique => 'Géographique',
            self::Social => 'Social',
            self::Familial => 'Familial',
            self::Formation => 'Formation',
            self::Physique => 'Physique',
        };
    }
}
