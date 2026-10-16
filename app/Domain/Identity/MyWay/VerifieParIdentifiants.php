<?php

namespace App\Domain\Identity\MyWay;

use App\Domain\Shared\RegleMetierException;
use SensitiveParameter;

/**
 * Vérification par saisie unique des identifiants du fournisseur.
 *
 * Réservée au simulateur et au connecteur de diagnostic : demander son mot de
 * passe MyWay à chaque stagiaire n'est pas un mécanisme de production tant que
 * l'OFPPT ne l'a pas explicitement approuvé.
 */
interface VerifieParIdentifiants extends FournisseurIdentiteStagiaire
{
    /**
     * @throws IdentifiantsInvalidesException Identifiants refusés par le fournisseur.
     * @throws FournisseurIndisponibleException Fournisseur injoignable, délai dépassé ou non configuré.
     * @throws ReponseInattendueException Réponse illisible ou incohérente.
     * @throws RegleMetierException Refus propre à une connexion réelle : compte non autorisé,
     *                              vérification humaine demandée, information non vérifiée.
     */
    public function authentifier(#[SensitiveParameter] string $email, #[SensitiveParameter] string $motDePasse): ProfilMyWay;
}
