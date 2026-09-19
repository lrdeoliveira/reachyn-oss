<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * RBK-001 — versiona o role de aplicação `reachyn_app` (LOGIN, NOSUPERUSER, NOBYPASSRLS) do qual
 * o RLS multi-tenant depende. Antes, a criação era manual/não-versionada: um re-deploy conectando
 * como o owner (superuser, que tem BYPASSRLS) DESLIGARIA o RLS silenciosamente, deixando só o
 * TenantScope (fail-open) como barreira. Esta migração garante os atributos seguros do role.
 *
 * Idempotente. Roda como superuser via scripts/migrate-prod.sh. Se rodada por um role sem
 * privilégio de gerenciar roles (ex.: o próprio reachyn_app), faz no-op (o role já deve existir).
 * Cria o role apenas se ele não existir, usando DB_APP_PASSWORD (ou DB_PASSWORD como fallback).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return; // sqlite (dev) não tem roles/RLS
        }

        // Sem privilégio de gerenciar roles → no-op (não trava o migrate; o role já existe em prod).
        $canManageRoles = (bool) DB::selectOne(
            'SELECT 1 FROM pg_roles WHERE rolname = current_user AND (rolsuper OR rolcreaterole)'
        );
        if (! $canManageRoles) {
            return;
        }

        $exists = (bool) DB::selectOne("SELECT 1 FROM pg_roles WHERE rolname = 'reachyn_app'");
        if (! $exists) {
            $pw = env('DB_APP_PASSWORD') ?: env('DB_PASSWORD');
            if (! $pw) {
                throw new RuntimeException(
                    'reachyn_app não existe e DB_APP_PASSWORD/DB_PASSWORD não definidos — '.
                    'defina a senha do role de aplicação antes de migrar.'
                );
            }
            DB::statement('CREATE ROLE reachyn_app WITH LOGIN PASSWORD '.DB::getPdo()->quote($pw));
        }

        // Coração do RBK-001: SEMPRE força os atributos seguros (idempotente).
        DB::statement('ALTER ROLE reachyn_app WITH LOGIN NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE');

        // Acesso do role às tabelas/sequences atuais e futuras (reforça a migração de RLS).
        DB::statement('GRANT USAGE ON SCHEMA public TO reachyn_app');
        DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO reachyn_app');
        DB::statement('GRANT USAGE, SELECT, UPDATE ON ALL SEQUENCES IN SCHEMA public TO reachyn_app');
        DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO reachyn_app');
        DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT, UPDATE ON SEQUENCES TO reachyn_app');
    }

    public function down(): void
    {
        // Não dropa o role: a aplicação conecta com ele em runtime.
    }
};
