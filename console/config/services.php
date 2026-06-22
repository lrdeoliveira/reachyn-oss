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

    // Publish social white-label (1 token → N profiles, 1 por tenant)
    'zernio' => [
        'key' => env('ZERNIO_API_KEY'),
    ],

    // Engine Go (geração) — chamado pelo console e pelo web
    'engine' => [
        'url' => env('ENGINE_URL', 'http://engine:8080'),
        // Token compartilhado p/ /v1/admin/* do engine + /internal/gen-keys do console.
        'admin_token' => env('ENGINE_ADMIN_TOKEN'),
    ],

    // Studio Next.js (web) — destino do SSO a partir do painel do cliente
    'studio' => [
        'url' => env('STUDIO_URL'),
    ],

    // Provedor de voz — clonagem de voz e dublagem (Studio). White-label: base via env.
    'voice' => [
        'key' => env('SPEECH_API_KEY'),
        'base' => env('VOICE_API_BASE'),
    ],

];
