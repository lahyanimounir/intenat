<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Hachage des mots de passe : Argon2id
    |--------------------------------------------------------------------------
    |
    | Les mots de passe INTERNAT (personnel et stagiaires) sont hachés en
    | Argon2id. Les comptes créés avant ce réglage portent une empreinte bcrypt :
    | elle reste vérifiable (`verify` à false) et elle est recalculée en Argon2id
    | à la première connexion réussie (`rehash_on_login`).
    |
    */

    'driver' => env('HASH_DRIVER', 'argon2id'),

    'bcrypt' => [
        'rounds' => env('BCRYPT_ROUNDS', 12),
        'verify' => false,
        'limit' => env('BCRYPT_LIMIT', null),
    ],

    'argon' => [
        // Mémoire en Kio, nombre de passes, fils d'exécution (1 : seule valeur acceptée par l'implémentation libsodium de PHP).
        'memory' => (int) env('ARGON_MEMORY', 65536),
        'threads' => (int) env('ARGON_THREADS', 1),
        'time' => (int) env('ARGON_TIME', 4),
        'verify' => false,
    ],

    'rehash_on_login' => true,

];
