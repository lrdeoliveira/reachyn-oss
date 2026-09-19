<?php

namespace App\Jobs;

use App\Models\Draft;
use App\Models\Publication;
use App\Services\PublishService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Publica um rascunho nas redes do tenant de forma ASSÍNCRONA (worker de fila).
 * Publicar em N redes é sequencial e lento (upload + API de cada rede); fazer no request
 * estourava timeout. Aqui o worker (timeout 1300s) publica e grava o resultado em drafts.publish;
 * o Studio faz polling de /api/studio/publish-status.
 */
class PublishDraftJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1300;

    public int $tries = 1;

    public function __construct(public int $draftId) {}

    public function handle(PublishService $publisher): void
    {
        $d = Draft::find($this->draftId);
        if (! $d) {
            return;
        }

        $results = $publisher->publishDraft($d);

        $d->update([
            'status' => 'publicado',
            'publish' => [
                'state' => 'done',
                'results' => $results,
                'finished_at' => now()->toIso8601String(),
            ],
        ]);

        // Arquivo de publicações: snapshot PERMANENTE do rascunho que foi ao ar.
        // tenant_id explícito (do próprio draft). content_text = textos publicados (≠ vazios)
        // juntados; media = a galeria do rascunho. record() é à prova de falha (não derruba o job).
        $texts = array_filter($d->texts ?? [], fn ($v) => trim((string) $v) !== '');
        $content = implode("\n\n---\n\n", array_map('strval', $texts));
        Publication::record(
            tenantId: (int) $d->tenant_id,
            sourceType: 'draft',
            sourceId: $d->id,
            keyword: (string) $d->keyword,
            contentText: $content,
            media: $d->media ?? [],
            results: $results,
        );
    }

    public function failed(\Throwable $e): void
    {
        Log::warning('PublishDraftJob falhou', ['draft' => $this->draftId, 'error' => $e->getMessage()]);
        $d = Draft::find($this->draftId);
        if ($d) {
            $d->update(['publish' => [
                'state' => 'failed',
                'error' => $e->getMessage(),
                'finished_at' => now()->toIso8601String(),
            ]]);
        }
    }
}
