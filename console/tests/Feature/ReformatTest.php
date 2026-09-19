<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Scopes\TenantScope;
use App\Support\FormatVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 🖼️ /api/studio/reformat — guard-rails do contrato que a galeria consome.
 *
 * O caso feliz (a renderização em si) é coberto em Tests\Unit\FormatVariantTest, que exercita o
 * GD de verdade. Aqui ficam as recusas: elas é que impedem o endpoint de virar um proxy de
 * download arbitrário (o backend baixa a URL que receber).
 */
class ReformatTest extends TestCase
{
    use RefreshDatabase;

    private User $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        $marca = Tenant::factory()->create();
        TenantScope::flushActiveTenantCache();
        $this->cliente = User::factory()->create([
            'tenant_id' => $marca->id,
            'organization_id' => $marca->organization_id,
            'role' => 'client',
        ]);
    }

    public function test_exige_autenticacao(): void
    {
        $this->postJson('/api/studio/reformat', [
            'imageUrl' => 'https://s3.example.com/public/reachyn/image/a.jpg',
            'formats' => ['feed'],
        ])->assertUnauthorized();
    }

    public function test_url_fora_do_nosso_storage_e_recusada(): void
    {
        // AUD-013/014: sem isto o endpoint baixaria qualquer coisa que mandassem (SSRF).
        foreach ([
            'https://evil.example.com/x.jpg',
            'file:///etc/passwd',
            'http://169.254.169.254/latest/meta-data/',
            '',
        ] as $url) {
            $this->actingAs($this->cliente)
                ->postJson('/api/studio/reformat', ['imageUrl' => $url, 'formats' => ['feed']])
                ->assertStatus(422);
        }
    }

    public function test_formato_desconhecido_e_recusado_com_a_lista(): void
    {
        $r = $this->actingAs($this->cliente)->postJson('/api/studio/reformat', [
            'imageUrl' => 'https://s3.example.com/public/reachyn/image/a.jpg',
            'formats' => ['banner_gigante', 'feed;rm -rf', 42],
        ])->assertStatus(422);

        $this->assertSame(array_keys(FormatVariant::SIZES), $r->json('supported'));
    }

    public function test_lista_de_formatos_vazia_e_recusada(): void
    {
        $this->actingAs($this->cliente)->postJson('/api/studio/reformat', [
            'imageUrl' => 'https://s3.example.com/public/reachyn/image/a.jpg',
            'formats' => [],
        ])->assertStatus(422);
    }
}
