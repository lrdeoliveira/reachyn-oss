<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SAÍDA DO AGREGADOR (2026-08-03, ordem do Luciano: "vamos remover o kie, e deixar o
 * higgsfield, incluir o magnific e o local mmx").
 *
 * Esta migration é a metade de CATÁLOGO da mudança; a metade de CÓDIGO (remoção do provider no
 * engine) vai no mesmo deploy. A ordem importa: primeiro o catálogo para de oferecer o
 * agregador, depois o código dele sai. Invertido, um cliente clicaria num modelo cujo provider
 * o engine não conhece mais e receberia erro de config.
 *
 * O TAMANHO REAL do problema só apareceu no banco VIVO, não no seeder: 35 modelos ATIVOS
 * dependiam do agregador — 15 de imagem, 12 de vídeo, 3 de edição e 7 de TEXTO. O seeder
 * versionado descrevia uma fração disso. Por isso cada família abaixo tem um sucessor
 * declarado, e não um "desativa tudo e vê no que dá".
 *
 * ── TEXTO ────────────────────────────────────────────────────────────────────────────────
 * Os 7 txt-* eram o SELETOR QUE O CLIENTE VÊ (Fable, Opus, Sonnet, Haiku, Gemini, GPT), todos
 * pelo agregador. Sucessor: a CLI de assinatura no host, via cli-bridge (ver o canal /v1/text
 * em tools/cli-bridge). Sobram DUAS linhas de verdade — `codex` (agente, régua alta) e `mmx`
 * (resposta em segundos). Manter as 7 apontando para os mesmos 2 adapters seria um seletor de
 * mentira: 7 nomes diferentes entregando 2 resultados. As outras 5 saem do ar.
 *
 * ── IMAGEM e VÍDEO ───────────────────────────────────────────────────────────────────────
 * Já têm sucessor NO AR: a fatia curada do Higgsfield (10 de imagem + 4 de vídeo, ativada e
 * precificada em 2026_08_03_120000) mais o mmx e o Cinema. Então aqui é só desativar.
 *
 * ── EDIÇÃO e FALA SINCRONIZADA ───────────────────────────────────────────────────────────
 * Não tinham sucessor nenhum. Passam para o Magnific, que é upscaler de origem e serve o MESMO
 * OmniHuman 1.5 do lipsync antigo. Entram DESLIGADOS com `unstable_reason`: a chave
 * (MAGNIFIC_API_KEY) ainda não existe. Ligar sem chave seria vender o que não entrega — cada
 * clique reservaria crédito, falharia e estornaria. Com a chave no ar, é só o operador ativar
 * no Filament (ou rodar a migration seguinte).
 *
 * Reversível: o down() devolve tudo ao estado anterior, inclusive os provider_model_id de texto.
 */
return new class extends Migration
{
    /** Texto que SOBREVIVE: slug => [adapter no bridge, nome público, créditos, ordem]. */
    private const TEXTO_NOVO = [
        'txt-topo' => ['codex', 'Avançado (assinatura)', 2, 0],
        'txt-rapido' => ['mmx', 'Rápido (assinatura)', 1, 1],
    ];

    /** Texto que sai do ar (o mesmo par de motores por trás — seletor de mentira). */
    private const TEXTO_FORA = [
        'txt-equilibrado', 'txt-versatil', 'txt-criativo', 'txt-profundo', 'txt-avancado',
    ];

    /**
     * Edição e fala sincronizada migradas para o Magnific.
     * slug => [modelo (último segmento do path do endpoint), spec do corpo].
     *
     * O `magnific` da capabilities é o schema do corpo — a API não padroniza params entre
     * endpoints, então o formato vive no catálogo e não no engine (ver os magnific.go do engine).
     */
    private const PARA_MAGNIFIC = [
        'edit-upscale' => ['image-upscaler-precision', ['image_field' => 'image']],
        'edit-upscale-pro' => ['image-upscaler-creative', ['image_field' => 'image']],
        'edit-remove-bg' => ['remove-background', ['image_field' => 'image']],
        'vid-lipsync' => ['omni-human-1-5', [
            'image_field' => 'image', 'audio_field' => 'audio',
        ]],
    ];

    private const MOTIVO_SEM_CHAVE = 'Temporariamente indisponível: motor em configuração.';

    /**
     * Linhas NOVAS do Magnific no catálogo de geração.
     *
     * PREÇO — o Magnific cobra em créditos PRÓPRIOS e ninguém rodou uma fatura ainda, então os
     * valores abaixo são ESTIMATIVA DELIBERADAMENTE ALTA, na mesma régua já adotada para o
     * Higgsfield (2026_08_02_140000): subcobrar é denial-of-wallet, e recalibrar para baixo
     * depois é trivial no Filament. Quando a primeira fatura real existir, ajustar por medição —
     * não por palpite melhorado.
     *
     * White-label (#6): o nome público diz o que o modelo FAZ, nunca quem o fabrica.
     */
    private const MAGNIFIC_NOVOS = [
        'img-mystic' => [
            'nome' => 'Ultrarrealista', 'kind' => 'image', 'subtype' => 'text_to_image',
            'modelo' => 'mystic', 'creditos' => 10, 'base_micro' => 50000, 'ordem' => 40,
            'caps' => ['task_types' => ['Text to Image'], 'upstream' => 'Magnific', 'async' => true,
                'magnific' => ['aspect_field' => 'aspect_ratio']],
        ],
        'img-flux-pro' => [
            'nome' => 'Preciso (nuvem)', 'kind' => 'image', 'subtype' => 'text_to_image',
            'modelo' => 'flux-2-pro', 'creditos' => 8, 'base_micro' => 40000, 'ordem' => 41,
            'caps' => ['task_types' => ['Text to Image', 'Image to Image'], 'upstream' => 'Magnific',
                'async' => true,
                'magnific' => ['aspect_field' => 'aspect_ratio', 'refs_field' => 'reference_images']],
        ],
        'vid-kling-nuvem' => [
            'nome' => 'Clipe premium (nuvem)', 'kind' => 'video', 'subtype' => 'image_to_video',
            'modelo' => 'kling-v2-6-pro', 'creditos' => 120, 'base_micro' => 600000, 'ordem' => 42,
            'caps' => ['task_types' => ['Text to Video', 'Image to Video'], 'upstream' => 'Magnific',
                'async' => true, 'durations' => [5, 10],
                'magnific' => ['aspect_field' => 'aspect_ratio', 'duration_field' => 'duration',
                    'refs_field' => 'image', 'refs_single' => true]],
        ],
    ];

    public function up(): void
    {
        // 1. TEXTO — repontar as duas linhas que ficam para a CLI do host.
        foreach (self::TEXTO_NOVO as $slug => [$adapter, $nome, $creditos, $ordem]) {
            DB::table('gen_models')->where('slug', $slug)->update([
                'display_name' => $nome,
                'provider' => 'cli-bridge',
                'provider_model_id' => $adapter,
                // Conta de assinatura: custo marginal ZERO. O crédito cobrado não é repasse de
                // custo, é limite de uso — a assinatura é uma só e o bridge roda poucos jobs em
                // paralelo (BRIDGE_CONCURRENCY). Cobrar 0 convidaria a esgotar a fila.
                'cost_credits' => $creditos,
                'cost_basis_micro' => 0,
                'is_active' => true,
                'sort_order' => $ordem,
                'unstable_reason' => null,
                'updated_at' => now(),
            ]);
        }

        // 2. TEXTO — as 5 redundantes saem do ar.
        DB::table('gen_models')->whereIn('slug', self::TEXTO_FORA)->update([
            'is_active' => false,
            'updated_at' => now(),
        ]);

        // 3. EDIÇÃO + FALA SINCRONIZADA — para o Magnific, desligadas até a chave existir.
        foreach (self::PARA_MAGNIFIC as $slug => [$modelo, $spec]) {
            $linha = DB::table('gen_models')->where('slug', $slug)->first();
            if (! $linha) {
                continue;
            }
            $caps = json_decode($linha->capabilities ?? '{}', true) ?: [];
            unset($caps['kie']); // o spec do agregador não vale mais nada aqui
            $caps['magnific'] = $spec;
            $caps['upstream'] = 'Magnific';
            DB::table('gen_models')->where('slug', $slug)->update([
                'provider' => 'magnific',
                'provider_model_id' => $modelo,
                'capabilities' => json_encode($caps),
                'is_active' => false,
                'is_unstable' => true,
                'unstable_reason' => self::MOTIVO_SEM_CHAVE,
                'updated_at' => now(),
            ]);
        }

        // 4. TODO o resto do agregador sai do ar. Depois dos passos acima, o que sobra com
        //    provider='kie' é imagem e vídeo — famílias que JÁ têm sucessor ativo (Higgsfield
        //    curado + mmx + Cinema + Veo). Desativar, e não apagar: a linha guarda o histórico
        //    de preço e o down() religa.
        DB::table('gen_models')->where('provider', 'kie')->where('is_active', true)->update([
            'is_active' => false,
            'unstable_reason' => 'Motor descontinuado.',
            'updated_at' => now(),
        ]);

        // 5. MAGNIFIC entra no catálogo de GERAÇÃO (além da edição do passo 3). Também
        //    desligados até a chave existir — mesma regra do passo 3.
        foreach (self::MAGNIFIC_NOVOS as $slug => $m) {
            DB::table('gen_models')->updateOrInsert(['slug' => $slug], [
                'display_name' => $m['nome'],
                'kind' => $m['kind'],
                'subtype' => $m['subtype'],
                'provider' => 'magnific',
                'provider_model_id' => $m['modelo'],
                'capabilities' => json_encode($m['caps']),
                'cost_credits' => $m['creditos'],
                'cost_basis_micro' => $m['base_micro'],
                'is_active' => false,
                'is_unstable' => true,
                'unstable_reason' => self::MOTIVO_SEM_CHAVE,
                'sort_order' => $m['ordem'],
                'updated_at' => now(),
                'created_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Texto volta ao agregador, com os modelos e preços de antes.
        $antes = [
            'txt-topo' => ['claude-fable-5', 'Topo', 20, 2],
            'txt-rapido' => ['claude-haiku-4-5', 'Rápido', 1, 0],
        ];
        foreach ($antes as $slug => [$modelo, $nome, $creditos, $ordem]) {
            DB::table('gen_models')->where('slug', $slug)->update([
                'display_name' => $nome,
                'provider' => 'kie',
                'provider_model_id' => $modelo,
                'cost_credits' => $creditos,
                'sort_order' => $ordem,
                'updated_at' => now(),
            ]);
        }
        DB::table('gen_models')->whereIn('slug', self::TEXTO_FORA)->update([
            'is_active' => true,
            'updated_at' => now(),
        ]);

        $voltaKie = [
            'edit-upscale' => ['recraft/crisp-upscale', ['image_field' => 'image']],
            'edit-upscale-pro' => ['topaz/image-upscale', ['image_field' => 'image']],
            'edit-remove-bg' => ['recraft/remove-background', ['image_field' => 'image']],
            'vid-lipsync' => ['omnihuman-1-5', [
                'refs_field' => 'image_url', 'refs_single' => true, 'audio_field' => 'audio_url',
                'extra' => ['output_resolution' => '720'],
            ]],
        ];
        foreach ($voltaKie as $slug => [$modelo, $spec]) {
            $linha = DB::table('gen_models')->where('slug', $slug)->first();
            if (! $linha) {
                continue;
            }
            $caps = json_decode($linha->capabilities ?? '{}', true) ?: [];
            unset($caps['magnific']);
            $caps['kie'] = $spec;
            DB::table('gen_models')->where('slug', $slug)->update([
                'provider' => 'kie',
                'provider_model_id' => $modelo,
                'capabilities' => json_encode($caps),
                // is_active EXPLÍCITO: estas 4 linhas estavam ATIVAS antes da migration e o
                // up() as desligou. Sem isto o rollback "dá certo" e deixa upscale, remoção de
                // fundo e fala sincronizada mortos — o pior desfecho de um rollback, porque
                // ninguém vai procurar defeito depois de desfazer.
                'is_active' => true,
                'is_unstable' => false,
                'unstable_reason' => null,
                'updated_at' => now(),
            ]);
        }

        // Religa o que esta migration desligou. Usa o motivo como marca: só volta o que saiu
        // AQUI, não o que o operador já tinha desativado por conta própria antes.
        DB::table('gen_models')->where('provider', 'kie')
            ->where('unstable_reason', 'Motor descontinuado.')
            ->update(['is_active' => true, 'unstable_reason' => null, 'updated_at' => now()]);

        // As linhas NOVAS do Magnific não existiam antes desta migration: somem no rollback.
        DB::table('gen_models')->whereIn('slug', array_keys(self::MAGNIFIC_NOVOS))->delete();
    }
};
