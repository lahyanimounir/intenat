<?php

namespace App\Enums;

use App\Enums\Concerns\AvecLibelle;

/**
 * Suivi d'un paiement : autorisation, dépôt du reçu puis contrôle.
 */
enum StatutPaiement: string
{
    use AvecLibelle;

    case EnAttente = 'en_attente';
    case Autorise = 'autorise';
    case RecuDepose = 'recu_depose';
    case Valide = 'valide';
    case Refuse = 'refuse';

    public function libelle(): string
    {
        return match ($this) {
            self::EnAttente => 'En attente',
            self::Autorise => 'Autorisé',
            self::RecuDepose => 'Reçu déposé',
            self::Valide => 'Validé',
            self::Refuse => 'Refusé',
        };
    }
}
