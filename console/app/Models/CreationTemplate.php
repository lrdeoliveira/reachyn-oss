<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Template de criação (aba Rápido / Quick Start). Tabela MISTA: `tenant_id` NULL = template GLOBAL
 * Reachyn (seed), preenchido = receita da própria org.
 *
 * NÃO usa BelongsToTenant DE PROPÓSITO: o TenantScope faz where('tenant_id', $tid) estrito, que
 * ESCONDERIA os templates globais do cliente. Use scopeVisibleTo() para listar globais + os da
 * marca ativa. O RLS do banco (migration) respalda a mesma regra (globais legíveis, escrita só na
 * própria marca).
 */
class CreationTemplate extends Model
{
    /** Categorias do catálogo (usadas no filtro da UI e na validação). */
    public const CATEGORIES = ['ugc_produto', 'historia', 'reels', 'comic', 'faceless', 'outro'];

    /** Destino do apply — cria um projeto de animação ou um rascunho de conteúdo. */
    public const TARGETS = ['animation', 'draft'];

    protected $fillable = [
        'tenant_id', 'slug', 'title', 'description', 'category', 'preview_url', 'payload', 'sort', 'active',
    ];

    protected $casts = [
        'payload' => 'array',
        'active' => 'boolean',
        'sort' => 'integer',
    ];

    /** Globais (tenant_id NULL) + os da marca ativa. Espelha o USING da policy RLS. */
    public function scopeVisibleTo(Builder $q, ?int $tenantId): Builder
    {
        return $q->where(function (Builder $w) use ($tenantId) {
            $w->whereNull('tenant_id');
            if ($tenantId !== null) {
                $w->orWhere('tenant_id', $tenantId);
            }
        });
    }
}
