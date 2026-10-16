<?php

namespace App\Enums;

use App\Enums\Concerns\AvecLibelle;

/**
 * Repas de la journée.
 */
enum TypeRepas: string
{
    use AvecLibelle;

    case PetitDejeuner = 'petit_dejeuner';
    case Dejeuner = 'dejeuner';
    case Diner = 'diner';

    public function libelle(): string
    {
        return match ($this) {
            self::PetitDejeuner => 'Petit déjeuner',
            self::Dejeuner => 'Déjeuner',
            self::Diner => 'Dîner',
        };
    }
}
