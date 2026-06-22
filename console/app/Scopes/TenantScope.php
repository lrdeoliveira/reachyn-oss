<?php

namespace App\Scopes;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * AUD-020 — Defesa em profundidade do isolamento multi-tenant.
 *
 * Camadas de isolamento: (1) `where('tenant_id')` manual nos controllers; (2) este global
 * scope (app-level), rede de segurança caso uma query esqueça o filtro; (3) RLS no Postgres
 * (enable_rls_tenant_tables), alimentado por app.current_tenant (SetCurrentTenant + listener
 * Authenticated) usando a MESMA regra de resolução abaixo. Este scope é a 2ª das 3 barreiras.
 *
 * FILOSOFIA: fail-open. Esta é uma rede de segurança, não o controle primário.
 * Em qualquer contexto AMBÍGUO (sem usuário, operador, CLI, job, webhook) o scope
 * NÃO filtra — para JAMAIS quebrar o painel admin cross-tenant, jobs de fila,
 * seeders ou webhooks de billing. O filtro só é aplicado quando temos CERTEZA de
 * que há um usuário-cliente autenticado com tenant_id resolvido.
 *
 * Quando o scope FILTRA (cliente autenticado com tenant):
 *   WHERE <tabela>.tenant_id = <tenant do usuário>
 *
 * Quando o scope NÃO filtra (bypass):
 *   - Não há usuário autenticado (auth()->check() === false):
 *       jobs de fila, comandos de console (CLI), seeders, migrations.
 *   - Usuário é operador/admin (isOperator()): painel /admin precisa ver TODOS
 *     os tenants (DraftResource, ConnectionResource, etc. listam tenant.name).
 *   - Usuário sem tenant_id (operador sem tenant, estado inesperado): fail-open.
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenantId = self::resolveClientTenantId();

        if ($tenantId === null) {
            // Bypass: contexto sem cliente autenticado, ou operador, ou sem tenant.
            return;
        }

        $builder->where($model->getTable().'.tenant_id', $tenantId);
    }

    /**
     * Retorna o tenant_id a aplicar OU null para bypass (fail-open).
     *
     * Só devolve um id quando há um usuário-CLIENTE autenticado, com tenant_id.
     * Operador/admin, ausência de usuário, ou ausência de tenant → null (bypass).
     */
    public static function resolveClientTenantId(): int|string|null
    {
        // Sem sessão/token (jobs, CLI, seeders, webhooks): nunca filtra.
        if (! auth()->check()) {
            return null;
        }

        $user = auth()->user();

        // Só sabemos isolar o que for nosso User com role/tenant conhecidos.
        if (! $user instanceof User) {
            return null;
        }

        // Operador/admin (painel /admin) vê cross-tenant: bypass.
        if (method_exists($user, 'isOperator') && $user->isOperator()) {
            return null;
        }

        // Cliente sem tenant resolvido: fail-open (não quebra).
        return $user->tenant_id ?: null;
    }
}
