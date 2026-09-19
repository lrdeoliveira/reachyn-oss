<?php

namespace App\Console\Commands;

use App\Models\GenModel;
use App\Support\EngineClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Marca/desmarca `is_unstable` nos modelos de TEXTO, sondando o caminho REAL de geração.
 *
 * POR QUE EXISTE: em 2026-08-03 as quatro linhas mais caras do seletor de texto ficaram fora por
 * mais de um dia. O sintoma no cliente era invisível — o engine caía na reserva e devolvia HTTP
 * 200 — e a coluna `is_unstable` só foi marcada À MÃO, depois de alguém notar. Enquanto isso o
 * app cobrava premium por entrega de reserva. Um flag que depende de alguém perceber não é um
 * flag: é um atraso. Aqui a sonda é automática e roda no scheduler.
 *
 * COMO SABE: não bate no provedor direto (a chave vive no engine, não aqui) — manda uma geração
 * mínima pelo mesmo `/v1/chat` que o app usa, com o `gen_lines` daquele modelo, e lê o cabeçalho
 * X-Reachyn-Reserva. Cabeçalho presente ⇒ a linha primária daquele modelo não serviu e a reserva
 * entregou ⇒ instável. É o teste ponta a ponta do caminho que o cliente percorre, não uma
 * aproximação: usa o mesmo roteamento, o mesmo timeout e o mesmo fallback.
 *
 * Custa uma geração de ~8 tokens por modelo ativo (7 hoje). Roda de hora em hora.
 */
class CheckTextModelsHealth extends Command
{
    protected $signature = 'reachyn:check-text-health {--dry : só relata, não escreve no catálogo}';

    protected $description = 'Sonda os modelos de texto e atualiza o flag de instabilidade';

    /** Aviso mostrado ao cliente. White-label (#6): descreve o efeito, nunca o provedor. */
    private const MOTIVO = 'Linha instável no momento — a geração pode cair numa reserva de qualidade menor. Prefira outro nível até normalizar.';

    public function handle(): int
    {
        $modelos = GenModel::query()->where('kind', 'text')->where('is_active', true)->orderBy('cost_credits')->get();
        if ($modelos->isEmpty()) {
            $this->warn('Nenhum modelo de texto ativo.');

            return self::SUCCESS;
        }

        $mudou = 0;
        foreach ($modelos as $m) {
            $instavel = $this->sonda($m);
            if ($instavel === null) {
                // Indeterminado (engine fora, timeout): NÃO escreve. Marcar tudo como instável
                // porque o engine caiu seria trocar um alarme por outro, e o aviso perde o sentido.
                $this->line(sprintf('  %-16s ? indeterminado (engine não respondeu)', $m->slug));

                continue;
            }

            $this->line(sprintf('  %-16s %s', $m->slug, $instavel ? '✗ instável (a reserva atendeu)' : '✓ ok'));

            if ($this->option('dry')) {
                continue;
            }

            $antes = (bool) $m->is_unstable;
            $m->forceFill([
                'is_unstable' => $instavel,
                'unstable_reason' => $instavel ? self::MOTIVO : null,
                'health_checked_at' => now(),
            ])->save();

            if ($antes !== $instavel) {
                $mudou++;
                Log::warning('saúde do modelo de texto mudou', [
                    'slug' => $m->slug, 'instavel' => $instavel,
                ]);
            }
        }

        $this->info($this->option('dry') ? 'Sonda concluída (dry-run, nada gravado).' : "Sonda concluída — {$mudou} mudança(s) de estado.");

        return self::SUCCESS;
    }

    /** true = a reserva atendeu (instável) · false = a primária serviu · null = indeterminado. */
    private function sonda(GenModel $m): ?bool
    {
        try {
            $r = EngineClient::make(90)->post('/v1/chat', [
                'system' => 'Responda com uma única palavra.',
                'message' => 'Diga OK.',
                'maxTokens' => 8,
                'gen_lines' => ['text' => ['model' => (string) $m->provider_model_id]],
            ]);
        } catch (\Throwable $e) {
            return null;
        }

        if (! $r->successful()) {
            // A primária E a reserva falharam, ou o engine está fora. Do ponto de vista do
            // cliente o nível não está entregando — instável é a leitura honesta.
            return $r->status() >= 500 ? null : true;
        }

        return $r->header(EngineClient::CABECALHO_RESERVA) === '1';
    }
}
