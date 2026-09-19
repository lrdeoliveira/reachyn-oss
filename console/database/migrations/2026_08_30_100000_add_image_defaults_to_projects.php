<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PADRÃO VISUAL DA HISTÓRIA (pedido do Luciano, 2026-08-30): o modelo e a técnica de imagem
 * passam a ser escolhidos UMA vez, no Roteiro, e valem para todo o elenco derivado dele —
 * personagens, cenários e elementos. Antes cada aba escolhia o seu, então o personagem nascia
 * "realista", o cenário no default do catálogo e o objeto num terceiro modelo: a história saía
 * com três estéticas e a produção tinha de regerar tudo à mão pra empatar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('image_model')->nullable();  // gen_models.slug — null = topo do catálogo
            $table->string('image_style')->nullable();  // técnica (imageStyles): realista, 3d, anime…
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['image_model', 'image_style']);
        });
    }
};
