<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎬 ESTÚDIO DE ANIMAÇÃO — projeto "roteiro → desenho pronto" (docs/PLANO-ESTUDIO-ANIMACAO.md).
 * O projeto é uma entidade própria (não vive no JSON do Draft): tem ciclo de vida longo
 * (roteiro → elementos → storyboard → animação → montagem), é retomável e cada etapa grava
 * estado granular em `elements`/`storyboard` (JSON). O vídeo FINAL vira um Draft normal
 * (final_draft_id) — cai na galeria/aprovações/publicações como qualquer mídia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('animation_projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('title')->default('');
            $table->string('style')->default('3d');          // 3d|cartoon|stickman|anime|realista (style-pack)
            $table->string('lang', 8)->default('pt-BR');
            $table->string('quality', 16)->default('padrao'); // economico|padrao|premium (mapa de modelos)
            $table->string('aspect', 8)->default('16:9');
            $table->string('palette')->default('');           // direção de arte do projeto (S3)
            $table->string('music_prompt')->default('');
            $table->text('script');                           // roteiro colado (ou ideia)
            $table->json('elements')->nullable();             // {characters:[...], locations:[...], props:[...]}
            $table->json('storyboard')->nullable();           // [{action, dialogue, spec, keyframe_url, video_url, ...}]
            $table->string('status', 24)->default('parsing'); // parsing|elements|storyboard|animating|assembling|done|error
            $table->boolean('auto')->default(false);          // modo automático (o "agente"): cada job avança o próximo passo
            $table->string('error')->default('');
            $table->foreignId('final_draft_id')->nullable()->constrained('drafts')->nullOnDelete();
            $table->string('final_url')->default('');
            $table->timestamps();
            $table->index(['tenant_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('animation_projects');
    }
};
