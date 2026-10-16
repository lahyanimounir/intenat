<?php

namespace App\Enums;

use App\Enums\Concerns\AvecLibelle;

/**
 * Statut d'un lit, unité d'affectation.
 */
enum StatutLit: string
{
    use AvecLibelle;

    case Disponible = 'disponible';
    case Occupe = 'occupe';
    case HorsService = 'hors_service';

    public function libelle(): string
    {
        return match ($this) {
            self::Disponible => 'Disponible',
            self::Occupe => 'Occupé',
            self::HorsService => 'Hors service',
        };
    }
}
