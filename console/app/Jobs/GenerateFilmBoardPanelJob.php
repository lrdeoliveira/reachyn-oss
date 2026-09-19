<?php

namespace App\Jobs;

use App\Http\Controllers\Api\StudioController;
use App\Models\Draft;
use App\Models\Tenant;
use App\Services\UsageService;
use App\Support\BoardImage;
use App\Support\EngineClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 🩹 CONSERTA UM PAINEL do storyboard-sheet sem regenerar o resto: o controller já recortou a
 * célula e montou o i2i "mude SÓ isto"; aqui geramos o painel editado (/v1/image), baixamos o
 * board ATUAL e colamos o painel de volta na célula (BoardImage::pastePanel — os outros painéis
 * ficam byte a byte). Persiste o board novo em film.board_url + galeria (style=board,
 * intermediário). Cota reservada no controller; estornada aqui em falha. Espelha
 * GenerateFilmBoardJob (bucket image).
 */
class GenerateFilmBoardPanelJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 420;

    public int $tries = 1;

    public function __construct(
        public int $draftId,
        public int $tenantId,
        public int $index,   // painel 0-based
        public array $payload, // body do /v1/image (i2i da célula recortada)
        public int $weight,
        public ?int $costCredits = null,
    ) {}

    public function handle(UsageService $usage): void
    {
        $res = EngineClient::make(360)
            ->post('/v1/image', $this->payload);

        $editedUrl = $res->successful() ? (string) $res->json('url') : '';
        if ($editedUrl === '') {
            Log::warning('GenerateFilmBoardPanelJob: geração sem URL', ['draft' => $this->draftId, 'status' => $res->status()]);
            $this->refund($usage);

            return;
        }

        // Board ATUAL (relido agora — não o do momento do clique) + painel editado → cola.
        $d = Draft::find($this->draftId);
        $film = is_array($d?->film) ? $d->film : [];
        $board = trim((string) ($film['board_url'] ?? ''));
        $cols = (int) ($film['board_cols'] ?? 0);
        $rows = (int) ($film['board_rows'] ?? 0);
        $boardRaw = $board !== '' ? @file_get_contents($board) : false;
        $panelRaw = @file_get_contents($editedUrl);
        $bytes = ($boardRaw !== false && $panelRaw !== false)
            ? BoardImage::pastePanel($boardRaw, $panelRaw, $this->index, $cols, $rows)
            : null;
        if ($bytes === null) {
            Log::warning('GenerateFilmBoardPanelJob: falha ao colar o painel', ['draft' => $this->draftId, 'index' => $this->index]);
            $this->refund($usage);

            return;
        }
        $url = StudioController::storeMedia($bytes, 'jpg', 'image');

        DB::transaction(function () use ($url) {
            $d = Draft::lockForUpdate()->find($this->draftId);
            if (! $d) {
                return;
            }
            $film = is_array($d->film) ? $d->film : [];
            $film['board_url'] = $url;
            $upd = ['film' => $film];
            $media = $d->media ?? [];
            if (! collect($media)->contains(fn ($m) => ($m['url'] ?? null) === $url)) {
                // scene = -1: board é intermediário (não publicável) — mesmo padrão do BoardJob.
                $media[] = ['id' => Draft::mediaId(), 'kind' => 'image', 'url' => $url, 'style' => 'board', 'platforms' => [], 'scene' => -1];
                $upd['media'] = $media;
            }
            $d->update($upd);
        });
    }

    public function failed(\Throwable $e): void
    {
        Log::warning('GenerateFilmBoardPanelJob falhou', ['draft' => $this->draftId, 'error' => $e->getMessage()]);
        $this->refund(app(UsageService::class));
    }

    private function refund(UsageService $usage): void
    {
        $t = Tenant::find($this->tenantId);
        if ($t) {
            $usage->refund($t, 'image', $this->weight, $this->costCredits);
        }
    }
}
