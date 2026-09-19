<?php

namespace App\Jobs;

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
 * Gera a IMAGEM de UMA cena da história de forma ASSÍNCRONA. A imagem i2i (nano-banana, com
 * referências) leva ~2min; feita no request, estourava o timeout do proxy (HTTP 499 → a imagem
 * nem era anexada à cena). Aqui o worker chama o engine (/v1/image) e grava a URL em
 * story.scenes[index].image_url + anexa à galeria (kind=image, scene=index+1); o gerador de
 * Histórias faz polling do rascunho até a imagem aparecer. Cota: reservada no controller
 * (reserve-then-consume) e ESTORNADA aqui se a geração falhar. Espelha o GenerateStoryClipJob.
 */
class GenerateStoryImageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Teto curto: imagem i2i leva ~2min. Se o provedor travar, falha em ~6min e LIBERA o worker.
    public int $timeout = 420;

    public int $tries = 1;

    public function __construct(
        public int $draftId,
        public int $tenantId,
        public int $index,        // índice da cena dentro de story.scenes
        public array $payload,    // body do /v1/image (prompt + aspect + style + imageUrls?)
        public string $style,
        public int $weight,
        public ?int $refIndex = null, // null = grava a IMAGEM da cena (+galeria); k = edita scenes[i].refs[k] no lugar
        public ?int $costCredits = null, // custo em créditos do modelo usado (null = custo fixo por tipo)
    ) {}

    public function handle(UsageService $usage): void
    {
        $d = Draft::find($this->draftId);
        if (! $d) {
            $this->refund($usage);

            return;
        }

        $res = EngineClient::make(360) // i2i não deve passar disso; estoura → falha rápido + estorna
            ->post('/v1/image', $this->payload);

        $url = $res->successful() ? (string) $res->json('url') : '';
        if ($url === '') {
            Log::warning('GenerateStoryImageJob: geração sem URL', ['draft' => $this->draftId, 'index' => $this->index, 'status' => $res->status()]);
            $this->refund($usage);

            return;
        }

        // Read-modify-write da coluna JSON sob LOCK: várias cenas podem gerar em paralelo, então
        // recarregamos o rascunho travado para não sobrescrever o image_url de outra cena. No mesmo
        // passo, anexa a imagem à GALERIA (kind=image, scene=index+1 = intermediária, não publicável).
        DB::transaction(function () use ($url) {
            $d = Draft::lockForUpdate()->find($this->draftId);
            if (! $d) {
                return;
            }
            $upd = [];
            $story = is_array($d->story) ? $d->story : [];
            $scenes = $story['scenes'] ?? [];
            if (! isset($scenes[$this->index])) {
                return;
            }
            if ($this->refIndex !== null) {
                // EDIÇÃO de UMA referência da cena (i2i in place): troca scenes[i].refs[k] pela versão
                // editada. Não vai pra galeria (ref é intermediária; a URL fica só em scenes.refs).
                $refs = array_values(array_filter((array) ($scenes[$this->index]['refs'] ?? []), 'is_string'));
                if (isset($refs[$this->refIndex])) {
                    $refs[$this->refIndex] = $url;
                    $scenes[$this->index]['refs'] = $refs;
                    $story['scenes'] = $scenes;
                    $upd['story'] = $story;
                }
            } else {
                // IMAGEM da cena: grava image_url + anexa à galeria (scene = intermediária, não publicável).
                $scenes[$this->index]['image_url'] = $url;
                $story['scenes'] = $scenes;
                $upd['story'] = $story;
                $media = $d->media ?? [];
                if (! collect($media)->contains(fn ($m) => ($m['url'] ?? null) === $url)) {
                    $media[] = ['id' => Draft::mediaId(), 'kind' => 'image', 'url' => $url, 'style' => $this->style, 'platforms' => [], 'scene' => $this->index + 1];
                    $upd['media'] = $media;
                }
            }
            if ($upd !== []) {
                $d->update($upd);
            }
        });
    }

    public function failed(\Throwable $e): void
    {
        Log::warning('GenerateStoryImageJob falhou', ['draft' => $this->draftId, 'index' => $this->index, 'error' => $e->getMessage()]);
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
