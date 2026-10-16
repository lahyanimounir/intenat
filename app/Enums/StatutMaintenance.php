<?php

namespace App\Enums;

use App\Enums\Concerns\AvecLibelle;

/**
 * Suivi d'une demande de réparation.
 */
enum StatutMaintenance: string
{
    use AvecLibelle;

    case Signalee = 'signalee';
    case EnCours = 'en_cours';
    case Resolue = 'resolue';

    public function libelle(): string
    {
        return match ($this) {
            self::Signalee => 'Signalée',
            self::EnCours => 'En cours',
            self::Resolue => 'Résolue',
        };
    }
}
