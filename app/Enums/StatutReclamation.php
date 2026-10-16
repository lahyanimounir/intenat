<?php

namespace App\Enums;

use App\Enums\Concerns\AvecLibelle;

/**
 * Suivi d'une réclamation.
 */
enum StatutReclamation: string
{
    use AvecLibelle;

    case Ouverte = 'ouverte';
    case EnCours = 'en_cours';
    case Traitee = 'traitee';

    public function libelle(): string
    {
        return match ($this) {
            self::Ouverte => 'Ouverte',
            self::EnCours => 'En cours',
            self::Traitee => 'Traitée',
        };
    }
}
