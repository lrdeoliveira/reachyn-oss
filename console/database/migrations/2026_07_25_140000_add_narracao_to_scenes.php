<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NARRAÇÃO por cena na escaleta.
 *
 * A cena tinha o que se VÊ (resumo, cabeçalho, dramaturgia) e nada do que se OUVE. Resultado: as
 * cenas chegavam MUDAS na Montagem — `narracao` vazia em todo nó — e a locução tinha que ser
 * escrita cena a cena lá, já na tela de produção, que é o lugar errado pra escrever texto. Pior
 * no filme montado: sem narração em nenhuma cena, o `script` contínuo sai vazio e a montagem
 * entrega um vídeo mudo sem avisar por quê.
 *
 * Fica na escaleta (e não só no canvas) porque narração é ROTEIRO: é escrita junto com a ação,
 * revisada com ela, e é o que a IA consegue escrever de uma vez com o resto da cena.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scenes', function (Blueprint $t) {
            $t->text('narracao')->nullable()->after('resumo');
        });
    }

    public function down(): void
    {
        Schema::table('scenes', function (Blueprint $t) {
            $t->dropColumn('narracao');
        });
    }
};
