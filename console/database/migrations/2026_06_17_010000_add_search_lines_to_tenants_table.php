<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Linhas de pesquisa (principal/fallback) por função: normal/deep/scraper.
            // NÃO é segredo (só nomes de provedor) → json claro, sem cifra. Vazio = defaults.
            $table->json('search_lines')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('search_lines');
        });
    }
};
