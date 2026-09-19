<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Reprecifica os modelos de IMAGEM pro custo real da KIE × 1,30 (margem 30%), alinhando com o
// vídeo v2. Custos reais (créditos KIE, $0,005 cada, resolução default): z-image 0,8 · seedream
// 5-lite 5,5 · qwen2 ~5,6 · seedream 4.5 6,5 · nano-banana-2 (1K) 8 · flux-2 pro (1K) 7 · ideogram
// v3 (QUALITY) 10 · gpt-image-2 (1k) 6 · nano-banana-pro (1/2K) 18. A maioria estava SUBSIDIADA;
// z-image e gpt-image-2 estavam CAROS. image-01 (MiniMax, ~grátis) fica em 2.
return new class extends Migration
{
    public function up(): void
    {
        $novo = [
            'img-veloz' => 2,          // z-image 0,8×1,3≈1 → 2 (o mais barato)
            'img-realista-lite' => 7,  // seedream 5-lite 5,5×1,3
            'img-versatil' => 7,       // qwen2 ~5,6×1,3
            'img-realista' => 9,       // seedream 4.5 6,5×1,3
            'img-referencia' => 10,    // nano-banana-2 (1K) 8×1,3
            'img-pro' => 10,           // nano-banana-2 (1K) 8×1,3
            'img-artistico' => 9,      // flux-2 pro (1K) 7×1,3
            'img-tipografia' => 13,    // ideogram v3 QUALITY 10×1,3
            'img-criativo' => 8,       // gpt-image-2 (1k) 6×1,3 (estava 12, caro)
            'img-ultra' => 23,         // nano-banana-pro (1/2K) 18×1,3
        ];
        foreach ($novo as $slug => $c) {
            DB::table('gen_models')->where('slug', $slug)->update(['cost_credits' => $c, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        $velho = [
            'img-veloz' => 4, 'img-realista-lite' => 4, 'img-versatil' => 5, 'img-realista' => 6,
            'img-referencia' => 6, 'img-pro' => 6, 'img-artistico' => 8, 'img-tipografia' => 8,
            'img-criativo' => 12, 'img-ultra' => 16,
        ];
        foreach ($velho as $slug => $c) {
            DB::table('gen_models')->where('slug', $slug)->update(['cost_credits' => $c]);
        }
    }
};
