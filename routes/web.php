<?php

use App\Http\Controllers\FichierController;
use App\Http\Controllers\ImpressionClassementController;
use App\Http\Controllers\SessionController;
use App\Livewire\Administration;
use App\Livewire\Auth;
use App\Livewire\Notifications;
use App\Livewire\Personnel;
use App\Livewire\Stagiaire;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Interface INTERNAT
|--------------------------------------------------------------------------
|
| Une seule application Laravel : chaque écran est un composant Livewire rendu
| par le serveur. Trois niveaux de contrôle s'empilent sur un écran protégé :
|   1. `auth:web` / `auth:stagiaire` .. session ouverte dans le bon espace
|      `auth.session` ................. session fermée si le mot de passe a changé depuis
|   2. `personnel` / `stagiaire` ...... compte actif
|   3. `permission:…` ................. écran ouvert au rôle
| Chaque action d'un écran revérifie ensuite sa permission et le périmètre
| (internat, établissement, région) de la ligne visée. Masquer une entrée de
| menu ne remplace jamais ces contrôles.
|
*/

// ------------------------------------------------------------------ Hors session
// Deux entrées distinctes, sans choix d'espace à faire : un compte déjà connecté est renvoyé à l'accueil du sien.
Route::middleware('guest:web,stagiaire')->group(function () {
    // Stagiaires et candidats (frontend) : connexion, création du compte, récupération.
    Route::get('/', Auth\Connexion::class)->name('connexion');
    Route::get('inscription', Auth\Inscription::class)->name('inscription');
    Route::get('mot-de-passe-oublie', Auth\MotDePasseOublie::class)->name('mot-de-passe.oubli');
    Route::get('reinitialisation', Auth\Reinitialisation::class)->name('mot-de-passe.reinitialisation');

    // Personnel (backend) : connexion et récupération, sans lien vers l'espace stagiaire.
    Route::prefix('backend')->name('backend.')->group(function () {
        Route::get('/', Auth\ConnexionPersonnel::class)->name('connexion');
        Route::get('mot-de-passe-oublie', Auth\MotDePasseOublie::class)->defaults('espace', 'personnel')->name('mot-de-passe.oubli');
        Route::get('reinitialisation', Auth\Reinitialisation::class)->defaults('espace', 'personnel')->name('mot-de-passe.reinitialisation');
    });
});

// Ancienne page commune : chacun est renvoyé vers sa propre entrée (le personnel par défaut, comme autrefois).
Route::get('connexion', fn (Request $requete) => redirect($requete->query('espace') === 'stagiaire' ? route('connexion') : route('backend.connexion')));

Route::post('deconnexion', [SessionController::class, 'deconnecter'])->name('deconnexion');

// Lien de confirmation de l'adresse, reçu par courriel : signé, limité dans le temps.
Route::get('adresse/confirmation/{stagiaire}/{empreinte}', [SessionController::class, 'confirmerAdresse'])
    ->middleware(['signed', 'throttle:12,1'])->name('stagiaire.adresse.confirmer');

// ====================================================================== STAGIAIRE
Route::middleware(['auth:stagiaire', 'auth.session', 'stagiaire'])->prefix('stagiaire')->name('stagiaire.')->group(function () {
    Route::get('adresse', Stagiaire\ConfirmationAdresse::class)->name('adresse');

    Route::middleware('adresse.verifiee')->group(function () {
        // Identité, formation, situation, pièces : toute la candidature se saisit ici,
        // accessible dès la création du compte (avant la validation par le gestionnaire).
        Route::get('inscription', Stagiaire\MonInscription::class)->name('inscription');
        Route::get('inscription/justificatifs/{verification}', [FichierController::class, 'justificatifDuStagiaire'])->name('inscription.justificatif');
        Route::get('inscription/pieces/{piece}', [FichierController::class, 'pieceDuStagiaire'])->name('inscription.piece');

        // Le séjour n'est accessible qu'une fois l'inscription en formation vérifiée.
        // Avant cela, tout ramène à « Mon inscription » (statut et corrections).
        Route::middleware(['profil.complet', 'inscription.verifiee'])->group(function () {
            Route::get('/', Stagiaire\Accueil::class)->name('accueil');
            Route::get('profil', Stagiaire\Profil::class)->name('profil');

            // Demande de place : brouillon, pièces, dépôt, suivi, confirmation, désistement.
            Route::get('dossier', Stagiaire\Dossier::class)->name('dossier');
            Route::get('dossier/accuse', [FichierController::class, 'accuseDuStagiaire'])->name('dossier.accuse');
            Route::get('dossier/pieces/{piece}', [FichierController::class, 'pieceDuStagiaire'])->name('dossier.piece');

            // Séjour et réclamations : seulement une fois un lit affecté. Valider le dossier,
            // admettre ou confirmer la place n'ouvre pas encore les services de l'internat.
            Route::middleware('lit.affecte')->group(function () {
                Route::get('hebergement', Stagiaire\Hebergement::class)->name('hebergement');
                Route::get('hebergement/attestation', [FichierController::class, 'attestationDuStagiaire'])->name('hebergement.attestation');
                Route::get('paiements', Stagiaire\Paiements::class)->name('paiements');
                Route::get('paiements/{paiement}/recu', [FichierController::class, 'recuDuStagiaire'])->name('paiements.recu');
                Route::get('paiements/{paiement}/autorisation', [FichierController::class, 'autorisationDuStagiaire'])->name('paiements.autorisation');
                Route::get('repas', Stagiaire\Repas::class)->name('repas');
                Route::get('sorties', Stagiaire\Sorties::class)->name('sorties');
                Route::get('reclamations', Stagiaire\Reclamations::class)->name('reclamations');
            });

            // Services.
            Route::get('documents', Stagiaire\DocumentsUtiles::class)->name('documents');
            Route::get('documents/{document}', [FichierController::class, 'documentDuStagiaire'])->name('documents.fichier');
        });
    });
});

// ============================================================ Les deux populations
Route::get('notifications', Notifications::class)->middleware(['auth:web,stagiaire', 'auth.session'])->name('notifications');

// ====================================================================== PERSONNEL
Route::middleware(['auth:web', 'auth.session', 'personnel'])->group(function () {
    Route::get('backend/tableau-de-bord', Personnel\TableauDeBord::class)->name('tableau-de-bord');

    // ------------------------------------------ Vérification des inscriptions en formation
    Route::middleware('permission:inscriptions.verifier')->group(function () {
        Route::get('inscriptions', Personnel\Inscriptions::class)->name('inscriptions');
        Route::get('inscriptions/{stagiaire}', Personnel\InscriptionFiche::class)->name('inscriptions.fiche')->whereNumber('stagiaire');
        Route::get('inscriptions/justificatifs/{verification}', [FichierController::class, 'justificatifPourAgent'])->name('inscriptions.justificatif');
    });

    // -------------------------------------------------------- Référentiel et hébergement
    Route::get('hebergement/plan', Personnel\Plan::class)->middleware('permission:referentiel.consulter')->name('hebergement.plan');

    Route::middleware('permission:hebergement.consulter')->prefix('hebergement')->name('hebergement.')->group(function () {
        Route::get('affectations', Personnel\Affectations::class)->name('affectations');
        Route::get('residents', Personnel\Residents::class)->name('residents');
        Route::get('residents/{affectation}', Personnel\ResidentFiche::class)->name('residents.fiche')->whereNumber('affectation');
        Route::get('residents/{affectation}/attestation', [FichierController::class, 'attestationDuResident'])->name('residents.attestation');
    });

    Route::middleware('permission:referentiel.gerer')->prefix('hebergement/import')->name('hebergement.import')->group(function () {
        Route::get('/', Personnel\ImportReferentiel::class);
        Route::get('modele', [FichierController::class, 'modeleImport'])->name('.modele');
        Route::get('{import}/rapport', [FichierController::class, 'rapportImport'])->name('.rapport')->whereNumber('import');
    });

    // ----------------------------------------------- Admissions (directeur, chef de pôle, gestionnaire)
    Route::prefix('admissions')->name('admissions.')->group(function () {
        // Classement : consulté par les rôles des admissions et, en lecture seule, par le gestionnaire ;
        // la délibération reste réservée au directeur (contrôlée à chaque action).
        Route::get('classement', Personnel\Classement::class)->middleware('permission:admissions.consulter|admissions.promouvoir')->name('classement');
        // Liste imprimable des candidats en vue de la délibération (lecture seule).
        Route::get('classement/{campagne}/impression', [ImpressionClassementController::class, 'imprimer'])
            ->middleware('permission:admissions.consulter|admissions.promouvoir')->name('classement.impression')->whereNumber('campagne');
        // Liste d'attente : consultée par le chef de pôle et le directeur, et utilisée par le gestionnaire pour appeler.
        Route::get('liste-attente', Personnel\ListeAttente::class)->middleware('permission:admissions.consulter|admissions.promouvoir')->name('liste-attente');
    });

    // ---------------------------------------------------------------------- Campagnes
    Route::middleware('permission:campagnes.consulter')->group(function () {
        Route::get('campagnes', Personnel\Campagnes::class)->name('campagnes');
        Route::get('campagnes/{campagne}', Personnel\CampagneFiche::class)->name('campagnes.fiche')->whereNumber('campagne');
    });
    Route::get('grille-reference', Personnel\GrilleReference::class)->middleware('permission:grille_reference.gerer')->name('grille-reference');

    // ---------------------------------------------------------------- Dossiers, instruction
    Route::middleware('permission:demandes.consulter')->group(function () {
        Route::get('dossiers', Personnel\Dossiers::class)->name('dossiers');
        Route::get('dossiers/{demande}', Personnel\DossierFiche::class)->name('dossiers.fiche')->whereNumber('demande');
        Route::get('dossiers/{demande}/accuse', [FichierController::class, 'accuseDuDossier'])->name('dossiers.accuse');
        Route::get('pieces/{piece}', [FichierController::class, 'pieceDuDossier'])->name('dossiers.piece');
    });

    // ---------------------------------------------------------------- Vie à l'internat
    Route::middleware('permission:vie_internat.gerer')->prefix('vie-internat')->name('vie-internat.')->group(function () {
        Route::get('presences', Personnel\Presences::class)->name('presences');
        Route::get('incidents', Personnel\Incidents::class)->name('incidents');
        Route::get('incidents/{incident}/rapport', [FichierController::class, 'rapportIncident'])->name('incidents.rapport')->whereNumber('incident');

        // Liens portés par d'anciennes notifications.
        Route::redirect('sorties', '/vie-internat/presences');
        Route::redirect('absences', '/vie-internat/presences?onglet=absences');
    });

    Route::get('reclamations', Personnel\Reclamations::class)->middleware('permission:reclamations.traiter')->name('reclamations');

    // ------------------------------------------------- Paiement des frais d'hébergement
    Route::middleware('permission:paiements.consulter')->group(function () {
        Route::get('paiements', Personnel\Paiements::class)->name('paiements');
        Route::get('paiements/{paiement}/recu', [FichierController::class, 'recuDuPaiement'])->name('paiements.recu')->whereNumber('paiement');
        Route::get('paiements/{paiement}/autorisation', [FichierController::class, 'autorisationDuPaiement'])->name('paiements.autorisation')->whereNumber('paiement');
    });

    // -------------------------------------------------------------------- Restauration
    Route::middleware('permission:restauration.consulter')->prefix('restauration')->name('restauration.')->group(function () {
        Route::get('menus', Personnel\Menus::class)->name('menus');
        Route::get('service', Personnel\ServiceDesRepas::class)->name('service');
        Route::get('statistiques', Personnel\StatistiquesRepas::class)->name('statistiques');
        Route::get('regimes/{regime}/justificatif', [FichierController::class, 'justificatifDuRegime'])
            ->middleware('permission:restauration.gerer')->name('regimes.justificatif')->whereNumber('regime');
    });

    // ------------------------------------------------------------ Rapports et exports
    Route::middleware('permission:exports.creer')->group(function () {
        Route::get('rapports', Personnel\Rapports::class)->name('rapports');
        Route::get('rapports/{export}/fichier', [FichierController::class, 'fichierDeLExport'])->name('rapports.fichier')->whereNumber('export');
    });

    Route::middleware('permission:documents.gerer')->group(function () {
        Route::get('documents', Personnel\DocumentsInternat::class)->name('documents');
        Route::get('documents/{document}/fichier', [FichierController::class, 'fichierDuDocument'])->name('documents.fichier')->whereNumber('document');
    });

    // ------------------------------------------------------------------ Administration
    Route::prefix('administration')->name('administration.')->group(function () {
        Route::get('utilisateurs', Administration\Utilisateurs::class)->middleware('permission:utilisateurs.gerer')->name('utilisateurs');
        Route::get('permissions', Administration\RolesEtPermissions::class)->middleware('permission:utilisateurs.gerer')->name('permissions');
        Route::get('comptes-stagiaires', Administration\ComptesStagiaires::class)->middleware('permission:stagiaires.gerer')->name('comptes-stagiaires');
        Route::get('organisation', Administration\Organisation::class)->middleware('permission:organisation.gerer')->name('organisation');
        Route::get('parametres', Administration\Parametres::class)->middleware('permission:parametres.gerer')->name('parametres');
        Route::get('audit', Administration\Audit::class)->middleware('permission:audit.consulter')->name('audit');
    });
});
