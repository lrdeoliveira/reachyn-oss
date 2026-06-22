<?php

return [
    // Em produção web e console são a MESMA origem (app.example.com) → CORS nem dispara.
    // Em dev (web :3000 → console :8000) liberamos a origem do front com credenciais.
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'login', 'logout'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        env('FRONTEND_URL', 'http://localhost:3000'),
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,
];
