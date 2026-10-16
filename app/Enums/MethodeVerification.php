<?php

namespace App\Enums;

use App\Enums\Concerns\AvecLibelle;

/**
 * Manière dont un fournisseur d'identité établit qui est le stagiaire.
 * L'écran de première connexion s'adapte à la méthode ; le reste du parcours
 * (revue du profil, mot de passe INTERNAT, activation) est identique.
 */
enum MethodeVerification: string
{
    use AvecLibelle;

    /** Le stagiaire saisit une fois ses identifiants du fournisseur (simulateur, connecteur de diagnostic). */
    case Identifiants = 'identifiants';

    /** Le stagiaire s'authentifie chez le fournisseur, qui le renvoie vers INTERNAT (SSO, OpenID Connect). */
    case Redirection = 'redirection';

    /** Le stagiaire prouve son identité par un code à usage unique envoyé sur un canal enregistré (registre institutionnel). */
    case Code = 'code';

    /** Aucun mécanisme approuvé n'est configuré : la première connexion est fermée. */
    case Indisponible = 'indisponible';

    public function libelle(): string
    {
        return match ($this) {
            self::Identifiants => 'Identifiants MyWay',
            self::Redirection => 'Authentification déléguée',
            self::Code => 'Code à usage unique',
            self::Indisponible => 'Non configurée',
        };
    }
}
