<?php

namespace Tests\Feature;

use App\Jobs\GenerateImageJob;
use App\Models\GenModel;
use App\Models\Tenant;
use App\Models\User;
use App\Scopes\TenantScope;
use App\Services\UsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Modelos de imagem via CLI Bridge (provider=cli-bridge): img-cli-mmx (t2i + subject-ref)
 * e img-cli-cursor (t2i apenas). Trava: (1) o gate por plano funciona como allowlist
 * (min_plan=unlimited → só a org RedFox resolve; plano menor cai no t2i padrão SEM erro);
 * (2) provider/model internos vão pro engine e NUNCA vêm do request; (3) falha do engine
 * estorna o crédito (reserve-then-consume); (4) o INVARIANTE do t2i padrão — ele precisa
 * resolver pro MENOR plano, senão o fallback devolve null e a geração morre calada.
 *
 * Esse invariante virou crítico em 2026-07-20: o default deixou de ser o img-padrao (API)
 * e passou a ser o img-cli-mmx (CLI). Se alguém puser min_plan no default, todo plano
 * abaixo dele perde t2i — em silêncio.
 */
class CliBridgeImageModelTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $marca;

    private User $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marca = Tenant::factory()->create();
        TenantScope::flushActiveTenantCache();
        $this->cliente = User::factory()->create([
            'tenant_id' => $this->marca->id,
            'organization_id' => $this->marca->organization_id,
            'role' => 'client',
        ]);
        config(['services.engine.url' => 'http://engine.test', 'services.engine.admin_token' => 'x']);

        // Espelha a prod pós-2026-07-20: img-padrao INATIVO (API paga, redundante com o mmx),
        // os dois motores de CLI abertos a todos os planos, e um modelo restrito só pra
        // exercitar o gate.
        GenModel::factory()->create([
            'slug' => 'img-padrao', 'kind' => 'image', 'subtype' => 'text_to_image',
            'provider' => 'minimax', 'provider_model_id' => 'image-01',
            'is_active' => false, 'min_plan' => null,
        ]);
        GenModel::factory()->create([
            'slug' => 'img-cli-mmx', 'kind' => 'image', 'subtype' => 'text_to_image',
            'provider' => 'cli-bridge', 'provider_model_id' => 'mmx',
            'is_active' => true, 'min_plan' => null,
        ]);
        // updateOrCreate, não factory: a migration que criou este motor é uma data-migration,
        // então o RefreshDatabase já deixa a linha no banco — factory()->create() colidiria
        // no UNIQUE de slug.
        GenModel::updateOrCreate(
            ['slug' => 'img-cli-cursor'],
            [
                'display_name' => 'cursor', 'kind' => 'image', 'subtype' => 'text_to_image',
                'provider' => 'cli-bridge', 'provider_model_id' => 'cursor',
                'cost_credits' => 2, 'is_active' => true, 'min_plan' => null,
                // async: o cursor leva 110-145s e o Cloudflare corta em ~100s (524). Explícito
                // aqui pro teste se explicar sozinho, mesmo que a migration já ponha a flag.
                'capabilities' => ['task_types' => ['Text to Image'], 'async' => true],
            ]
        );
        GenModel::factory()->create([
            'slug' => 'img-restrito', 'kind' => 'image', 'subtype' => 'text_to_image',
            'provider' => 'kie', 'provider_model_id' => 'nano-banana-2',
            'is_active' => true, 'min_plan' => 'unlimited',
        ]);
        // i2i: async porque mediu 98s com saldo KIE — colado no corte do Cloudflare.
        GenModel::updateOrCreate(
            ['slug' => 'img-referencia'],
            [
                'display_name' => 'nano-banana-2 (referência)', 'kind' => 'image',
                'subtype' => 'image_to_image', 'provider' => 'kie', 'provider_model_id' => 'nano-banana-2',
                'cost_credits' => 6, 'is_active' => true, 'min_plan' => null,
                'capabilities' => ['task_types' => ['Image to Image'], 'async' => true],
            ]
        );
    }

    private function mockUsage(bool $consume, int $refunds = 0): void
    {
        $this->mock(UsageService::class, function ($m) use ($consume, $refunds) {
            $m->shouldReceive('weightFor')->andReturn(1);
            $m->shouldReceive('tryConsume')->andReturn($consume);
            $m->shouldReceive('refund')->times($refunds);
        });
    }

    public function test_min_plan_unlimited_funciona_como_allowlist(): void
    {
        $this->assertNull(GenModel::resolveSelectable('img-restrito', 'image', 'pro'));
        $this->assertNotNull(GenModel::resolveSelectable('img-restrito', 'image', 'unlimited'));
    }

    /** INVARIANTE: o t2i padrão tem que resolver pro MENOR plano. Se não resolver, todo
     *  fallback de imagem (Estúdio, Filme, Animação) devolve null e a geração morre calada. */
    public function test_t2i_padrao_resolve_para_o_menor_plano(): void
    {
        $menor = GenModel::PLAN_ORDER[0];
        $this->assertNotNull(
            GenModel::resolveSelectable(GenModel::DEFAULT_T2I, 'image', $menor),
            'O t2i padrão ('.GenModel::DEFAULT_T2I.") não resolve pro plano '$menor' — ".
            'todo fallback de imagem quebra em silêncio.'
        );
    }

    /** Motor marcado como async NÃO pode segurar a request: o Cloudflare corta em ~100s (524) e
     *  o usuário via erro numa imagem que fora gerada com sucesso. Enfileira e devolve draftId. */
    public function test_cursor_enfileira_em_vez_de_segurar_a_request(): void
    {
        Bus::fake();
        $this->mockUsage(true);
        Http::fake(['engine.test/*' => Http::response(['url' => 'https://s3.example.com/reachyn/image/cur.png'])]);

        $j = $this->actingAs($this->cliente)->postJson('/api/studio/media', [
            'kind' => 'image', 'prompt' => 'raposa mascote', 'aspect' => '1:1', 'model' => 'img-cli-cursor',
        ])->assertOk()->json();

        $this->assertTrue($j['ok']);
        $this->assertArrayNotHasKey('item', $j, 'sem `item` é o que faz o front cair no polling');
        $this->assertArrayHasKey('draftId', $j);

        Bus::assertDispatched(GenerateImageJob::class, fn ($job) => $job->payload['provider'] === 'cli-bridge'
            && $job->payload['model'] === 'cursor');

        // A request NÃO pode ter chamado o engine — quem chama é o worker.
        Http::assertNothingSent();
    }

    /** i2i também é async (98s medido). Resolve o modelo por outro ramo e monta o payload com
     *  `imageUrls` em vez de provider/model — se alguém mover a checagem de async para antes da
     *  montagem do payload, o job sairia sem a referência e a ancoragem de personagem quebraria. */
    public function test_i2i_enfileira_com_a_referencia_no_payload(): void
    {
        Bus::fake();
        $this->mockUsage(true);

        $src = 'https://s3.example.com/public/reachyn/image/base.jpg';
        $j = $this->actingAs($this->cliente)->postJson('/api/studio/media', [
            'kind' => 'image', 'prompt' => 'a mesma raposa sob chuva', 'imageUrl' => $src,
        ])->assertOk()->json();

        $this->assertArrayNotHasKey('item', $j);
        Bus::assertDispatched(GenerateImageJob::class, fn ($job) => ($job->payload['imageUrls'] ?? []) === [$src]);
    }

    /** Modelo SEM a flag async segue síncrono — segurar a request é a UX melhor quando a
     *  geração cabe com folga abaixo de ~90s. (Em prod hoje TODOS os motores de imagem estão
     *  marcados async; este teste trava o mecanismo, que continua valendo pro próximo modelo
     *  rápido que entrar. A fixture do img-cli-mmx aqui é criada sem a flag de propósito.) */
    public function test_modelo_sem_flag_async_segue_sincrono(): void
    {
        Bus::fake();
        $this->mockUsage(true);
        Http::fake(['engine.test/*' => Http::response(['url' => 'https://s3.example.com/reachyn/image/m.jpg'])]);

        $j = $this->actingAs($this->cliente)->postJson('/api/studio/media', [
            'kind' => 'image', 'prompt' => 'raposa', 'aspect' => '1:1', 'model' => 'img-cli-mmx',
        ])->assertOk()->json();

        $this->assertArrayHasKey('item', $j);
        Bus::assertNotDispatched(GenerateImageJob::class);
    }

    public function test_gera_via_cli_bridge_com_provider_e_model_internos(): void
    {
        $this->marca->organization->update(['plan' => 'unlimited']);
        $this->mockUsage(true);
        Http::fake(['engine.test/*' => Http::response(['url' => 'https://s3.example.com/reachyn/image/cli.jpg'])]);

        $j = $this->actingAs($this->cliente)->postJson('/api/studio/media', [
            'kind' => 'image', 'prompt' => 'raposa mascote', 'aspect' => '1:1', 'model' => 'img-cli-mmx',
        ])->assertOk()->json();

        $this->assertTrue($j['ok']);
        $this->assertSame('https://s3.example.com/reachyn/image/cli.jpg', $j['item']['url']);
        Http::assertSent(function ($req) {
            return str_ends_with($req->url(), '/v1/image')
                && $req['provider'] === 'cli-bridge'
                && $req['model'] === 'mmx';
        });
    }

    public function test_plano_menor_cai_no_t2i_padrao_sem_erro(): void
    {
        $this->marca->organization->update(['plan' => 'pro']);
        $this->mockUsage(true);
        Http::fake(['engine.test/*' => Http::response(['url' => 'https://s3.example.com/reachyn/image/pad.jpg'])]);

        $this->actingAs($this->cliente)->postJson('/api/studio/media', [
            'kind' => 'image', 'prompt' => 'raposa', 'model' => 'img-restrito',
        ])->assertOk();

        // O slug pedido não resolve pro plano → cai no t2i padrão (cli-bridge/mmx) SEM erro.
        Http::assertSent(fn ($req) => str_ends_with($req->url(), '/v1/image')
            && $req['provider'] === 'cli-bridge'
            && $req['model'] === 'mmx');
    }

    public function test_falha_do_engine_estorna_credito(): void
    {
        $this->marca->organization->update(['plan' => 'unlimited']);
        $this->mockUsage(true, refunds: 1);
        Http::fake(['engine.test/*' => Http::response(['url' => null])]);

        $this->actingAs($this->cliente)->postJson('/api/studio/media', [
            'kind' => 'image', 'prompt' => 'raposa', 'model' => 'img-cli-mmx',
        ])->assertStatus(502);
    }
}
