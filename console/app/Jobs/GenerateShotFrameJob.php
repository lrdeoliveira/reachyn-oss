<?php

namespace App\Jobs;

use App\Models\Shot;
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
 * QUADRO DO PLANO assíncrono (Fase 2.1 do docs/ESTUDIO-3D.md): /v1/image com o motor local
 * de pose — a âncora de ângulo (imageUrls[0]) vira contorno e a geração OBEDECE a composição.
 *
 * Espelha o GenerateElementJob: ~104s medidos por quadro no M5, então o worker grava a URL e
 * o front faz polling dos shots. Cota reservada no controller e estornada aqui na falha —
 * o cliente nunca paga por quadro que não recebeu.
 */
class GenerateShotFrameJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 720;   // > 660 do HTTP no Estúdio Local (que espera os 600s do ComfyUI)

    public int $tries = 1;

    public function __construct(
        public int $shotId,
        public int $tenantId,
        public array $payload,   // body do /v1/image (motor comfy/sdxl-pose)
        public int $weight,
        public ?int $costCredits = null,
    ) {}

    public function handle(UsageService $usage): void
    {
        $s = Shot::find($this->shotId);
        if (! $s) {
            $this->refund($usage);

            return;
        }

        $res = EngineClient::paraGeracao($this->payload)->post('/v1/image', $this->payload);
        $url = $res->successful() ? (string) $res->json('url') : '';

        if ($url === '') {
            Log::warning('GenerateShotFrameJob: geração sem URL', [
                'shot' => $this->shotId, 'status' => $res->status(),
            ]);
            $this->refund($usage);
            $s->update(['quadro_status' => 'erro']);

            return;
        }

        $s->update(['quadro_url' => $url, 'quadro_status' => null]);
    }

    public function failed(\Throwable $e): void
    {
        Log::warning('GenerateShotFrameJob falhou', ['shot' => $this->shotId, 'error' => $e->getMessage()]);
        $this->refund(app(UsageService::class));
        Shot::find($this->shotId)?->update(['quadro_status' => 'erro']);
    }

    private function refund(UsageService $usage): void
    {
        if ($t = Tenant::find($this->tenantId)) {
            $usage->refund($t, 'image', $this->weight, $this->costCredits);
        }
    }
}
