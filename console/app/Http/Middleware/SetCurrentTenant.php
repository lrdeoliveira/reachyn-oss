<?php

namespace App\Http\Middleware;

use App\Scopes\TenantScope;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Define `app.current_tenant` na conexão Postgres, alimentando as políticas RLS
 * (defense-in-depth). Usa a MESMA regra do TenantScope: cliente autenticado → seu tenant_id;
 * operador / sem auth / job → vazio (bypass, fail-open).
 *
 * IMPORTANTE: este middleware roda ANTES do auth:sanctum (middleware de grupo executa antes do
 * de rota), então aqui o usuário ainda NÃO está resolvido → seta '' (reset, evita vazar tenant
 * de um request anterior na conexão reusada). O valor REAL é setado depois pelo listener do
 * evento Authenticated (ver AppServiceProvider), que chama sync() já com o usuário resolvido.
 *
 * No-op fora do Postgres (sqlite em dev). FAIL-OPEN: se a leitura falhar, libera (não quebra) —
 * o isolamento da aplicação (TenantScope) continua valendo.
 */
class SetCurrentTenant
{
    /** Sincroniza app.current_tenant com o tenant do usuário atual (ou '' = bypass). */
    public static function sync(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        $tid = TenantScope::resolveClientTenantId();
        $value = ($tid !== null && $tid !== '') ? (string) (int) $tid : '';
        try {
            // set_config(name, value, is_local=false): vale para a sessão da conexão.
            DB::statement("select set_config('app.current_tenant', ?, false)", [$value]);
        } catch (\Throwable $e) {
            // fail-open: não derruba o request; TenantScope ainda isola na aplicação.
        }
    }

    public function handle(Request $request, Closure $next)
    {
        self::sync(); // reset no início do request (sem user ainda → '')

        return $next($request);
    }
}
