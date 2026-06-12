<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Canal email Brevo (complémentaire aux notifications internes).
    // BREVO_ENABLED=false par défaut : aucun envoi tant que l'admin n'a pas branché
    // ses credentials et activé explicitement le canal. Aucune incidence sur le métier.
    //
    // Deux transports disponibles :
    //   - 'api'  : HTTP POST https://api.brevo.com/v3/smtp/email (auth par BREVO_API_KEY)
    //              ✓ Pas de restriction d'IP, plus rapide, recommandé en production.
    //   - 'smtp' : SMTP relais smtp-relay.brevo.com:587 (auth login/pass)
    //              ⚠️ Sujet aux restrictions d'IP autorisées côté compte Brevo.
    //
    // Sélection : BREVO_TRANSPORT=api (défaut) ou BREVO_TRANSPORT=smtp.
    // En mode 'api', BREVO_API_KEY est requis (xkeysib-...).
    'brevo' => [
        'enabled' => filter_var(env('BREVO_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'transport' => env('BREVO_TRANSPORT', 'api'),
        'api_key' => env('BREVO_API_KEY'),
        'api_endpoint' => env('BREVO_API_ENDPOINT', 'https://api.brevo.com/v3/smtp/email'),
        'api_timeout' => (int) env('BREVO_API_TIMEOUT', 5),
        // En local Windows PHP n'a souvent pas de bundle CA configuré (curl.cainfo
        // vide dans php.ini) → cURL error 60. En dev, on peut désactiver la vérif
        // SSL via BREVO_API_VERIFY_SSL=false. NE JAMAIS le faire en production.
        'api_verify_ssl' => filter_var(env('BREVO_API_VERIFY_SSL', true), FILTER_VALIDATE_BOOLEAN),
        'mailer' => env('BREVO_MAILER', 'brevo'),
        'queue' => [
            'connection' => env('BREVO_QUEUE_CONNECTION', env('QUEUE_CONNECTION', 'database')),
            'name' => env('BREVO_QUEUE', env('DB_QUEUE', 'default')),
        ],
        'from' => [
            'address' => env('BREVO_FROM_ADDRESS', env('MAIL_FROM_ADDRESS', 'no-reply@anbg.ga')),
            'name' => env('BREVO_FROM_NAME', env('MAIL_FROM_NAME', 'ANBG · e-Pilotage PAS')),
        ],
    ],

];
