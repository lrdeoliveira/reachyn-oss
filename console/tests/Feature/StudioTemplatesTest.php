<?php

namespace Tests\Feature;

use App\Models\AnimationProject;
use App\Models\Character;
use App\Models\CreationTemplate;
use App\Models\Draft;
use App\Models\Tenant;
use App\Models\User;
use App\Scopes\TenantScope;
use Database\Seeders\CreationTemplatesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Aba Rápido — catálogo de templates de criação + apply (benchmark Nordy+RunningHub). Trava as
 * invariantes: globais (tenant_id NULL) aparecem pra todas as marcas, receitas privadas não vazam,
 * aplicar é 0 crédito (animation devolve prefill sem criar projeto; draft cria só o rascunho), e o
 * personagem repassado tem que ser da própria marca.
 */
class StudioTemplatesTest extends TestCase
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
    }

    private function globalTemplate(array $payload, array $over = []): CreationTemplate
    {
        return CreationTemplate::create(array_merge([
            'tenant_id' => null,
            'slug' => 'tpl-'.bin2hex(random_bytes(3)),
            'title' => 'Template',
            'category' => 'reels',
            'payload' => $payload,
            'active' => true,
        ], $over));
    }

    public function test_seeder_publica_8_globais_visiveis_com_resumo_e_estimativa(): void
    {
        $this->seed(CreationTemplatesSeeder::class);

        $j = $this->actingAs($this->cliente)->getJson('/api/studio/templates')->assertOk()->json();

        $this->assertCount(8, $j['templates']);
        $card = $j['templates'][0];
        foreach (['id', 'slug', 'title', 'category', 'target', 'summary', 'estimated_credits', 'character_required'] as $k) {
            $this->assertArrayHasKey($k, $card);
        }
        $this->assertTrue($card['is_global']);
        $this->assertGreaterThan(0, $card['estimated_credits']);
    }

    public function test_filtra_por_categoria_e_target(): void
    {
        $this->globalTemplate(['target' => 'animation', 'mode' => 'historia', 'scenes' => 6], ['category' => 'reels']);
        $this->globalTemplate(['target' => 'draft'], ['category' => 'ugc_produto']);

        $reels = $this->actingAs($this->cliente)->getJson('/api/studio/templates?category=reels')->assertOk()->json();
        $this->assertCount(1, $reels['templates']);
        $this->assertSame('reels', $reels['templates'][0]['category']);

        $drafts = $this->actingAs($this->cliente)->getJson('/api/studio/templates?target=draft')->assertOk()->json();
        $this->assertCount(1, $drafts['templates']);
        $this->assertSame('draft', $drafts['templates'][0]['target']);
    }

    public function test_apply_animation_devolve_prefill_normalizado_sem_criar_projeto(): void
    {
        $tpl = $this->globalTemplate([
            'target' => 'animation', 'mode' => 'animacao', 'style' => 'cartoon', 'aspect' => '9:16',
            'scenes' => 99, 'quality' => 'padrao', 'sequence_mode' => 'encadeado',
            'script_seed' => 'Um diálogo curto e engraçado.', 'palette' => 'tons pastel',
        ]);

        $j = $this->actingAs($this->cliente)
            ->postJson("/api/studio/templates/{$tpl->id}/apply")
            ->assertOk()->json();

        $this->assertSame('animation', $j['target']);
        $this->assertSame('/estudio', $j['redirect']);
        $this->assertSame('animacao', $j['prefill']['mode']);
        $this->assertSame('cartoon', $j['prefill']['style']);
        $this->assertSame(20, $j['prefill']['scenes']); // clamp 99 → 20
        $this->assertSame('encadeado', $j['prefill']['sequenceMode']);
        $this->assertNull($j['prefill']['preCharId']);
        // apply = 0 crédito e não cria projeto/draft
        $this->assertDatabaseCount('animation_projects', 0);
        $this->assertDatabaseCount('drafts', 0);
    }

    public function test_apply_draft_cria_rascunho_e_manda_pro_editor(): void
    {
        $tpl = $this->globalTemplate(['target' => 'draft', 'script_seed' => 'Depoimento UGC.', 'platforms' => ['instagram']],
            ['title' => 'UGC de produto']);

        $j = $this->actingAs($this->cliente)
            ->postJson("/api/studio/templates/{$tpl->id}/apply")
            ->assertOk()->json();

        $this->assertSame('draft', $j['target']);
        $this->assertSame('/editar', $j['redirect']);
        $this->assertArrayHasKey('draftId', $j);
        $this->assertDatabaseHas('drafts', ['id' => $j['draftId'], 'tenant_id' => $this->marca->id, 'keyword' => 'UGC de produto']);
        $this->assertSame(['instagram'], $j['prefill']['platforms']);
    }

    public function test_apply_com_personagem_da_marca_repassa_precharid(): void
    {
        $c = Character::create(['tenant_id' => $this->marca->id, 'name' => 'Mel', 'status' => '']);
        $tpl = $this->globalTemplate(['target' => 'animation', 'mode' => 'historia', 'scenes' => 6]);

        $j = $this->actingAs($this->cliente)
            ->postJson("/api/studio/templates/{$tpl->id}/apply", ['characterId' => $c->id])
            ->assertOk()->json();

        $this->assertSame($c->id, $j['prefill']['preCharId']);
    }

    public function test_apply_recusa_personagem_de_outra_marca(): void
    {
        $outra = Tenant::factory()->create();
        $alheio = Character::create(['tenant_id' => $outra->id, 'name' => 'Alheio', 'status' => '']);
        $tpl = $this->globalTemplate(['target' => 'animation', 'mode' => 'historia', 'scenes' => 6]);

        $this->actingAs($this->cliente)
            ->postJson("/api/studio/templates/{$tpl->id}/apply", ['characterId' => $alheio->id])
            ->assertNotFound();
    }

    public function test_receita_privada_de_outra_marca_nao_vaza_nem_aplica(): void
    {
        $outra = Tenant::factory()->create();
        $privada = CreationTemplate::create([
            'tenant_id' => $outra->id, 'slug' => 'receita-alheia', 'title' => 'Receita alheia',
            'category' => 'outro', 'payload' => ['target' => 'draft'], 'active' => true,
        ]);
        $global = $this->globalTemplate(['target' => 'draft']);

        // index: vê o global, não vê a receita da outra marca
        $j = $this->actingAs($this->cliente)->getJson('/api/studio/templates')->assertOk()->json();
        $ids = array_column($j['templates'], 'id');
        $this->assertContains($global->id, $ids);
        $this->assertNotContains($privada->id, $ids);

        // apply: template de outra marca = 404
        $this->actingAs($this->cliente)
            ->postJson("/api/studio/templates/{$privada->id}/apply")
            ->assertNotFound();
    }

    public function test_salva_projeto_como_receita_da_marca(): void
    {
        $proj = AnimationProject::factory()->create([
            'tenant_id' => $this->marca->id, 'mode' => 'historia', 'style' => '3d', 'aspect' => '9:16',
            'quality' => 'padrao', 'sequence_mode' => 'encadeado', 'script' => 'uma história boa',
            'storyboard' => [['a' => 1], ['b' => 2], ['c' => 3]],
        ]);

        $j = $this->actingAs($this->cliente)->postJson('/api/studio/templates', [
            'title' => 'Minha Receita', 'category' => 'reels', 'fromProjectId' => $proj->id,
        ])->assertOk()->json();

        $this->assertDatabaseHas('creation_templates', ['id' => $j['template']['id'], 'tenant_id' => $this->marca->id, 'slug' => 'minha-receita']);

        // aparece no index como "da sua equipe" (não global) e é aplicável
        $list = $this->actingAs($this->cliente)->getJson('/api/studio/templates')->assertOk()->json();
        $mine = collect($list['templates'])->firstWhere('id', $j['template']['id']);
        $this->assertFalse($mine['is_global']);

        $apply = $this->actingAs($this->cliente)->postJson("/api/studio/templates/{$j['template']['id']}/apply")->assertOk()->json();
        $this->assertSame('historia', $apply['prefill']['mode']);
        $this->assertSame(3, $apply['prefill']['scenes']); // derivado do storyboard (3 cenas)
    }

    public function test_receita_exige_titulo_e_origem(): void
    {
        // sem título → 422 (o título é checado antes da origem)
        $this->actingAs($this->cliente)->postJson('/api/studio/templates', ['fromDraftId' => 1])->assertStatus(422);
        // com título mas sem origem → 422
        $this->actingAs($this->cliente)->postJson('/api/studio/templates', ['title' => 'X'])->assertStatus(422);
        // com título mas projeto inexistente → 404
        $this->actingAs($this->cliente)->postJson('/api/studio/templates', ['title' => 'X', 'fromProjectId' => 99999])->assertStatus(404);
    }

    public function test_apaga_receita_da_marca_mas_nao_global(): void
    {
        $receita = CreationTemplate::create([
            'tenant_id' => $this->marca->id, 'slug' => 'minha', 'title' => 'Minha', 'category' => 'outro',
            'payload' => ['target' => 'draft'], 'active' => true,
        ]);
        $global = $this->globalTemplate(['target' => 'draft']);

        $this->actingAs($this->cliente)->deleteJson("/api/studio/templates/{$receita->id}")->assertOk();
        $this->assertDatabaseMissing('creation_templates', ['id' => $receita->id]);

        // global não é apagável por cliente
        $this->actingAs($this->cliente)->deleteJson("/api/studio/templates/{$global->id}")->assertNotFound();
        $this->assertDatabaseHas('creation_templates', ['id' => $global->id]);
    }
}
