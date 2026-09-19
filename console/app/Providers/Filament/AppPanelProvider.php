<?php

namespace App\Providers\Filament;

use App\Filament\App\Pages\Auth\Register;
use App\Filament\App\Pages\Dashboard;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AppPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $panel = $panel
            ->id('app')
            ->path('app')
            ->login()
            ->passwordReset() // reset de senha self-service (seguro também p/ contas existentes)
            // S5 (PLANO-UX-INTERFACE): 2FA TOTP opcional também pro CLIENTE (antes só o /admin
            // tinha) — conta que gasta crédito merece segundo fator (baseline #9). O cliente
            // ativa no próprio perfil (/app/profile); códigos de recuperação habilitados.
            ->multiFactorAuthentication([
                AppAuthentication::make()->recoverable(),
            ])
            // Pós-login o cliente cai direto no Studio, não no painel /app. Em prod web e console
            // dividem o domínio (Traefik), então studio.url = APP_URL = a mesma raiz de sempre.
            // Em dev o console é OUTRA origem (:8210) e '/' hardcodado caía na welcome do Laravel —
            // STUDIO_URL (:3210) aponta pro Next. Fallback '/' preserva o comportamento antigo.
            ->homeUrl(rtrim((string) config('services.studio.url'), '/') ?: '/')
            ->brandName('Reachyn')
            ->favicon('/favicon.ico') // path relativo: mesma origem (https), servido pelo web
            ->colors([
                'primary' => Color::Violet,
            ])
            ->discoverResources(in: app_path('Filament/App/Resources'), for: 'App\Filament\App\Resources')
            ->discoverPages(in: app_path('Filament/App/Pages'), for: 'App\Filament\App\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/App/Widgets'), for: 'App\Filament\App\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
            ->navigationItems([
                NavigationItem::make('Abrir Studio')
                    ->icon(Heroicon::OutlinedSparkles)
                    ->url(fn (): string => route('studio.sso'), shouldOpenInNewTab: true)
                    ->sort(2),
                NavigationItem::make('Privacidade & Meus Dados')
                    ->icon(Heroicon::OutlinedShieldCheck)
                    ->url(fn (): string => route('account.privacy'))
                    ->sort(9),
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);

        // Signup self-service (freemium) — atrás de REGISTRATION_ENABLED (secure-by-default).
        // Habilita junto a verificação de e-mail OBRIGATÓRIA (User implements MustVerifyEmail).
        // ⚠️ Go-live: marcar usuários legados como verificados (email_verified_at) p/ não travar o login deles.
        if (config('services.signup.enabled')) {
            $panel
                ->registration(Register::class)
                ->emailVerification();
        }

        return $panel;
    }
}
