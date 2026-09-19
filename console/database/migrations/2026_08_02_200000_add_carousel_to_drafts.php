<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎠 CARROSSEL — o plano editorial da peça de slides, no rascunho.
 *
 * Espelha `drafts.story` de propósito: mesma natureza (um plano de N partes que o usuário revisa
 * e depois manda renderizar uma a uma), mesmo padrão de leitura/escrita sob lock nos jobs. Guarda
 * headline, família de gancho, legenda e os slides (papel, tag, blocos de copy, accent, brief de
 * imagem, URL da imagem gerada e URL do slide composto).
 *
 * Coluna própria e não um sub-array de `story`: um rascunho pode ter história E carrossel, e
 * enfiar os dois no mesmo JSON faria dois jobs distintos disputarem a mesma linha no lock.
 *
 * Idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('drafts') || Schema::hasColumn('drafts', 'carousel')) {
            return;
        }
        Schema::table('drafts', function (Blueprint $t) {
            $t->jsonb('carousel')->nullable()->after('film');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('drafts') && Schema::hasColumn('drafts', 'carousel')) {
            Schema::table('drafts', function (Blueprint $t) {
                $t->dropColumn('carousel');
            });
        }
    }
};
