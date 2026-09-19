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
        // 🔗 Espera pelo permalink: publicar é assíncrono na rede e o link só existe depois que
        // ela aceita o upload (9s num vídeo do YouTube, caso real 2026-08-04). Roda dentro do job
        // de publicação, então esperar não trava ninguém — mas tem teto: sem link a peça continua
        // publicada, só fica sem o atalho no arquivo. 0 tentativas = não espera.
        'permalink_tentativas' => (int) env('ZERNIO_PERMALINK_TENTATIVAS', 5),
        'permalink_espera' => (int) env('ZERNIO_PERMALINK_ESPERA', 4),
    ],

    // Engine Go (geração) — chamado pelo console e pelo web
    'engine' => [
        'url' => env('ENGINE_URL', 'http://engine:8080'),
        // Token compartilhado p/ /v1/admin/* do engine + /internal/gen-keys do console.
        'admin_token' => env('ENGINE_ADMIN_TOKEN'),
    ],

    // Motion transfer (workflow ComfyUI). Caro e lento →
    // premium, assíncrono, custo alto. Config-driven: trocar de workflow (ex. os workflows próprios do
    // o operador depois de subir o workflow) é só mudar os env — sem redeploy. Default =
    // SDPose Uni3C (nó 106 = imagem-alvo/keyframe, nó 130 = vídeo-guia; validado no spike 2026-07-19).
    'motion' => [
        'workflow_id' => env('MOTION_WORKFLOW_ID', '2001253005766914050'),
        'image_node' => env('MOTION_IMAGE_NODE', '106'),
        'video_node' => env('MOTION_VIDEO_NODE', '130'),
        'instance_type' => env('MOTION_INSTANCE_TYPE', 'default'), // default=24G | plus=48G
        'cost_credits' => (int) env('MOTION_COST_CREDITS', 300),   // reflete o Runtime Fee alto (premium)
    ],

    // ffmpeg-service (mídia): mesma rede docker (reachyn-net). Usado pelo model sheet para
    // COMPOR a folha (grid fixo) a partir dos shots individuais. POST exige X-Service-Token (AUD-008).
    'ffmpeg' => [
        'url' => env('MEDIA_FFMPEG_URL', 'http://ffmpeg-service:7788'),
        'token' => env('FFMPEG_SERVICE_TOKEN'),
    ],

    // Studio Next.js (web) — destino do SSO/redirects (Connect social, plano, conexões) a partir
    // do painel do cliente. Em produção web e console são MESMA ORIGEM (app.example.com), então
    // quando STUDIO_URL não está setado caímos no APP_URL — evita redirect_url relativo (Zernio 400/
    // 500 no Connect). Em dev, aponte STUDIO_URL pro web (ex.: http://localhost:3000).
    'studio' => [
        'url' => env('STUDIO_URL', env('APP_URL')),
    ],

    // Web Next.js — endpoint INTERNO do compositor de posts (next/og). Chamada server-to-server
    // console→web pela rede docker; NUNCA exposto ao browser. Fechado por COMPOSE_TOKEN (secure-baseline #2).
    'web' => [
        'url' => env('WEB_INTERNAL_URL', 'http://web:3210'),
        'compose_token' => env('COMPOSE_TOKEN'),
    ],

    // Voz — clonagem e dublagem (Studio)
    'elevenlabs' => [
        'key' => env('ELEVENLABS_API_KEY'),
    ],

    // Cadastro self-service (freemium). OFF por padrão (secure-by-default): liga-se no
    // go-live, depois de validar Resend + marcar usuários legados como verificados.
    'signup' => [
        'enabled' => env('REGISTRATION_ENABLED', false),
        'trial_days' => (int) env('REGISTRATION_TRIAL_DAYS', 7),
    ],


    // CLI Bridge — sidecar opcional no host que roda as CLIs de IA.
    // O console só lê o /health daqui, pra listar os refinadores de prompt vivos; a
    // GERAÇÃO passa pelo engine, que tem o token. Vazio = sem refinadores na UI.
    'cli_bridge' => [
        'url' => env('CLI_BRIDGE_URL', ''),
        // Opcional: o /health do bridge aceita Bearer. Vazio = chamada sem auth (o bridge só
        // escuta em 127.0.0.1/docker0, então o /health continua fechado pra fora).
        'token' => env('CLI_BRIDGE_TOKEN', ''),
    ],

];
