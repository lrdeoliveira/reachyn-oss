<?php

namespace App\Models\Concerns;

use App\Models\Tenant;
use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AUD-020 — Trait para models com coluna `tenant_id`.
 *
 * Adiciona, como DEFESA EM PROFUNDIDADE (não como controle primário):
 *   1. Um global scope ({@see TenantScope}) que filtra por tenant_id do cliente
 *      autenticado, com bypass para operador/CLI/job/webhook (fail-open).
 *   2. Um hook `creating` que preenche tenant_id automaticamente a partir do
 *      cliente autenticado SE o atributo ainda estiver vazio — sem nunca
 *      sobrescrever um tenant_id já definido explicitamente (ex.: formulário do
 *      painel admin, em que o operador escolhe o tenant via Select).
 *
 * IMPORTANTE: os controllers continuam fazendo `where('tenant_id')` / setando
 * tenant_id manualmente. Esta trait NÃO substitui esse filtro — é a 2ª barreira.
 *
 * NÃO aplicar em User nem Tenant.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model): void {
            // Só auto-preenche se ninguém setou explicitamente (admin Select, controllers).
            if (! empty($model->tenant_id)) {
                return;
            }

            $tenantId = TenantScope::resolveClientTenantId();
            if ($tenantId !== null) {
                $model->tenant_id = $tenantId;
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
