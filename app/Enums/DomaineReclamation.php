<?php

namespace App\Enums;

use App\Enums\Concerns\AvecLibelle;

/**
 * Le domaine oriente la réclamation vers le bon responsable.
 */
enum DomaineReclamation: string
{
    use AvecLibelle;

    case Hebergement = 'hebergement';
    case Restauration = 'restauration';
    case Selection = 'selection';

    public function libelle(): string
    {
        return match ($this) {
            self::Hebergement => 'Hébergement',
            self::Restauration => 'Restauration',
            self::Selection => 'Sélection (recours)',
        };
    }
}
