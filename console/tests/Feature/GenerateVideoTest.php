<?php

namespace Tests\Feature;

use App\Models\GenModel;
use App\Models\Organization;
use App\Models\Tenant;
use App\Models\User;
use App\Scopes\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * POST /api/generate/video — o proxy SÍNCRONO de clipe.
 *
 * 🐛 REGRESSÃO que este teste tranca: a rota foi removida em 2026-07-16 sob a alegação de que não
 * tinha caller. Tinha 3 (abas Vídeo, Montagem e Sprites), todos com `await` na URL do clipe — em
 * prod virou 404. Voltou, mas com as guardas que faltavam: motor `capabilities.async` e motor
 * premium (Veo, que roteia pro /v1/veo lento) são recusados com 422 em vez de segurar o PHP-FPM
 * até o timeout, que era o problema real por trás da remoção.
 */
class GenerateVideoTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $marca;

    private User $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marca = Tenant::factory()->for(Organization::factory()->paying('studio', 100000))->create();
        TenantScope::flushActiveTenantCache();
        $this->cliente = User::factory()->create([
            'tenant_id' => $this->marca->id,
            'organization_id' => $this->marca->organization_id,
            'role' => 'client',
        ]);
        config(['services.engine.url' => 'http://engine.test', 'services.engine.admin_token' => 'x']);
    }

    private function modelo(string $slug, array $caps = []): GenModel
    {
        return GenModel::updateOrCreate(['slug' => $slug], [
            'display_name' => 'Motor '.$slug, 'kind' => 'video', 'subtype' => 'text_to_video',
            'provider' => 'kie', 'provider_model_id' => 'seedance-1.5-pro',
            'cost_credits' => 20, 'is_active' => true, 'min_plan' => null,
            'capabilities' => $caps,
        ]);
    }

    public function test_gera_e_devolve_url(): void
    {
        $this->modelo('vid-rapido');
        Http::fake(['*/v1/video' => Http::response(['url' => 'https://s3.example.com/public/reachyn/video/x.mp4'])]);

        $j = $this->actingAs($this->cliente)
            ->postJson('/api/generate/video', [
                'prompt' => 'uma raposa vermelha correndo', 'model' => 'vid-rapido',
                'aspect' => '16:9', 'duration' => '10',
            ])
            ->assertOk()->json();

        $this->assertTrue($j['ok']);
        $this->assertSame('https://s3.example.com/public/reachyn/video/x.mp4', $j['url']);
        $this->assertSame('vid-rapido', $j['model']);
        Http::assertSent(fn ($req) => str_contains($req->url(), '/v1/video')
            && $req['aspect'] === '16:9' && $req['duration'] === '10' && $req['scenes'] === 1);
    }

    /** Campos que o engine NÃO lê não podem virar pass-through decorativo. */
    public function test_nao_repassa_campos_nao_suportados(): void
    {
        $this->modelo('vid-rapido');
        Http::fake(['*/v1/video' => Http::response(['url' => 'https://s3.example.com/public/reachyn/video/x.mp4'])]);

        $this->actingAs($this->cliente)
            ->postJson('/api/generate/video', [
                'prompt' => 'uma raposa', 'model' => 'vid-rapido',
                'smooth' => true, 'keyframes' => true, 'separateParts' => true,
                'narrationText' => 'texto falado', 'imageUrls' => ['https://x.test/a.jpg'],
            ])->assertOk();

        Http::assertSent(function ($req) {
            foreach (['smooth', 'keyframes', 'separateParts', 'narrationText', 'imageUrls'] as $k) {
                if (array_key_exists($k, $req->data())) {
                    return false;
                }
            }

            return true;
        });
    }

    public function test_modelo_async_recusa_sem_chamar_o_engine(): void
    {
        $this->modelo('vid-cli-mmx', ['async' => true]);
        Http::fake();

        $this->actingAs($this->cliente)
            ->postJson('/api/generate/video', ['prompt' => 'uma raposa', 'model' => 'vid-cli-mmx'])
            ->assertStatus(422);

        Http::assertNothingSent(); // a guarda corta ANTES do proxy — é o que salva o PHP-FPM
    }

    public function test_modelo_premium_veo_recusa_sem_chamar_o_engine(): void
    {
        $this->modelo('vid-premium', ['veo' => true]);
        Http::fake();

        $this->actingAs($this->cliente)
            ->postJson('/api/generate/video', ['prompt' => 'uma raposa', 'model' => 'vid-premium'])
            ->assertStatus(422);

        Http::assertNothingSent(); // Veo roteia pro /v1/veo: lento demais pra segurar a conexão
    }

    public function test_sem_auth_da_401(): void
    {
        $this->postJson('/api/generate/video', ['prompt' => 'uma raposa'])->assertStatus(401);
    }
}
