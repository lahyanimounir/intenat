<?php

namespace App\Enums;

use App\Enums\Concerns\AvecLibelle;

/**
 * Rôles du personnel (dictionnaire : role.code). Le stagiaire est un acteur distinct.
 */
enum RoleCode: string
{
    use AvecLibelle;

    case Admin = 'ADMIN';
    case Gestionnaire = 'GESTIONNAIRE';
    case Restauration = 'RESTAURATION';
    case ChefPole = 'CHEF_POLE';
    case Directeur = 'DIRECTEUR';
    case DirRegionale = 'DIR_REGIONALE';
    case DirCentrale = 'DIR_CENTRALE';

    public function libelle(): string
    {
        return match ($this) {
            self::Admin => 'Administrateur',
            self::Gestionnaire => 'Gestionnaire d\'internat',
            self::Restauration => 'Responsable restauration',
            self::ChefPole => 'Chef de pôle scolarité',
            self::Directeur => 'Directeur de complexe / CMC',
            self::DirRegionale => 'Direction régionale',
            self::DirCentrale => 'Direction centrale',
        };
    }
}
