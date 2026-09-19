<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Biblioteca de CENÁRIOS reutilizáveis (F5 — aprendizado do storyboard "Elementos"): imagem-âncora
 * + descrição, plugável como ref i2i de cena nas Histórias/Quadrinhos (mesmo papel do personagem
 * da biblioteca, mas pro AMBIENTE — mantém o mesmo cenário/luz entre cenas e episódios).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scenarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->text('description')->nullable();
            $table->string('image_url', 500)->nullable(); // imagem-âncora (nosso S3)
            $table->timestamps();
            $table->index(['tenant_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scenarios');
    }
};
