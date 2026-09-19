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
 * Gera UM TRECHO do filme contínuo (i2v com primeiro E último frame — keyframes K_i → K_{i+1})
 * de forma ASSÍNCRONA via /v1/filmclip. Grava em film.beats[index].clip_url + galeria
 * (kind=video, scene=index+1 → intermediário, não publicável). Como os keyframes são âncoras
 * fixas, vários trechos geram EM PARALELO. Cota reservada no controller; estornada em falha.
 */
class GenerateFilmClipJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RetriesTransientEngineErrors, SerializesModels;

    public int $timeout = 900; // i2v Kling FullHD leva minutos; o poll do engine cobre ~11min

    // Retry idempotente: keyframes são âncoras fixas e o attach é dedup por URL, então re-gerar um
    // trecho que blipou é seguro. Transitório retenta; 4xx (param inválido) desiste na hora.
    public int $tries = 3;

    public function __construct(
        public int $draftId,
        public int $tenantId,
        public int $index,     // índice do trecho (beat) do filme
        public array $payload, // body do /v1/filmclip
        public ?int $costCredits = null,
    ) {}

    public function handle(UsageService $usage): void
    {
        $res = EngineClient::make(840)
            ->post('/v1/filmclip', $this->payload);

        $url = $this->engineUrlOrRetry($res, 'GenerateFilmClipJob', ['draft' => $this->draftId, 'index' => $this->index]);
        if ($url === '') {
            $this->refund($usage);

            return;
        }

        DB::transaction(function () use ($url) {
            $d = Draft::lockForUpdate()->find($this->draftId);
            if (! $d) {
                return;
            }
            $film = is_array($d->film) ? $d->film : [];
            $beats = array_values((array) ($film['beats'] ?? []));
            if (! isset($beats[$this->index])) {
                return;
            }
            $beats[$this->index]['clip_url'] = $url;
            $film['beats'] = $beats;
            $upd = ['film' => $film];
            $media = $d->media ?? [];
            if (! collect($media)->contains(fn ($m) => ($m['url'] ?? null) === $url)) {
                $media[] = ['id' => Draft::mediaId(), 'kind' => 'video', 'url' => $url, 'style' => 'filme', 'platforms' => [], 'scene' => $this->index + 1];
                $upd['media'] = $media;
            }
            $d->update($upd);
        });
    }

    public function failed(\Throwable $e): void
    {
        Log::warning('GenerateFilmClipJob falhou', ['draft' => $this->draftId, 'index' => $this->index, 'error' => $e->getMessage()]);
        $this->refund(app(UsageService::class));
    }

    private function refund(UsageService $usage): void
    {
        $t = Tenant::find($this->tenantId);
        if ($t) {
            $usage->refund($t, 'video', 1, $this->costCredits);
        }
    }
}
