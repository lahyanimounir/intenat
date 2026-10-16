<?php

namespace App\Enums;

use App\Enums\Concerns\AvecLibelle;

/**
 * Issue d'une vérification d'identité conservée dans `verification_identite`.
 *
 * Seule `verifiee` ouvre une autorisation d'activation. Les autres issues sont
 * conservées pour être examinées par l'administration : aucune ne crée de compte.
 * Un refus pour inéligibilité (« aucune candidature Inscrit Payé ») ou pour
 * identifiants incorrects n'est pas conservé : rien n'est enregistré sur la personne.
 */
enum ResultatVerification: string
{
    use AvecLibelle;

    case Verifiee = 'verifiee';
    case InformationsIncompletes = 'informations_incompletes';
    case EfpInconnu = 'efp_inconnu';
    case CandidatureAmbigue = 'candidature_ambigue';
    case ConflitIdentite = 'conflit_identite';

    public function libelle(): string
    {
        return match ($this) {
            self::Verifiee => 'Vérifiée',
            self::InformationsIncompletes => 'Informations incomplètes',
            self::EfpInconnu => 'Établissement non référencé',
            self::CandidatureAmbigue => 'Plusieurs inscriptions éligibles',
            self::ConflitIdentite => 'Conflit d\'identité',
        };
    }

    /** Vrai si l'issue demande l'intervention de l'administration. */
    public function aExaminer(): bool
    {
        return $this !== self::Verifiee;
    }
}
