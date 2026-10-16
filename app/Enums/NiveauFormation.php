<?php

namespace App\Enums;

use App\Enums\Concerns\AvecLibelle;

/**
 * Niveau de formation, utilisé par le critère « Formation ».
 */
enum NiveauFormation: string
{
    use AvecLibelle;

    case TechnicienSpecialise = 'technicien_specialise';
    case Technicien = 'technicien';
    case Qualification = 'qualification';
    case Specialisation = 'specialisation';

    public function libelle(): string
    {
        return match ($this) {
            self::TechnicienSpecialise => 'Technicien Spécialisé',
            self::Technicien => 'Technicien',
            self::Qualification => 'Qualification',
            self::Specialisation => 'Spécialisation',
        };
    }
}
