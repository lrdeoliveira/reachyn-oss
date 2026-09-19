<?php

namespace Tests\Feature;

use App\Jobs\GenerateVideoJob;
use App\Models\Prompt;
use App\Models\Tenant;
use App\Models\User;
use App\Scopes\TenantScope;
use App\Services\UsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * 🎨 Personas de estilo — a direção visual que entra no prompt de geração.
 * Trava: o select só enxerga personas do kind pedido; persona de OUTRA marca nunca
 * resolve (isolamento); e o texto escolhido chega mesmo ao engine.
 */
class PersonaEstiloTest extends TestCase
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
        $this->mock(UsageService::class, function ($m) {
            $m->shouldReceive('tryConsume')->andReturn(true);
            $m->shouldReceive('refund');
            $m->shouldReceive('weightFor')->andReturn(1);
        });
    }

    private function persona(string $title, string $kind, string $content, ?int $tenantId = null): Prompt
    {
        return Prompt::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $tenantId ?? $this->marca->id,
            'title' => $title,
            'kind' => $kind,
            'content' => $content,
        ]);
    }

    public function test_index_filtra_personas_por_kind(): void
    {
        $this->persona('🎨 Estilo: Cinematográfico', 'image', 'anamorphic, teal and orange');
        $this->persona('🎥 Estilo: Videoclipe', 'video', 'beat-synced camera');
        // Prompt de texto (kind NULL) — não é persona e não pode aparecer no select.
        Prompt::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $this->marca->id, 'title' => '🎬 Roteirista: Geral', 'content' => 'escreva…',
        ]);

        $r = $this->actingAs($this->cliente)->getJson('/api/prompts?kind=image');

        $r->assertOk()->assertJsonCount(1);
        $this->assertSame('🎨 Estilo: Cinematográfico', $r->json('0.title'));
    }

    public function test_index_sem_kind_devolve_tudo(): void
    {
        $this->persona('🎨 Estilo: Cinematográfico', 'image', 'anamorphic');
        Prompt::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $this->marca->id, 'title' => '🎬 Roteirista: Geral', 'content' => 'escreva…',
        ]);

        $this->actingAs($this->cliente)->getJson('/api/prompts')->assertOk()->assertJsonCount(2);
    }

    public function test_kind_invalido_e_recusado(): void
    {
        $this->actingAs($this->cliente)->getJson('/api/prompts?kind=audio')->assertStatus(422);
    }

    public function test_persona_escolhida_chega_ao_engine(): void
    {
        $p = $this->persona('🎨 Estilo: Noir', 'image', 'hard shadows, venetian blinds, monochrome');
        Http::fake(['*/v1/image' => Http::response(['url' => 'https://s3.example.com/x.jpg'])]);

        $this->actingAs($this->cliente)->postJson('/api/studio/media', [
            'kind' => 'image', 'prompt' => 'um detetive na chuva', 'personaId' => $p->id,
        ])->assertOk();

        Http::assertSent(fn ($req) => str_contains($req->url(), '/v1/image')
            && ($req['persona'] ?? '') === 'hard shadows, venetian blinds, monochrome');
    }

    public function test_persona_de_outra_marca_nao_resolve(): void
    {
        $outra = Tenant::factory()->create();
        TenantScope::flushActiveTenantCache();
        $alheia = $this->persona('🎨 Estilo: Alheia', 'image', 'segredo da concorrente', $outra->id);
        Http::fake(['*/v1/image' => Http::response(['url' => 'https://s3.example.com/x.jpg'])]);

        $this->actingAs($this->cliente)->postJson('/api/studio/media', [
            'kind' => 'image', 'prompt' => 'um carro', 'personaId' => $alheia->id,
        ])->assertOk();

        // Sem persona no payload: o id existe, mas é de outra marca → não resolve.
        Http::assertSent(fn ($req) => str_contains($req->url(), '/v1/image')
            && ! isset($req['persona']));
    }

    public function test_persona_de_kind_errado_nao_resolve(): void
    {
        $video = $this->persona('🎥 Estilo: Videoclipe', 'video', 'beat-synced camera');
        Http::fake(['*/v1/image' => Http::response(['url' => 'https://s3.example.com/x.jpg'])]);

        $this->actingAs($this->cliente)->postJson('/api/studio/media', [
            'kind' => 'image', 'prompt' => 'um carro', 'personaId' => $video->id,
        ])->assertOk();

        Http::assertSent(fn ($req) => str_contains($req->url(), '/v1/image') && ! isset($req['persona']));
    }

    public function test_persona_digitada_na_hora_chega_ao_engine(): void
    {
        Http::fake(['*/v1/image' => Http::response(['url' => 'https://s3.example.com/x.jpg'])]);

        $this->actingAs($this->cliente)->postJson('/api/studio/media', [
            'kind' => 'image', 'prompt' => 'uma praia', 'persona' => 'golden hour, 85mm, warm rim light',
        ])->assertOk();

        Http::assertSent(fn ($req) => ($req['persona'] ?? '') === 'golden hour, 85mm, warm rim light');
    }

    public function test_sem_persona_o_payload_segue_como_antes(): void
    {
        Http::fake(['*/v1/image' => Http::response(['url' => 'https://s3.example.com/x.jpg'])]);

        $this->actingAs($this->cliente)->postJson('/api/studio/media', [
            'kind' => 'image', 'prompt' => 'uma praia',
        ])->assertOk();

        Http::assertSent(fn ($req) => str_contains($req->url(), '/v1/image') && ! isset($req['persona']));
    }

    public function test_persona_de_video_chega_no_payload_do_job(): void
    {
        $p = $this->persona('🎥 Estilo: Videoclipe', 'video', 'beat-synced cuts, handheld energy');
        Queue::fake();

        $this->actingAs($this->cliente)->postJson('/api/studio/media', [
            'kind' => 'video', 'prompt' => 'um show de rock', 'personaId' => $p->id, 'scenes' => 1,
        ])->assertOk();

        Queue::assertPushed(GenerateVideoJob::class, function ($job) {
            $payload = (fn () => $this->payload)->call($job);

            return ($payload['persona'] ?? '') === 'beat-synced cuts, handheld energy';
        });
    }

    public function test_persona_de_imagem_nao_vale_para_video(): void
    {
        $img = $this->persona('🎨 Estilo: Cinematográfico', 'image', 'anamorphic still');
        Queue::fake();

        $this->actingAs($this->cliente)->postJson('/api/studio/media', [
            'kind' => 'video', 'prompt' => 'um carro', 'personaId' => $img->id, 'scenes' => 1,
        ])->assertOk();

        Queue::assertPushed(GenerateVideoJob::class, function ($job) {
            $payload = (fn () => $this->payload)->call($job);

            return ! isset($payload['persona']);
        });
    }

    public function test_refinador_valido_chega_ao_engine(): void
    {
        Http::fake(['*/v1/image' => Http::response(['url' => 'https://s3.example.com/x.jpg'])]);

        $this->actingAs($this->cliente)->postJson('/api/studio/media', [
            'kind' => 'image', 'prompt' => 'uma praia', 'refiner' => 'cursor',
        ])->assertOk();

        Http::assertSent(fn ($req) => ($req['refiner'] ?? '') === 'cursor');
    }

    /** O refiner vira NOME DE PROCESSO no host — allowlist fechada, nada de texto livre. */
    public function test_refinador_fora_do_allowlist_e_descartado(): void
    {
        Http::fake(['*/v1/image' => Http::response(['url' => 'https://s3.example.com/x.jpg'])]);

        foreach (['rm -rf /', 'bash', 'mmx; whoami', 'MMX', ''] as $mau) {
            $this->actingAs($this->cliente)->postJson('/api/studio/media', [
                'kind' => 'image', 'prompt' => 'uma praia', 'refiner' => $mau,
            ])->assertOk();
        }

        Http::assertSent(fn ($req) => ! isset($req['refiner']));
    }

    public function test_lista_de_refinadores_sobrevive_a_bridge_fora_do_ar(): void
    {
        config(['services.cli_bridge.url' => 'http://127.0.0.1:59999']);
        Cache::flush();
        Http::fake(['*/health' => Http::response(null, 500)]);

        $this->actingAs($this->cliente)->getJson('/api/studio/refiners')
            ->assertOk()->assertJson(['ok' => true, 'refiners' => []]);
    }

    public function test_lista_refinadores_so_os_presentes_com_nome_real(): void
    {
        config(['services.cli_bridge.url' => 'http://127.0.0.1:3921']);
        Cache::flush();
        Http::fake(['*/health' => Http::response([
            'ok' => true,
            'providers' => [
                'mmx' => ['present' => true, 'can_enhance' => true],
                'cursor' => ['present' => true, 'can_enhance' => true],
                'agy' => ['present' => false, 'can_enhance' => true], // ausente → não listar
            ],
        ])]);

        $r = $this->actingAs($this->cliente)->getJson('/api/studio/refiners')->assertOk();

        $ids = array_column($r->json('refiners'), 'id');
        sort($ids);
        $this->assertSame(['cursor', 'mmx'], $ids);
        // Nome real desde 2026-07-20 (era "Studio B/C" white-label) — ordem do Luciano.
        $this->assertSame(['mmx', 'cursor'], array_column($r->json('refiners'), 'label'));
    }

    public function test_cliente_pode_salvar_a_propria_persona(): void
    {
        $r = $this->actingAs($this->cliente)->postJson('/api/prompts', [
            'title' => '🎨 Estilo: minha', 'kind' => 'image', 'content' => 'grão pesado, alto contraste',
        ]);

        $r->assertCreated();
        $this->assertDatabaseHas('prompts', [
            'tenant_id' => $this->marca->id, 'title' => '🎨 Estilo: minha', 'kind' => 'image',
        ]);
        // E passa a aparecer no select de imagem.
        $this->actingAs($this->cliente)->getJson('/api/prompts?kind=image')->assertOk()->assertJsonCount(1);
    }
}
