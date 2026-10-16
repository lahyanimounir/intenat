<?php

namespace App\Enums;

use App\Enums\Concerns\AvecLibelle;

/**
 * Dix statuts documentés, plus deux extensions tracées : délai dépassé et séjour terminé.
 */
enum StatutDemande: string
{
    use AvecLibelle;

    case Brouillon = 'brouillon';
    case Recu = 'recu';
    case Complet = 'complet';
    case RetourRectification = 'retour_rectification';
    case Rejete = 'rejete';
    case Admis = 'admis';
    case Confirme = 'confirme';
    case EnAttente = 'en_attente';
    case Refuse = 'refuse';
    case Desiste = 'desiste';
    case DelaiDepasse = 'delai_depasse';
    case SejourTermine = 'sejour_termine';

    public function libelle(): string
    {
        return match ($this) {
            self::Brouillon => 'Brouillon',
            self::Recu => 'Reçu',
            self::Complet => 'Complet',
            self::RetourRectification => 'Retour pour rectification',
            self::Rejete => 'Rejeté',
            self::Admis => 'Admis',
            self::Confirme => 'Confirmé',
            self::EnAttente => 'En attente',
            self::Refuse => 'Refusé',
            self::Desiste => 'Désisté',
            self::DelaiDepasse => 'Délai dépassé',
            self::SejourTermine => 'Séjour terminé',
        };
    }
}
