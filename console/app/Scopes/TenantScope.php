<?php

namespace App\Scopes;

use App\Models\Tenant;
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
 *       jobs de fila, comandos de console (CLI), seeders, migrations, webhooks
 *       (jobs e webhooks externos rodam sem sessão).
 *   - Usuário é operador/admin (isOperator()): painel /admin precisa ver TODOS
 *     os tenants (DraftResource, ConnectionResource, etc. listam tenant.name).
 *   - Usuário sem tenant_id (operador sem tenant, estado inesperado): fail-open.
 */
class TenantScope implements Scope
{
    /** Marca ativa por usuário, memoizada por request. Ver flushActiveTenantCache(). */
    private static array $activeTenantCache = [];

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

        // Cliente: a marca ATIVA (selecionada) — ou o tenant default. Fail-open se não resolver.
        return self::activeTenantId($user);
    }

    /**
     * Fase 2 — marca ATIVA do usuário (a que ele selecionou no seletor de marcas). O SPA manda o
     * header `X-Tenant-Id`; validamos que a marca pedida pertence à ORG do usuário (segurança:
     * ninguém troca pra marca de outra org). Sem header/ inválido → tenant default do usuário.
     * Memoizado por request (php-fpm reseta entre requests) — roda a validação 1× só.
     */
    public static function activeTenantId(?User $user = null): ?int
    {
        $user ??= auth()->user() instanceof User ? auth()->user() : null;
        if (! $user) {
            return null;
        }

        $uid = $user->getKey();
        if (array_key_exists($uid, self::$activeTenantCache)) {
            return self::$activeTenantCache[$uid];
        }

        $default = $user->tenant_id ? (int) $user->tenant_id : null;
        $resolved = $default;

        $req = function_exists('request') ? request() : null;
        $requested = $req ? ($req->header('X-Tenant-Id') ?: $req->input('__tenant')) : null;
        if ($requested && $user->organization_id) {
            // Validação: a marca pedida tem que ser da MESMA org do usuário (sem o global scope p/ não recursar).
            $ok = Tenant::withoutGlobalScope(self::class)
                ->where('organization_id', $user->organization_id)
                ->whereKey($requested)
                ->exists();
            if ($ok) {
                $resolved = (int) $requested;
            }
        }

        return self::$activeTenantCache[$uid] = $resolved;
    }

    /**
     * Zera a memoização da marca ativa. Chame ao trocar de usuário DENTRO do mesmo processo PHP.
     *
     * Em produção isso nunca é preciso: php-fpm derruba o estado a cada request, e job/CLI/webhook
     * saem antes do cache (não há auth). O cache era um `static` dentro do método — sem reset
     * possível, e por isso o TenantScope era intestável: dois testes seguidos reciclam o id 1
     * (RefreshDatabase) e o segundo usuário herdava a marca do primeiro. Extraído aqui também para
     * não virar bug de verdade se um dia o runtime for persistente (Octane).
     */
    public static function flushActiveTenantCache(): void
    {
        self::$activeTenantCache = [];
    }

    /** Modelo da marca ATIVA (sem o global scope, p/ não recursar). */
    public static function activeTenant(?User $user = null): ?Tenant
    {
        $id = self::activeTenantId($user);

        return $id ? Tenant::withoutGlobalScope(self::class)->find($id) : null;
    }
}
