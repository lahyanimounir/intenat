<?php

namespace App\Enums;

use App\Enums\Concerns\AvecLibelle;

/**
 * Événements du séjour.
 */
enum TypeMouvement: string
{
    use AvecLibelle;

    case Arrivee = 'arrivee';
    case Depart = 'depart';
    case ChangementChambre = 'changement_chambre';
    case Exclusion = 'exclusion';
    case NonPresente = 'non_presente';

    public function libelle(): string
    {
        return match ($this) {
            self::Arrivee => 'Arrivée',
            self::Depart => 'Départ',
            self::ChangementChambre => 'Changement de chambre',
            self::Exclusion => 'Exclusion',
            self::NonPresente => 'Non présenté',
        };
    }
}
