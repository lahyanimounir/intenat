<?php

namespace App\Enums;

use App\Enums\Concerns\AvecLibelle;

/** Année du cursus dans laquelle le stagiaire est inscrit. */
enum AnneeFormation: string
{
    use AvecLibelle;

    case Premiere = '1';
    case Deuxieme = '2';
    case Troisieme = '3';

    public function libelle(): string
    {
        return match ($this) {
            self::Premiere => '1re année',
            self::Deuxieme => '2e année',
            self::Troisieme => '3e année',
        };
    }
}
