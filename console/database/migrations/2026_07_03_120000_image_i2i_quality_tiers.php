<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// IMAGEM i2i (cenas de Histórias / ancoragem de personagem) — seletor de QUALIDADE também no i2i:
// o img-referencia (KIE nano-banana-2, refs em image_input) ganha os MESMOS tiers de resolução do
// t2i nano-banana-2 (campo `resolution` 1K/2K/4K, confirmado na playground da KIE em 2026-07-02).
// Preço: o default (1K) mantém o cost_credits já praticado (6) e os tiers escalam na MESMA
// proporção do img-pro (10/16/23 → ×0,6 ≈ 6/10/14). O `extra` é interno (white-label).
return new class extends Migration
{
    public function up(): void
    {
        $m = DB::table('gen_models')->where('slug', 'img-referencia')->where('kind', 'image')->first();
        if (! $m) {
            return;
        }
        $caps = json_decode($m->capabilities ?: '{}', true) ?: [];
        $caps['kie'] = $caps['kie'] ?? [];
        $caps['kie']['qualities'] = [
            ['key' => '1k', 'label' => 'HD (1K)', 'p' => 6, 'extra' => ['resolution' => '1K']],
            ['key' => '2k', 'label' => '2K', 'p' => 10, 'extra' => ['resolution' => '2K']],
            ['key' => '4k', 'label' => '4K', 'p' => 14, 'extra' => ['resolution' => '4K']],
        ];
        $caps['kie']['default_quality'] = '1k';
        DB::table('gen_models')->where('id', $m->id)->update([
            'capabilities' => json_encode($caps),
            'cost_credits' => 6, // base = preço da qualidade default (1K) — o preço já praticado
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $m = DB::table('gen_models')->where('slug', 'img-referencia')->first();
        if (! $m) {
            return;
        }
        $caps = json_decode($m->capabilities ?: '{}', true) ?: [];
        unset($caps['kie']['qualities'], $caps['kie']['default_quality']);
        DB::table('gen_models')->where('id', $m->id)->update(['capabilities' => json_encode($caps), 'updated_at' => now()]);
    }
};
