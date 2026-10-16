<?php

namespace App\Enums;

use App\Enums\Concerns\AvecLibelle;

/**
 * Régimes alimentaires particuliers.
 */
enum TypeRegime: string
{
    use AvecLibelle;

    case Allergie = 'allergie';
    case RestrictionMedicale = 'restriction_medicale';
    case RestrictionReligieuse = 'restriction_religieuse';

    public function libelle(): string
    {
        return match ($this) {
            self::Allergie => 'Allergie',
            self::RestrictionMedicale => 'Restriction médicale',
            self::RestrictionReligieuse => 'Restriction religieuse',
        };
    }
}
