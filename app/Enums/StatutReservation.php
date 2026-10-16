<?php

namespace App\Enums;

use App\Enums\Concerns\AvecLibelle;

/**
 * Cycle d'une réservation de repas.
 */
enum StatutReservation: string
{
    use AvecLibelle;

    case Reserve = 'reserve';
    case Annule = 'annule';
    case Servi = 'servi';
    case Absent = 'absent';

    public function libelle(): string
    {
        return match ($this) {
            self::Reserve => 'Réservé',
            self::Annule => 'Annulé',
            self::Servi => 'Servi',
            self::Absent => 'Absent',
        };
    }
}
