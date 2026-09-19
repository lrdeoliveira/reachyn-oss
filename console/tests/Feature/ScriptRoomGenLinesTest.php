<?php

namespace Tests\Feature;

use App\Models\AnimationProject;
use App\Models\Draft;
use App\Models\GenModel;
use App\Models\Organization;
use App\Models\Tenant;
use App\Models\User;
use App\Scopes\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 🎬 SALA DE ROTEIRO — o seletor de MODELO DE TEXTO tem de valer na espinha dramática
 * (/v1/storystructure) e no script doctor (/v1/storyreview), nas DUAS abas (História e Animação).
 *
 * 🐛 REGRESSÃO que este teste tranca: o engine sempre aceitou `gen_lines` nessas duas rotas, mas o
 * console nunca enviava — as duas etapas rodavam no modelo default do engine enquanto TODO o resto
 * do produto respeitava a escolha do usuário. Falha silenciosa: resposta 200, texto plausível, só
 * o modelo errado. Por isso a asserção é no PAYLOAD ENVIADO, não no retorno.
 */
class ScriptRoomGenLinesTest extends TestCase
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
        config(['services.engine.url' => 'http://engine.test', 'services.engine.admin_token' => 'x']);

        // Dois modelos de texto: o default do trait e um escolhido explicitamente pela UI.
        $this->modeloTexto('txt-equilibrado', 'default/text-1');
        $this->modeloTexto('txt-premium', 'premium/text-9');
    }

    private function modeloTexto(string $slug, string $providerId): GenModel
    {
        return GenModel::updateOrCreate(['slug' => $slug], [
            'display_name' => 'Texto '.$slug, 'kind' => 'text', 'subtype' => 'text',
            'provider' => 'kie', 'provider_model_id' => $providerId,
            'cost_credits' => 1, 'is_active' => true, 'min_plan' => null,
        ]);
    }

    /** Asserta que a chamada ao endpoint levou gen_lines.text.model = $providerId. */
    private function assertEnviouModelo(string $endpoint, string $providerId): void
    {
        Http::assertSent(fn ($req) => str_contains($req->url(), $endpoint)
            && ($req->data()['gen_lines']['text']['model'] ?? null) === $providerId);
    }

    public function test_story_structure_envia_o_modelo_escolhido(): void
    {
        Http::fake(['*/v1/storystructure' => Http::response(['logline' => 'x'])]);

        $this->actingAs($this->cliente)
            ->postJson('/api/studio/story-structure', ['theme' => 'uma raposa na colina', 'textModel' => 'txt-premium'])
            ->assertOk();

        $this->assertEnviouModelo('/v1/storystructure', 'premium/text-9');
    }

    public function test_story_structure_cai_no_default_sem_escolha(): void
    {
        Http::fake(['*/v1/storystructure' => Http::response(['logline' => 'x'])]);

        $this->actingAs($this->cliente)
            ->postJson('/api/studio/story-structure', ['theme' => 'uma raposa na colina'])
            ->assertOk();

        $this->assertEnviouModelo('/v1/storystructure', 'default/text-1');
    }

    public function test_story_review_envia_o_modelo_escolhido(): void
    {
        $d = Draft::create([
            'tenant_id' => $this->marca->id,
            'keyword' => 'raposa',
            'story' => ['theme' => 'raposa', 'lang' => 'pt-BR', 'scenes' => [
                ['title' => 'Cena 1', 'voiceover' => 'A raposa sobe a colina.'],
            ]],
        ]);
        Http::fake(['*/v1/storyreview' => Http::response(['overall' => 'ok', 'notes' => []])]);

        $this->actingAs($this->cliente)
            ->postJson('/api/studio/story-review', ['draftId' => $d->id, 'textModel' => 'txt-premium'])
            ->assertOk();

        $this->assertEnviouModelo('/v1/storyreview', 'premium/text-9');
    }

    public function test_animation_structure_envia_o_modelo_escolhido(): void
    {
        Http::fake(['*/v1/storystructure' => Http::response(['logline' => 'x'])]);

        $this->actingAs($this->cliente)
            ->postJson('/api/animation-structure', ['theme' => 'uma raposa na colina', 'textModel' => 'txt-premium'])
            ->assertOk();

        $this->assertEnviouModelo('/v1/storystructure', 'premium/text-9');
    }

    public function test_animation_review_envia_o_modelo_escolhido(): void
    {
        $p = AnimationProject::create([
            'tenant_id' => $this->marca->id,
            'title' => 'A colina',
            'lang' => 'pt-BR',
            'script' => 'uma raposa sobe a colina',
            'storyboard' => [['title' => 'Cena 1', 'narration' => 'A raposa sobe a colina.']],
        ]);
        Http::fake(['*/v1/storyreview' => Http::response(['overall' => 'ok', 'notes' => []])]);

        $this->actingAs($this->cliente)
            ->postJson('/api/animation/'.$p->id.'/review', ['textModel' => 'txt-premium'])
            ->assertOk();

        $this->assertEnviouModelo('/v1/storyreview', 'premium/text-9');
    }
}
