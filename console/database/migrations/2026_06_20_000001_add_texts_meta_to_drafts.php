<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fixa (persiste) os metadados do texto gerado por plataforma: rank vs resumo, grounding
// vs fontes e flags. Antes eram transitórios (só retornados ao front). O rank prioriza os
// textos quando entram como contexto dos prompts de mídia.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drafts', function (Blueprint $table) {
            $table->json('texts_meta')->nullable()->after('texts'); // {plataforma: {rank, grounding, flags}}
        });
    }

    public function down(): void
    {
        Schema::table('drafts', function (Blueprint $table) {
            $table->dropColumn('texts_meta');
        });
    }
};
