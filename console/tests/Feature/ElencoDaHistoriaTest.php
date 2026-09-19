<?php

namespace Tests\Feature;

use App\Jobs\GenerateCharacterJob;
use App\Jobs\GenerateElementJob;
use App\Jobs\GenerateScenarioJob;
use App\Models\Character;
use App\Models\Element;
use App\Models\GenModel;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Prompt;
use App\Models\Scenario;
use App\Models\Scene;
use App\Models\Tenant;
use App\Models\User;
use App\Scopes\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 🎭 BOTÃO ÚNICO do Roteiro (POST /api/projects/{id}/cast): um clique dá rosto a TUDO que a
 * história cita — personagem, cenário e elemento — no MESMO modelo e na MESMA técnica.
 *
 * O que este teste tranca:
 *  1. dispara os três tipos de job de uma vez (o elemento é o que nunca entrava no fluxo);
 *  2. a técnica da HISTÓRIA viaja no payload dos três — é isso que faz o filme ter uma estética
 *     só, em vez de personagem realista + cenário no default + objeto num terceiro estilo;
 *  3. asset que JÁ tem imagem não é regerado (regerar custa crédito e é decisão do autor);
 *  4. asset de outra história não entra no gasto.
 */
class ElencoDaHistoriaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $marca;

    private User $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        $this->marca = Tenant::factory()->for(Organization::factory()->paying('studio', 100000))->create();
        TenantScope::flushActiveTenantCache();
        $this->cliente = User::factory()->create([
            'tenant_id' => $this->marca->id,
            'organization_id' => $this->marca->organization_id,
            'role' => 'client',
        ]);
        GenModel::updateOrCreate(
            ['slug' => 'img-ultra'],
            [
                'display_name' => 'Ultra', 'kind' => 'image', 'subtype' => 'text_to_image',
                'provider' => 'kie', 'provider_model_id' => 'seedream/4.5-text-to-image',
                'cost_credits' => 9, 'is_active' => true, 'min_plan' => null,
            ]
        );
    }

    public function test_um_clique_gera_personagem_cenario_e_elemento_na_tecnica_da_historia(): void
    {
        $p = Project::create(['tenant_id' => $this->marca->id, 'name' => 'O sinal na colina', 'image_style' => 'aquarela']);
        $personagem = Character::create(['tenant_id' => $this->marca->id, 'name' => 'Mel', 'description' => 'dachshund cor de mel']);
        $cenario = Scenario::create(['tenant_id' => $this->marca->id, 'name' => 'Feira', 'description' => 'feira de rua ao amanhecer']);
        $elemento = Element::create(['tenant_id' => $this->marca->id, 'name' => 'Coleira', 'categoria' => 'prop', 'description' => 'coleira vermelha gasta']);
        // Fora da história: mesma biblioteca, outro filme — não pode entrar no gasto.
        $deOutroFilme = Element::create(['tenant_id' => $this->marca->id, 'name' => 'Nave', 'categoria' => 'veiculo', 'description' => 'nave prateada']);

        Scene::create([
            'tenant_id' => $this->marca->id, 'project_id' => $p->id, 'ordem' => 1,
            'character_ids' => [$personagem->id], 'scenario_id' => $cenario->id, 'element_ids' => [$elemento->id],
        ]);

        $this->actingAs($this->cliente)
            ->postJson("/api/projects/{$p->id}/cast", [])
            ->assertOk()
            ->assertJson(['ok' => true, 'personagens' => 1, 'cenarios' => 1, 'elementos' => 1, 'estilo' => 'aquarela']);

        Bus::assertDispatched(GenerateCharacterJob::class, fn ($j) => $j->characterId === $personagem->id
            && ($j->imagePayload['style'] ?? null) === 'aquarela');
        Bus::assertDispatched(GenerateScenarioJob::class, fn ($j) => $j->scenarioId === $cenario->id
            && ($j->payload['style'] ?? null) === 'aquarela');
        Bus::assertDispatched(GenerateElementJob::class, fn ($j) => $j->elementId === $elemento->id
            && ($j->payload['style'] ?? null) === 'aquarela');
        Bus::assertNotDispatched(GenerateElementJob::class, fn ($j) => $j->elementId === $deOutroFilme->id);

        // A técnica fica gravada no asset: regerar depois volta igual aos irmãos.
        $this->assertSame('aquarela', $cenario->fresh()->style);
        $this->assertSame('aquarela', $elemento->fresh()->style);
        $this->assertSame('aquarela', $personagem->fresh()->style);
    }

    public function test_nao_regera_o_que_ja_tem_imagem(): void
    {
        $p = Project::create(['tenant_id' => $this->marca->id, 'name' => 'Piloto']);
        $pronto = Character::create([
            'tenant_id' => $this->marca->id, 'name' => 'Rui', 'description' => 'homem de sobretudo',
            'base_url' => 'https://s3.example.com/public/reachyn/image/rui.jpg',
        ]);
        Scene::create([
            'tenant_id' => $this->marca->id, 'project_id' => $p->id, 'ordem' => 1,
            'character_ids' => [$pronto->id],
        ]);

        $this->actingAs($this->cliente)
            ->postJson("/api/projects/{$p->id}/cast", [])
            ->assertOk()
            ->assertJson(['ok' => true, 'personagens' => 0, 'prontos' => 1]);

        Bus::assertNotDispatched(GenerateCharacterJob::class);
    }

    /**
     * O ROTEIRO cria as três fichas. Elemento era o elo aberto: `scenes.element_ids` existia e o
     * plano nunca preenchia — o objeto que a história reconhece de uma cena pra outra (o carro, a
     * carta) só entrava se alguém cadastrasse na mão. Sem isto o botão único não tem o que gerar.
     */
    public function test_o_roteiro_cria_personagens_cenarios_e_elementos_e_amarra_na_cena(): void
    {
        config(['services.engine.url' => 'http://engine.test', 'services.engine.admin_token' => 'x']);
        Prompt::create([
            'tenant_id' => $this->marca->id,
            'title' => '📝 Escaleta',
            'content' => 'responda em json',
        ]);
        $escaleta = [
            'personagens' => [['nome' => 'Mel', 'descricao' => 'dachshund cor de mel']],
            'cenarios' => [['nome' => 'Feira', 'descricao' => 'feira de rua ao amanhecer']],
            'elementos' => [['nome' => 'Coleira', 'categoria' => 'figurino', 'descricao' => 'coleira vermelha gasta']],
            'cenas' => [[
                'local' => 'Feira', 'int_ext' => 'EXT', 'tempo' => 'DIA', 'resumo' => 'Mel perde o dono',
                'elenco' => ['Mel'], 'cenario' => 'Feira', 'objetos' => ['Coleira'],
            ]],
        ];
        Http::fake(['*/v1/chat' => Http::response(['text' => json_encode($escaleta)], 200), '*' => Http::response([], 200)]);

        $p = Project::create(['tenant_id' => $this->marca->id, 'name' => 'O sinal na colina']);

        $this->actingAs($this->cliente)
            ->postJson("/api/projects/{$p->id}/plan", [
                'brief' => 'Uma cachorra se perde do dono numa feira.', 'cenas' => 2, 'image_style' => 'aquarela',
            ])
            ->assertOk()
            ->assertJson(['ok' => true, 'novos_personagens' => 1, 'novos_cenarios' => 1, 'novos_elementos' => 1]);

        $coleira = Element::where('tenant_id', $this->marca->id)->where('name', 'Coleira')->first();
        $this->assertNotNull($coleira, 'o elemento que a história pede tem de virar ficha');
        $this->assertSame('figurino', $coleira->categoria);

        $cena = Scene::where('project_id', $p->id)->firstOrFail();
        $this->assertSame([$coleira->id], $cena->element_ids, 'a cena tem de citar o objeto pelo nome que a IA usou');
        // A técnica escolhida no Roteiro vira o padrão da história.
        $this->assertSame('aquarela', $p->fresh()->image_style);
    }

    public function test_historia_sem_cena_nao_gera_nada(): void
    {
        $p = Project::create(['tenant_id' => $this->marca->id, 'name' => 'Vazia']);

        $this->actingAs($this->cliente)
            ->postJson("/api/projects/{$p->id}/cast", [])
            ->assertStatus(422);

        Bus::assertNothingDispatched();
    }
}
