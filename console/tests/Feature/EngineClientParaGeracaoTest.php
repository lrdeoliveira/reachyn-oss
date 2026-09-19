<?php

namespace Tests\Feature;

use App\Jobs\GenerateElementJob;
use App\Jobs\GenerateShotFrameJob;
use App\Jobs\RenderSceneImageJob;
use App\Support\EngineClient;
use Tests\TestCase;

/**
 * 🐛 REGRESSÃO que este teste tranca: `EngineClient::paraGeracao()` era CHAMADO por três jobs
 * (RenderSceneImageJob, GenerateShotFrameJob, GenerateElementJob) e simplesmente NÃO EXISTIA na
 * classe — só havia `make()`. Todo quadro de cena, sprite ou elemento enfileirado morria com
 * "Call to undefined method": reservava o crédito, falhava as 3 tentativas e caía no estorno.
 *
 * Não aparecia em `failed_jobs` porque o caminho ainda não tinha sido exercitado em produção —
 * ou seja, era uma bomba armada esperando o primeiro uso real da aba.
 *
 * O teste assere as DUAS pontas: que o método existe com a assinatura que os chamadores usam,
 * e que cada job de fato consegue chamá-lo.
 */
class EngineClientParaGeracaoTest extends TestCase
{
    public function test_o_metodo_existe_com_a_assinatura_que_os_jobs_usam(): void
    {
        $this->assertTrue(
            method_exists(EngineClient::class, 'paraGeracao'),
            'Os jobs de geração chamam paraGeracao() — sem ele, todo quadro enfileirado morre.'
        );

        $m = new \ReflectionMethod(EngineClient::class, 'paraGeracao');
        $this->assertTrue($m->isStatic(), 'É chamado estaticamente nos jobs.');

        // Os dois modos de chamada em produção: só payload, e payload + timeout explícito.
        $this->assertSame(2, $m->getNumberOfParameters());
        $this->assertSame(1, $m->getNumberOfRequiredParameters(), 'O timeout é opcional (RenderSceneImageJob passa 540; os outros omitem).');
    }

    public function test_timeout_explicito_vence_a_derivacao(): void
    {
        $r = EngineClient::paraGeracao(['model' => 'img-local-pose'], 540);

        $this->assertSame(540, $this->timeoutDe($r));
    }

    public function test_motor_do_estudio_local_ganha_timeout_maior_que_o_da_nuvem(): void
    {
        // O teto real não é da rota, é de ONDE o motor roda: o estúdio local leva minutos.
        $local = $this->timeoutDe(EngineClient::paraGeracao(['model' => 'img-local-pose']));
        $nuvem = $this->timeoutDe(EngineClient::paraGeracao(['model' => 'img-realista']));

        $this->assertGreaterThan(
            $nuvem,
            $local,
            'Motor local precisa esperar mais — senão o timeout corta uma geração que ia terminar.'
        );
    }

    public function test_payload_sem_pista_de_modelo_cai_no_padrao(): void
    {
        $this->assertSame(
            EngineClient::TIMEOUT_PADRAO,
            $this->timeoutDe(EngineClient::paraGeracao([]))
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('jobsQueGeram')]
    public function test_o_job_consegue_montar_o_cliente(string $job): void
    {
        // Não roda o handle() inteiro (bateria no engine); prova só que a chamada resolve —
        // que é exatamente o que faltava e derrubava os três.
        $this->assertTrue(class_exists($job));
        $this->assertInstanceOf(
            \Illuminate\Http\Client\PendingRequest::class,
            EngineClient::paraGeracao(['model' => 'img-realista'])
        );
    }

    public static function jobsQueGeram(): array
    {
        return [
            'quadro de cena' => [RenderSceneImageJob::class],
            'quadro do plano' => [GenerateShotFrameJob::class],
            'elemento' => [GenerateElementJob::class],
        ];
    }

    /** O timeout vive no array `options` do PendingRequest — leitura por reflexão, só no teste. */
    private function timeoutDe(\Illuminate\Http\Client\PendingRequest $r): int
    {
        $p = new \ReflectionProperty($r, 'options');
        $p->setAccessible(true);

        return (int) (($p->getValue($r)['timeout']) ?? 0);
    }
}
