<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// HIGGSFIELD ganha VÍDEO e ÁUDIO no catálogo (2026-08-02, pedido do Luciano). Até aqui a conta
// de assinatura Plus só servia IMAGEM (img-higgsfield-nano2 / -soul) — o sidecar tools/cli-bridge
// passou a expor também POST /v1/generate-video e POST /v1/generate-audio (ver media.go).
//
// Por que migration e não só o seeder: o CurrentModelsSeeder é NÃO-DESTRUTIVO de propósito
// (cost_credits/is_active/sort_order só entram no 1º insert, pra não sobrescrever a calibração que
// o operador faz no Filament). Em base que já existe, linha nova só entra com insert explícito.
//
// job_type FIXO por adapter no bridge — o console manda só o provider_model_id:
//   higgsfield-cinema → cinematic_studio_3_0 (vídeo COM trilha, generate_audio=true)
//   higgsfield-tts    → text2speech_v2       (narração/TTS)
//
// PREÇO — a Higgsfield cobra em créditos PRÓPRIOS do plano, não em USD; a equivalência é escolha
// nossa e está registrada aqui pra poder ser recalibrada contra a fatura:
//   custo medido (`higgsfield generate cost`, 2026-08-02): vídeo 5s/720p = 25 créd HF;
//   narração curta = 0,15 créd HF.
//   equivalência ADOTADA: US$0,02 por crédito HF (conservadora — o plano dá ~1000 créd/mês e
//   ninguém conferiu a fatura ainda; subcobrar é denial-of-wallet).
//   ⇒ vídeo: 25 × US$0,02 = US$0,50 ÷ US$0,005 = 100 créditos Reachyn (acima do vid-premium/60).
//   ⇒ narração: US$0,003 → abaixo do piso do bucket 'audio' ⇒ 1 crédito, igual à audio-narracao.
//
// ⚠️ Estes dois modelos só GERAM de fato depois de (a) deploy do bridge com media.go e (b) o
// engine passar a rotear provider "cli-bridge" no caminho de clipe/narração — hoje o
// clipModelOrdered só conhece "kie" e "minimax", e não existe cliente de áudio via bridge.
// Por isso entram INATIVOS: aparecer no select antes de funcionar seria prometer o que não
// entrega. Ativar no Filament quando o roteamento do engine estiver no ar.
return new class extends Migration
{
    public function up(): void
    {
        DB::table('gen_models')->updateOrInsert(
            ['slug' => 'vid-higgsfield-cinema'],
            [
                'display_name' => 'Cinema (com áudio)',
                'kind' => 'video',
                'subtype' => 'image_to_video',
                'provider' => 'cli-bridge',
                'provider_model_id' => 'higgsfield-cinema',
                'cost_credits' => 100,
                'cost_basis_micro' => 500000,
                // MEDIDO ponta a ponta pelo bridge: 9:16/4s/480p → mp4 h264+aac 496x864 em 3m27s.
                // async obrigatório: o corte de ~100s do Cloudflare mataria o síncrono com 524.
                'capabilities' => json_encode([
                    'task_types' => ['Text to Video', 'Image to Video'],
                    'audio' => true,
                    'durations' => [4, 5, 6, 8, 10, 15],
                    'upstream' => 'Higgsfield',
                    'async' => true,
                ]),
                'is_active' => false,
                'min_plan' => null,
                'sort_order' => 7,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        DB::table('gen_models')->updateOrInsert(
            ['slug' => 'audio-higgsfield-tts'],
            [
                'display_name' => 'Narração (assinatura)',
                'kind' => 'audio',
                'subtype' => 'text_to_speech',
                'provider' => 'cli-bridge',
                'provider_model_id' => 'higgsfield-tts',
                'cost_credits' => 1,
                'cost_basis_micro' => 3000,
                'capabilities' => json_encode([
                    'task_types' => ['Text to Speech'],
                    'upstream' => 'Higgsfield',
                    'max_chars' => 10000, // limite da variante minimax (regra do próprio modelo)
                ]),
                'is_active' => false,
                'min_plan' => null,
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('gen_models')->whereIn('slug', ['vid-higgsfield-cinema', 'audio-higgsfield-tts'])->delete();
    }
};
