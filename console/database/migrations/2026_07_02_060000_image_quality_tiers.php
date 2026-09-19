<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// IMAGEM — seletor de QUALIDADE (resolução). Modelos com tiers ganham `capabilities.kie.qualities`
// `[{key,label,extra,p}]` + `default_quality` (imagem = 1 preço `p`, sem duração). O cliente escolhe a
// resolução e o preço = quality.p. Campos confirmados na playground da KIE: nano-banana-2/pro e
// gpt-image-2 = `resolution` (1K/2K/4K); ideogram v3 = `rendering_speed` (TURBO/BALANCED/QUALITY).
// Preços = custo real KIE × 1,30. O default bate com o cost_credits atual (reprice de 2026-07-02).
return new class extends Migration
{
    public function up(): void
    {
        $cfg = [
            'nano-banana-2' => ['default' => '1k', 'q' => [ // img-pro (t2i)
                ['key' => '1k', 'label' => 'HD (1K)', 'p' => 10, 'extra' => ['resolution' => '1K']],
                ['key' => '2k', 'label' => '2K', 'p' => 16, 'extra' => ['resolution' => '2K']],
                ['key' => '4k', 'label' => '4K', 'p' => 23, 'extra' => ['resolution' => '4K']],
            ], 'slug' => 'img-pro'],
            'nano-banana-pro' => ['default' => '1k', 'q' => [ // img-ultra
                ['key' => '1k', 'label' => 'HD (1K)', 'p' => 23, 'extra' => ['resolution' => '1K']],
                ['key' => '2k', 'label' => '2K', 'p' => 23, 'extra' => ['resolution' => '2K']],
                ['key' => '4k', 'label' => '4K', 'p' => 31, 'extra' => ['resolution' => '4K']],
            ], 'slug' => 'img-ultra'],
            'gpt-image-2-text-to-image' => ['default' => '1k', 'q' => [ // img-criativo
                ['key' => '1k', 'label' => 'HD (1K)', 'p' => 8, 'extra' => ['resolution' => '1K']],
                ['key' => '2k', 'label' => '2K', 'p' => 13, 'extra' => ['resolution' => '2K']],
                ['key' => '4k', 'label' => '4K', 'p' => 21, 'extra' => ['resolution' => '4K']],
            ], 'slug' => 'img-criativo'],
            'ideogram/v3-text-to-image' => ['default' => 'quality', 'q' => [ // img-tipografia (velocidade = qualidade)
                ['key' => 'turbo', 'label' => 'Rápida', 'p' => 5, 'extra' => ['rendering_speed' => 'TURBO']],
                ['key' => 'balanced', 'label' => 'Equilibrada', 'p' => 9, 'extra' => ['rendering_speed' => 'BALANCED']],
                ['key' => 'quality', 'label' => 'Alta', 'p' => 13, 'extra' => ['rendering_speed' => 'QUALITY']],
            ], 'slug' => 'img-tipografia'],
        ];
        foreach ($cfg as $c) {
            $m = DB::table('gen_models')->where('slug', $c['slug'])->where('kind', 'image')->first();
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
                'cost_credits' => $def['p'], // base = preço da qualidade default
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Remove só as qualities/default_quality (mantém o resto do kie spec).
        foreach (['img-pro', 'img-ultra', 'img-criativo', 'img-tipografia'] as $slug) {
            $m = DB::table('gen_models')->where('slug', $slug)->first();
            if (! $m) {
                continue;
            }
            $caps = json_decode($m->capabilities ?: '{}', true) ?: [];
            unset($caps['kie']['qualities'], $caps['kie']['default_quality']);
            DB::table('gen_models')->where('id', $m->id)->update(['capabilities' => json_encode($caps)]);
        }
    }
};
