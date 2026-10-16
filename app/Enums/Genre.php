<?php

namespace App\Enums;

use App\Enums\Concerns\AvecLibelle;

/**
 * Genre accueilli par un internat et déclaré par le stagiaire.
 */
enum Genre: string
{
    use AvecLibelle;

    case Fille = 'fille';
    case Garcon = 'garcon';

    public function libelle(): string
    {
        return match ($this) {
            self::Fille => 'Fille',
            self::Garcon => 'Garçon',
        };
    }
}
