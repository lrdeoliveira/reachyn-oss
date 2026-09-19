<?php

namespace Tests\Feature;

use App\Jobs\GenerateVideoJob;
use App\Models\Draft;
use App\Models\Tenant;
use App\Models\User;
use App\Scopes\TenantScope;
use App\Services\UsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * 🗺️ Aventuras — juntar filmes prontos num filmão (/api/studio/adventure*).
 * Trava as invariantes: só filme COM montagem final entra; ownership por tenant;
 * a ordem escolhida é a ordem dos clipes; o filmão nasce num draft mode=adventure.
 */
class AdventureTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $marca;

    private User $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marca = Tenant::factory()->create();
        TenantScope::flushActiveTenantCache();
        $this->cliente = User::factory()->create(['tenant_id' => $this->marca->id, 'organization_id' => $this->marca->organization_id, 'role' => 'client']);
        // Cota nunca é o assunto destes testes — o UsageService tem suíte própria.
        $this->mock(UsageService::class, function ($m) {
            $m->shouldReceive('tryConsume')->andReturn(true);
            $m->shouldReceive('refund');
            $m->shouldReceive('weightFor')->andReturn(1);
        });
    }

    private function filmePronto(string $titulo, ?int $tenantId = null): Draft
    {
        return Draft::factory()->create([
            'tenant_id' => $tenantId ?? $this->marca->id,
            'keyword' => $titulo,
            'film' => ['mode' => 'keyframe', 'title' => $titulo, 'aspect' => '16:9', 'beats' => []],
            'media' => [['id' => 'm1', 'kind' => 'video', 'style' => 'filme', 'url' => 'https://s3.example.com/public/reachyn/video/'.$titulo.'.mp4']],
        ]);
    }

    public function test_lista_so_filmes_com_montagem_final(): void
    {
        $pronto = $this->filmePronto('corrida');
        // Filme SEM final (sem vídeo style=filme na galeria) não é insumo de aventura.
        Draft::factory()->create(['tenant_id' => $this->marca->id, 'film' => ['mode' => 'keyframe', 'beats' => []], 'media' => []]);

        $j = $this->actingAs($this->cliente)->getJson('/api/studio/adventure-films')->assertOk()->json();

        $this->assertSame([$pronto->id], array_column($j['films'], 'id'));
    }

    public function test_nao_lista_filme_de_outra_marca(): void
    {
        $outra = Tenant::factory()->create();
        $this->filmePronto('alheio', $outra->id);

        $j = $this->actingAs($this->cliente)->getJson('/api/studio/adventure-films')->assertOk()->json();

        $this->assertSame([], $j['films']);
    }

    public function test_juntar_exige_pelo_menos_2_filmes(): void
    {
        $a = $this->filmePronto('a');

        $this->actingAs($this->cliente)
            ->postJson('/api/studio/adventure', ['draftIds' => [$a->id]])
            ->assertStatus(422);
    }

    public function test_juntar_recusa_filme_sem_final_e_de_outra_marca(): void
    {
        $a = $this->filmePronto('a');
        $semFinal = Draft::factory()->create(['tenant_id' => $this->marca->id, 'film' => ['mode' => 'keyframe'], 'media' => []]);
        $outra = Tenant::factory()->create();
        $alheio = $this->filmePronto('alheio', $outra->id);

        $this->actingAs($this->cliente)
            ->postJson('/api/studio/adventure', ['draftIds' => [$a->id, $semFinal->id]])
            ->assertStatus(422);
        $this->actingAs($this->cliente)
            ->postJson('/api/studio/adventure', ['draftIds' => [$a->id, $alheio->id]])
            ->assertStatus(422);
    }

    public function test_juntar_cria_aventura_e_manda_os_finais_na_ordem(): void
    {
        Queue::fake();
        $a = $this->filmePronto('cap1');
        $b = $this->filmePronto('cap2');

        $j = $this->actingAs($this->cliente)
            ->postJson('/api/studio/adventure', ['draftIds' => [$b->id, $a->id], 'title' => 'Temporada 1'])
            ->assertOk()->json();

        $nd = Draft::find($j['draftId']);
        $this->assertSame('adventure', $nd->film['mode']);
        $this->assertSame([$b->id, $a->id], $nd->film['parts']);
        Queue::assertPushed(GenerateVideoJob::class, function ($job) use ($nd) {
            return $job->draftId === $nd->id
                && $job->endpoint === '/v1/filmassemble'
                && $job->style === 'aventura'
                && $job->payload['clipUrls'] === [
                    'https://s3.example.com/public/reachyn/video/cap2.mp4',
                    'https://s3.example.com/public/reachyn/video/cap1.mp4',
                ];
        });
        // A aventura recém-criada aparece na lista (sem url — montando).
        $lista = $this->actingAs($this->cliente)->getJson('/api/studio/adventure-films')->json();
        $this->assertSame([$nd->id], array_column($lista['adventures'], 'id'));
    }
}
