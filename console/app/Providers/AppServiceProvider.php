<?php

namespace App\Providers;

use App\Support\Audit;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Pós-login do painel cliente → Studio (/pesquisar), não o painel Filament /app.
        $this->app->bind(
            \Filament\Auth\Http\Responses\Contracts\LoginResponse::class,
            \App\Http\Responses\LoginResponse::class,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Atrás do Traefik (TLS no proxy): força https em TODAS as URLs geradas
        // (asset/url/route), senão assets como o favicon saem em http → mixed-content.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        // RLS (defense-in-depth): o middleware SetCurrentTenant roda ANTES do auth, então o
        // valor real do tenant só é conhecido aqui — quando o guard resolve o usuário. Re-sincroniza
        // app.current_tenant com o tenant do usuário autenticado para a política RLS filtrar de fato.
        Event::listen(\Illuminate\Auth\Events\Authenticated::class, function (): void {
            \App\Http\Middleware\SetCurrentTenant::sync();
        });

        // AUD-005: auditoria de autenticação (login ok / falha / lockout do throttle).
        Event::listen(Login::class, function (Login $e): void {
            Audit::log('auth.login.success', ['guard' => $e->guard, 'auth_user_id' => $e->user?->getAuthIdentifier()]);
        });
        Event::listen(Failed::class, function (Failed $e): void {
            // NUNCA logar a senha: registramos só o e-mail tentado (credentials['email']).
            Audit::log('auth.login.failed', ['guard' => $e->guard, 'email' => $e->credentials['email'] ?? null]);
        });
        Event::listen(Lockout::class, function (Lockout $e): void {
            Audit::log('auth.login.lockout', ['ip' => $e->request->ip(), 'email' => $e->request->input('email')]);
        });
    }
}
