<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Brand kit por marca (tenant) — identidade visual usada pelo compositor de posts (next/og).
 * Complementa `brand_voice` (tom textual) com a parte VISUAL: cor, contraste, logo e handle.
 * Tudo nullable → o compositor cai em defaults da marca quando vazio (secure/robusto by default).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('brand_primary', 9)->nullable();   // cor da marca (#RRGGBB[AA])
            $table->string('brand_ink', 9)->nullable();       // cor de texto sobre superfície clara (CTA)
            $table->string('brand_logo_url')->nullable();     // logo do tenant (URL no nosso storage)
            $table->string('brand_handle')->nullable();       // @arroba exibido no post
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['brand_primary', 'brand_ink', 'brand_logo_url', 'brand_handle']);
        });
    }
};
