<?php

namespace Tests\Feature;

use App\Jobs\Concerns\RetriesTransientEngineErrors;
use App\Jobs\Concerns\TransientEngineException;
use Illuminate\Http\Client\Response;
use Tests\TestCase;

/**
 * 💳 SEM SALDO NÃO É "TENTE DE NOVO". A terceira tentativa encontra a mesma conta vazia da
 * primeira — só gasta tempo de fila e enche o log de erro repetido, empurrando o motivo real
 * ("recarregue a conta") pra longe de quem está lendo.
 *
 * Flagrado em 2026-08-04: o pré-voo do engine passou a barrar a geração ANTES de gastar, mas o
 * job retentava mesmo assim, porque a resposta não é 4xx e não fala em "poucos clipes ok".
 */
class RetryCreditoTest extends TestCase
{
    use RetriesTransientEngineErrors;

    public $tries = 3;

    private int $tentativa = 1;

    public function attempts(): int
    {
        return $this->tentativa;
    }

    private function resposta(string $corpo, int $status = 500): Response
    {
        return new Response(new \GuzzleHttp\Psr7\Response($status, [], $corpo));
    }

    public function test_sem_credito_desiste_na_primeira(): void
    {
        foreach ([
            '{"error":"sem créditos no provedor de IA (saldo 4.1) — recarregue a conta"}',
            '{"error":"not_enough_credits"}',
        ] as $corpo) {
            $this->tentativa = 1;
            // Sem lançar exceção = sem retry: o caller estorna e mostra o motivo.
            $this->assertSame('', $this->engineUrlOrRetry($this->resposta($corpo), 'teste'));
        }
    }

    public function test_erro_transitorio_de_verdade_ainda_retenta(): void
    {
        // A contraprova: 5xx genérico continua ganhando as tentativas que existem pra ele.
        $this->tentativa = 1;
        $this->expectException(TransientEngineException::class);
        $this->engineUrlOrRetry($this->resposta('{"error":"bad gateway"}', 502), 'teste');
    }
}
