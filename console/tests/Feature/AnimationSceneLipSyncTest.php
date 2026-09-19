<?php

namespace Tests\Feature;

use App\Jobs\AnimationSceneJob;
use App\Models\AnimationProject;
use App\Services\UsageService;
use Database\Factories\AnimationProjectFactory as Scene;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 🎬 Contrato do lip-sync da cena: a boca sincronizada anima o KEYFRAME (a toca, a floresta) —
 * nunca o retrato do personagem em fundo de estúdio.
 *
 * Regressão de prod (projeto 12, 2026-07-15): o lip-sync per-fala mandava o `char_ref` (ficha 3:4)
 * como imagem, então 5 das 6 cenas do desenho viraram a ficha em fundo cinza, e o cover+crop da
 * montagem pro 16:9 decapitava o personagem. O aspecto do clipe SEGUE a imagem de entrada — por
 * isso mandar o keyframe é o que mantém a cena no 16:9 e fora do crop.
 */
class AnimationSceneLipSyncTest extends TestCase
{
    use RefreshDatabase;

    private const KEYFRAME = 'https://s3/keyframe-da-cena.jpg';

    private function project(): AnimationProject
    {
        return AnimationProject::factory()->animating([Scene::scenePendente(1)])->create();
    }

    /** @param  list<array<string,mixed>>|null  $lines */
    private function job(AnimationProject $p, ?array $lines, ?array $lipLine): AnimationSceneJob
    {
        return new AnimationSceneJob(
            projectId: $p->id,
            tenantId: (int) $p->tenant_id,
            index: 0,
            lines: $lines,
            clip: ['prompt' => 'a cena respira', 'imageUrl' => self::KEYFRAME, 'aspect' => '16:9', 'duration' => '6'],
            weight: 1,
            costCredits: 10,
            audioCount: $lines === null ? 0 : count($lines),
            ttsModel: 'tts-model',
            upscale: null,
            lipLine: $lipLine,
        );
    }

    private function fakeEngine(): void
    {
        Http::fake([
            '*/v1/dialogueaudio' => Http::response(['url' => 'https://s3/dialogo.mp3', 'duration' => 4.2]),
            '*/v1/lipsync' => Http::response(['url' => 'https://s3/cena-com-boca.mp4']),
            '*/v1/filmclip' => Http::response(['url' => 'https://s3/cena-muda.mp4']),
            '*/v1/muxaudio' => Http::response(['url' => 'https://s3/cena-muxada.mp4']),
        ]);
    }

    public function test_lipsync_anima_o_keyframe_da_cena_e_nao_um_retrato(): void
    {
        $this->fakeEngine();
        $p = $this->project();

        $this->job($p, [['text' => 'Meu amor, a noite guarda surpresas.', 'voice_id' => 'v-mae']], ['video' => ['primary' => 'omni']])
            ->handle(app(UsageService::class));

        Http::assertSent(fn ($r) => str_contains($r->url(), '/v1/lipsync') && $r['imageUrl'] === self::KEYFRAME);
        $this->assertSame('https://s3/cena-com-boca.mp4', $p->fresh()->storyboard[0]['video_url']);
    }

    /** O diálogo INTEIRO vai num lip-sync só — não 1 clipe por fala (a 2ª sumia em silêncio). */
    public function test_cena_com_varias_falas_gera_um_unico_clipe(): void
    {
        $this->fakeEngine();
        $p = $this->project();

        $this->job($p, [
            ['text' => 'Mamãe... e se tiver monstros?', 'voice_id' => 'v-pipo'],
            ['text' => 'Vamos juntos conhecer a noite.', 'voice_id' => 'v-mae'],
            ['text' => 'Tá bom!', 'voice_id' => 'v-pipo'],
        ], ['video' => ['primary' => 'omni']])->handle(app(UsageService::class));

        Http::assertSentCount(2); // dialogueaudio + 1 lipsync — o i2v nem é chamado
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/v1/filmclip'));
    }

    /** Cena SEM fala não tem o que sincronizar — segue no i2v mudo. */
    public function test_cena_sem_fala_usa_i2v(): void
    {
        $this->fakeEngine();
        $p = $this->project();

        $this->job($p, null, ['video' => ['primary' => 'omni']])->handle(app(UsageService::class));

        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/v1/lipsync'));
        $this->assertSame('https://s3/cena-muda.mp4', $p->fresh()->storyboard[0]['video_url']);
    }

    /** Lip-sync fora do ar não pode matar a cena: cai no i2v + mux, com a fala colada por cima. */
    public function test_lipsync_que_falha_cai_no_i2v_com_mux(): void
    {
        Http::fake([
            '*/v1/dialogueaudio' => Http::response(['url' => 'https://s3/dialogo.mp3', 'duration' => 4.2]),
            '*/v1/lipsync' => Http::response(['error' => 'provider fora do ar'], 500),
            '*/v1/filmclip' => Http::response(['url' => 'https://s3/cena-muda.mp4']),
            '*/v1/muxaudio' => Http::response(['url' => 'https://s3/cena-muxada.mp4']),
        ]);
        $p = $this->project();

        $this->job($p, [['text' => 'oi', 'voice_id' => 'v1']], ['video' => ['primary' => 'omni']])
            ->handle(app(UsageService::class));

        $sc = $p->fresh()->storyboard[0];
        $this->assertSame('https://s3/cena-muxada.mp4', $sc['video_url']);
        $this->assertSame('ready', $sc['video_status'], 'lip-sync fora do ar não pode marcar a cena como erro');
    }
}
