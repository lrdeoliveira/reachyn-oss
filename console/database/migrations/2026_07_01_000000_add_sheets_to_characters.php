<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MODEL SHEET v2 (multi-painel). O model sheet deixou de ser UMA imagem (sheet_url) e passou a ser
// um CONJUNTO de pranchas profissionais (turnaround 8 vistas, folha de expressões/cabeça, folha de
// poses/mãos-pés/paleta/materiais) — igual a um character-design sheet de estúdio. Cada prancha é
// um job i2i próprio (paralelo), ancorado na imagem-base. Aditivo e nullable: personagens antigos
// seguem funcionando (sheets vazio → UI cai no sheet_url legado).
//
//  - sheets:        [{kind,url}] das pranchas geradas (turnaround | expressions | poses).
//  - sheet_pending: quantas pranchas ainda estão gerando; o último job a zerar limpa o status.
// sheet_url continua sendo a prancha PRIMÁRIA (turnaround) — retrocompat com Histórias/Mídia/UI.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->json('sheets')->nullable()->after('sheet_url');
            $table->unsignedTinyInteger('sheet_pending')->default(0)->after('sheets');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn(['sheets', 'sheet_pending']);
        });
    }
};
