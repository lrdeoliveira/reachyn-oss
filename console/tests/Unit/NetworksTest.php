<?php

namespace Tests\Unit;

use App\Services\ZernioService;
use App\Support\Networks;
use PHPUnit\Framework\TestCase;

/**
 * Fonte única das redes. Estes testes existem porque a lista estava copiada em 12 lugares: o
 * risco real não é errar a lista, é ela DIVERGIR de novo — uma rede que some de um dos filtros
 * não dá erro, só deixa de publicar em silêncio.
 */
class NetworksTest extends TestCase
{
    public function test_todas_as_redes_tem_chave_rotulo_e_icone(): void
    {
        $this->assertCount(11, Networks::ALL);
        foreach (Networks::ALL as $n) {
            $this->assertNotSame('', trim($n['key'] ?? ''));
            $this->assertNotSame('', trim($n['label'] ?? ''));
            $this->assertNotSame('', trim($n['icon'] ?? ''));
        }
        $keys = Networks::keys();
        $this->assertSame($keys, array_unique($keys), 'chave duplicada no catálogo');
    }

    public function test_only_filtra_preserva_ordem_e_remove_duplicata(): void
    {
        $this->assertSame(
            ['tiktok', 'instagram'],
            Networks::only(['tiktok', 'instagram', 'tiktok', 'nao_existe', 'BLOG'])
        );
        $this->assertSame([], Networks::only('nao é array'));
        $this->assertSame([], Networks::only(null));
        $this->assertSame([], Networks::only([['aninhado'], 123, true]));
    }

    public function test_blog_nao_e_publicavel_mas_e_filtravel(): void
    {
        // 'blog' é legado: o publish pula, mas publicações antigas ficaram gravadas com ele e
        // precisam continuar filtráveis na listagem.
        $this->assertSame([], Networks::only(['blog']));
        $this->assertNotContains('blog', Networks::keys());
        $this->assertContains('blog', Networks::filterable());
        $this->assertCount(12, Networks::filterable());
    }

    public function test_zernio_continua_espelhando_o_catalogo(): void
    {
        // O alias existe por retrocompat (ConnectionController consome). Se alguém reintroduzir a
        // lista lá, este teste quebra.
        $this->assertSame(Networks::ALL, ZernioService::NETWORKS);
    }

    public function test_toda_rede_tem_formato_padrao(): void
    {
        // Sem isto, uma rede nova cairia no fallback 'feed' sem ninguém notar.
        foreach (Networks::keys() as $k) {
            $this->assertArrayHasKey($k, Networks::DEFAULT_FORMAT, "rede {$k} sem formato padrão");
        }
        $validos = ['feed', 'retrato', 'story', 'paisagem']; // chaves de FORMATS no compositor
        foreach (Networks::DEFAULT_FORMAT as $rede => $fmt) {
            $this->assertContains($fmt, $validos, "formato inválido em {$rede}");
        }
        $this->assertSame('story', Networks::formatFor('tiktok'));
        $this->assertSame('feed', Networks::formatFor('rede_inexistente')); // fallback
    }
}
