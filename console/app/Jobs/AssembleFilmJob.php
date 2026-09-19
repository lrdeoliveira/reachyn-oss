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
 * Monta o filme FORA da requisição web.
 *
 * POR QUE EXISTE: a montagem custa ~1,25× a duração final (medido: 20 trechos = 2min06 de filme
 * em 2min38). Um filme de 5 minutos leva ~6min15 — e o nginx corta a requisição em 600s, o php
 * em 700s. Com narração e legenda por cima, estoura. Na fila não há esse teto: o worker leva o
 * tempo que precisar e o canvas descobre pelo status.
 *
 * Grava em `film.final_url` do Draft (mesmo lugar do resto do filme) e anexa à galeria como
 * vídeo final — sem `scene`, porque este É o vídeo publicável, não uma peça intermediária.
 */
class AssembleFilmJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RetriesTransientEngineErrors, SerializesModels;

    // Folgado de propósito: 5 min de filme com narração e legenda passam de 6 minutos de CPU.
    public int $timeout = 1800;

    // Montagem é cara: uma retentativa cobre instabilidade de rede sem refazer trabalho pesado
    // várias vezes à toa.
    public int $tries = 2;

    public function __construct(
        public int $draftId,
        public array $payload, // body do /v1/filmassemble
        // Cota RESERVADA no controller (tryConsume 'short' 1 + 'effect' se narração/legenda)
        // antes de enfileirar; estornada em falha definitiva. Mesmo bucket que
        // FilmController::assemble já usa pra montagem — este é só outro caminho até o mesmo job.
        public ?int $tenantId = null,
        public int $effectCost = 0,
    ) {}

    public function handle(): void
    {
        // Marca o início pro front distinguir "montando" de "nunca montou".
        $this->marcar(['montando' => true]);

        $res = EngineClient::make(1740)->post('/v1/filmassemble', $this->payload);
        $url = $this->engineUrlOrRetry($res, 'AssembleFilmJob', ['draft' => $this->draftId]);
        if ($url === '') {
            $this->marcar(['montando' => false]);
            $this->refund();

            return;
        }

        DB::transaction(function () use ($url) {
            $d = Draft::lockForUpdate()->find($this->draftId);
            if (! $d) {
                return;
            }
            $film = is_array($d->film) ? $d->film : [];
            $film['final_url'] = $url;
            $film['montando'] = false;
            $upd = ['film' => $film];

            $media = $d->media ?? [];
            if (! collect($media)->contains(fn ($m) => ($m['url'] ?? null) === $url)) {
                // Sem `scene`: este é o filme, não um trecho.
                $media[] = array_merge(
                    ['id' => Draft::mediaId(), 'kind' => 'video', 'url' => $url, 'style' => 'filme', 'platforms' => []],
                    Draft::probeMeta($url),
                );
                $upd['media'] = $media;
            }
            $d->update($upd);
        });
    }

    public function failed(\Throwable $e): void
    {
        Log::warning('AssembleFilmJob falhou', ['draft' => $this->draftId, 'error' => $e->getMessage()]);
        $this->marcar(['montando' => false]);
        $this->refund();
    }

    private function refund(): void
    {
        if (! $this->tenantId) {
            return; // sem cota reservada (chamada antiga sem tenantId) — nada a estornar
        }
        if ($t = Tenant::find($this->tenantId)) {
            $usage = app(UsageService::class);
            $usage->refund($t, 'short', 1);
            if ($this->effectCost > 0) {
                $usage->refund($t, 'effect', $this->effectCost);
            }
        }
    }

    /** Atualiza chaves do `film` sem tocar no resto (os trechos continuam onde estão). */
    private function marcar(array $campos): void
    {
        DB::transaction(function () use ($campos) {
            $d = Draft::lockForUpdate()->find($this->draftId);
            if (! $d) {
                return;
            }
            $d->update(['film' => array_merge(is_array($d->film) ? $d->film : [], $campos)]);
        });
    }
}
