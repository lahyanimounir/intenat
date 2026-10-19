<?php

use App\Http\Controllers\Api\Administration\CompteStagiaireController;
use App\Http\Controllers\Api\Administration\OrganisationController;
use App\Http\Controllers\Api\Administration\ParametreController;
use App\Http\Controllers\Api\Administration\UtilisateurController;
use App\Http\Controllers\Api\AdmissionController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CampagneController;
use App\Http\Controllers\Api\DossierController;
use App\Http\Controllers\Api\HebergementController;
use App\Http\Controllers\Api\PaiementController;
use App\Http\Controllers\Api\PilotageController;
use App\Http\Controllers\Api\ReferentielController;
use App\Http\Controllers\Api\RestaurationController;
use App\Http\Controllers\Api\Stagiaire\DossierController as DossierStagiaireController;
use App\Http\Controllers\Api\Stagiaire\VieController;
use App\Http\Controllers\Api\VieInternatController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API INTERNAT
|--------------------------------------------------------------------------
|
| Trois niveaux de contrôle s'empilent sur chaque route protégée :
|   1. `auth:sanctum` ............. session valide (personnel ou stagiaire)
|   2. `personnel` / `stagiaire` .. population autorisée
|   3. `permission:…` ............. action ouverte au rôle
| Le périmètre (internat, établissement, région) de chaque ligne est ensuite
| vérifié dans le contrôleur. Masquer un menu ne remplace jamais ces contrôles.
|
*/

// ------------------------------------------------------------------ Public
Route::get('contexte', [AuthController::class, 'contexte']);

Route::middleware('throttle:connexion')->prefix('auth')->group(function () {
    Route::post('personnel/connexion', [AuthController::class, 'connexionPersonnel']);

    // Stagiaire — première connexion : vérification d'identité (MyWay), puis création du compte INTERNAT.
    Route::post('stagiaire/verification', [AuthController::class, 'verificationStagiaire']);
    Route::post('stagiaire/verification/retour', [AuthController::class, 'retourVerificationStagiaire']);
    Route::post('stagiaire/activation', [AuthController::class, 'activationStagiaire']);
    // Stagiaire — connexions suivantes : compte INTERNAT uniquement.
    Route::post('stagiaire/connexion', [AuthController::class, 'connexionStagiaire']);

    Route::post('mot-de-passe/oubli', [AuthController::class, 'motDePasseOublie']);
    Route::post('mot-de-passe/reinitialisation', [AuthController::class, 'reinitialiserMotDePasse']);
});

Route::middleware('auth:sanctum')->group(function () {
    // --------------------------------------------------- Commun aux deux populations
    Route::get('auth/moi', [AuthController::class, 'moi']);
    Route::post('auth/deconnexion', [AuthController::class, 'deconnexion']);
    Route::get('notifications', [PilotageController::class, 'notifications']);
    Route::post('notifications/lues', [PilotageController::class, 'lireNotification']);
    Route::post('notifications/{notification}/lue', [PilotageController::class, 'lireNotification']);

    // ================================================================ STAGIAIRE
    Route::middleware('stagiaire')->prefix('stagiaire')->group(function () {
        Route::get('tableau-de-bord', [DossierStagiaireController::class, 'tableauDeBord']);
        Route::get('profil', [DossierStagiaireController::class, 'profil']);
        Route::put('profil', [DossierStagiaireController::class, 'majProfil']);

        Route::get('dossier', [DossierStagiaireController::class, 'show']);
        Route::post('dossier', [DossierStagiaireController::class, 'ouvrir']);
        Route::put('dossier', [DossierStagiaireController::class, 'enregistrer']);
        Route::post('dossier/soumettre', [DossierStagiaireController::class, 'soumettre']);
        Route::get('dossier/accuse', [DossierStagiaireController::class, 'accuse']);
        // La confirmation de la place est prononcée par le gestionnaire, plus par le stagiaire.
        Route::post('dossier/desister', [DossierStagiaireController::class, 'desister']);
        Route::post('dossier/pieces/{typePiece}', [DossierStagiaireController::class, 'deposerPiece'])->middleware('throttle:televersement');
        Route::get('dossier/pieces/{piece}/fichier', [DossierStagiaireController::class, 'fichierPiece']);
        Route::delete('dossier/pieces/{piece}', [DossierStagiaireController::class, 'retirerPiece']);

        Route::get('paiements', [VieController::class, 'paiements']);
        Route::post('paiements/{paiement}/recu', [VieController::class, 'deposerRecu'])->middleware('throttle:televersement');
        Route::get('paiements/{paiement}/recu', [VieController::class, 'recu']);
        Route::get('paiements/{paiement}/autorisation', [VieController::class, 'autorisation']);

        Route::get('hebergement', [VieController::class, 'hebergement']);
        Route::get('hebergement/attestation', [VieController::class, 'attestation']);
        Route::get('sorties', [VieController::class, 'sorties']);
        Route::post('sorties', [VieController::class, 'deposerSortie']);
        Route::get('reclamations', [VieController::class, 'reclamations']);
        Route::post('reclamations', [VieController::class, 'deposerReclamation']);

        Route::get('menus', [VieController::class, 'menus']);
        Route::post('menus/{menu}/reservation', [VieController::class, 'reserver']);
        Route::delete('reservations/{reservation}', [VieController::class, 'annulerReservation']);
        Route::get('regimes', [VieController::class, 'regimes']);
        Route::post('regimes', [VieController::class, 'declarerRegime'])->middleware('throttle:televersement');
        Route::delete('regimes/{regime}', [VieController::class, 'retirerRegime']);

        Route::get('documents', [VieController::class, 'documents']);
        Route::get('documents/{document}/fichier', [VieController::class, 'document']);
    });

    // ================================================================ PERSONNEL
    Route::middleware('personnel')->group(function () {
        Route::get('tableau-de-bord', [PilotageController::class, 'tableauDeBord']);
        Route::get('organisation', [OrganisationController::class, 'arbre']);

        // ------------------------------------------------------- Administration
        Route::middleware('permission:utilisateurs.gerer')->group(function () {
            Route::get('utilisateurs/matrice', [UtilisateurController::class, 'matrice']);
            Route::apiResource('utilisateurs', UtilisateurController::class)->except('destroy');
            Route::post('utilisateurs/{utilisateur}/basculer', [UtilisateurController::class, 'basculer']);
        });

        Route::middleware('permission:stagiaires.gerer')->group(function () {
            Route::get('comptes-stagiaires', [CompteStagiaireController::class, 'index']);
            Route::post('comptes-stagiaires/{stagiaire}/suspension', [CompteStagiaireController::class, 'suspendre']);
            Route::post('comptes-stagiaires/{stagiaire}/retablissement', [CompteStagiaireController::class, 'retablir']);
            Route::post('comptes-stagiaires/{stagiaire}/reinitialisation', [CompteStagiaireController::class, 'envoyerReinitialisation']);
            Route::get('verifications-identite', [CompteStagiaireController::class, 'verifications']);
            Route::post('verifications-identite/{verification}/examen', [CompteStagiaireController::class, 'examiner']);
        });

        Route::middleware('permission:organisation.gerer')->prefix('organisation')->group(function () {
            Route::post('regions', [OrganisationController::class, 'enregistrerRegion']);
            Route::put('regions/{region}', [OrganisationController::class, 'enregistrerRegion']);
            Route::post('provinces', [OrganisationController::class, 'enregistrerProvince']);
            Route::put('provinces/{province}', [OrganisationController::class, 'enregistrerProvince']);
            Route::post('complexes', [OrganisationController::class, 'enregistrerComplexe']);
            Route::put('complexes/{complexe}', [OrganisationController::class, 'enregistrerComplexe']);
            Route::post('etablissements', [OrganisationController::class, 'enregistrerEtablissement']);
            Route::put('etablissements/{etablissement}', [OrganisationController::class, 'enregistrerEtablissement']);
            Route::post('internats', [OrganisationController::class, 'enregistrerInternat']);
            Route::put('internats/{internat}', [OrganisationController::class, 'enregistrerInternat']);
            Route::delete('{niveau}/{id}', [OrganisationController::class, 'supprimer'])
                ->whereIn('niveau', ['regions', 'provinces', 'complexes', 'etablissements', 'internats'])->whereNumber('id');
        });

        Route::middleware('permission:parametres.gerer')->group(function () {
            Route::get('parametres', [ParametreController::class, 'index']);
            Route::put('parametres/{cle}', [ParametreController::class, 'update'])->where('cle', '[a-z_.]+');
        });

        Route::middleware('permission:audit.consulter')->group(function () {
            Route::get('journal-audit', [ParametreController::class, 'audit']);
            Route::get('journal-audit/filtres', [ParametreController::class, 'actionsAudit']);
        });

        // ------------------------------------------------------------ Campagnes
        Route::middleware('permission:campagnes.consulter')->group(function () {
            Route::get('campagnes', [CampagneController::class, 'index']);
            Route::get('campagnes/{campagne}', [CampagneController::class, 'show']);
            Route::get('grille-reference', [CampagneController::class, 'grilleReference']);
        });

        Route::middleware('permission:campagnes.gerer')->group(function () {
            Route::post('campagnes', [CampagneController::class, 'store']);
            Route::put('campagnes/{campagne}', [CampagneController::class, 'update']);
            Route::delete('campagnes/{campagne}', [CampagneController::class, 'destroy']);
            Route::post('campagnes/{campagne}/{action}', [CampagneController::class, 'transition'])
                ->whereIn('action', ['ouvrir', 'cloturer', 'archiver']);
            Route::post('campagnes/{campagne}/types-pieces', [CampagneController::class, 'enregistrerTypePiece']);
            Route::put('campagnes/{campagne}/types-pieces/{typePiece}', [CampagneController::class, 'enregistrerTypePiece']);
            Route::delete('campagnes/{campagne}/types-pieces/{typePiece}', [CampagneController::class, 'supprimerTypePiece']);
        });

        Route::middleware('permission:criteres.gerer')->group(function () {
            Route::post('campagnes/{campagne}/criteres', [CampagneController::class, 'enregistrerCritere']);
            Route::put('campagnes/{campagne}/criteres/{critere}', [CampagneController::class, 'enregistrerCritere']);
            Route::delete('campagnes/{campagne}/criteres/{critere}', [CampagneController::class, 'supprimerCritere']);
            Route::post('campagnes/{campagne}/grille-reference', [CampagneController::class, 'appliquerReference']);
        });

        Route::middleware('permission:grille_reference.gerer')->group(function () {
            Route::post('grille-reference', [CampagneController::class, 'enregistrerCritereReference']);
            Route::put('grille-reference/{critereReference}', [CampagneController::class, 'enregistrerCritereReference']);
            Route::delete('grille-reference/{critereReference}', [CampagneController::class, 'supprimerCritereReference']);
        });

        // -------------------------------------------------- Dossiers, instruction
        Route::middleware('permission:demandes.consulter')->group(function () {
            Route::get('dossiers', [DossierController::class, 'index']);
            Route::get('dossiers/{demande}', [DossierController::class, 'show']);
            Route::get('dossiers/{demande}/accuse', [DossierController::class, 'accuse']);
            Route::get('pieces/{piece}/fichier', [DossierController::class, 'fichierPiece']);
        });

        Route::middleware('permission:demandes.instruire')->group(function () {
            Route::post('pieces/{piece}/controle', [DossierController::class, 'controlerPiece']);
            Route::post('dossiers/{demande}/decision/{decision}', [DossierController::class, 'decider'])
                ->whereIn('decision', ['complet', 'rectification', 'rejet']);
        });

        // ------------------------------------------------ Admissions (chef de pôle)
        Route::middleware('permission:admissions.consulter')->group(function () {
            Route::get('admissions/campagnes/{campagne}/classement', [AdmissionController::class, 'classement']);
            Route::get('admissions/dossiers/{demande}/score', [AdmissionController::class, 'score']);
            Route::get('admissions/listes-attente', [AdmissionController::class, 'listesAttente']);
        });

        Route::middleware('permission:admissions.deliberer')->group(function () {
            Route::post('admissions/campagnes/{campagne}/classement', [AdmissionController::class, 'recalculer']);
            Route::post('admissions/campagnes/{campagne}/deliberation', [AdmissionController::class, 'deliberer']);
        });

        Route::post('admissions/dossiers/{demande}/promotion', [AdmissionController::class, 'promouvoir'])
            ->middleware('permission:admissions.promouvoir');

        // ------------------------------------------------------------ Paiements
        Route::middleware('permission:paiements.consulter')->group(function () {
            Route::get('paiements', [PaiementController::class, 'index']);
            Route::get('paiements/{paiement}/recu', [PaiementController::class, 'recu']);
            Route::get('paiements/{paiement}/autorisation', [PaiementController::class, 'autorisation']);
        });

        Route::post('paiements/{paiement}/controle', [PaiementController::class, 'controler'])
            ->middleware('permission:paiements.controler');

        // ----------------------------------------------------------- Référentiel
        Route::middleware('permission:referentiel.consulter')->prefix('referentiel')->group(function () {
            Route::get('plan', [ReferentielController::class, 'plan']);
            Route::get('batiments', [ReferentielController::class, 'batiments']);
            Route::get('lits', [ReferentielController::class, 'lits']);
            Route::get('lits/{lit}/historique', [ReferentielController::class, 'historiqueLit']);
        });

        Route::middleware('permission:referentiel.gerer')->prefix('referentiel')->group(function () {
            Route::post('batiments', [ReferentielController::class, 'enregistrerBatiment']);
            Route::put('batiments/{batiment}', [ReferentielController::class, 'enregistrerBatiment']);
            Route::post('chambres', [ReferentielController::class, 'enregistrerChambre']);
            Route::put('chambres/{chambre}', [ReferentielController::class, 'enregistrerChambre']);
            Route::post('chambres/{chambre}/lits', [ReferentielController::class, 'ajouterLit']);
            Route::post('lits/{lit}/statut', [ReferentielController::class, 'statutLit']);

            Route::get('imports', [ReferentielController::class, 'imports']);
            Route::get('imports/modele', [ReferentielController::class, 'modeleImport']);
            Route::post('imports', [ReferentielController::class, 'analyserImport'])->middleware('throttle:televersement');
            Route::post('imports/{import}/confirmation', [ReferentielController::class, 'confirmerImport']);
            Route::post('imports/{import}/annulation', [ReferentielController::class, 'annulerImport']);
            Route::get('imports/{import}/rapport', [ReferentielController::class, 'rapportImport']);
        });

        // ----------------------------------------------------------- Hébergement
        Route::middleware('permission:hebergement.consulter')->prefix('hebergement')->group(function () {
            Route::get('candidats', [HebergementController::class, 'candidats']);
            Route::get('residents', [HebergementController::class, 'residents']);
            Route::get('residents/{affectation}', [HebergementController::class, 'show']);
            Route::get('residents/{affectation}/attestation', [HebergementController::class, 'attestation']);
        });

        Route::middleware('permission:hebergement.gerer')->group(function () {
            Route::post('dossiers/{demande}/confirmation', [DossierController::class, 'confirmer']);
            Route::post('dossiers/{demande}/occupation', [DossierController::class, 'autoriserOccupation']);
            Route::post('hebergement/affectations', [HebergementController::class, 'affecter']);
            Route::post('hebergement/residents/{affectation}/changement-lit', [HebergementController::class, 'changerDeLit']);
            Route::post('hebergement/residents/{affectation}/arrivee', [HebergementController::class, 'arrivee']);
            Route::post('hebergement/residents/{affectation}/fin', [HebergementController::class, 'cloturer']);
        });

        // --------------------------------------------------- Vie à l'internat
        Route::middleware('permission:vie_internat.gerer')->prefix('vie-internat')->group(function () {
            Route::get('sorties', [VieInternatController::class, 'sorties']);
            Route::post('sorties/{sortie}/decision', [VieInternatController::class, 'traiterSortie']);
            Route::get('absences/feuille', [VieInternatController::class, 'feuilleAbsences']);
            Route::get('absences', [VieInternatController::class, 'absences']);
            Route::post('absences', [VieInternatController::class, 'saisirAbsences']);
            Route::delete('absences/{absence}', [VieInternatController::class, 'supprimerAbsence']);
            Route::get('incidents', [VieInternatController::class, 'incidents']);
            Route::post('incidents', [VieInternatController::class, 'enregistrerIncident']);
            Route::get('incidents/{incident}/rapport', [VieInternatController::class, 'rapportIncident']);
            Route::get('maintenances', [VieInternatController::class, 'maintenances']);
            Route::post('maintenances', [VieInternatController::class, 'signalerMaintenance']);
            Route::post('maintenances/{maintenance}/suivi', [VieInternatController::class, 'avancerMaintenance']);
        });

        Route::middleware('permission:reclamations.traiter')->group(function () {
            Route::get('reclamations', [VieInternatController::class, 'reclamations']);
            Route::post('reclamations/{reclamation}/traitement', [VieInternatController::class, 'traiterReclamation']);
        });

        Route::middleware('permission:documents.gerer')->group(function () {
            Route::get('documents', [VieInternatController::class, 'documents']);
            Route::post('documents', [VieInternatController::class, 'publierDocument'])->middleware('throttle:televersement');
            Route::get('documents/{document}/fichier', [VieInternatController::class, 'fichierDocument']);
            Route::delete('documents/{document}', [VieInternatController::class, 'supprimerDocument']);
        });

        // ---------------------------------------------------------- Restauration
        Route::middleware('permission:restauration.consulter')->prefix('restauration')->group(function () {
            Route::get('menus', [RestaurationController::class, 'menus']);
            Route::get('menus/{menu}/reservations', [RestaurationController::class, 'reservations']);
            Route::get('statistiques', [RestaurationController::class, 'statistiques']);
        });

        Route::middleware('permission:restauration.valider_menus')->prefix('restauration')->group(function () {
            Route::post('menus/{menu}/validation', [RestaurationController::class, 'validerMenu']);
        });

        Route::middleware('permission:restauration.gerer')->prefix('restauration')->group(function () {
            Route::post('menus', [RestaurationController::class, 'creerMenu']);
            Route::put('menus/{menu}', [RestaurationController::class, 'modifierMenu']);
            Route::delete('menus/{menu}', [RestaurationController::class, 'supprimerMenu']);
            Route::post('menus/{menu}/effectif', [RestaurationController::class, 'confirmerEffectif']);
            Route::post('menus/{menu}/service', [RestaurationController::class, 'cloturerService']);
            Route::get('regimes', [RestaurationController::class, 'regimes']);
            Route::get('regimes/{regime}/justificatif', [RestaurationController::class, 'justificatifRegime']);
        });

        // ---------------------------------------------------------------- Exports
        Route::middleware('permission:exports.creer')->group(function () {
            Route::get('exports', [PilotageController::class, 'exports']);
            Route::post('exports', [PilotageController::class, 'demanderExport']);
            Route::get('exports/{export}/fichier', [PilotageController::class, 'telechargerExport']);
        });
    });
});
