<?php

namespace App\Jobs;

use App\Models\AnimationProject;
use App\Models\Tenant;
use App\Services\AnimationFlow;
use App\Services\UsageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 🕺 MOTION TRANSFER de UMA cena (RunningHub, workflow ComfyUI curado). Transfere o gesto/câmera de
 * um vídeo-guia pro personagem do keyframe da cena. Assíncrono porque é LENTO (~8 min) e CARO (~96
 * coins) — o engine roda o workflow (submit→poll→persist no S3) e devolve a URL; aqui a gente só
 * seta o video_url da cena e estorna se falhar.
 *
 * ⚠️ tries=1 DE PROPÓSITO: cada tentativa custa ~96 coins na conta RunningHub. Auto-retry queimaria
 * dinheiro num loop — motion falhou = estorna o crédito e marca erro, sem retentar.
 */
class MotionTransferJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1290; // < worker-anim --timeout=1300; a chamada ao engine espera o poll (~13min)

    public int $tries = 1;      // NUNCA auto-retentar: cada run custa ~96 coins

    public function __construct(
        public int $projectId,
        public int $tenantId,
        public int $index,
        public string $workflowId,
        public array $nodeInfoList, // [{nodeId, fieldName, fieldValue(URL do S3)}]
        public string $instanceType,
        public int $weight,
        public ?int $costCredits = null,
    ) {
        $this->onQueue('animation');
    }

    public function handle(UsageService $usage): void
    {
        $p = AnimationProject::find($this->projectId);
        if (! $p) {
            $this->refund($usage);

            return;
        }
        $flow = app(AnimationFlow::class);

        // Chamada SÍNCRONA ao engine: ele submete o workflow e faz o poll até terminar (~8min), então
        // re-hospeda o mp4 no nosso S3 (a URL da RunningHub expira em 24h). Timeout > budget de poll do engine.
        $res = Http::baseUrl(rtrim((string) config('services.engine.url'), '/'))
            ->withHeaders(['X-Admin-Token' => (string) config('services.engine.admin_token')])
            ->acceptJson()
            ->timeout(840)
            ->post('/v1/motiontransfer', [
                'workflowId' => $this->workflowId,
                'nodeInfoList' => $this->nodeInfoList,
                'instanceType' => $this->instanceType,
            ]);

        $url = $res->successful() ? (string) $res->json('url') : '';
        if ($url === '') {
            Log::warning('MotionTransferJob: sem URL do engine', ['project' => $this->projectId, 'i' => $this->index, 'status' => $res->status()]);
            $this->refund($usage);
            $flow->patchScene($p, $this->index, ['video_status' => 'error']);
            $flow->advance($p);

            return;
        }

        $flow->patchScene($p, $this->index, ['video_url' => $url, 'video_status' => 'ready']);
        $flow->advance($p);
    }

    public function failed(\Throwable $e): void
    {
        Log::warning('MotionTransferJob falhou', ['project' => $this->projectId, 'error' => $e->getMessage()]);
        $this->refund(app(UsageService::class));
        $p = AnimationProject::find($this->projectId);
        if ($p) {
            $flow = app(AnimationFlow::class);
            $flow->patchScene($p, $this->index, ['video_status' => 'error']);
            $flow->advance($p);
        }
    }

    private function refund(UsageService $usage): void
    {
        $t = Tenant::find($this->tenantId);
        if ($t) {
            $usage->refund($t, 'video', $this->weight, $this->costCredits);
        }
    }
}
