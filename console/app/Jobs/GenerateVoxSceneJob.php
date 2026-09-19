<?php

namespace App\Jobs;

use App\Jobs\Concerns\RetriesTransientEngineErrors;
use App\Models\Draft;
use App\Models\Tenant;
use App\Services\UsageService;
use App\Support\EngineClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 📰 Gera UMA cena do Vox (imagem em colagem + clipe i2v) de forma ASSÍNCRONA — a regeneração
 * beat a beat da aba /video (estilo Vox) (V2). Espelho do GenerateStoryClipJob: o worker chama o engine
 * (/v1/voxscene) e grava a URL em story.vox.beats[index].clip_url; a aba faz polling do rascunho
 * até a cena aparecer. Cota: reservada no controller (reserve-then-consume) e ESTORNADA aqui se
 * a geração falhar. O clipe também entra na GALERIA (kind=video, scene=index+1) — cena gerada é
 * cena paga, e mídia paga que só existe dentro de um JSON é mídia perdida.
 */
class GenerateVoxSceneJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RetriesTransientEngineErrors, SerializesModels;

    // Imagem (~1min) + i2v (~1-3min): teto que falha rápido e libera o worker (padrão do
    // GenerateStoryClipJob — job de 20min segurava a fila serial inteira).
    public int $timeout = 480;

    // Retry idempotente: grava clip_url sob lock e o attach é dedup por URL.
    public int $tries = 3;

    public function __construct(
        public int $draftId,
        public int $tenantId,
        public int $index,        // índice do beat dentro de story.vox.beats
        public array $payload,    // body do endpoint (image_prompt/prompt + aspect + gen_lines + extras)
        public string $usageKind, // bucket de cota: 'video'
        public int $weight,
        public ?int $costCredits = null, // custo em créditos do modelo (null = custo fixo por tipo)
        // 🎨 Unificação Vídeo+Vox (2026-08-06): a cena a cena deixou de ser exclusiva do Vox.
        // Estilo 'vox' → /v1/voxscene (colagem + i2v do formato); qualquer outro estilo →
        // /v1/video de uma cena, o caminho genérico que a aba Vídeo sempre usou. Os dois
        // respondem {"url"}, então o resto do job é idêntico.
        public string $endpoint = '/v1/voxscene',
        // `style` da mídia na GALERIA — é ele que classifica a peça (catOf em galeria/page.tsx).
        public string $style = 'vox',
    ) {}

    public function handle(UsageService $usage): void
    {
        $d = Draft::find($this->draftId);
        if (! $d) {
            $this->refund($usage);

            return;
        }

        $res = EngineClient::make(420)->post($this->endpoint, $this->payload);

        $url = $this->engineUrlOrRetry($res, 'GenerateVoxSceneJob', ['draft' => $this->draftId, 'index' => $this->index]);
        if ($url === '') {
            $this->refund($usage);

            return;
        }

        // Read-modify-write da coluna JSON sob LOCK: várias cenas podem gerar em paralelo, então
        // recarregamos o rascunho travado pra não sobrescrever o clip_url de outra cena (mesma
        // lição do GenerateStoryClipJob).
        DB::transaction(function () use ($url) {
            $d = Draft::lockForUpdate()->find($this->draftId);
            if (! $d) {
                return;
            }
            $upd = [];
            $story = is_array($d->story) ? $d->story : [];
            $beats = $story['vox']['beats'] ?? [];
            if (isset($beats[$this->index])) {
                $beats[$this->index]['clip_url'] = $url;
                $story['vox']['beats'] = $beats;
                $upd['story'] = $story;
            }
            // Galeria: anexa o clipe (dedup por URL). `scene` = de qual capítulo veio — e é o que
            // faz a galeria tratá-lo como clipe de cena, não como peça final.
            $media = $d->media ?? [];
            if (! collect($media)->contains(fn ($m) => ($m['url'] ?? null) === $url)) {
                $media[] = ['id' => Draft::mediaId(), 'kind' => 'video', 'url' => $url, 'style' => $this->style, 'platforms' => [], 'scene' => $this->index + 1];
                $upd['media'] = $media;
            }
            if ($upd !== []) {
                $d->update($upd);
            }
        });
    }

    public function failed(\Throwable $e): void
    {
        Log::warning('GenerateVoxSceneJob falhou', ['draft' => $this->draftId, 'index' => $this->index, 'error' => $e->getMessage()]);
        $this->refund(app(UsageService::class));
    }

    private function refund(UsageService $usage): void
    {
        $t = Tenant::find($this->tenantId);
        if ($t) {
            $usage->refund($t, $this->usageKind, $this->weight, $this->costCredits);
        }
    }
}
