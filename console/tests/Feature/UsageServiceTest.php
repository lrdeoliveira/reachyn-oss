<?php

namespace Tests\Feature;

use App\Exceptions\InsufficientCreditsException;
use App\Models\Organization;
use App\Models\Tenant;
use App\Services\CreditWallet;
use App\Services\UsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 💳 UsageService — o serviço que cobra crédito de IA, e até 2026-07-15 sem teste dedicado
 * (aparecia só mockado, isto é: nunca exercitado).
 *
 * O foco aqui é a SIMETRIA reserva↔estorno. O padrão é reserve-then-consume: debita ANTES de
 * chamar o provedor e estorna se falhar. Se o refund não devolver exatamente o que o tryConsume
 * cobrou, ou o cliente paga por geração que não recebeu, ou nós geramos de graça — e nada quebra
 * de forma visível, o saldo só deriva em silêncio.
 *
 * Não é hipótese: o lip-sync cobrava `count($lines)` clipes e entregava 1 (achado de 2026-07-15).
 */
class UsageServiceTest extends TestCase
{
    use RefreshDatabase;

    private function usage(): UsageService
    {
        return app(UsageService::class);
    }

    /** Marca de uma org PAGANTE (a factory default é exempt, onde debit() é no-op). */
    private function marcaPagante(string $plan = 'pro', int $saldo = 100000): Tenant
    {
        return Tenant::factory()->for(Organization::factory()->paying($plan, $saldo))->create();
    }

    private function saldo(Tenant $t): int
    {
        return app(CreditWallet::class)->balance($t->organization->fresh());
    }

    public function test_debita_o_custo_fixo_do_tipo(): void
    {
        $t = $this->marcaPagante(saldo: 1000);

        $this->assertTrue($this->usage()->tryConsume($t, 'video'));

        // CREDIT_COST['video'] = 60
        $this->assertSame(1000 - 60, $this->saldo($t));
    }

    /** weight = nº de unidades geradas; 3 cenas custam 3×, não 1×. */
    public function test_o_peso_multiplica_o_custo(): void
    {
        $t = $this->marcaPagante(saldo: 1000);

        $this->usage()->tryConsume($t, 'video', 3);

        $this->assertSame(1000 - 180, $this->saldo($t));
    }

    /** O custo do MODELO escolhido vence o custo fixo do tipo (gen_models.cost_credits). */
    public function test_custo_por_modelo_sobrepoe_o_custo_fixo(): void
    {
        $t = $this->marcaPagante(saldo: 1000);

        $this->usage()->tryConsume($t, 'video', 1, costCredits: 105);

        $this->assertSame(1000 - 105, $this->saldo($t), 'ignorou o cost_credits do modelo');
    }

    /**
     * ⚖️ A invariante: estornar devolve EXATAMENTE o que a reserva tirou. Com os mesmos argumentos,
     * saldo volta ao ponto inicial — nem crédito criado, nem sumido.
     */
    public function test_estorno_devolve_exatamente_o_que_a_reserva_cobrou(): void
    {
        $t = $this->marcaPagante(saldo: 5000);

        $this->usage()->tryConsume($t, 'video', 3, costCredits: 105);
        $this->assertSame(5000 - 315, $this->saldo($t));

        $this->usage()->refund($t, 'video', 3, 105);

        $this->assertSame(5000, $this->saldo($t), 'reserva e estorno têm de se anular');
    }

    /** Estorno com o custo unitário ERRADO cria dinheiro — o cenário que o docblock do refund alerta. */
    public function test_estorno_com_custo_divergente_nao_fecha_a_conta(): void
    {
        $t = $this->marcaPagante(saldo: 5000);

        $this->usage()->tryConsume($t, 'video', 1, costCredits: 105);
        $this->usage()->refund($t, 'video', 1); // esqueceu o costCredits → devolve o fixo (60)

        // Documenta a consequência real: some a diferença (105 - 60). O refund NÃO se defende
        // sozinho — quem chama tem de repetir o mesmo custo unitário da reserva.
        $this->assertSame(5000 - 45, $this->saldo($t));
    }

    /** Feature-gate: starter é plano só-imagem (video => 0). Recusa ANTES de gastar crédito. */
    public function test_plano_sem_a_feature_recusa_sem_debitar(): void
    {
        $t = $this->marcaPagante('starter', 1000);

        $this->assertFalse($this->usage()->tryConsume($t, 'video'), 'starter não tem vídeo');
        $this->assertSame(1000, $this->saldo($t), 'recusa não pode cobrar');
    }

    /** Saldo insuficiente PROPAGA (vira 402 no controller) em vez de gerar de graça. */
    public function test_saldo_insuficiente_lanca_e_nao_gera(): void
    {
        $t = $this->marcaPagante(saldo: 10); // vídeo custa 60

        $this->expectException(InsufficientCreditsException::class);
        $this->usage()->tryConsume($t, 'video');
    }

    /** Org interna (exempt) não é cobrada — é o dogfooding da RedFoxCode. */
    public function test_org_exempt_nao_e_debitada(): void
    {
        $t = Tenant::factory()->create(); // factory default: unlimited + exempt

        $this->assertTrue($this->usage()->tryConsume($t, 'video', 2));
        $this->assertSame(0, $this->saldo($t), 'exempt não movimenta saldo');
    }
}
