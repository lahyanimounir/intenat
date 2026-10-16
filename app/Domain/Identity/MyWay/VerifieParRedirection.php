<?php

namespace App\Domain\Identity\MyWay;

/**
 * Vérification déléguée : le stagiaire s'authentifie chez le fournisseur, qui le
 * renvoie vers INTERNAT avec une preuve à échanger (fédération d'identité, SSO,
 * OpenID Connect). INTERNAT ne voit jamais le mot de passe du fournisseur.
 *
 * Aucun adaptateur de production n'implémente encore ce contrat : il attend le
 * protocole que l'OFPPT communiquera. Le parcours (routes, écrans, liaison du
 * retour à la session) est en place et éprouvé par un fournisseur de test.
 */
interface VerifieParRedirection extends FournisseurIdentiteStagiaire
{
    /**
     * Adresse du fournisseur vers laquelle envoyer le stagiaire.
     *
     * @param  string  $etat  Valeur aléatoire liée à la session du navigateur, à faire revenir telle quelle.
     */
    public function urlAutorisation(string $etat, string $urlRetour): string;

    /**
     * Échange la preuve rapportée par le navigateur contre le profil vérifié.
     *
     * @param  array<string, string>  $parametres  Paramètres de retour (hors `etat`, déjà contrôlé).
     *
     * @throws IdentifiantsInvalidesException Preuve refusée ou périmée.
     * @throws FournisseurIndisponibleException
     * @throws ReponseInattendueException
     */
    public function terminer(array $parametres, string $urlRetour): ProfilMyWay;
}
