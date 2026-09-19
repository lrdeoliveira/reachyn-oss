<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * RESCALDO DA SAÍDA DO AGREGADOR (2026-08-04) — o que ficou quebrado em silêncio.
 *
 * A migration de 2026-08-03 desativou TODAS as linhas `provider='kie'`, o que estava certo. O que
 * não foi feito junto: repontar as linhas que o CÓDIGO procura pelo slug. Elas continuaram
 * existindo, inativas, e cada caminho que dependia delas degradou sem erro nenhum.
 *
 * ── 1. img-referencia — a ancoragem de personagem virou t2i ──────────────────────────────────
 * `GenModel::referenceImageModel()` resolve 'img-referencia' e, quando não acha, CAI NO
 * DEFAULT_T2I (img-cli-mmx). O comentário do próprio método já avisava o preço disso: usar um
 * text-to-image num pedido i2i significa "ignorar as referências em silêncio e devolver uma
 * imagem nova, em vez da edição pedida". Foi exatamente o que passou a acontecer em produção,
 * em 14 pontos de chamada (Filme, Animação, Estúdio, Personagem): o cliente mandava a imagem
 * do personagem e recebia outro personagem — sem erro, sem log, cobrando igual.
 *
 * O modelo NÃO mudou: é o mesmo Nano Banana 2. Mudou a CONTA — o i2i ancorado roda pelo adapter
 * `higgsfield` do cli-bridge (ver `i2iAncorado` em engine/internal/content/content.go, que já
 * chama `CliImage(ctx, "higgsfield", …)`). O engine já estava certo desde 03/08; era o catálogo
 * que apontava pro lugar morto.
 *
 * Custo: conta de ASSINATURA, custo marginal zero. Os 6 créditos ficam como LIMITE DE USO (mesma
 * doutrina da migration de texto: o crédito não é repasse de custo, é o que impede uma conta só
 * de assinatura de virar fila infinita). `async` preservado: o caminho leva ~100s e a tela
 * síncrona precisa continuar recusando e mandando pra fila.
 *
 * ── 2. audio-narracao / audio-narracao-v3 — o último bloco `kie` vivo ────────────────────────
 * As duas linhas do ElevenLabs guardavam os tiers de qualidade em `capabilities.kie.qualities`.
 * Funcionava só por causa do fallback `capabilities[provider] ?? capabilities['kie']` espalhado
 * no console. Eram as ÚNICAS linhas ATIVAS ainda dependendo desse fallback — com elas migradas
 * para `capabilities.elevenlabs`, o fallback passa a servir apenas linhas inativas e pode ser
 * removido quando alguém quiser, sem caçar quem depende dele.
 *
 * Reversível: o down() devolve as duas mudanças ao estado anterior.
 */
return new class extends Migration
{
    private const AUDIO = ['audio-narracao', 'audio-narracao-v3'];

    public function up(): void
    {
        // 1. Ancoragem i2i de volta ao ar, na conta de assinatura.
        DB::table('gen_models')->where('slug', 'img-referencia')->update([
            'display_name' => 'Referência (ancoragem)',
            'provider' => 'cli-bridge',
            'provider_model_id' => 'higgsfield',
            'capabilities' => json_encode([
                'task_types' => ['Image to Image'],
                'upstream' => 'Higgsfield',
                'async' => true,
                'refs' => true,
            ]),
            'is_active' => true,
            'is_unstable' => false,
            'unstable_reason' => null,
            'updated_at' => now(),
        ]);

        // 2. Tiers do áudio saem de `kie` e passam a morar sob o provider real.
        foreach (self::AUDIO as $slug) {
            $linha = DB::table('gen_models')->where('slug', $slug)->first();
            if (! $linha) {
                continue;
            }
            $caps = json_decode($linha->capabilities ?? '{}', true) ?: [];
            if (! isset($caps['kie'])) {
                continue;
            }
            $caps['elevenlabs'] = $caps['kie'];
            unset($caps['kie']);
            DB::table('gen_models')->where('slug', $slug)
                ->update(['capabilities' => json_encode($caps), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        DB::table('gen_models')->where('slug', 'img-referencia')->update([
            'display_name' => 'nano-banana-2 (referência)',
            'provider' => 'kie',
            'provider_model_id' => 'nano-banana-2',
            'capabilities' => json_encode([
                'task_types' => ['Image to Image'],
                'upstream' => 'Google',
                'async' => true,
                'kie' => ['aspect_field' => 'aspect_ratio', 'refs_field' => 'image_input'],
            ]),
            // Volta INATIVA: era esse o estado antes desta migration (a saída do agregador a
            // desligou). Religar aqui devolveria um modelo que o engine não roteia mais.
            'is_active' => false,
            'unstable_reason' => 'Motor descontinuado.',
            'updated_at' => now(),
        ]);

        foreach (self::AUDIO as $slug) {
            $linha = DB::table('gen_models')->where('slug', $slug)->first();
            if (! $linha) {
                continue;
            }
            $caps = json_decode($linha->capabilities ?? '{}', true) ?: [];
            if (! isset($caps['elevenlabs'])) {
                continue;
            }
            $caps['kie'] = $caps['elevenlabs'];
            unset($caps['elevenlabs']);
            DB::table('gen_models')->where('slug', $slug)
                ->update(['capabilities' => json_encode($caps), 'updated_at' => now()]);
        }
    }
};
