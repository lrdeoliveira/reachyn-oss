<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Idioma padrão do CONTEÚDO da conta (#3): 'pt-BR' ou 'en-US'. Cada rede pode
            // sobrescrever no Studio (idioma por rede); este é só o default do seletor.
            $table->string('content_lang', 8)->default('pt-BR');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('content_lang');
        });
    }
};
