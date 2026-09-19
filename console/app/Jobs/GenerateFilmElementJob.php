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
 * 🎬 Filme — FASE 1 (Elementos): gera a REFERÊNCIA visual de UM elemento do filme (o carro, o
 * produto, o cenário) via /v1/image (t2i). A ref fica TRAVADA em film.elements[i].ref_url e — o
 * pulo do gato — vira um dos master_refs do filme, que o board E os keyframes já consomem como
 * âncora i2i. Assim tudo passa a mostrar o MESMO carro (fim do "board e keyframe = carros
 * diferentes"). Cota 'image' reservada no dispatch; estornada aqui se falhar.
 */
class GenerateFilmElementJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RetriesTransientEngineErrors, SerializesModels;

    public int $timeout = 420;

    public int $tries = 3;

    public function __construct(
        public int $draftId,
        public int $tenantId,
        public int $index,
        public array $payload, // body do /v1/image
        public int $weight,
        public ?int $costCredits = null,
    ) {}

    public function handle(UsageService $usage): void
    {
        $res = EngineClient::make(360)
            ->post('/v1/image', $this->payload);

        $url = $this->engineUrlOrRetry($res, 'GenerateFilmElementJob', ['draft' => $this->draftId, 'i' => $this->index]);
        if ($url === '') {
            $this->refund($usage);
            $this->patch(['status' => 'error']);

            return;
        }
        $this->patch(['ref_url' => $url, 'status' => 'ready']);
    }

    /**
     * Read-modify-write do elemento sob lock (vários elementos geram em paralelo) e RECONSTRÓI os
     * master_refs a partir de todos os elementos com ref travada (cap 3 = limite do i2i). É por aqui
     * que a identidade chega no board() e no keyframe() (ambos leem film.master_refs).
     */
    private function patch(array $patch): void
    {
        DB::transaction(function () use ($patch) {
            $d = Draft::lockForUpdate()->find($this->draftId);
            if (! $d) {
                return;
            }
            $film = is_array($d->film) ? $d->film : [];
            $els = array_values((array) ($film['elements'] ?? []));
            if (! isset($els[$this->index])) {
                return;
            }
            $els[$this->index] = array_merge((array) $els[$this->index], $patch);
            $film['elements'] = $els;

            $refs = [];
            foreach ($els as $e) {
                $u = trim((string) ($e['ref_url'] ?? ''));
                if ($u !== '') {
                    $refs[] = $u;
                }
            }
            $refs = array_slice(array_values(array_unique($refs)), 0, 3);
            $film['master_refs'] = $refs;
            $film['master_ref'] = $refs[0] ?? '';
            $d->update(['film' => $film]);
        });
    }

    public function failed(\Throwable $e): void
    {
        Log::warning('GenerateFilmElementJob falhou', ['draft' => $this->draftId, 'index' => $this->index, 'error' => $e->getMessage()]);
        $this->refund(app(UsageService::class));
        $this->patch(['status' => 'error']);
    }

    private function refund(UsageService $usage): void
    {
        $t = Tenant::find($this->tenantId);
        if ($t) {
            $usage->refund($t, 'image', $this->weight, $this->costCredits);
        }
    }
}
