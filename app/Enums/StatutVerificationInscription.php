<?php

namespace App\Enums;

use App\Enums\Concerns\AvecLibelle;

/** État d'une demande de vérification d'inscription (une ligne de `verification_inscription`). */
enum StatutVerificationInscription: string
{
    use AvecLibelle;

    case Soumise = 'soumise';
    case Verifiee = 'verifiee';
    case ACorriger = 'a_corriger';
    case Rejetee = 'rejetee';
    /** Retirée par le stagiaire ou remplacée par une soumission plus récente. */
    case Remplacee = 'remplacee';

    public function libelle(): string
    {
        return match ($this) {
            self::Soumise => 'En attente de vérification',
            self::Verifiee => 'Vérifiée',
            self::ACorriger => 'Correction demandée',
            self::Rejetee => 'Rejetée',
            self::Remplacee => 'Retirée ou remplacée',
        };
    }

    /** Décisions qu'un agent peut rendre sur une demande soumise. */
    public static function decisions(): array
    {
        return [self::Verifiee, self::ACorriger, self::Rejetee];
    }

    public function versStagiaire(): ?StatutInscription
    {
        return match ($this) {
            self::Soumise => StatutInscription::Soumise,
            self::Verifiee => StatutInscription::Verifiee,
            self::ACorriger => StatutInscription::ACorriger,
            self::Rejetee => StatutInscription::Rejetee,
            self::Remplacee => null,
        };
    }
}
