<?php

namespace App\Providers;

use App\Http\Middleware\SetCurrentTenant;
use App\Http\Responses\LoginResponse;
use App\Support\Audit;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
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
            LoginResponse::class,
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

        // Reply-To global: envio é noreply@redfoxcode.com.br (transacional), mas
        // respostas dos usuários caem na caixa monitorada contato@redfoxcode.com.br.
        Mail::alwaysReplyTo('contato@redfoxcode.com.br', 'RedFoxCode');

        Event::listen(Authenticated::class, function (): void {
            SetCurrentTenant::sync();
        });

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
