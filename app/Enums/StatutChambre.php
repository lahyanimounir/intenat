<?php

namespace App\Enums;

use App\Enums\Concerns\AvecLibelle;

/**
 * « Maintenance » est un statut de chambre, pas un type.
 */
enum StatutChambre: string
{
    use AvecLibelle;

    case Disponible = 'disponible';
    case Occupee = 'occupee';
    case EnMaintenance = 'en_maintenance';

    public function libelle(): string
    {
        return match ($this) {
            self::Disponible => 'Disponible',
            self::Occupee => 'Occupée',
            self::EnMaintenance => 'En maintenance',
        };
    }
}
