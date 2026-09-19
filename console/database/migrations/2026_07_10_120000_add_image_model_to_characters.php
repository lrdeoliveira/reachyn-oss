<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Modelo de imagem ESCOLHIDO pro personagem (slug de gen_models, ex img-ultra): o "Regerar base"
// passa a usar o modelo salvo do personagem em vez do estado global do editor (pedido Luciano
// 2026-07-10 — regenerar mantinha o estilo mas caía no modelo mais simples).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->string('image_model', 60)->nullable()->after('style');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn('image_model');
        });
    }
};
