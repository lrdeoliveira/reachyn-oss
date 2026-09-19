<?php

namespace Tests\Feature;

use App\Models\OrgAsset;
use App\Models\Tenant;
use App\Models\User;
use App\Scopes\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Hub My Assets (org_assets) — reuso de mídia da marca. Trava: guarda só URL do S3 próprio,
 * dedup por URL, favorito, e isolamento por marca (não vê/apaga asset de outra).
 */
class StudioAssetsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $marca;

    private User $cliente;

    private string $img = 'https://s3.example.com/public/reachyn/image/a.jpg';

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
    }

    public function test_guarda_asset_do_s3_proprio(): void
    {
        $j = $this->actingAs($this->cliente)->postJson('/api/studio/assets', [
            'url' => $this->img, 'kind' => 'image', 'source' => 'generation', 'meta' => ['draft_id' => 7, 'prompt' => 'gato'],
        ])->assertOk()->json();

        $this->assertTrue($j['ok']);
        $this->assertDatabaseHas('org_assets', ['id' => $j['asset']['id'], 'tenant_id' => $this->marca->id, 'url' => $this->img]);
        $this->assertSame(7, $j['asset']['meta']['draft_id']);
    }

    public function test_recusa_url_de_fora(): void
    {
        $this->actingAs($this->cliente)->postJson('/api/studio/assets', ['url' => 'https://evil.example.com/x.jpg'])
            ->assertStatus(422);
    }

    public function test_dedup_por_url(): void
    {
        $a = $this->actingAs($this->cliente)->postJson('/api/studio/assets', ['url' => $this->img])->json();
        $b = $this->actingAs($this->cliente)->postJson('/api/studio/assets', ['url' => $this->img])->json();

        $this->assertSame($a['asset']['id'], $b['asset']['id']);
        $this->assertTrue($b['deduped']);
        $this->assertSame(1, OrgAsset::where('tenant_id', $this->marca->id)->count());
    }

    public function test_lista_filtra_kind_e_favorito(): void
    {
        OrgAsset::create(['tenant_id' => $this->marca->id, 'kind' => 'image', 'source' => 'upload', 'url' => $this->img, 'favorite' => true]);
        OrgAsset::create(['tenant_id' => $this->marca->id, 'kind' => 'video', 'source' => 'upload', 'url' => 'https://s3.example.com/v.mp4']);

        $imgs = $this->actingAs($this->cliente)->getJson('/api/studio/assets?kind=image')->assertOk()->json();
        $this->assertCount(1, $imgs['assets']);

        $favs = $this->actingAs($this->cliente)->getJson('/api/studio/assets?favorite=1')->assertOk()->json();
        $this->assertCount(1, $favs['assets']);
        $this->assertTrue($favs['assets'][0]['favorite']);
    }

    public function test_favorita_e_desfavorita(): void
    {
        $a = OrgAsset::create(['tenant_id' => $this->marca->id, 'kind' => 'image', 'source' => 'upload', 'url' => $this->img]);

        $j = $this->actingAs($this->cliente)->postJson("/api/studio/assets/{$a->id}/favorite")->assertOk()->json();
        $this->assertTrue($j['favorite']);
        $j = $this->actingAs($this->cliente)->postJson("/api/studio/assets/{$a->id}/favorite")->assertOk()->json();
        $this->assertFalse($j['favorite']);
    }

    public function test_nao_ve_nem_apaga_asset_de_outra_marca(): void
    {
        $outra = Tenant::factory()->create();
        $alheio = OrgAsset::create(['tenant_id' => $outra->id, 'kind' => 'image', 'source' => 'upload', 'url' => 'https://s3.example.com/o.jpg']);

        $list = $this->actingAs($this->cliente)->getJson('/api/studio/assets')->assertOk()->json();
        $this->assertNotContains($alheio->id, array_column($list['assets'], 'id'));

        $this->actingAs($this->cliente)->deleteJson("/api/studio/assets/{$alheio->id}")->assertNotFound();
        $this->assertDatabaseHas('org_assets', ['id' => $alheio->id]);
    }
}
