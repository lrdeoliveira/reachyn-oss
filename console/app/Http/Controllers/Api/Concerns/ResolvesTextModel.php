<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\GenModel;
use App\Models\Tenant;
use App\Services\UsageService;
use App\Support\EngineClient;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Seletor de MODELO DE TEXTO (kind='text') — resumo da pesquisa, conteúdo (posts), roteiro de
 * História/Filme e bíblia de Personagem. O cliente manda `textModel` (slug público); aqui resolve
 * server-side pro GenModel (custo + provider_model_id) — slug inválido/ausente cai no default
 * Equilibrado. O engine recebe só `gen_lines.text.model` (white-label preservado).
 */
trait ResolvesTextModel
{
    /** Slug default do texto premium quando o cliente não escolhe. */
    private static string $textDefault = 'txt-equilibrado';

    /**
     * Resolve o modelo de texto escolhido (request `textModel`) validado pro plano; fallback default.
     *
     * ⚠️ O DEFAULT NUNCA PODE SER UM NÍVEL INSTÁVEL. Quem escolhe explicitamente um nível marcado
     * como instável é atendido assim mesmo — a UI já mostra o aviso e a decisão é do cliente. Mas
     * quem NÃO escolhe está aceitando a nossa recomendação, e recomendar uma linha que está caindo
     * pra reserva é cobrar caro por entrega básica. Em 2026-08-03 foi exatamente o que aconteceu:
     * `txt-equilibrado`, o default de todo o app, passou mais de um dia fora junto com as outras
     * três linhas Claude do agregador. Aqui o default degrada sozinho pro nível estável mais
     * próximo, e volta ao normal quando a sonda (reachyn:check-text-health) limpar o flag.
     */
    private function textModelFor(Request $r, ?string $plan): ?GenModel
    {
        if ($escolhido = GenModel::resolveSelectable($r->input('textModel'), 'text', $plan)) {
            return $escolhido;
        }

        $padrao = GenModel::resolveSelectable(self::$textDefault, 'text', $plan);
        if ($padrao && ! $padrao->is_unstable) {
            return $padrao;
        }

        // Nível estável mais próximo POR CIMA do preço do default: degradar a qualidade sem avisar
        // é pior que gastar um pouco mais. Sem nenhum estável acima, pega o melhor estável que houver.
        $piso = (int) ($padrao->cost_credits ?? 0);

        return GenModel::active()->kind('text')->forPlan($plan)
            ->where('is_unstable', false)
            ->orderByRaw('CASE WHEN cost_credits >= ? THEN 0 ELSE 1 END', [$piso])
            ->orderByRaw('ABS(cost_credits - ?)', [$piso])
            ->first() ?? $padrao;
    }

    /** `gen_lines` com a line de texto do modelo (payload engine); [] quando não resolvido. */
    private function textGenLines(?GenModel $gm): array
    {
        return $gm ? ['text' => ['model' => (string) $gm->provider_model_id]] : [];
    }

    /**
     * Devolve a diferença quando a entrega veio da LINHA DE RESERVA, e não do modelo pedido.
     *
     * Chamar DEPOIS de toda chamada de texto ao engine que teve sucesso — o débito acontece antes
     * de gerar, pelo preço do modelo pedido, e a queda pra reserva volta como HTTP 200. Sem isto
     * o cliente paga premium por uma entrega de nível básico (caso real 2026-08-03, com a linha
     * primária do agregador fora por mais de um dia).
     *
     * Silencioso e sem exceção de propósito: contabilidade não pode derrubar uma geração que já
     * foi entregue ao cliente. Se o engine for antigo e não mandar o cabeçalho, o comportamento é
     * o de antes — nada a devolver.
     *
     * @param  Response  $resp  resposta do engine (de onde sai o cabeçalho)
     * @return int créditos devolvidos
     */
    private function ajustaSeReserva(Tenant $tenant, ?GenModel $gm, $resp, int $weight = 1): int
    {
        if (! $gm || ! $resp || $resp->header(EngineClient::CABECALHO_RESERVA) !== '1') {
            return 0;
        }

        try {
            return app(UsageService::class)->ajustaParaReserva($tenant, 'text', $weight, $gm->cost_credits);
        } catch (\Throwable $e) {
            Log::warning('falhou devolver a diferença da reserva', ['erro' => $e->getMessage()]);

            return 0;
        }
    }
}
