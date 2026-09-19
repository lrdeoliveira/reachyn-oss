<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * RBK-001 — fonte ÚNICA da política de RLS multi-tenant. Use em TODA migration que criar uma
 * tabela com `tenant_id`:
 *
 *     TenantRls::protect('minha_tabela');
 *
 * POR QUE ISTO EXISTE: a condição era copiada à mão em cada create_*_table. Quando o cast direto
 * se mostrou quebrado, o fix (2026_06_18) corrigiu só as 5 tabelas existentes — e as tabelas
 * criadas DEPOIS (profiles 06_26, characters 06_28) recopiaram a versão antiga do vizinho e
 * reintroduziram o bug, que só apareceu em produção meses depois (2026_07_14: 500 pro operador).
 * Condição copiada = bug copiado. Aqui ela vive num lugar só.
 *
 * O cast direto `current_setting('app.current_tenant', true)::bigint` estoura 22P02 quando o
 * setting é '' — e ele É '' em todo request de OPERADOR, job, CLI e webhook. O `OR ... = ''`
 * antes NÃO protege: um OR do Postgres não garante ordem de avaliação. Daí o NULLIF.
 *
 * FAIL-OPEN por design: setting vazio/nulo libera. É o bypass de que operador (painel /admin,
 * cross-tenant), jobs e CLI dependem — o TenantScope é quem filtra o cliente na aplicação, e o
 * RLS é a segunda barreira, que impede um bug do TenantScope de virar vazamento entre clientes.
 */
class TenantRls
{
    /** A condição canônica da policy. Não copie: chame. */
    public static function condition(): string
    {
        return "current_setting('app.current_tenant', true) IS NULL "
             ."OR current_setting('app.current_tenant', true) = '' "
             ."OR tenant_id = NULLIF(current_setting('app.current_tenant', true), '')::bigint";
    }

    /**
     * Liga o RLS na tabela e (re)cria a policy `tenant_isolation`. Idempotente.
     * No-op fora do Postgres (a suíte roda em sqlite, que não tem RLS).
     *
     * FORCE: sem ele o DONO da tabela ignora a policy — e em dev/CI o dono costuma ser quem migra.
     */
    public static function protect(string $table): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        $cond = self::condition();
        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
        DB::statement("CREATE POLICY tenant_isolation ON {$table} USING ({$cond}) WITH CHECK ({$cond})");
    }
}
