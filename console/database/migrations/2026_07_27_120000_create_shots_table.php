<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PLANOS (shots) — a camada que faltava entre a CENA e a IMAGEM.
 *
 * Até aqui a unidade era `cena = 1 quadro = 1 clipe`, e é por isso que o resultado parecia "clipes
 * bonitos em sequência" em vez de cena coberta: no cinema ninguém filma uma cena inteira num
 * enquadramento só. O storyboard do Celtx trabalha por PLANO, e cada plano carrega o próprio
 * quadro, ângulo e movimento — é essa unidade que entra aqui.
 *
 * O QUE MORA AQUI É PLANEJAMENTO, não produção: a cena guarda a dramaturgia, o plano guarda a
 * decupagem (como a câmera vê), e a mídia continua sendo gerada no canvas da Montagem. Mesma
 * divisão que já existia entre `scenes` (plano) e o nó do canvas (produção).
 *
 * BLOCKING COMO DADO, não como adjetivo: enquadramento, ângulo, altura da câmera e movimento são
 * campos com vocabulário fechado (`App\Support\Plano`), porque instrução mensurável vence prosa —
 * a mesma lição da régua de escala do cenário.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shots', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // Apagar a cena apaga os planos dela: plano sem cena não é nada.
            $t->foreignId('scene_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('ordem')->default(0);

            // Decupagem (vocabulário fechado — ver App\Support\Plano)
            $t->string('enquadramento', 24)->nullable();  // geral, conjunto, medio, close, detalhe…
            $t->string('angulo', 24)->nullable();         // frontal, tres_quartos, lateral, plongee…
            $t->string('altura', 24)->nullable();         // chao, joelho, peito, olhos, alto
            $t->string('movimento', 24)->nullable();      // fixo, dolly_in, travelling, panoramica…

            $t->text('acao')->nullable();                 // o que acontece NESTE plano
            $t->unsignedSmallInteger('duracao')->default(5);

            $t->timestamps();
            $t->index(['scene_id', 'ordem']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shots');
    }
};
