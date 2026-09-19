<?php

namespace Tests\Feature;

use App\Jobs\AnimationSceneJob;
use App\Models\AnimationProject;
use App\Models\GenModel;
use App\Services\AnimationFlow;
use Database\Factories\AnimationProjectFactory as Scene;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * ⛔ Regressão do loop infinito de re-despacho (prod 2026-07-15: 6h30 queimando chamada de
 * provedor no projeto 12, ~105 créditos por volta).
 *
 * O ciclo era: AnimationSceneJob falha → estorna o crédito + marca video_status='error' → chama
 * advance() → advance vê a cena como PENDENTE (video_url vazio) e NÃO-rodando (não é 'generating')
 * → re-despacha → falha de novo → … Sem freio: o haltAuto só dispara por falta de saldo, e o
 * estorno do próprio job garantia que o saldo nunca acabasse.
 */
class AnimationAutoLoopTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        // Catálogo mínimo: sem um modelo de vídeo ativo o dispatch morre por outro motivo.
        GenModel::factory()->videoPadrao()->create();
    }

    /** @param  list<array<string,mixed>>  $scenes */
    private function project(array $scenes): AnimationProject
    {
        return AnimationProject::factory()->auto()->animating($scenes)->create();
    }

    public function test_cena_com_erro_nao_e_redespachada_em_loop(): void
    {
        $p = $this->project([Scene::scenePronta(1), Scene::sceneComErro(2)]);

        app(AnimationFlow::class)->advance($p);

        Queue::assertNotPushed(AnimationSceneJob::class);
    }

    public function test_cena_com_erro_para_o_auto_e_expoe_a_causa(): void
    {
        $p = $this->project([Scene::scenePronta(1), Scene::sceneComErro(2)]);

        app(AnimationFlow::class)->advance($p);
        $p->refresh();

        // Sem isto o projeto fica 'animating' pra sempre — "gerando" que nunca termina.
        $this->assertSame('error', $p->status);
        $this->assertFalse((bool) $p->auto);
        $this->assertStringContainsString('falhou ao gerar o vídeo', (string) $p->error);
    }

    /** Regressão inversa: o guard não pode matar o fluxo normal. */
    public function test_cena_pendente_sem_erro_continua_sendo_despachada(): void
    {
        $p = $this->project([Scene::scenePronta(1), Scene::scenePendente(2)]);

        app(AnimationFlow::class)->advance($p);
        $p->refresh();

        Queue::assertPushed(AnimationSceneJob::class);
        $this->assertSame('animating', $p->status);
        $this->assertTrue((bool) $p->auto);
    }

    /** Uma cena já em geração segura o auto — não é erro, e não deve disparar o guard. */
    public function test_cena_gerando_nao_dispara_o_guard_nem_redespacha(): void
    {
        $p = $this->project([Scene::scenePronta(1), Scene::sceneGerando(2)]);

        app(AnimationFlow::class)->advance($p);
        $p->refresh();

        Queue::assertNotPushed(AnimationSceneJob::class);
        $this->assertSame('animating', $p->status, 'cena em geração não é falha — o auto só espera');
        $this->assertTrue((bool) $p->auto);
    }
}
