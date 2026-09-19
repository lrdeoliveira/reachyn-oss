<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎬 UNIFICAÇÃO Histórias + Quadrinhos como MODOS do Estúdio de Animação
 * (docs/PLANO-UNIFICACAO-HISTORIAS-QUADRINHOS.md). `mode` decide o pipeline:
 *  - animacao   (default, atual): diálogo multi-voz por cena + /v1/filmassemble;
 *  - historia   : narração única (voz do narrador) + i2v por cena + montagem /v1/storyvideo;
 *  - quadrinhos : narração única + SLIDES (sem i2v, Ken Burns) + montagem /v1/storyvideo (40 créd).
 * `voice_id` = voz do NARRADOR nos modos narrados (vazio = voz padrão da conta).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('animation_projects', function (Blueprint $table) {
            $table->string('mode', 16)->default('animacao')->after('title');
            $table->string('voice_id', 64)->default('')->after('music_prompt');
        });
    }

    public function down(): void
    {
        Schema::table('animation_projects', function (Blueprint $table) {
            $table->dropColumn(['mode', 'voice_id']);
        });
    }
};
