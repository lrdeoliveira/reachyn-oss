<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Move para a pós-produção os 5 modelos que NÃO ACEITAM PROMPT e mesmo assim estavam no
 * seletor de geração.
 *
 * ── COMO APARECERAM ────────────────────────────────────────────────────────────────────────
 * Varredura de 29/08 comparando cada modelo ATIVO com o schema que a própria CLI publica
 * (`higgsfield model get <job_type> --json`), procurando por quem não tem o parâmetro `prompt`.
 * Sem prompt, o modelo não gera do zero: ele TRANSFORMA uma peça que já existe. Oferecê-lo em
 * "gerar imagem" produz sempre o mesmo resultado — erro:
 *
 *   Missing required params: brightness, color, image_references, light_quality, light_source
 *   Unknown params: prompt
 *
 * ── O QUE ISSO REVELOU ─────────────────────────────────────────────────────────────────────
 * `nano_banana_2_ai_stylist` e `nano_banana_2_skin_enhancer` estavam ATIVOS e precificados a 8
 * créditos desde 2026-08-04, oferecidos como geradores de imagem. Os dois exigem
 * `image_references` e recusam `prompt`: nenhuma geração por eles jamais pôde funcionar. Não
 * apareceu em log nem em teste — a falha acontecia no bridge, na frente do cliente, e o
 * catálogo continuava anunciando os modelos como disponíveis.
 *
 * É o mesmo padrão da credencial ausente (migration anterior): escrito, ligado, e nunca
 * exercitado de ponta a ponta.
 *
 * Para repetir a varredura (é o que encontrou estes 5 — vale a cada catálogo novo):
 *
 *   psql -At -F"|" -c "SELECT replace(provider_model_id,'higgsfield:',''), slug FROM gen_models
 *                      WHERE provider='cli-bridge' AND is_active" |
 *   while IFS="|" read -r jt slug; do
 *     higgsfield model get "$jt" --json |
 *       jq -e '[.params[].name]|index("prompt")' >/dev/null || echo "SEM-PROMPT $jt $slug"
 *   done
 *
 * O `reachyn:sync-higgsfield` ainda NÃO classifica sozinho: o /v1/models do bridge devolve
 * `refs` e `aspects`, mas não diz se o modelo aceita `prompt`. Expor esse campo em
 * higgsmodels.go é o que tornaria a checagem automática — enquanto isso, a varredura acima é
 * manual e precisa ser rodada de propósito.
 *
 * ── POR QUE NÃO DESLIGAR ───────────────────────────────────────────────────────────────────
 * Eles FUNCIONAM: reiluminar, estilizar, realçar pele e cortar clipes são operações legítimas,
 * só que sobre uma peça existente. Desligar jogaria fora capacidade real. Reclassificados,
 * saem do seletor de geração (scope `uso`) e ficam disponíveis para a pós-produção da galeria.
 */
return new class extends Migration
{
    /** job_type => por que não é geração (só documentação; a chave usada é o slug abaixo). */
    private const FERRAMENTAS = [
        'hf-clipify' => 'req=urls — corta clipes de um vídeo que já existe',
        'hf-nano-banana-2-ai-stylist' => 'req=image_references — restiliza uma imagem dada',
        'hf-nano-banana-2-skin-enhancer' => 'req=image_references,preset_id — realce de pele',
        'hf-nano-banana-2-shots' => 'sem prompt — deriva enquadramentos de uma imagem',
        'hf-nano-banana-2-relight' => 'req=brightness,color,light_* — reilumina uma imagem',
    ];

    public function up(): void
    {
        DB::table('gen_models')
            ->whereIn('slug', array_keys(self::FERRAMENTAS))
            ->where('provider', 'cli-bridge')
            ->update(['subtype' => 'postproducao', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('gen_models')
            ->whereIn('slug', array_keys(self::FERRAMENTAS))
            ->where('provider', 'cli-bridge')
            ->update(['subtype' => null, 'updated_at' => now()]);
    }
};
