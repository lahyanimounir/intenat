<?php

namespace App\Domain\Identity;

use App\Enums\RoleCode;

/**
 * Matrice centralisée des permissions. Source unique : le seeder, l'écran
 * d'administration et la documentation en sont tous dérivés.
 *
 * Principe du moindre privilège : un rôle ne reçoit que ce que le workflow
 * documenté lui attribue. Une permission ouvre une action ; elle ne dispense
 * jamais du contrôle de périmètre (voir Perimetre).
 */
final class Permissions
{
    /** @return array<string, string> code => libellé */
    public static function catalogue(): array
    {
        return [
            'utilisateurs.gerer' => 'Créer et gérer les comptes du personnel et leurs rôles',
            'stagiaires.gerer' => 'Consulter les comptes stagiaires, les suspendre, les rétablir et leur envoyer un lien de réinitialisation',
            'inscriptions.verifier' => 'Vérifier l\'inscription en formation déclarée par les stagiaires de son périmètre',
            'organisation.gerer' => 'Gérer régions, complexes, établissements (codes EFP) et internats',
            'parametres.gerer' => 'Modifier les règles de gestion paramétrables',
            'audit.consulter' => 'Consulter le journal d\'audit',
            'grille_reference.gerer' => 'Gérer la grille nationale de critères de référence',
            'campagnes.consulter' => 'Consulter les campagnes',
            'campagnes.gerer' => 'Créer, ouvrir, clôturer et archiver les campagnes ; définir les pièces exigées',
            'criteres.gerer' => 'Saisir la grille de critères d\'une campagne en préparation',
            'demandes.consulter' => 'Consulter les dossiers de préinscription',
            'demandes.instruire' => 'Instruire les dossiers : pièces, complet, rectification, rejet',
            'admissions.consulter' => 'Voir les scores, le classement, la liste principale et la liste d\'attente',
            'admissions.deliberer' => 'Délibérer et valider la liste des admis',
            'admissions.promouvoir' => 'Appeler un candidat de la liste d\'attente',
            'paiements.consulter' => 'Consulter le suivi des paiements',
            'paiements.autoriser' => 'Délivrer les autorisations de paiement',
            'paiements.controler' => 'Valider ou refuser les reçus de paiement',
            'referentiel.consulter' => 'Consulter bâtiments, chambres et lits',
            'referentiel.gerer' => 'Importer et mettre à jour le référentiel de son internat',
            'hebergement.consulter' => 'Consulter les résidents et l\'occupation',
            'hebergement.gerer' => 'Autoriser l\'occupation, affecter les lits, saisir arrivées et départs',
            'vie_internat.gerer' => 'Gérer sorties, absences, incidents et maintenance',
            'documents.gerer' => 'Publier les documents utiles aux stagiaires',
            'reclamations.traiter' => 'Traiter les réclamations de son domaine',
            'restauration.consulter' => 'Consulter menus, effectifs et statistiques de restauration',
            'restauration.gerer' => 'Gérer les menus, confirmer les effectifs et suivre le service',
            'pilotage.consulter' => 'Consulter les indicateurs consolidés et les rapports',
            'exports.creer' => 'Produire des exports dans son périmètre',
        ];
    }

    /** @return array<string, list<string>> code de rôle => permissions */
    public static function matrice(): array
    {
        return [
            RoleCode::Admin->value => [
                'utilisateurs.gerer', 'stagiaires.gerer', 'organisation.gerer', 'parametres.gerer', 'audit.consulter',
                'campagnes.consulter', 'criteres.gerer', 'referentiel.consulter',
            ],
            RoleCode::Gestionnaire->value => [
                'campagnes.consulter', 'inscriptions.verifier', 'demandes.consulter', 'demandes.instruire',
                'paiements.consulter', 'paiements.controler',
                'referentiel.consulter', 'referentiel.gerer', 'hebergement.consulter', 'hebergement.gerer',
                'vie_internat.gerer', 'documents.gerer', 'reclamations.traiter', 'exports.creer',
            ],
            RoleCode::ChefPole->value => [
                'campagnes.consulter', 'campagnes.gerer',
                'inscriptions.verifier', 'demandes.consulter', 'admissions.consulter', 'admissions.deliberer', 'admissions.promouvoir',
                'paiements.consulter', 'paiements.autoriser',
                'referentiel.consulter', 'hebergement.consulter', 'restauration.consulter',
                'documents.gerer', 'reclamations.traiter', 'pilotage.consulter', 'exports.creer',
            ],
            RoleCode::Restauration->value => [
                'restauration.consulter', 'restauration.gerer', 'reclamations.traiter', 'exports.creer',
            ],
            RoleCode::Directeur->value => [
                'campagnes.consulter', 'referentiel.consulter',
                'pilotage.consulter', 'exports.creer',
            ],
            RoleCode::DirRegionale->value => [
                'campagnes.consulter', 'pilotage.consulter', 'exports.creer',
            ],
            RoleCode::DirCentrale->value => [
                'campagnes.consulter', 'grille_reference.gerer', 'pilotage.consulter', 'exports.creer',
            ],
        ];
    }

    /**
     * Niveau de périmètre exigé par chaque rôle : colonne à renseigner sur le compte.
     *
     * @return array<string, string|null>
     */
    public static function niveauxDePerimetre(): array
    {
        return [
            RoleCode::Admin->value => null,
            RoleCode::Gestionnaire->value => 'internat_id',
            RoleCode::ChefPole->value => 'etablissement_id',
            RoleCode::Restauration->value => 'etablissement_id',
            RoleCode::Directeur->value => 'complexe_id',
            RoleCode::DirRegionale->value => 'region_id',
            RoleCode::DirCentrale->value => null,
        ];
    }
}
