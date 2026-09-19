<?php

namespace App\Jobs\Concerns;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

/**
 * Retry idempotente para os jobs que chamam o engine e esperam uma URL de mídia.
 *
 * O PROBLEMA (documentado em AnimationSceneJob e no PLANO-UX-INTERFACE): a maioria dos jobs de
 * geração era `tries=1`. Um blip de rede, um restart de worker (deploy/OOM) ou um 5xx transitório
 * do provedor no meio de 8-14min jogava fora o trabalho já feito — o provedor que responde
 * "200 sem URL" ou 5xx NÃO lança exceção, então nem chegava a retentar: marcava erro e estornava
 * na hora, mesmo sendo transitório.
 *
 * A REGRA aqui:
 *   - URL presente            → retorna a URL (sucesso).
 *   - vazia + 4xx PERMANENTE  → retorna '' (param inválido/cota: retentar não ajuda; o caller
 *                               estorna e desiste sem gastar retry).
 *   - vazia + TRANSITÓRIO     → LANÇA TransientEngineException enquanto houver tentativa: a fila
 *     (5xx/200-sem-url/blip)    devolve o job com backoff. Ao esgotar `tries`, retorna '' (o caller
 *                               estorna 1× na desistência).
 *
 * IDEMPOTÊNCIA: a cota é reservada 1× no dispatch e estornada 1× — ou inline na desistência, ou
 * no failed() do job ao esgotar (nunca os dois no mesmo run, porque a desistência RETORNA sem
 * lançar e o failed() só dispara em exceção). O attach de mídia dos jobs é dedup por URL, então
 * um re-run que só refez a geração não duplica.
 */
trait RetriesTransientEngineErrors
{
    /**
     * Extrai a URL da resposta do engine, decidindo entre retentar (transitório) e desistir.
     *
     * @param  array<string,mixed>  $ctx  contexto pro log (draft/project/index…)
     * @return string a URL, ou '' quando é pra DESISTIR (4xx permanente ou última tentativa)
     */
    protected function engineUrlOrRetry(Response $res, string $job, array $ctx = []): string
    {
        $url = $res->successful() ? (string) $res->json('url') : '';
        if ($url !== '') {
            return $url;
        }

        $status = $res->status();
        $attempt = $this->attempts();
        $permanent = $res->clientError() // 4xx: parâmetro inválido/cota → não melhora com retry
            || self::jaQueimouCredito($res)
            || self::semCredito($res);
        $max = property_exists($this, 'tries') && $this->tries ? (int) $this->tries : 1;

        Log::warning("$job: engine sem URL", $ctx + [
            'status' => $status,
            'attempt' => $attempt,
            'max_tries' => $max,
            'permanent' => $permanent,
        ]);

        if (! $permanent && $attempt < $max) {
            throw new TransientEngineException("$job: engine sem URL (status $status, tentativa $attempt/$max) — retry");
        }

        return ''; // desiste: caller estorna + marca erro
    }

    /**
     * A geração JÁ GASTOU crédito do provedor? Então retentar não é "mais uma chance": é pagar de
     * novo pelo mesmo pedido.
     *
     * O caso que motivou (2026-08-04): um Vox de 6 cenas devolveu "poucos clipes ok (0/6)". O erro
     * parece transitório — e é, do ponto de vista do serviço —, mas cada rodada dispara N gerações
     * na conta do provedor. Três tentativas viraram até 18 gerações pagas para entregar uma peça
     * de 11 segundos. Retry às cegas em operação multi-cena é multiplicador de custo, não rede de
     * segurança: o cliente prefere o erro claro na primeira vez.
     *
     * A detecção é pela mensagem porque é o que o engine expõe hoje; se um dia ele devolver um
     * código de erro estruturado, trocar aqui (e só aqui).
     */
    private static function jaQueimouCredito(Response $res): bool
    {
        return str_contains(mb_strtolower((string) $res->body()), 'poucos clipes ok');
    }

    /**
     * A conta do provedor está SEM SALDO? Então não existe "mais uma tentativa".
     *
     * Retentar aqui é puro desperdício de tempo e de fila: a terceira tentativa encontra a mesma
     * conta vazia da primeira. Pior, enche o log de erro repetido e empurra o motivo real —
     * "recarregue a conta" — pra longe de quem está lendo.
     *
     * Flagrado em 2026-08-04: o pré-voo do engine passou a barrar a geração antes de gastar (bom),
     * mas o job retentava assim mesmo porque a resposta não é 4xx e não fala em "poucos clipes ok".
     */
    private static function semCredito(Response $res): bool
    {
        $b = mb_strtolower((string) $res->body());

        return str_contains($b, 'sem créditos') || str_contains($b, 'not_enough_credits');
    }

    /**
     * Backoff progressivo entre retentativas de erro transitório do engine.
     * Curto o bastante pra recuperar de um blip; espaçado pra não martelar um provedor em 5xx.
     */
    public function backoff(): array
    {
        return [15, 45];
    }
}
