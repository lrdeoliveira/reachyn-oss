<?php

namespace Tests\Feature;

use App\Models\Draft;
use App\Models\Tenant;
use App\Models\User;
use App\Scopes\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 📁 Adotar mídia da GALERIA sem trafegar bytes pelo navegador.
 *
 * O bug que originou (2026-08-04): a tela de publicar baixava a mídia com `fetch()` pra reenviar
 * como upload. O storage não devolve `Access-Control-Allow-Origin`, então o navegador bloqueava a
 * leitura e o usuário via "Não foi possível carregar essa mídia da galeria" — em QUALQUER item.
 * O arquivo já é nosso: o servidor anexa por URL.
 */
class AdotaMidiaDaGaleriaTest extends TestCase
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
        config(['filesystems.disks.media.url' => 'https://s3.exemplo.test/public']);
    }

    public function test_adota_video_da_galeria_e_reconhece_o_tipo(): void
    {
        $d = Draft::factory()->create(['tenant_id' => $this->marca->id]);

        $this->actingAs($this->cliente)->postJson('/api/studio/adopt', [
            'draftId' => $d->id,
            'url' => 'https://s3.exemplo.test/public/reachyn/videos/1785854223752-abc.mp4',
        ])->assertOk()->assertJson(['ok' => true, 'kind' => 'video']);

        $midia = $d->fresh()->media;
        $this->assertSame('video', $midia[0]['kind'], 'mp4 tem que entrar como vídeo, não como imagem');
    }

    public function test_adota_imagem_da_galeria(): void
    {
        $d = Draft::factory()->create(['tenant_id' => $this->marca->id]);

        $this->actingAs($this->cliente)->postJson('/api/studio/adopt', [
            'draftId' => $d->id,
            'url' => 'https://s3.exemplo.test/public/reachyn/image/foto.jpg',
        ])->assertOk()->assertJson(['ok' => true, 'kind' => 'image']);
    }

    /** Anti-SSRF: só mídia do NOSSO storage entra — a mesma trava das referências de geração. */
    public function test_recusa_url_de_fora(): void
    {
        $d = Draft::factory()->create(['tenant_id' => $this->marca->id]);

        $this->actingAs($this->cliente)->postJson('/api/studio/adopt', [
            'draftId' => $d->id,
            'url' => 'https://exemplo-de-fora.com/foto.jpg',
        ])->assertStatus(422);

        $this->assertEmpty($d->fresh()->media ?? []);
    }
}
