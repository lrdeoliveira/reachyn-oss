<?php

namespace Tests\Feature;

use App\Models\ProviderKey;
use App\Services\ZernioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 🔗 O LINK DA PUBLICAÇÃO — o defeito mais silencioso que este código já teve.
 *
 * O campo do permalink no Zernio é `platformPostUrl`, DENTRO de cada item de `platforms[]`.
 * Procurávamos por `permalink`, `postUrl` e `url` — cinco nomes, nenhum deles o certo. Resultado:
 * TODA publicação, desde sempre, era gravada com `url = null`. Nenhum erro, nenhum log, `ok=true`
 * no registro: a peça ia pro ar de verdade e a tela não tinha nada pra clicar. Do lado do cliente
 * isso se descreve como "publiquei e sumiu" (caso real 2026-08-04, vídeo que estava no YouTube o
 * tempo todo).
 *
 * O segundo tempo do mesmo defeito: publicar é ASSÍNCRONO. O create responde 200 quando o post
 * entra na fila; o link só existe quando a rede aceita o upload — 9s no vídeo do caso real. Ler o
 * campo certo cedo demais devolve null igual.
 */
class ZernioPermalinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ProviderKey::create(['provider' => 'zernio', 'api_key' => 'k_teste']);
        // A espera entre releituras é real em produção; no teste importa o COMPORTAMENTO (quantas
        // vezes relê, quando desiste), não o relógio.
        config(['services.zernio.permalink_espera' => 0]);
    }

    /** Resposta do create já com o link pronto (rede rápida: texto, imagem). */
    private function postComLink(?string $url, string $status = 'published'): array
    {
        $plat = ['platform' => 'youtube', 'status' => $status];
        if ($url !== null) {
            $plat['platformPostUrl'] = $url;
        }

        return ['post' => ['_id' => 'p1', 'status' => $status, 'platforms' => [$plat]]];
    }

    public function test_le_o_campo_platform_post_url(): void
    {
        Http::fake(['*/posts' => Http::response($this->postComLink('https://youtu.be/abc'))]);

        $r = app(ZernioService::class)->createPost('youtube', 'acc1', 'oi');

        $this->assertTrue($r['ok']);
        $this->assertSame('https://youtu.be/abc', $r['url'], 'o link tem que vir do platformPostUrl');
    }

    public function test_espera_o_link_quando_a_rede_ainda_esta_subindo(): void
    {
        // O create responde SEM link (upload em andamento) e a releitura, já publicada, traz.
        Http::fake([
            '*/v1/posts' => Http::response($this->postComLink(null, 'publishing')),
            '*/v1/posts/p1' => Http::response($this->postComLink('https://youtu.be/tarde')),
        ]);

        $r = app(ZernioService::class)->createPost('youtube', 'acc1', 'oi');

        $this->assertSame('https://youtu.be/tarde', $r['url']);
    }

    public function test_desiste_quando_a_rede_recusou(): void
    {
        // Post que falhou DEPOIS do aceite não vai gerar link nenhum: esperar é só tempo perdido.
        Http::fake([
            '*/v1/posts' => Http::response($this->postComLink(null, 'publishing')),
            '*/v1/posts/p1' => Http::response($this->postComLink(null, 'failed')),
        ]);

        $r = app(ZernioService::class)->createPost('youtube', 'acc1', 'oi');

        $this->assertNull($r['url']);
        $this->assertTrue($r['ok'], 'a publicação em si não é invalidada pela ausência de link');
    }

    public function test_sem_link_a_publicacao_continua_valida(): void
    {
        // INVARIANTE: o post JÁ ESTÁ no ar quando buscamos o link. Falha na leitura (ou link que
        // nunca aparece) não pode transformar uma publicação bem-sucedida em erro.
        Http::fake([
            '*/v1/posts' => Http::response($this->postComLink(null, 'publishing')),
            '*/v1/posts/p1' => Http::response('', 500),
        ]);

        $r = app(ZernioService::class)->createPost('youtube', 'acc1', 'oi');

        $this->assertTrue($r['ok']);
        $this->assertSame('p1', $r['id']);
        $this->assertNull($r['url']);
    }

    public function test_nomes_antigos_continuam_aceitos(): void
    {
        // Defesa em camada: se alguma rede devolver o link no formato antigo, ainda pegamos.
        Http::fake(['*/posts' => Http::response([
            'post' => ['_id' => 'p1', 'platforms' => [['platform' => 'x', 'postUrl' => 'https://x.com/1']]],
        ])]);

        $this->assertSame('https://x.com/1', app(ZernioService::class)->createPost('x', 'acc1', 'oi')['url']);
    }

    public function test_reddit_volta_relativo_e_precisa_virar_absoluto(): void
    {
        // O Reddit devolve o permalink como CAMINHO. Gravado cru, o href do arquivo apontaria pro
        // NOSSO domínio e o cliente clicaria num 404 — pior que não ter link, porque parece funcionar.
        Http::fake(['*/posts' => Http::response(['post' => ['_id' => 'p1', 'platforms' => [
            ['platform' => 'reddit', 'platformPostUrl' => '/r/redfoxcode/comments/1vf860s/x/'],
        ]]])]);

        $this->assertSame(
            'https://www.reddit.com/r/redfoxcode/comments/1vf860s/x/',
            app(ZernioService::class)->createPost('reddit', 'acc1', 'oi')['url']
        );
    }

    public function test_relativo_de_rede_desconhecida_nao_vira_link_errado(): void
    {
        // Sem host conhecido, melhor SEM link do que um link que leva pro lugar errado.
        Http::fake([
            '*/v1/posts' => Http::response(['post' => ['_id' => 'p1', 'platforms' => [
                ['platform' => 'rede_nova', 'platformPostUrl' => '/algum/caminho'],
            ]]]),
            '*/v1/posts/p1' => Http::response(['post' => ['_id' => 'p1', 'status' => 'failed', 'platforms' => []]]),
        ]);

        $this->assertNull(app(ZernioService::class)->createPost('rede_nova', 'acc1', 'oi')['url']);
    }
}
