<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// AUD-022: colunas de MFA (TOTP) do painel operador.
// IMPORTANTE: esta migration PRECISA ser rodada (php artisan migrate) para o MFA
// funcionar. Os valores são armazenados CIFRADOS pelo cast 'encrypted' no model
// User, por isso as colunas são TEXT (o ciphertext é maior que o segredo). O
// operador ativa o MFA no próprio perfil dentro do painel /admin — nada é forçado.
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('app_authentication_secret')->nullable()->after('remember_token');
            $table->text('app_authentication_recovery_codes')->nullable()->after('app_authentication_secret');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['app_authentication_secret', 'app_authentication_recovery_codes']);
        });
    }
};
