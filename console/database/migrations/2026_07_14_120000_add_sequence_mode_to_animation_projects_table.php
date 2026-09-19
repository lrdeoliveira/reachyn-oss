<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🔗 SEQUÊNCIA do Desenho animado (modo `animacao`): como as cenas se ligam entre si.
 *  - solto      : cada cena independente (comportamento atual — vira um "slideshow" de tomadas);
 *  - encadeado  : keyframe de cada cena ANCORADO no keyframe da cena anterior (continuidade
 *                 visual — mesmo cenário/enquadramento fluem); mantém o lip-sync;
 *  - plano      : encadeado + o clipe vai do keyframe DESTA cena ao da PRÓXIMA (endImageUrl →
 *                 movimento contínuo, sem corte, à moda do Filme); troca o lip-sync por i2v+mux.
 * Vale em TODOS os formatos: encadeado/plano em animacao e historia; Quadrinhos (slides-only, sem
 * clipe) usa só solto/encadeado. Default do Desenho animado = `encadeado`; narrados = `solto`
 * (keyframes em paralelo, storyboard mais rápido). Default de coluna `solto` = não muda projetos já existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('animation_projects', function (Blueprint $table) {
            $table->string('sequence_mode', 16)->default('solto')->after('mode');
        });
    }

    public function down(): void
    {
        Schema::table('animation_projects', function (Blueprint $table) {
            $table->dropColumn('sequence_mode');
        });
    }
};
