<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// FASE O1 — camada de ORGANIZAÇÃO acima do Tenant. A Organização passa a ser a dona do BILLING
// (assinatura Cashier + créditos) e dos USUÁRIOS; o Tenant vira uma MARCA/unidade dentro da org
// (conexões/perfis/conteúdo próprios, billing compartilhado da org). Decisão Luciano 2026-06-27.
//
// Esta migração é ADITIVA + backfill: cria organizations, adiciona organization_id em
// tenants/users/subscriptions/credit_transactions, e migra cada tenant atual → 1 org-de-um
// (copiando o billing). As colunas de billing do tenant ficam (vestigiais) até a fase de limpeza —
// nada é removido aqui, então é 100% reversível.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            // Billing (movido do tenant): plano + status + saldo de créditos.
            $table->string('plan')->default('starter');
            $table->string('billing_status')->nullable();
            $table->bigInteger('credit_balance')->default(0);
            // Cashier customer columns (a Org vira o Billable).
            $table->string('stripe_id')->nullable()->index();
            $table->string('pm_type')->nullable();
            $table->string('pm_last_four', 4)->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamps();
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('tenant_id')->constrained()->nullOnDelete();
        });
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->unsignedBigInteger('organization_id')->nullable()->after('tenant_id')->index();
            // tenant_id vira vestigial: o billable agora é a Org. Cashier cria assinaturas SÓ com
            // organization_id (getForeignKey) → tenant_id precisa aceitar NULL p/ não violar NOT NULL.
            $table->unsignedBigInteger('tenant_id')->nullable()->change();
        });
        Schema::table('credit_transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('organization_id')->nullable()->after('tenant_id')->index();
        });

        // BACKFILL: cada tenant atual → 1 org-de-um (copia o billing; liga tenant/users/subs/ledger).
        foreach (DB::table('tenants')->get() as $t) {
            $orgId = DB::table('organizations')->insertGetId([
                'slug' => $t->slug.'-org',
                'name' => $t->name,
                'plan' => $t->plan ?? 'starter',
                'billing_status' => $t->billing_status,
                'credit_balance' => $t->credit_balance ?? 0,
                'stripe_id' => $t->stripe_id ?? null,
                'pm_type' => $t->pm_type ?? null,
                'pm_last_four' => $t->pm_last_four ?? null,
                'trial_ends_at' => $t->trial_ends_at ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('tenants')->where('id', $t->id)->update(['organization_id' => $orgId]);
            DB::table('users')->where('tenant_id', $t->id)->update(['organization_id' => $orgId]);
            DB::table('subscriptions')->where('tenant_id', $t->id)->update(['organization_id' => $orgId]);
            DB::table('credit_transactions')->where('tenant_id', $t->id)->update(['organization_id' => $orgId]);
        }
    }

    public function down(): void
    {
        // Dropar o índice ANTES da coluna (SQLite recusa coluna indexada; Postgres tolera, mas
        // sermos explícitos mantém o rollback portável entre os dois).
        Schema::table('credit_transactions', function (Blueprint $t) {
            $t->dropIndex('credit_transactions_organization_id_index');
            $t->dropColumn('organization_id');
        });
        Schema::table('subscriptions', function (Blueprint $t) {
            $t->dropIndex('subscriptions_organization_id_index');
            $t->dropColumn('organization_id');
        });
        Schema::table('users', function (Blueprint $t) {
            $t->dropConstrainedForeignId('organization_id');
        });
        Schema::table('tenants', function (Blueprint $t) {
            $t->dropConstrainedForeignId('organization_id');
        });
        Schema::dropIfExists('organizations');
    }
};
