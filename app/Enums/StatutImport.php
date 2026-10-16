<?php

namespace App\Enums;

use App\Enums\Concerns\AvecLibelle;

/**
 * Extension : suivi d'un import CSV du référentiel.
 */
enum StatutImport: string
{
    use AvecLibelle;

    case Analyse = 'analyse';
    case Importe = 'importe';
    case Annule = 'annule';

    public function libelle(): string
    {
        return match ($this) {
            self::Analyse => 'Analysé',
            self::Importe => 'Importé',
            self::Annule => 'Annulé',
        };
    }
}
