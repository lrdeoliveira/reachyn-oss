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

        // Policies com o CAST DIRETO (sem NULLIF) estouram 22P02 quando app.current_tenant é ''
        // — e ele É '' pra operador/job/CLI. Foi assim que /api/characters ficou 500 em produção
        // por semanas (profiles/characters nasceram depois do fix 06_18 e recopiaram o cast).
        // Falha o deploy: é regressão, não estilo. Migrations novas devem usar App\Support\TenantRls.
        $quebradas = DB::select(
            "SELECT tablename FROM pg_policies
             WHERE policyname = 'tenant_isolation' AND qual NOT LIKE '%NULLIF%'
             ORDER BY tablename"
        );
        if ($quebradas !== []) {
            $this->error(sprintf(
                'POLICY QUEBRADA (cast direto -> 22P02 pra operador/job) em: %s. '
                .'Use App\Support\TenantRls::protect() em vez de copiar a condição à mão.',
                implode(', ', array_column($quebradas, 'tablename')),
            ));

            return self::FAILURE;
        }

        // Tabelas com tenant_id que NÃO têm policy: o RLS não as cobre (só o TenantScope protege).
        // As exceções abaixo estão fora DE PROPÓSITO — ter tenant_id não as torna por-marca, e
        // ligar RLS por tenant_id nelas quebraria o app (ver a migration 2026_07_14_130000):
        //   users         → pertence à ORG; activeTenantId() pode resolver outra marca da mesma org
        //                   (X-Tenant-Id/__tenant) e o usuário logado sumiria → Auth::user() null.
        //   subscriptions → tenant_id é legado (migrou p/ organization_id).
        // Qualquer OUTRA tabela aparecendo aqui é policy esquecida: use TenantRls::protect().
        $excecoes = ['users', 'subscriptions'];
        $semPolicy = DB::select(
            "SELECT c.table_name FROM information_schema.columns c
             WHERE c.column_name = 'tenant_id' AND c.table_schema = 'public'
               AND c.table_name NOT IN ('".implode("','", $excecoes)."')
               AND NOT EXISTS (SELECT 1 FROM pg_policies p
                               WHERE p.tablename = c.table_name AND p.policyname = 'tenant_isolation')
             ORDER BY c.table_name"
        );

        $comPolicy = DB::selectOne(
            "SELECT count(*) AS n FROM pg_class c
             JOIN pg_policies p ON p.tablename = c.relname AND p.policyname = 'tenant_isolation'
             WHERE c.relrowsecurity AND c.relforcerowsecurity"
        );

        $this->info(sprintf(
            "OK: app conecta como '%s' (NOSUPERUSER/NOBYPASSRLS). Policies íntegras; %d tabelas com RLS FORCE.",
            $role->u,
            (int) $comPolicy->n,
        ));

        if ($semPolicy !== []) {
            $this->warn(sprintf(
                'Aviso: tabela com tenant_id SEM policy (só o TenantScope isola): %s. '
                .'Se for por-marca, use App\Support\TenantRls::protect() numa migration.',
                implode(', ', array_column($semPolicy, 'table_name')),
            ));
        }

        return self::SUCCESS;
    }
}
