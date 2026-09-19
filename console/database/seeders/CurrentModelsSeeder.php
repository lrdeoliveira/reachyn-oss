<?php

namespace Database\Seeders;

use App\Models\GenModel;
use Illuminate\Database\Seeder;

/**
 * Modelos que JÁ GERAM hoje (providers de produção: cli-bridge/Higgsfield, MiniMax, Google,
 * ElevenLabs, Magnific) — entram no catálogo `gen_models` ATIVOS e PRECIFICADOS. É a Fase 1 do
 * seletor de modelo: dar escolha real usando o que já roda.
 *
 * ⚠️ Este seeder tem que concordar com as MIGRATIONS de catálogo. Depois da saída do agregador
 * (2026-08-03) ele ficou descrevendo o mundo velho e um `db:seed` religava motores mortos —
 * corrigido em 2026-08-04. Ao mexer numa linha aqui, confira o estado VIVO em produção antes
 * (guideline #4): o catálogo real mora no banco, não neste arquivo.
 *
 * Custos (cost_credits) = custo real do provedor ÷ US$0,005/crédito (ver docs/custos-e-planos.md §9.2).
 * A margem vive no PREÇO de venda do crédito ($0,00833), não aqui. cost_basis_micro = custo upstream
 * real em micro-USD (interno, p/ margem).
 *
 * provider_model_id = a CHAVE que o engine já entende no roteamento atual:
 *  - vídeo Google: veo  (engine roteia pra VeoGoogle)
 *  - imagem: image-01 (MiniMax t2i) | nano-banana-2 (KIE i2i)
 * O console resolve slug -> provider/provider_model_id server-side; o cliente só manda o slug.
 *
 * Idempotente (updateOrCreate por slug). White-label: display_name sem citar o provedor.
 */
class CurrentModelsSeeder extends Seeder
{
    public function run(): void
    {
        $models = [
            // ---------------- VÍDEO ----------------
            [
                'slug' => 'vid-premium', 'display_name' => 'Premium (com áudio)', 'kind' => 'video',
                'subtype' => 'image_to_video', 'provider' => 'google', 'provider_model_id' => 'veo',
                'cost_credits' => 60, 'cost_basis_micro' => 300000, // US$0,30 (veo3_fast 720p/8s via agregador; era US$1,20 direto — migrado 2026-07-09)
                'capabilities' => ['task_types' => ['Text to Video', 'Image to Video'], 'audio' => true, 'durations' => [8], 'upstream' => 'Google'],
                // 2026-07-12: SEM min_plan — custo é por crédito (60), exclusividade caiu. O bucket
                // 'veo' por plano acompanha o teto de 'video' (anti-abuso, não exclusividade).
                'is_active' => true, 'min_plan' => null, 'sort_order' => 4,
            ],
            [
                // 🎬 LIP SYNC (talking-head): imagem-retrato + áudio → clipe com a BOCA sincronizada
                // com a fala. Usado no Estúdio de Animação nas cenas com diálogo (no lugar do i2v
                // mudo + áudio colado por cima). Provider KIE (schema: refs_field=image_url +
                // audio_field=audio_url). Validado e2e 2026-07-13 (clipe 4.6s, boca em sincronia).
                // ⚠️ 2026-08-04: era provider 'kie' e is_active=true. A saída do agregador
                // (migration saida_do_agregador_kie) moveu o MESMO OmniHuman 1.5 pro Magnific e
                // deixou DESLIGADO até a MAGNIFIC_API_KEY existir. O seeder ficou descrevendo o
                // mundo velho — rodar `db:seed` religava um motor que ninguém pode chamar, com
                // preço e ativo. Seeder que contradiz migration é uma bomba de relógio.
                'slug' => 'vid-lipsync', 'display_name' => 'Fala sincronizada', 'kind' => 'video',
                'subtype' => 'image_to_video', 'provider' => 'magnific', 'provider_model_id' => 'omni-human-1-5',
                'cost_credits' => 200, 'cost_basis_micro' => 1000000,
                'capabilities' => ['task_types' => ['Lip Sync'], 'upstream' => 'Magnific', 'magnific' => [
                    'image_field' => 'image', 'audio_field' => 'audio',
                ]],
                'is_active' => false, 'min_plan' => null, 'sort_order' => 5,
            ],

            [
                // 🧩 MODO SIMPLES: Hailuo 2.3 DIRETO na conta MiniMax pré-paga — o caminho de vídeo
                // que NÃO depende de saldo do agregador (contingência do 402 de 2026-07-17). i2v
                // (first_frame) e t2v; SEM 1º+último frame nem multi_shots → no Filme cai no modo
                // corrente. Durações reais da API: 6 ou 10s/768P (o engine clampa o 5s do Filme).
                // ⚠️ A conta MiniMax comporta POUCOS vídeos por dia — caminho de contingência/uso
                // leve, não de escala.
                'slug' => 'vid-simples', 'display_name' => 'Simples', 'kind' => 'video',
                'subtype' => 'image_to_video', 'provider' => 'minimax', 'provider_model_id' => 'MiniMax-Hailuo-2.3',
                'cost_credits' => 30, 'cost_basis_micro' => 150000, // ~US$0,15 (768P/6s, preço direto)
                'capabilities' => ['task_types' => ['Text to Video', 'Image to Video'], 'durations' => [6, 10], 'upstream' => 'MiniMax'],
                'is_active' => true, 'sort_order' => 6,
            ],
            [
                // 🎬 HIGGSFIELD CINEMA via CLI BRIDGE (2026-08-02, pedido do Luciano: vídeo E áudio
                // dele, no mesmo sidecar que já servia imagem). job_type cinematic_studio_3_0 FIXO
                // no adapter do bridge (tools/cli-bridge/media.go), endpoint POST /v1/generate-video.
                // Gera TRILHA JUNTO (generate_audio=true) — clipe já sai com som, não é i2v mudo.
                //
                // MEDIDO na VPS em 2026-08-02, ponta a ponta pelo bridge: 9:16, 4s, 480p → mp4
                // h264+aac 496x864, 4,06s, em 3m27s. Por isso async: o corte de ~100s do Cloudflare
                // mataria o caminho síncrono com 524 num vídeo que na verdade foi gerado.
                //
                // ⚠️ PREÇO — a Higgsfield cobra em CRÉDITOS PRÓPRIOS do plano Plus, não em USD, então
                // a equivalência é uma ESCOLHA nossa e está documentada aqui:
                //   custo real medido (`higgsfield generate cost`, 2026-08-02):
                //     5s/480p = 17,5 · 5s/720p = 25 · 5s/1080p = 50 · 10s/720p = 50 créditos HF
                //   equivalência adotada: US$0,02 por crédito HF ⇒ 25 créd HF (o default 5s/720p)
                //   ≈ US$0,50 ⇒ 100 créditos Reachyn (÷ US$0,005, a fórmula do UsageService).
                // A taxa de US$0,02/créd HF é CONSERVADORA de propósito: o plano Plus dá ~1000
                // créditos/mês e ninguém conferiu a fatura ainda, então preferimos superestimar —
                // subcobrar aqui é denial-of-wallet (mesma régua do vid-lipsync). Fica ACIMA do
                // vid-premium (60) e do vid-simples (30); se a fatura mostrar que dá pra baixar, o
                // ajuste é no Filament (o seeder não sobrescreve calibração do operador).
                // cost_basis_micro = 500000 (US$0,50) — é o custo de OPORTUNIDADE do crédito HF, não
                // uma cobrança em dinheiro; a conta de assinatura já está paga.
                'slug' => 'vid-higgsfield-cinema', 'display_name' => 'Cinema (com áudio)', 'kind' => 'video',
                'subtype' => 'image_to_video', 'provider' => 'cli-bridge', 'provider_model_id' => 'higgsfield-cinema',
                'cost_credits' => 100, 'cost_basis_micro' => 500000,
                'capabilities' => ['task_types' => ['Text to Video', 'Image to Video'], 'audio' => true,
                    'durations' => [4, 5, 6, 8, 10, 15], 'upstream' => 'Higgsfield', 'async' => true],
                // ATIVO desde que o engine passou a rotear "cli-bridge" no clipe (clipModelOrdered
                // chama cliBridgeClip). O comentário anterior ainda dizia "inativo até o engine
                // rotear cli-bridge" muito depois de o engine rotear — e em prod a linha já estava
                // ativa. Comentário que descreve um mundo que não existe manda quem lê investigar
                // o lugar errado.
                'is_active' => true, 'min_plan' => null, 'sort_order' => 7,
            ],

            // ---------------- IMAGEM ----------------
            // NOMENCLATURA (2026-07-20, ordem do Luciano): os modelos de imagem passam a
            // se chamar pelo nome REAL no select, em vez do rótulo white-label "Studio X".
            // Motivo: hoje só ele usa o Reachyn, e "Studio B" ao lado de "Imagem" escondia
            // que eram o MESMO modelo por baixo. ⚠️ Isso contraria a guideline #6
            // (white-label) — se entrar cliente, reverter os display_name aqui.
            [
                // ⚠️ DESATIVADO em 2026-07-20: é o MESMO image-01 que o img-cli-mmx roda,
                // só que pela API paga (US$0,0035/img) em vez da CLI de assinatura (custo
                // marginal zero). Mantido no catálogo, e não removido, porque não depende
                // do sidecar: se o bridge cair, reativar aqui devolve t2i na hora.
                'slug' => 'img-padrao', 'display_name' => 'image-01 (API)', 'kind' => 'image',
                'subtype' => 'text_to_image', 'provider' => 'minimax', 'provider_model_id' => 'image-01',
                'cost_credits' => 2, 'cost_basis_micro' => 3500, // US$0,0035/img
                'capabilities' => ['task_types' => ['Text to Image'], 'upstream' => 'MiniMax'],
                'is_active' => false, 'sort_order' => 3,
            ],
            [
                // i2i (ancoragem de personagem) — este registro é a COBRANÇA desse i2i.
                //
                // ⚠️ 2026-08-04: era provider 'kie' + nano-banana-2 e ATIVO; a saída do agregador
                // desativou a linha em prod, mas o seeder continuava religando. O MESMO modelo
                // segue em uso — o que mudou foi a CONTA: o i2i ancorado roda pelo adapter
                // higgsfield do cli-bridge (ver `i2iAncorado` no engine e docs/SAIDA-DO-AGREGADOR.md).
                //
                // async: o i2i ancorado leva ~100s, colado no corte do Cloudflare — por isso a
                // flag, que manda a tela síncrona recusar e enfileirar pela Mídia do Estúdio.
                'slug' => 'img-referencia', 'display_name' => 'Referência (ancoragem)', 'kind' => 'image',
                'subtype' => 'image_to_image', 'provider' => 'cli-bridge', 'provider_model_id' => 'higgsfield',
                'cost_credits' => 6, 'cost_basis_micro' => 30000,
                'capabilities' => ['task_types' => ['Image to Image'], 'upstream' => 'Higgsfield', 'async' => true, 'refs' => true],
                'is_active' => true, 'sort_order' => 1,
            ],
            [
                // Motor de imagem via CLI BRIDGE (tools/cli-bridge — sidecar systemd no host da
                // VPS que executa a CLI mmx com a conta OAuth de assinatura; a API key do engine
                // não cobre imagem). provider_model_id = adapter no bridge.
                // Nome real no select desde 2026-07-20 (era "Studio B") — ver nota de
                // nomenclatura no topo do bloco IMAGEM. É o t2i PADRÃO agora: mesmo modelo
                // do img-padrao (image-01), pela conta de assinatura em vez da API paga.
                //
                // ABERTO A TODOS OS PLANOS (min_plan null) desde 2026-07-20 — antes era
                // min_plan=unlimited (exclusivo RedFox). Motivo: é geração de custo marginal
                // ZERO (conta de assinatura), e passa a ser a opção padrão de t2i ao lado do
                // KIE, que cobra por chamada. Com o KIE sem saldo, é o caminho que mantém a
                // geração de imagem viva.
                // ⚠️ CAPACIDADE: a assinatura é UMA conta e o bridge roda BRIDGE_CONCURRENCY
                // jobs em paralelo (default 2). Com muitos clientes simultâneos a fila cresce e
                // o bridge devolve 429 ("bridge ocupado") em vez de enfileirar sem limite —
                // preferimos recusar rápido a acumular processo de CLI no host. Se virar
                // gargalo, subir BRIDGE_CONCURRENCY (teto 8) antes de mexer no código.
                'slug' => 'img-cli-mmx', 'display_name' => 'mmx', 'kind' => 'image',
                'subtype' => 'text_to_image', 'provider' => 'cli-bridge', 'provider_model_id' => 'mmx',
                'cost_credits' => 2, 'cost_basis_micro' => 0, // conta de assinatura — custo marginal zero
                // async desde 2026-07-20: com a resolução em 2048x1152 o 16:9 subiu pra ~75s,
                // a só ~25s do corte de ~100s do Cloudflare. Não vale apostar na margem —
                // 524 intermitente é pior que polling.
                'capabilities' => ['task_types' => ['Text to Image'], 'upstream' => 'MiniMax', 'async' => true],
                'is_active' => true, 'sort_order' => 0, 'min_plan' => null,
            ],
            [
                // Segundo motor via CLI BRIDGE — o agente `cursor` tem tool de geração de
                // imagem. Validado na VPS em 2026-07-20 (PNG 1536x1024 real).
                //
                // Diferenças pro mmx, que justificam ser um registro separado:
                //   - t2i APENAS: não tem subject-ref, então não serve pra ancoragem de
                //     personagem. O bridge recusa ref_urls com erro claro.
                //   - é um AGENTE: não tem flag de saída; grava o arquivo no workdir do job
                //     e o bridge varre atrás dele (imgAdapter.discover).
                //   - LENTO: 110-145s por imagem (medido em prod), contra ~5-20s do mmx. Por isso
                //     `async: true` — passa pelo GenerateImageJob em vez do caminho síncrono,
                //     senão o Cloudflare corta em ~100s com 524 e o usuário vê erro numa imagem
                //     que na verdade foi gerada e salva.
                //
                // Custo marginal zero (mesma conta de assinatura) — por isso cost_credits
                // acompanha o mmx. ⚠️ Divide o mesmo BRIDGE_CONCURRENCY: dois motores no
                // mesmo semáforo, a fila é comum.
                'slug' => 'img-cli-cursor', 'display_name' => 'cursor', 'kind' => 'image',
                'subtype' => 'text_to_image', 'provider' => 'cli-bridge', 'provider_model_id' => 'cursor',
                'cost_credits' => 2, 'cost_basis_micro' => 0,
                'capabilities' => ['task_types' => ['Text to Image'], 'async' => true],
                'is_active' => true, 'sort_order' => 1, 'min_plan' => null,
            ],
            [
                // HIGGSFIELD via CLI BRIDGE (2026-08-01, pedido do Luciano: Higgsfield como
                // opção de IA ao lado do KIE). CLI oficial `higgsfield` no host (OAuth PKCE,
                // conta de assinatura Plus → custo marginal zero, mesma régua do mmx).
                // job_type nano_banana_flash (Nano Banana 2) FIXO no adapter do bridge —
                // t2i E i2i (--image-references), com 4:5 NATIVO (o mmx degrada pra 3:4).
                // Medido ~52s/imagem no bridge → async (mesma regra dos 100s do Cloudflare).
                // ⚠️ Requisito no host: higgsfield auth login + workspace set (docs/PLANO-CLI-IMAGE-BRIDGE.md).
                'slug' => 'img-higgsfield-nano2', 'display_name' => 'Nano Banana 2 (Higgsfield)', 'kind' => 'image',
                'subtype' => 'text_to_image', 'provider' => 'cli-bridge', 'provider_model_id' => 'higgsfield',
                'cost_credits' => 2, 'cost_basis_micro' => 0,
                'capabilities' => ['task_types' => ['Text to Image', 'Image to Image'], 'upstream' => 'Higgsfield', 'async' => true],
                'is_active' => true, 'sort_order' => 2, 'min_plan' => null,
            ],
            [
                // Soul 2.0 — o modelo-assinatura da Higgsfield pra retrato/realismo editorial.
                // Mesmo bridge/conta do nano2; registro separado porque é OUTRO look (não é
                // tier do mesmo modelo) e o cliente escolhe pelo resultado, não pelo preço.
                'slug' => 'img-higgsfield-soul', 'display_name' => 'Soul 2.0 (Higgsfield)', 'kind' => 'image',
                'subtype' => 'text_to_image', 'provider' => 'cli-bridge', 'provider_model_id' => 'higgsfield-soul',
                'cost_credits' => 2, 'cost_basis_micro' => 0,
                'capabilities' => ['task_types' => ['Text to Image', 'Image to Image'], 'upstream' => 'Higgsfield', 'async' => true],
                'is_active' => true, 'sort_order' => 3, 'min_plan' => null,
            ],

            // ---------------- ÁUDIO ----------------
            [
                // Narração/TTS como modelo de catálogo (qualidade = bitrate do MP3, tiers em
                // capabilities.elevenlabs.qualities — ver migration audio_narration_model_tiers).
                // padrao (128k) = comportamento/preço históricos; hd (192k) = tier novo.
                'slug' => 'audio-narracao', 'display_name' => 'Narração', 'kind' => 'audio',
                'subtype' => 'text_to_speech', 'provider' => 'elevenlabs', 'provider_model_id' => 'eleven_multilingual_v2',
                'cost_credits' => 1, 'cost_basis_micro' => 30000, // ~US$0,03/narração de cena
                'capabilities' => ['task_types' => ['Text to Speech'], 'elevenlabs' => [
                    'qualities' => [
                        ['key' => 'padrao', 'label' => 'Padrão', 'p' => 1, 'extra' => ['output_format' => 'mp3_44100_128']],
                        ['key' => 'hd', 'label' => 'HD (192k)', 'p' => 2, 'extra' => ['output_format' => 'mp3_44100_192']],
                    ],
                    'default_quality' => 'padrao',
                ]],
                'is_active' => true, 'sort_order' => 0,
            ],
            [
                // 🎙️ NARRAÇÃO HIGGSFIELD via CLI BRIDGE (2026-08-02) — job_type text2speech_v2 FIXO
                // no adapter (endpoint POST /v1/generate-audio). Alternativa de assinatura à
                // audio-narracao (ElevenLabs API paga): mesma função, outra conta.
                //
                // MEDIDO ponta a ponta pelo bridge em 2026-08-02: texto em PT (60 chars) → mp3
                // 149 kbps, 5,26s de áudio, em 5,3s de parede. Síncrono cabe folgado.
                // ⚠️ O áudio saiu com a voz preset "John" e NINGUÉM OUVIU ainda — o que está
                // provado é que o mp3 é válido e tem a duração certa, não que a prosódia em PT
                // esteja boa. Antes de virar padrão de narração, escutar. Se a voz não servir pro
                // PT, trocar o voice_id (higgsfield voices list) ou o job_type pro
                // inworld_text_to_speech, que tem vozes PT nomeadas ("Heitor", "Maitê").
                //
                // ⚠️ PREÇO — custo real medido: 0,15 crédito HF por narração curta. Na mesma
                // equivalência do vídeo (US$0,02/créd HF) dá US$0,003 ⇒ 0,6 crédito Reachyn, abaixo
                // do piso de 1 do bucket 'audio' no UsageService. Adotado cost_credits = 1: igual à
                // audio-narracao, nunca menos que o custo.
                'slug' => 'audio-higgsfield-tts', 'display_name' => 'Narração (assinatura)', 'kind' => 'audio',
                'subtype' => 'text_to_speech', 'provider' => 'cli-bridge', 'provider_model_id' => 'higgsfield-tts',
                'cost_credits' => 1, 'cost_basis_micro' => 3000, // US$0,003 = 0,15 créd HF × US$0,02
                'capabilities' => ['task_types' => ['Text to Speech'], 'upstream' => 'Higgsfield',
                    'max_chars' => 10000],
                // ⚠️ INATIVO pelo mesmo motivo do vídeo: não existe cliente de áudio via bridge
                // no engine ainda (provider/speech só fala ElevenLabs). Ativar no Filament quando
                // o roteamento estiver no ar.
                'is_active' => false, 'min_plan' => null, 'sort_order' => 1,
            ],
        ];

        // Não-destrutivo: identidade/roteamento sempre atualizados (seeder = fonte), mas a CALIBRAÇÃO
        // do operador (cost_credits, is_active, sort_order) só no 1º insert — re-seed não sobrescreve
        // o preço/ativo ajustado no Filament.
        foreach ($models as $m) {
            $gm = GenModel::firstOrNew(['slug' => $m['slug']]);
            $gm->fill([
                'display_name' => $m['display_name'],
                'kind' => $m['kind'],
                'subtype' => $m['subtype'],
                'provider' => $m['provider'],
                'provider_model_id' => $m['provider_model_id'],
                'capabilities' => $m['capabilities'],
                'min_plan' => $m['min_plan'] ?? null,
            ]);
            if (! $gm->exists) {
                $gm->cost_credits = $m['cost_credits'];
                $gm->cost_basis_micro = $m['cost_basis_micro'];
                $gm->is_active = $m['is_active'];
                $gm->sort_order = $m['sort_order'];
            }
            $gm->save();
        }
    }
}
