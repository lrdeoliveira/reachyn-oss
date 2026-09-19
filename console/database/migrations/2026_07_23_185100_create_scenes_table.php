<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F4 (PLANO-PERSONAGENS §3.3): a ESCALETA. Uma lista ordenada de `scenes` É a escaleta do projeto e
 * a base do mini-GDD. Cada cena LIGA personagem × cenário e carrega a dramaturgia + o cabeçalho de
 * cena do curso (Tema 3):
 *   - cabeçalho: local / int_ext (INT|EXT) / tempo (DIA|NOITE|época) — "CASA/INT/NOITE".
 *   - dramaturgia: motivacao, objetivo_cena, conflito_cena, virada (ponto de virada), tamanho, resumo.
 *   - ligações: scenario_id (o cenário) + character_ids (quem entra).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scenes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('ordem')->default(0);
            // ligações
            $table->foreignId('scenario_id')->nullable()->constrained('scenarios')->nullOnDelete();
            $table->json('character_ids')->nullable(); // ids dos personagens que entram na cena
            // cabeçalho de cena (Tema 3 do curso)
            $table->string('local')->nullable();
            $table->string('int_ext', 8)->nullable(); // INT | EXT
            $table->string('tempo')->nullable();       // DIA | NOITE | época/hora
            // dramaturgia da cena
            $table->text('motivacao')->nullable();
            $table->text('objetivo_cena')->nullable();
            $table->text('conflito_cena')->nullable();
            $table->boolean('virada')->default(false); // ponto de virada?
            $table->string('tamanho', 40)->nullable(); // peso/duração da cena
            $table->text('resumo')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'ordem']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scenes');
    }
};
