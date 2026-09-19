<?php

namespace Tests\Feature;

use App\Jobs\AnimationSceneJob;
use App\Jobs\Concerns\TransientEngineException;
use App\Models\AnimationProject;
use App\Models\Organization;
use App\Models\Tenant;
use App\Services\CreditWallet;
use App\Services\UsageService;
use Database\Factories\AnimationProjectFactory as Scene;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 💳 Estorno da cena: o que foi cobrado 1× no dispatch tem de voltar 1× — nem 0, nem 2.
 *
 * A cena reserva crédito no dispatch (video + 1 audio por fala) e o job estorna o que não entregou.
 * Cada etapa tem seu próprio caminho de estorno, e eles se cruzam: o áudio pode falhar sozinho (a
 * cena segue muda) ou junto com o vídeo. Sem teste, esses cruzamentos só aparecem no extrato.
 */
class AnimationSceneRefundTest extends TestCase
{
    use RefreshDatabase;

    private function marcaPagante(int $saldo = 10000): Tenant
    {
        return Tenant::factory()->for(Organization::factory()->paying('studio', $saldo))->create();
    }

    private function saldo(Tenant $t): int
    {
        return app(CreditWallet::class)->balance($t->organization->fresh());
    }

    /** @param  list<array<string,mixed>>|null  $lines */
    private function rodarCena(Tenant $t, ?array $lines, array $fakes): void
    {
        Http::fake($fakes);
        $p = AnimationProject::factory()->animating([Scene::scenePendente(1)])->create(['tenant_id' => $t->id]);
        $usage = app(UsageService::class);

        // Simula o que o dispatch reserva antes de enfileirar (reserve-then-consume).
        $usage->tryConsume($t, 'video', 1, 60);
        $nLines = $lines === null ? 0 : count($lines);
        if ($nLines > 0) {
            $usage->tryConsume($t, 'audio', $nLines, 1);
        }

        (new AnimationSceneJob(
            projectId: $p->id, tenantId: $t->id, index: 0, lines: $lines,
            clip: ['prompt' => 'x', 'imageUrl' => 'https://s3/k1.jpg', 'aspect' => '16:9', 'duration' => '6'],
            weight: 1, costCredits: 60, audioCount: $nLines, ttsModel: 'tts', upscale: null, lipLine: null,
        ))->handle($usage);
    }

    /**
     * 🐛 REGRESSÃO: áudio E vídeo falham → o áudio era estornado DUAS vezes.
     *
     * O passo do diálogo já estorna o áudio quando ele não sai ("segue mudo"). Depois, o i2v
     * falhando chamava refund(refundAudio: $audioUrl === ''), e $audioUrl ESTÁ vazio justamente
     * porque o áudio falhou — então estornava o mesmo áudio de novo. Crédito criado do nada.
     */
    public function test_audio_e_video_falhando_nao_estorna_o_audio_duas_vezes(): void
    {
        $t = $this->marcaPagante();
        $antes = $this->saldo($t);

        $this->rodarCena($t, [['text' => 'oi', 'voice_id' => 'v1'], ['text' => 'ola', 'voice_id' => 'v2']], [
            '*/v1/dialogueaudio' => Http::response(['error' => 'tts fora'], 500),
            '*/v1/filmclip' => Http::response(['error' => 'param inválido'], 422), // 4xx = permanente → desiste e estorna (não retenta)
        ]);

        // Reservou 60 (vídeo) + 2 (áudio) e não entregou nada → volta exatamente 62.
        $this->assertSame($antes, $this->saldo($t), 'estorno tem de fechar a conta, sem sobra');
    }

    /** Áudio OK e vídeo falhando: o áudio FOI gerado (custou), então não se estorna o áudio. */
    public function test_video_falhando_com_audio_pronto_nao_estorna_o_audio(): void
    {
        $t = $this->marcaPagante();
        $antes = $this->saldo($t);

        $this->rodarCena($t, [['text' => 'oi', 'voice_id' => 'v1']], [
            '*/v1/dialogueaudio' => Http::response(['url' => 'https://s3/a.mp3', 'duration' => 3.0]),
            '*/v1/filmclip' => Http::response(['error' => 'param inválido'], 422), // 4xx = permanente → desiste e estorna (não retenta)
        ]);

        // Volta o vídeo (60), fica cobrado 1 de áudio — o arquivo existe.
        $this->assertSame($antes - 1, $this->saldo($t));
    }

    /** Cena sem fala que falha: estorna só o vídeo. */
    public function test_cena_muda_que_falha_estorna_so_o_video(): void
    {
        $t = $this->marcaPagante();
        $antes = $this->saldo($t);

        $this->rodarCena($t, null, [
            '*/v1/filmclip' => Http::response(['error' => 'param inválido'], 422), // 4xx = permanente → desiste e estorna (não retenta)
        ]);

        $this->assertSame($antes, $this->saldo($t));
    }

    /** Caminho feliz: entregou → o que foi reservado FICA cobrado (60 vídeo + 1 áudio). */
    public function test_cena_pronta_permanece_cobrada(): void
    {
        $t = $this->marcaPagante();
        $antes = $this->saldo($t);

        $this->rodarCena($t, [['text' => 'oi', 'voice_id' => 'v1']], [
            '*/v1/dialogueaudio' => Http::response(['url' => 'https://s3/a.mp3', 'duration' => 3.0]),
            '*/v1/filmclip' => Http::response(['url' => 'https://s3/v.mp4']),
            '*/v1/muxaudio' => Http::response(['url' => 'https://s3/mux.mp4']),
        ]);

        $this->assertSame($antes - 61, $this->saldo($t), 'cena entregue não devolve crédito');
    }

    /**
     * 🔁 RETRY: i2v com erro TRANSITÓRIO (5xx / 200-sem-url) relança pra retentar (não desiste
     * na 1ª). Enquanto retenta, NÃO estorna — o crédito segue reservado. O estorno só acontece
     * ao esgotar as tentativas (via failed()) ou num erro permanente (4xx).
     */
    public function test_i2v_transitorio_relanca_para_retentar_sem_estornar(): void
    {
        $t = $this->marcaPagante();
        $antes = $this->saldo($t);

        try {
            $this->rodarCena($t, null, [
                '*/v1/filmclip' => Http::response(['error' => 'i2v fora'], 500), // 5xx = transitório → retenta
            ]);
            $this->fail('esperava TransientEngineException (job deveria relançar pra retentar)');
        } catch (TransientEngineException $e) {
            // esperado
        }

        // Reservou 60 (vídeo) e AINDA não desistiu → o crédito NÃO volta enquanto há retry.
        $this->assertSame($antes - 60, $this->saldo($t), 'transitório não estorna — segue reservado pro retry');
    }
}
