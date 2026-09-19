<?php

namespace App\Filament\App\Pages\Auth;

use App\Models\Organization;
use App\Models\Tenant;
use Filament\Auth\Pages\Register as BaseRegister;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/**
 * Cadastro self-service (freemium) do Reachyn. Cada conta nova nasce com um TENANT próprio
 * em período de TRIAL (sem cartão): pode pesquisar/resumir; gerar/publicar exige assinar.
 * A verificação de e-mail é obrigatória (User implements MustVerifyEmail + panel emailVerification()).
 */
class Register extends BaseRegister
{
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('brand')
                    ->label('Nome da sua marca ou projeto')
                    ->required()
                    ->maxLength(120),
                $this->getNameFormComponent(),
                $this->getEmailFormComponent(),
                // Baseline #9: senha forte (mín. 10, maiúsc/minúsc, número, não-vazada/HIBP).
                $this->getPasswordFormComponent()
                    ->rule(PasswordRule::min(10)->mixedCase()->numbers()->uncompromised()),
                $this->getPasswordConfirmationFormComponent(),
            ]);
    }

    /**
     * Cria ORGANIZAÇÃO (billing/trial) + primeira MARCA (Tenant) + usuário dono numa única
     * transação (Fase 2). Filament dispara o evento Registered no retorno → envia o e-mail de
     * verificação automaticamente. A org nasce em TRIAL (sem cartão); a marca guarda o conteúdo.
     */
    protected function handleRegistration(array $data): Model
    {
        // Baseline: rate limit anti-flood de cadastro por IP (5 contas / 10 min).
        $key = 'signup:'.request()->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'data.email' => 'Muitas tentativas de cadastro. Tente novamente em alguns minutos.',
            ]);
        }
        RateLimiter::hit($key, 600);

        return DB::transaction(function () use ($data) {
            $name = $data['brand'] ?? $data['name'];
            $slug = $this->uniqueSlug($name);

            // ORGANIZAÇÃO (billing): trial, sem plano pago. Dona da assinatura + créditos.
            $org = new Organization;
            $org->slug = $slug.'-org';
            $org->name = $name;
            $org->plan = 'starter';
            $org->billing_status = 'none';
            $org->trial_ends_at = now()->addDays((int) config('services.signup.trial_days', 7));
            $org->save();

            // Primeira MARCA (conteúdo) da org.
            $tenant = new Tenant;
            $tenant->organization_id = $org->id;
            $tenant->slug = $slug;
            $tenant->name = $name;
            $tenant->save();

            return $this->getUserModel()::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'organization_id' => $org->id,
                'tenant_id' => $tenant->id,
                'role' => 'owner',
            ]);
        });
    }

    /** Slug único e seguro (allowlist a-z0-9-); sufixo numérico em caso de colisão. */
    private function uniqueSlug(string $base): string
    {
        $slug = Str::slug($base) ?: 'conta';
        $candidate = $slug;
        $i = 1;
        while (Tenant::where('slug', $candidate)->exists()) {
            $candidate = $slug.'-'.(++$i);
        }

        return $candidate;
    }
}
