<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Narração passa a usar o Eleven v3 — o modelo mais expressivo da ElevenLabs, já liberado na
 * conta (a API reporta requires_alpha_access=false).
 *
 * Por que trocar: no projeto 20 a queixa foi "as falas estão ruins". Parte era mixagem (corrigida
 * à parte), parte era o modelo: o multilingual_v2 entrega a emoção só por voice_settings
 * (stability/style), então uma direção de cena como "fascínio sussurrado, respiração contida"
 * virava no máximo um número. O v3 aceita audio tags no texto ("[whispers] …") e atua de verdade.
 *
 * ⚠️ Contrato DIFERENTE, não é só trocar o id: o v3 reporta can_use_style=false e a tag só vale
 * para ele — medido contra a API, mandar "[whispers]" para o multilingual_v2 aumenta a fala em 2s
 * porque ele LÊ a palavra em voz alta. A conversão vive no ffmpeg-service (tts_text_for_model /
 * tts_settings_for_model), que é quem conhece o tts_model.
 *
 * O modelo antigo continua no catálogo, inativo, para poder voltar sem migration nova.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Idempotente: se já existir (re-run), só reativa e reordena.
        $existe = DB::table('gen_models')->where('slug', 'audio-narracao-v3')->exists();
        if (! $existe) {
            $base = DB::table('gen_models')->where('slug', 'audio-narracao')->first();
            DB::table('gen_models')->insert([
                'slug' => 'audio-narracao-v3',
                'display_name' => 'Narração expressiva (v3)',
                'kind' => 'audio',
                'subtype' => 'text_to_speech',
                'provider' => 'elevenlabs',
                'provider_model_id' => 'eleven_v3',
                'cost_credits' => $base->cost_credits ?? 1,
                'cost_basis_micro' => $base->cost_basis_micro ?? 30000,
                'capabilities' => $base->capabilities ?? null,
                'is_active' => true,
                'sort_order' => 0,   // vira o default do fluxo (kind=audio ordenado por sort_order)
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            DB::table('gen_models')->where('slug', 'audio-narracao-v3')
                ->update(['is_active' => true, 'sort_order' => 0, 'updated_at' => now()]);
        }

        // O v2 sai da frente, mas continua disponível para voltar atrás sem nova migration.
        DB::table('gen_models')->where('slug', 'audio-narracao')
            ->update(['sort_order' => 1, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('gen_models')->where('slug', 'audio-narracao')->update(['sort_order' => 0, 'updated_at' => now()]);
        DB::table('gen_models')->where('slug', 'audio-narracao-v3')->update(['is_active' => false, 'sort_order' => 2, 'updated_at' => now()]);
    }
};
