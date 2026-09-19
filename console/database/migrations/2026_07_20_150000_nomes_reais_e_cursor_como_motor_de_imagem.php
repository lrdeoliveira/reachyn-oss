<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Três mudanças no catálogo de imagem, todas pedidas pelo Luciano em 2026-07-20:
//
// 1. NOME REAL no select, no lugar do rótulo white-label ("Studio B" → "mmx"). Ele é o
//    único usuário do Reachyn hoje, e "Studio B" ao lado de "Imagem" escondia que os dois
//    eram o MESMO modelo por baixo. ⚠️ Contraria a guideline #6 (white-label): se entrar
//    cliente, o down() desta migration devolve os rótulos antigos.
//
// 2. img-padrao DESATIVADO. É o mesmo image-01 do img-cli-mmx, só que pela API paga
//    (US$0,0035/img) em vez da CLI de assinatura (custo marginal zero). Não removo o
//    registro: ele não depende do sidecar, então é a contingência se o bridge cair —
//    reativar no Filament devolve t2i na hora.
//
// 3. img-cli-cursor CRIADO como segundo motor via bridge. O agente `cursor` tem tool de
//    geração de imagem — validado na VPS em 2026-07-20 (PNG 1536x1024 real). É t2i apenas:
//    não tem subject-ref, então não serve pra ancoragem de personagem (o bridge recusa
//    ref_urls com erro claro). Só chega ao usuário depois do deploy do bridge com o
//    imgAdapter novo; antes disso o /health devolve can_generate=false pra ele.
//
// O `agy` continua FORA da geração de propósito: só produz imagem com
// --dangerously-skip-permissions (auto-aprova todo tool de um agente com shell rodando
// como root, sobre prompt vindo do usuário) e grava num scratch fixo que colide entre
// jobs concorrentes. Segue disponível como refinador, onde roda read-only.
//
// Por que migration e não só seeder: o CurrentModelsSeeder é não-destrutivo de propósito —
// is_active/sort_order/cost_credits só entram no 1º insert, pra não sobrescrever a
// calibração que o operador faz no Filament. Então mudar isso em linha que já existe em
// prod exige UPDATE explícito.
return new class extends Migration
{
    public function up(): void
    {
        DB::table('gen_models')->where('slug', 'img-cli-mmx')
            ->update(['display_name' => 'mmx', 'sort_order' => 0]);

        DB::table('gen_models')->where('slug', 'img-padrao')
            ->update(['display_name' => 'image-01 (API)', 'is_active' => false, 'sort_order' => 3]);

        DB::table('gen_models')->where('slug', 'img-referencia')
            ->update(['display_name' => 'nano-banana-2 (referência)']);

        // upsert: se o seeder já rodou depois do deploy, o registro existe — não duplicar.
        DB::table('gen_models')->updateOrInsert(
            ['slug' => 'img-cli-cursor'],
            [
                'display_name' => 'cursor',
                'kind' => 'image',
                'subtype' => 'text_to_image',
                'provider' => 'cli-bridge',
                'provider_model_id' => 'cursor',
                'cost_credits' => 2,
                'cost_basis_micro' => 0,
                'capabilities' => json_encode(['task_types' => ['Text to Image']]),
                'is_active' => true,
                'sort_order' => 1,
                'min_plan' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('gen_models')->where('slug', 'img-cli-cursor')->delete();

        DB::table('gen_models')->where('slug', 'img-referencia')
            ->update(['display_name' => 'Imagem (referência)']);

        DB::table('gen_models')->where('slug', 'img-padrao')
            ->update(['display_name' => 'Imagem', 'is_active' => true, 'sort_order' => 0]);

        DB::table('gen_models')->where('slug', 'img-cli-mmx')
            ->update(['display_name' => 'Studio B', 'sort_order' => 2]);
    }
};
