<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Sistema de CRÉDITOS (saldo único, rollover total — nada expira).
// - tenants.credit_balance: saldo atual denormalizado (leitura rápida; fonte da verdade
//   reconciliável pelo ledger).
// - credit_transactions: ledger APPEND-ONLY (auditoria/LGPD): toda concessão e débito,
//   com balance_after, idempotency_key (dedupe de webhook/geração) e referência.
// Concessão por assinatura (F2) + top-up (F3) creditam; geração (F4) debita gen_models.cost_credits.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->bigInteger('credit_balance')->default(0)->after('billing_status');
        });

        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('delta');          // + concessão / - débito
            $table->bigInteger('balance_after');  // snapshot do saldo após a transação
            $table->string('type');               // grant_subscription | grant_topup | grant_signup
            // | debit_generation | refund_generation | adjustment
            $table->string('reference_type')->nullable(); // gen_model | stripe_invoice | stripe_checkout | draft
            $table->string('reference_id')->nullable();
            $table->string('idempotency_key')->nullable()->unique(); // dedupe (webhook/geração)
            $table->jsonb('meta')->default('{}');
            $table->timestamp('created_at')->useCurrent(); // append-only: sem updated_at

            $table->index(['tenant_id', 'created_at']); // extrato
        });

        // Defense-in-depth: RLS no ledger (Postgres), igual às demais tabelas com tenant_id.
        // Guard de driver — em dev (sqlite) não se aplica.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE credit_transactions ENABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE credit_transactions FORCE ROW LEVEL SECURITY');
            DB::statement(<<<'SQL'
                CREATE POLICY tenant_isolation ON credit_transactions
                USING (
                    current_setting('app.current_tenant', true) IS NULL
                    OR current_setting('app.current_tenant', true) = ''
                    OR tenant_id = NULLIF(current_setting('app.current_tenant', true), '')::bigint
                )
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('credit_balance');
        });
    }
};
