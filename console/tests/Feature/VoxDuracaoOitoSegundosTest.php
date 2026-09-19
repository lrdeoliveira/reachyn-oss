<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Tenant;
use App\Models\User;
use App\Scopes\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ⏱️ COMPATIBILIDADE com o Vox Factory (a ferramenta do Google Labs Flow que a casa usa): o beat
 * de 8 segundos.
 *
 * Lá o beat é de 8s com teto de 90 caracteres — e os dois números saem da MESMA régua que a nossa
 * (12,2 c/s menos 0,6s de respiro). Até 2026-08-30 o nosso Vox forçava `duration: '6'` em dois
 * lugares (o roteiro e a geração da cena), e `validDuration` no engine devolvia "6" para qualquer
 * valor desconhecido, em silêncio. Um roteiro escrito no Vox Factory virava clipe de 6s, cuja
 * janela falada é 5,4s = 65 caracteres: a narração de 90 saía CORTADA no meio da frase.
 *
 * Falha perfeita de silenciosa: nenhum erro, nenhum log — só a fala truncada no filme entregue.
 */
class VoxDuracaoOitoSegundosTest extends TestCase
{
    use RefreshDatabase;

    private User $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        $t = Tenant::factory()->for(Organization::factory()->paying('studio', 100000))->create();
        TenantScope::flushActiveTenantCache();
        $this->cliente = User::factory()->create([
            'tenant_id' => $t->id, 'organization_id' => $t->organization_id, 'role' => 'client',
        ]);
        config(['services.engine.url' => 'http://engine.test', 'services.engine.admin_token' => 'x']);
    }

    public function test_roteiro_vox_de_8s_chega_ao_engine_como_8(): void
    {
        Http::fake(['*/v1/beats' => Http::response(['beats' => [
            ['caption' => 'a', 'script' => 'uma frase', 'image_prompt' => 'paper', 'sfx' => 'paper slide'],
        ]], 200)]);

        $this->actingAs($this->cliente)
            ->postJson('/api/studio/vox-roteiro', [
                'prompt' => 'A crise dos semicondutores explicada', 'scenes' => 4,
                'style' => 'vox', 'duration' => '8',
            ])
            ->assertOk();

        Http::assertSent(fn ($req) => str_contains($req->url(), '/v1/beats')
            && ($req->data()['duration'] ?? null) === '8'
            && ($req->data()['preset'] ?? null) === 'vox');
    }

    public function test_duracao_estranha_no_vox_volta_para_a_doutrina_de_6s(): void
    {
        Http::fake(['*/v1/beats' => Http::response(['beats' => [
            ['caption' => 'a', 'script' => 'uma frase', 'image_prompt' => 'paper', 'sfx' => 'paper slide'],
        ]], 200)]);

        // 5s é a duração do caminho GENÉRICO; no Vox ela não existe — e virar clipe de 5s com um
        // roteiro medido para 6 truncaria a fala do mesmo jeito.
        $this->actingAs($this->cliente)
            ->postJson('/api/studio/vox-roteiro', [
                'prompt' => 'tema', 'scenes' => 4, 'style' => 'vox', 'duration' => '5',
            ])
            ->assertOk();

        Http::assertSent(fn ($req) => str_contains($req->url(), '/v1/beats')
            && ($req->data()['duration'] ?? null) === '6');
    }

    public function test_storyboard_guarda_os_8s_para_a_cena_sair_do_mesmo_tamanho(): void
    {
        $this->actingAs($this->cliente)
            ->postJson('/api/studio/vox-storyboard', [
                'tema' => 'A crise dos semicondutores',
                'duration' => '8',
                'beats' => [[
                    'caption' => 'abertura', 'script' => 'Uma frase de abertura que cabe em oito segundos.',
                    'image_prompt' => 'paper cut-out shelves', 'sfx' => 'paper slide',
                ]],
            ])
            ->assertOk()
            // Sem isto a cena regerada amanhã sairia com 6s para um roteiro escrito para 8.
            ->assertJsonPath('vox.duration', '8');
    }
}
