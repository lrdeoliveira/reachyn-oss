<?php

namespace Tests\Feature;

use App\Models\AnimationProject;
use App\Models\Organization;
use App\Models\Tenant;
use App\Services\AnimationFlow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 🔒 Trava de identidade da cena — o que NÃO pode mudar enquanto ela se move.
 *
 * Nasceu do projeto 19 (2026-07-22/23): o keyframe estava certo em todos os casos e o clipe
 * inventou mesmo assim — a peça mágica virou um boneco 3D, o cristal ganhou um rosto humano e a
 * protagonista trocou de cara entre cenas. O prompt do clipe só dizia o que DEVIA acontecer
 * (movimento + câmera) e nada sobre o que devia PERMANECER.
 *
 * O outro lado do mesmo problema: consertar isso à mão exagerando na âncora ("locked-off camera",
 * "o único movimento é um pulso de luz") MATOU a cena — o movimento medido caiu de 1,40 para 0,63
 * e o gesto nunca completou. Por isso a trava fala de identidade e NUNCA de câmera ou de ritmo.
 */
class SceneryLockTest extends TestCase
{
    use RefreshDatabase;

    private function projeto(array $extra = []): AnimationProject
    {
        $t = Tenant::factory()->for(Organization::factory()->paying('studio', 10000))->create();

        return AnimationProject::factory()->create(array_merge([
            'tenant_id' => $t->id,
            'elements' => [
                'characters' => [
                    ['name' => 'Bruxa', 'visual_prompt' => 'mulher de capa escura', 'ref_url' => 'https://s3.example.com/public/reachyn/image/c.jpg', 'status' => 'ready'],
                ],
                'locations' => [
                    ['name' => 'Salão do trono', 'visual_prompt' => 'salão gótico de pedra, vitrais altos', 'ref_url' => 'https://s3.example.com/public/reachyn/image/l.jpg', 'status' => 'ready'],
                ],
                'props' => [],
            ],
            'storyboard' => [[
                'title' => 'Cena', 'action' => 'ela caminha', 'image_prompt' => 'ela caminha',
                'video_prompt' => 'The camera holds still on a locked-off frame, only the scene breathes.',
                'characters' => ['Bruxa'], 'location' => 'Salão do trono',
                'keyframe_url' => 'https://s3.example.com/public/reachyn/image/kf.jpg',
                'keyframe_status' => 'ready', 'audio_url' => '', 'video_url' => '', 'video_status' => '',
            ]],
        ], $extra));
    }

    /** O clipe leva a trava de identidade — era o que faltava quando o cristal virou boneco. */
    public function test_prompt_do_clipe_trava_identidade(): void
    {
        $p = $this->projeto();
        [, $clip] = app(AnimationFlow::class)->scenePayloads($p, 0);

        $this->assertStringContainsString('Keep every subject and the setting exactly', $clip['prompt']);
        $this->assertStringContainsString('turns into a different person, creature, character or object', $clip['prompt']);
        $this->assertStringContainsString('no object grows a face, eyes or limbs', $clip['prompt']);
    }

    /** Personagem e cenário da cena entram nominalmente na trava. */
    public function test_trava_cita_personagem_e_cenario_da_cena(): void
    {
        $p = $this->projeto();
        [, $clip] = app(AnimationFlow::class)->scenePayloads($p, 0);

        $this->assertStringContainsString('Bruxa', $clip['prompt']);
        $this->assertStringContainsString('Salão do trono', $clip['prompt']);
        $this->assertStringContainsString('salão gótico de pedra', $clip['prompt'], 'a descrição do cenário ancora arquitetura e luz');
    }

    /** A trava NÃO pode congelar a cena: nada sobre câmera parada ou "só a luz se move". */
    public function test_trava_nao_congela_a_cena(): void
    {
        $p = $this->projeto();
        [, $clip] = app(AnimationFlow::class)->scenePayloads($p, 0);

        foreach (['locked-off', 'holds still', 'only movement', 'do not move', 'static camera', 'no motion'] as $proibido) {
            $this->assertStringNotContainsString($proibido, mb_strtolower(str_replace(
                // o texto do OPERADOR (video_prompt) pode conter isso; a trava é o que vem DEPOIS dele
                mb_strtolower((string) $p->storyboard[0]['video_prompt']), '', mb_strtolower($clip['prompt'])
            )), "a trava não pode inibir movimento ($proibido)");
        }
        // E a instrução de movimento continua lá, na frente da trava.
        $this->assertStringContainsString('moves and acts out the scene naturally', $clip['prompt']);
    }

    /**
     * O LOCK TEXTUAL do cenário sobrevive ao cap de 4 refs.
     *
     * Antes um `if` só exigia ref_url E vaga no cap para imagem e texto: cena cheia de personagens
     * estourava o cap e o cenário sumia inteiro — inclusive a âncora textual, que não ocupa slot.
     */
    public function test_lock_textual_do_cenario_sobrevive_ao_cap_de_refs(): void
    {
        $p = $this->projeto([
            'elements' => [
                'characters' => array_map(fn ($n) => [
                    'name' => "P$n", 'visual_prompt' => "personagem $n",
                    'ref_url' => "https://s3.example.com/public/reachyn/image/c$n.jpg", 'status' => 'ready',
                ], [1, 2, 3]),
                'locations' => [
                    ['name' => 'Salão', 'visual_prompt' => 'salão gótico de pedra', 'ref_url' => 'https://s3.example.com/public/reachyn/image/l.jpg', 'status' => 'ready'],
                ],
                'props' => [],
            ],
            'storyboard' => [[
                'title' => 'Cheia', 'action' => 'x', 'image_prompt' => 'x', 'video_prompt' => '',
                'characters' => ['P1', 'P2', 'P3'], 'location' => 'Salão',
                'keyframe_url' => '', 'keyframe_status' => '', 'ref_url' => 'https://s3.example.com/public/reachyn/image/own.jpg',
                'audio_url' => '', 'video_url' => '', 'video_status' => '',
            ]],
        ]);

        $payload = app(AnimationFlow::class)->framePayload($p, 0);

        $this->assertLessThanOrEqual(4, count($payload['imageUrls'] ?? []), 'o cap de refs continua valendo');
        $this->assertStringContainsString('LOCATION LOCK', $payload['prompt'], 'o texto do cenário não pode cair junto com a imagem');
        $this->assertStringContainsString('salão gótico de pedra', $payload['prompt']);
    }

    /**
     * O OBJETO da cena entra nominalmente na trava.
     *
     * Personagem e cenário sozinhos não bastaram: no teste do projeto 20 o cristal do pedestal
     * virou uma peça metálica dourada no meio do clipe, mesmo com a trava genérica dizendo
     * "same objects". Objeto pequeno e isolado no quadro é o alvo preferido da alucinação — e o
     * que funcionou à mão foi nomear o objeto e o que ele É.
     */
    public function test_trava_cita_os_objetos_da_cena(): void
    {
        $t = Tenant::factory()->for(Organization::factory()->paying('studio', 10000))->create();
        $p = AnimationProject::factory()->create([
            'tenant_id' => $t->id,
            'elements' => [
                'characters' => [], 'locations' => [],
                'props' => [
                    ['name' => 'Cristal Azul', 'visual_prompt' => 'a fist-sized faceted crystal of deep sapphire blue', 'ref_url' => '', 'status' => 'ready'],
                ],
            ],
            'storyboard' => [[
                'title' => 'Close no cristal', 'action' => 'ela toca o cristal', 'image_prompt' => 'x',
                'video_prompt' => '', 'characters' => [], 'location' => '', 'props' => ['Cristal Azul'],
                'keyframe_url' => 'https://s3.example.com/public/reachyn/image/kf.jpg',
                'keyframe_status' => 'ready', 'audio_url' => '', 'video_url' => '', 'video_status' => '',
            ]],
        ]);

        [, $clip] = app(AnimationFlow::class)->scenePayloads($p, 0);

        $this->assertStringContainsString('Cristal Azul', $clip['prompt']);
        $this->assertStringContainsString('faceted crystal of deep sapphire blue', $clip['prompt'], 'a descrição do objeto ancora forma e material');
        $this->assertStringContainsString('never morph into a different object', $clip['prompt']);
    }

    /** Cena sem cenário definido não inventa trava de ambiente. */
    public function test_cena_sem_cenario_nao_cita_ambiente(): void
    {
        $p = $this->projeto([
            'storyboard' => [[
                'title' => 'Sem local', 'action' => 'x', 'image_prompt' => 'x', 'video_prompt' => '',
                'characters' => ['Bruxa'], 'location' => '',
                'keyframe_url' => 'https://s3.example.com/public/reachyn/image/kf.jpg',
                'keyframe_status' => 'ready', 'audio_url' => '', 'video_url' => '', 'video_status' => '',
            ]],
        ]);
        [, $clip] = app(AnimationFlow::class)->scenePayloads($p, 0);

        $this->assertStringNotContainsString('The setting stays', $clip['prompt']);
        $this->assertStringContainsString('Keep every subject', $clip['prompt'], 'a trava geral continua valendo');
    }
}
