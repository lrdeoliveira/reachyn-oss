<?php

namespace Tests\Feature;

use App\Services\PublishService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 📐 Instagram só aceita imagem de feed entre 4:5 (0,75) e 1.91:1 — e o formato PADRÃO do produto
 * é 9:16 (0,5625).
 *
 * 🐛 INCIDENTE (2026-07-22): publicar uma história vertical no Instagram morria com o 400 cru do
 * provedor — "Aspect ratio 0.56:1 is outside Instagram's allowed range (0.75 to 1.91)". Agora a
 * imagem é reenquadrada com BARRAS (nunca cortada — igual ao padFit da montagem: barra é erro
 * visível, corte é conteúdo perdido em silêncio).
 */
class InstagramAspectTest extends TestCase
{
    /** 9:16 (0,5625) = o vertical padrão do produto — o caso que quebrou em prod. */
    public function test_vertical_9_16_vira_4_5(): void
    {
        $canvas = PublishService::instagramCanvas(1080, 1920);

        $this->assertNotNull($canvas);
        [$w, $h] = $canvas;
        $this->assertSame(1920, $h, 'a altura não muda — a imagem inteira continua lá');
        $this->assertSame(1536, $w, '1920 × 0,8 = 4:5, o mais alto que o feed aceita');
        $this->assertEqualsWithDelta(0.8, $w / $h, 0.001);
        $this->assertGreaterThanOrEqual(0.75, $w / $h, 'tem de cair DENTRO da faixa do Instagram');
    }

    public function test_panoramica_ganha_barras_em_cima_e_embaixo(): void
    {
        $canvas = PublishService::instagramCanvas(2560, 1080); // 2,37:1 — acima do teto 1,91

        $this->assertNotNull($canvas);
        [$w, $h] = $canvas;
        $this->assertSame(2560, $w, 'a largura não muda');
        $this->assertLessThanOrEqual(1.91, $w / $h);
    }

    #[DataProvider('proporcoesAceitas')]
    public function test_proporcao_ja_aceita_nao_e_tocada(int $w, int $h): void
    {
        $this->assertNull(PublishService::instagramCanvas($w, $h), "{$w}×{$h} já cabe no feed — reprocessar só degradaria a imagem");
    }

    /** @return array<string,array{int,int}> */
    public static function proporcoesAceitas(): array
    {
        return [
            'quadrado 1:1' => [1080, 1080],
            'retrato 4:5' => [1080, 1350],
            'paisagem 16:9' => [1920, 1080],
            'limite 1.91:1' => [1910, 1000],
        ];
    }

    public function test_dimensao_invalida_nao_quebra(): void
    {
        $this->assertNull(PublishService::instagramCanvas(0, 0));
        $this->assertNull(PublishService::instagramCanvas(-5, 100));
    }
}
