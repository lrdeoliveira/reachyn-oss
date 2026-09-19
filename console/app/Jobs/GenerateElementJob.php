<?php

namespace App\Jobs;

use App\Models\Element;
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
 * Geração ASSÍNCRONA da imagem-âncora de um ELEMENTO do catálogo (t2i via /v1/image).
 *
 * Espelha o GenerateCharacterJob task 'base': a geração leva minutos e estouraria o timeout do
 * proxy no request, então o worker grava a URL e o front faz polling de /api/scenarios.
 *
 * Cota 'image' reservada no controller (reserve-then-consume) e ESTORNADA aqui na falha — mesma
 * regra do personagem, para o cliente nunca pagar por imagem que não recebeu.
 */
class GenerateElementJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 720;   // > 660 do HTTP no Estúdio Local (que espera os 600s do ComfyUI)

    public int $tries = 1;

    public function __construct(
        public int $elementId,
        public int $tenantId,
        public array $payload,   // body do /v1/image
        public int $weight,
        public ?int $costCredits = null,
    ) {}

    public function handle(UsageService $usage): void
    {
        $s = Element::find($this->elementId);
        if (! $s) {
            $this->refund($usage);

            return;
        }

        $res = EngineClient::paraGeracao($this->payload)->post('/v1/image', $this->payload);
        $url = $res->successful() ? (string) $res->json('url') : '';

        if ($url === '') {
            Log::warning('GenerateElementJob: geração sem URL', [
                'element' => $this->elementId, 'status' => $res->status(),
            ]);
            $this->refund($usage);
            $s->update(['status' => 'error']);

            return;
        }

        $s->update(['image_url' => $url, 'status' => '']);
    }

    public function failed(\Throwable $e): void
    {
        Log::warning('GenerateElementJob falhou', ['element' => $this->elementId, 'error' => $e->getMessage()]);
        $this->refund(app(UsageService::class));
        Element::find($this->elementId)?->update(['status' => 'error']);
    }

    private function refund(UsageService $usage): void
    {
        if ($t = Tenant::find($this->tenantId)) {
            $usage->refund($t, 'image', $this->weight, $this->costCredits);
        }
    }
}
