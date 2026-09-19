<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Agendamento de publicação: QUANDO publicar. O estado ('scheduled') continua dentro do JSON
// `publish`, reusando a máquina de estados e o claim anti-duplo-publish que já existem — mas o
// instante vira COLUNA porque o worker varre "vencidos" a cada minuto, e varrer campo JSON não
// aproveita índice. Índice parcial: só linhas agendadas entram (a esmagadora maioria é null).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drafts', function (Blueprint $table) {
            $table->timestampTz('scheduled_at')->nullable();
        });
        Schema::table('drafts', function (Blueprint $table) {
            $table->index('scheduled_at', 'drafts_scheduled_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('drafts', function (Blueprint $table) {
            $table->dropIndex('drafts_scheduled_at_idx');
            $table->dropColumn('scheduled_at');
        });
    }
};
