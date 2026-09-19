<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F3 do fluxo image→cena (PLANO-PERSONAGENS §3.2): a camada metodológica do CENÁRIO. O cenário
 * deixa de ser só nome+imagem e ganha o `spec` (JSON) — a ambientação com FUNÇÃO DRAMÁTICA, a
 * tríade tempo/espaço (Aristóteles), atmosfera e zonas de risco (Tema 3 do curso):
 *
 *   spec = { funcao_dramatica, tempo_espaco:{tempo,espaco}, atmosfera:{mood,luz,clima,paleta},
 *            riscos:[…], jogabilidade }
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scenarios', function (Blueprint $table) {
            $table->json('spec')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('scenarios', function (Blueprint $table) {
            $table->dropColumn('spec');
        });
    }
};
