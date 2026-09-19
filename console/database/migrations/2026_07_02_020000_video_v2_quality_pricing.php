<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// VÍDEO v2 — seletor de QUALIDADE (resolução) + preço por DURAÇÃO. A KIE cobra por segundo (kling/
// wan/seedance) OU por vídeo fixo (hailuo). Cada modelo ganha `capabilities.kie.qualities`
// [{key,label,extra,p5,p10}] + `default_quality`. Preço = custo real KIE × 1,30 (margem 30%),
// a US$0,005/crédito. O console cobra quality.p{5|10} × cenas e mescla quality.extra (o campo de
// resolução/mode: kling=`mode`, wan/hailuo/seedance=`resolution`, seedance tb `generate_audio`).
// Aposenta os DUPLICADOS legados (KIE-direto é mais barato e com preço real). Adiciona "Econômico".
return new class extends Migration
{
    public function up(): void
    {
        // Qualidades por provider_model_id (modelos KIE já existentes).
        $cfg = [
            'kling-3.0/video' => ['default' => 'pro', 'q' => [
                ['key' => 'std', 'label' => 'HD (720p)', 'p5' => 90, 'p10' => 180, 'extra' => ['mode' => 'std', 'sound' => false]],
                ['key' => 'pro', 'label' => 'Full HD (1080p)', 'p5' => 120, 'p10' => 235, 'extra' => ['mode' => 'pro', 'sound' => false]],
                ['key' => '4k', 'label' => '4K', 'p5' => 435, 'p10' => 870, 'extra' => ['mode' => '4K', 'sound' => false]],
            ]],
            'wan/2-7-text-to-video' => ['default' => '720p', 'q' => [
                ['key' => '720p', 'label' => 'HD (720p)', 'p5' => 105, 'p10' => 210, 'extra' => ['resolution' => '720p']],
                ['key' => '1080p', 'label' => 'Full HD (1080p)', 'p5' => 155, 'p10' => 310, 'extra' => ['resolution' => '1080p']],
            ]],
            'hailuo/2-3-image-to-video-pro' => ['default' => '768p', 'q' => [
                ['key' => '768p', 'label' => 'HD', 'p5' => 60, 'p10' => 120, 'extra' => ['resolution' => '768P']],
                ['key' => '1080p', 'label' => 'Full HD', 'p5' => 105, 'p10' => 175, 'extra' => ['resolution' => '1080P']],
            ]],
            'bytedance/seedance-2' => ['default' => '720p', 'q' => [
                ['key' => '720p', 'label' => 'HD (720p)', 'p5' => 265, 'p10' => 530, 'extra' => ['resolution' => '720p', 'generate_audio' => false]],
                ['key' => '1080p', 'label' => 'Full HD (1080p)', 'p5' => 665, 'p10' => 1325, 'extra' => ['resolution' => '1080p', 'generate_audio' => false]],
            ]],
        ];
        foreach ($cfg as $pmid => $c) {
            $m = DB::table('gen_models')->where('provider_model_id', $pmid)->where('kind', 'video')->first();
            if (! $m) {
                continue;
            }
            $caps = json_decode($m->capabilities ?: '{}', true) ?: [];
            $caps['kie'] = $caps['kie'] ?? [];
            $caps['kie']['qualities'] = $c['q'];
            $caps['kie']['default_quality'] = $c['default'];
            $def = collect($c['q'])->firstWhere('key', $c['default']) ?? $c['q'][0];
            DB::table('gen_models')->where('id', $m->id)->update([
                'capabilities' => json_encode($caps),
                'cost_credits' => $def['p5'], // base = preço da qualidade default em 5s
                'updated_at' => now(),
            ]);
        }

        // NOVO "Econômico" — seedance-1.5-pro, o mais barato (per-second, sem áudio).
        DB::table('gen_models')->updateOrInsert(
            ['slug' => 'vid-economico'],
            [
                'display_name' => 'Econômico',
                'kind' => 'video',
                'subtype' => 'image_to_video',
                'provider' => 'kie',
                'provider_model_id' => 'bytedance/seedance-1.5-pro',
                'cost_credits' => 25,
                'capabilities' => json_encode([
                    'kie' => [
                        'refs_field' => 'input_urls', 'refs_single' => false,
                        'aspect_field' => 'aspect_ratio', 'duration_field' => 'duration', 'duration_string' => false,
                        'extra' => ['generate_audio' => false],
                        'default_quality' => '720p',
                        'qualities' => [
                            ['key' => '480p', 'label' => 'Rápida (480p)', 'p5' => 12, 'p10' => 23, 'extra' => ['resolution' => '480p', 'generate_audio' => false]],
                            ['key' => '720p', 'label' => 'HD (720p)', 'p5' => 25, 'p10' => 46, 'extra' => ['resolution' => '720p', 'generate_audio' => false]],
                            ['key' => '1080p', 'label' => 'Full HD (1080p)', 'p5' => 50, 'p10' => 98, 'extra' => ['resolution' => '1080p', 'generate_audio' => false]],
                        ],
                    ],
                    'durations' => [5, 10], 'task_types' => ['Text to Video', 'Image to Video'],
                ]),
                'is_active' => true, 'min_plan' => null, 'sort_order' => 5,
                'updated_at' => now(), 'created_at' => now(),
            ],
        );

        // Os DUPLICADOS legados (Rápido/Equilibrado/Pro/Cinematográfico) foram aposentados: os
        // KIE-diretos cobrem os mesmos upstreams mais barato e com preço real por segundo. A
        // desativação vive hoje na migration `desativa_modelos_provedor_legado` (idempotente).
    }

    public function down(): void
    {
        DB::table('gen_models')->where('slug', 'vid-economico')->delete();
        // As `qualities` nas capabilities ficam (inócuas se o console não usar).
    }
};
