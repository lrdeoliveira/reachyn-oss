<?php

namespace App\Jobs;

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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Gera o STORYBOARD-SHEET do filme: UMA única imagem multi-painel (grid, ex. 3x3) onde cada
 * painel é um beat da história, com a MESMA identidade em todos os quadros. Assíncrono via
 * /v1/image (i2i com as refs-mestre + anchorIdentity). Grava em film.board_url + galeria
 * (kind=image, style=board → intermediária). É a referência ÚNICA que o Filme rápido
 * (multi_shots) lê pra gerar a sequência inteira numa tacada. Cota reservada no controller;
 * estornada aqui em falha. Espelha GenerateFilmKeyframeJob (bucket image).
 */
class GenerateFilmBoardJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 420;

    public int $tries = 1;

    public function __construct(
        public int $draftId,
        public int $tenantId,
        public array $payload, // body do /v1/image (prompt de grid + refs)
        public int $weight,
        public ?int $costCredits = null,
    ) {}

    public function handle(UsageService $usage): void
    {
        $res = EngineClient::make(360)
            ->post('/v1/image', $this->payload);

        $url = $res->successful() ? (string) $res->json('url') : '';
        if ($url === '') {
            Log::warning('GenerateFilmBoardJob: geração sem URL', ['draft' => $this->draftId, 'status' => $res->status()]);
            $this->refund($usage);

            return;
        }

        // 📐 GRADE REAL: o modelo não obedece a grade pedida (caso real 2026-07-22 — pedimos 4x4
        // num filme 9:16 e veio 2x6). cols/rows gravados com o PEDIDO faziam todo o resto trabalhar
        // sobre uma mentira: o recorte cortava retângulos fora dos painéis, a malha de seleção da
        // tela caía no meio dos quadros (o "pontilhado atravessando a arte") e o conserto colava no
        // lugar errado. Medimos a imagem entregue e gravamos o que ELA tem.
        $bytes = (string) Http::timeout(30)->get($url)->body();
        $grade = BoardImage::detectGrid($bytes);
        // Guarda também a PROPORÇÃO da célula: é ela que o keyframe herda, e é o que permite a tela
        // avisar ANTES de o operador recortar (recortar painel deitado num filme 9:16 = faixa preta
        // no quadro, e depois no vídeo).
        $celula = $grade !== null ? BoardImage::cellAspect($bytes, $grade['cols'], $grade['rows']) : null;

        DB::transaction(function () use ($url, $grade, $celula) {
            $d = Draft::lockForUpdate()->find($this->draftId);
            if (! $d) {
                return;
            }
            $film = is_array($d->film) ? $d->film : [];
            $film['board_url'] = $url;
            if ($grade !== null) {
                $film['board_cols'] = $grade['cols'];
                $film['board_rows'] = $grade['rows'];
            }
            if ($celula !== null) {
                $film['board_cell'] = round($celula, 4);
            }
            $upd = ['film' => $film];
            $media = $d->media ?? [];
            if (! collect($media)->contains(fn ($m) => ($m['url'] ?? null) === $url)) {
                // scene = -1 (não 0): marca o board como INTERMEDIÁRIO (não publicável). Os filtros
                // de publicação usam `if (m.scene) return false` (truthy JS) — scene:0 vazaria como
                // publicável; -1 é truthy, != null (fora do input picker) e isset (limpo no clearMedia).
                $media[] = ['id' => Draft::mediaId(), 'kind' => 'image', 'url' => $url, 'style' => 'board', 'platforms' => [], 'scene' => -1];
                $upd['media'] = $media;
            }
            $d->update($upd);
        });
    }

    public function failed(\Throwable $e): void
    {
        Log::warning('GenerateFilmBoardJob falhou', ['draft' => $this->draftId, 'error' => $e->getMessage()]);
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
