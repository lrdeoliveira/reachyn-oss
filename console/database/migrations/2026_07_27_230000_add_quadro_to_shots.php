<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QUADRO DO PLANO (Fase 2.1 do docs/ESTUDIO-3D.md).
 *
 * A âncora de ângulo (render da malha, `shots.ancora_url`) deixou de ser só consulta visual:
 * o motor local a transforma em contorno (Canny) e gera o quadro OBEDECENDO a composição —
 * `quadro_url` guarda esse resultado, por plano. `quadro_status` segue o padrão do Element
 * ('gerando' | 'erro' | null) pra UI saber o que mostrar sem inventar estado no cliente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shots', function (Blueprint $t) {
            $t->string('quadro_url')->nullable()->after('ancora_url');
            $t->string('quadro_status', 20)->nullable()->after('quadro_url');
        });
    }

    public function down(): void
    {
        Schema::table('shots', function (Blueprint $t) {
            $t->dropColumn(['quadro_url', 'quadro_status']);
        });
    }
};
