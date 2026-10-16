<?php

use App\Models\Stagiaire;
use App\Models\Utilisateur;

return [

    /*
    |--------------------------------------------------------------------------
    | Gardes d'authentification
    |--------------------------------------------------------------------------
    |
    | Deux populations distinctes, conformément au dictionnaire de données :
    | le personnel (table `utilisateur`) et les stagiaires (table `stagiaire`).
    | Les deux gardes reposent sur la session (SPA Sanctum, cookies + CSRF).
    |
    */

    'defaults' => [
        'guard' => 'web',
        'passwords' => 'utilisateurs',
    ],

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'utilisateurs',
        ],
        'stagiaire' => [
            'driver' => 'session',
            'provider' => 'stagiaires',
        ],
    ],

    'providers' => [
        'utilisateurs' => [
            'driver' => 'eloquent',
            'model' => Utilisateur::class,
        ],
        'stagiaires' => [
            'driver' => 'eloquent',
            'model' => Stagiaire::class,
        ],
    ],

    /*
    | Réinitialisation du mot de passe INTERNAT par lien envoyé à l'adresse du
    | compte : jeton haché en base, valable `expire` minutes, à usage unique ;
    | une nouvelle demande n'est acceptée qu'après `throttle` secondes.
    | Deux tables : une même adresse peut exister chez le personnel et chez les stagiaires.
    */
    'passwords' => [
        'utilisateurs' => [
            'provider' => 'utilisateurs',
            'table' => 'password_reset_tokens',
            'expire' => 30,
            'throttle' => 60,
        ],
        'stagiaires' => [
            'provider' => 'stagiaires',
            'table' => 'stagiaire_password_reset_tokens',
            'expire' => 30,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

];
