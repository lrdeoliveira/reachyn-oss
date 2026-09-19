<?php

namespace Tests\Feature;

use App\Models\Draft;
use App\Models\Organization;
use App\Models\Tenant;
use App\Models\User;
use App\Scopes\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * ✍️ /api/studio/story-texts — a legenda por rede é PRÉ-REQUISITO do Aprovar.
 *
 * 🐛 REGRESSÃO (Estúdio de Animação → "📣 Aprovar e publicar"): o endpoint respondia ok:true mesmo
 * quando NENHUMA rede ganhou texto (sem crédito, ou engine falhando em todas). A tela dava o passo
 * como concluído e navegava pro /aprovar — que monta as abas de rede a partir de draft.texts e só
 * mostra o preview da mídia junto delas. Sem texto, a página abria vazia: "não carrega o vídeo e
 * nem aparece as redes". Zero entrega tem de responder erro (402 sem saldo · 502 falha da geração).
 */
class StudioStoryTextsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $marca;

    private User $cliente;

    private function preparar(int $saldo): void
    {
        $this->marca = Tenant::factory()->for(Organization::factory()->paying('studio', $saldo))->create();
        TenantScope::flushActiveTenantCache();
        $this->cliente = User::factory()->create([
            'tenant_id' => $this->marca->id,
            'organization_id' => $this->marca->organization_id,
            'role' => 'client',
        ]);
    }

    /** Rascunho no formato que a ponte do Estúdio de Animação grava (story.scenes com voiceover). */
    private function rascunhoDeAnimacao(): Draft
    {
        return Draft::factory()->create([
            'tenant_id' => $this->marca->id,
            'keyword' => 'A raposa e o drone',
            'video_url' => 'https://s3.example.com/public/reachyn/video/final.mp4',
            'story' => [
                'theme' => 'A raposa e o drone',
                'lang' => 'pt-BR',
                'status' => 'ready',
                'scenes' => [['title' => 'Cena 1', 'voiceover' => 'A raposa acorda com um zumbido.']],
            ],
        ]);
    }

    /** @param  list<string>  $platforms */
    private function pedir(Draft $d, array $platforms = ['youtube', 'instagram', 'linkedin']): TestResponse
    {
        return $this->actingAs($this->cliente)->postJson('/api/studio/story-texts', [
            'draftId' => $d->id,
            'platforms' => $platforms,
        ]);
    }

    /** O vídeo final da montagem, tagueado só pras 3 redes históricas. */
    private function comMidiaFinal(Draft $d, array $platforms = ['youtube', 'instagram', 'linkedin']): Draft
    {
        $d->update(['media' => [[
            'id' => 'm1', 'kind' => 'video', 'style' => 'historia',
            'url' => 'https://s3.example.com/public/reachyn/video/final.mp4',
            'platforms' => $platforms,
        ]]]);

        return $d->fresh();
    }

    public function test_gera_e_persiste_a_legenda_por_rede(): void
    {
        $this->preparar(10000);
        Http::fake(['*/v1/text' => Http::response(['post' => 'A raposa e o drone — assista!', 'rank_summary' => 1, 'grounding' => 1, 'flags' => []])]);
        $d = $this->rascunhoDeAnimacao();

        $j = $this->pedir($d)->assertOk()->json();

        $this->assertTrue($j['ok']);
        $this->assertSame(3, $j['generated']);
        $this->assertSame(['youtube', 'instagram', 'linkedin'], array_keys($d->fresh()->texts));
    }

    public function test_sem_saldo_responde_402_em_vez_de_ok_vazio(): void
    {
        $this->preparar(0);
        Http::fake(['*/v1/text' => Http::response(['post' => 'nunca deveria ser chamado'])]);
        $d = $this->rascunhoDeAnimacao();

        $this->pedir($d)->assertStatus(402);

        $this->assertEmpty($d->fresh()->texts ?? []); // nada de rascunho "pronto" sem legenda
    }

    public function test_engine_falhando_em_todas_as_redes_responde_502(): void
    {
        $this->preparar(10000);
        Http::fake(['*/v1/text' => Http::response([], 500)]);
        $d = $this->rascunhoDeAnimacao();

        $this->pedir($d)->assertStatus(502);

        $this->assertEmpty($d->fresh()->texts ?? []);
    }

    public function test_post_vazio_do_engine_nao_conta_como_legenda(): void
    {
        $this->preparar(10000);
        Http::fake(['*/v1/text' => Http::response(['post' => '   ', 'rank_summary' => 0, 'grounding' => 0, 'flags' => []])]);
        $d = $this->rascunhoDeAnimacao();

        $this->pedir($d)->assertStatus(502);

        $this->assertEmpty($d->fresh()->texts ?? []);
    }

    /**
     * 🐛 REGRESSÃO: escolher uma rede NOVA depois da montagem deixava ela sem mídia.
     *
     * O vídeo é montado com platforms=[youtube,instagram,linkedin]. Ao marcar TikTok nas badges e
     * gerar a legenda, o Aprovar mostrava a aba do TikTok com "Nenhuma mídia destinada a esta rede"
     * e o publish (que faz interseção com media[].platforms) pulava a rede em silêncio.
     */
    public function test_rede_nova_passa_a_ser_servida_pela_midia_final(): void
    {
        $this->preparar(10000);
        Http::fake(['*/v1/text' => Http::response(['post' => 'legenda', 'rank_summary' => 1, 'grounding' => 1, 'flags' => []])]);
        $d = $this->comMidiaFinal($this->rascunhoDeAnimacao());

        $this->pedir($d, ['tiktok'])->assertOk();

        $midia = $d->fresh()->media[0];
        $this->assertContains('tiktok', $midia['platforms']);
        $this->assertContains('youtube', $midia['platforms']); // não perde as antigas
    }

    public function test_midia_que_ja_serve_todas_as_redes_nao_e_alterada(): void
    {
        $this->preparar(10000);
        Http::fake(['*/v1/text' => Http::response(['post' => 'legenda', 'rank_summary' => 1, 'grounding' => 1, 'flags' => []])]);
        $d = $this->comMidiaFinal($this->rascunhoDeAnimacao(), []); // [] = serve todas

        $this->pedir($d, ['tiktok'])->assertOk();

        $this->assertSame([], $d->fresh()->media[0]['platforms']); // continua "todas", não vira lista de 1
    }

    public function test_cena_intermediaria_nao_e_retagueada(): void
    {
        $this->preparar(10000);
        Http::fake(['*/v1/text' => Http::response(['post' => 'legenda', 'rank_summary' => 1, 'grounding' => 1, 'flags' => []])]);
        $d = $this->rascunhoDeAnimacao();
        $d->update(['media' => [[
            'id' => 'c1', 'kind' => 'video', 'scene' => 0, 'url' => 'https://s3.example.com/public/reachyn/video/cena0.mp4',
            'platforms' => ['youtube'],
        ]]]);

        $this->pedir($d, ['tiktok'])->assertOk();

        $this->assertSame(['youtube'], $d->fresh()->media[0]['platforms']); // cena não é o que se publica
    }

    /**
     * 🐛 REGRESSÃO: desmarcar uma rede nas badges não desfazia nada.
     *
     * A legenda antiga do LinkedIn seguia em draft.texts — reaparecia no Aprovar e o PublishService
     * (que itera draft.texts) publicava nela mesmo desmarcada. "Tirei o LinkedIn e ele persiste."
     */
    public function test_post_networks_remove_a_legenda_da_rede_desmarcada(): void
    {
        $this->preparar(10000);
        $d = $this->comMidiaFinal($this->rascunhoDeAnimacao());
        $d->update([
            'texts' => ['youtube' => 'legenda yt', 'linkedin' => 'legenda li'],
            'texts_meta' => ['youtube' => ['lang' => 'pt-BR'], 'linkedin' => ['lang' => 'pt-BR']],
        ]);

        $j = $this->actingAs($this->cliente)->postJson('/api/studio/post-networks', [
            'draftId' => $d->id, 'platforms' => ['youtube'],
        ])->assertOk()->json();

        $this->assertSame(['linkedin'], $j['removed']);
        $this->assertSame(['youtube'], array_keys($d->fresh()->texts));
        $this->assertSame(['youtube'], array_keys($d->fresh()->texts_meta)); // o meta acompanha
    }

    public function test_post_networks_recusa_seleção_vazia(): void
    {
        $this->preparar(10000);
        $d = $this->rascunhoDeAnimacao();
        $d->update(['texts' => ['youtube' => 'legenda yt']]);

        $this->actingAs($this->cliente)->postJson('/api/studio/post-networks', [
            'draftId' => $d->id, 'platforms' => [],
        ])->assertStatus(422);

        $this->assertSame(['youtube'], array_keys($d->fresh()->texts)); // não apaga tudo por engano
    }

    public function test_falha_parcial_ainda_entrega_as_redes_que_deram_certo(): void
    {
        $this->preparar(10000);
        $n = 0;
        Http::fake(['*/v1/text' => function () use (&$n) {
            $n++;

            return $n === 1 ? Http::response(['post' => 'legenda do YouTube', 'rank_summary' => 1, 'grounding' => 1, 'flags' => []]) : Http::response([], 500);
        }]);
        $d = $this->rascunhoDeAnimacao();

        $j = $this->pedir($d)->assertOk()->json();

        $this->assertSame(1, $j['generated']);
        $this->assertSame(['youtube'], array_keys($d->fresh()->texts));
    }
}
