<?php

namespace App\Domain\Identity\MyWay;

use App\Domain\Shared\RegleMetierException;

/**
 * MyWay a demandé une vérification que seule une personne peut accomplir
 * (CAPTCHA, code à usage unique). INTERNAT ne la contourne pas : la tentative
 * s'arrête et rien n'est enregistré.
 */
class VerificationRequiseException extends RegleMetierException
{
    public function __construct(string $obstacle)
    {
        parent::__construct(
            'MyWay demande une vérification supplémentaire (CAPTCHA ou code de sécurité). INTERNAT ne la contourne pas : '
                .'connectez-vous d\'abord sur MyWay, puis réessayez plus tard.',
            'verification_requise',
            ['obstacle' => $obstacle],
            409,
        );
    }
}
