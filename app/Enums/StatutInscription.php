<?php

namespace App\Enums;

use App\Enums\Concerns\AvecLibelle;

/**
 * Où en est la vérification de l'inscription en formation d'un stagiaire.
 *
 * Depuis l'abandon de MyWay, le stagiaire déclare lui-même son inscription ;
 * une déclaration, même accompagnée d'un justificatif, ne vaut rien tant qu'un
 * agent habilité ne l'a pas vérifiée. Seul `verifiee` ouvre la sélection.
 *
 * Cette vérification porte sur l'inscription en formation (frais de scolarité).
 * Elle n'a aucun rapport avec le paiement de l'hébergement, suivi après l'admission.
 */
enum StatutInscription: string
{
    use AvecLibelle;

    /** Le stagiaire n'a pas encore renseigné son identité et sa formation. */
    case NonDeclaree = 'non_declaree';
    /** Identité et formation déclarées, rien n'a été soumis à la vérification. */
    case Declaree = 'declaree';
    /** Déclaration soumise avec son justificatif : en attente d'un agent. */
    case Soumise = 'soumise';
    case Verifiee = 'verifiee';
    /** L'agent demande une correction : le stagiaire rectifie puis soumet de nouveau. */
    case ACorriger = 'a_corriger';
    case Rejetee = 'rejetee';

    public function libelle(): string
    {
        return match ($this) {
            self::NonDeclaree => 'Non déclarée',
            self::Declaree => 'Déclarée, non soumise',
            self::Soumise => 'En attente de vérification',
            self::Verifiee => 'Vérifiée',
            self::ACorriger => 'Correction demandée',
            self::Rejetee => 'Rejetée',
        };
    }

    /** Le stagiaire peut encore modifier ce qu'il a déclaré. */
    public function modifiable(): bool
    {
        return in_array($this, [self::NonDeclaree, self::Declaree, self::ACorriger, self::Rejetee], true);
    }

    /** Une déclaration peut être soumise (ou soumise de nouveau) à la vérification. */
    public function soumettable(): bool
    {
        return in_array($this, [self::Declaree, self::ACorriger, self::Rejetee], true);
    }
}
