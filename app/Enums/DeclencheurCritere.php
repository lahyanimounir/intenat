<?php

namespace App\Enums;

use App\Enums\Concerns\AvecLibelle;

/**
 * Extension : donnée du dossier qui déclenche un critère (rend le calcul déterministe).
 */
enum DeclencheurCritere: string
{
    use AvecLibelle;

    case EloigneOuRural = 'eloigne_ou_rural';
    case Demuni = 'demuni';
    case OrphelinOuDivorce = 'orphelin_ou_divorce';
    case BesoinsSpecifiques = 'besoins_specifiques';
    case NiveauFormation = 'niveau_formation';

    public function libelle(): string
    {
        return match ($this) {
            self::EloigneOuRural => 'Éloignement ou milieu rural',
            self::Demuni => 'Stagiaire démuni',
            self::OrphelinOuDivorce => 'Orphelin ou parents divorcés',
            self::BesoinsSpecifiques => 'Besoins spécifiques',
            self::NiveauFormation => 'Niveau de formation',
        };
    }
}
