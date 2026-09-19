<?php

namespace Tests\Unit;

use App\Services\StoryboardService;
use Tests\TestCase;

/**
 * A folha de storyboard é CONFERÊNCIA: ela existe pra alguém olhar antes de gastar a montagem.
 * Por isso o que se testa aqui é a NORMALIZAÇÃO — as três fontes (filme, animação, decupagem) têm
 * formatos diferentes e precisam cair no mesmo painel, com número, tempo e rótulo preenchidos.
 * Um painel que perde o keyframe não pode sumir da folha: célula vazia é justamente o que se quer
 * enxergar antes de montar.
 */
class StoryboardServiceTest extends TestCase
{
    public function test_timecode_formata_minuto_e_segundo(): void
    {
        $this->assertSame('0:00', StoryboardService::timecode(0));
        $this->assertSame('0:05', StoryboardService::timecode(5));
        $this->assertSame('1:05', StoryboardService::timecode(65));
        // Tempo negativo não existe em timeline — vira 0 em vez de "-0:01".
        $this->assertSame('0:00', StoryboardService::timecode(-3));
    }

    public function test_rotulo_de_shot_conhecido_e_desconhecido(): void
    {
        $this->assertSame('Close-up', StoryboardService::rotuloShot('closeup'));
        $this->assertSame('Contra-plongée', StoryboardService::rotuloShot('contraplongee'));
        // Key nova no engine que ainda não chegou no mapa: vira texto legível, NUNCA célula vazia
        // — a folha continua servindo mesmo desatualizada.
        $this->assertSame('Plano sequencia', StoryboardService::rotuloShot('plano_sequencia'));
        $this->assertSame('', StoryboardService::rotuloShot(null));
    }

    public function test_filme_vira_paineis_numerados_com_tempo_e_quadro_final(): void
    {
        $film = [
            'beats' => [
                ['title' => 'Abertura', 'move_prompt' => 'a câmera desliza pela porta', 'voiceover' => 'Comece aqui.', 'spec' => ['shot' => 'panoramico']],
                ['title' => 'Revelação', 'move_prompt' => 'push-in até o rosto', 'voiceover' => 'Agora você vê.', 'spec' => ['shot' => 'closeup']],
            ],
            'keyframes' => ['https://s3/k0.jpg', 'https://s3/k1.jpg'],
            'final_url' => 'https://s3/final.jpg',
            'final_frame_prompt' => 'o herói de costas para o mar',
        ];

        $p = StoryboardService::painelsDeFilme($film, 5.0);

        $this->assertCount(3, $p, 'o quadro final é um painel — é o que mais se quer conferir');
        $this->assertSame([1, 2, 3], array_column($p, 'index'));
        $this->assertSame(['0:00', '0:05', '0:10'], array_column($p, 'time'));
        $this->assertSame('Plano panorâmico', $p[0]['shot']);
        // A AÇÃO do painel é o movimento, não a redescrição da imagem que já está à vista.
        $this->assertSame('a câmera desliza pela porta', $p[0]['action']);
        $this->assertSame('Comece aqui.', $p[0]['dialogue']);
        $this->assertSame('https://s3/final.jpg', $p[2]['url']);
    }

    public function test_filme_sem_keyframe_ainda_gera_o_painel(): void
    {
        // Keyframe que falhou não pode APAGAR o painel: a folha é onde se descobre o buraco.
        $film = ['beats' => [['title' => 'Cena 1', 'move_prompt' => 'pan'], ['title' => 'Cena 2', 'move_prompt' => 'tilt']], 'keyframes' => ['https://s3/k0.jpg']];

        $p = StoryboardService::painelsDeFilme($film);

        $this->assertCount(2, $p);
        $this->assertSame('', $p[1]['url'], 'sem imagem o painel fica vazio, mas continua na folha');
        $this->assertSame(2, $p[1]['index']);
    }

    public function test_animacao_extrai_a_primeira_fala_com_o_personagem(): void
    {
        $storyboard = [
            [
                'action' => 'Mel corre pelo parque',
                'keyframe_url' => 'https://s3/c0.jpg',
                'spec' => ['shot' => 'medio'],
                'dialogue' => [['character' => 'Mel', 'line' => 'Me pega!']],
            ],
            [
                'action' => 'O dono chega',
                'keyframe_url' => 'https://s3/c1.jpg',
                'spec' => ['shot' => 'americano'],
                'narration' => 'E então tudo mudou.',
            ],
        ];

        $p = StoryboardService::painelsDeAnimacao($storyboard, 4.0);

        $this->assertSame('Mel: Me pega!', $p[0]['dialogue']);
        $this->assertSame('Plano médio', $p[0]['shot']);
        // Cena sem fala cai na narração — a folha nunca fica muda quando existe texto.
        $this->assertSame('E então tudo mudou.', $p[1]['dialogue']);
        $this->assertSame('0:04', $p[1]['time']);
    }

    public function test_compor_sem_paineis_nao_chama_o_compositor(): void
    {
        // Folha vazia não é folha — e não pode virar requisição ao ffmpeg-service à toa.
        $this->assertNull((new StoryboardService)->compor([], 'Filme'));
    }
}
