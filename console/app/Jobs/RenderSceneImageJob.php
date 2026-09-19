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
 * Gera o QUADRO de uma cena do canvas FORA do processo web.
 *
 * POR QUE EXISTE: a imagem da cena era síncrona — cada clique segurava um worker do php-fpm por
 * 20 a 40 segundos. Onze gerações em três minutos (gerar o quadro de meia dúzia de cenas) prendiam
 * o pool inteiro, e TODO o app entrava na fila: a lista de personagens levou 14s e o cartão da
 * cena chegou a mostrar "#14" no lugar do nome, porque desenhava antes da biblioteca chegar
 * (2026-07-25). Fila resolve na raiz: o processo web só enfileira e responde.
 *
 * Irmão do RenderSceneClipJob, de propósito — mesma estrutura, mesmo lugar de gravação (o Draft),
 * mesmo dedup por URL. O canvas já pollava o rascunho pra ver clipe pronto; agora o mesmo polling
 * traz o quadro.
 */
class RenderSceneImageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RetriesTransientEngineErrors, SerializesModels;

    // Um t2i/i2i leva ~20-45s; o teto cobre modelo lento (nano-banana-pro em 2K) com folga.
    public int $timeout = 600;

    // A cena tem prompt e âncoras fixos: re-gerar é seguro, e o attach é dedup por URL.
    public int $tries = 3;

    public function __construct(
        public int $draftId,
        public int $index,        // índice da cena no caminho
        public array $payload,    // body do /v1/image (prompt, aspect, style, imageUrls, provider…)
        public ?string $modelSlug = null,
        // Cota RESERVADA no controller (tryConsume) antes de enfileirar; estornada em falha
        // definitiva (mesmo padrão de GenerateFilmClipJob/ShotController::quadro).
        public ?int $tenantId = null,
        public ?int $costCredits = null,
        public float $weight = 1,
    ) {}

    public function handle(): void
    {
        $res = EngineClient::paraGeracao($this->payload, 540)->post('/v1/image', $this->payload);
        $url = $this->engineUrlOrRetry($res, 'RenderSceneImageJob', ['draft' => $this->draftId, 'index' => $this->index]);
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
                $beats[$this->index] = [];
            }
            // `frame_url` é o quadro da cena — o mesmo que vira base i2v do clipe depois.
            $beats[$this->index]['frame_url'] = $url;
            $film['beats'] = $beats;
            $upd = ['film' => $film];

            // Entra na galeria como peça de cena (scene = N), igual ao clipe: é intermediário do
            // filme, não a peça publicável.
            $media = $d->media ?? [];
            if (! collect($media)->contains(fn ($m) => ($m['url'] ?? null) === $url)) {
                $media[] = array_merge(
                    ['id' => Draft::mediaId(), 'kind' => 'image', 'url' => $url, 'style' => 'roteiro', 'platforms' => [], 'scene' => $this->index + 1],
                    Draft::modelStamp($this->modelSlug ? GenModel::where('slug', $this->modelSlug)->first() : null),
                    Draft::imageMeta($url),
                );
                $upd['media'] = $media;
            }
            $d->update($upd);
        });
    }

    public function failed(\Throwable $e): void
    {
        // A cena fica sem frame_url e o canvas a mostra como pendente — repetir refaz só ela.
        Log::warning('RenderSceneImageJob falhou', ['draft' => $this->draftId, 'index' => $this->index, 'error' => $e->getMessage()]);
        $this->refund();
    }

    private function refund(): void
    {
        if (! $this->tenantId || ! $this->costCredits) {
            return; // sem cota reservada (chamada antiga sem tenantId/cost) — nada a estornar
        }
        if ($t = Tenant::find($this->tenantId)) {
            app(UsageService::class)->refund($t, 'image', $this->weight, $this->costCredits);
        }
    }
}
