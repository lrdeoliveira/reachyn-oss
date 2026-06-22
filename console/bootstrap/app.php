<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Atrás do Traefik (TLS termina no proxy): confiar no X-Forwarded-* para
        // que o Laravel gere URLs/redirects em https (senão /app → http://…/app/login).
        // AUD-007: não confiar em qualquer proxy ('*' permite spoof de X-Forwarded-*).
        // Restringe às faixas privadas do Docker (rede do Traefik) + loopback.
        $middleware->trustProxies(at: [
            '10.0.0.0/8',
            '172.16.0.0/12',
            '192.168.0.0/16',
            '127.0.0.1',
        ], headers: Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO);

        // Mesma origem (app.example.com): o web usa a SESSÃO do login Filament
        // (cookie) para autenticar as chamadas /api — Sanctum stateful, sem token.
        $middleware->statefulApi();

        // RLS (defense-in-depth): define app.current_tenant por request (web + api), após o
        // auth resolver o usuário. Política fail-open no banco — ver migration enable_rls_*.
        $middleware->appendToGroup('web', \App\Http\Middleware\SetCurrentTenant::class);
        $middleware->appendToGroup('api', \App\Http\Middleware\SetCurrentTenant::class);

        // Não há rota 'login' genérica: o login do cliente é o painel Filament /app.
        $middleware->redirectGuestsTo(fn () => route('filament.app.auth.login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
