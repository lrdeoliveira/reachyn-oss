<?php

namespace Tests\Feature;

use App\Jobs\GenerateMeshJob;
use App\Models\Character;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\Tenant;
use App\Scopes\TenantScope;
use App\Support\GaleriaMalha;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 🐛 REGRESSÃO que este teste tranca: a malha 3D NÃO APARECIA NA GALERIA.
 *
 * Relatado em 2026-08-02 ("o 3d e sprites não estão na galeria"). A malha era gravada só em
 * `characters.mesh_url` / `elements.mesh_url`, e a galeria (GET /api/media/list) lê EXCLUSIVAMENTE
 * o array `media` dos rascunhos. O usuário gerava a malha, ela aparecia na aba 3D e sumia do
 * acervo — nada em "Tudo", nada em filtro nenhum, nenhum download.
 *
 * `kind = 'mesh3d'` e não 'image'/'video': a galeria classificava tudo que não fosse imagem ou
 * áudio como VÍDEO, então um .glb cairia dentro de um <video> e daria card quebrado — pior que
 * ausente, porque parece mídia morta em vez de formato que o browser não toca inline.
 */
class MalhaNaGaleriaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $marca;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.engine.url' => 'http://engine.test', 'services.engine.admin_token' => 'x']);
        $this->marca = Tenant::factory()->for(Organization::factory()->paying('studio', 100000))->create();
        TenantScope::flushActiveTenantCache();
    }

    private function personagem(): Character
    {
        return Character::create(['tenant_id' => $this->marca->id, 'name' => 'Mel', 'mesh_status' => 'gerando']);
    }

    /** Os itens de malha da galeria do tenant, como o /api/media/list os enxerga. */
    private function malhasNaGaleria(): array
    {
        return Draft::where('tenant_id', $this->marca->id)->get()
            ->flatMap(fn (Draft $d) => collect($d->media ?? [])->where('kind', 'mesh3d'))
            ->values()->all();
    }

    public function test_malha_gerada_entra_na_galeria(): void
    {
        $glb = 'https://s3.example.com/public/reachyn/mesh/mel.glb';
        Http::fake([
            '*/v1/mesh/generate' => Http::response(['url' => $glb], 200),
            '*' => Http::response('', 200, ['Content-Length' => '5600000']),
        ]);

        $c = $this->personagem();
        (new GenerateMeshJob('character', $c->id, $c->tenant_id, ['imageUrl' => 'https://s3.example.com/public/x.jpg']))
            ->handle(app(\App\Services\UsageService::class));

        $itens = $this->malhasNaGaleria();
        $this->assertCount(1, $itens, 'A malha tem de aparecer no acervo, não só na aba 3D.');
        $this->assertSame($glb, $itens[0]['url']);
        $this->assertSame('mesh3d', $itens[0]['kind'], 'kind próprio: como "video" o .glb cai num <video> e o card quebra.');
    }

    public function test_regerar_troca_o_item_em_vez_de_empilhar(): void
    {
        Http::fake(['*' => Http::response('', 200, ['Content-Length' => '10'])]);
        $c = $this->personagem();

        GaleriaMalha::publica($this->marca->id, 'character', $c->id, 'Mel', 'https://s3.example.com/public/reachyn/mesh/v1.glb');
        GaleriaMalha::publica($this->marca->id, 'character', $c->id, 'Mel', 'https://s3.example.com/public/reachyn/mesh/v2.glb');

        $itens = $this->malhasNaGaleria();
        $this->assertCount(1, $itens, 'Regerar não pode acumular versões que ninguém pediu.');
        $this->assertStringEndsWith('v2.glb', $itens[0]['url']);
    }

    public function test_excluir_a_malha_tira_o_card_da_galeria(): void
    {
        Http::fake(['*' => Http::response('', 200, ['Content-Length' => '10'])]);
        $c = $this->personagem();

        GaleriaMalha::publica($this->marca->id, 'character', $c->id, 'Mel', 'https://s3.example.com/public/reachyn/mesh/mel.glb');
        $this->assertCount(1, $this->malhasNaGaleria());

        GaleriaMalha::retira($this->marca->id, 'character', $c->id, 'Mel');
        $this->assertCount(0, $this->malhasNaGaleria(), 'Card apontando pra malha excluída é a "mídia morta" que a galeria varre.');
        $this->assertSame(0, Draft::where('tenant_id', $this->marca->id)->count(), 'O rascunho só existia pra hospedar a malha: vazio, é lixo que acumularia um por exclusão.');
    }

    public function test_retirar_preserva_rascunho_que_tem_outra_midia(): void
    {
        Http::fake(['*' => Http::response('', 200, ['Content-Length' => '10'])]);
        $c = $this->personagem();
        GaleriaMalha::publica($this->marca->id, 'character', $c->id, 'Mel', 'https://s3.example.com/public/reachyn/mesh/mel.glb');

        // Alguém somou uma imagem ao mesmo rascunho — a limpeza não pode levar isso junto.
        $d = Draft::where('tenant_id', $this->marca->id)->firstOrFail();
        $d->update(['media' => array_merge($d->media, [['id' => Draft::mediaId(), 'kind' => 'image', 'url' => 'https://s3.example.com/public/a.jpg']])]);

        GaleriaMalha::retira($this->marca->id, 'character', $c->id, 'Mel');

        $d->refresh();
        $this->assertCount(1, $d->media);
        $this->assertSame('image', $d->media[0]['kind']);
    }

    public function test_falha_ao_publicar_nao_derruba_a_geracao(): void
    {
        // O HEAD do peso é enfeite; a malha JÁ está no asset e custou minutos de GPU.
        //
        // O stub que LANÇA precisa ser específico do storage: o Laravel avalia TODOS os callbacks
        // do fake (Arr::map em Factory::fake) antes de escolher o primeiro que casa, então um
        // catch-all '*' que lança dispara também na chamada ao engine — e o teste passaria a medir
        // o próprio harness em vez do código.
        Http::fake([
            'engine.test/*' => Http::response(['url' => 'https://s3.example.com/public/reachyn/mesh/ok.glb'], 200),
            's3.example.com/*' => fn () => throw new \RuntimeException('storage fora do ar'),
        ]);

        $c = $this->personagem();
        (new GenerateMeshJob('character', $c->id, $c->tenant_id, ['imageUrl' => 'https://s3.example.com/public/x.jpg']))
            ->handle(app(\App\Services\UsageService::class));

        $c->refresh();
        $this->assertSame('https://s3.example.com/public/reachyn/mesh/ok.glb', $c->mesh_url);
        $this->assertNull($c->mesh_status, 'A malha vale mesmo se a vitrine falhar.');
    }
}
