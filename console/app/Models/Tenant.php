<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    protected $fillable = [
        'slug', 'name', 'plan', 'zernio_profile_id', 'billing_status', 'voice_id', 'search_keys', 'search_lines',
    ];

    protected function casts(): array
    {
        return [
            'search_keys' => 'encrypted:array', // BYOK: chaves de pesquisa do cliente, cifradas
            'search_lines' => 'array',          // principal/fallback por função — só nomes, NÃO é segredo
        ];
    }

    /** Defaults recomendados de principal/fallback por função de pesquisa (quando o tenant não setou). */
    public const SEARCH_LINES_DEFAULT = [
        'normal'  => ['primary' => 'search-primary', 'fallback' => 'search-alt'],
        'deep'    => ['primary' => 'reader',         'fallback' => 'search-primary'],
        'scraper' => ['primary' => 'scraper',        'fallback' => ''],
    ];

    /** Provedores válidos por função (allowlist de validação). */
    public const SEARCH_PROVIDERS = [
        'normal'  => ['search-primary', 'search-alt', 'reader'],
        'deep'    => ['reader', 'search-primary'],
        'scraper' => ['scraper'],
    ];

    /**
     * Slugs de TODOS os provedores de pesquisa (contrato de fio com o engine — NÃO renomear
     * sem alinhar o engine, que casa por essas strings). Fonte única para os loops de chaves.
     *
     * @var array<int,string>
     */
    public const SEARCH_KEY_SLUGS = ['search-primary', 'search-alt', 'reader', 'scraper'];

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

    /** Tetos por plano (operações/mês). Limites calibrados pelo CUSTO real dos provedores —
     *  ver docs/custos-e-planos.md. Buckets separados (1 unidade = 1 operação):
     *  image (~US$0,01) · video clipe (~US$0,30) · short sincronizado (~US$2,20) · premium-video (~US$1,20).
     *  `networks` = redes sociais inclusas (cada conta no Zernio custa ~US$6/mês → é TETO, não
     *  "ilimitado"; rede extra é cobrada à parte). Margem-alvo 1,3–1,5× no uso 100%. */
    public const PLAN_LIMITS = [
        'starter'   => ['image' => 300,     'video' => 15,      'short' => 4,       'premium-video' => 0,       'premium' => false, 'networks' => 2], // US$ 39/mês
        'pro'       => ['image' => 800,     'video' => 40,      'short' => 12,      'premium-video' => 0,       'premium' => false, 'networks' => 4], // US$ 99/mês
        'studio'    => ['image' => 2000,    'video' => 100,     'short' => 30,      'premium-video' => 15,      'premium' => true,  'networks' => 8], // US$ 269/mês
        // Plano INTERNO (uso próprio/dogfooding) — sem teto prático. Não comercializado; usar só em tenant exempt.
        'unlimited' => ['image' => 1000000, 'video' => 1000000, 'short' => 1000000, 'premium-video' => 1000000, 'premium' => true,  'networks' => 1000000],
    ];

    public function limits(): array
    {
        return self::PLAN_LIMITS[$this->plan] ?? self::PLAN_LIMITS['starter'];
    }

    public function users(): HasMany { return $this->hasMany(User::class); }
    public function connections(): HasMany { return $this->hasMany(Connection::class); }
    public function drafts(): HasMany { return $this->hasMany(Draft::class); }
    public function approvals(): HasMany { return $this->hasMany(Approval::class); }
    public function usages(): HasMany { return $this->hasMany(Usage::class); }
}
