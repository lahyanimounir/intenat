<?php

namespace App\Domain\Identity\MyWay;

use SensitiveParameter;

/**
 * Vérification à partir d'enregistrements institutionnels authentifiés (export
 * signé, registre tenu par l'établissement) : le stagiaire se désigne, puis
 * prouve qu'il est bien cette personne par un code à usage unique envoyé sur un
 * canal figurant dans l'enregistrement — jamais sur une adresse qu'il saisit.
 *
 * Connaître un CEF, une adresse ou un numéro de CNI ne prouve rien : seule la
 * maîtrise du canal enregistré vaut preuve.
 *
 * Aucun adaptateur de production n'implémente encore ce contrat : il suppose un
 * export que l'OFPPT doit autoriser et dont le format reste à convenir.
 */
interface VerifieParCode extends FournisseurIdentiteStagiaire
{
    /**
     * Envoie un code sur le canal enregistré. La réponse est la même que
     * l'enregistrement existe ou non : aucune énumération possible.
     *
     * @return array{reference: string, canal: string, expire_dans_minutes: int}
     *
     * @throws FournisseurIndisponibleException
     */
    public function demanderCode(string $cef, string $email): array;

    /**
     * @throws IdentifiantsInvalidesException Code erroné, périmé ou déjà utilisé.
     * @throws FournisseurIndisponibleException
     */
    public function confirmerCode(string $reference, #[SensitiveParameter] string $code): ProfilMyWay;
}
