<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Modelo de TEXTO escolhido pro personagem (slug kind=text, ex txt-topo) — par do image_model:
// a bíblia/lock do model sheet regenera com o modelo salvo na CRIAÇÃO; só muda em edição explícita.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->string('text_model', 60)->nullable()->after('image_model');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn('text_model');
        });
    }
};
