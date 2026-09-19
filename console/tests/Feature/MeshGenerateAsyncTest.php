<?php

namespace Tests\Feature;

use App\Jobs\GenerateMeshJob;
use App\Models\Character;
use App\Models\Element;
use App\Models\Organization;
use App\Models\Tenant;
use App\Models\User;
use App\Scopes\TenantScope;
use App\Services\UsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * POST /api/mesh-generate — 🧊 a malha 3D deixou de ser síncrona (2026-08-02).
 *
 * O QUE ISTO TRAVA, e por quê: a geração leva ~5 minutos de GPU. Enquanto ela acontecia DENTRO do
 * request, o navegador desistia no meio e o servidor registrava HTTP 499 (conexão fechada pelo
 * cliente) — a malha até saía, mas o clique do usuário sempre terminava em falha. Então:
 *
 *  (1) o endpoint ENFILEIRA e responde na hora, marcando `mesh_status = 'gerando'` e SEM devolver
 *      `mesh_url` — é a ausência da malha na resposta que joga o front no polling;
 *  (2) NADA é falado com o engine dentro do request (é o que garante que ele volta rápido);
 *  (3) segunda geração no mesmo asset enquanto a primeira roda volta 409 (a GPU é uma só);
 *  (4) o job que FALHA em definitivo marca 'erro' e ESTORNA a reserva — o cliente não paga por
 *      malha que não recebeu (reserve-then-consume).
 */
class MeshGenerateAsyncTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $marca;

    private User $cliente;

    private const IMG = 'https://s3.example.com/public/reachyn/image/base.jpg';

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
    }

    private function personagem(array $extra = []): Character
    {
        return Character::create(array_merge([
            'tenant_id' => $this->marca->id,
            'name' => 'Mel',
            'description' => 'raposa',
            'style' => 'realista',
            'base_url' => self::IMG,
            'status' => '',
        ], $extra));
    }

    public function test_sem_auth_e_401(): void
    {
        $this->postJson('/api/mesh-generate', ['tipo' => 'character', 'id' => 1, 'imageUrl' => self::IMG])
            ->assertStatus(401);
    }

    /** O caminho novo: enfileira, marca 'gerando', não fala com o engine e não devolve a malha. */
    public function test_enfileira_e_devolve_status_sem_esperar_a_malha(): void
    {
        Queue::fake();
        Http::fake();
        $c = $this->personagem();

        $this->actingAs($this->cliente)
            ->postJson('/api/mesh-generate', ['tipo' => 'character', 'id' => $c->id, 'imageUrl' => self::IMG])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('async', true)
            ->assertJsonPath('asset.mesh_status', 'gerando')
            // Sem `mesh_url` na resposta: é este vazio que diz ao front "caia no polling".
            ->assertJsonMissingPath('mesh_url');

        Queue::assertPushed(GenerateMeshJob::class, fn (GenerateMeshJob $j) => $j->tipo === 'character' && $j->donoId === $c->id);
        // O request NÃO pode ter esperado o engine — era exatamente isso que dava 499.
        Http::assertNothingSent();
        $this->assertSame('gerando', $c->fresh()->mesh_status);
    }

    /** Elemento entra pelo mesmo endpoint (é o mesmo arquivo e a mesma checagem de tenant). */
    public function test_elemento_tambem_enfileira(): void
    {
        Queue::fake();
        $e = Element::create([
            'tenant_id' => $this->marca->id, 'name' => 'Carro', 'categoria' => 'veiculo', 'image_url' => self::IMG,
        ]);

        $this->actingAs($this->cliente)
            ->postJson('/api/mesh-generate', ['tipo' => 'element', 'id' => $e->id, 'imageUrl' => self::IMG])
            ->assertOk()
            ->assertJsonPath('asset.mesh_status', 'gerando');

        Queue::assertPushed(GenerateMeshJob::class, fn (GenerateMeshJob $j) => $j->tipo === 'element' && $j->donoId === $e->id);
    }

    /** Duplo-clique não põe duas malhas na mesma GPU. */
    public function test_segunda_geracao_enquanto_a_primeira_roda_e_409(): void
    {
        Queue::fake();
        $c = $this->personagem(['mesh_status' => 'gerando']);

        $this->actingAs($this->cliente)
            ->postJson('/api/mesh-generate', ['tipo' => 'character', 'id' => $c->id, 'imageUrl' => self::IMG])
            ->assertStatus(409)
            ->assertJsonPath('ok', false);

        Queue::assertNothingPushed();
    }

    /** Cenário não tem malha — continua 422, como antes. */
    public function test_cenario_nao_tem_malha(): void
    {
        Queue::fake();
        $this->actingAs($this->cliente)
            ->postJson('/api/mesh-generate', ['tipo' => 'scenario', 'id' => 1, 'imageUrl' => self::IMG])
            ->assertStatus(422);

        Queue::assertNothingPushed();
    }

    /** Anti-SSRF preservado: imagem de fora do nosso acervo nem chega a virar job. */
    public function test_imagem_de_fora_do_acervo_e_422(): void
    {
        Queue::fake();
        $c = $this->personagem();

        $this->actingAs($this->cliente)
            ->postJson('/api/mesh-generate', ['tipo' => 'character', 'id' => $c->id, 'imageUrl' => 'https://evil.example.com/x.jpg'])
            ->assertStatus(422);

        Queue::assertNothingPushed();
    }

    /** Falha DEFINITIVA do job: estado vira 'erro' na tela e a reserva é estornada. */
    public function test_job_que_falha_marca_erro_e_estorna(): void
    {
        $c = $this->personagem(['mesh_status' => 'gerando']);

        $usage = app(UsageService::class);
        $usage->tryConsume($this->marca, 'image', 1, 0);
        $antes = (int) $this->marca->usages()->where('period', $usage->period())->where('kind', 'image')->value('count');

        (new GenerateMeshJob('character', $c->id, $this->marca->id, ['imageUrl' => self::IMG], 1, 0))
            ->failed(new \RuntimeException('worker morreu'));

        $this->assertSame('erro', $c->fresh()->mesh_status);
        $depois = (int) $this->marca->usages()->where('period', $usage->period())->where('kind', 'image')->value('count');
        $this->assertSame($antes - 1, $depois, 'a reserva tem de voltar quando a malha não vem');
    }

    /** Engine sem URL = malha não veio: 'erro' na tela e estorno (o job não pode ficar em silêncio). */
    public function test_engine_sem_url_marca_erro_e_estorna(): void
    {
        Http::fake(['*/v1/mesh/generate' => Http::response(['error' => 'sem gpu'], 502)]);
        $c = $this->personagem(['mesh_status' => 'gerando']);

        $usage = app(UsageService::class);
        $usage->tryConsume($this->marca, 'image', 1, 0);
        $antes = (int) $this->marca->usages()->where('period', $usage->period())->where('kind', 'image')->value('count');

        (new GenerateMeshJob('character', $c->id, $this->marca->id, ['imageUrl' => self::IMG], 1, 0))->handle($usage);

        $this->assertSame('erro', $c->fresh()->mesh_status);
        $this->assertNull($c->fresh()->mesh_url);
        $depois = (int) $this->marca->usages()->where('period', $usage->period())->where('kind', 'image')->value('count');
        $this->assertSame($antes - 1, $depois);
    }

    /** Caminho feliz do worker: grava a malha e LIMPA o status — é isso que o polling espera ver. */
    public function test_job_ok_grava_a_malha_e_limpa_o_status(): void
    {
        $glb = 'https://s3.example.com/public/reachyn/mesh/mel.glb';
        Http::fake(['*/v1/mesh/generate' => Http::response(['url' => $glb])]);
        $c = $this->personagem(['mesh_status' => 'gerando']);

        (new GenerateMeshJob('character', $c->id, $this->marca->id, ['imageUrl' => self::IMG], 1, 0))
            ->handle(app(UsageService::class));

        $c->refresh();
        $this->assertSame($glb, $c->mesh_url);
        $this->assertNull($c->mesh_status);
    }

    /** Ressalva do juiz de visão vira estado 'aviso' — a malha VALE, mas a tela precisa avisar. */
    public function test_aviso_do_juiz_vira_status_aviso_com_a_malha_gravada(): void
    {
        $glb = 'https://s3.example.com/public/reachyn/mesh/mel.glb';
        Http::fake(['*/v1/mesh/generate' => Http::response(['url' => $glb, 'aviso' => 'pedestal na base'])]);
        $c = $this->personagem(['mesh_status' => 'gerando']);

        (new GenerateMeshJob('character', $c->id, $this->marca->id, ['imageUrl' => self::IMG], 1, 0))
            ->handle(app(UsageService::class));

        $c->refresh();
        $this->assertSame($glb, $c->mesh_url);
        $this->assertSame('aviso', $c->mesh_status);
    }
}
