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
    case Vegetarien = 'vegetarien';
    case Autre = 'autre';

    public function libelle(): string
    {
        return match ($this) {
            self::Allergie => 'Allergie',
            self::RestrictionMedicale => 'Restriction médicale',
            self::RestrictionReligieuse => 'Restriction religieuse',
            self::Vegetarien => 'Végétarien',
            self::Autre => 'Autre restriction alimentaire',
        };
    }

    /** Une allergie ou une restriction médicale se justifie par un document médical. */
    public function exigeJustificatif(): bool
    {
        return in_array($this, [self::Allergie, self::RestrictionMedicale], true);
    }
}
