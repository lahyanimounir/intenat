<?php

namespace Tests\Support;

use App\Domain\Identity\MyWay\IdentifiantsInvalidesException;
use App\Domain\Identity\MyWay\ProfilMyWay;
use App\Domain\Identity\MyWay\VerifieParCode;
use App\Enums\MethodeVerification;

/**
 * Fournisseur de test pour la méthode « code » (registre institutionnel).
 * Le code n'est « envoyé » que si le CEF et l'adresse désignent l'enregistrement ;
 * la réponse à la demande est la même dans tous les cas.
 */
class FournisseurParCode implements VerifieParCode
{
    public const CODE = '482913';

    public const REFERENCE = 'reference-de-test';

    public bool $codeEnvoye = false;

    public function __construct(private readonly ProfilMyWay $profil) {}

    public function demanderCode(string $cef, string $email): array
    {
        $this->codeEnvoye = $cef === $this->profil->cef && $email === mb_strtolower($this->profil->email);

        return ['reference' => self::REFERENCE, 'canal' => 'adresse e-mail enregistrée', 'expire_dans_minutes' => 10];
    }

    public function confirmerCode(string $reference, string $code): ProfilMyWay
    {
        if (! $this->codeEnvoye || $reference !== self::REFERENCE || ! hash_equals(self::CODE, $code)) {
            throw new IdentifiantsInvalidesException('Code refusé par le fournisseur de test.');
        }

        $this->codeEnvoye = false;

        return $this->profil;
    }

    public function nom(): string
    {
        return 'test-registre';
    }

    public function estSimule(): bool
    {
        return true;
    }

    public function methode(): MethodeVerification
    {
        return MethodeVerification::Code;
    }
}
