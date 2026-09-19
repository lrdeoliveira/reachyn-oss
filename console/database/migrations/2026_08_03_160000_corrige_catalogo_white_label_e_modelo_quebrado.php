<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Conserta o catálogo em dois pontos que estavam ERRADOS em produção (auditoria 2026-08-03).
 *
 * ── 1. MODELO ATIVO QUE NÃO FUNCIONA ────────────────────────────────────────────────────────
 * `img-cli-cursor` roda pela CLI `cursor` através do bridge, mas essa CLI NÃO está instalada no
 * host da VPS (`command -v cursor` = ausente; só `mmx` e `higgsfield` existem). O modelo estava
 * ATIVO no seletor: toda escolha dele reservava crédito, falhava e caía no estorno. Sai do ar até
 * a CLI existir — o down() devolve, então religar é trivial se ela for instalada.
 *
 * ── 2. WHITE-LABEL (guideline #6) ───────────────────────────────────────────────────────────
 * O `GenModelResource` já protege `provider`, `provider_model_id` e `capabilities.upstream` — mas
 * o `display_name` sai CRU pro cliente, e sete modelos ativos nomeavam o fabricante ou o modelo
 * técnico: "nano-banana-2 (referência)", "Nano Banana 2 (Higgsfield)", "Soul 2.0 (Higgsfield)",
 * "image-01 (API)", "mmx", "HappyHorse", "Wan". O nome público descreve o que o modelo FAZ.
 *
 * De quebra resolve DUAS COLISÕES: "Realista" e "Tipografia" apareciam DUAS VEZES no seletor de
 * imagem (uma linha do agregador e uma da assinatura), sem nada que dissesse ao cliente qual era
 * qual. O sufixo "(assinatura)" segue a convenção que já existia em "Narração (assinatura)" e
 * diz a coisa útil: essa opção sai da conta de assinatura, que é mensal e tem teto.
 *
 * Reversível: o down() restaura os nomes anteriores exatamente como estavam.
 */
return new class extends Migration
{
    /** slug => [nome novo (white-label), nome anterior (pro rollback)]. */
    private const NOMES = [
        'img-referencia' => ['Referência', 'nano-banana-2 (referência)'],
        'img-higgsfield-nano2' => ['Consistente (assinatura)', 'Nano Banana 2 (Higgsfield)'],
        'img-higgsfield-soul' => ['Retrato (assinatura)', 'Soul 2.0 (Higgsfield)'],
        'img-padrao' => ['Básico', 'image-01 (API)'],
        'img-cli-mmx' => ['Ágil', 'mmx'],
        'img-wan' => ['Suave', 'Wan'],
        'vid-happyhorse' => ['Vibrante', 'HappyHorse'],
        // Colisões de nome no seletor de imagem:
        'hf-kling-omni-image' => ['Realista (assinatura)', 'Realista'],
        'hf-openai-hazel' => ['Tipografia (assinatura)', 'Tipografia'],
    ];

    public function up(): void
    {
        foreach (self::NOMES as $slug => [$novo, $_]) {
            DB::table('gen_models')->where('slug', $slug)
                ->update(['display_name' => $novo, 'updated_at' => now()]);
        }

        DB::table('gen_models')->where('slug', 'img-cli-cursor')->update([
            'is_active' => false,
            'unstable_reason' => 'Indisponível: o motor não está instalado no servidor.',
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        foreach (self::NOMES as $slug => [$_, $antigo]) {
            DB::table('gen_models')->where('slug', $slug)
                ->update(['display_name' => $antigo, 'updated_at' => now()]);
        }

        DB::table('gen_models')->where('slug', 'img-cli-cursor')->update([
            'is_active' => true,
            'unstable_reason' => null,
            'updated_at' => now(),
        ]);
    }
};
