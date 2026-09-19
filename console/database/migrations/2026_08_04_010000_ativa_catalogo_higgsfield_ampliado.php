<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Abre o catálogo Higgsfield: 12 modelos de imagem e 13 de vídeo que estavam como [rascunho].
 *
 * POR QUE ESTAVAM DESLIGADOS: entraram em lote quando o bridge virou genérico (59 modelos
 * endereçáveis sem adapter por modelo) e nasceram sem `cost_credits`. Modelo ativo SEM preço é o
 * problema: quem cobra lê `cost_credits` e, nulo, a geração ou sai de graça ou cai no custo do
 * bucket — ou seja, o cliente paga o preço de outro modelo. Por isso ligar exige precificar.
 *
 * COMO O PREÇO FOI DEFINIDO: por EQUIVALÊNCIA com o que já está ativo e precificado, nunca por
 * chute solto. A conta Higgsfield é de assinatura (custo marginal ~zero por peça), então o preço
 * interno serve pra dosar consumo e manter a régua coerente entre motores da mesma família:
 *   imagem  — rápido/rascunho 1 · padrão 2 · edição/estilo 4 · alta fidelidade 8 · topo 12-16
 *   vídeo   — econômico 30 · premium 40 · cinema 90-100
 * Onde não havia equivalente claro, ficou o teto da faixa: errar pra MAIS protege o crédito da
 * casa; errar pra menos vira denial-of-wallet (guideline #11).
 *
 * O QUE FICOU DE FORA, de propósito: utilitários que não são "gerar uma peça" e precisam de UI
 * própria pra fazer sentido (upscale, remover fundo, outpaint, deflicker, clipify, sam-3, llm-text).
 * Ligar no seletor de geração só encheria a lista de opções que devolvem a peça errada.
 *
 * Reversível: o down() devolve exatamente os slugs tocados ao estado de rascunho.
 */
return new class extends Migration
{
    /** slug => [cost_credits, min_plan|null] — preço por equivalência (ver cabeçalho). */
    private const IMAGENS = [
        'hf-gpt-image-2' => [8, null],                 // texto renderizado; equivale ao nano-banana-pro em fidelidade
        'hf-nano-banana-flash' => [2, null],           // linha rápida, mesma faixa do img-higgsfield-nano2
        'hf-nano-banana-2-lite' => [2, null],
        'hf-nano-banana' => [2, null],
        'hf-seedream-v4-5' => [4, null],               // geração anterior do seedream-v5-lite (4)
        'hf-text2image-soul-v2' => [2, null],          // mesma família do img-higgsfield-soul (2)
        'hf-soul-cast' => [4, null],                   // Soul especializado (elenco)
        'hf-soul-location' => [4, null],               // Soul especializado (locação)
        'hf-flux-kontext' => [4, null],                // edição por contexto; faixa do flux-2 (4)
        'hf-image-auto' => [2, null],                  // roteador do próprio provedor
        'hf-nano-banana-2-ai-stylist' => [8, null],    // variantes Pro → preço do nano-banana-pro
        'hf-nano-banana-2-skin-enhancer' => [8, null],
    ];

    /** Vídeo: a régua é a dos clipes já ativos (30 econômico · 40 premium · 90+ cinema). */
    private const VIDEOS = [
        'hf-wan2-6' => [30, null],                     // geração anterior do hf-wan2-7 (30)
        'hf-kling2-6' => [30, null],                   // anterior ao kling3-0-turbo (30)
        'hf-minimax-hailuo' => [30, null],             // mesma casa do vid-simples (30)
        'hf-minimax-h3' => [40, null],
        'hf-seedance1-5' => [40, null],                // anterior ao seedance-2-0 (90)
        'hf-seedance-2-0-mini' => [40, null],          // versão leve do cinema
        'hf-grok-video' => [40, null],
        'hf-grok-video-v15' => [40, null],
        'hf-happy-horse-video' => [40, null],
        'hf-gemini-omni' => [40, null],
        'hf-veo3-1-lite' => [60, null],                // faixa do vid-premium/Veo (60)
        'hf-veo3' => [90, null],
        'hf-veo3-1' => [90, null],
    ];

    public function up(): void
    {
        foreach ([self::IMAGENS, self::VIDEOS] as $grupo) {
            foreach ($grupo as $slug => [$custo, $plano]) {
                // Só toca o que EXISTE e está desligado: rodar de novo é seguro, e um slug que já
                // foi ligado/ajustado à mão no Filament não é sobrescrito por esta migration.
                DB::table('gen_models')
                    ->where('slug', $slug)
                    ->where('provider', 'cli-bridge')
                    ->where('is_active', false)
                    ->update([
                        'is_active' => true,
                        'cost_credits' => $custo,
                        'min_plan' => $plano,
                        // Tira o "[rascunho]" do nome visível — ele era o aviso de que o modelo não
                        // estava pronto pra ser escolhido por ninguém.
                        'display_name' => DB::raw("trim(replace(display_name, '[rascunho]', ''))"),
                        'updated_at' => now(),
                    ]);
            }
        }
    }

    public function down(): void
    {
        foreach ([self::IMAGENS, self::VIDEOS] as $grupo) {
            foreach ($grupo as $slug => $_) {
                DB::table('gen_models')->where('slug', $slug)->where('provider', 'cli-bridge')->update([
                    'is_active' => false,
                    'cost_credits' => null,
                    'display_name' => DB::raw("'[rascunho] ' || trim(display_name)"),
                    'updated_at' => now(),
                ]);
            }
        }
    }
};
