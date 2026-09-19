<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MALHA COMO ÂNCORA DE ÂNGULO.
 *
 * O problema estrutural do filme gerado por IA é a identidade derivar entre planos. A imagem-âncora
 * resolve — mas ela é de UM ângulo só, e a decupagem (2026-07-27) passou a pedir ângulo e altura de
 * câmera por plano. Com uma malha do personagem/objeto, o ângulo pedido deixa de ser torcida: dá
 * pra RENDERIZAR exatamente aquele enquadramento e usar o render como âncora daquele plano.
 *
 * `mesh_url` guarda a malha (GLB); `shots.ancora_url` guarda o render daquele plano. A geração da
 * malha em si fica de fora por ora — ela exige GPU local (ComfyUI/Trellis) ou API 3D paga; aqui a
 * malha entra por upload, vinda de onde o usuário quiser (3D Gen Studio, Tripo, Blender).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $t) {
            $t->string('mesh_url')->nullable()->after('sheet_url');
        });
        Schema::table('elements', function (Blueprint $t) {
            $t->string('mesh_url')->nullable()->after('image_url');
        });
        Schema::table('shots', function (Blueprint $t) {
            $t->string('ancora_url')->nullable()->after('acao');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $t) {
            $t->dropColumn('mesh_url');
        });
        Schema::table('elements', function (Blueprint $t) {
            $t->dropColumn('mesh_url');
        });
        Schema::table('shots', function (Blueprint $t) {
            $t->dropColumn('ancora_url');
        });
    }
};
