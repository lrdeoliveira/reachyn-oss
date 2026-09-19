<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TÉCNICA no cenário e no elemento — personagem já tinha `style` desde sempre; cenário e elemento
 * geravam SEM estilo nenhum (prompt cru, o engine caía no "realista"). Com a história definindo o
 * padrão visual no Roteiro, os três precisam guardar a técnica com que a imagem foi feita: é o que
 * permite regerar um asset depois e ele voltar igual aos irmãos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scenarios', fn (Blueprint $t) => $t->string('style')->nullable());
        Schema::table('elements', fn (Blueprint $t) => $t->string('style')->nullable());
    }

    public function down(): void
    {
        Schema::table('scenarios', fn (Blueprint $t) => $t->dropColumn('style'));
        Schema::table('elements', fn (Blueprint $t) => $t->dropColumn('style'));
    }
};
