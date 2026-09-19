<?php

namespace App\Jobs;

use App\Jobs\Concerns\RetriesTransientEngineErrors;
use App\Models\Draft;
use App\Models\GenModel;
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
 * Gera o clipe de UMA cena do canvas de Roteiro, FORA do browser.
 *
 * POR QUE EXISTE: o canvas renderizava o caminho com um `await` por cena, na aba. Um filme de
 * 5 minutos são 30 a 48 clipes — 45 a 72 minutos com a janela aberta, e fechar (ou recarregar)
 * perdia o lote no meio. Foi o que aconteceu em 2026-07-24, na cena 3 de 6. Aqui a fila do
 * worker carrega o trabalho: a aba vira um espectador que pode ir embora e voltar.
 *
 * O estado mora no Draft (`film.beats[i]`), o mesmo lugar que o filme contínuo já usava — então
 * a galeria, a montagem e o histórico funcionam sem nada novo.
 *
 * Irmão do GenerateFilmClipJob: aquele é do filme com keyframes (/v1/filmclip, primeiro E último
 * quadro); este é da cena avulsa do canvas (/v1/video, i2v comum com âncoras opcionais).
 */
class RenderSceneClipJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RetriesTransientEngineErrors, SerializesModels;

    // O i2v leva minutos e o engine ainda faz o poll do provedor; a fluidez soma o pós-processo.
    public int $timeout = 900;

    // Retry só vale a pena em falha transitória (o trait separa 4xx de instabilidade): a cena tem
    // âncora e prompt fixos, então re-gerar é seguro e o attach é dedup por URL.
    public int $tries = 3;

    public function __construct(
        public int $draftId,
        public int $index,      // índice da cena no caminho
        public array $payload,  // body do /v1/video (prompt, imageUrls, aspect, duration, gen_lines…)
        // Motor do lote (slug do catálogo). Vai pro item da galeria como ficha técnica: sem ele
        // o clipe do roteiro entrava no acervo sem motor nenhum — ao contrário do clipe avulso —
        // e não dava pra saber depois qual modelo produziu cada cena do filme.
        public ?string $modelSlug = null,
        // Cota RESERVADA no controller (tryConsume) antes de enfileirar; estornada em falha
        // definitiva (mesmo padrão de GenerateFilmClipJob).
        public ?int $tenantId = null,
        public ?int $costCredits = null,
    ) {}

    public function handle(): void
    {
        $res = EngineClient::make(840)->post('/v1/video', $this->payload);
        $url = $this->engineUrlOrRetry($res, 'RenderSceneClipJob', ['draft' => $this->draftId, 'index' => $this->index]);
        if ($url === '') {
            $this->refund();

            return; // o trait já decidiu entre retentar e desistir
        }

        DB::transaction(function () use ($url) {
            $d = Draft::lockForUpdate()->find($this->draftId);
            if (! $d) {
                return;
            }
            $film = is_array($d->film) ? $d->film : [];
            $beats = array_values((array) ($film['beats'] ?? []));
            if (! isset($beats[$this->index])) {
                return;
            }
            $beats[$this->index]['clip_url'] = $url;
            if ($this->modelSlug) {
                $beats[$this->index]['model_slug'] = $this->modelSlug; // qual motor fez ESTA cena
            }
            $film['beats'] = $beats;
            $upd = ['film' => $film];

            // Entra na galeria como peça de cena (scene = N): é intermediário do filme, não o
            // vídeo final publicável — o mesmo critério do filme contínuo.
            $media = $d->media ?? [];
            if (! collect($media)->contains(fn ($m) => ($m['url'] ?? null) === $url)) {
                $media[] = array_merge(
                    ['id' => Draft::mediaId(), 'kind' => 'video', 'url' => $url, 'style' => 'roteiro', 'platforms' => [], 'scene' => $this->index + 1],
                    Draft::modelStamp($this->modelSlug ? GenModel::where('slug', $this->modelSlug)->first() : null),
                    Draft::probeMeta($url),
                );
                $upd['media'] = $media;
            }
            $d->update($upd);
        });
    }

    public function failed(\Throwable $e): void
    {
        // A cena fica sem clip_url e o front a mostra como pendente — repetir o lote refaz só ela.
        Log::warning('RenderSceneClipJob falhou', ['draft' => $this->draftId, 'index' => $this->index, 'error' => $e->getMessage()]);
        $this->refund();
    }

    private function refund(): void
    {
        if (! $this->tenantId || ! $this->costCredits) {
            return; // sem cota reservada (chamada antiga sem tenantId/cost) — nada a estornar
        }
        if ($t = Tenant::find($this->tenantId)) {
            app(UsageService::class)->refund($t, 'video', 1, $this->costCredits);
        }
    }
}
