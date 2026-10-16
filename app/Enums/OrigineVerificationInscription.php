<?php

namespace App\Enums;

use App\Enums\Concerns\AvecLibelle;

/** D'où vient une ligne de vérification d'inscription. */
enum OrigineVerificationInscription: string
{
    use AvecLibelle;

    /** Soumise par le stagiaire depuis son espace. */
    case Declaration = 'declaration';
    /** Identité vérifiée autrefois lors de la première connexion MyWay, reprise à la migration. */
    case Reprise = 'reprise';
    /** Décision prise par un agent sans soumission du stagiaire (contrôle sur registre, réexamen). */
    case Administration = 'administration';

    public function libelle(): string
    {
        return match ($this) {
            self::Declaration => 'Déclaration du stagiaire',
            self::Reprise => 'Reprise d\'une vérification MyWay',
            self::Administration => 'Décision de l\'administration',
        };
    }
}
