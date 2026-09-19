<?php

namespace Tests\Unit;

use App\Support\FormatVariant;
use PHPUnit\Framework\TestCase;

/**
 * Reenquadramento de peça aprovada (1 arte → N proporções, custo zero de IA).
 * A invariante que importa: em salto grande NÃO se corta — a peça inteira tem que caber, senão
 * o CTA do rodapé e a cabeça do sujeito somem (foi o bug que criou o pad_fit no ffmpeg-service).
 */
class FormatVariantTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD ausente');
        }
    }

    private function peca(int $w, int $h): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 30, 30, 40));
        imagefilledrectangle($im, (int) ($w * 0.2), (int) ($h * 0.2), (int) ($w * 0.8), (int) ($h * 0.8),
            imagecolorallocate($im, 240, 200, 60));
        ob_start();
        imagejpeg($im, null, 92);

        return (string) ob_get_clean();
    }

    public function test_todo_formato_sai_na_dimensao_exata(): void
    {
        $raw = $this->peca(1080, 1080);
        foreach (FormatVariant::SIZES as $nome => [$w, $h]) {
            $out = FormatVariant::render($raw, $w, $h);
            $this->assertNotNull($out, "formato {$nome} não renderizou");
            [$gw, $gh] = getimagesizefromstring($out);
            $this->assertSame([$w, $h], [$gw, $gh], "formato {$nome} saiu fora da dimensão");
        }
    }

    public function test_corte_pequeno_corta_e_salto_grande_usa_fundo(): void
    {
        // 1:1 → 4:5 perde ~20%: cortar não come o sujeito.
        $this->assertFalse(FormatVariant::precisaDeFundo(1080, 1080, 1080, 1350));
        // 1:1 → 9:16 e 1:1 → 16:9 perdem ~44%: cortar decapita.
        $this->assertTrue(FormatVariant::precisaDeFundo(1080, 1080, 1080, 1920));
        $this->assertTrue(FormatVariant::precisaDeFundo(1080, 1080, 1200, 675));
        // Mesma proporção nunca perde nada.
        $this->assertFalse(FormatVariant::precisaDeFundo(1080, 1080, 1080, 1080));
    }

    public function test_no_salto_grande_a_peca_inteira_continua_visivel(): void
    {
        // O teste que pega o bug de verdade: uma marca no CANTO da peça precisa sobreviver ao
        // 9:16. Com cover+crop ela some; com contain+fundo ela continua lá.
        $w = $h = 600;
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 0, 0, 0));
        $magenta = imagecolorallocate($im, 255, 0, 255);
        imagefilledrectangle($im, 0, 0, 60, 60, $magenta);                    // canto superior esquerdo
        imagefilledrectangle($im, $w - 61, $h - 61, $w - 1, $h - 1, $magenta); // canto inferior direito
        ob_start();
        imagejpeg($im, null, 95);
        $raw = (string) ob_get_clean();

        $out = FormatVariant::render($raw, 1080, 1920); // 1:1 → 9:16
        $this->assertNotNull($out);
        $var = imagecreatefromstring($out);

        $achou = 0;
        for ($x = 0; $x < imagesx($var); $x += 4) {
            for ($y = 0; $y < imagesy($var); $y += 4) {
                $c = imagecolorat($var, $x, $y);
                $r = ($c >> 16) & 0xFF;
                $g = ($c >> 8) & 0xFF;
                $b = $c & 0xFF;
                if ($r > 180 && $b > 180 && $g < 90) { // magenta nítido (o fundo é borrado+escuro)
                    $achou++;
                }
            }
        }
        $this->assertGreaterThan(20, $achou, 'os cantos da peça sumiram no 9:16 — voltou a cortar');
    }

    public function test_entrada_invalida_devolve_null(): void
    {
        $this->assertNull(FormatVariant::render('isto não é imagem', 1080, 1080));
        $this->assertNull(FormatVariant::render($this->peca(100, 100), 0, 1080));
        $this->assertNull(FormatVariant::render($this->peca(100, 100), 1080, 2));
    }

    public function test_dimensoes_batem_com_o_compositor(): void
    {
        // Se divergir de FORMATS (web/app/api/compose/templates.tsx), a mesma peça sai em dois
        // tamanhos dependendo do caminho que gerou.
        $this->assertSame([1080, 1080], FormatVariant::SIZES['feed']);
        $this->assertSame([1080, 1350], FormatVariant::SIZES['retrato']);
        $this->assertSame([1080, 1920], FormatVariant::SIZES['story']);
        $this->assertSame([1200, 675], FormatVariant::SIZES['paisagem']);
    }
}
