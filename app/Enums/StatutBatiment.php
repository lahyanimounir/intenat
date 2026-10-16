<?php

namespace App\Enums;

use App\Enums\Concerns\AvecLibelle;

/**
 * Seul un bâtiment ouvert reçoit des affectations.
 */
enum StatutBatiment: string
{
    use AvecLibelle;

    case Ouvert = 'ouvert';
    case EnTravaux = 'en_travaux';
    case Ferme = 'ferme';

    public function libelle(): string
    {
        return match ($this) {
            self::Ouvert => 'Ouvert',
            self::EnTravaux => 'En travaux',
            self::Ferme => 'Fermé',
        };
    }
}
