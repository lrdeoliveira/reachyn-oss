<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Abre o catálogo Higgsfield INTEIRO: insere os 9 modelos que a conta passou a oferecer e liga
 * os 19 que estavam parados como [rascunho] — inclusive os utilitários de pós-produção.
 *
 * ── O QUE ACONTECEU ANTES DISTO ────────────────────────────────────────────────────────────
 * Em 04/08 o sidecar do bridge migrou do Mac para a VPS, mas a credencial OAuth da CLI não foi
 * junto: `/root/.config/higgsfield/credentials.json` simplesmente não existia na máquina. O
 * sintoma era 502 "catálogo indisponível" em TODA geração Higgsfield — 44 modelos ativos
 * apontando para um caminho que não autenticava, sem log e sem alarme. A credencial foi
 * instalada em 29/08 e agora um keep-alive horário (/usr/local/bin/higgsfield-keepalive.sh,
 * cron 17 * * * *) mantém o refresh em dia e faz a falha aparecer no syslog em vez de aparecer
 * na tela de um cliente. Só DEPOIS disso ligar modelo novo passou a fazer sentido.
 *
 * ── O QUE ENTRA ────────────────────────────────────────────────────────────────────────────
 * Só o que o bridge CONSEGUE EXECUTAR hoje: os 68 job_types que `higgsfield model list` publica
 * e o bridge resolve por schema (higgsmodels.go). A Developer API expõe mais 37 (labels `chain`
 * e `app`: Cinema Studio 4.0, reframe, dubbing, voice_change, speech2text…), mas o bridge não os
 * endereça — cadastrar aqui criaria opção que devolve erro, que é exatamente o defeito que esta
 * migration está fechando. Eles entram junto com o provider que fala direto com a API.
 *
 * ── PREÇO ──────────────────────────────────────────────────────────────────────────────────
 * Por EQUIVALÊNCIA com o que já está ativo e precificado, mesma régua da migration de 04/08:
 *   imagem — rascunho 1 · padrão 2 · edição/estilo 4 · alta fidelidade 8 · topo 12-16
 *   vídeo  — econômico 30 · premium 40 · veo-lite 60 · cinema 90-100
 *   áudio  — fala 1 · efeito 2 · música 4
 * Onde não havia equivalente claro, fica o teto da faixa: errar pra mais protege o crédito da
 * casa, errar pra menos vira denial-of-wallet (guideline #11).
 *
 * ── UTILITÁRIOS ────────────────────────────────────────────────────────────────────────────
 * A migration de 04/08 deixou upscale/remove-bg/outpaint/deflicker de fora de propósito, porque
 * no seletor de GERAÇÃO eles devolvem a peça errada. A decisão agora é disponibilizar tudo — mas
 * marcados com `subtype = 'postproducao'`, para que o front os coloque na pós-produção da galeria
 * e NÃO na lista de "gerar uma peça". O dado carrega a distinção; a tela obedece.
 *
 * ── ELEVENLABS ─────────────────────────────────────────────────────────────────────────────
 * Intocado. `provider = 'elevenlabs'` (narração, incl. Eleven v3) é caminho próprio, não passa
 * pelo bridge, e NENHUM update aqui o alcança — todo WHERE abaixo fixa provider='cli-bridge'.
 *
 * Reversível: o down() remove os inseridos e devolve os demais a [rascunho].
 */
return new class extends Migration
{
    /** Novos no upstream. slug => [job_type, kind, custo, subtype|null, refs, aspects, sort] */
    private const NOVOS = [
        // ── imagem ────────────────────────────────────────────────────────────────────────
        'hf-grok-image-2-0' => ['grok_image_2_0', 'image', 4, null, true, 10, 26],
        'hf-nano-banana-2-relight' => ['nano_banana_2_relight', 'image', 8, null, true, 0, 27],
        // Outpaint expande enquadramento a partir de uma imagem: é pós-produção, não geração.
        'hf-flux-2-pro-outpaint' => ['flux_2_pro_outpaint', 'image', 4, 'postproducao', true, 0, 28],

        // ── vídeo ─────────────────────────────────────────────────────────────────────────
        'hf-seedance-2-5' => ['seedance_2_5', 'video', 90, null, true, 7, 34],   // sucessor do 2.0 (90)
        'hf-wan3-0' => ['wan3_0', 'video', 40, null, true, 6, 31],         // acima do wan2-7 (30)
        'hf-wan3-0-prime' => ['wan3_0_prime', 'video', 60, null, true, 6, 32],   // topo da linha Wan
        'hf-gemini-omni-flash-1-1' => ['gemini_omni_flash_1_1', 'video', 40, null, true, 2, 35],
        'hf-flux-3-video' => ['flux_3_video', 'video', 40, null, true, 8, 36],
        'hf-ad-multiplier' => ['ad_multiplier', 'video', 40, null, true, 7, 37],
    ];

    /** Já existiam como [rascunho]. slug => [custo, subtype|null] */
    private const RASCUNHOS = [
        // ── áudio: fala 1 · efeito 2 · música 4 ───────────────────────────────────────────
        'hf-text2speech-v2' => [1, null],
        'hf-inworld-text-to-speech' => [1, null],
        'hf-qwen-audio-tts' => [1, null],
        'hf-mirelo-text-to-audio' => [2, null],   // efeito sonoro
        'hf-sonilo-music' => [4, null],   // música

        // ── imagem ────────────────────────────────────────────────────────────────────────
        'hf-nano-banana-2-shots' => [8, null],   // família Pro (8)

        // ── vídeo ─────────────────────────────────────────────────────────────────────────
        'hf-clipify' => [40, null],  // corta clipes a partir de um vídeo longo

        // ── pós-produção: partem de uma peça pronta, não geram do zero ────────────────────
        'hf-image-background-remover' => [1, 'postproducao'],
        'hf-outpaint' => [2, 'postproducao'],
        'hf-bytedance-image-upscale' => [2, 'postproducao'],
        'hf-topaz-image' => [4, 'postproducao'],
        'hf-topaz-image-generative' => [4, 'postproducao'],
        'hf-sam-3-video' => [10, 'postproducao'],
        'hf-video-background-remover' => [10, 'postproducao'],
        'hf-video-deflicker' => [10, 'postproducao'],
        'hf-video-upscale' => [20, 'postproducao'],
        'hf-bytedance-video-upscale' => [20, 'postproducao'],
        'hf-topaz-video' => [20, 'postproducao'],
    ];

    public function up(): void
    {
        $agora = now();

        foreach (self::NOVOS as $slug => [$jobType, $kind, $custo, $subtype, $refs, $aspects, $sort]) {
            // updateOrInsert: rodar duas vezes não duplica, e um slug ajustado à mão no Filament
            // recebe o mesmo estado declarado aqui (esta migration é a fonte para eles).
            DB::table('gen_models')->updateOrInsert(
                ['slug' => $slug],
                [
                    'display_name' => self::nomePublico($jobType),
                    'kind' => $kind,
                    'subtype' => $subtype,
                    'provider' => 'cli-bridge',
                    'provider_model_id' => 'higgsfield:'.$jobType,
                    'cost_credits' => $custo,
                    'capabilities' => json_encode([
                        'refs' => $refs,
                        'async' => true,
                        'aspects' => $aspects,
                        'upstream' => 'Higgsfield',
                    ]),
                    'is_active' => true,
                    'min_plan' => null,
                    'sort_order' => $sort,
                    'created_at' => $agora,
                    'updated_at' => $agora,
                ]
            );
        }

        foreach (self::RASCUNHOS as $slug => [$custo, $subtype]) {
            DB::table('gen_models')
                ->where('slug', $slug)
                ->where('provider', 'cli-bridge')   // trava: elevenlabs/minimax/google fora
                ->update([
                    'is_active' => true,
                    'cost_credits' => $custo,
                    'subtype' => $subtype,
                    // Tira o "[rascunho]" — ele era o aviso de que ninguém devia escolher isto.
                    'display_name' => DB::raw("trim(replace(display_name, '[rascunho]', ''))"),
                    'updated_at' => $agora,
                ]);
        }

        // `llm_text` entrou com kind='video' num seed em lote e ficou assim: é geração de TEXTO,
        // e num seletor de vídeo devolveria texto para quem pediu um clipe. Fica de fora do ar
        // até ter destino próprio, mas ao menos classificado certo.
        DB::table('gen_models')
            ->where('slug', 'hf-llm-text')
            ->where('provider', 'cli-bridge')
            ->update(['kind' => 'text', 'updated_at' => $agora]);
    }

    public function down(): void
    {
        DB::table('gen_models')->whereIn('slug', array_keys(self::NOVOS))->delete();

        foreach (array_keys(self::RASCUNHOS) as $slug) {
            DB::table('gen_models')->where('slug', $slug)->where('provider', 'cli-bridge')->update([
                'is_active' => false,
                'cost_credits' => null,
                'subtype' => null,
                'display_name' => DB::raw("'[rascunho] ' || trim(display_name)"),
                'updated_at' => now(),
            ]);
        }

        DB::table('gen_models')->where('slug', 'hf-llm-text')->where('provider', 'cli-bridge')
            ->update(['kind' => 'video', 'updated_at' => now()]);
    }

    /** Nome PÚBLICO (white-label, guideline #6): nunca cita o provedor de IA. */
    private static function nomePublico(string $jobType): string
    {
        return match ($jobType) {
            'grok_image_2_0' => 'Ilustração 2.0',
            'nano_banana_2_relight' => 'Reiluminação',
            'flux_2_pro_outpaint' => 'Expandir enquadramento',
            'seedance_2_5' => 'Cinema 2.5',
            'wan3_0' => 'Movimento 3.0',
            'wan3_0_prime' => 'Movimento 3.0 Prime',
            'gemini_omni_flash_1_1' => 'Clipe com áudio 1.1',
            'flux_3_video' => 'Clipe autoral 3',
            'ad_multiplier' => 'Multiplicador de anúncio',
            default => ucfirst(str_replace('_', ' ', $jobType)),
        };
    }
};
