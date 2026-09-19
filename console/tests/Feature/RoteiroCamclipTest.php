<?php

namespace Tests\Feature;

use App\Models\Draft;
use App\Models\Organization;
use App\Models\Tenant;
use App\Models\User;
use App\Scopes\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * POST /api/roteiro/camclip — 🎞️ CÂMERA PROGRAMADA da Montagem (sem IA, sem crédito).
 *
 * Trava três coisas: (1) só os 7 movimentos que o ffmpeg-service reproduz de verdade passam —
 * o resto precisa da IA de vídeo e volta 422 com a lista suportada; (2) a `imageUrl` vem do
 * CLIENTE, então URL de fora do nosso storage é barrada (anti-SSRF) antes de virar requisição
 * do serviço interno; (3) o clipe é gravado em film.beats[i] — mesma estrutura que
 * roteiroRender grava e roteiroStatus lê, senão ele nunca aparece na tela.
 */
class RoteiroCamclipTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $marca;

    private User $cliente;

    private const OK = 'https://s3.example.com/public/reachyn/image/a.jpg';

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
        config(['services.ffmpeg.url' => 'http://ffmpeg.test', 'services.ffmpeg.token' => 'x']);
    }

    public function test_sem_auth_e_401(): void
    {
        $this->postJson('/api/roteiro/camclip', [
            'index' => 0, 'imageUrl' => self::OK, 'move' => 'push_in',
        ])->assertStatus(401);
    }

    public function test_movimento_fora_da_allowlist_e_422_com_suportados(): void
    {
        Http::fake();
        $this->actingAs($this->cliente)
            ->postJson('/api/roteiro/camclip', [
                'index' => 0, 'imageUrl' => self::OK, 'move' => 'orbit_360',
            ])
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('supported.0', 'static');

        Http::assertNothingSent();
    }

    public function test_url_de_fora_do_nosso_storage_e_422(): void
    {
        Http::fake();
        $this->actingAs($this->cliente)
            ->postJson('/api/roteiro/camclip', [
                'index' => 0, 'imageUrl' => 'https://evil.example.com/x.jpg', 'move' => 'push_in',
            ])
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_caminho_feliz_grava_o_clipe_no_rascunho(): void
    {
        Http::fake(['*/camclip' => Http::response(['url' => 'https://s3.example.com/public/reachyn/video/c.mp4'])]);
        $d = Draft::create(['tenant_id' => $this->marca->id, 'keyword' => 'roteiro']);

        $this->actingAs($this->cliente)
            ->postJson('/api/roteiro/camclip', [
                'draftId' => $d->id, 'index' => 1, 'imageUrl' => self::OK,
                'move' => 'pan_left', 'duration' => '10', 'aspect' => '16:9',
            ])
            ->assertOk()
            ->assertJsonPath('url', 'https://s3.example.com/public/reachyn/video/c.mp4');

        Http::assertSent(fn ($req) => str_ends_with($req->url(), '/camclip')
            && $req['move'] === 'pan_left' && $req['duration'] === 10 && $req['aspect'] === '16:9');

        $beats = (array) ($d->fresh()->film['beats'] ?? []);
        $this->assertSame('https://s3.example.com/public/reachyn/video/c.mp4', $beats[1]['clip_url']);
        $this->assertSame('camera', $beats[1]['clip_engine']);
        $this->assertSame('pan_left', $beats[1]['clip_move']);
    }
}
