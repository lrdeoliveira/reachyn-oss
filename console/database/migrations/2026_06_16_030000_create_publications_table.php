<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Arquivo de PUBLICAÇÕES (snapshot permanente).
 *
 * Ao aprovar/publicar uma peça, gravamos aqui um registro IMUTÁVEL com o que
 * realmente foi ao ar (texto final + mídia + redes/posts). Diferente de
 * drafts.publish / approvals.meta (que são estado mutável do fluxo e podem ser
 * sobrescritos/limpos), esta tabela é o histórico permanente que o cliente vê
 * em /publicacoes.
 *
 * Multi-tenant: tenant_id indexado + global scope do trait BelongsToTenant no model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('publications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // Origem do snapshot: de qual fluxo veio.
            $table->string('source_type'); // 'draft' | 'approval'
            $table->string('source_id');   // id do Draft/Approval de origem (string p/ flexibilidade)
            $table->string('keyword')->default('');
            $table->text('content_text')->nullable();       // texto final publicado
            $table->json('media')->nullable();              // [{kind, url}]
            $table->json('networks')->nullable();           // [{platform, ok, post_id, url, published_at}]
            $table->string('status')->default('publicado'); // 'publicado' | 'parcial' | 'falhou'
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            // Listagem do cliente: por tenant, mais recentes primeiro.
            $table->index(['tenant_id', 'published_at']);
            // Idempotência do snapshot (evita duplicar em republish): updateOrCreate por origem.
            $table->unique(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publications');
    }
};
