<?php

namespace App\Enums;

use App\Enums\Concerns\AvecLibelle;

/**
 * Documents mis à disposition des stagiaires.
 */
enum TypeDocument: string
{
    use AvecLibelle;

    case ReglementInterieur = 'reglement_interieur';
    case NoteInformation = 'note_information';
    case DatesArrivee = 'dates_arrivee';
    case Autre = 'autre';

    public function libelle(): string
    {
        return match ($this) {
            self::ReglementInterieur => 'Règlement intérieur',
            self::NoteInformation => 'Note d\'information',
            self::DatesArrivee => 'Dates d\'arrivée',
            self::Autre => 'Autre',
        };
    }
}
