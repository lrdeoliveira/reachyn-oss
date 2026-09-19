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
use Illuminate\Support\Facades\Log;

/**
 * 🎠 Gera o PLANO EDITORIAL do carrossel de forma ASSÍNCRONA (headline + arquitetura narrativa +
 * brief de imagem por slide). São três chamadas encadeadas a um reasoning model — ~1-3min no
 * total, muito além do que o proxy aguenta num request.
 *
 * TEXTO PURO: este job não gera imagem nenhuma e não gasta crédito de imagem. O render (a parte
 * cara) só acontece depois que o usuário revisa o plano na tela e clica em renderizar. Essa ordem
 * é a decisão central da feature: um carrossel de 9 slides são 9 imagens, e deixar a IA disparar
 * isso sem revisão humana transforma um tema mal interpretado em nove cobranças.
 *
 * Cota 'text': reservada no controller (reserve-then-consume) e ESTORNADA aqui se falhar.
 * Espelha o GenerateStoryJob.
 */
class GenerateCarouselPlanJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Três chamadas encadeadas de até 200s cada (o engine bounda cada etapa). Damos margem
    // acima da soma: teto menor que o do engine mata o job com a geração ainda em curso.
    public int $timeout = 700;

    public int $tries = 1;

    public function __construct(
        public int $draftId,
        public int $tenantId,
        public array $payload,        // body do /v1/carousel (topic, slides, lang, brand, visual_brief…)
        public ?string $textModel = null, // modelo do seletor de texto (null = default do engine)
        public ?int $textCost = null,     // créditos cobrados no controller (estornados na falha)
    ) {}

    public function handle(UsageService $usage): void
    {
        $payload = $this->payload;
        if ($this->textModel) {
            $payload['gen_lines'] = ['text' => ['model' => $this->textModel]];
        }

        $res = EngineClient::make(660)->post('/v1/carousel', $payload);

        $slides = $res->successful() ? $res->json('slides') : null;
        if (! is_array($slides) || $slides === []) {
            Log::warning('GenerateCarouselPlanJob: sem slides', ['draft' => $this->draftId, 'status' => $res->status()]);
            $this->markFailed($usage, 'o carrossel não retornou slides');

            return;
        }

        DB::transaction(function () use ($res, $slides) {
            $d = Draft::lockForUpdate()->find($this->draftId);
            if (! $d) {
                return;
            }
            // MERGE, não substituição: o controller já gravou tema, formato, modo, redes-alvo e a
            // assinatura de rodapé antes de enfileirar. Montar o array do zero aqui apagaria tudo
            // isso em silêncio — o render sairia sem assinatura e sem as redes escolhidas.
            $prev = is_array($d->carousel) ? $d->carousel : [];
            $headline = mb_substr(trim((string) ($res->json('headline') ?? '')), 0, 300);
            $d->update([
                'carousel' => array_merge($prev, [
                    'headline' => $headline,
                    'family' => mb_substr(trim((string) ($res->json('family') ?? '')), 0, 80),
                    'axis' => mb_substr(trim((string) ($res->json('axis') ?? '')), 0, 40),
                    'caption' => trim((string) ($res->json('caption') ?? '')),
                    'slides' => $slides,
                    'status' => 'ready',
                ]),
                // A headline vira o nome do rascunho em toda a UI (galeria/aprovações leem keyword).
                'keyword' => $headline !== '' ? mb_substr($headline, 0, 80) : $d->keyword,
            ]);
        });
    }

    public function failed(\Throwable $e): void
    {
        Log::warning('GenerateCarouselPlanJob falhou', ['draft' => $this->draftId, 'error' => $e->getMessage()]);
        $this->markFailed(app(UsageService::class), 'a geração do carrossel falhou');
    }

    /** Estorna a cota de texto e marca carousel.status=error (o front sai do "gerando" e mostra o motivo). */
    private function markFailed(UsageService $usage, string $msg): void
    {
        if ($t = Tenant::find($this->tenantId)) {
            $usage->refund($t, 'text', 1, $this->textCost);
        }
        DB::transaction(function () use ($msg) {
            $d = Draft::lockForUpdate()->find($this->draftId);
            if (! $d) {
                return;
            }
            $c = is_array($d->carousel) ? $d->carousel : [];
            $c['status'] = 'error';
            $c['error'] = $msg;
            $d->update(['carousel' => $c]);
        });
    }
}
