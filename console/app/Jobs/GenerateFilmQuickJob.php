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
 * ⚡ FILME RÁPIDO (Sprint D): gera o filme inteiro numa ÚNICA geração (Kling multi_shots) via
 * /v1/filmquick — ASSÍNCRONO (minutos). Grava em film.quick_clip_url (o "Montar o filme" usa
 * este clipe único no lugar da concatenação de beats[].clip_url, quando presente). Cota
 * reservada no controller; estornada em falha.
 */
class GenerateFilmQuickJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RetriesTransientEngineErrors, SerializesModels;

    public int $timeout = 900;

    // Retry idempotente: grava em quick_clip_url (overwrite), então re-gerar é seguro.
    public int $tries = 3;

    public function __construct(
        public int $draftId,
        public int $tenantId,
        public array $payload, // body do /v1/filmquick
        public ?int $costCredits = null,
    ) {}

    public function handle(UsageService $usage): void
    {
        $res = EngineClient::make(840)
            ->post('/v1/filmquick', $this->payload);

        $url = $this->engineUrlOrRetry($res, 'GenerateFilmQuickJob', ['draft' => $this->draftId]);
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
            $film['quick_clip_url'] = $url;
            $d->update(['film' => $film]);
        });
    }

    public function failed(\Throwable $e): void
    {
        Log::warning('GenerateFilmQuickJob falhou', ['draft' => $this->draftId, 'error' => $e->getMessage()]);
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
