<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ORGANIZAÇÃO — dona do saldo de créditos e dos usuários. Contém várias marcas (Tenant).
 *
 * @property \Illuminate\Support\Carbon|null $trial_ends_at
 */
class Organization extends Model
{
    use HasFactory;

    protected $fillable = ['slug', 'name', 'plan', 'billing_status', 'credit_balance'];

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'credit_balance' => 'integer',
        ];
    }

    /** Org interna (dogfooding) — não é debitada por créditos. */
    public function isExempt(): bool
    {
        return ($this->billing_status ?? null) === 'exempt';
    }

    /** Tem geração: isenta ou tem um plano configurado localmente (BYOK / cota). */
    public function hasGenerationPlan(): bool
    {
        if ($this->isExempt()) {
            return true;
        }

        return filled($this->plan);
    }

    /** Publicação no OSS não é um produto pago à parte — segue o mesmo critério da geração. */
    public function hasPublishing(): bool
    {
        return $this->hasGenerationPlan();
    }

    public function hasActivePlan(): bool
    {
        return $this->hasGenerationPlan();
    }

    public function inTrial(): bool
    {
        return $this->trial_ends_at !== null && $this->trial_ends_at->isFuture();
    }

    public function canResearch(): bool
    {
        return $this->hasActivePlan() || $this->inTrial();
    }

    public const PLAN_LIMITS = [
        'starter' => ['image' => 300,     'video' => 0,       'short' => 0,       'veo' => 0,       'story' => 999999, 'audio' => 999999, 'text' => 999999, 'effect' => 999999, 'premium' => false, 'networks' => 1],
        'pro' => ['image' => 1000,    'video' => 33,      'short' => 4,       'veo' => 33,      'story' => 999999, 'audio' => 999999, 'text' => 999999, 'effect' => 999999, 'premium' => false, 'networks' => 2],
        'studio' => ['image' => 2500,    'video' => 83,      'short' => 11,      'veo' => 83,      'story' => 999999, 'audio' => 999999, 'text' => 999999, 'effect' => 999999, 'premium' => false, 'networks' => 4],
        'enterprise' => ['image' => 6000,    'video' => 200,     'short' => 27,      'veo' => 50,      'story' => 999999, 'audio' => 999999, 'text' => 999999, 'effect' => 999999, 'premium' => true,  'networks' => 8],
        'unlimited' => ['image' => 1000000, 'video' => 1000000, 'short' => 1000000, 'veo' => 1000000, 'story' => 1000000, 'audio' => 1000000, 'text' => 1000000, 'effect' => 1000000, 'premium' => true,  'networks' => 1000000],
    ];

    public function limits(): array
    {
        return self::PLAN_LIMITS[$this->plan] ?? self::PLAN_LIMITS['starter'];
    }

    public const PLAN_CREDITS = [
        'starter' => 600,
        'pro' => 2000,
        'studio' => 5000,
        'enterprise' => 12000,
        'unlimited' => 0,
    ];

    public function monthlyCredits(): int
    {
        return self::PLAN_CREDITS[$this->plan] ?? 0;
    }

    /** @return HasMany<Tenant, $this> */
    public function tenants(): HasMany
    {
        return $this->hasMany(Tenant::class);
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return HasMany<CreditTransaction, $this> */
    public function creditTransactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class);
    }
}
