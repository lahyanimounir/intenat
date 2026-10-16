<?php

namespace App\Enums;

use App\Enums\Concerns\AvecLibelle;

/**
 * Croisée au genre de l'internat, donne les quatre listes.
 */
enum ListeAdmission: string
{
    use AvecLibelle;

    case Principale = 'principale';
    case Attente = 'attente';

    public function libelle(): string
    {
        return match ($this) {
            self::Principale => 'Liste principale',
            self::Attente => 'Liste d\'attente',
        };
    }
}
