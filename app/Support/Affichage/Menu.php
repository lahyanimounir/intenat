<?php

namespace App\Support\Affichage;

use App\Models\Stagiaire;
use App\Models\Utilisateur;

/**
 * Menus de l'application.
 *
 * Le menu du personnel reflète les permissions du compte : aucune entrée n'est
 * montrée si l'action est interdite. Ce filtrage est un confort d'interface ;
 * chaque route et chaque action refusent de toute façon ce que le rôle
 * n'autorise pas.
 */
final class Menu
{
    /** @var list<array{titre?: string, entrees: list<array{0: string, 1: string, 2: string, 3?: list<string>}>}> libellé, chemin, icône, permissions */
    private const PERSONNEL = [
        ['entrees' => [['Tableau de bord', '/backend/tableau-de-bord', 'layout-dashboard']]],
        ['titre' => 'Admissions', 'entrees' => [
            ['Campagnes', '/campagnes', 'calendar-range', ['campagnes.consulter']],
            ['Dossiers', '/dossiers', 'folder-open', ['demandes.consulter']],
            ['Classement', '/admissions/classement', 'list-ordered', ['admissions.consulter', 'admissions.promouvoir']],
            ['Liste d’attente', '/admissions/liste-attente', 'clipboard-list', ['admissions.consulter', 'admissions.promouvoir']],
            ['Grille de référence', '/grille-reference', 'sliders-horizontal', ['grille_reference.gerer']],
        ]],
        ['titre' => 'Hébergement', 'entrees' => [
            ['Plan d’occupation', '/hebergement/plan', 'building-complex', ['referentiel.consulter']],
            ['Affectations', '/hebergement/affectations', 'bed-double', ['hebergement.consulter']],
            ['Résidents', '/hebergement/residents', 'users', ['hebergement.consulter']],
            ['Import du référentiel', '/hebergement/import', 'file-spreadsheet', ['referentiel.gerer']],
        ]],
        ['titre' => 'Vie à l’internat', 'entrees' => [
            ['Sorties et absences', '/vie-internat/presences', 'door-open', ['vie_internat.gerer']],
            ['Incidents et maintenance', '/vie-internat/incidents', 'wrench', ['vie_internat.gerer']],
            ['Réclamations', '/reclamations', 'message-square-warning', ['reclamations.traiter']],
            ['Documents utiles', '/documents', 'file-text', ['documents.gerer']],
        ]],
        ['titre' => 'Paiements', 'entrees' => [
            ['Suivi des paiements', '/paiements', 'credit-card', ['paiements.consulter']],
        ]],
        ['titre' => 'Restauration', 'entrees' => [
            ['Menus', '/restauration/menus', 'soup', ['restauration.consulter']],
            ['Service et effectifs', '/restauration/service', 'utensils-crossed', ['restauration.consulter']],
            ['Liste J+1', '/restauration/liste-j1', 'clipboard-list', ['restauration.consulter']],
            ['Statistiques', '/restauration/statistiques', 'gauge', ['restauration.consulter']],
        ]],
        ['titre' => 'Pilotage', 'entrees' => [
            ['Rapports et exports', '/rapports', 'file-chart-column-increasing', ['exports.creer']],
        ]],
        ['titre' => 'Administration', 'entrees' => [
            ['Utilisateurs', '/administration/utilisateurs', 'users', ['utilisateurs.gerer', 'mots_de_passe.personnel']],
            ['Comptes stagiaires', '/administration/comptes-stagiaires', 'user-check', ['stagiaires.gerer', 'mots_de_passe.stagiaires']],
            ['Rôles et permissions', '/administration/permissions', 'shield-check', ['utilisateurs.gerer']],
            ['Organisation', '/administration/organisation', 'network', ['organisation.gerer']],
            ['Paramètres', '/administration/parametres', 'settings-2', ['parametres.gerer']],
            ['Journal d’audit', '/administration/audit', 'rotate-ccw-clock', ['audit.consulter']],
        ]],
    ];

    private const STAGIAIRE = [
        ['entrees' => [
            ['Accueil', '/stagiaire', 'house'],
            ['Mon inscription', '/stagiaire/inscription', 'graduation-cap'],
            ['Mon dossier', '/stagiaire/dossier', 'clipboard-check'],
            ['Mon profil', '/stagiaire/profil', 'user-round'],
        ]],
        // « sejour » : entrée réservée au stagiaire à qui un lit a été affecté.
        ['titre' => 'Mon séjour', 'entrees' => [
            ['Hébergement', '/stagiaire/hebergement', 'bed-double', ['sejour']],
            ['Paiements', '/stagiaire/paiements', 'credit-card', ['sejour']],
            ['Repas', '/stagiaire/repas', 'utensils-crossed', ['sejour']],
            ['Sorties', '/stagiaire/sorties', 'door-open', ['sejour']],
        ]],
        ['titre' => 'Services', 'entrees' => [
            ['Réclamations', '/stagiaire/reclamations', 'message-square-warning', ['sejour']],
            ['Documents utiles', '/stagiaire/documents', 'file-text'],
        ]],
    ];

    /** Chemins dont l'entrée n'est active que sur l'adresse exacte. */
    private const EXACTS = ['/backend/tableau-de-bord', '/stagiaire'];

    /**
     * Sections dont au moins une entrée est accessible au compte.
     *
     * @return list<array{titre: string|null, entrees: list<array{libelle: string, chemin: string, icone: string, actif: bool}>}>
     */
    public static function personnel(Utilisateur $utilisateur): array
    {
        return self::construire(self::PERSONNEL, fn (array $permissions) => $permissions === [] || $utilisateur->canAny($permissions));
    }

    /**
     * Menu du candidat. Tant que son inscription en formation n'est pas vérifiée,
     * seul « Mon inscription » est proposé : les fonctionnalités du séjour
     * n'apparaissent qu'après la validation par l'agent habilité. Les rubriques du
     * séjour et les réclamations attendent en outre l'affectation d'un lit. Ce
     * filtrage double les verrous de route (`inscription.verifiee`, `lit.affecte`).
     *
     * @return list<array{titre: string|null, entrees: list<array{libelle: string, chemin: string, icone: string, actif: bool}>}>
     */
    public static function stagiaire(Stagiaire $stagiaire): array
    {
        $sections = $stagiaire->inscriptionVerifiee()
            ? self::STAGIAIRE
            : [['entrees' => [['Mon inscription', '/stagiaire/inscription', 'graduation-cap']]]];

        $heberge = $stagiaire->inscriptionVerifiee() && $stagiaire->aUnLitAffecte();

        return self::construire($sections, fn (array $conditions) => ! in_array('sejour', $conditions, true) || $heberge);
    }

    private static function construire(array $sections, callable $permis): array
    {
        $courant = '/'.trim(request()->path(), '/');

        return collect($sections)
            ->map(fn (array $section) => [
                'titre' => $section['titre'] ?? null,
                'entrees' => collect($section['entrees'])
                    ->filter(fn (array $entree) => $permis($entree[3] ?? []))
                    ->map(fn (array $entree) => [
                        'libelle' => $entree[0],
                        'chemin' => $entree[1],
                        'icone' => $entree[2],
                        'actif' => $courant === $entree[1]
                            || (! in_array($entree[1], self::EXACTS, true) && str_starts_with($courant, $entree[1].'/')),
                    ])->values()->all(),
            ])
            ->filter(fn (array $section) => $section['entrees'] !== [])
            ->values()->all();
    }
}
