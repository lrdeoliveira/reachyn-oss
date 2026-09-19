<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Modelos de PÓS-PROCESSAMENTO de imagem (kind='edit' — ISOLADO dos seletores de geração de
// imagem/vídeo, que filtram por kind): "Melhorar" (upscale) e "Remover fundo", todos via KIE.
// capabilities.kie.image_field = campo onde a imagem de ORIGEM entra no createTask da KIE
// (recraft usa "image", topaz usa "image_url"). Preços = custo real KIE ÷ US$0,005 (editável no
// Filament). display_name é white-label (#6); o operador vê o modelo real via real_name.
// Idempotente: updateOrInsert por slug (re-rodar não duplica).
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $rows = [
            [
                'slug' => 'edit-upscale',
                'display_name' => 'Melhorar',
                'kind' => 'edit',
                'subtype' => 'upscale',
                'provider' => 'kie',
                'provider_model_id' => 'recraft/crisp-upscale',
                'cost_credits' => 2, // ~US$0,006
                'capabilities' => json_encode(['kie' => ['image_field' => 'image'], 'task_types' => ['Melhorar imagem']]),
                'is_active' => true,
                'min_plan' => null,
                'sort_order' => 0,
            ],
            [
                'slug' => 'edit-upscale-pro',
                'display_name' => 'Melhorar Pro',
                'kind' => 'edit',
                'subtype' => 'upscale',
                'provider' => 'kie',
                'provider_model_id' => 'topaz/image-upscale',
                'cost_credits' => 13, // Topaz 2K (upscale_factor 2) ~US$0,05 × 1,30 margem
                'capabilities' => json_encode(['kie' => ['image_field' => 'image_url', 'extra' => ['upscale_factor' => '2']], 'task_types' => ['Melhorar imagem']]),
                'is_active' => true,
                'min_plan' => null,
                'sort_order' => 1,
            ],
            [
                'slug' => 'edit-remove-bg',
                'display_name' => 'Remover fundo',
                'kind' => 'edit',
                'subtype' => 'remove_bg',
                'provider' => 'kie',
                'provider_model_id' => 'recraft/remove-background',
                'cost_credits' => 2, // ~US$0,006
                'capabilities' => json_encode(['kie' => ['image_field' => 'image'], 'task_types' => ['Remover fundo']]),
                'is_active' => true,
                'min_plan' => null,
                'sort_order' => 2,
            ],
        ];
        foreach ($rows as $row) {
            DB::table('gen_models')->updateOrInsert(
                ['slug' => $row['slug']],
                array_merge($row, ['updated_at' => $now, 'created_at' => $now]),
            );
        }
    }

    public function down(): void
    {
        DB::table('gen_models')->whereIn('slug', ['edit-upscale', 'edit-upscale-pro', 'edit-remove-bg'])->delete();
    }
};
