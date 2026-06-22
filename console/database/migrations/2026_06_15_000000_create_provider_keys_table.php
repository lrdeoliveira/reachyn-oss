<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Chaves dos provedores de GERAÇÃO (operador): texto, texto-alt, vídeo/imagem, voz.
// Globais (não por tenant), cifradas (cast encrypted no model). O engine as recebe via
// PUT /v1/admin/gen-keys (push no save) + GET /internal/gen-keys (boot-fetch).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_keys', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->unique(); // slug do provedor (contrato de fio com o engine)
            $table->text('api_key')->nullable();   // cifrada (encrypted cast)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_keys');
    }
};
