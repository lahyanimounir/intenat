<?php

namespace App\Enums;

use App\Enums\Concerns\AvecLibelle;

/**
 * Type de chambre (dictionnaire : chambre.type_chambre).
 */
enum TypeChambre: string
{
    use AvecLibelle;

    case Standard = 'standard';
    case Pmr = 'pmr';
    case Isolement = 'isolement';
    case Infirmerie = 'infirmerie';

    public function libelle(): string
    {
        return match ($this) {
            self::Standard => 'Standard',
            self::Pmr => 'PMR',
            self::Isolement => 'Isolement',
            self::Infirmerie => 'Infirmerie',
        };
    }
}
