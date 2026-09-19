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
 * 🏷️ /api/studio/post-networks — quais redes uma peça pronta serve (badges no Aprovar).
 *
 * 🐛 REGRESSÃO: desmarcar uma rede nas badges não desfazia nada. A legenda antiga seguia em
 * draft.texts, reaparecia no Aprovar e o PublishService (que itera draft.texts) publicava nela
 * mesmo desmarcada. Este endpoint sincroniza texts/texts_meta com as redes selecionadas.
 *
 * A geração de legenda por cena (story-texts) saiu daqui com a criação de mídia — foi pro
 * FoxAssets. A mídia agora entra pronta por "Subir arquivo"; a marcação por rede é a parte que
 * ficou no fluxo de publicação.
 */
class PostNetworksTest extends TestCase
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

    /** Rascunho simples com mídia pronta (subida por /postar). */
    private function rascunhoDeAnimacao(): Draft
    {
        return Draft::factory()->create([
            'tenant_id' => $this->marca->id,
            'keyword' => 'A raposa e o drone',
            'video_url' => 'https://s3.example.com/public/reachyn/video/final.mp4',
        ]);
    }

    /** O vídeo final, tagueado só pras 3 redes históricas. */
    private function comMidiaFinal(Draft $d, array $platforms = ['youtube', 'instagram', 'linkedin']): Draft
    {
        $d->update(['media' => [[
            'id' => 'm1', 'kind' => 'video', 'style' => 'historia',
            'url' => 'https://s3.example.com/public/reachyn/video/final.mp4',
            'platforms' => $platforms,
        ]]]);

        return $d->fresh();
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

}
