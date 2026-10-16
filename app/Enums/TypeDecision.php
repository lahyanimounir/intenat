<?php

namespace App\Enums;

use App\Enums\Concerns\AvecLibelle;

/**
 * Décision du chef de pôle scolarité.
 */
enum TypeDecision: string
{
    use AvecLibelle;

    case Admis = 'admis';
    case ListeAttente = 'liste_attente';
    case Refuse = 'refuse';

    public function libelle(): string
    {
        return match ($this) {
            self::Admis => 'Admis',
            self::ListeAttente => 'Liste d\'attente',
            self::Refuse => 'Refusé',
        };
    }
}
