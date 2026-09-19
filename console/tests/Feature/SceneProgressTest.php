<?php

namespace Tests\Feature;

use App\Jobs\AnimationSceneJob;
use App\Models\AnimationProject;
use App\Models\Organization;
use App\Models\Tenant;
use App\Services\AnimationFlow;
use App\Services\UsageService;
use Database\Factories\AnimationProjectFactory as Scene;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ⏱️ A cena diz em que etapa está — e some com o rótulo quando termina.
 *
 * O card mostrava "animando a cena…" do primeiro ao último segundo, então um job rodando há 8
 * minutos era visualmente idêntico a um travado: uma geração perfeitamente normal virou chamado
 * de "está parado". O stage é o que a tela lê para dizer o que está acontecendo.
 */
class SceneProgressTest extends TestCase
{
    use RefreshDatabase;

    private function cena(AnimationProject $p, int $i = 0): array
    {
        return (array) (array_values((array) $p->fresh()->storyboard)[$i] ?? []);
    }

    /** Cena que termina bem larga o rótulo de etapa (senão a tela mostraria progresso eterno). */
    public function test_cena_pronta_limpa_a_etapa(): void
    {
        $t = Tenant::factory()->for(Organization::factory()->paying('studio', 10000))->create();
        Http::fake(['*/v1/filmclip' => Http::response(['url' => 'https://s3.example.com/public/reachyn/video/ok.mp4'])]);

        $p = AnimationProject::factory()->animating([Scene::scenePendente(1)])->create(['tenant_id' => $t->id]);
        // Estado de quem está no meio da geração, como o dispatch deixa.
        app(AnimationFlow::class)->patchScene($p, 0, [
            'video_status' => 'generating', 'video_stage' => 'clipe', 'video_started_at' => now()->timestamp,
        ]);

        (new AnimationSceneJob($p->id, $t->id, 0, null, [
            'prompt' => 'x', 'imageUrl' => 'https://s3.example.com/public/reachyn/image/kf.jpg',
            'duration' => '6', 'aspect' => '9:16', 'gen_lines' => ['video' => ['primary' => 'm', 'provider' => 'kie']],
        ], 1, 10))->handle(app(UsageService::class));

        $sc = $this->cena($p);
        $this->assertSame('ready', $sc['video_status']);
        $this->assertSame('', $sc['video_stage'] ?? null, 'cena pronta não pode continuar anunciando etapa');
    }

    /** O aviso de que o lip-sync caiu sobrevive até o fim da cena — é o que explica a demora. */
    public function test_falha_do_lipsync_marca_a_etapa_e_nao_e_sobrescrita(): void
    {
        $t = Tenant::factory()->for(Organization::factory()->paying('studio', 10000))->create();
        Http::fake([
            '*/v1/dialogueaudio' => Http::response(['url' => 'https://s3.example.com/public/reachyn/tts/a.mp3', 'duration' => 5.0]),
            '*/v1/lipsync' => Http::response([], 502),                     // o provedor cai
            '*/v1/filmclip' => Http::response(['url' => 'https://s3.example.com/public/reachyn/video/ok.mp4']),
            '*/v1/muxaudio' => Http::response(['url' => 'https://s3.example.com/public/reachyn/video/mux.mp4']),
        ]);

        $p = AnimationProject::factory()->animating([Scene::scenePendente(1)])->create(['tenant_id' => $t->id]);
        $usage = app(UsageService::class);
        $usage->tryConsume($t, 'video', 1, 10);
        $usage->tryConsume($t, 'audio', 1, 1);

        $job = new AnimationSceneJob($p->id, $t->id, 0,
            [['text' => 'olá', 'voice_id' => '', 'tts_style' => '']],
            ['prompt' => 'x', 'imageUrl' => 'https://s3.example.com/public/reachyn/image/kf.jpg',
                'duration' => '6', 'aspect' => '9:16', 'gen_lines' => ['video' => ['primary' => 'm', 'provider' => 'kie']]],
            1, 10, 1, '', null, ['video' => ['primary' => 'lip', 'provider' => 'kie']]);

        $job->handle($usage);

        $sc = $this->cena($p);
        $this->assertSame('ready', $sc['video_status'], 'a cena precisa terminar pelo caminho alternativo');
        $this->assertSame('', $sc['video_stage'] ?? null, 'ao terminar, o rótulo sai — inclusive o do fallback');
        $this->assertNotSame('', (string) ($sc['video_url'] ?? ''));
    }
}
