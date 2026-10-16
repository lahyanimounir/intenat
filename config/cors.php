<?php

return [

    /*
    | Le SPA et l'API partagent la même origine en production (Nginx). En
    | développement, le serveur Vite relaie /api et /sanctum vers Laravel.
    | Les origines supplémentaires éventuelles sont listées explicitement :
    | aucun joker, car les cookies de session sont transmis.
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_filter(array_map('trim', explode(',', (string) env(
        'CORS_ALLOWED_ORIGINS',
        'http://localhost:5173,http://127.0.0.1:5173'
    )))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'Content-Type', 'X-Requested-With', 'X-XSRF-TOKEN'],

    'exposed_headers' => ['Content-Disposition'],

    'max_age' => 600,

    'supports_credentials' => true,

];
