<?php

namespace App\Enums;

use App\Enums\Concerns\AvecLibelle;

/**
 * Nature d'un incident.
 */
enum TypeIncident: string
{
    use AvecLibelle;

    case Discipline = 'discipline';
    case Cohabitation = 'cohabitation';
    case Degradation = 'degradation';

    public function libelle(): string
    {
        return match ($this) {
            self::Discipline => 'Discipline',
            self::Cohabitation => 'Cohabitation',
            self::Degradation => 'Dégradation',
        };
    }
}
