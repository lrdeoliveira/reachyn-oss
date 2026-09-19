<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * MARCA / unidade dentro de uma ORGANIZAÇÃO (Fase 2). O Tenant guarda CONTEÚDO (perfis, conexões,
 * drafts, linhas de pesquisa, voz, idioma) e tem o SEU próprio escopo de dados (RLS). O BILLING
 * (plano, assinatura, créditos) vive na Organization e é COMPARTILHADO entre as marcas da org —
 * o Tenant apenas delega (accessors + métodos abaixo) para não quebrar os ~dezenas de pontos que
 * liam `$tenant->plan`, `$tenant->credit_balance`, `$tenant->isExempt()`, `$tenant->limits()`.
 *
 * As colunas de billing antigas (plan/billing_status/credit_balance/trial_ends_at) continuam na
 * tabela tenants (vestigiais, p/ rollback) mas são SOMBREADAS por estes accessors → sempre leem da
 * org. NUNCA escreva billing no tenant; escreva na Organization.
 */
class Tenant extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id', 'slug', 'name', 'zernio_profile_id', 'voice_id',
        'search_keys', 'search_lines', 'content_lang',
        'brand_primary', 'brand_ink', 'brand_logo_url', 'brand_handle', 'visual_brief', 'visual_brief_at',
    ];

    /** Cor default da marca RedFoxCode/Reachyn (vermelho) quando o tenant não configurou a sua. */
    public const BRAND_PRIMARY_DEFAULT = '#E23744';

    public const BRAND_INK_DEFAULT = '#141414';

    /**
     * Brand kit VISUAL efetivo da marca (o que o compositor de posts consome). Cai em defaults
     * quando o tenant não configurou — nunca retorna vazio, então a composição sempre funciona.
     * Pareia com `brand_voice` (tom textual). O `initial` é derivado do nome.
     */
    public function brandKit(): array
    {
        $name = trim((string) $this->name) ?: 'Reachyn';

        return [
            'name' => $name,
            'handle' => ($h = trim((string) $this->brand_handle)) !== '' ? $h : null,
            'initial' => mb_strtoupper(mb_substr($name, 0, 1)),
            'logoUrl' => ($l = trim((string) $this->brand_logo_url)) !== '' ? $l : null,
            'primary' => ($p = trim((string) $this->brand_primary)) !== '' ? $p : self::BRAND_PRIMARY_DEFAULT,
            'ink' => ($i = trim((string) $this->brand_ink)) !== '' ? $i : self::BRAND_INK_DEFAULT,
        ];
    }

    protected function casts(): array
    {
        return [
            'search_keys' => 'encrypted:array', // BYOK: chaves de pesquisa do cliente, cifradas
            'search_lines' => 'array',          // principal/fallback por função — só nomes, NÃO é segredo
            'visual_brief_at' => 'datetime',    // quando as referências visuais da marca foram lidas
        ];
    }

    // ── Organização (billing compartilhado) ──────────────────────────────────

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    // Accessors de DELEGAÇÃO: sombreiam as colunas vestigiais e sempre leem da org. Mantêm
    // compatível todo código que faz $tenant->plan / ->credit_balance / ->billing_status / ->trial_ends_at.
    public function getPlanAttribute(): string
    {
        return $this->organization?->plan ?? 'starter';
    }

    public function getBillingStatusAttribute(): ?string
    {
        return $this->organization?->billing_status;
    }

    public function getCreditBalanceAttribute(): int
    {
        return (int) ($this->organization?->credit_balance ?? 0);
    }

    public function getTrialEndsAtAttribute()
    {
        return $this->organization?->trial_ends_at;
    }

    // Métodos de billing — delegam para a org (fonte única). Tenant sem org → fechado por padrão.
    public function isExempt(): bool
    {
        return (bool) $this->organization?->isExempt();
    }

    public function hasActivePlan(): bool
    {
        return (bool) $this->organization?->hasActivePlan();
    }

    public function hasGenerationPlan(): bool
    {
        return (bool) $this->organization?->hasGenerationPlan();
    }

    public function hasPublishing(): bool
    {
        return (bool) $this->organization?->hasPublishing();
    }

    public function inTrial(): bool
    {
        return (bool) $this->organization?->inTrial();
    }

    public function canResearch(): bool
    {
        return (bool) $this->organization?->canResearch();
    }

    public function monthlyCredits(): int
    {
        return (int) $this->organization?->monthlyCredits();
    }

    public function limits(): array
    {
        return $this->organization?->limits() ?? Organization::PLAN_LIMITS['starter'];
    }

    // ── Conteúdo / pesquisa (próprio da marca) ───────────────────────────────

    /** Defaults recomendados de principal/fallback por função de pesquisa (quando o tenant não setou). */
    public const SEARCH_LINES_DEFAULT = [
        'normal' => ['primary' => 'tavily', 'fallback' => 'brave'],
        'deep' => ['primary' => 'jina',   'fallback' => 'tavily'],
        'scraper' => ['primary' => 'scrapecreators', 'fallback' => ''],
    ];

    /** Provedores válidos por função (allowlist de validação). */
    public const SEARCH_PROVIDERS = [
        'normal' => ['tavily', 'brave', 'jina'],
        'deep' => ['jina', 'tavily'],
        'scraper' => ['scrapecreators'],
    ];

    /** Linhas efetivas: o que o tenant salvou OU os defaults recomendados (merge por função). */
    public function searchLines(): array
    {
        $saved = (array) ($this->search_lines ?? []);
        $lines = [];
        foreach (self::SEARCH_LINES_DEFAULT as $fn => $def) {
            $cur = (array) ($saved[$fn] ?? []);
            $lines[$fn] = [
                'primary' => (string) ($cur['primary'] ?? $def['primary']),
                'fallback' => (string) ($cur['fallback'] ?? $def['fallback']),
            ];
        }

        return $lines;
    }

    // ── Relações ─────────────────────────────────────────────────────────────

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return HasMany<Profile, $this> */
    public function profiles(): HasMany
    {
        return $this->hasMany(Profile::class);
    }

    /** @return HasMany<Connection, $this> */
    public function connections(): HasMany
    {
        return $this->hasMany(Connection::class);
    }

    /** @return HasMany<Draft, $this> */
    public function drafts(): HasMany
    {
        return $this->hasMany(Draft::class);
    }

    /** @return HasMany<Approval, $this> */
    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class);
    }

    /** @return HasMany<Usage, $this> */
    public function usages(): HasMany
    {
        return $this->hasMany(Usage::class);
    }

    /** Débitos de geração desta marca (extrato por marca; o saldo é da org).
     *
     * @return HasMany<CreditTransaction, $this>
     */
    public function creditTransactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class);
    }
}
