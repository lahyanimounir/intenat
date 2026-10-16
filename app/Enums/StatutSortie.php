<?php

namespace App\Enums;

use App\Enums\Concerns\AvecLibelle;

/**
 * Traitement d'une demande de sortie.
 */
enum StatutSortie: string
{
    use AvecLibelle;

    case EnAttente = 'en_attente';
    case Acceptee = 'acceptee';
    case Refusee = 'refusee';

    public function libelle(): string
    {
        return match ($this) {
            self::EnAttente => 'En attente',
            self::Acceptee => 'Acceptée',
            self::Refusee => 'Refusée',
        };
    }
}
