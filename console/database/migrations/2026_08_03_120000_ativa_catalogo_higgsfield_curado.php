<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ativa a fatia CURADA do catálogo Higgsfield (2026-08-03).
 *
 * O `reachyn:sync-higgsfield` cadastrou os 59 modelos da conta, todos inativos e sem preço —
 * secure-by-default. Esta migration faz o que faltava para virarem opção de verdade no seletor:
 * nome público, preço medido e ativação.
 *
 * PREÇO — medido, não estimado. `higgsfield generate cost <job_type>` foi rodado modelo a modelo
 * em 2026-08-03; a coluna `hf` abaixo é o custo REAL em créditos Higgsfield. A conversão é a
 * mesma já adotada em 2026_08_02_140000 (US$0,02 por crédito HF ÷ US$0,005 por crédito Reachyn
 * = ×4), o que mantém a régua coerente com o vid-higgsfield-cinema já no ar (25 HF → 100).
 *
 * NOME — white-label (#6): descreve o que o modelo FAZ, nunca quem o fabrica. A CLI devolve
 * "Google Veo 3.1", "Kling 3.0", "GPT Image 2"; esses nomes ficam no `[rascunho]` das linhas não
 * curadas, que seguem inativas e invisíveis ao cliente.
 *
 * O QUE NÃO ENTRA, e por quê:
 *  - UTILITÁRIOS (upscale, remove-bg, outpaint, deflicker, transcriber): exigem imagem/vídeo de
 *    entrada e não geram a partir de prompt. No seletor de GERAÇÃO eles só produziriam falha —
 *    o lugar deles é o bucket `edit`, que é outro fluxo.
 *  - Modelos cujo custo a CLI não estimou sem params extras (veo3_1, minimax_hailuo, grok_video_v15,
 *    sonilo_music…): ficam cadastrados e inativos até alguém medir. Ativar sem preço é
 *    denial-of-wallet, que foi exatamente o motivo de o catálogo nascer desligado.
 *
 * Reversível: o down() devolve as linhas ao estado de rascunho (inativas, sem preço).
 */
return new class extends Migration
{
    /** slug => [nome público white-label, custo medido em créditos Higgsfield, ordem]. */
    private const CURADOS = [
        // ── Imagem ──────────────────────────────────────────────────────────────────────
        'hf-z-image' => ['Rascunho', 0.15, 20],
        'hf-soul-cinematic' => ['Cinematográfico', 0.12, 21],
        'hf-kling-omni-image' => ['Realista', 0.5, 22],
        'hf-flux-2' => ['Preciso', 1.0, 23],
        'hf-seedream-v5-lite' => ['Edição inteligente', 1.0, 24],
        'hf-grok-image' => ['Expressivo', 1.0, 25],
        'hf-recraft-v4-1' => ['Vetor e logotipo', 1.25, 26],
        'hf-nano-banana-pro' => ['Alta fidelidade', 2.0, 27],
        'hf-seedream-v5-pro' => ['Detalhe máximo', 3.0, 28],
        'hf-openai-hazel' => ['Tipografia', 4.0, 29],
        // ── Vídeo (custo medido em clipe de 5s) ─────────────────────────────────────────
        'hf-wan2-7' => ['Clipe econômico', 7.5, 30],
        'hf-kling3-0-turbo' => ['Clipe rápido', 7.5, 31],
        'hf-kling3-0' => ['Clipe premium', 10.0, 32],
        'hf-seedance-2-0' => ['Clipe cinema', 22.5, 33],
        // ── Áudio ───────────────────────────────────────────────────────────────────────
        'hf-seed-audio' => ['Narração natural', 0.1, 34],
    ];

    /** Créditos Reachyn a partir do custo medido em créditos Higgsfield (×4, piso 1). */
    private function creditos(float $hf): int
    {
        return max(1, (int) ceil($hf * 4));
    }

    public function up(): void
    {
        foreach (self::CURADOS as $slug => [$nome, $hf, $ordem]) {
            DB::table('gen_models')->where('slug', $slug)->update([
                'display_name' => $nome,
                'cost_credits' => $this->creditos($hf),
                // Base em micro-dólares do custo REAL, pra permitir recalibrar contra a fatura.
                'cost_basis_micro' => (int) round($hf * 0.02 * 1_000_000),
                'is_active' => true,
                'sort_order' => $ordem,
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::CURADOS) as $slug) {
            DB::table('gen_models')->where('slug', $slug)->update([
                'is_active' => false,
                'cost_credits' => null,
                'cost_basis_micro' => null,
                'updated_at' => now(),
            ]);
        }
    }
};
