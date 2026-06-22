<?php

namespace App\Jobs;

use App\Models\Draft;
use App\Models\Tenant;
use App\Services\UsageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Gera vídeo de forma ASSÍNCRONA (worker de fila). A geração leva minutos (vídeo/vídeo premium) e,
 * feita no request, estourava o timeout do proxy → o front recebia resposta cortada e
 * mostrava "undefined". Aqui o worker (timeout 1300s) chama o engine e anexa a mídia ao
 * rascunho; o Studio já faz polling da galeria até o vídeo aparecer. Cota: reservada no
 * controller (reserve-then-consume) e ESTORNADA aqui se a geração falhar.
 */
class GenerateVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1300;
    public int $tries = 1;

    public function __construct(
        public int $draftId,
        public int $tenantId,
        public string $endpoint,   // '/v1/video' ou '/v1/premium-video'
        public array $payload,
        public string $usageKind,  // bucket de cota: 'video' ou 'premium-video'
        public int $weight,
        public string $style,
    ) {}

    public function handle(UsageService $usage): void
    {
        $d = Draft::find($this->draftId);
        if (! $d) {
            $this->refund($usage);
            return;
        }

        $res = Http::baseUrl(rtrim((string) config('services.engine.url'), '/'))
            ->withHeaders(['X-Admin-Token' => (string) config('services.engine.admin_token')])
            ->acceptJson()->timeout(1200)
            ->post($this->endpoint, $this->payload);

        $url = $res->successful() ? (string) $res->json('url') : '';
        if ($url === '') {
            Log::warning('GenerateVideoJob: geração sem URL', ['draft' => $this->draftId, 'status' => $res->status()]);
            $this->refund($usage);
            return;
        }

        // Anexa o vídeo à galeria do rascunho (mesmo formato do attach síncrono).
        $item = ['id' => (string) (int) (microtime(true) * 1000), 'kind' => 'video', 'url' => $url, 'style' => $this->style];
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
            $usage->refund($t, $this->usageKind, $this->weight);
        }
    }
}
