<?php

namespace App\Console\Commands;

use App\Jobs\PublishDraftJob;
use App\Models\Draft;
use Illuminate\Console\Command;

/**
 * Dispara as publicações AGENDADAS que já venceram.
 *
 * Roda a cada minuto. O agendamento em si (endpoint /api/studio/schedule) só grava a intenção;
 * quem publica continua sendo o mesmo PublishDraftJob do "publicar agora" — este comando é só o
 * gatilho que faltava.
 *
 * A transição scheduled → running usa o MESMO UPDATE condicional do submit (AUD-004): quem vence
 * a corrida é quem conseguir mudar o state, então duas execuções sobrepostas (ou dois workers)
 * nunca publicam o mesmo rascunho duas vezes. É o que torna seguro rodar de minuto em minuto.
 */
class PublishDue extends Command
{
    protected $signature = 'reachyn:publish-due {--dry-run : lista o que publicaria, sem disparar}';

    protected $description = 'Dispara as publicações agendadas cujo horário já chegou';

    public function handle(): int
    {
        // Sem escopo de tenant: é worker, varre todas as marcas. O claim abaixo já filtra por id.
        $due = Draft::withoutGlobalScopes()
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->whereRaw("publish->>'state' = 'scheduled'")
            ->orderBy('scheduled_at')
            ->limit(200) // teto por rodada: uma fila represada não vira um pico de publicações
            ->get(['id', 'tenant_id', 'scheduled_at', 'publish']);

        if ($due->isEmpty()) {
            return self::SUCCESS;
        }

        $disparados = 0;
        foreach ($due as $d) {
            $payload = is_array($d->publish) ? $d->publish : [];
            $redes = implode(', ', (array) ($payload['platforms'] ?? []));

            if ($this->option('dry-run')) {
                $this->line("rascunho {$d->id} (marca {$d->tenant_id}) → {$redes}");

                continue;
            }

            $payload['state'] = 'running';
            $payload['started_at'] = now()->toIso8601String();

            // Mesmo claim do submit: só dispara quem conseguiu virar o state de 'scheduled'.
            $claimed = Draft::withoutGlobalScopes()
                ->where('id', $d->id)
                ->whereRaw("publish->>'state' = 'scheduled'")
                ->update(['publish' => json_encode($payload), 'scheduled_at' => null]);

            if ($claimed !== 1) {
                continue; // outra execução pegou primeiro
            }

            PublishDraftJob::dispatch($d->id);
            $disparados++;
        }

        if ($disparados > 0) {
            $this->info("{$disparados} publicação(ões) agendada(s) disparada(s).");
        }

        return self::SUCCESS;
    }
}
