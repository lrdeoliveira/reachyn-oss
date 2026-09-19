<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 🗣️ VOZ DA MARCA (Sprint A do plano Hollywood): texto curto do tenant (tom, vocabulário,
// restrições) PREFIXADO como craft em TODA geração de texto — posts de rede, resumos,
// roteiro das Histórias e plano/locução do Filme. Editável na aba Prompts.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->text('brand_voice')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('brand_voice');
        });
    }
};
