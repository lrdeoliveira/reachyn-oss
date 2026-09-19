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
 * Gera o VÍDEO (image-to-video) de UMA cena da história de forma ASSÍNCRONA. O i2v leva
 * minutos e, feito no request, estourava o timeout do proxy. Aqui o worker chama o engine
 * (/v1/video, scenes=1 = atalho i2v direto) e grava a URL em story.scenes[index].video_url;
 * o gerador de Histórias faz polling do rascunho até o vídeo da cena aparecer. Cota:
 * reservada no controller (reserve-then-consume) e ESTORNADA aqui se a geração falhar.
 */
class GenerateStoryClipJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RetriesTransientEngineErrors, SerializesModels;

    // Teto curto: i2v de 1 clipe leva ~1-3min. Se o provedor travar, falha em ~6min e LIBERA o
    // worker (antes 20min seguravam a fila serial e entupiam tudo).
    public int $timeout = 420;

    // Retry idempotente: grava video_url sob lock e o attach é dedup por URL. Transitório retenta
    // (blip de rede); 4xx desiste na hora, preservando o fail-fast que libera o worker.
    public int $tries = 3;

    public function __construct(
        public int $draftId,
        public int $tenantId,
        public int $index,        // índice da cena dentro de story.scenes
        public array $payload,    // body do /v1/video (prompt + imageUrl + scenes=1 + duration + aspect + gen_lines?)
        public string $usageKind, // bucket de cota: 'video'
        public int $weight,
        public ?int $costCredits = null, // custo em créditos do modelo escolhido (null = custo fixo por tipo)
    ) {}

    public function handle(UsageService $usage): void
    {
        $d = Draft::find($this->draftId);
        if (! $d) {
            $this->refund($usage);

            return;
        }

        $res = EngineClient::make(360) // i2v não deve passar disso; estoura → falha rápido + estorna
            ->post('/v1/video', $this->payload);

        $url = $this->engineUrlOrRetry($res, 'GenerateStoryClipJob', ['draft' => $this->draftId, 'index' => $this->index]);
        if ($url === '') {
            $this->refund($usage);

            return;
        }

        // Read-modify-write da coluna JSON sob LOCK: várias cenas podem gerar em paralelo, então
        // recarregamos o rascunho travado para não sobrescrever o video_url de outra cena. No mesmo
        // passo, anexa o clipe à GALERIA (kind=video) — antes os vídeos de cena só ficavam na cena.
        DB::transaction(function () use ($url) {
            $d = Draft::lockForUpdate()->find($this->draftId);
            if (! $d) {
                return;
            }
            $upd = [];
            $story = is_array($d->story) ? $d->story : [];
            $scenes = $story['scenes'] ?? [];
            if (isset($scenes[$this->index])) {
                $scenes[$this->index]['video_url'] = $url;
                $story['scenes'] = $scenes;
                $upd['story'] = $story;
            }
            // Galeria: anexa o vídeo (sem duplicar a mesma URL). `scene` marca de qual cena veio.
            $media = $d->media ?? [];
            if (! collect($media)->contains(fn ($m) => ($m['url'] ?? null) === $url)) {
                $media[] = ['id' => Draft::mediaId(), 'kind' => 'video', 'url' => $url, 'style' => 'historia', 'platforms' => [], 'scene' => $this->index + 1];
                $upd['media'] = $media;
            }
            if ($upd !== []) {
                $d->update($upd);
            }
        });
    }

    public function failed(\Throwable $e): void
    {
        Log::warning('GenerateStoryClipJob falhou', ['draft' => $this->draftId, 'index' => $this->index, 'error' => $e->getMessage()]);
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
