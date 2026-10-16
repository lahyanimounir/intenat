<?php

namespace App\Enums;

use App\Enums\Concerns\AvecLibelle;

/**
 * Extension : suivi d'un export asynchrone.
 */
enum StatutExport: string
{
    use AvecLibelle;

    case EnAttente = 'en_attente';
    case EnCours = 'en_cours';
    case Termine = 'termine';
    case Echec = 'echec';

    public function libelle(): string
    {
        return match ($this) {
            self::EnAttente => 'En attente',
            self::EnCours => 'En cours',
            self::Termine => 'Terminé',
            self::Echec => 'Échec',
        };
    }
}
