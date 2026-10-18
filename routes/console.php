<?php

use App\Domain\Audit\Journal;
use App\Domain\Campaigns\GestionCampagne;
use App\Domain\Identity\MyWay\FournisseurIdentiteStagiaire;
use App\Domain\Payments\SuiviPaiements;
use App\Domain\Reporting\Exports;
use App\Enums\MethodeVerification;
use App\Enums\ResultatVerification;
use App\Enums\RoleCode;
use App\Models\Utilisateur;
use App\Models\VerificationIdentite;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/*
|--------------------------------------------------------------------------
| Tâches planifiées
|--------------------------------------------------------------------------
|
| Chaque tâche est idempotente : rejouée, elle ne produit ni seconde décision
| ni seconde notification (statuts déjà changés, contraintes d'unicité, clés
| d'unicité des notifications). `withoutOverlapping` et `onOneServer` évitent
| en outre les exécutions simultanées.
|
*/

Artisan::command('internat:cloturer-campagnes', function (GestionCampagne $campagnes) {
    $this->info('Campagnes clôturées : '.$campagnes->cloturerLesEchues());
})->purpose('Clôture les campagnes dont la date de clôture est passée');

Artisan::command('internat:mensualites', function (SuiviPaiements $paiements) {
    $this->info('Mensualités ouvertes : '.$paiements->genererMensualites());
})->purpose('Ouvre la mensualité des frais d\'internat du mois, à payer, pour chaque résident hébergé');

Artisan::command('internat:rappels-restauration', function (SuiviPaiements $paiements) {
    $this->info('Rappels envoyés : '.$paiements->rappelerRestauration());
})->purpose('Invite chaque résident à régler sa restauration du mois (simple rappel, sans blocage)');

Artisan::command('internat:purger-exports', function (Exports $exports) {
    $this->info('Exports expirés supprimés : '.$exports->purgerExpires());
})->purpose('Supprime les fichiers d\'export expirés');

Artisan::command('internat:purger-verifications', function () {
    // Une autorisation expirée sans activation ne doit plus porter de profil, même chiffré.
    $effaces = VerificationIdentite::query()
        ->whereNotNull('profil')->where('expire_le', '<', now())->whereNull('consommee_le')
        ->update(['profil' => null]);

    // Les lignes closes (activées, expirées, examinées) sont supprimées passé le délai de conservation.
    // Une vérification qui attend encore un examen est gardée.
    $limite = now()->subDays((int) config('internat.myway.conservation_verifications_jours'));
    $supprimees = VerificationIdentite::query()
        ->where('updated_at', '<', $limite)
        ->where(fn ($q) => $q
            ->where('resultat', ResultatVerification::Verifiee->value)
            ->orWhereNotNull('examinee_le'))
        ->delete();

    $this->info("Profils effacés : {$effaces} ; vérifications supprimées : {$supprimees}");
})->purpose('Efface les profils des vérifications expirées et supprime les vérifications closes');

/*
|--------------------------------------------------------------------------
| Installation
|--------------------------------------------------------------------------
*/

Artisan::command('internat:creer-administrateur {email} {--nom=Administrateur} {--prenom=INTERNAT}', function (Journal $journal) {
    // Le mot de passe est demandé au clavier : il n'apparaît ni dans l'historique du terminal ni dans les journaux.
    $motDePasse = (string) $this->secret('Mot de passe du compte');
    $confirmation = (string) $this->secret('Confirmez le mot de passe');

    $controle = Validator::make(
        ['email' => $this->argument('email'), 'mot_de_passe' => $motDePasse, 'mot_de_passe_confirmation' => $confirmation],
        ['email' => ['required', 'email', 'max:255', 'unique:utilisateur,email'], 'mot_de_passe' => ['required', 'confirmed', Password::defaults()]],
        [],
        ['email' => 'adresse e-mail', 'mot_de_passe' => 'mot de passe'],
    );

    if ($controle->fails()) {
        foreach ($controle->errors()->all() as $erreur) {
            $this->error($erreur);
        }

        return 1;
    }

    $compte = Utilisateur::create([
        'nom' => $this->option('nom'), 'prenom' => $this->option('prenom'), 'email' => mb_strtolower($this->argument('email')),
        'mot_de_passe_hash' => Hash::make($motDePasse), 'actif' => true,
    ]);
    $compte->syncRoles([RoleCode::Admin->value]);
    $journal->consigner('utilisateur.creation', $compte, details: ['role' => RoleCode::Admin->value, 'origine' => 'console']);

    $this->info("Compte administrateur créé : {$compte->email}");

    return 0;
})->purpose('Crée un compte administrateur (première installation)');

/*
|--------------------------------------------------------------------------
| Liaison MyWay
|--------------------------------------------------------------------------
|
| Le fournisseur d'identité retenu dépend de plusieurs réglages, et un réglage
| incohérent se replie toujours sur le fournisseur officiel, inopérant. Cette
| commande dit lequel est réellement actif, et pourquoi.
|
*/

Artisan::command('internat:myway-diagnostic', function (FournisseurIdentiteStagiaire $effectif) {
    $choix = (string) config('internat.myway.fournisseur');
    $connecteur = (array) config('internat.myway.connecteur');

    $this->line("Fournisseur demandé (MYWAY_FOURNISSEUR) : {$choix}");
    $this->line('Fournisseur actif                       : '.$effectif->nom().($effectif->estSimule() ? ' — profils fictifs' : ''));
    $this->line('Méthode de vérification                 : '.$effectif->methode()->libelle());

    if ($effectif->methode() === MethodeVerification::Indisponible) {
        $this->warn('Aucun mécanisme approuvé n\'est configuré : la première connexion des stagiaires est fermée.');
    }

    if ($choix !== 'connecteur') {
        $this->comment('La connexion réelle à MyWay n\'est pas demandée (MYWAY_FOURNISSEUR=connecteur).');

        return $effectif->nom() === $choix ? 0 : 1;
    }

    $problemes = [];

    if (app()->isProduction()) {
        $problemes[] = 'Le connecteur est refusé en production tant que l\'OFPPT n\'a pas donné son accord.';
    }
    if (config('internat.mode_demo')) {
        $problemes[] = 'INTERNAT_MODE_DEMO=true : une identité réelle ne se mêle pas aux données de démonstration. Utilisez une base distincte avec INTERNAT_MODE_DEMO=false.';
    }
    if (strlen((string) ($connecteur['jeton'] ?? '')) < 32) {
        $problemes[] = 'MYWAY_CONNECTEUR_JETON est absent ou trop court (32 caractères au moins).';
    }

    $comptes = array_filter(array_map('trim', explode(',', (string) ($connecteur['comptes_autorises'] ?? ''))));
    $this->line('Comptes autorisés                       : '.count($comptes));
    if ($comptes === []) {
        $problemes[] = 'MYWAY_COMPTES_AUTORISES est vide : toute vérification serait refusée.';
    }

    try {
        $sante = Http::connectTimeout(3)->timeout(5)->acceptJson()->get(rtrim((string) ($connecteur['url'] ?? ''), '/').'/sante');

        if (! $sante->successful() || $sante->json('service') !== 'myway-connector') {
            $problemes[] = "Aucun connecteur MyWay ne répond à {$connecteur['url']} (HTTP {$sante->status()}).";
        } else {
            $this->line('Connecteur                              : version '.$sante->json('version').', cible '.$sante->json('cible.site'));

            if ($sante->json('cible.officielle') !== true) {
                $problemes[] = 'Le connecteur vise un banc d\'essai, pas MyWay : INTERNAT refusera tous ses résultats.';
            }
            if ((int) $sante->json('comptes_autorises') === 0) {
                $problemes[] = 'Le connecteur n\'autorise aucun compte (MYWAY_COMPTES_AUTORISES de myway-connector/.env).';
            }
        }
    } catch (ConnectionException) {
        $problemes[] = "Connecteur injoignable à {$connecteur['url']} : lancez « npm start » dans myway-connector/.";
    }

    if ($problemes === []) {
        // Ce contrôle ne sollicite pas MyWay : il ne dit ni que le site répond, ni qu'il fournit les champs exigés.
        $this->info('La liaison entre INTERNAT et le connecteur est correctement réglée pour les comptes autorisés.');
        $this->comment('MyWay n\'a pas été sollicité : seule une vérification réelle, menée par le titulaire du compte, prouve que la connexion fonctionne (myway-connector/README.md).');

        return 0;
    }

    foreach ($problemes as $probleme) {
        $this->error($probleme);
    }

    return 1;
})->purpose('Indique quel fournisseur MyWay est actif et vérifie la liaison avec le connecteur');

Schedule::command('internat:cloturer-campagnes')->dailyAt('00:10')->withoutOverlapping()->onOneServer();
Schedule::command('internat:mensualites')->monthlyOn(1, '06:00')->withoutOverlapping()->onOneServer();
Schedule::command('internat:rappels-restauration')->monthlyOn(1, '08:00')->withoutOverlapping()->onOneServer();
Schedule::command('internat:purger-exports')->dailyAt('02:30')->withoutOverlapping()->onOneServer();
Schedule::command('internat:purger-verifications')->hourly()->withoutOverlapping()->onOneServer();
Schedule::command('auth:clear-resets utilisateurs')->dailyAt('03:00');
Schedule::command('auth:clear-resets stagiaires')->dailyAt('03:05');
Schedule::command('queue:prune-failed', ['--hours' => 168])->daily();

// Tables Markdown de la documentation (routes, matrice des permissions) : voir routes/console-docs.php.
require __DIR__.'/console-docs.php';
