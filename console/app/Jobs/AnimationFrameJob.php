<?php

namespace App\Jobs;

use App\Models\AnimationProject;
use App\Models\Tenant;
use App\Services\AnimationFlow;
use App\Services\UsageService;
use App\Support\EngineClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 🎬 Estúdio de Animação — passo 3: gera o KEYFRAME de UMA cena via /v1/image com
 * multi-referência (personagens da cena + locação) + IDENTITY LOCK + Ficha de Cena +
 * paleta do projeto. Cota reservada no dispatch; estornada aqui se falhar.
 */
class AnimationFrameJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 420;

    public int $tries = 1;

    public function __construct(
        public int $projectId,
        public int $tenantId,
        public int $index,
        public array $payload, // body do /v1/image (multi-ref + spec + palette)
        public int $weight,
        public ?int $costCredits = null,
        public bool $chainNext = false, // 🔗 encadeado/plano: ao concluir, dispara o próximo keyframe (serial)
        public bool $isEnd = false, // 🔚 keyframe FINAL da cena (grava end_keyframe_*; fora do fluxo auto)
    ) {
        $this->onQueue('animation'); // ver AnimationSceneJob: fan-out isolado do resto da fila
    }

    public function handle(UsageService $usage): void
    {
        $p = AnimationProject::find($this->projectId);
        if (! $p) {
            $this->refund($usage);

            return;
        }
        $flow = app(AnimationFlow::class);
        $res = EngineClient::make(360)
            ->post('/v1/image', $this->payload);

        $url = $res->successful() ? (string) $res->json('url') : '';
        // 🔚 Keyframe FINAL: grava nos campos end_* e NÃO entra no fluxo automático (não encadeia,
        // não avança o pipeline elementos→storyboard→cenas→montagem — é um extra pra exportar).
        if ($this->isEnd) {
            if ($url === '') {
                Log::warning('AnimationFrameJob(end): geração sem URL', ['project' => $this->projectId, 'i' => $this->index, 'status' => $res->status()]);
                $this->refund($usage);
                $flow->patchScene($p, $this->index, ['end_keyframe_status' => 'error']);
            } else {
                $flow->patchScene($p, $this->index, ['end_keyframe_url' => $url, 'end_keyframe_status' => 'ready']);
            }

            return;
        }
        if ($url === '') {
            Log::warning('AnimationFrameJob: geração sem URL', ['project' => $this->projectId, 'i' => $this->index, 'status' => $res->status()]);
            $this->refund($usage);
            $flow->patchScene($p, $this->index, ['keyframe_status' => 'error']);
        } else {
            $flow->patchScene($p, $this->index, ['keyframe_url' => $url, 'keyframe_status' => 'ready']);
        }
        // 🔗 Encadeamento SERIAL dos keyframes (encadeado/plano): concluí o meu → disparo o próximo
        // pendente (usa o meu keyframe como âncora). Vale em manual OU auto; roda ANTES do advance()
        // pra o próximo já constar como 'generating' e o advance não redisparar em paralelo. Só na
        // geração bem-sucedida (falha vira 'error' e para a corrente — o usuário retoma na mão).
        if ($this->chainNext && $url !== '' && $p->refresh()->chainsFrames()) {
            if ($t = Tenant::find($this->tenantId)) {
                $flow->dispatchNextFrame($p, $t);
            }
        }
        $flow->advance($p);
    }

    public function failed(\Throwable $e): void
    {
        Log::warning('AnimationFrameJob falhou', ['project' => $this->projectId, 'error' => $e->getMessage()]);
        $this->refund(app(UsageService::class));
        $p = AnimationProject::find($this->projectId);
        if ($p) {
            $col = $this->isEnd ? 'end_keyframe_status' : 'keyframe_status';
            app(AnimationFlow::class)->patchScene($p, $this->index, [$col => 'error']);
        }
    }

    private function refund(UsageService $usage): void
    {
        $t = Tenant::find($this->tenantId);
        if ($t) {
            $usage->refund($t, 'image', $this->weight, $this->costCredits);
        }
    }
}
