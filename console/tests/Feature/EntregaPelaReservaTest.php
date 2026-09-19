<?php

namespace Tests\Feature;

use App\Models\GenModel;
use App\Models\Organization;
use App\Models\Tenant;
use App\Models\User;
use App\Scopes\TenantScope;
use App\Services\UsageService;
use App\Support\EngineClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 💳 Texto entregue pela RESERVA não pode ser cobrado como premium.
 *
 * 🐛 DEFEITO (produção, 2026-08-03): a linha de texto é principal→reserva. Quando a primária cai,
 * a reserva atende e a resposta volta **HTTP 200** — indistinguível de sucesso. Mas o console
 * debita ANTES de gerar, pelo preço do modelo PEDIDO, e só estorna quando a resposta falha.
 * Com as quatro linhas Claude do agregador fora por mais de um dia, o cliente pagou 20 créditos
 * por "Topo" e recebeu texto da reserva, cujo nível equivalente custa 1.
 *
 * O engine passou a carimbar X-Reachyn-Reserva; estes testes trancam as três regras que sobraram.
 */
class EntregaPelaReservaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $marca;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marca = Tenant::factory()->for(Organization::factory()->paying('studio', 100000))->create();
        TenantScope::flushActiveTenantCache();
        User::factory()->create([
            'tenant_id' => $this->marca->id,
            'organization_id' => $this->marca->organization_id,
            'role' => 'client',
        ]);
        config(['services.engine.url' => 'http://engine.test', 'services.engine.admin_token' => 'x']);
    }

    private function nivel(string $slug, int $custo, bool $instavel = false): GenModel
    {
        return GenModel::updateOrCreate(['slug' => $slug], [
            'display_name' => $slug, 'kind' => 'text', 'provider' => 'kie',
            'provider_model_id' => $slug.'-real', 'cost_credits' => $custo,
            'is_active' => true, 'is_unstable' => $instavel, 'min_plan' => null,
        ]);
    }

    /** A diferença volta: cobrou 20, a reserva entregou, o justo é 1 ⇒ devolve 19. */
    public function test_devolve_a_diferenca_quando_a_reserva_entrega(): void
    {
        $topo = $this->nivel('txt-topo', 20);
        $usage = app(UsageService::class);
        $saldoAntes = $this->marca->organization->fresh()->credit_balance;

        $this->assertTrue($usage->tryConsume($this->marca, 'text', 1, $topo->cost_credits));
        $this->assertSame(
            $saldoAntes - 20,
            $this->marca->organization->fresh()->credit_balance,
            'a reserva do crédito cobra o preço do modelo pedido'
        );

        $devolvido = $usage->ajustaParaReserva($this->marca, 'text', 1, $topo->cost_credits);

        $this->assertSame(19, $devolvido);
        $this->assertSame(
            $saldoAntes - 1,
            $this->marca->organization->fresh()->credit_balance,
            'no fim o cliente paga o preço da entrega que recebeu, não o do nível pedido'
        );
    }

    /** Quem pediu o nível básico e recebeu o básico não recebe nada de volta. */
    public function test_nao_devolve_nada_quando_o_pedido_ja_era_do_nivel_da_reserva(): void
    {
        $rapido = $this->nivel('txt-rapido', 1);
        $usage = app(UsageService::class);

        $usage->tryConsume($this->marca, 'text', 1, $rapido->cost_credits);
        $saldo = $this->marca->organization->fresh()->credit_balance;

        $this->assertSame(0, $usage->ajustaParaReserva($this->marca, 'text', 1, $rapido->cost_credits));
        $this->assertSame($saldo, $this->marca->organization->fresh()->credit_balance);
    }

    /**
     * O contador de analytics NÃO é desfeito: a peça foi ENTREGUE e conta como uso.
     * Isto separa "entrega em nível abaixo" de "geração falhou" — que é um refund() comum.
     */
    public function test_a_peca_entregue_continua_contando_como_uso(): void
    {
        $topo = $this->nivel('txt-topo', 20);
        $usage = app(UsageService::class);
        $usage->tryConsume($this->marca, 'text', 1, $topo->cost_credits);

        $antes = (int) \DB::table('usages')->where('tenant_id', $this->marca->id)->where('kind', 'text')->value('count');
        $usage->ajustaParaReserva($this->marca, 'text', 1, $topo->cost_credits);
        $depois = (int) \DB::table('usages')->where('tenant_id', $this->marca->id)->where('kind', 'text')->value('count');

        $this->assertSame($antes, $depois, 'o uso permanece: a peça existe');
    }

    /**
     * O DEFAULT nunca recomenda um nível instável. Quem escolhe explicitamente é atendido (a UI
     * avisa e a decisão é dele); quem não escolhe está aceitando a nossa recomendação — e
     * recomendar linha que cai pra reserva é cobrar caro por entrega básica.
     */
    public function test_o_default_degrada_para_o_nivel_estavel_mais_proximo(): void
    {
        $this->nivel('txt-equilibrado', 5, instavel: true);  // o default, fora do ar
        $this->nivel('txt-rapido', 1);
        $estavel = $this->nivel('txt-profundo', 6);          // estável mais próximo POR CIMA

        $seletor = new class
        {
            use \App\Http\Controllers\Api\Concerns\ResolvesTextModel;

            public function escolhe(\Illuminate\Http\Request $r, ?string $plan): ?GenModel
            {
                return $this->textModelFor($r, $plan);
            }
        };

        $escolhido = $seletor->escolhe(\Illuminate\Http\Request::create('/', 'POST'), 'studio');

        $this->assertSame($estavel->slug, $escolhido?->slug, 'não pode recomendar o nível instável');
    }

    /** Escolha EXPLÍCITA do cliente é respeitada mesmo instável — o aviso já foi dado na tela. */
    public function test_escolha_explicita_de_nivel_instavel_e_respeitada(): void
    {
        $this->nivel('txt-equilibrado', 5, instavel: true);
        $this->nivel('txt-profundo', 6);

        $seletor = new class
        {
            use \App\Http\Controllers\Api\Concerns\ResolvesTextModel;

            public function escolhe(\Illuminate\Http\Request $r, ?string $plan): ?GenModel
            {
                return $this->textModelFor($r, $plan);
            }
        };

        $r = \Illuminate\Http\Request::create('/', 'POST', ['textModel' => 'txt-equilibrado']);

        $this->assertSame('txt-equilibrado', $seletor->escolhe($r, 'studio')?->slug);
    }

    /** O cabeçalho do engine é o que dispara o ajuste — e ele chega ao console pela resposta HTTP. */
    public function test_o_cabecalho_do_engine_chega_na_resposta(): void
    {
        Http::fake([
            'engine.test/*' => Http::response(['ok' => true, 'text' => 'oi'], 200, [
                EngineClient::CABECALHO_RESERVA => '1',
            ]),
        ]);

        $r = EngineClient::make()->post('/v1/chat', ['system' => 's', 'message' => 'm']);

        $this->assertSame('1', $r->header(EngineClient::CABECALHO_RESERVA));
    }
}
