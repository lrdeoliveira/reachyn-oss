<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🧠 Escolha explícita de MODELO no wizard (feedback Luciano 2026-07-12: o seletor de modelo
 * é controle de CUSTO e não podia sumir na unificação). Slugs do catálogo gen_models;
 * vazio = automático pelo tier de qualidade (mapa IMG_MODELS/VID_MODELS do AnimationFlow).
 * Persistido no projeto pra valer também no modo automático e refletir no quote().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('animation_projects', function (Blueprint $table) {
            $table->string('image_model', 64)->default('')->after('quality');
            $table->string('video_model', 64)->default('')->after('image_model');
        });
    }

    public function down(): void
    {
        Schema::table('animation_projects', function (Blueprint $table) {
            $table->dropColumn(['image_model', 'video_model']);
        });
    }
};
