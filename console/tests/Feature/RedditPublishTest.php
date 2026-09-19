<?php

namespace Tests\Feature;

use App\Models\Draft;
use App\Models\Tenant;
use App\Models\User;
use App\Scopes\TenantScope;
use App\Services\PublishService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * 👽 REDDIT — as duas regras que a publicação 24 (2026-08-04) descobriu do jeito caro, em produção:
 *
 *  1. TÍTULO. No Reddit o título é obrigatório e, num post de IMAGEM, é o único texto que aparece.
 *     Mandávamos só `content` + mídia: subia a foto, o texto sumia, e a API respondia ok=true.
 *  2. COMUNIDADE. Sem subreddit, o provedor publica no "padrão da conta" — uma comunidade que o
 *     cliente não escolheu. Post no alvo errado não tem desfazer.
 *
 * Os dois defeitos eram SILENCIOSOS: nada no log, `ok=true` no registro. Por isso viram teste.
 */
class RedditPublishTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $marca;

    private User $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marca = Tenant::factory()->create();
        TenantScope::flushActiveTenantCache();
        $this->cliente = User::factory()->create([
            'tenant_id' => $this->marca->id,
            'organization_id' => $this->marca->organization_id,
            'role' => 'client',
        ]);
    }

    private function rascunho(): Draft
    {
        return Draft::factory()->create([
            'tenant_id' => $this->marca->id,
            'texts' => ['reddit' => "## O impacto da IA no consumo de energia\n\nnúmeros que você precisa ver."],
        ]);
    }

    public function test_recusa_publicar_no_reddit_sem_comunidade(): void
    {
        Queue::fake();

        $this->actingAs($this->cliente)->postJson('/api/studio/submit', [
            'draftId' => $this->rascunho()->id,
            'platforms' => ['reddit'],
        ])->assertStatus(422)->assertJson(['ok' => false]);

        // Não publicou nada: a recusa acontece ANTES de enfileirar.
        Queue::assertNothingPushed();
    }

    public function test_aceita_comunidade_e_perfil(): void
    {
        Queue::fake();

        foreach (['r/RedFoxCode', 'u/redfoxcode', 'RedFoxCode'] as $alvo) {
            $this->actingAs($this->cliente)->postJson('/api/studio/submit', [
                'draftId' => $this->rascunho()->id,
                'platforms' => ['reddit'],
                'reddit_subreddit' => $alvo,
            ])->assertOk()->assertJson(['ok' => true]);
        }
    }

    public function test_recusa_alvo_com_formato_invalido(): void
    {
        Queue::fake();

        $this->actingAs($this->cliente)->postJson('/api/studio/submit', [
            'draftId' => $this->rascunho()->id,
            'platforms' => ['reddit'],
            'reddit_subreddit' => 'x/algo-invalido!',
        ])->assertStatus(422);
    }

    /** No protocolo do Reddit o PERFIL é o "subreddit" u_<nome>; a comunidade vai sem prefixo. */
    public function test_traduz_o_alvo_para_o_campo_do_protocolo(): void
    {
        $this->assertSame('RedFoxCode', PublishService::redditSubreddit('r/RedFoxCode'));
        $this->assertSame('RedFoxCode', PublishService::redditSubreddit('RedFoxCode'));
        $this->assertSame('u_redfoxcode', PublishService::redditSubreddit('u/redfoxcode'));
        $this->assertSame('u_redfoxcode', PublishService::redditSubreddit('/u/redfoxcode'));
    }

    /**
     * O caso que sumiu com o texto: peça COM imagem e COM corpo. Sem forceSelf, o Reddit trata o
     * content como título e descarta o resto — foi o que o cliente viu como "só a foto subiu".
     */
    public function test_post_com_imagem_e_corpo_vira_self_post_e_leva_a_imagem_no_corpo(): void
    {
        $texto = "## Título da peça\n\nO corpo que precisa aparecer.";
        $midia = [['type' => 'image', 'url' => 'https://s3.exemplo/foto.jpg']];

        $data = PublishService::redditPlatformData('r/RedFoxCode', $texto, true, PublishService::REDDIT_TEXTO);

        $this->assertTrue($data['forceSelf'], 'com corpo + mídia o post tem que ser self, senão o texto some');
        $this->assertSame('Título da peça', $data['title']);
        $this->assertSame('RedFoxCode', $data['subreddit']);

        $content = PublishService::redditContent($texto, $midia, true);
        $this->assertStringContainsString('O corpo que precisa aparecer.', $content);
        $this->assertStringContainsString('https://s3.exemplo/foto.jpg', $content);
    }

    /**
     * FORMATO IMAGEM: o cliente escolheu a foto no feed sabendo que o texto vira só o título.
     * Sem forceSelf o Reddit publica a imagem nativa — é a outra metade da escolha.
     */
    public function test_formato_imagem_nao_forca_self_post(): void
    {
        $texto = "## Título da peça\n\nO corpo que NÃO vai ser publicado neste formato.";

        $data = PublishService::redditPlatformData('r/RedFoxCode', $texto, true, PublishService::REDDIT_IMAGEM);

        $this->assertArrayNotHasKey('forceSelf', $data);
        $this->assertSame('Título da peça', $data['title']);
    }

    /**
     * DEFAULT = imagem. Não é preferência: é o comportamento que a peça JÁ TINHA (post de imagem
     * nativo, foto no feed). Trocar o default pra texto tirou do ar a única coisa que funcionava.
     * Peça antiga (sem o campo no publish) tem que continuar publicando como sempre publicou.
     */
    public function test_formato_ausente_ou_invalido_mantem_o_comportamento_historico(): void
    {
        $this->assertSame('imagem', PublishService::redditFormato(null));
        $this->assertSame('imagem', PublishService::redditFormato('qualquer-coisa'));
        $this->assertSame('texto', PublishService::redditFormato('texto'));
    }

    /** Sem formato explícito, peça com imagem + corpo NÃO vira self post — a foto continua no feed. */
    public function test_default_publica_a_imagem_e_nao_forca_self(): void
    {
        $data = PublishService::redditPlatformData('r/RedFoxCode', "Título\n\nCorpo longo.", true);

        $this->assertArrayNotHasKey('forceSelf', $data);
    }

    /**
     * A URL da imagem vai CRUA no corpo, não em Markdown de imagem: o Reddit não embute imagem
     * externa em selftext — `![](url)` some e o leitor fica sem nada (relato do cliente:
     * "publicou o texto, mas não levou a imagem").
     */
    public function test_imagem_no_corpo_vai_como_url_crua_nao_markdown(): void
    {
        $content = PublishService::redditContent(
            "Título\n\nCorpo.",
            [['type' => 'image', 'url' => 'https://s3.exemplo/foto.jpg']],
            true
        );

        $this->assertStringContainsString('https://s3.exemplo/foto.jpg', $content);
        $this->assertStringNotContainsString('![', $content, 'markdown de imagem não renderiza no Reddit');
        $this->assertStringNotContainsString('](', $content);
    }

    /**
     * TÍTULO ESCOLHIDO pelo cliente ganha do derivado. No formato imagem ele é o único texto que
     * acompanha a foto, então precisa caber a mensagem — e não só a 1ª linha que a peça tinha.
     */
    public function test_titulo_escrito_pelo_cliente_vence_a_primeira_linha(): void
    {
        $data = PublishService::redditPlatformData(
            'r/RedFoxCode',
            "Linha original da peça\n\ncorpo",
            true,
            PublishService::REDDIT_IMAGEM,
            'Título que eu escrevi'
        );

        $this->assertSame('Título que eu escrevi', $data['title']);
    }

    /** Título vazio mantém o comportamento de sempre: 1ª linha útil do texto da rede. */
    public function test_titulo_vazio_cai_na_primeira_linha(): void
    {
        $data = PublishService::redditPlatformData('r/RedFoxCode', "## Linha original\n\ncorpo", true, PublishService::REDDIT_IMAGEM, '   ');

        $this->assertSame('Linha original', $data['title']);
    }

    /** 300 é limite DURO da API do Reddit: acima disso o post inteiro é recusado. */
    public function test_titulo_do_cliente_e_cortado_no_teto_do_reddit(): void
    {
        Queue::fake(); // sem isto o PublishDraftJob roda de verdade e vai bater no Zernio

        $this->actingAs($this->cliente)->postJson('/api/studio/submit', [
            'draftId' => ($d = $this->rascunho())->id,
            'platforms' => ['reddit'],
            'reddit_subreddit' => 'r/RedFoxCode',
            'reddit_title' => str_repeat('a', 500),
        ])->assertOk();

        $this->assertSame(300, mb_strlen($d->fresh()->publish['reddit_title']));
    }

    /** Sem corpo não há texto a perder: o post de imagem nativo continua sendo o melhor formato. */
    public function test_texto_de_uma_linha_com_imagem_continua_post_de_imagem(): void
    {
        $data = PublishService::redditPlatformData('r/RedFoxCode', 'Só uma chamada curta', true, PublishService::REDDIT_TEXTO);

        $this->assertArrayNotHasKey('forceSelf', $data);
        $this->assertSame('Só uma chamada curta', $data['title']);
    }

    /**
     * O caminho que resolve a peça: post de imagem + corpo no 1º COMENTÁRIO. O endpoint de
     * comentários do provedor aceita responder AO POST (sem commentId) — foi o que destravou.
     */
    public function test_formato_imagem_com_corpo_comenta_o_texto_no_post(): void
    {
        Queue::fake();
        $zernio = \Mockery::mock(\App\Services\ZernioService::class);
        $zernio->shouldReceive('listAccounts')->andReturn([['platform' => 'reddit', '_id' => 'acc1']]);
        $zernio->shouldReceive('createPost')->once()->andReturn(['ok' => true, 'id' => 'post1', 'url' => null]);
        $zernio->shouldReceive('commentOnPost')
            ->once()
            ->with('post1', 'acc1', 'O corpo que precisa aparecer.')
            ->andReturn(['ok' => true, 'commentId' => 'c1']);

        $d = Draft::factory()->create([
            'tenant_id' => $this->marca->id,
            'texts' => ['reddit' => "Título da peça\n\nO corpo que precisa aparecer."],
            'media' => [['kind' => 'image', 'url' => 'https://s3.exemplo/foto.jpg', 'platforms' => []]],
            'publish' => ['platforms' => ['reddit'], 'reddit_subreddit' => 'r/RedFoxCode', 'reddit_formato' => 'imagem'],
        ]);

        $res = (new PublishService($zernio))->publishDraft($d);

        $this->assertTrue($res[0]['ok']);
        $this->assertSame('c1', $res[0]['comment_id']);
    }

    /** No self post o corpo JÁ foi publicado — comentar de novo seria eco. */
    public function test_formato_texto_nao_comenta(): void
    {
        Queue::fake();
        $zernio = \Mockery::mock(\App\Services\ZernioService::class);
        $zernio->shouldReceive('listAccounts')->andReturn([['platform' => 'reddit', '_id' => 'acc1']]);
        $zernio->shouldReceive('createPost')->once()->andReturn(['ok' => true, 'id' => 'post1', 'url' => null]);
        $zernio->shouldNotReceive('commentOnPost');

        $d = Draft::factory()->create([
            'tenant_id' => $this->marca->id,
            'texts' => ['reddit' => "Título\n\nCorpo."],
            'media' => [['kind' => 'image', 'url' => 'https://s3.exemplo/foto.jpg', 'platforms' => []]],
            'publish' => ['platforms' => ['reddit'], 'reddit_subreddit' => 'r/RedFoxCode', 'reddit_formato' => 'texto'],
        ]);

        (new PublishService($zernio))->publishDraft($d);
    }

    /** Comentário é BEST-EFFORT: o post já está no ar, falhar aqui não pode invalidar a peça. */
    public function test_falha_no_comentario_nao_derruba_a_publicacao(): void
    {
        Queue::fake();
        $zernio = \Mockery::mock(\App\Services\ZernioService::class);
        $zernio->shouldReceive('listAccounts')->andReturn([['platform' => 'reddit', '_id' => 'acc1']]);
        $zernio->shouldReceive('createPost')->once()->andReturn(['ok' => true, 'id' => 'post1', 'url' => null]);
        $zernio->shouldReceive('commentOnPost')->once()->andReturn(['ok' => false, 'detail' => 'rate limit']);

        $d = Draft::factory()->create([
            'tenant_id' => $this->marca->id,
            'texts' => ['reddit' => "Título\n\nCorpo."],
            'media' => [['kind' => 'image', 'url' => 'https://s3.exemplo/foto.jpg', 'platforms' => []]],
            'publish' => ['platforms' => ['reddit'], 'reddit_subreddit' => 'r/RedFoxCode', 'reddit_formato' => 'imagem'],
        ]);

        $res = (new PublishService($zernio))->publishDraft($d);

        $this->assertTrue($res[0]['ok'], 'a publicação continua válida');
        $this->assertSame('rate limit', $res[0]['comment_error']);
    }

    /**
     * 🎞️ A arte vira VIDEOGIF nativo: é o único caminho em que a mídia sai do nosso S3 e passa a
     * viver no Reddit (o provedor faz upload nativo só de vídeo). O `videogif` mantém a cara de
     * imagem — loop silencioso, sem player.
     */
    public function test_formato_imagem_converte_a_arte_em_videogif_nativo(): void
    {
        Queue::fake();
        Http::fake(['*/camclip' => Http::response(['url' => 'https://s3.exemplo/clipe.mp4'])]);

        $zernio = \Mockery::mock(\App\Services\ZernioService::class);
        $zernio->shouldReceive('listAccounts')->andReturn([['platform' => 'reddit', '_id' => 'acc1']]);
        $zernio->shouldReceive('commentOnPost')->andReturn(['ok' => true, 'commentId' => 'c1']);
        $zernio->shouldReceive('createPost')->once()
            ->withArgs(function ($plat, $acc, $content, $title, $media, $now, $data) {
                return $plat === 'reddit'
                    && $media === [['type' => 'video', 'url' => 'https://s3.exemplo/clipe.mp4']]
                    && ($data['videogif'] ?? false) === true;
            })
            ->andReturn(['ok' => true, 'id' => 'post1', 'url' => null]);

        $d = Draft::factory()->create([
            'tenant_id' => $this->marca->id,
            'texts' => ['reddit' => "Título\n\nCorpo."],
            'media' => [['kind' => 'image', 'url' => 'https://s3.exemplo/foto.jpg', 'platforms' => []]],
            'publish' => ['platforms' => ['reddit'], 'reddit_subreddit' => 'r/RedFoxCode', 'reddit_formato' => 'imagem'],
        ]);

        (new PublishService($zernio))->publishDraft($d);
    }

    /** Conversão é best-effort: se o ffmpeg cair, publica a imagem como antes — não perde a peça. */
    public function test_falha_na_conversao_publica_a_imagem_normal(): void
    {
        Queue::fake();
        Http::fake(['*/camclip' => Http::response(null, 500)]);

        $zernio = \Mockery::mock(\App\Services\ZernioService::class);
        $zernio->shouldReceive('listAccounts')->andReturn([['platform' => 'reddit', '_id' => 'acc1']]);
        $zernio->shouldReceive('commentOnPost')->andReturn(['ok' => true, 'commentId' => 'c1']);
        $zernio->shouldReceive('createPost')->once()
            ->withArgs(fn ($plat, $acc, $content, $title, $media, $now, $data) => ($media[0]['type'] ?? '') === 'image' && ! isset($data['videogif']))
            ->andReturn(['ok' => true, 'id' => 'post1', 'url' => null]);

        $d = Draft::factory()->create([
            'tenant_id' => $this->marca->id,
            'texts' => ['reddit' => "Título\n\nCorpo."],
            'media' => [['kind' => 'image', 'url' => 'https://s3.exemplo/foto.jpg', 'platforms' => []]],
            'publish' => ['platforms' => ['reddit'], 'reddit_subreddit' => 'r/RedFoxCode', 'reddit_formato' => 'imagem'],
        ]);

        (new PublishService($zernio))->publishDraft($d);
    }

    public function test_corpo_e_tudo_depois_da_primeira_linha_util(): void
    {
        $this->assertSame(
            "linha 2\nlinha 3",
            PublishService::redditBody("# título\nlinha 2\nlinha 3")
        );
        $this->assertSame('', PublishService::redditBody('só o título'));
    }

    public function test_titulo_do_reddit_tira_markdown_e_pega_a_primeira_linha_real(): void
    {
        $this->assertSame(
            'O impacto da IA no consumo de energia',
            PublishService::redditTitle("## O impacto da IA no consumo de energia\n\nnúmeros que você precisa ver.")
        );

        // Ênfase inline também sai — "**Chegou**" no título vira asterisco literal na timeline.
        $this->assertSame('Chegou o Opus 5', PublishService::redditTitle('**Chegou** o Opus 5'));

        // Linhas vazias/só marcação são puladas até achar texto de verdade.
        $this->assertSame('Texto real', PublishService::redditTitle("\n\n#\n\nTexto real"));
    }

    public function test_titulo_do_reddit_respeita_o_teto_de_300(): void
    {
        $titulo = PublishService::redditTitle(str_repeat('palavra ', 60)); // ~480 chars

        $this->assertLessThanOrEqual(300, mb_strlen($titulo), 'acima de 300 o Reddit recusa o post');
        $this->assertStringEndsWith('…', $titulo);
    }

    public function test_titulo_cai_no_fallback_quando_o_texto_nao_tem_linha_util(): void
    {
        $this->assertSame('Minha marca', PublishService::redditTitle("###\n**\n", 'Minha marca'));
    }
}
