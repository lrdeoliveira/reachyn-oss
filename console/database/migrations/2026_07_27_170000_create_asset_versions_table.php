<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VERSÕES DE ASSET — o histórico de imagens de personagem, cenário e elemento.
 *
 * O QUE ISSO CONSERTA: a gente SOBRESCREVIA. Regerar a base de um personagem ou a imagem de um
 * cenário trocava a URL e órfãva o arquivo anterior — sem lista, sem volta. Aconteceu em
 * 2026-07-27 com o cenário "Casa do filhote": a primeira versão sumiu do produto no instante em
 * que a segunda ficou pronta, e ela era a única prova do defeito que estávamos investigando.
 * Ideia lida no 3D Gen Studio, que versiona cada asset e deixa apagar versão velha.
 *
 * O arquivo em si nunca é apagado do storage — só a URL saía de vista. Aqui ela fica guardada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // Polimórfico na mão (sem morphs): três donos conhecidos, e um enum curto lê melhor
            // no banco do que a FQCN do model.
            $t->string('owner_type', 16);           // character | scenario | element
            $t->unsignedBigInteger('owner_id');
            $t->string('campo', 24);                // base_url | image_url | sheet_url | mesh_url
            $t->string('url', 500);
            $t->string('model', 60)->nullable();    // motor que gerou (quando se sabe)
            $t->timestamps();
            $t->index(['tenant_id', 'owner_type', 'owner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_versions');
    }
};
