<?php

namespace App\Jobs;

use App\Http\Controllers\Api\StudioController;
use App\Jobs\Concerns\RetriesTransientEngineErrors;
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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Gera a imagem AVULSA do Estúdio de forma ASSÍNCRONA, para os motores lentos demais para o
 * caminho síncrono.
 *
 * POR QUE EXISTE (2026-07-20): o motor `cursor` do CLI bridge leva 110-145s por imagem — é um
 * agente rodando um loop de tools, não uma CLI de mídia direta. O app.reachyn.agency está atrás
 * do CLOUDFLARE, que corta a requisição em ~100s com **HTTP 524**. Ou seja: a imagem era gerada
 * e salva no S3 com sucesso, mas o navegador recebia erro — o usuário esperava 100s pra ver
 * falha e depois achava a imagem "aparecida" na galeria. Nossa cadeia interna de timeouts
 * (console 360 > engine 300 > bridge 280) está correta; quem corta é a borda, que não
 * controlamos. A saída é não segurar a requisição: enfileira, devolve o draftId na hora e o
 * front faz polling — exatamente como vídeo, GIF e música já fazem.
 *
 * QUEM CAI AQUI: qualquer GenModel com `capabilities.async = true` (hoje só o img-cli-cursor).
 * É data-driven de propósito — motor novo e lento entra pelo catálogo, sem tocar em código.
 *
 * COTA: reservada no controller (reserve-then-consume) e ESTORNADA aqui se a geração falhar.
 * Molde: GenerateStoryImageJob (append sob lock + dedup) + o trait de retry do GenerateVideoJob.
 * ⚠️ NÃO copiar o append do GenerateVideoJob: aquele é read-modify-write sem lock e perde item
 * quando dois jobs terminam juntos no mesmo draft — risco real aqui, onde o paralelismo é alto.
 */
class GenerateImageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RetriesTransientEngineErrors, SerializesModels;

    // Teto acima do pior caso medido do cursor (145s) com folga para fila do bridge e persistência.
    public int $timeout = 420;

    public int $tries = 3;

    /**
     * @param  array<string,mixed>  $payload  body do /v1/image (prompt + aspect + style + provider/model…)
     * @param  array<int,string>  $platforms  redes a que a mídia se destina ([] = todas)
     */
    public function __construct(
        public int $draftId,
        public int $tenantId,
        public array $payload,
        public string $style,
        public int $weight,
        public array $platforms = [],
        public ?int $costCredits = null,
        public ?int $storyIndex = null, // cena da história: marca `scene` e grava scenes[i].image_url
        public ?string $modelName = null, // display_name do motor — vai pro item pra galeria mostrar
        public string $grade = 'natural', // 🎨 cor (color grade) embutida, igual ao caminho síncrono
        public int $gradeStrength = 0,
    ) {}

    public function handle(UsageService $usage): void
    {
        $d = Draft::find($this->draftId);
        if (! $d) {
            $this->refund($usage);

            return;
        }

        $res = EngineClient::make(360) // > 300 do engine > 280 do bridge: quem corta é quem sabe o motivo
            ->post('/v1/image', $this->payload);

        $url = $this->engineUrlOrRetry($res, 'GenerateImageJob', ['draft' => $this->draftId]);
        if ($url === '') {
            $this->refund($usage);

            return;
        }

        // 🎨 Cor embutida — mesmo passo do caminho síncrono, pra motor lento não perder o grade.
        $url = StudioController::gradeGeneratedImage($url, $this->grade, $this->gradeStrength);

        // Mesma semântica do attachImageLocked do caminho síncrono: append sob LOCK (várias
        // imagens podem terminar ao mesmo tempo no mesmo draft) + dedup por URL, que é o que
        // torna um re-run do trait de retry inofensivo.
        DB::transaction(function () use ($url) {
            $d = Draft::lockForUpdate()->find($this->draftId);
            if (! $d) {
                return;
            }
            $media = $d->media ?? [];
            if (collect($media)->contains(fn ($m) => ($m['url'] ?? null) === $url)) {
                return; // já anexada por uma tentativa anterior
            }
            $item = array_merge([
                'id' => Draft::mediaId(),
                'kind' => 'image',
                'url' => $url,
                'style' => $this->style,
                'platforms' => array_values($this->platforms),
            ], $this->modelName !== null ? ['model' => $this->modelName] : [], Draft::imageMeta($url));
            if ($this->storyIndex !== null) {
                $item['scene'] = $this->storyIndex + 1; // intermediária: não vaza pra Aprovar/Publicar
            }
            $media[] = $item;
            $upd = ['media' => $media];
            if ($this->storyIndex !== null) {
                $story = is_array($d->story) ? $d->story : [];
                $scenes = $story['scenes'] ?? [];
                if (isset($scenes[$this->storyIndex])) {
                    $scenes[$this->storyIndex]['image_url'] = $url;
                    $story['scenes'] = $scenes;
                    $upd['story'] = $story;
                }
            }
            $d->update($upd);
        });
    }

    public function failed(\Throwable $e): void
    {
        Log::warning('GenerateImageJob falhou', ['draft' => $this->draftId, 'error' => $e->getMessage()]);
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
