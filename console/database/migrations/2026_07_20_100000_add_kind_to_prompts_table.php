<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// `kind` classifica o prompt pelo ALVO da geração: 'image' e 'video' são as PERSONAS de
// estilo (o select de persona do Studio filtra por aqui). NULL = prompt de texto/roteiro
// — todos os 33 existentes (🎬 Roteirista, 🎥 Diretor, e os do cliente) continuam como
// estão e seguem aparecendo onde já apareciam.
//
// Por que uma coluna e não o prefixo do título: a convenção por emoji ("🎥 Diretor: ")
// já existe e funciona pra AGRUPAR na UI, mas usar emoji como chave de filtro quebra no
// primeiro título que o cliente editar — e a aba Prompts é editável de propósito. Tipo
// é dado, não formatação.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prompts', function (Blueprint $table) {
            $table->string('kind', 16)->nullable()->index()->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('prompts', function (Blueprint $table) {
            $table->dropIndex(['kind']);
            $table->dropColumn('kind');
        });
    }
};
