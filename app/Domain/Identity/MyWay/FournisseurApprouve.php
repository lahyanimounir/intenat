<?php

namespace App\Domain\Identity\MyWay;

use App\Enums\MethodeVerification;

/**
 * Intégration officielle MyWay — EN ATTENTE, BLOQUANTE POUR LA PRODUCTION.
 *
 * L'OFPPT a autorisé le projet INTERNAT, mais aucun mécanisme d'intégration n'a
 * été communiqué : ni API, ni fédération d'identité, ni export signé. Cet
 * adaptateur ne propose donc aucune méthode de vérification : la première
 * connexion est fermée, plutôt que simulée ou bricolée par extraction de pages.
 *
 * À faire à réception du protocole : écrire l'adaptateur qui implémente le
 * contrat correspondant — `VerifieParRedirection` (SSO, OpenID Connect),
 * `VerifieParCode` (registre institutionnel signé) ou `VerifieParIdentifiants`
 * (service de vérification approuvé) — et le lier dans AppServiceProvider.
 * Rien d'autre ne change : ni l'interface, ni la base, ni les workflows.
 * Voir docs/decisions/0003-integration-myway.md et 0007-premiere-connexion.md.
 */
class FournisseurApprouve implements FournisseurIdentiteStagiaire
{
    public function nom(): string
    {
        return 'approuve';
    }

    public function estSimule(): bool
    {
        return false;
    }

    public function methode(): MethodeVerification
    {
        return MethodeVerification::Indisponible;
    }
}
