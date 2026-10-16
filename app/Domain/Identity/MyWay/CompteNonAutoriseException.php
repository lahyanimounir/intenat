<?php

namespace App\Domain\Identity\MyWay;

use App\Domain\Shared\RegleMetierException;

/**
 * Le compte ne figure pas dans la liste des comptes dont le titulaire a
 * autorisé la vérification réelle. Aucun appel n'a été fait vers MyWay.
 */
class CompteNonAutoriseException extends RegleMetierException
{
    public function __construct()
    {
        parent::__construct(
            'La connexion réelle à MyWay est pour l\'instant réservée aux comptes de test autorisés.',
            'compte_non_autorise',
            statutHttp: 403,
        );
    }
}
