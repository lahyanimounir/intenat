<?php

namespace App\Enums;

use App\Enums\Concerns\AvecLibelle;

/**
 * Raison d'un désistement (statistiques d'abandon).
 */
enum RaisonDesistement: string
{
    use AvecLibelle;

    case Eloignement = 'eloignement';
    case Cout = 'cout';
    case LogementAilleurs = 'logement_ailleurs';
    case AbandonFormation = 'abandon_formation';
    case Autre = 'autre';

    public function libelle(): string
    {
        return match ($this) {
            self::Eloignement => 'Éloignement',
            self::Cout => 'Coût',
            self::LogementAilleurs => 'Logement trouvé ailleurs',
            self::AbandonFormation => 'Abandon de la formation',
            self::Autre => 'Autre',
        };
    }
}
