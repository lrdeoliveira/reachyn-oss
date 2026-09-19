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
use Illuminate\Support\Facades\Log;

/**
 * Gera vídeo de forma ASSÍNCRONA (worker de fila). A geração leva minutos (Kling/Veo) e,
 * feita no request, estourava o timeout do proxy → o front recebia resposta cortada e
 * mostrava "undefined". Aqui o worker (timeout 1300s) chama o engine e anexa a mídia ao
 * rascunho; o Studio já faz polling da galeria até o vídeo aparecer. Cota: reservada no
 * controller (reserve-then-consume) e ESTORNADA aqui se a geração falhar.
 */
class GenerateVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RetriesTransientEngineErrors, SerializesModels;

    public int $timeout = 1300;

    // Retry idempotente: um blip/5xx transitório do provedor no meio de minutos de geração era
    // descartado (tries=1). Agora retenta com backoff; erro permanente (4xx) desiste na hora.
    public int $tries = 3;

    public function __construct(
        public int $draftId,
        public int $tenantId,
        public string $endpoint,   // '/v1/video' ou '/v1/veo'
        public array $payload,
        public string $usageKind,  // bucket de cota: 'video' ou 'veo'
        public int $weight,
        public string $style,
        public array $platforms = [],  // redes a que esta mídia se destina (vazio = todas)
        public ?int $costCredits = null, // custo em créditos do modelo escolhido (null = custo fixo por tipo)
        public string $mediaKind = 'video', // tipo do item na galeria: 'video' (default) | 'audio' (música)
        public int $effectCount = 0,        // F1: transições/efeitos cobrados no controller (estornados aqui em falha)
    ) {}

    public function handle(UsageService $usage): void
    {
        $d = Draft::find($this->draftId);
        if (! $d) {
            $this->refund($usage);

            return;
        }

        $res = EngineClient::make(1200)
            ->post($this->endpoint, $this->payload);

        $url = $this->engineUrlOrRetry($res, 'GenerateVideoJob', ['draft' => $this->draftId]);
        if ($url === '') {
            $this->refund($usage);

            return;
        }

        // Anexa a mídia à galeria do rascunho (mesmo formato do attach síncrono). mediaKind = 'video'
        // por padrão; 'audio' para música (a galeria renderiza com <audio controls>).
        $item = ['id' => Draft::mediaId(), 'kind' => $this->mediaKind, 'url' => $url, 'style' => $this->style, 'platforms' => array_values($this->platforms)];
        $d->update(['media' => array_merge($d->media ?? [], [$item])]);
    }

    public function failed(\Throwable $e): void
    {
        Log::warning('GenerateVideoJob falhou', ['draft' => $this->draftId, 'error' => $e->getMessage()]);
        $this->refund(app(UsageService::class));
    }

    private function refund(UsageService $usage): void
    {
        $t = Tenant::find($this->tenantId);
        if ($t) {
            $usage->refund($t, $this->usageKind, $this->weight, $this->costCredits);
            if ($this->effectCount > 0) {
                $usage->refund($t, 'effect', $this->effectCount); // transições cobradas junto da montagem
            }
        }
    }
}
