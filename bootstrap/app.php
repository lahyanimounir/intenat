<?php

use App\Domain\Identity\MyWay\FournisseurIndisponibleException;
use App\Domain\Identity\MyWay\IdentifiantsInvalidesException;
use App\Domain\Identity\MyWay\ReponseInattendueException;
use App\Domain\Shared\RegleMetierException;
use App\Http\Middleware\EnTetesSecurite;
use App\Http\Middleware\ExigerAdresseVerifiee;
use App\Http\Middleware\ExigerInscriptionVerifiee;
use App\Http\Middleware\ExigerPermission;
use App\Http\Middleware\ExigerPersonnel;
use App\Http\Middleware\ExigerProfilComplet;
use App\Http\Middleware\ExigerStagiaire;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [EnTetesSecurite::class]);

        // API de l'ancienne interface : conservée le temps de la migration des écrans, puis retirée.
        $middleware->statefulApi();
        $middleware->api(append: [EnTetesSecurite::class]);
        $middleware->throttleApi('api');

        $middleware->alias([
            'personnel' => ExigerPersonnel::class,
            'stagiaire' => ExigerStagiaire::class,
            'permission' => ExigerPermission::class,
            'adresse.verifiee' => ExigerAdresseVerifiee::class,
            'profil.complet' => ExigerProfilComplet::class,
            'inscription.verifiee' => ExigerInscriptionVerifiee::class,
        ]);

        // Un visiteur est renvoyé à la connexion ; un compte déjà connecté, à l'accueil de son espace.
        $middleware->redirectGuestsTo(fn () => route('connexion'));
        $middleware->redirectUsersTo(fn () => auth('stagiaire')->check() ? route('stagiaire.accueil') : route('tableau-de-bord'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $api = fn (Request $request) => $request->is('api/*');

        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $api($request) || $request->expectsJson());

        // Les règles métier et les erreurs d'accès attendues ne polluent pas le journal d'erreurs.
        $exceptions->dontReport([RegleMetierException::class, IdentifiantsInvalidesException::class]);

        // Interface : une règle de gestion non interceptée par l'écran ramène à la page précédente, avec son explication.
        $exceptions->render(function (RegleMetierException $e, Request $request) use ($api) {
            if ($api($request) || $request->expectsJson() || $request->hasHeader('X-Livewire')) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'code' => $e->codeRegle,
                    'contexte' => $e->contexte ?: null,
                ], $e->statutHttp);
            }

            return back()->with('danger', $e->getMessage());
        });

        // Session fermée ou expirée pendant qu'un écran était ouvert : l'action Livewire reçoit un 401 et la page se recharge
        // vers la connexion (voir resources/js/app.js), au lieu d'afficher la page de connexion dans une fenêtre d'erreur.
        $exceptions->render(fn (AuthenticationException $e, Request $request) => $request->hasHeader('X-Livewire') && ! $api($request)
            ? response()->noContent(401)
            : null);

        // ------------------------------------------------------------ API (ancienne interface)
        // Message identique que l'adresse existe ou non : pas d'énumération de comptes.
        $exceptions->render(fn (IdentifiantsInvalidesException $e, Request $request) => $api($request) ? response()->json([
            'message' => 'Identifiants MyWay incorrects.',
            'code' => 'identifiants_invalides',
        ], 422) : null);

        $exceptions->render(fn (FournisseurIndisponibleException $e, Request $request) => $api($request) ? response()->json([
            'message' => 'Le service MyWay est momentanément indisponible. Réessayez plus tard.',
            'code' => 'fournisseur_indisponible',
        ], 503) : null);

        $exceptions->render(fn (ReponseInattendueException $e, Request $request) => $api($request) ? response()->json([
            'message' => 'Les informations reçues de MyWay sont incomplètes. Contactez l\'administration de votre établissement.',
            'code' => 'reponse_inattendue',
        ], 502) : null);

        $exceptions->render(fn (AuthenticationException $e, Request $request) => $api($request) ? response()->json([
            'message' => 'Votre session a expiré. Reconnectez-vous.',
            'code' => 'non_authentifie',
        ], 401) : null);

        $exceptions->render(fn (AuthorizationException|AccessDeniedHttpException $e, Request $request) => $api($request) ? response()->json([
            'message' => $e->getMessage() && $e->getMessage() !== 'This action is unauthorized.'
                ? $e->getMessage()
                : 'Vous n\'êtes pas autorisé à effectuer cette action.',
            'code' => 'acces_refuse',
        ], 403) : null);

        $exceptions->render(fn (ModelNotFoundException|NotFoundHttpException $e, Request $request) => $api($request) ? response()->json([
            'message' => 'Ressource introuvable.',
            'code' => 'introuvable',
        ], 404) : null);

        $exceptions->render(fn (ThrottleRequestsException $e, Request $request) => $api($request) ? response()->json([
            'message' => 'Trop de tentatives. Patientez avant de réessayer.',
            'code' => 'trop_de_tentatives',
        ], 429, $e->getHeaders()) : null);
    })->create();
