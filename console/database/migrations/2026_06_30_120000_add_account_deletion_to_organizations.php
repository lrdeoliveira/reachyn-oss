<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Exclusão de conta (LGPD art. 18, VI) — Reachyn.
 * Agendamento na ORGANIZATION (a entidade-conta: billing + users + tenants) +
 * tabela de auditoria que SOBREVIVE ao purge (sem FK), prova de cumprimento (art. 6, X).
 * Espelha o padrão do Nexusyn (console/database/migrations/..._add_account_deletion.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->timestamp('deletion_requested_at')->nullable()->after('plan');
            $table->timestamp('deletion_scheduled_for')->nullable()->after('deletion_requested_at');
            $table->string('deletion_token')->nullable()->after('deletion_scheduled_for'); // hash SHA256
            $table->unsignedBigInteger('deletion_requested_by')->nullable()->after('deletion_token');
        });

        Schema::create('account_deletion_audits', function (Blueprint $table) {
            $table->id();
            // SEM FK para organizations: a auditoria sobrevive ao purge (accountability LGPD).
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->string('organization_slug')->nullable();
            $table->unsignedBigInteger('requester_user_id')->nullable();
            $table->string('requester_email_hash')->nullable(); // sha256 — sem PII em claro
            $table->string('status')->default('requested');      // requested|canceled|executed
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->boolean('stripe_canceled')->default(false);
            $table->string('request_ip')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn([
                'deletion_requested_at',
                'deletion_scheduled_for',
                'deletion_token',
                'deletion_requested_by',
            ]);
        });
        Schema::dropIfExists('account_deletion_audits');
    }
};
