<?php

/*
|--------------------------------------------------------------------------
| Configuration métier INTERNAT
|--------------------------------------------------------------------------
|
| Valeurs techniques et valeurs par défaut des règles de gestion. Les règles
| marquées « à valider » dans la documentation sont exposées dans la table
| `parametre` (modifiable par l'administrateur) ; ce fichier n'en fournit que
| la valeur initiale. Voir docs/requirements/questions-ouvertes.md.
|
*/

return [

    // Affiche le bandeau « Démonstration » et autorise le fournisseur MyWay simulé.
    'mode_demo' => (bool) env('INTERNAT_MODE_DEMO', false),

    // Mot de passe des comptes créés par le seeder de démonstration.
    'demo_mot_de_passe' => env('INTERNAT_DEMO_MOT_DE_PASSE'),

    // Proxys amont dont les en-têtes X-Forwarded-* sont crus (adresses ou plages séparées par des virgules, ou « * »).
    // Sans cela, l'adresse du proxy remplacerait celle du client dans le journal d'audit et les limites de débit.
    'proxys_de_confiance' => env('TRUSTED_PROXIES'),

    'myway' => [
        // `mock` : profils synthétiques (développement, tests, démonstration).
        // `connecteur` : outil de diagnostic — connexion réelle par le service myway-connector, pour les seuls comptes autorisés.
        // `approuve` : intégration officielle ; ne propose aucune méthode tant que l'OFPPT n'a pas communiqué de mécanisme.
        // Ni le simulateur ni le connecteur ne sont actifs en production (voir AppServiceProvider).
        'fournisseur' => env('MYWAY_FOURNISSEUR', 'mock'),
        'connecteur' => [
            // Service interne, sur la boucle locale : il reçoit le mot de passe MyWay le temps d'une vérification.
            'url' => env('MYWAY_CONNECTEUR_URL', 'http://127.0.0.1:8787'),
            'jeton' => env('MYWAY_CONNECTEUR_JETON'),
            // Adresses MyWay dont le titulaire a autorisé la vérification, séparées par des virgules. Vide : tout est refusé.
            'comptes_autorises' => env('MYWAY_COMPTES_AUTORISES', ''),
            // Une vérification dure le temps d'une session de navigateur (75 s au plus côté connecteur).
            'delai_secondes' => (int) env('MYWAY_CONNECTEUR_TIMEOUT', 90),
        ],
        'statut_eligible' => 'Inscrit Payé',
        'url' => env('MYWAY_API_URL'),
        'client_id' => env('MYWAY_CLIENT_ID'),
        'client_secret' => env('MYWAY_CLIENT_SECRET'),
        'delai_secondes' => (int) env('MYWAY_TIMEOUT', 10),
        'fichier_mock' => database_path('fixtures/myway_mock.json'),
        // Durée de validité de l'autorisation d'activation délivrée par une vérification d'identité réussie.
        'activation_minutes' => (int) env('INTERNAT_ACTIVATION_MINUTES', 30),
        // Conservation, en jours, des vérifications closes (activées, expirées ou examinées) avant leur suppression.
        'conservation_verifications_jours' => (int) env('INTERNAT_CONSERVATION_VERIFICATIONS_JOURS', 90),
    ],

    'inscription' => [
        // Confirmation de l'adresse e-mail à la création du compte. `null` : exigée dès qu'un serveur d'envoi réel est configuré
        // (MAIL_MAILER autre que `log` ou `array`) ; `true` ou `false` force le réglage.
        'verifier_adresse' => env('INTERNAT_VERIFIER_ADRESSE'),
    ],

    'documents' => [
        'disque' => env('INTERNAT_DISQUE_DOCUMENTS', 'prive'),
        'taille_max_ko' => (int) env('INTERNAT_TAILLE_MAX_KO', 5120),
        'extensions' => ['pdf', 'jpg', 'jpeg', 'png'],
        'mimes' => ['application/pdf', 'image/jpeg', 'image/png'],
    ],

    'score' => [
        // « Score sur 80 » : plafond documenté du barème d'une campagne.
        'bareme_maximum' => 80,
    ],

    // Valeurs initiales de la table `parametre` (clé => [valeur, libellé, à valider]).
    'parametres' => [
        'inscription.justificatif_obligatoire' => [true, 'Un justificatif d\'inscription est exigé pour soumettre son inscription à la vérification', true],
        'classement.departage' => ['arbitrage_chef_pole', 'Règle de départage des ex æquo (arbitrage_chef_pole | date_soumission)', true],
        'places.exclure_isolement_infirmerie' => [true, 'Les chambres Isolement et Infirmerie ne comptent pas dans les places offertes', true],
        'instruction.masquer_score_gestionnaire' => [true, 'Le score et le rang sont masqués au gestionnaire', true],
        'occupation.exiger_autorisation_paiement' => [true, 'Une autorisation de paiement est requise avant d\'autoriser l\'occupation', true],
        'occupation.exiger_recu_valide' => [false, 'Un reçu validé est requis avant d\'autoriser l\'occupation (documenté : non bloquant)', true],
        'restauration.heure_limite_reservation' => ['18:00', 'Heure limite de réservation ou d\'annulation, la veille du repas', true],
        'restauration.sortie_annule_repas' => [false, 'Une sortie acceptée annule les repas réservés sur la période', true],
        'absence.alerte_destinataires' => ['gestionnaire', 'Destinataires de l\'alerte d\'absence non autorisée (gestionnaire | gestionnaire_et_chef_pole)', true],
    ],

];
