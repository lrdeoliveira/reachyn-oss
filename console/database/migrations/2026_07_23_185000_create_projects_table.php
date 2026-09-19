<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F4 (PLANO-PERSONAGENS §3.3): PROJETO = o agrupador leve de uma escaleta (uma história / mini-GDD).
 * Guarda o nome e um argumento/storyline curto (etapas do roteiro do curso: Ideia → Storyline →
 * Argumento → Escaleta). As cenas (tabela `scenes`) penduram aqui.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('argumento')->nullable(); // storyline/argumento: conflito + o que o protagonista quer + atos
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
