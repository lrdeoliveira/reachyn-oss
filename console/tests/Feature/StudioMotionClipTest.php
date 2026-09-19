<?php

namespace Tests\Feature;

use App\Jobs\GenerateVideoJob;
use App\Models\Draft;
use App\Models\GenModel;
use App\Models\Organization;
use App\Models\Tenant;
use App\Models\User;
use App\Scopes\TenantScope;
use App\Support\MotionPrompt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * POST /api/studio/motion-clip — 🎞️ MOTION: anima a TELA ESTÁTICA aprovada.
 *
 * Trava quatro coisas: (1) a `imageUrl` da tela vem do CLIENTE, então URL de fora do nosso
 * storage é barrada (anti-SSRF) antes de virar referência do clipe; (2) estrutura e técnica são
 * allowlist FECHADA — viram texto no prompt, então lixo cai no default e nunca vaza; (3) o
 * prompt é montado no SERVIDOR (as duas regras fixas têm de estar lá, senão o modelo redesenha
 * os elementos e a identidade da peça se perde); (4) o caminho feliz enfileira o i2v com a tela
 * como `imageUrl` — é isso que diferencia "animar a peça" de "animar do nada".
 */
class StudioMotionClipTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $marca;

    private User $cliente;

    private const TELA = 'https://s3.example.com/public/reachyn/image/tela.jpg';

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
        // provider REAL do catálogo pós-saída do agregador (2026-08-03). Enquanto este fixture
        // dizia 'kie', o teste passava com um mundo que não existe mais: em prod nenhum modelo de
        // vídeo é 'kie', e a guarda do motionClip recusava TODO clique no card Motion. Fixture que
        // descreve catálogo morto é teste que protege o bug.
        GenModel::create([
            'slug' => 'vid-motion', 'kind' => 'video', 'provider' => 'cli-bridge',
            'provider_model_id' => 'higgsfield:seedance_2_0', 'display_name' => 'Motion', 'is_active' => true,
            'cost_credits' => 5,
        ]);
    }

    private function payload(array $extra = []): array
    {
        return array_merge([
            'imageUrl' => self::TELA, 'structure' => 'camadas', 'style' => 'colagem',
            'seconds' => 10, 'aspect' => '16:9', 'model' => 'vid-motion',
            'description' => 'como um pedido chega até a sua porta',
        ], $extra);
    }

    public function test_sem_auth_e_401(): void
    {
        $this->postJson('/api/studio/motion-clip', $this->payload())->assertStatus(401);
    }

    public function test_url_de_fora_do_nosso_storage_e_422(): void
    {
        Queue::fake();
        $this->actingAs($this->cliente)
            ->postJson('/api/studio/motion-clip', $this->payload(['imageUrl' => 'https://evil.example.com/x.jpg']))
            ->assertStatus(422);

        Queue::assertNothingPushed();
    }

    public function test_cartelas_sem_frase_e_422(): void
    {
        Queue::fake();
        $this->actingAs($this->cliente)
            ->postJson('/api/studio/motion-clip', $this->payload(['structure' => 'cartelas', 'lines' => []]))
            ->assertStatus(422);

        Queue::assertNothingPushed();
    }

    public function test_sem_modelo_de_video_do_plano_e_422(): void
    {
        Queue::fake();
        $this->actingAs($this->cliente)
            ->postJson('/api/studio/motion-clip', $this->payload(['model' => 'nao-existe']))
            ->assertStatus(422);

        Queue::assertNothingPushed();
    }

    public function test_caminho_feliz_enfileira_o_i2v_com_a_tela_aprovada(): void
    {
        Queue::fake();
        $d = Draft::create(['tenant_id' => $this->marca->id, 'keyword' => 'motion']);

        $this->actingAs($this->cliente)
            ->postJson('/api/studio/motion-clip', $this->payload(['draftId' => $d->id]))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('seconds', 10);

        Queue::assertPushed(GenerateVideoJob::class, function ($job) {
            $p = (array) $this->jobPayload($job);
            $prompt = (string) ($p['prompt'] ?? '');

            return ($p['imageUrl'] ?? null) === self::TELA
                && ($p['narration'] ?? true) === false
                && ($p['duration'] ?? null) === '10'
                && ($p['aspect'] ?? null) === '16:9'
                && str_contains($prompt, MotionPrompt::REGRA_ELEMENTOS)
                && str_contains($prompt, 'no voiceover')
                && str_contains($prompt, 'STYLE LOCK');
        });
    }

    public function test_estrutura_e_tecnica_fora_da_allowlist_caem_no_default(): void
    {
        // Não é 422 de propósito: são campos de VOCABULÁRIO, não de intenção do usuário — o
        // default é uma peça válida. O que não pode é o texto do cliente virar prompt.
        $this->assertSame('camadas', MotionPrompt::estrutura('<script>alert(1)</script>'));
        $this->assertSame('colagem', MotionPrompt::tecnica('qualquer-coisa'));
        $this->assertSame(MotionPrompt::DUR_MAX, MotionPrompt::duracao(999));
        $this->assertSame(MotionPrompt::DUR_MIN, MotionPrompt::duracao(1));
        $this->assertCount(MotionPrompt::MAX_FRASES, MotionPrompt::frases(array_fill(0, 20, 'a')));
    }

    /** O payload do job é privado: lê via reflexão (mesma leitura que o worker faria). */
    private function jobPayload(GenerateVideoJob $job): array
    {
        $ref = new \ReflectionClass($job);
        foreach ($ref->getProperties() as $p) {
            $p->setAccessible(true);
            $v = $p->getValue($job);
            if (is_array($v) && isset($v['prompt'])) {
                return $v;
            }
        }

        return [];
    }
}
