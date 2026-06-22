<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Corrige a política RLS (enable_rls_tenant_tables): o cast direto
 * `current_setting('app.current_tenant', true)::bigint` EXPLODE quando o setting é '' (string
 * vazia) — Postgres avalia o cast mesmo com o `OR ... = ''` antes dele (22P02: invalid input
 * syntax for type bigint: ""). Isso derrubava /api/usage, /api/connections e toda rota com tenant.
 *
 * Fix: NULLIF(setting, '') → '' vira NULL, e NULL::bigint = NULL (sem erro). A condição segue
 * FAIL-OPEN (libera quando vazio/nulo). Idempotente: prod já recebeu o mesmo SQL manualmente.
 */
return new class extends Migration
{
    private array $tables = ['drafts', 'connections', 'usages', 'approvals', 'publications'];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        $cond = "current_setting('app.current_tenant', true) IS NULL "
              . "OR current_setting('app.current_tenant', true) = '' "
              . "OR tenant_id = NULLIF(current_setting('app.current_tenant', true), '')::bigint";

        foreach ($this->tables as $t) {
            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$t}");
            DB::statement("CREATE POLICY tenant_isolation ON {$t} USING ({$cond}) WITH CHECK ({$cond})");
        }
    }

    public function down(): void
    {
        // Não reverte para o cast quebrado de propósito (o down original recria sem política).
    }
};
