<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * RBK-001 — healthcheck de isolamento: falha (exit≠0) se a aplicação conectar como um role
 * com superuser/BYPASSRLS (o que desligaria o RLS multi-tenant). Rodar com o DB user de RUNTIME
 * (reachyn_app), NÃO com o superuser do migrate. Chamado no fim do scripts/migrate-prod.sh para
 * abortar o deploy se a config de DB ficou insegura.
 */
class VerifyDbIsolation extends Command
{
    protected $signature = 'reachyn:verify-db-isolation';

    protected $description = 'RBK-001: aborta se o app conectar como role com BYPASSRLS (RLS inerte).';

    public function handle(): int
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->info('Driver não-pgsql: RLS não se aplica.');

            return self::SUCCESS;
        }

        $role = DB::selectOne(
            'SELECT current_user AS u, rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user'
        );
        if (! $role) {
            $this->error('Não foi possível ler o role atual do banco.');

            return self::FAILURE;
        }

        if ($role->rolsuper || $role->rolbypassrls) {
            $this->error(sprintf(
                "INSEGURO: a aplicação conecta como '%s' (super=%s, bypassrls=%s) → o RLS é BYPASSADO. "
                .'Configure DB_USERNAME=reachyn_app no runtime.',
                $role->u,
                $role->rolsuper ? 'true' : 'false',
                $role->rolbypassrls ? 'true' : 'false',
            ));

            return self::FAILURE;
        }

        $forced = DB::selectOne(
            "SELECT count(*) AS n FROM pg_class
             WHERE relrowsecurity AND relforcerowsecurity
               AND relname IN ('drafts','connections','usages','approvals','publications')"
        );

        $this->info(sprintf(
            "OK: app conecta como '%s' (NOSUPERUSER/NOBYPASSRLS). Tabelas com RLS FORCE: %d/5.",
            $role->u,
            (int) $forced->n,
        ));

        return self::SUCCESS;
    }
}
