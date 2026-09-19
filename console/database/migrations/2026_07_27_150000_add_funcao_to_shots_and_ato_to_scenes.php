<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Duas lacunas que a leitura do Celtx expôs (2026-07-27):
 *
 * 1) FUNÇÃO DO PLANO. O nosso vocabulário era geometria pura (enquadramento/ângulo/altura/
 *    movimento). O do Celtx mistura função de MONTAGEM — master, cutaway, cut-in — e num filme
 *    gerado por IA isso pesa ainda mais que no set: o cutaway é o corte de alívio que salva uma
 *    emenda que não casa, e a decupagem não tinha como pedir um.
 *
 * 2) ATO. A escaleta era uma lista plana; o Beat Sheet deles organiza a história em atos. Com o
 *    ato gravado, o 📐 Doutor de Roteiro julga estrutura com régua ("a virada está no fim do ato
 *    1?") e o roteiro exportado sai com as seções que qualquer editor mostra no outline.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shots', function (Blueprint $t) {
            $t->string('funcao', 24)->nullable()->after('ordem');
        });
        // Default 1: história sem divisão declarada é um ato só — não existe cena órfã de ato.
        Schema::table('scenes', function (Blueprint $t) {
            $t->unsignedSmallInteger('ato')->default(1)->after('ordem');
        });
    }

    public function down(): void
    {
        Schema::table('shots', function (Blueprint $t) {
            $t->dropColumn('funcao');
        });
        Schema::table('scenes', function (Blueprint $t) {
            $t->dropColumn('ato');
        });
    }
};
