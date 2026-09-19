<?php

namespace App\Jobs;

use App\Http\Controllers\Api\StudioController;
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
 * Gera o PLANO DE FILMAGEM do filme contínuo (plano-sequência) de forma ASSÍNCRONA: o engine
 * (/v1/filmplan) devolve N beats {title, frame_prompt, move_prompt} + o keyframe final, com o
 * contrato de continuidade (um take só). Grava em draft.film (status generating→ready|error);
 * o front faz polling. Sem cota de mídia (texto) — gate de assinatura no controller.
 */
class GenerateFilmPlanJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300; // o plano usa reasoning model (~min); o engine capa em 200s

    public int $tries = 1;

    public function __construct(
        public int $draftId,
        public int $tenantId,
        public array $payload, // body do /v1/filmplan {brief, style, masterDesc, lang, clipDuration, beats, gen_lines?}
        public ?int $textCost = null, // custo do roteiro cobrado no controller (estornado se o plano falhar)
    ) {}

    public function handle(): void
    {
        $res = EngineClient::make(280)
            ->post('/v1/filmplan', $this->payload);

        $beats = $res->successful() ? (array) $res->json('beats') : [];
        $finalFrame = (string) ($res->json('final_frame_prompt') ?? '');
        // Roteiro técnico completo: título do filme + direção musical (seções editáveis do plano).
        $title = mb_substr(trim((string) ($res->json('title') ?? '')), 0, 160);
        $music = mb_substr(trim((string) ($res->json('music_prompt') ?? '')), 0, 400);
        // Observabilidade: sem beats, registra a CAUSA real do engine (status + corpo) — o job só
        // gravava uma mensagem genérica, cegando o diagnóstico (ex.: timeout do provider de texto).
        if ($beats === []) {
            // Estorna o roteiro cobrado no controller (falha nossa não cobra).
            if ($t = Tenant::find($this->tenantId)) {
                app(UsageService::class)->refund($t, 'text', 1, $this->textCost);
            }
            Log::warning('GenerateFilmPlanJob: plano sem beats', [
                'draft' => $this->draftId,
                'http' => $res->status(),
                'body' => mb_substr((string) $res->body(), 0, 500),
            ]);
        }
        $this->write(function (array $film) use ($beats, $finalFrame, $title, $music) {
            if ($beats === []) {
                $film['status'] = 'error';
                $film['error'] = 'o plano de filmagem falhou — tente de novo';

                return $film;
            }
            $film['beats'] = array_map(fn ($b) => [
                'title' => (string) ($b['title'] ?? ''),
                'frame_prompt' => (string) ($b['frame_prompt'] ?? ''),
                'move_prompt' => (string) ($b['move_prompt'] ?? ''),
                'voiceover' => (string) ($b['voiceover'] ?? ''), // locução do trecho (narração opcional na montagem)
                // FICHA DE CENA (S1) do plano: antes era descartada aqui e só existia via edição manual.
                'spec' => StudioController::sanitizeSceneSpec($b['spec'] ?? null),
                'clip_url' => '',
            ], $beats);
            $film['final_frame_prompt'] = $finalFrame;
            $film['title'] = $title;        // título do filme (Roteiro técnico)
            $film['music_prompt'] = $music; // direção musical (a montagem usa como default da trilha)
            $film['keyframes'] = array_fill(0, count($beats) + 1, ''); // K0..KN vazios (gera depois)
            $film['final_url'] = '';
            $film['status'] = 'ready';
            unset($film['error']);

            return $film;
        });
    }

    public function failed(\Throwable $e): void
    {
        Log::warning('GenerateFilmPlanJob falhou', ['draft' => $this->draftId, 'error' => $e->getMessage()]);
        $this->write(function (array $film) {
            $film['status'] = 'error';
            $film['error'] = 'o plano de filmagem falhou — tente de novo';

            return $film;
        });
    }

    /** Read-modify-write de draft.film sob lock (o padrão dos jobs de história). */
    private function write(callable $mut): void
    {
        DB::transaction(function () use ($mut) {
            $d = Draft::lockForUpdate()->find($this->draftId);
            if (! $d) {
                return;
            }
            $film = is_array($d->film) ? $d->film : [];
            $d->update(['film' => $mut($film)]);
        });
    }
}
