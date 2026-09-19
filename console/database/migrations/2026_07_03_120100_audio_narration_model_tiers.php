<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ÁUDIO (narração/TTS) vira modelo de CATÁLOGO: até aqui a narração era um modelo fixo no serviço
// de mídia, cobrada a 1 crédito fixo no bucket 'audio', sem conceito de qualidade. Cria o gen_model
// `audio-narracao` (kind=audio) com tiers de qualidade (bitrate do MP3):
//   padrao = 128 kbps (comportamento e preço ATUAIS — retrocompat total) · hd = 192 kbps.
// Os tiers vivem em capabilities.kie.qualities — o MESMO contêiner que imagem/vídeo v2 usam — pra
// reusar TODO o plumbing (GenModelResource expõe key/label/p; videoQuality() resolve o tier; o
// `extra` com o output_format real é INTERNO, white-label #6). Idempotente e não-destrutivo:
// se o slug já existe, só garante os tiers (preserva a calibração do operador).
return new class extends Migration
{
    public function up(): void
    {
        $qualities = [
            ['key' => 'padrao', 'label' => 'Padrão', 'p' => 1, 'extra' => ['output_format' => 'mp3_44100_128']],
            ['key' => 'hd', 'label' => 'HD (192k)', 'p' => 2, 'extra' => ['output_format' => 'mp3_44100_192']],
        ];
        $m = DB::table('gen_models')->where('slug', 'audio-narracao')->first();
        if ($m) {
            $caps = json_decode($m->capabilities ?: '{}', true) ?: [];
            $caps['kie'] = $caps['kie'] ?? [];
            $caps['kie']['qualities'] = $qualities;
            $caps['kie']['default_quality'] = $caps['kie']['default_quality'] ?? 'padrao';
            DB::table('gen_models')->where('id', $m->id)->update(['capabilities' => json_encode($caps), 'updated_at' => now()]);

            return;
        }
        DB::table('gen_models')->insert([
            'slug' => 'audio-narracao',
            'display_name' => 'Narração',
            'kind' => 'audio',
            'subtype' => 'text_to_speech',
            'provider' => 'elevenlabs',
            'provider_model_id' => 'eleven_multilingual_v2',
            'cost_credits' => 1, // = preço da qualidade default — o 1 crédito fixo já praticado
            'cost_basis_micro' => 30000, // ~US$0,03/narração de cena (~150 caracteres a ~US$0,20/1k)
            'capabilities' => json_encode([
                'task_types' => ['Text to Speech'],
                'kie' => ['qualities' => $qualities, 'default_quality' => 'padrao'],
            ]),
            'is_active' => true,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Remove só os tiers (não apaga o modelo — o operador pode tê-lo calibrado no Filament).
        $m = DB::table('gen_models')->where('slug', 'audio-narracao')->first();
        if (! $m) {
            return;
        }
        $caps = json_decode($m->capabilities ?: '{}', true) ?: [];
        unset($caps['kie']['qualities'], $caps['kie']['default_quality']);
        DB::table('gen_models')->where('id', $m->id)->update(['capabilities' => json_encode($caps), 'updated_at' => now()]);
    }
};
