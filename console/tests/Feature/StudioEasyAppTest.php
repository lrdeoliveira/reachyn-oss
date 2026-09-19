<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\Tenant;
use App\Models\User;
use App\Scopes\TenantScope;
use App\Services\UsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * EasyApps de pós-produção (POST /api/studio/easyapp) — painel Ajustes / aba Rápido. Trava:
 * relight/product_bg fazem i2i e anexam o resultado à galeria; upscale/bg_remove delegam ao
 * enhance() existente; URL fora do nosso S3 = 422; kind inválido = 400; falha do engine estorna
 * o crédito (502).
 */
class StudioEasyAppTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $marca;

    private User $cliente;

    private string $img = 'https://s3.example.com/public/reachyn/image/base.jpg';

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
        $this->mockUsage(true);
    }

    private function mockUsage(bool $consume): void
    {
        $this->mock(UsageService::class, function ($m) use ($consume) {
            $m->shouldReceive('weightFor')->andReturn(1);
            $m->shouldReceive('tryConsume')->andReturn($consume);
            $m->shouldReceive('refund');
        });
    }

    public function test_relight_gera_e_anexa_na_galeria(): void
    {
        Http::fake(['engine.test/*' => Http::response(['url' => 'https://s3.example.com/out-relight.jpg'])]);

        $j = $this->actingAs($this->cliente)->postJson('/api/studio/easyapp', [
            'kind' => 'relight', 'imageUrl' => $this->img, 'params' => ['mood' => 'golden hour'],
        ])->assertOk()->json();

        $this->assertSame('relight', $j['item']['style']);
        $this->assertSame('https://s3.example.com/out-relight.jpg', $j['item']['url']);
        $this->assertDatabaseHas('drafts', ['id' => $j['draftId'], 'tenant_id' => $this->marca->id]);
        Http::assertSent(fn ($req) => str_contains($req->url(), '/v1/image')
            && str_contains((string) ($req['prompt'] ?? ''), 'golden hour'));
    }

    public function test_product_bg_troca_so_o_fundo(): void
    {
        Http::fake(['engine.test/*' => Http::response(['url' => 'https://s3.example.com/out-bg.jpg'])]);

        $j = $this->actingAs($this->cliente)->postJson('/api/studio/easyapp', [
            'kind' => 'product_bg', 'imageUrl' => $this->img, 'params' => ['background' => 'a marble kitchen counter'],
        ])->assertOk()->json();

        $this->assertSame('novo-fundo', $j['item']['style']);
        Http::assertSent(fn ($req) => str_contains((string) ($req['prompt'] ?? ''), 'marble kitchen counter'));
    }

    public function test_bg_remove_delega_ao_enhance(): void
    {
        Http::fake(['engine.test/*' => Http::response(['url' => 'https://s3.example.com/out-nobg.jpg'])]);

        $j = $this->actingAs($this->cliente)->postJson('/api/studio/easyapp', [
            'kind' => 'bg_remove', 'imageUrl' => $this->img,
        ])->assertOk()->json();

        // enhance() marca o remove_bg com style 'sem-fundo' → prova que delegou
        $this->assertSame('sem-fundo', $j['item']['style']);
    }

    public function test_kind_invalido_400(): void
    {
        $this->actingAs($this->cliente)->postJson('/api/studio/easyapp', [
            'kind' => 'face_swap', 'imageUrl' => $this->img,
        ])->assertStatus(400);
    }

    public function test_bg_change_troca_fundo_mantendo_sujeito(): void
    {
        Http::fake(['engine.test/*' => Http::response(['url' => 'https://s3.example.com/out-bg2.jpg'])]);

        $j = $this->actingAs($this->cliente)->postJson('/api/studio/easyapp', [
            'kind' => 'bg_change', 'imageUrl' => $this->img, 'params' => ['background' => 'a sunny beach'],
        ])->assertOk()->json();

        $this->assertSame('novo-fundo', $j['item']['style']);
        Http::assertSent(fn ($req) => str_contains((string) ($req['prompt'] ?? ''), 'sunny beach'));
    }

    public function test_cloth_change_exige_personagem_da_biblioteca(): void
    {
        // sem characterId → 422 (guardrail anti-deepfake)
        $this->actingAs($this->cliente)->postJson('/api/studio/easyapp', [
            'kind' => 'cloth_change', 'imageUrl' => $this->img, 'params' => ['outfit' => 'a red dress'],
        ])->assertStatus(422);

        // personagem de OUTRA marca → 422 (não é da biblioteca do tenant)
        $outra = Tenant::factory()->create();
        $alheio = Character::create(['tenant_id' => $outra->id, 'name' => 'Alheio', 'status' => '']);
        $this->actingAs($this->cliente)->postJson('/api/studio/easyapp', [
            'kind' => 'cloth_change', 'imageUrl' => $this->img, 'characterId' => $alheio->id,
        ])->assertStatus(422);
    }

    public function test_cloth_change_com_personagem_injeta_lock(): void
    {
        Http::fake(['engine.test/*' => Http::response(['url' => 'https://s3.example.com/out-cloth.jpg'])]);
        $c = Character::create(['tenant_id' => $this->marca->id, 'name' => 'Mel', 'lock' => 'MEL: golden retriever, red collar', 'status' => '']);

        $j = $this->actingAs($this->cliente)->postJson('/api/studio/easyapp', [
            'kind' => 'cloth_change', 'imageUrl' => $this->img, 'characterId' => $c->id, 'params' => ['outfit' => 'a superhero cape'],
        ])->assertOk()->json();

        $this->assertSame('nova-roupa', $j['item']['style']);
        Http::assertSent(fn ($req) => str_contains((string) ($req['prompt'] ?? ''), 'golden retriever, red collar')
            && str_contains((string) ($req['prompt'] ?? ''), 'superhero cape'));
    }

    public function test_face_detail_refina_sem_swap(): void
    {
        Http::fake(['engine.test/*' => Http::response(['url' => 'https://s3.example.com/out-face.jpg'])]);

        $j = $this->actingAs($this->cliente)->postJson('/api/studio/easyapp', [
            'kind' => 'face_detail', 'imageUrl' => $this->img,
        ])->assertOk()->json();

        $this->assertSame('rosto-detalhado', $j['item']['style']);
        Http::assertSent(fn ($req) => str_contains((string) ($req['prompt'] ?? ''), 'REFINEMENT only'));
    }

    public function test_url_fora_do_s3_proprio_422(): void
    {
        $this->actingAs($this->cliente)->postJson('/api/studio/easyapp', [
            'kind' => 'relight', 'imageUrl' => 'https://evil.example.com/x.jpg',
        ])->assertStatus(422);
    }

    public function test_sem_credito_402(): void
    {
        $this->mockUsage(false);
        Http::fake(['engine.test/*' => Http::response(['url' => 'https://s3.example.com/x.jpg'])]);

        $this->actingAs($this->cliente)->postJson('/api/studio/easyapp', [
            'kind' => 'relight', 'imageUrl' => $this->img,
        ])->assertStatus(402);
    }

    public function test_falha_do_engine_estorna_e_502(): void
    {
        Http::fake(['engine.test/*' => Http::response(['url' => null])]); // engine não retornou imagem

        $usage = \Mockery::mock(UsageService::class);
        $usage->shouldReceive('weightFor')->andReturn(1);
        $usage->shouldReceive('tryConsume')->andReturn(true);
        $usage->shouldReceive('refund')->once(); // ESTORNO obrigatório na falha
        $this->app->instance(UsageService::class, $usage);

        $this->actingAs($this->cliente)->postJson('/api/studio/easyapp', [
            'kind' => 'relight', 'imageUrl' => $this->img,
        ])->assertStatus(502);
    }
}
