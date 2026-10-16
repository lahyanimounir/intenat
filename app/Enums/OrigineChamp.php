<?php

namespace App\Enums;

use App\Enums\Concerns\AvecLibelle;

/**
 * D'où vient une information du profil d'un stagiaire. Une information
 * manquante n'est jamais comblée par une valeur supposée.
 */
enum OrigineChamp: string
{
    use AvecLibelle;

    /** Lue auprès du fournisseur d'identité, sans modification. */
    case Importe = 'importe';

    /** Déduite d'une table tenue par l'administration (Nom EFP → Code EFP). */
    case Correspondance = 'correspondance';

    /** Absente de la réponse du fournisseur. */
    case Manquant = 'manquant';

    /** Saisie ou corrigée par le stagiaire : à contrôler sur pièce. */
    case Declare = 'declare';

    public function libelle(): string
    {
        return match ($this) {
            self::Importe => 'Vérifié auprès de MyWay',
            self::Correspondance => 'Déduit du référentiel des établissements',
            self::Manquant => 'Non communiqué',
            self::Declare => 'Déclaré par le stagiaire',
        };
    }
}
