<?php

namespace App\Jobs;

use App\Models\Character;
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
 * Geração ASSÍNCRONA de um PERSONAGEM da biblioteca. A imagem i2i (nano-banana) leva ~2-4min e
 * estouraria o timeout do proxy se fosse no request. O worker chama o engine e grava a URL no
 * personagem; o front faz polling de /api/characters/{id} até status sair de '' (gerando).
 *
 * Tasks (todas só geram a IMAGEM; a bíblia/lock do sheet já vêm prontos do controller):
 *  - 'base'        : imagem-BASE (retrato frontal, t2i) → base_url.
 *  - 'edit'        : edição i2i da base → base_url (no lugar).
 *  - 'sheet-panel' : UMA prancha do MODEL SHEET v3 (i2i da base) → upsert-by-kind em sheets[] (a
 *                    prancha 'angles' também vira sheet_url). Vários jobs em paralelo; o contador
 *                    sheet_pending coordena o fim (o último a zerar limpa o status). Estorno per-prancha.
 *
 * Cota 'image' reservada no controller (reserve-then-consume) e ESTORNADA aqui na falha
 * (por prancha, no caso do sheet). Espelha o GenerateStoryImageJob.
 */
class GenerateCharacterJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 420;

    public int $tries = 1;

    public function __construct(
        public int $characterId,
        public int $tenantId,
        public string $task,        // 'base' | 'edit' | 'sheet-panel'
        public array $imagePayload, // body do /v1/image (prompt + aspect + style + imageUrls?)
        public int $weight,         // cota 'image' reservada (estornada na falha)
        public ?int $costCredits = null, // custo em créditos do modelo usado (null = custo fixo por tipo)
        public ?string $panelKind = null, // sheet-panel v3: 'angles' | 'head' | 'poses' | 'palette' | 'outfit'
        public ?string $panelId = null,   // sheet-panel: slot único p/ upsert (default = panelKind). Figurinos usam id próprio → coexistem vários.
        public ?string $panelLabel = null, // sheet-panel: rótulo amigável (ex: descrição do figurino/roupa)
    ) {}

    public function handle(UsageService $usage): void
    {
        $sheet = $this->task === 'sheet-panel';
        if (! Character::find($this->characterId)) {
            $this->refund($usage);

            return; // personagem sumiu: nada pra atualizar (o pending morre com o registro)
        }

        // Imagem PAGA (lenta): base (t2i) / prancha do sheet (i2i) / edição (i2i). A bíblia (texto)
        // já foi gerada SÍNCRONA no controller (que também montou os prompts das pranchas a partir dela).
        $res = EngineClient::make(360) // i2i não deve passar disso; estoura → falha rápido + estorna
            ->post('/v1/image', $this->imagePayload);
        $url = $res->successful() ? (string) $res->json('url') : '';

        if ($url === '') {
            Log::warning('GenerateCharacterJob: imagem sem URL', ['character' => $this->characterId, 'task' => $this->task, 'panel' => $this->panelKind, 'status' => $res->status()]);
            $this->refund($usage);
            $sheet ? $this->finalizePanel(false, '') : $this->clearStatus();

            return;
        }

        if ($sheet) {
            $this->finalizePanel(true, $url);

            return;
        }

        DB::transaction(function () use ($url) {
            $c = Character::lockForUpdate()->find($this->characterId);
            if (! $c) {
                return;
            }
            // 'base'/'edit' gravam a imagem-base (âncora i2i das cenas).
            $c->update(['status' => '', 'base_url' => $url]);
        });
    }

    public function failed(\Throwable $e): void
    {
        Log::warning('GenerateCharacterJob falhou', ['character' => $this->characterId, 'task' => $this->task, 'panel' => $this->panelKind, 'error' => $e->getMessage()]);
        $this->refund(app(UsageService::class));
        $this->task === 'sheet-panel' ? $this->finalizePanel(false, '') : $this->clearStatus();
    }

    /**
     * Fecha UMA prancha do model sheet (atômico, lock de linha): anexa {kind,url} em sheets[] (e no
     * turnaround também grava sheet_url), decrementa sheet_pending e — quando zera — limpa o status.
     * Chamado exatamente 1x por job (sucesso, URL vazia, ou exceção via failed()).
     */
    private function finalizePanel(bool $ok, string $url): void
    {
        DB::transaction(function () use ($ok, $url) {
            $c = Character::lockForUpdate()->find($this->characterId);
            if (! $c) {
                return;
            }
            $upd = [];
            if ($ok && $url !== '') {
                // upsert-by-SLOT: substitui a prancha do mesmo slot (regeneração) ou anexa (nova). O slot
                // é o panelId quando existe (figurinos → cada um tem id próprio, então vários coexistem),
                // senão o kind (as 4 canônicas). Evita pranchas duplicadas.
                $slot = $this->panelId ?? $this->panelKind;
                $sheets = array_values(array_filter($c->sheets ?? [], fn ($s) => ($s['id'] ?? $s['kind'] ?? '') !== $slot));
                $panel = ['kind' => $this->panelKind, 'url' => $url];
                if ($this->panelId !== null) {
                    $panel['id'] = $this->panelId;
                }
                if ($this->panelLabel !== null && $this->panelLabel !== '') {
                    $panel['label'] = $this->panelLabel;
                }
                $sheets[] = $panel;
                $upd['sheets'] = $sheets;
                // prancha primária (retrocompat sheet_url usado por Histórias/Mídia/UI): angles (v3) ou turnaround (v2).
                if (in_array($this->panelKind, ['angles', 'turnaround'], true)) {
                    $upd['sheet_url'] = $url;
                }
            }
            $pending = max(0, (int) $c->sheet_pending - 1);
            $upd['sheet_pending'] = $pending;
            if ($pending === 0) {
                $upd['status'] = ''; // última prancha concluiu → personagem pronto
            }
            $c->update($upd);
        });
    }

    private function clearStatus(): void
    {
        $c = Character::find($this->characterId);
        if ($c && $c->status !== '') {
            $c->update(['status' => '']);
        }
    }

    private function refund(UsageService $usage): void
    {
        $t = Tenant::find($this->tenantId);
        if ($t) {
            $usage->refund($t, 'image', $this->weight, $this->costCredits);
        }
    }
}
