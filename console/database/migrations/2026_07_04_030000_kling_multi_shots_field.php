<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ⚡ FILME RÁPIDO (Sprint D, 2026-07-04): expõe o modo multi_shots do Kling 3.0 (já validado com
// geração real — 2 cortes, total ≤15s, só 1º frame) via capabilities.kie.multi_prompt_field.
// Merge ADITIVO (preserva qualities/refs_field/etc já configurados) — GenModelResource deriva
// a flag pública `multi_shots` só quando este campo está preenchido.
return new class extends Migration
{
    public function up(): void
    {
        $m = DB::table('gen_models')->where('provider_model_id', 'kling-3.0/video')->where('kind', 'video')->first();
        if (! $m) {
            return;
        }
        $caps = json_decode($m->capabilities ?: '{}', true) ?: [];
        $caps['kie'] = $caps['kie'] ?? [];
        $caps['kie']['multi_prompt_field'] = 'multi_prompt';
        DB::table('gen_models')->where('id', $m->id)->update([
            'capabilities' => json_encode($caps),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $m = DB::table('gen_models')->where('provider_model_id', 'kling-3.0/video')->where('kind', 'video')->first();
        if (! $m) {
            return;
        }
        $caps = json_decode($m->capabilities ?: '{}', true) ?: [];
        unset($caps['kie']['multi_prompt_field']);
        DB::table('gen_models')->where('id', $m->id)->update([
            'capabilities' => json_encode($caps),
            'updated_at' => now(),
        ]);
    }
};
