<?php

namespace App\Enums;

use App\Enums\Concerns\AvecLibelle;

/**
 * État de contrôle d'une pièce justificative.
 */
enum StatutPiece: string
{
    use AvecLibelle;

    case Deposee = 'deposee';
    case Acceptee = 'acceptee';
    case ARemplacer = 'a_remplacer';

    public function libelle(): string
    {
        return match ($this) {
            self::Deposee => 'Déposée',
            self::Acceptee => 'Acceptée',
            self::ARemplacer => 'À remplacer',
        };
    }
}
