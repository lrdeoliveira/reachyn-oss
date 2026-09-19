<?php

namespace Tests\Feature;

use App\Jobs\GenerateMeshJob;
use App\Models\Character;
use App\Models\Organization;
use App\Models\Tenant;
use App\Scopes\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 🐛 REGRESSÃO que este teste tranca: o engine produzia a mensagem CERTA e o console a jogava fora.
 *
 * Teste real de 2026-08-02, personagem "Drone de Segurança Alpha": o juiz de identidade reprovou as
 * três fichas de malha com o motivo exato — "includes human-like legs which are not part of the
 * described octocopter drone" —, porque o `lock` dizia "octocopter drone" e a imagem-base mostrava
 * esse drone montado sobre pernas humanas. O `GenerateMeshJob` gravava só `mesh_status = 'erro'` e
 * mandava o texto pro log. Na tela sobrava "A última geração de malha falhou", que não diz o que
 * fazer — e o usuário reclicaria pra sempre, porque o problema não estava na geração e sim no DADO.
 *
 * Dois pontos de falha, e este teste cobre o do console (o do engine é a classe gerr.Config em
 * mesh.go, que faz a mensagem sobreviver à borda em vez de virar o 502 genérico "a IA está
 * indisponível" — mentira dupla: a IA estava disponível e trabalhou 3 minutos).
 */
class MeshMotivoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.engine.url' => 'http://engine.test', 'services.engine.admin_token' => 'x']);
    }

    private function personagem(): Character
    {
        $t = Tenant::factory()->for(Organization::factory()->paying('studio', 100000))->create();
        TenantScope::flushActiveTenantCache();

        return Character::create([
            'tenant_id' => $t->id,
            'name' => 'Drone de Segurança Alpha',
            'mesh_status' => 'gerando',
        ]);
    }

    public function test_motivo_acionavel_do_engine_e_guardado_para_a_tela(): void
    {
        $motivo = 'malha: nenhuma ficha passou no juiz em 3 tentativas — a descrição do personagem e a imagem-base precisam combinar';
        // 422 = classe Config do engine: permanente e acionável por quem chamou.
        Http::fake(['*/v1/mesh/generate' => Http::response(['error' => $motivo], 422)]);

        $c = $this->personagem();
        (new GenerateMeshJob('character', $c->id, $c->tenant_id, ['imageUrl' => 'https://s3.example.com/public/x.jpg']))
            ->handle(app(\App\Services\UsageService::class));

        $c->refresh();
        $this->assertSame('erro', $c->mesh_status);
        $this->assertSame($motivo, $c->mesh_msg, 'Sem o motivo, a tela só sabe dizer "falhou" e o usuário reclica contra um problema que é do dado.');
    }

    public function test_erro_transitorio_nao_vira_texto_na_tela(): void
    {
        // 5xx traz a frase genérica de propósito ("a IA está indisponível") — exibi-la seria ruído.
        Http::fake(['*/v1/mesh/generate' => Http::response(['error' => 'a IA está indisponível no momento'], 502)]);

        $c = $this->personagem();
        (new GenerateMeshJob('character', $c->id, $c->tenant_id, ['imageUrl' => 'https://s3.example.com/public/x.jpg']))
            ->handle(app(\App\Services\UsageService::class));

        $c->refresh();
        $this->assertSame('erro', $c->mesh_status);
        $this->assertNull($c->mesh_msg);
    }

    public function test_ressalva_do_juiz_tambem_chega_a_tela(): void
    {
        // O mesmo buraco existia no caminho FELIZ: 'aviso' sem texto é alerta sem conteúdo.
        Http::fake(['*/v1/mesh/generate' => Http::response([
            'url' => 'https://s3.example.com/public/reachyn/mesh/x.glb',
            'aviso' => 'a malha veio sobre um pedestal',
        ], 200)]);

        $c = $this->personagem();
        (new GenerateMeshJob('character', $c->id, $c->tenant_id, ['imageUrl' => 'https://s3.example.com/public/x.jpg']))
            ->handle(app(\App\Services\UsageService::class));

        $c->refresh();
        $this->assertSame('aviso', $c->mesh_status);
        $this->assertSame('a malha veio sobre um pedestal', $c->mesh_msg);
    }

    public function test_malha_limpa_nao_deixa_motivo_pendurado(): void
    {
        Http::fake(['*/v1/mesh/generate' => Http::response([
            'url' => 'https://s3.example.com/public/reachyn/mesh/ok.glb',
        ], 200)]);

        $c = $this->personagem();
        $c->update(['mesh_msg' => 'motivo da rodada anterior']);

        (new GenerateMeshJob('character', $c->id, $c->tenant_id, ['imageUrl' => 'https://s3.example.com/public/x.jpg']))
            ->handle(app(\App\Services\UsageService::class));

        $c->refresh();
        $this->assertNull($c->mesh_status);
        $this->assertNull($c->mesh_msg, 'Motivo é da rodada, não do asset — senão o erro velho cola na malha nova.');
    }
}
