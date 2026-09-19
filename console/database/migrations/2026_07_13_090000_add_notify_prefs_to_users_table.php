<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// S2 (PLANO-UX-INTERFACE): preferências de aviso por e-mail do usuário.
// JSON { generation_ready: bool, approvals_digest: bool, credits_low: bool, trial_ending: bool }.
// Ausente/null = todos LIGADOS (opt-out explícito).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('notify_prefs')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notify_prefs');
        });
    }
};
