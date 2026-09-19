<?php

namespace App\Jobs;

use App\Mail\GenerationReady;
use App\Models\Character;
use App\Models\Tenant;
use App\Services\ModelSheetService;
use App\Services\Notifier;
use App\Services\UsageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Gera o MODEL SHEET determinístico: para cada grupo (turnaround/cabeça/poses/acessórios/paleta)
 * gera os shots individuais (i2i ancorado na base, concorrente) e COMPÕE a folha num template fixo
 * (ffmpeg-service). Substitui o antigo GenerateCharacterJob 'sheet-panel' (1 imagem = 1 folha inteira
 * desenhada pela IA, com layout/rótulos inconsistentes). Assíncrono (a geração leva minutos).
 *
 * `only` = regenerar SÓ um grupo (upsert por kind, mantém os outros). Cota reservada no controller
 * (reserve-then-consume); shots que falharem são ESTORNADOS aqui.
 */
class GenerateModelSheetJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1300;

    // O sheet leva 10-16 min; um reinício do worker (deploy/crash/OOM) matava o job com tries=1 e
    // ele caía em MaxAttemptsExceeded (nunca terminava). tries=3 deixa retomar; o handle() abaixo
    // PULA as pranchas já geradas quando é uma re-tentativa (attempts()>1) → o retry continua de
    // onde parou, não refaz tudo nem re-cobra (a cota é consumida 1× no dispatch). retry_after do
    // driver (1360s) é > timeout, então worker concorrente não rouba o job em execução.
    public int $tries = 3;

    public function __construct(
        public int $characterId,
        public int $tenantId,
        public ?array $bible,
        public string $lang = 'pt-BR',
        public ?string $only = null,   // null = folha completa; kind = regenerar 1 grupo
        public int $perWeight = 1,
        public ?int $perCost = null,
        public string $tweak = '',     // ajuste livre do usuário (só na regeneração de 1 grupo)
    ) {}

    public function handle(ModelSheetService $sheets, UsageService $usage): void
    {
        $c = Character::withoutGlobalScopes()->find($this->characterId);
        if (! $c) {
            return;
        }
        if (! $c->base_url) {
            $c->update(['status' => '', 'sheet_pending' => 0]);

            return;
        }

        $groups = $sheets->groups($c, $this->bible ?? (is_array($c->bible) ? $c->bible : null), $this->lang, $this->only, $this->tweak);
        if ($groups === []) {
            $c->update(['status' => '', 'sheet_pending' => 0]);

            return;
        }

        // sheets[] existente indexado por kind (p/ upsert na regeneração de 1 grupo).
        $existing = [];
        foreach ((array) ($c->sheets ?? []) as $s) {
            if (! empty($s['kind'])) {
                $existing[(string) $s['kind']] = $s;
            }
        }
        // Folhas LEGADO substituídas pelas equivalentes novas: gerar 'angles' remove 'turnaround';
        // 'head' remove 'expressions' (a folha antiga sumiria duplicada na galeria).
        foreach (['angles' => 'turnaround', 'head' => 'expressions'] as $new => $legacy) {
            if (isset($groups[$new])) {
                unset($existing[$legacy]);
            }
        }

        // Retomada: numa RE-TENTATIVA (worker reiniciou no meio), pula as pranchas que a tentativa
        // anterior já compôs — o retry continua de onde parou em vez de refazer/re-cobrar tudo.
        $resume = $this->attempts() > 1;
        $failedTotal = 0;
        foreach ($groups as $kind => $group) {
            if ($resume && ! empty($existing[(string) $kind]['url'])) {
                continue; // já gerada numa tentativa anterior
            }
            $res = $sheets->buildSheet($c, $group);
            $failedTotal += (int) $res['failed'];
            if ($res['url']) {
                $existing[(string) $kind] = ['kind' => (string) $kind, 'url' => $res['url']];
            }
            // grava incremental: o front vê as folhas aparecendo uma a uma no polling.
            $c->update(['sheets' => $this->ordered($existing)]);
        }

        // sheet_url primário = a folha do turnaround (angles); fallback = 1ª folha.
        $ordered = $this->ordered($existing);
        $primary = null;
        foreach ($ordered as $s) {
            if (($s['kind'] ?? '') === 'angles') {
                $primary = $s['url'] ?? null;
                break;
            }
        }
        $c->update([
            'sheets' => $ordered,
            'sheet_url' => $primary ?? ($ordered[0]['url'] ?? $c->sheet_url),
            'status' => '',
            'sheet_pending' => 0,
        ]);

        // S2: model sheet completo é geração LONGA (~10+ min) → e-mail "ficou pronto" (só no bundle
        // completo, não na regeneração de 1 prancha; 1 e-mail por personagem/dia via throttle).
        if ($this->only === null && $ordered !== []) {
            $org = Tenant::find($this->tenantId)?->organization;
            if ($org) {
                app(Notifier::class)->send(
                    $org,
                    'generation_ready',
                    new GenerationReady('O model sheet de "'.mb_substr($c->name, 0, 60).'"', rtrim((string) config('services.studio.url'), '/').'/personagens'),
                    'generation_ready:sheet:'.$c->id,
                    86400,
                );
            }
        }

        // Estorna a cota dos shots que falharam (a paleta não consome; só shots de IA).
        // ⚠️ `perCost` é o custo de UM shot — o UsageService já multiplica pelo peso (nº de shots).
        // Passar `perCost * $failedTotal` aqui estornava ao QUADRADO, espelhando o mesmo erro que a
        // reserva tinha no CharacterController (débito de 8.649 onde o certo eram 279).
        if ($failedTotal > 0) {
            $t = Tenant::find($this->tenantId);
            if ($t) {
                $usage->refund($t, 'image', $this->perWeight * $failedTotal, $this->perCost);
            }
        }
    }

    /** Reordena na ordem canônica (KINDS) e PRESERVA folhas extras (figurinos manuais do addOutfit,
     *  kind fora de KINDS) no fim — pra regenerar o model sheet não apagar os figurinos do cliente. */
    private function ordered(array $byKind): array
    {
        $out = [];
        foreach (ModelSheetService::KINDS as $k) {
            if (isset($byKind[$k])) {
                $out[] = $byKind[$k];
                unset($byKind[$k]);
            }
        }
        foreach ($byKind as $extra) {
            $out[] = $extra; // figurinos manuais e quaisquer folhas não-canônicas
        }

        return $out;
    }

    public function failed(\Throwable $e): void
    {
        Log::warning('GenerateModelSheetJob falhou', ['char' => $this->characterId, 'error' => $e->getMessage()]);
        $c = Character::withoutGlobalScopes()->find($this->characterId);
        if ($c) {
            $c->update(['status' => '', 'sheet_pending' => 0]);
        }
        // estorna a reserva restante (best-effort): não sabemos quantos rodaram, então não estorna
        // aqui pra não duplicar; o handle() já estorna os falhos. Falha dura = perda mínima aceitável.
    }
}
