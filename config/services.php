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
    'brevo' => [
        'enabled' => filter_var(env('BREVO_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'mailer' => env('BREVO_MAILER', 'brevo'),
        'from' => [
            'address' => env('BREVO_FROM_ADDRESS', env('MAIL_FROM_ADDRESS', 'no-reply@anbg.ga')),
            'name' => env('BREVO_FROM_NAME', env('MAIL_FROM_NAME', 'ANBG · e-Pilotage PAS')),
        ],
    ],

];
