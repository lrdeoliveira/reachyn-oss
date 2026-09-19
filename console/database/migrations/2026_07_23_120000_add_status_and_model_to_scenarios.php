<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cenário passa a GERAR a própria imagem-âncora (antes só recebia uma imagem já existente da
 * galeria, e nenhuma tela sequer chamava o POST /api/scenarios — a biblioteca só era alimentada
 * de fora). Com a geração assíncrona (GenerateScenarioJob) o registro precisa dizer em que pé
 * está, igual ao personagem:
 *
 *   status: '' = ocioso/pronto · 'base' = gerando a imagem · 'error' = falhou
 *           (mesma semântica do characters.status — descreve a geração EM CURSO, não o resultado)
 *   image_model: slug do GenModel usado, para regenerar reusando a escolha original
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scenarios', function (Blueprint $table) {
            $table->string('status', 24)->default('')->after('image_url');
            $table->string('image_model', 60)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('scenarios', function (Blueprint $table) {
            $table->dropColumn(['status', 'image_model']);
        });
    }
};
