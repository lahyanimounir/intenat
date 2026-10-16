<?php

namespace Tests\Support;

use App\Domain\Identity\MyWay\IdentifiantsInvalidesException;
use App\Domain\Identity\MyWay\ProfilMyWay;
use App\Domain\Identity\MyWay\VerifieParRedirection;
use App\Enums\MethodeVerification;

/**
 * Fournisseur de test pour la méthode « redirection » (SSO, OpenID Connect).
 * Il ne parle à aucun service : il prouve que le parcours d'INTERNAT sait
 * accueillir un fournisseur qui authentifie le stagiaire chez lui.
 */
class FournisseurParRedirection implements VerifieParRedirection
{
    public const PREUVE_VALIDE = 'preuve-valide';

    public function __construct(private readonly ProfilMyWay $profil) {}

    public function urlAutorisation(string $etat, string $urlRetour): string
    {
        return 'https://idp.exemple.test/autoriser?'.http_build_query(['state' => $etat, 'redirect_uri' => $urlRetour]);
    }

    public function terminer(array $parametres, string $urlRetour): ProfilMyWay
    {
        if (($parametres['code'] ?? null) !== self::PREUVE_VALIDE) {
            throw new IdentifiantsInvalidesException('Preuve refusée par le fournisseur de test.');
        }

        return $this->profil;
    }

    public function nom(): string
    {
        return 'test-sso';
    }

    public function estSimule(): bool
    {
        return true;
    }

    public function methode(): MethodeVerification
    {
        return MethodeVerification::Redirection;
    }
}
