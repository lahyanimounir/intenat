<?php

namespace App\Providers;

use App\Domain\Identity\MyWay\FournisseurApprouve;
use App\Domain\Identity\MyWay\FournisseurConnecteur;
use App\Domain\Identity\MyWay\FournisseurIdentiteStagiaire;
use App\Domain\Identity\MyWay\FournisseurSimule;
use App\Domain\Identity\Perimetre;
use App\Domain\Shared\Parametres;
use App\Http\Middleware\ExigerAdresseVerifiee;
use App\Http\Middleware\ExigerLitAffecte;
use App\Http\Middleware\ExigerPermission;
use App\Http\Middleware\ExigerPersonnel;
use App\Http\Middleware\ExigerProfilComplet;
use App\Http\Middleware\ExigerStagiaire;
use App\Models\NotificationInterne;
use App\Models\Utilisateur;
use App\Notifications\MessageInternat;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Un calcul de périmètre et une lecture des paramètres par requête.
        $this->app->scoped(Perimetre::class);
        $this->app->scoped(Parametres::class);

        /*
         * Le simulateur MyWay n'existe qu'en démonstration, en développement et en
         * test. Le connecteur (connexion réelle par navigateur, comptes autorisés
         * uniquement) n'existe que hors production, tant que l'OFPPT n'a pas donné
         * son accord, et jamais en mode démonstration : une identité réelle ne se
         * mêle pas à des données synthétiques. Dans tous les autres cas —
         * production, configuration absente, erronée ou incohérente — on retombe
         * sur le fournisseur officiel, qui refuse toute connexion tant qu'il n'est
         * pas configuré : jamais de faux MyWay par défaut, et jamais de simulation
         * à la place d'une connexion réelle. `php artisan internat:myway-diagnostic`
         * dit lequel est actif.
         */
        $this->app->bind(FournisseurIdentiteStagiaire::class, function ($app) {
            $choix = config('internat.myway.fournisseur');
            $demo = (bool) config('internat.mode_demo');
            $simulateurPermis = ! $app->isProduction() && ($demo || $app->runningUnitTests());
            $connecteurPermis = ! $app->isProduction() && ! $demo;

            return match (true) {
                $choix === 'mock' && $simulateurPermis => new FournisseurSimule(config('internat.myway.fichier_mock')),
                $choix === 'connecteur' && $connecteurPermis => FournisseurConnecteur::depuisConfiguration(),
                default => new FournisseurApprouve,
            };
        });
    }

    public function boot(): void
    {
        JsonResource::withoutWrapping();

        // Hors production : les chargements N+1 sont signalés dans le journal (sans
        // interrompre la requête) et une affectation de masse non prévue lève une erreur.
        Model::preventLazyLoading(! $this->app->isProduction() && ! $this->app->runningUnitTests());
        Model::handleLazyLoadingViolationUsing(function (Model $modele, string $relation) {
            $origine = $this->app->runningInConsole() ? 'console' : request()->method().' '.request()->path();
            logger()->warning('Chargement paresseux (N+1) : '.$modele::class.'::'.$relation.' — '.$origine);
        });
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        Password::defaults(fn () => Password::min(10)->letters()->mixedCase()->numbers());

        if ($proxys = config('internat.proxys_de_confiance')) {
            TrustProxies::at($proxys === '*' ? '*' : array_map('trim', explode(',', $proxys)));
        }

        // Une action Livewire repasse par les mêmes contrôles que l'ouverture de l'écran : compte actif,
        // permission du rôle, adresse confirmée, profil complet. Un compte suspendu perd la main aussitôt.
        Livewire::addPersistentMiddleware([
            AuthenticateSession::class,
            ExigerPersonnel::class, ExigerStagiaire::class, ExigerPermission::class,
            ExigerAdresseVerifiee::class, ExigerProfilComplet::class, ExigerLitAffecte::class,
        ]);

        $this->limitesDeDebit();
        $this->portes();
        $this->suiviDesCourriels();
    }

    private function limitesDeDebit(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(180)
            ->by($request->user()?->getAuthIdentifier() ? get_class($request->user()).':'.$request->user()->getAuthIdentifier() : $request->ip()));

        /*
         * Authentification, première connexion, activation, récupération de compte.
         *  - par cible et par poste : 5 essais par minute sur un même compte (adresse ou CEF) ou sur une
         *    même autorisation (jeton d'activation, référence de code, jeton de réinitialisation) ;
         *  - par cible, tous postes confondus : freine une attaque répartie sur de nombreuses adresses IP ;
         *  - par poste : plafond large, car tout un établissement sort souvent par la même adresse IP.
         */
        RateLimiter::for('connexion', function (Request $request) {
            $compte = mb_strtolower(trim((string) ($request->input('email') ?: $request->input('cef'))));
            $preuve = (string) ($request->input('jeton_activation') ?: $request->input('jeton') ?: $request->input('reference') ?: $request->input('etat'));
            $cible = match (true) {
                $compte !== '' => 'compte:'.$compte,
                $preuve !== '' => 'preuve:'.hash('sha256', $preuve),
                // Départ d'une vérification par redirection : rien n'est encore visé, seul le plafond par poste s'applique.
                default => null,
            };

            return array_filter([
                $cible !== null ? Limit::perMinute(5)->by($cible.'|'.$request->ip()) : null,
                $compte !== '' ? Limit::perMinutes(15, 30)->by('partout:'.$cible) : null,
                Limit::perMinute(120)->by('ip:'.$request->ip()),
            ]);
        });

        RateLimiter::for('televersement', fn (Request $request) => Limit::perMinute(30)
            ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }

    private function portes(): void
    {
        /*
         * Porte générique « permission + périmètre » : l'utilisateur détient la
         * permission ET la ligne visée relève d'un internat qu'il a en charge.
         */
        Gate::define('perimetre', function (Utilisateur $utilisateur, Model $modele, string $permission) {
            return $utilisateur->can($permission)
                && app(Perimetre::class)->couvreInternat($utilisateur, $modele->internatId());
        });
    }

    /**
     * Le statut d'un courriel n'est « accepté » qu'après remise au serveur d'envoi.
     * Avec un transport de développement (log, array), il est marqué « journalisé ».
     */
    private function suiviDesCourriels(): void
    {
        Event::listen(function (NotificationSent $evenement) {
            if ($evenement->notification instanceof MessageInternat && $evenement->channel === 'mail') {
                $reel = ! in_array(config('mail.default'), ['log', 'array'], true);

                NotificationInterne::whereKey($evenement->notification->notificationId)
                    ->update(['email_statut' => $reel ? 'accepte' : 'journalise']);
            }
        });

        Event::listen(function (NotificationFailed $evenement) {
            if ($evenement->notification instanceof MessageInternat) {
                NotificationInterne::whereKey($evenement->notification->notificationId)
                    ->update(['email_statut' => 'echec']);
            }
        });
    }
}
