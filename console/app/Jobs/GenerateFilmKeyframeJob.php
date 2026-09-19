<?php

namespace App\Jobs;

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
 * Gera UM KEYFRAME do filme contínuo (imagem estática K_i) de forma ASSÍNCRONA via /v1/image
 * (t2i ou i2i com refs [ref-mestre, K_{i-1}] — re-ancora a identidade e dá continuidade
 * espacial). Grava em film.keyframes[index] + galeria (kind=image, scene=index+1 →
 * intermediária, não publicável). Cota reservada no controller; estornada aqui em falha.
 */
class GenerateFilmKeyframeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 420;

    public int $tries = 1;

    public function __construct(
        public int $draftId,
        public int $tenantId,
        public int $index,     // índice do keyframe (0..N)
        public array $payload, // body do /v1/image
        public int $weight,
        public ?int $costCredits = null,
    ) {}

    public function handle(UsageService $usage): void
    {
        $res = EngineClient::make(360)
            ->post('/v1/image', $this->payload);

        $url = $res->successful() ? (string) $res->json('url') : '';
        if ($url === '') {
            Log::warning('GenerateFilmKeyframeJob: geração sem URL', ['draft' => $this->draftId, 'index' => $this->index, 'status' => $res->status()]);
            $this->refund($usage);

            return;
        }

        DB::transaction(function () use ($url) {
            $d = Draft::lockForUpdate()->find($this->draftId);
            if (! $d) {
                return;
            }
            $film = is_array($d->film) ? $d->film : [];
            $kf = array_values((array) ($film['keyframes'] ?? []));
            if (! array_key_exists($this->index, $kf)) {
                return;
            }
            $kf[$this->index] = $url;
            $film['keyframes'] = $kf;
            $upd = ['film' => $film];
            $media = $d->media ?? [];
            if (! collect($media)->contains(fn ($m) => ($m['url'] ?? null) === $url)) {
                $media[] = ['id' => Draft::mediaId(), 'kind' => 'image', 'url' => $url, 'style' => 'keyframe', 'platforms' => [], 'scene' => $this->index + 1];
                $upd['media'] = $media;
            }
            $d->update($upd);
        });
    }

    public function failed(\Throwable $e): void
    {
        Log::warning('GenerateFilmKeyframeJob falhou', ['draft' => $this->draftId, 'index' => $this->index, 'error' => $e->getMessage()]);
        $this->refund(app(UsageService::class));
    }

    private function refund(UsageService $usage): void
    {
        $t = Tenant::find($this->tenantId);
        if ($t) {
            $usage->refund($t, 'image', $this->weight, $this->costCredits);
        }
    }
}
