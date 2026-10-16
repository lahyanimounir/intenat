<?php

namespace App\Enums;

use App\Enums\Concerns\AvecLibelle;

/**
 * Cycle de vie documenté d'une campagne.
 */
enum StatutCampagne: string
{
    use AvecLibelle;

    case Preparee = 'preparee';
    case Ouverte = 'ouverte';
    case Cloturee = 'cloturee';

    public function libelle(): string
    {
        return match ($this) {
            self::Preparee => 'Préparée',
            self::Ouverte => 'Ouverte',
            self::Cloturee => 'Clôturée',
        };
    }
}
