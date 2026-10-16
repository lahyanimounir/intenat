<?php

namespace App\Domain\Identity\MyWay;

use App\Enums\MethodeVerification;

/**
 * Contrat commun des fournisseurs d'identité des stagiaires.
 *
 * INTERNAT n'a besoin du fournisseur qu'une fois par stagiaire : établir qui il
 * est et quelle est sa candidature éligible. La manière de l'établir dépend du
 * mécanisme autorisé — d'où trois contrats spécialisés, un par méthode :
 *
 *   VerifieParIdentifiants ... le stagiaire saisit une fois ses identifiants du fournisseur
 *   VerifieParRedirection .... le stagiaire s'authentifie chez le fournisseur (SSO, OpenID Connect)
 *   VerifieParCode ........... registre institutionnel et code à usage unique
 *
 * Changer de fournisseur ne touche ni l'interface, ni la table `stagiaire`, ni
 * les workflows d'hébergement : tout passe par `ProfilMyWay`.
 *
 * Adaptateurs livrés, jamais substitués l'un à l'autre en silence :
 *  - FournisseurSimule : profils synthétiques, pour le développement, les tests
 *    et la démonstration. Ne contacte jamais MyWay.
 *  - FournisseurConnecteur : outil de diagnostic — connexion réelle par un
 *    navigateur piloté (service `myway-connector`), limitée aux comptes dont le
 *    titulaire l'a autorisée, jamais en production (ADR 0006).
 *  - FournisseurApprouve : emplacement de l'intégration officielle, fermé tant
 *    que l'OFPPT n'a pas communiqué de mécanisme approuvé.
 *
 * Aucune implémentation ne contourne une protection du fournisseur (pare-feu,
 * CAPTCHA, code de vérification) ni ne complète une information qu'elle n'a
 * pas lue. Un secret reçu du stagiaire n'est jamais conservé, journalisé ni mis en cache.
 */
interface FournisseurIdentiteStagiaire
{
    /** Identifiant technique de l'adaptateur (`mock`, `connecteur`, `approuve`…). */
    public function nom(): string;

    /** Vrai si les profils sont synthétiques : l'interface doit alors l'afficher. */
    public function estSimule(): bool;

    /** Méthode de vérification proposée au stagiaire ; `Indisponible` ferme la première connexion. */
    public function methode(): MethodeVerification;
}
