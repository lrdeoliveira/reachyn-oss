<?php

namespace App\Jobs;

use App\Http\Controllers\Api\StudioController;
use App\Models\Draft;
use App\Models\Tenant;
use App\Services\UsageService;
use App\Support\Composer;
use App\Support\EngineClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 🎠 Renderiza UM slide do carrossel.
 *
 * Dois modos, decididos no controller:
 *
 * - EDITORIAL (padrão) — a IA gera só o FUNDO; o texto é composto por cima pelo compositor nativo
 *   (next/og). Tipografia idêntica em todos os slides, ortografia correta, marca travada.
 * - ARTE-TOTAL — a imagem já vem com o texto renderizado dentro. Mais caro e sujeito a erro de
 *   ortografia, por isso é modo avançado.
 *
 * A CAPA é gerada primeiro, sozinha, e vira referência de todos os slides internos (o controller
 * dispara os internos só depois que ela grava a URL). É isso que faz N imagens parecerem uma peça
 * só em vez de N avulsas — regra herdada do sistema editorial de origem, onde era a diferença
 * entre um carrossel e nove imagens soltas.
 *
 * Cota: reservada no controller (reserve-then-consume) e ESTORNADA aqui se a geração falhar.
 * Espelha o GenerateStoryImageJob.
 */
class GenerateCarouselSlideJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Imagem i2i leva ~2min. Se o provedor travar, falha em ~6min e LIBERA o worker.
    public int $timeout = 420;

    public int $tries = 1;

    public function __construct(
        public int $draftId,
        public int $tenantId,
        public int $index,            // índice 0-based dentro de carousel.slides
        public array $payload,        // body do /v1/image (prompt + aspect + style + imageUrls?)
        public string $mode,          // 'editorial' | 'arte-total'
        public string $format,        // formato do compositor (feed|retrato|story|paisagem)
        public int $weight,
        public ?int $costCredits = null,
        /**
         * Slides que esperam ESTE terminar pra começar (só a capa recebe lista cheia).
         * Cada item: ['index' => int, 'payload' => array]. A cota deles JÁ foi reservada no
         * controller — o usuário vê o custo total antes de mandar renderizar, e não descobre
         * slide a slide. Se a capa falhar, estorna todos e ninguém roda.
         *
         * @var list<array{index:int,payload:array<string,mixed>}>
         */
        public array $rest = [],
    ) {}

    public function handle(UsageService $usage): void
    {
        if (! Draft::find($this->draftId)) {
            $this->refund($usage);

            return;
        }

        $res = EngineClient::make(360)->post('/v1/image', $this->payload);
        $bg = $res->successful() ? (string) $res->json('url') : '';
        if ($bg === '') {
            Log::warning('GenerateCarouselSlideJob: geração sem URL', ['draft' => $this->draftId, 'index' => $this->index, 'status' => $res->status()]);
            $this->falhar($usage, 'a imagem do slide não foi gerada');

            return;
        }

        // No modo editorial o slide final é a composição; no arte-total, a própria imagem gerada.
        $final = $bg;
        if ($this->mode === 'editorial') {
            if ($composto = $this->compor($bg)) {
                $final = $composto;
            } else {
                // O compositor caiu, mas a IMAGEM foi paga e existe. Entregar o fundo cru é melhor
                // que perder o slide: o usuário vê o que saiu e pode recompor sem gastar de novo.
                Log::warning('GenerateCarouselSlideJob: composição falhou, entregando o fundo', ['draft' => $this->draftId, 'index' => $this->index]);
            }
        }

        $this->gravar($bg, $final, $final !== $bg);

        // CAPA PRONTA → libera os internos, todos com a capa como referência visual. Eles rodam em
        // paralelo entre si (não dependem uns dos outros), o que mantém o tempo total perto do de
        // um slide só em vez de N.
        foreach ($this->rest as $r) {
            $payload = $r['payload'];
            $refs = array_values(array_filter((array) ($payload['imageUrls'] ?? []), 'is_string'));
            array_unshift($refs, $bg);                       // a capa entra como 1ª referência
            $payload['imageUrls'] = array_slice($refs, 0, 3); // teto do modelo i2i
            self::dispatch(
                $this->draftId, $this->tenantId, (int) $r['index'], $payload,
                $this->mode, $this->format, $this->weight, $this->costCredits,
            );
        }
    }

    /** Compõe o texto do slide sobre o fundo gerado e persiste o PNG. Devolve a URL ou null. */
    private function compor(string $bg): ?string
    {
        $d = Draft::find($this->draftId);
        $t = Tenant::find($this->tenantId);
        if (! $d || ! $t) {
            return null;
        }
        $c = is_array($d->carousel) ? $d->carousel : [];
        $slides = $c['slides'] ?? [];
        if (! isset($slides[$this->index])) {
            return null;
        }
        $slide = $slides[$this->index];
        $blocks = array_values(array_filter(
            array_map(fn ($b) => trim((string) $b), (array) ($slide['blocks'] ?? [])),
            fn ($b) => $b !== ''
        ));
        if ($blocks === []) {
            return null;
        }
        $png = Composer::render([
            'type' => 'carousel',
            'format' => $this->format,
            'brand' => $t->brandKit(),
            'slide' => [
                'imageUrl' => $bg,
                'role' => (string) ($slide['role'] ?? 'hook'),
                'tag' => mb_substr(trim((string) ($slide['tag'] ?? '')), 0, 40),
                'blocks' => array_slice($blocks, 0, 4),
                'accent' => array_slice(array_values(array_filter((array) ($slide['accent'] ?? []), 'is_string')), 0, 3),
                'index' => $this->index + 1,
                'total' => max(1, count($slides)),
                'tone' => ($c['tone'] ?? 'light') === 'dark' ? 'dark' : 'light',
                'signature' => (string) ($c['signature'] ?? ''),
            ],
        ], 45);
        if ($png === null) {
            return null;
        }

        return StudioController::storeMedia($png, 'png', 'image');
    }

    /**
     * Grava as URLs no slide e anexa o resultado à galeria, sob LOCK: os slides internos rodam em
     * paralelo e escrevem na MESMA coluna JSON — sem o lock, o último a gravar apagaria os outros.
     */
    private function gravar(string $bg, string $final, bool $composto): void
    {
        DB::transaction(function () use ($bg, $final, $composto) {
            $d = Draft::lockForUpdate()->find($this->draftId);
            if (! $d) {
                return;
            }
            $c = is_array($d->carousel) ? $d->carousel : [];
            $slides = $c['slides'] ?? [];
            if (! isset($slides[$this->index])) {
                return;
            }
            $slides[$this->index]['image_url'] = $bg;      // o fundo cru — permite recompor sem regerar
            $slides[$this->index]['slide_url'] = $final;   // o slide entregável
            $slides[$this->index]['status'] = 'ready';
            unset($slides[$this->index]['error']);
            $c['slides'] = $slides;
            $upd = ['carousel' => $c];

            $media = $d->media ?? [];
            if (! collect($media)->contains(fn ($m) => ($m['url'] ?? null) === $final)) {
                $media[] = [
                    'id' => Draft::mediaId(),
                    'kind' => 'image',
                    'url' => $final,
                    'style' => 'carrossel',
                    'platforms' => array_values(array_filter((array) ($c['platforms'] ?? []), 'is_string')),
                    'composed' => $composto,
                    // Agrupa os slides na galeria e preserva a ORDEM de publicação — é a mesma
                    // chave que o compositor de posts já grava.
                    'carousel' => ['index' => $this->index + 1, 'total' => max(1, count($slides))],
                ];
                $upd['media'] = $media;
            }
            $d->update($upd);
        });
    }

    public function failed(\Throwable $e): void
    {
        Log::warning('GenerateCarouselSlideJob falhou', ['draft' => $this->draftId, 'index' => $this->index, 'error' => $e->getMessage()]);
        $this->falhar(app(UsageService::class), 'a geração do slide falhou');
    }

    /** Estorna e marca o slide como erro — um slide interno que falha não derruba os outros. */
    private function falhar(UsageService $usage, string $msg): void
    {
        $this->refund($usage);
        // A capa é o elo de que todos dependem: sem ela os internos perdem a referência visual e
        // sairiam como N imagens avulsas. Então ninguém roda, e a cota deles volta inteira.
        $indices = [$this->index];
        foreach ($this->rest as $r) {
            $this->refund($usage);
            $indices[] = (int) $r['index'];
        }
        DB::transaction(function () use ($msg, $indices) {
            $d = Draft::lockForUpdate()->find($this->draftId);
            if (! $d) {
                return;
            }
            $c = is_array($d->carousel) ? $d->carousel : [];
            $slides = $c['slides'] ?? [];
            foreach ($indices as $i) {
                if (! isset($slides[$i])) {
                    continue;
                }
                $slides[$i]['status'] = 'error';
                $slides[$i]['error'] = $i === $this->index ? $msg : 'cancelado: a capa falhou e é a referência visual dos demais';
            }
            $c['slides'] = $slides;
            $d->update(['carousel' => $c]);
        });
    }

    private function refund(UsageService $usage): void
    {
        if ($t = Tenant::find($this->tenantId)) {
            $usage->refund($t, 'image', $this->weight, $this->costCredits);
        }
    }
}
