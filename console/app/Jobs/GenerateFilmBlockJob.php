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
 * 🎬 FILME EM BLOCOS: gera UM bloco (até 3 cortes numa única geração multi_shots via
 * /v1/filmquick) e grava em film.blocks[index].url. O bloco é coeso por construção (uma
 * geração = um mundo — sem drift de identidade entre os cortes internos); a montagem
 * emenda os blocos. Cota reservada no controller; estornada em falha.
 */
class GenerateFilmBlockJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RetriesTransientEngineErrors, SerializesModels;

    public int $timeout = 900;

    // Retry idempotente: grava por índice (overwrite) — re-gerar o mesmo bloco é seguro.
    public int $tries = 3;

    public function __construct(
        public int $draftId,
        public int $tenantId,
        public int $block,     // índice em film.blocks
        public array $payload, // body do /v1/filmquick (shots do bloco + abertura)
        public ?int $costCredits = null,
    ) {}

    public function handle(UsageService $usage): void
    {
        $res = EngineClient::make(840)
            ->post('/v1/filmquick', $this->payload);

        $url = $this->engineUrlOrRetry($res, 'GenerateFilmBlockJob', ['draft' => $this->draftId, 'block' => $this->block]);
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
            $blocks = array_values((array) ($film['blocks'] ?? []));
            if (! array_key_exists($this->block, $blocks)) {
                return; // blocos foram reparticionados (replanejar) — não grava em slot fantasma
            }
            $blocks[$this->block]['url'] = $url;
            $film['blocks'] = $blocks;
            $d->update(['film' => $film]);
        });
    }

    public function failed(\Throwable $e): void
    {
        Log::warning('GenerateFilmBlockJob falhou', ['draft' => $this->draftId, 'block' => $this->block, 'error' => $e->getMessage()]);
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
