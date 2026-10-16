<?php

namespace App\Enums;

use App\Enums\Concerns\AvecLibelle;

/**
 * État du compte local d'un stagiaire.
 *
 * `en_attente` ne concerne que les lignes héritées de l'ancien parcours, où le
 * profil était enregistré avant le choix du mot de passe : depuis la migration
 * 000800, une vérification en cours vit dans `verification_identite` et la
 * ligne `stagiaire` n'est créée qu'à l'activation.
 */
enum EtatCompte: string
{
    use AvecLibelle;

    case EnAttente = 'en_attente';
    case Actif = 'actif';
    case Suspendu = 'suspendu';

    public function libelle(): string
    {
        return match ($this) {
            self::EnAttente => 'En attente d\'activation',
            self::Actif => 'Actif',
            self::Suspendu => 'Suspendu',
        };
    }
}
