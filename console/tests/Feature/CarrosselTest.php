<?php

namespace Tests\Feature;

use App\Jobs\GenerateCarouselPlanJob;
use App\Jobs\GenerateCarouselSlideJob;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\Tenant;
use App\Models\User;
use App\Scopes\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * 🎠 CARROSSEL — plano editorial e render de slides.
 *
 * O QUE ISTO TRAVA, e por quê:
 *
 *  (1) O PLANO NÃO GASTA IMAGEM. É a decisão central da feature: um carrossel de 9 slides são 9
 *      imagens, e o usuário precisa ler a headline e a narrativa ANTES de qualquer cobrança de
 *      imagem. Se alguém um dia fizer o plano já disparar render, este teste quebra.
 *  (2) SEM PLANO NÃO HÁ RENDER — 422 em vez de enfileirar N jobs sobre um array vazio.
 *  (3) A CAPA VAI PRIMEIRO, sozinha, levando os demais slides na bagagem. É o que faz N imagens
 *      parecerem uma peça só: os internos usam a capa como referência visual. Um fan-out de N
 *      jobs paralelos desde o início geraria N imagens sem parentesco entre si.
 *  (4) Re-render de UM slide não redispara o carrossel inteiro (o caminho barato de consertar um
 *      slide fraco).
 */
class CarrosselTest extends TestCase
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
    }

    /** Um rascunho com plano pronto, como o job de plano o deixaria. */
    private function comPlano(int $n = 9): Draft
    {
        $slides = [];
        for ($i = 0; $i < $n; $i++) {
            $slides[] = [
                'index' => $i + 1,
                'role' => $i === 0 ? 'capa' : ($i === $n - 1 ? 'assinatura' : 'hook'),
                'tag' => $i === 0 ? '' : 'O PROBLEMA',
                'blocks' => ['Bloco A do slide '.($i + 1), 'Bloco B do slide '.($i + 1)],
                'accent' => ['critério'],
                'image' => ['subject' => 'a red balloon on a wet street', 'avoid' => 'no text'],
            ];
        }

        return Draft::create([
            'tenant_id' => $this->marca->id,
            'keyword' => 'teste',
            'carousel' => [
                'topic' => 'bicicletas elétricas em São Paulo',
                'format' => 'retrato',
                'mode' => 'editorial',
                'signature' => '2026.08.02 · @marca',
                'headline' => 'A cidade travou: por que a bicicleta elétrica virou resposta',
                'slides' => $slides,
                'status' => 'ready',
            ],
        ]);
    }

    public function test_o_plano_enfileira_texto_e_nao_gasta_imagem(): void
    {
        Queue::fake();
        config(['services.web.compose_token' => 'tok', 'services.web.url' => 'http://web.test']);

        $r = $this->actingAs($this->cliente)->postJson('/api/studio/carousel', [
            'topic' => 'bicicletas elétricas em São Paulo',
            'slides' => 9,
        ]);

        $r->assertOk()->assertJson(['ok' => true, 'status' => 'generating']);
        Queue::assertPushed(GenerateCarouselPlanJob::class);
        // O plano é texto. Nenhuma imagem é enfileirada aqui — nem uma.
        Queue::assertNotPushed(GenerateCarouselSlideJob::class);

        $d = Draft::find($r->json('draftId'));
        $this->assertSame('generating', $d->carousel['status']);
        $this->assertSame('retrato', $d->carousel['format']);
        // A assinatura de rodapé nasce no controller e precisa sobreviver até o render.
        $this->assertNotEmpty($d->carousel['signature']);
    }

    public function test_render_sem_plano_falha_em_vez_de_enfileirar(): void
    {
        Queue::fake();
        config(['services.web.compose_token' => 'tok', 'services.web.url' => 'http://web.test']);
        $d = Draft::create(['tenant_id' => $this->marca->id, 'keyword' => 'vazio']);

        $this->actingAs($this->cliente)
            ->postJson('/api/studio/carousel-render', ['draftId' => $d->id])
            ->assertStatus(422);

        Queue::assertNotPushed(GenerateCarouselSlideJob::class);
    }

    public function test_a_capa_vai_primeiro_e_leva_os_internos_na_bagagem(): void
    {
        Queue::fake();
        config(['services.web.compose_token' => 'tok', 'services.web.url' => 'http://web.test']);
        $d = $this->comPlano(9);

        $this->actingAs($this->cliente)
            ->postJson('/api/studio/carousel-render', ['draftId' => $d->id])
            ->assertOk()
            ->assertJson(['ok' => true, 'slides' => 9]);

        // UM job — a capa. Os outros 8 viajam dentro dele e só são liberados quando ela grava a
        // URL, porque é ela a referência visual de todos.
        Queue::assertPushed(GenerateCarouselSlideJob::class, 1);
        Queue::assertPushed(GenerateCarouselSlideJob::class, function ($job) {
            return $job->index === 0 && count($job->rest) === 8 && $job->mode === 'editorial';
        });
    }

    public function test_refazer_um_slide_nao_redispara_o_carrossel(): void
    {
        Queue::fake();
        config(['services.web.compose_token' => 'tok', 'services.web.url' => 'http://web.test']);
        $d = $this->comPlano(9);
        // Capa já renderizada — é o pré-requisito pra refazer um interno.
        $c = $d->carousel;
        $c['slides'][0]['image_url'] = 'https://s3.example.com/public/reachyn/image/capa.jpg';
        $d->update(['carousel' => $c]);

        $this->actingAs($this->cliente)
            ->postJson('/api/studio/carousel-render', ['draftId' => $d->id, 'only' => 4])
            ->assertOk()
            ->assertJson(['slides' => 1]);

        Queue::assertPushed(GenerateCarouselSlideJob::class, 1);
        Queue::assertPushed(GenerateCarouselSlideJob::class, fn ($job) => $job->index === 4 && $job->rest === []);
    }

    public function test_interno_sem_capa_pronta_e_recusado(): void
    {
        Queue::fake();
        config(['services.web.compose_token' => 'tok', 'services.web.url' => 'http://web.test']);
        $d = $this->comPlano(9);

        // Sem capa renderizada, um slide interno não tem referência visual — sairia avulso.
        $this->actingAs($this->cliente)
            ->postJson('/api/studio/carousel-render', ['draftId' => $d->id, 'only' => 3])
            ->assertStatus(422);

        Queue::assertNotPushed(GenerateCarouselSlideJob::class);
    }

    public function test_modo_editorial_exige_compositor_configurado(): void
    {
        Queue::fake();
        config(['services.web.compose_token' => '', 'services.web.url' => '']);
        $d = $this->comPlano(5);

        // Sem compositor, o modo editorial não tem como pôr texto no slide: falha ANTES de gastar
        // as imagens, em vez de gerar N fundos crus e cobrar por eles.
        $this->actingAs($this->cliente)
            ->postJson('/api/studio/carousel-render', ['draftId' => $d->id])
            ->assertStatus(503);

        Queue::assertNotPushed(GenerateCarouselSlideJob::class);
    }
}
