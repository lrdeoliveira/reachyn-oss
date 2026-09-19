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
 * 🎞️ VÍDEO VOX — o carrossel virado peça de motion narrada.
 *
 * O QUE É: não é o slideshow dos PNGs do carrossel. Cada slide vira VÁRIOS quadros — o mesmo
 * fundo com um bloco de texto a mais em cada um — e cada quadro é narrado com exatamente o
 * texto que acabou de aparecer. O resultado é o texto entrando em camadas, no compasso da fala,
 * que é a linguagem do jornalismo explicativo em vídeo.
 *
 * POR QUE O MOTION É DETERMINÍSTICO, e não um modelo de vídeo: animar um slide com i2v derrete
 * a tipografia — modelos de vídeo não preservam letra. Aqui quem "anima" é o compositor: ele
 * redesenha o mesmo slide com opacidade 0 nos blocos ainda não revelados, então os quadros ficam
 * IDÊNTICOS pixel a pixel e o corte entre eles lê como "apareceu uma camada", não como troca de
 * slide. Custo de IA: zero — os fundos já existem, e compor é CPU.
 *
 * SINCRONIA (o ponto que decide se a peça presta):
 *  - cada quadro é um beat com o SEU texto → o ffmpeg-service sintetiza e mede o MP3 DECODIFICADO
 *    (não os timestamps do provedor, que derivam), + 0,35s de cauda pro último fonema não cortar;
 *  - o `duration` que mandamos é PISO, nunca teto: se a fala terminar antes de dar pra LER o
 *    bloco, o quadro estica até o tempo de leitura;
 *  - o zoom é uma RAMPA CONTÍNUA por slide (zoom_from/zoom_to por quadro): o zoompan padrão
 *    reinicia em 1.0 a cada quadro e o slide saltava pra trás quando a camada entrava.
 *  - legenda desligada: o texto já está desenhado no slide; queimar legenda escreveria a mesma
 *    frase duas vezes na tela.
 *
 * Roda em fila porque compõe N quadros (uma chamada HTTP ao compositor cada) antes de montar.
 */
class GenerateCarouselVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Montagem de vídeo é lenta (TTS por quadro + concat): o teto acompanha o do Short. */
    public int $timeout = 1300;

    /** Uma tentativa só: reprocessar re-sintetizaria toda a narração e dobraria o custo real. */
    public int $tries = 1;

    /**
     * @param  array<string,mixed>  $montagem  payload do buildShortMontage (sem os beats)
     * @param  list<string>  $platforms
     */
    public function __construct(
        public int $draftId,
        public int $tenantId,
        public array $montagem,
        public array $platforms,
        public int $custo,
        public int $fxCount,
        public bool $narracao,
    ) {}

    public function handle(UsageService $usage): void
    {
        $d = Draft::withoutGlobalScopes()->find($this->draftId);
        $t = Tenant::find($this->tenantId);
        if (! $d || ! $t) {
            return;
        }

        $beats = $this->montarQuadros($d, $t);
        if (count($beats) < 2) {
            $this->falhar($usage, 'não sobrou slide pronto o bastante para montar o vídeo');

            return;
        }

        $payload = array_merge($this->montagem, [
            'beats' => $beats,
            'muted' => ! $this->narracao,
            'noSubtitles' => true, // o texto já está desenhado no slide
            // ATRASO DE FPS — a assinatura visual do formato: a animação avança em degraus de
            // ~18 quadros/s dentro de um arquivo a 30, dando o movimento de stop-motion que
            // caracteriza o documentário explicativo animado. Sem isso a peça sai lisa demais.
            'fpsDelay' => 18,
        ]);

        try {
            $res = EngineClient::make(1200)->post('/v1/storyvideo', $payload);
        } catch (\Throwable $e) {
            $this->falhar($usage, 'a montagem do vídeo falhou: '.$e->getMessage());

            return;
        }
        $url = trim((string) $res->json('url'));
        if (! $res->successful() || $url === '') {
            $this->falhar($usage, 'a montagem do vídeo não devolveu arquivo');

            return;
        }

        $this->gravar($url, count($beats));
    }

    /**
     * Constrói os quadros do revelado: para cada slide pronto, um quadro por bloco de texto,
     * com `reveal` crescente. O `script` de cada quadro é SÓ o bloco que acabou de aparecer —
     * narrar o slide inteiro em todos os quadros repetiria a mesma frase a cada camada.
     *
     * @return list<array<string,mixed>>
     */
    private function montarQuadros(Draft $d, Tenant $t): array
    {
        $c = is_array($d->carousel) ? $d->carousel : [];
        $slides = is_array($c['slides'] ?? null) ? $c['slides'] : [];
        $marca = $t->brandKit();
        $total = max(1, count($slides));
        $beats = [];

        // Zoom LENTO e CONTÍNUO por slide (o "lente com zoom" que o estilo usa por baixo de tudo).
        // A rampa atravessa os quadros do revelado: cada quadro começa onde o anterior parou, senão
        // o slide salta pra trás toda vez que uma camada entra.
        $ZOOM_POR_SLIDE = 0.06; // 6% do início ao fim do slide — perceptível sem competir com a leitura

        foreach (array_values($slides) as $i => $s) {
            if (($s['status'] ?? '') !== 'ready') {
                continue;
            }
            // O FUNDO cru, não o slide já composto: vamos recompor com o texto em camadas, e
            // recompor sobre o slide pronto empilharia texto sobre texto.
            $fundo = trim((string) ($s['image_url'] ?? ''));
            $blocos = array_values(array_filter(
                array_map(fn ($b) => trim((string) $b), (array) ($s['blocks'] ?? [])),
                fn ($b) => $b !== ''
            ));
            if ($blocos === []) {
                continue;
            }
            $blocos = array_slice($blocos, 0, 4);

            $zoomIni = 1.0;
            $passo = $ZOOM_POR_SLIDE / max(1, count($blocos));

            foreach ($blocos as $k => $bloco) {
                $png = Composer::render([
                    'type' => 'carousel',
                    'format' => 'story', // 9:16 — o formato do vídeo, não o do carrossel
                    'brand' => $marca,
                    'slide' => [
                        'imageUrl' => $fundo,
                        'role' => (string) ($s['role'] ?? 'hook'),
                        'tag' => mb_substr(trim((string) ($s['tag'] ?? '')), 0, 40),
                        'blocks' => $blocos,
                        'accent' => array_slice(array_values(array_filter((array) ($s['accent'] ?? []), 'is_string')), 0, 3),
                        'index' => $i + 1,
                        'total' => $total,
                        'tone' => ($c['tone'] ?? 'light') === 'dark' ? 'dark' : 'light',
                        'signature' => (string) ($c['signature'] ?? ''),
                        'reveal' => $k + 1, // blocos 0..k visíveis; o resto com opacidade 0
                    ],
                ], 45);
                if ($png === null) {
                    // Um quadro que não compôs não derruba a peça: o slide segue com as camadas
                    // que deram certo. Derrubar tudo por um quadro seria pior que uma camada a menos.
                    Log::warning('carrossel-vídeo: quadro não compôs', ['draft' => $this->draftId, 'slide' => $i, 'bloco' => $k]);

                    continue;
                }

                $beats[] = [
                    'image_url' => StudioController::storeMedia($png, 'png', 'image'),
                    'script' => $this->narracao ? StudioController::carrosselFala($bloco) : '',
                    'duration' => StudioController::carrosselDuracaoBloco($bloco, (string) ($s['role'] ?? ''), $k === count($blocos) - 1),
                    // Rampa de zoom contínua ENTRE os quadros do mesmo slide (não reinicia).
                    'zoom_from' => round($zoomIni, 5),
                    'zoom_to' => round($zoomIni + $passo, 5),
                ];
                $zoomIni += $passo;
            }
        }

        return $beats;
    }

    /** Grava o vídeo no rascunho e na galeria, sob lock (o carrossel escreve na mesma coluna). */
    private function gravar(string $url, int $quadros): void
    {
        DB::transaction(function () use ($url, $quadros) {
            $d = Draft::withoutGlobalScopes()->lockForUpdate()->find($this->draftId);
            if (! $d) {
                return;
            }
            $c = is_array($d->carousel) ? $d->carousel : [];
            $c['video'] = ['url' => $url, 'status' => 'ready', 'frames' => $quadros, 'at' => now()->toIso8601String()];

            $media = $d->media ?? [];
            $media[] = [
                'id' => Draft::mediaId(),
                // kind 'video' porque É um mp4 — o card de vídeo da galeria funciona. Quem separa
                // a aba é o `style`, não o kind (criar kind novo quebraria a classificação).
                'kind' => 'video',
                'url' => $url,
                'style' => 'carrossel-video',
                'platforms' => array_values($this->platforms),
            ];
            $d->update(['carousel' => $c, 'media' => $media]);
        });
    }

    private function falhar(UsageService $usage, string $msg): void
    {
        Log::warning('GenerateCarouselVideoJob falhou', ['draft' => $this->draftId, 'erro' => $msg]);
        $usage->refund(Tenant::find($this->tenantId) ?? new Tenant, 'short', 1, $this->custo);
        if ($this->fxCount > 0) {
            $usage->refund(Tenant::find($this->tenantId) ?? new Tenant, 'effect', $this->fxCount);
        }
        DB::transaction(function () use ($msg) {
            $d = Draft::withoutGlobalScopes()->lockForUpdate()->find($this->draftId);
            if (! $d) {
                return;
            }
            $c = is_array($d->carousel) ? $d->carousel : [];
            $c['video'] = ['status' => 'error', 'error' => $msg];
            $d->update(['carousel' => $c]);
        });
    }

    public function failed(\Throwable $e): void
    {
        $this->falhar(app(UsageService::class), 'a montagem do vídeo falhou');
    }
}
