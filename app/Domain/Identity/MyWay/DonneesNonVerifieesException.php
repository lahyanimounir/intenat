<?php

namespace App\Domain\Identity\MyWay;

use App\Domain\Shared\RegleMetierException;

/**
 * La session MyWay a abouti, mais une information exigée (CEF, identité,
 * établissement, filière, statut de candidature) n'a pas pu y être lue.
 * Rien n'est déduit ni complété : aucun compte n'est créé.
 */
class DonneesNonVerifieesException extends RegleMetierException
{
    /**
     * @param  list<string>  $champs  Codes des champs manquants.
     * @param  list<string>  $libelles  Leur nom pour l'utilisateur.
     */
    public function __construct(array $champs, array $libelles)
    {
        parent::__construct(
            'Ces informations n\'ont pas pu être vérifiées auprès de MyWay : '.implode(', ', $libelles).'. Aucun compte n\'a été créé.',
            'donnees_non_verifiees',
            ['champs_manquants' => $champs],
            502,
        );
    }
}
