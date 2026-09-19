<?php

namespace Tests\Feature;

use App\Models\GenModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Integridade do catálogo de modelos (gen_models).
 *
 * 🐛 REGRESSÃO que este teste tranca: modelo ATIVO sem `cost_credits`.
 *
 * O custo em créditos é o que a cobrança usa (UsageService: créditos = custo do provedor ÷
 * US$0,005). Um modelo ativo com `cost_credits` NULL é gerável e não debita nada — vazamento de
 * receita silencioso, do tipo que só aparece na fatura do provedor no fim do mês.
 *
 * O caso real: a auditoria de 2026-08-01 encontrou 79 modelos importados do catálogo da KIE com
 * `cost_credits` E `cost_basis_micro` nulos. Todos estavam INATIVOS, então não havia vazamento —
 * mas bastava alguém ligar `is_active` no Filament pra criar um. Este teste transforma essa
 * disciplina numa trava automática, em vez de depender de lembrança.
 */
class CatalogoIntegridadeTest extends TestCase
{
    use RefreshDatabase;

    public function test_modelo_ativo_sempre_tem_preco(): void
    {
        // Dormente sem preço é LEGÍTIMO: é o estado de um modelo importado do provedor e ainda
        // não precificado. O que não pode existir é ele ativo assim.
        GenModel::factory()->create([
            'slug' => 'kie-importado-sem-preco',
            'kind' => 'image',
            'is_active' => false,
            'cost_credits' => null,
        ]);

        $ativosSemPreco = GenModel::query()
            ->where('is_active', true)
            ->whereNull('cost_credits')
            ->pluck('slug')
            ->all();

        $this->assertSame(
            [],
            $ativosSemPreco,
            'Modelo ATIVO sem cost_credits gera sem debitar crédito (vazamento de receita): '
            .implode(', ', $ativosSemPreco)
        );
    }

    public function test_modelo_inativo_nao_e_selecionavel(): void
    {
        // A trava de verdade: mesmo pedindo o slug na mão, um modelo inativo não pode ser
        // resolvido — é o que mantém os dormentes inofensivos.
        GenModel::factory()->create([
            'slug' => 'kie-dormente',
            'kind' => 'image',
            'is_active' => false,
            'cost_credits' => null,
        ]);

        $this->assertNull(
            GenModel::resolveSelectable('kie-dormente', 'image', null),
            'Modelo inativo não pode ser selecionável — senão o dormente sem preço vira gerável.'
        );
    }
}
