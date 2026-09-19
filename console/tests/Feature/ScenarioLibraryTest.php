<?php

namespace Tests\Feature;

use App\Jobs\AnimationElementJob;
use App\Jobs\GenerateScenarioJob;
use App\Models\AnimationProject;
use App\Models\Organization;
use App\Models\Scenario;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CreditWallet;
use App\Services\UsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * 🏞️ Biblioteca de cenários — as duas portas de entrada.
 *
 * Antes só existia a saída: dava para USAR um cenário salvo numa locação, mas nada alimentava a
 * biblioteca (nenhuma tela sequer chamava o POST /api/scenarios). Personagem se acumulava entre
 * projetos porque o AnimationElementJob o cadastrava sozinho; locação morria com o projeto.
 * Estes testes prendem as duas portas: a automática (locação → cenário) e a manual (gerar imagem).
 */
class ScenarioLibraryTest extends TestCase
{
    use RefreshDatabase;

    private function marcaPagante(int $saldo = 10000): Tenant
    {
        return Tenant::factory()->for(Organization::factory()->paying('studio', $saldo))->create();
    }

    /** A locação gerada no Estúdio de Animação entra na biblioteca — igual ao personagem. */
    public function test_locacao_gerada_vira_cenario_na_biblioteca(): void
    {
        $t = $this->marcaPagante();
        Http::fake(['*/v1/image' => Http::response(['url' => 'https://s3.example.com/public/reachyn/image/1-a.jpg'])]);

        $p = AnimationProject::factory()->create([
            'tenant_id' => $t->id,
            'elements' => ['characters' => [], 'locations' => [
                ['name' => 'Salão do trono', 'visual_prompt' => 'salão gótico de pedra, vitrais altos', 'ref_url' => '', 'status' => ''],
            ], 'props' => []],
        ]);

        (new AnimationElementJob($p->id, $t->id, 'locations', 0, ['prompt' => 'x'], 1, 10))
            ->handle(app(UsageService::class));

        $s = Scenario::where('tenant_id', $t->id)->first();
        $this->assertNotNull($s, 'a locação deveria ter virado cenário na biblioteca');
        $this->assertSame('Salão do trono', $s->name);
        $this->assertSame('salão gótico de pedra, vitrais altos', $s->description);
        $this->assertSame('https://s3.example.com/public/reachyn/image/1-a.jpg', $s->image_url);

        // O elemento guarda o vínculo — é ele que impede o re-cadastro numa regeração.
        $el = ((array) $p->fresh()->elements)['locations'][0];
        $this->assertSame($s->id, $el['scenario_id']);
    }

    /** Regenerar a mesma locação NÃO duplica o cenário (idempotência pelo scenario_id). */
    public function test_regerar_locacao_nao_duplica_cenario(): void
    {
        $t = $this->marcaPagante();
        Http::fake(['*/v1/image' => Http::response(['url' => 'https://s3.example.com/public/reachyn/image/1-b.jpg'])]);

        $p = AnimationProject::factory()->create([
            'tenant_id' => $t->id,
            'elements' => ['characters' => [], 'locations' => [
                ['name' => 'Corredor', 'visual_prompt' => 'corredor de pedra', 'ref_url' => '', 'status' => ''],
            ], 'props' => []],
        ]);

        foreach ([0, 1] as $_) {
            (new AnimationElementJob($p->id, $t->id, 'locations', 0, ['prompt' => 'x'], 1, 10))
                ->handle(app(UsageService::class));
        }

        $this->assertSame(1, Scenario::where('tenant_id', $t->id)->count(), 'a 2ª geração não pode criar outro cenário');
    }

    /** Criar do zero: a rota gera a imagem-âncora, marca o status e enfileira o job. */
    public function test_gerar_imagem_do_cenario_enfileira_e_marca_status(): void
    {
        Queue::fake();
        $t = $this->marcaPagante();
        $u = User::factory()->create(['tenant_id' => $t->id]);
        $s = Scenario::create(['tenant_id' => $t->id, 'name' => 'Praça', 'description' => 'praça vazia ao amanhecer']);

        $this->actingAs($u)
            ->postJson("/api/scenarios/{$s->id}/image", [])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertSame('base', $s->fresh()->status, "o status precisa sair de '' para a UI mostrar 'gerando'");
        Queue::assertPushed(GenerateScenarioJob::class);
    }

    /** Sem descrição não há o que gerar — recusa antes de cobrar. */
    public function test_cenario_sem_descricao_nao_gera(): void
    {
        Queue::fake();
        $t = $this->marcaPagante();
        $u = User::factory()->create(['tenant_id' => $t->id]);
        $s = Scenario::create(['tenant_id' => $t->id, 'name' => 'Sem descrição', 'description' => '']);

        $this->actingAs($u)
            ->postJson("/api/scenarios/{$s->id}/image", [])
            ->assertStatus(422);

        Queue::assertNothingPushed();
    }

    /** Geração já em curso não pode ser disparada de novo (evita cobrar 2× a mesma imagem). */
    public function test_geracao_em_curso_recusa_nova(): void
    {
        Queue::fake();
        $t = $this->marcaPagante();
        $u = User::factory()->create(['tenant_id' => $t->id]);
        $s = Scenario::create(['tenant_id' => $t->id, 'name' => 'Ocupado', 'description' => 'algo', 'status' => 'base']);

        $this->actingAs($u)
            ->postJson("/api/scenarios/{$s->id}/image", [])
            ->assertStatus(409);

        Queue::assertNothingPushed();
    }

    /** Falha na geração estorna a cota e marca erro — o cliente não paga pelo que não recebeu. */
    public function test_falha_na_geracao_estorna(): void
    {
        $t = $this->marcaPagante();
        Http::fake(['*/v1/image' => Http::response([], 500)]);
        $s = Scenario::create(['tenant_id' => $t->id, 'name' => 'Falha', 'description' => 'algo', 'status' => 'base']);

        $usage = app(UsageService::class);
        $wallet = app(CreditWallet::class);

        $usage->tryConsume($t, 'image', 1, 10);
        $depoisDeCobrar = $wallet->balance($t->organization->fresh());

        (new GenerateScenarioJob($s->id, $t->id, ['prompt' => 'x'], 1, 10))->handle($usage);

        $this->assertSame('error', $s->fresh()->status);
        $this->assertSame($depoisDeCobrar + 10, $wallet->balance($t->organization->fresh()), 'o crédito precisa voltar');
    }
}
