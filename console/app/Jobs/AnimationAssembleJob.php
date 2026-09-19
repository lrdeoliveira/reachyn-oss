<?php

namespace App\Jobs;

use App\Jobs\Concerns\RetriesTransientEngineErrors;
use App\Mail\GenerationReady;
use App\Models\AnimationProject;
use App\Models\Draft;
use App\Models\Tenant;
use App\Services\Notifier;
use App\Services\UsageService;
use App\Support\EngineClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 🎬 Estúdio de Animação — passo 5: MONTA o desenho final (/v1/filmassemble: concat das
 * cenas — o diálogo já vive dentro de cada clipe — + trilha com ducking + color-match) e
 * publica o resultado como um Draft normal (galeria → aprovações → publicações).
 * Cota 'short' reservada no dispatch; estornada aqui se falhar.
 */
class AnimationAssembleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RetriesTransientEngineErrors, SerializesModels;

    public int $timeout = 1300;

    // Retry idempotente: a montagem é o passo final e caro; um blip no /v1/filmassemble descartava
    // o desenho inteiro. Re-montar só re-lê o storyboard (cenas já geradas), então é seguro.
    public int $tries = 3;

    public function __construct(
        public int $projectId,
        public int $tenantId,
        public array $payload,       // body do endpoint de montagem
        public string $endpoint = '/v1/filmassemble', // modos narrados usam '/v1/storyvideo'
        public ?int $shortCost = null,  // override do bucket 'short' (slides-only = 40) — estornado igual
        public int $fxCount = 0,        // efeitos cobrados no dispatch (bucket 'effect') — estornados em falha
        public array $platforms = [],   // redes-alvo do item da galeria (publish seletivo)
        public string $styleTag = 'animacao', // tag do item na galeria: animacao|historia|quadrinhos
    ) {
        $this->onQueue('animation'); // ver AnimationSceneJob: fan-out isolado do resto da fila
    }

    public function handle(UsageService $usage): void
    {
        $p = AnimationProject::find($this->projectId);
        if (! $p) {
            $this->refund($usage);

            return;
        }
        $res = EngineClient::make(1200)
            ->post($this->endpoint, $this->payload);

        $url = $this->engineUrlOrRetry($res, 'AnimationAssembleJob', ['project' => $this->projectId]);
        if ($url === '') {
            $this->refund($usage);
            $p->update(['status' => 'error', 'error' => 'A montagem final falhou — tente montar de novo.', 'auto' => false]);

            return;
        }

        // O vídeo pronto vira um Draft normal: cai na galeria e segue o fluxo aprovar/publicar.
        // 🌉 PONTE: gravamos um story JSON compatível com o clássico no draft final — assim
        // "✍️ Gerar textos do post" (storyTexts), o export ZIP (storyExport) e o Aprovar
        // funcionam no resultado do wizard sem código novo.
        $scenes = [];
        foreach (array_values((array) $p->storyboard) as $sc) {
            $scenes[] = [
                'title' => (string) ($sc['title'] ?? ''),
                'voiceover' => trim((string) ($sc['narration'] ?? '')) ?: (string) ($sc['action'] ?? ''),
                'image_prompt' => (string) ($sc['image_prompt'] ?? ''),
                'video_prompt' => (string) ($sc['video_prompt'] ?? ''),
                'image_url' => (string) ($sc['keyframe_url'] ?? ''),
                'video_url' => (string) ($sc['video_url'] ?? ''),
                'audio_url' => (string) ($sc['audio_url'] ?? ''),
            ];
        }
        $d = Draft::create([
            'tenant_id' => $this->tenantId,
            'keyword' => mb_substr($p->title !== '' ? $p->title : 'Desenho animado', 0, 80),
            'video_url' => $url,
            'story' => [
                'theme' => $p->title !== '' ? $p->title : 'Desenho animado',
                'lang' => $p->lang,
                'status' => 'ready',
                'scenes' => $scenes,
            ],
            'media' => [[
                'id' => Draft::mediaId(),
                'kind' => 'video',
                'url' => $url,
                'style' => $this->styleTag,
                'platforms' => array_values($this->platforms),
            ]],
        ]);
        $p->update([
            'final_url' => $url,
            'final_draft_id' => $d->id,
            'status' => 'done',
            'auto' => false,
            'error' => '',
        ]);

        // S2: geração LONGA concluída → e-mail "ficou pronto" (1 por projeto, via throttle por id;
        // opt-out em notify_prefs.generation_ready). O link cai direto no estúdio no modo certo.
        $org = Tenant::find($this->tenantId)?->organization;
        if ($org) {
            $modo = in_array($p->mode, ['historia', 'quadrinhos', 'animacao'], true) ? $p->mode : 'animacao';
            $tipo = $modo === 'quadrinhos' ? 'Seu quadrinho' : ($modo === 'historia' ? 'Sua história' : 'Seu desenho animado');
            $titulo = trim((string) $p->title) !== '' ? ' "'.mb_substr($p->title, 0, 60).'"' : '';
            app(Notifier::class)->send(
                $org,
                'generation_ready',
                new GenerationReady($tipo.$titulo, rtrim((string) config('services.studio.url', 'https://app.reachyn.agency'), '/').'/estudio?modo='.$modo),
                'generation_ready:project:'.$p->id,
                86400,
            );
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::warning('AnimationAssembleJob falhou', ['project' => $this->projectId, 'error' => $e->getMessage()]);
        $this->refund(app(UsageService::class));
        AnimationProject::find($this->projectId)?->update(['status' => 'error', 'error' => 'A montagem final falhou — tente montar de novo.', 'auto' => false]);
    }

    private function refund(UsageService $usage): void
    {
        $t = Tenant::find($this->tenantId);
        if ($t) {
            $usage->refund($t, 'short', 1, $this->shortCost);
            if ($this->fxCount > 0) {
                $usage->refund($t, 'effect', $this->fxCount); // efeitos cobrados junto da montagem
            }
        }
    }
}
