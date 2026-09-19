<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Catálogo de MODELOS de geração selecionáveis (operador). Global (não por tenant),
// igual provider_keys. Organizado por `kind` (video/image/audio/text). Liga/desliga,
// preço e instabilidade SEM deploy. Campos públicos (white-label) x campos de
// roteamento INTERNOS (provider/provider_model_id/endpoint/cost_basis) que NUNCA
// vão pro cliente. Empurrado pro engine no mesmo fluxo das gen-keys.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gen_models', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();          // id estável p/ web + engine
            $table->string('display_name');            // PÚBLICO, white-label
            $table->string('kind');                    // video | image | audio | text
            $table->string('subtype')->nullable();     // text_to_image | image_to_video ...

            // --- roteamento INTERNO (nunca serializado pro cliente) ---
            $table->string('provider');                // kie | minimax | google | freepik | elevenlabs
            $table->string('provider_model_id');       // id/path no upstream (ex: 'veo-3-1')
            $table->string('provider_endpoint')->nullable();

            // --- custo / margem ---
            $table->unsignedInteger('cost_credits')->nullable(); // créditos cobrados do user (null = não precificado)
            $table->unsignedInteger('cost_basis_micro')->nullable(); // custo upstream micro-USD (margem; INTERNO)

            // --- capacidades (task_types, variantes, upstream real, proporções, duração) ---
            $table->jsonb('capabilities')->default('{}');

            // --- disponibilidade / saúde ---
            $table->boolean('is_active')->default(false);   // secure-by-default: nasce desligado
            $table->boolean('is_unstable')->default(false);
            $table->string('unstable_reason')->nullable();
            $table->timestamp('health_checked_at')->nullable();
            $table->string('min_plan')->nullable();    // null=todos | starter | pro | studio
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();
            $table->index(['kind', 'is_active']);
            $table->index('provider');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gen_models');
    }
};
