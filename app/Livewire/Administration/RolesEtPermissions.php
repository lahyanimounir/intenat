<?php

namespace App\Livewire\Administration;

use App\Domain\Identity\Permissions;
use App\Enums\RoleCode;
use App\Livewire\PagePersonnel;
use Livewire\Attributes\Title;

/**
 * Matrice des rôles et des permissions, en lecture seule : elle est définie
 * dans le code (Permissions::matrice) et synchronisée à chaque déploiement.
 */
#[Title('Rôles et permissions')]
class RolesEtPermissions extends PagePersonnel
{
    private const PERMISSION = 'utilisateurs.gerer';

    /** Famille de permissions (préfixe du code) => section de la matrice. */
    private const SECTIONS = [
        'utilisateurs' => 'Administration', 'stagiaires' => 'Administration', 'organisation' => 'Administration',
        'parametres' => 'Administration', 'audit' => 'Administration',
        'grille_reference' => 'Campagnes et critères', 'campagnes' => 'Campagnes et critères', 'criteres' => 'Campagnes et critères',
        'inscriptions' => 'Dossiers et admissions', 'demandes' => 'Dossiers et admissions', 'admissions' => 'Dossiers et admissions',
        'paiements' => 'Paiements',
        'referentiel' => 'Hébergement', 'hebergement' => 'Hébergement',
        'vie_internat' => 'Vie à l’internat', 'documents' => 'Vie à l’internat', 'reclamations' => 'Vie à l’internat',
        'restauration' => 'Restauration',
        'pilotage' => 'Pilotage', 'exports' => 'Pilotage',
    ];

    private const NIVEAUX = [
        'internat_id' => 'Son internat', 'etablissement_id' => 'Son établissement', 'complexe_id' => 'Son complexe', 'region_id' => 'Sa région',
    ];

    public function mount(): void
    {
        $this->exiger(self::PERMISSION);
    }

    public function render()
    {
        $this->exiger(self::PERMISSION);
        $matrice = Permissions::matrice();
        $niveaux = Permissions::niveauxDePerimetre();

        $sections = collect(Permissions::catalogue())
            ->map(fn (string $libelle, string $code) => ['code' => $code, 'libelle' => $libelle])
            ->groupBy(fn (array $permission) => self::SECTIONS[explode('.', $permission['code'])[0]] ?? 'Autres')
            // Les sections gardent l'ordre de lecture de la matrice, « Autres » en dernier.
            ->sortBy(fn ($permissions, string $section) => ($rang = array_search($section, array_values(array_unique(self::SECTIONS)), true)) === false ? PHP_INT_MAX : $rang);

        return view('livewire.administration.roles-et-permissions', [
            'sections' => $sections,
            'roles' => collect(RoleCode::cases())->map(fn (RoleCode $role) => [
                'code' => $role->value,
                'libelle' => $role->libelle(),
                'perimetre' => self::NIVEAUX[$niveaux[$role->value] ?? ''] ?? 'National',
                'permissions' => $matrice[$role->value] ?? [],
            ]),
        ]);
    }
}
