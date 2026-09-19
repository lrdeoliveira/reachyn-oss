<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Defense-in-depth: ativa Row Level Security (Postgres) nas tabelas com tenant_id,
 * como SEGUNDA barreira além do TenantScope (aplicação). A política é FAIL-OPEN:
 * filtra por `app.current_tenant` quando o setting está definido (request de cliente,
 * via middleware SetCurrentTenant); libera quando vazio/nulo (operador, jobs, migrations,
 * superuser) — assim não quebra o que já funciona.
 *
 * IMPORTANTE: o RLS só vale para roles SEM superuser/bypassrls — o app deve conectar como
 * `reachyn_app`. Migrations rodam como o owner (superuser), que bypassa o RLS — por isso a
 * migração consegue alterar as tabelas normalmente.
 *
 * O role é criado/versionado pela migração POSTERIOR `2026_06_18_010000_ensure_reachyn_app_role`,
 * que refaz estes mesmos GRANTs. Em banco NOVO o role ainda não existe aqui (esta roda antes),
 * então os GRANTs abaixo são condicionais — sem isso o migrate abortava com
 * `SQLSTATE[42704] role "reachyn_app" does not exist` e o projeto não subia do zero.
 */
return new class extends Migration
{
    private array $tables = ['drafts', 'connections', 'usages', 'approvals', 'publications'];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return; // RLS é específico do Postgres; em dev (sqlite) não se aplica.
        }
        $cond = "current_setting('app.current_tenant', true) IS NULL "
              ."OR current_setting('app.current_tenant', true) = '' "
              ."OR tenant_id = current_setting('app.current_tenant', true)::bigint";

        foreach ($this->tables as $t) {
            DB::statement("ALTER TABLE {$t} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$t} FORCE ROW LEVEL SECURITY");
            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$t}");
            DB::statement("CREATE POLICY tenant_isolation ON {$t} USING ({$cond}) WITH CHECK ({$cond})");
        }

        // Garante que o role de aplicação (não-privilegiado) tenha acesso às tabelas/sequences.
        // Só se o role já existir: em banco novo quem o cria é ensure_reachyn_app_role (roda depois)
        // e ela repete estes GRANTs — pular aqui não deixa buraco.
        if (DB::selectOne("SELECT 1 FROM pg_roles WHERE rolname = 'reachyn_app'")) {
            DB::statement('GRANT USAGE ON SCHEMA public TO reachyn_app');
            DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO reachyn_app');
            DB::statement('GRANT USAGE, SELECT, UPDATE ON ALL SEQUENCES IN SCHEMA public TO reachyn_app');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach ($this->tables as $t) {
            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$t}");
            DB::statement("ALTER TABLE {$t} NO FORCE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$t} DISABLE ROW LEVEL SECURITY");
        }
    }
};
