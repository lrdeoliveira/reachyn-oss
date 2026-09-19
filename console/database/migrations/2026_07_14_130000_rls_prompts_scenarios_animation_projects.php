<?php

use App\Support\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * RBK-001 — fecha o buraco do RLS: 13 tabelas têm `tenant_id`, mas só 8 tinham policy. Estas 3 são
 * por-marca de verdade e estavam sem cobertura — só o TenantScope (aplicação) as isolava, então um
 * bug nele viraria vazamento entre clientes. Agora o banco também barra.
 *
 *  - prompts    → model Prompt usa BelongsToTenant
 *  - scenarios  → model Scenario usa BelongsToTenant
 *  - animation_projects → não usa o trait, mas o AnimationController filtra por tenant_id à mão
 *    (`where('tenant_id', $this->tenant($r)->id)`) — exatamente o filtro que o RLS respalda.
 *
 * DE PROPÓSITO FORA (não são por-marca; ligar RLS por tenant_id nelas QUEBRARIA o app):
 *  - users: o usuário pertence a uma ORG, não a uma marca. TenantScope::activeTenantId() pode
 *    resolver uma marca DIFERENTE de users.tenant_id (header X-Tenant-Id / __tenant, qualquer marca
 *    da mesma org) — com RLS por tenant_id o próprio usuário logado sumiria ao trocar de marca e
 *    Auth::user() viraria null. O isolamento correto aqui seria por organization_id.
 *  - subscriptions: tenant_id é LEGADO (2026_06_27_create_organizations migrou para
 *    organization_id); o Cashier fatura a Organization e ninguém filtra por tenant_id.
 *
 * Idempotente (TenantRls::protect = DROP + CREATE). Sem policy anterior aqui: são novas.
 */
return new class extends Migration
{
    private array $tables = ['prompts', 'scenarios', 'animation_projects'];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach ($this->tables as $t) {
            if (! DB::selectOne('SELECT to_regclass(?) AS t', ["public.{$t}"])->t) {
                continue;
            }
            TenantRls::protect($t);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach ($this->tables as $t) {
            if (! DB::selectOne('SELECT to_regclass(?) AS t', ["public.{$t}"])->t) {
                continue;
            }
            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$t}");
            DB::statement("ALTER TABLE {$t} NO FORCE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$t} DISABLE ROW LEVEL SECURITY");
        }
    }
};
