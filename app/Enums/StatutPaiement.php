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
            self::EnAttente => 'En attente de paiement',
            // Ancien statut (autorisation par le chef de pôle, supprimée) : même sens que « en attente de paiement ».
            self::Autorise => 'En attente de paiement',
            self::RecuDepose => 'Justificatif déposé, en cours de vérification',
            self::Valide => 'Paiement validé',
            self::Refuse => 'Justificatif rejeté, à corriger',
        };
    }
}
