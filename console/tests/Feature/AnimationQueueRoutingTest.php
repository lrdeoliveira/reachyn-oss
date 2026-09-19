<?php

namespace Tests\Feature;

use App\Jobs\AnimationAssembleJob;
use App\Jobs\AnimationElementJob;
use App\Jobs\AnimationFrameJob;
use App\Jobs\AnimationParseJob;
use App\Jobs\AnimationSceneJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 🚦 Roteamento de fila do Estúdio de Animação.
 *
 * O desenho despacha N cenas de uma vez, cada uma segurando um worker por 8-14min; na fila única
 * isso empurrava todo o resto pra trás (publicar um post é lento também — upload sequencial por
 * rede — e podia esperar o desenho inteiro). Por isso a animação tem fila e pool próprios.
 *
 * ⚠️ Este teste existe porque errar aqui é SILENCIOSO: job numa fila que nenhum worker consome
 * não estoura, não loga — fica em `jobs` pra sempre e o desenho "trava" sem explicação. Se alguém
 * renomear a fila, tem de renomear TAMBÉM o --queue dos workers (docker-compose.yml: worker-anim;
 * docker-compose.dev.yml: worker) — e é isso que a lista abaixo protege.
 */
class AnimationQueueRoutingTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<array{0:class-string}> */
    public static function jobsDeAnimacao(): array
    {
        return [
            [AnimationParseJob::class],
            [AnimationElementJob::class],
            [AnimationFrameJob::class],
            [AnimationSceneJob::class],
            [AnimationAssembleJob::class],
        ];
    }

    /** @param  class-string  $job */
    #[DataProvider('jobsDeAnimacao')]
    public function test_job_de_animacao_vai_pra_fila_animation(string $job): void
    {
        Queue::fake();

        // Construtor vazio de propósito: o onQueue mora no construtor (não no dispatch), então
        // vale pra QUALQUER caller — inclusive um retry manual pelo controller.
        $r = new \ReflectionClass($job);
        $args = array_map(fn ($p) => $p->isDefaultValueAvailable() ? $p->getDefaultValue() : $this->fake($p), $r->getConstructor()->getParameters());
        $instancia = $r->newInstanceArgs($args);

        dispatch($instancia);

        Queue::assertPushedOn('animation', $job);
    }

    private function fake(\ReflectionParameter $p): mixed
    {
        $t = $p->getType() instanceof \ReflectionNamedType ? $p->getType()->getName() : 'string';

        return match ($t) {
            'int' => 1,
            'float' => 1.0,
            'bool' => false,
            'array' => [],
            default => '',
        };
    }
}
