<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'organization_id', 'tenant_id', 'role'])]
#[Hidden(['password', 'remember_token'])]
/**
 * Tipos pós-cast que o Larastan não infere do casts() (colunas json/jsonb/timestamp).
 * @property array<string>|null $app_authentication_recovery_codes
 */
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /** Organização do usuário (dona do billing). Usuário acessa TODAS as marcas dela.
     *
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** Marca ATIVA/default do usuário (a sessão pode trocar para outra marca da mesma org).
     *
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** Marcas que o usuário pode acessar = todas as da sua org (fallback: só a marca default). */
    public function accessibleTenants()
    {
        return $this->organization
            ? $this->organization->tenants()->orderBy('name')->get()
            : Tenant::whereKey($this->tenant_id)->get();
    }

    public function isOperator(): bool
    {
        return in_array($this->role, ['operator', 'admin'], true);
    }

    /** admin = operador RedFoxCode; app = qualquer usuário de tenant. */
    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'admin' ? $this->isOperator() : true;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            // AUD-022: segredo TOTP e códigos de recuperação SEMPRE cifrados em repouso.
            'app_authentication_secret' => 'encrypted',
            'app_authentication_recovery_codes' => 'encrypted:array',
            // S2: preferências de aviso por e-mail (opt-out; ausente = ligado).
            'notify_prefs' => 'array',
        ];
    }

    // ── AUD-022: MFA (TOTP) do Filament — App authenticator ──────────────────
    // O segredo só existe depois que o operador configura o MFA no próprio perfil.
    // Sem segredo, AppAuthentication::isEnabled() retorna false e o login segue
    // normal só com senha (MFA é opcional, não forçado).

    public function getAppAuthenticationSecret(): ?string
    {
        return $this->app_authentication_secret;
    }

    public function saveAppAuthenticationSecret(?string $secret): void
    {
        $this->app_authentication_secret = $secret;
        $this->save();
    }

    public function getAppAuthenticationHolderName(): string
    {
        return $this->email;
    }

    /**
     * @return ?array<string>
     */
    public function getAppAuthenticationRecoveryCodes(): ?array
    {
        return $this->app_authentication_recovery_codes;
    }

    /**
     * @param  ?array<string>  $codes
     */
    public function saveAppAuthenticationRecoveryCodes(?array $codes): void
    {
        $this->app_authentication_recovery_codes = $codes;
        $this->save();
    }
}
