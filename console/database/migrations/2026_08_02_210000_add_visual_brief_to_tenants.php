<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🖼️ BRIEF VISUAL da marca — o que as referências do tenant dizem, lido por visão e guardado.
 *
 * POR QUE PERSISTE: decodificar as referências custa uma chamada de visão com as imagens inline.
 * Fazer isso a cada carrossel seria pagar de novo pela mesma resposta, já que as referências de
 * uma marca mudam de mês em mês, não de post em post. O `visual_brief_at` diz quando foi lido,
 * pra tela mostrar a idade e oferecer "reler as referências".
 *
 * `text` e não JSON: o brief é um bloco em linguagem natural que vai INTEIRO pro prompt. Quebrá-lo
 * em campos só criaria a tentação de reescrevê-lo por partes — e o valor dele está em ser o que a
 * visão observou, não o que achamos que a marca deveria ser.
 *
 * Nullable: marca sem referências gera carrossel no registro sóbrio default. Nada trava.
 *
 * Idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenants') || Schema::hasColumn('tenants', 'visual_brief')) {
            return;
        }
        Schema::table('tenants', function (Blueprint $t) {
            $t->text('visual_brief')->nullable()->after('brand_handle');
            $t->timestamp('visual_brief_at')->nullable()->after('visual_brief');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('tenants') && Schema::hasColumn('tenants', 'visual_brief')) {
            Schema::table('tenants', function (Blueprint $t) {
                $t->dropColumn(['visual_brief', 'visual_brief_at']);
            });
        }
    }
};
