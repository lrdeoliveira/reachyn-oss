<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Kling 3.0 (KIE) no catálogo de vídeo — "Cinema Pro".
 *
 * POR QUE ENTRA MESMO SENDO MAIS CARO: o mesmo modelo já está no ar pela Higgsfield a 40 créditos
 * ("Clipe premium"), contra 140 aqui — 3,5×. A diferença não é desperdício, é REDUNDÂNCIA: a
 * Higgsfield é assinatura CAPADA por mês (~1005 créditos); quando o saldo acaba, ela para. A KIE é
 * pay-per-use e não depende de cota. Ter o mesmo modelo nas duas contas é o que mantém o Estúdio
 * de pé no fim do mês. Decisão do operador em 2026-08-03, com a diferença de preço à vista.
 *
 * PREÇO MEDIDO, conversão inferida: uma geração real (5s, std, 16:9) cobrou **70 créditos KIE**
 * — saldo 7481,46 → 7411,46, debitado NA CRIAÇÃO da task, não na conclusão. A taxa crédito→USD
 * não é publicada; adotamos US$0,01/crédito ⇒ US$0,70 ⇒ 140 créditos Reachyn (US$0,005 cada).
 * O `cost_basis_micro` guarda esses US$0,70 para recalibrar contra a fatura. Se a taxa real for
 * US$0,005, o preço certo é 70 e estamos cobrando o dobro — conferir na primeira fatura.
 *
 * SPEC — do teste real + doc oficial (`kling-3.0/video`):
 *  - id ÚNICO para t2v e i2v: a imagem entra por `image_urls` (ARRAY), não há variante separada;
 *  - `duration` como STRING ("5"), não inteiro;
 *  - ⚠️ `multi_shots` é OBRIGATÓRIO: sem ele a KIE recusa com 422 "multi_shots cannot be empty".
 *    Ele vai no Extra como `false` (modo normal); quando o Filme rápido liga o modo multi-corte,
 *    o engine sobrescreve DEPOIS do Extra — ordem travada por teste de regressão em
 *    kie_multishots_test.go, porque aplicar o Extra por último desligava o modo em silêncio.
 *
 * NÃO inclui os modos `pro` e `4k`: custam mais e não foram medidos. Preço não medido não entra.
 */
return new class extends Migration
{
    private const SLUG = 'vid-cinema-pro';

    public function up(): void
    {
        DB::table('gen_models')->updateOrInsert(
            ['slug' => self::SLUG],
            [
                'display_name' => 'Cinema Pro',
                'kind' => 'video',
                'subtype' => 'text_to_video',
                'provider' => 'kie',
                'provider_model_id' => 'kling-3.0/video',
                'cost_credits' => 140,
                'cost_basis_micro' => 700000,
                'capabilities' => json_encode([
                    'task_types' => ['Text to Video', 'Image to Video'],
                    'upstream' => 'Kling',
                    'async' => true,
                    'durations' => [3, 5, 10, 15],
                    'kie' => [
                        'aspect_field' => 'aspect_ratio',
                        'refs_field' => 'image_urls',
                        'refs_single' => false,
                        'duration_field' => 'duration',
                        'duration_string' => true,
                        'multi_prompt_field' => 'multi_prompt',
                        'extra' => [
                            'mode' => 'std',
                            'sound' => false,
                            'multi_shots' => false,
                            'multi_prompt' => [],
                        ],
                    ],
                ]),
                'is_active' => true,
                'min_plan' => null,
                'sort_order' => 125, // logo depois do Turbo (120), que é o irmão barato
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('gen_models')->where('slug', self::SLUG)->update([
            'is_active' => false,
            'updated_at' => now(),
        ]);
    }
};
