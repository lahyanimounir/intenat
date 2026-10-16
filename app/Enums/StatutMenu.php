<?php

namespace App\Enums;

use App\Enums\Concerns\AvecLibelle;

/**
 * Seul un menu validé est diffusé aux stagiaires.
 */
enum StatutMenu: string
{
    use AvecLibelle;

    case Propose = 'propose';
    case Valide = 'valide';

    public function libelle(): string
    {
        return match ($this) {
            self::Propose => 'Proposé',
            self::Valide => 'Validé',
        };
    }
}
