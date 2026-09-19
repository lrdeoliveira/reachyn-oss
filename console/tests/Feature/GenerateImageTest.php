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
 * POST /api/generate/image — o proxy SÍNCRONO de imagem.
 *
 * 🐛 REGRESSÃO que este teste tranca: a rota foi removida em 2026-07-16 sob a alegação de que
 * não tinha caller. Tinha 4 (abas Imagem, Sprite ×2, Ficha do personagem), todos com `await` na
 * URL — em prod virou 404. Voltou, mas com a GUARDA que faltava: motor `capabilities.async`
 * (110-145s) é recusado com 422 em vez de segurar o PHP-FPM até o timeout, que era o problema
 * real por trás da remoção.
 */
class GenerateImageTest extends TestCase
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
            'display_name' => 'Motor '.$slug, 'kind' => 'image', 'subtype' => 'text_to_image',
            'provider' => 'kie', 'provider_model_id' => 'seedream/4.5',
            'cost_credits' => 5, 'is_active' => true, 'min_plan' => null,
            'capabilities' => $caps,
        ]);
    }

    public function test_gera_e_devolve_url(): void
    {
        $this->modelo('img-rapido');
        Http::fake(['*/v1/image' => Http::response(['url' => 'https://s3.example.com/public/reachyn/image/x.jpg'])]);

        $j = $this->actingAs($this->cliente)
            ->postJson('/api/generate/image', ['prompt' => 'uma raposa vermelha', 'model' => 'img-rapido', 'aspect' => '1:1'])
            ->assertOk()->json();

        $this->assertTrue($j['ok']);
        $this->assertSame('https://s3.example.com/public/reachyn/image/x.jpg', $j['url']);
        $this->assertSame('img-rapido', $j['model']);
        Http::assertSent(fn ($req) => str_contains($req->url(), '/v1/image') && $req['aspect'] === '1:1');
    }

    public function test_modelo_async_recusa_sem_chamar_o_engine(): void
    {
        $this->modelo('img-cli-mmx', ['async' => true]);
        Http::fake();

        $this->actingAs($this->cliente)
            ->postJson('/api/generate/image', ['prompt' => 'uma raposa', 'model' => 'img-cli-mmx'])
            ->assertStatus(422);

        Http::assertNothingSent(); // a guarda corta ANTES do proxy — é o que salva o PHP-FPM
    }

    public function test_sem_auth_da_401(): void
    {
        $this->postJson('/api/generate/image', ['prompt' => 'uma raposa'])->assertStatus(401);
    }
}
