<?php

namespace App\Enums;

use App\Enums\Concerns\AvecLibelle;

/**
 * Les cinq premiers types sont documentés ; les suivants sont des extensions tracées.
 */
enum TypeNotification: string
{
    use AvecLibelle;

    case ChangementStatut = 'changement_statut';
    case Resultat = 'resultat';
    case RappelPaiement = 'rappel_paiement';
    case PlaceLibre = 'place_libre';
    case AbsenceNonAutorisee = 'absence_non_autorisee';
    case EcheanceConfirmation = 'echeance_confirmation';
    case Affectation = 'affectation';
    case Maintenance = 'maintenance';
    case Approbation = 'approbation';
    case Reclamation = 'reclamation';
    case Information = 'information';

    public function libelle(): string
    {
        return match ($this) {
            self::ChangementStatut => 'Changement de statut',
            self::Resultat => 'Résultat',
            self::RappelPaiement => 'Rappel de paiement',
            self::PlaceLibre => 'Place libre',
            self::AbsenceNonAutorisee => 'Absence non autorisée',
            self::EcheanceConfirmation => 'Échéance de confirmation',
            self::Affectation => 'Affectation',
            self::Maintenance => 'Maintenance',
            self::Approbation => 'Approbation requise',
            self::Reclamation => 'Réclamation',
            self::Information => 'Information',
        };
    }
}
