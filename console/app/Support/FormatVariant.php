<?php

namespace App\Support;

/**
 * Reenquadra uma peça APROVADA para outra proporção, sem gerar de novo.
 *
 * Serve o caso "1 arte → N redes": hoje o cliente gera uma vez por formato e paga N vezes, mesmo
 * quando a arte é a mesma. Aqui o custo de IA é ZERO — é GD puro, igual ao BoardImage.
 *
 * A regra central é NÃO cortar cego. O `pad_fit` do ffmpeg-service (server.py) nasceu justamente
 * disso: retrato 3:4 indo pra 16:9 cortava o rosto. Então:
 *   - corte pequeno  → cover+crop centrado (a perda não come o sujeito);
 *   - corte agressivo → a peça inteira cabe (contain) sobre um fundo dela mesma, ampliado e
 *     borrado. Nada do conteúdo se perde, e não fica a tarja preta que denuncia reaproveitamento.
 *
 * Tudo GD, sem I/O de rede (o caller baixa e persiste).
 */
final class FormatVariant
{
    /**
     * Dimensões de cada formato. Espelha FORMATS em web/app/api/compose/templates.tsx — se mudar
     * lá, muda aqui (o compositor e o reenquadramento têm que produzir o mesmo tamanho, senão a
     * mesma peça sai em dois tamanhos dependendo do caminho).
     */
    public const SIZES = [
        'feed' => [1080, 1080],      // 1:1
        'retrato' => [1080, 1350],   // 4:5
        'story' => [1080, 1920],     // 9:16
        'paisagem' => [1200, 675],   // 16:9
    ];

    /**
     * Acima desta perda linear, cortar comeria composição demais e passamos a conter+borrar.
     * 0.25 = um quarto da dimensão. Calibrado nos saltos reais: 1:1 → 4:5 perde ~20% (corta bem);
     * 1:1 → 9:16 e 1:1 → 16:9 perdem ~44% (cortar decapita o sujeito).
     */
    private const MAX_PERDA = 0.25;

    /** Quantas passadas de blur no fundo. 6 já borra o suficiente pra não competir com a frente. */
    private const BLUR_PASSES = 6;

    /**
     * @return string|null JPEG da variante, ou null se o GD faltar / a imagem não decodificar.
     */
    public static function render(string $raw, int $outW, int $outH): ?string
    {
        if (! function_exists('imagecreatefromstring') || $outW < 8 || $outH < 8) {
            return null;
        }
        $src = @imagecreatefromstring($raw);
        if (! $src) {
            return null;
        }
        $sw = imagesx($src);
        $sh = imagesy($src);
        if ($sw < 1 || $sh < 1) {
            return null;
        }

        // Cover: escala pela dimensão que falta. A perda é o quanto sobra na OUTRA dimensão.
        $escalaCover = max($outW / $sw, $outH / $sh);
        $larguraCoberta = $sw * $escalaCover;
        $alturaCoberta = $sh * $escalaCover;
        $perda = max(
            $larguraCoberta > 0 ? ($larguraCoberta - $outW) / $larguraCoberta : 0,
            $alturaCoberta > 0 ? ($alturaCoberta - $outH) / $alturaCoberta : 0,
        );

        $dst = imagecreatetruecolor($outW, $outH);

        if ($perda <= self::MAX_PERDA) {
            // Corte aceitável: cover centrado, sem fundo.
            $janelaW = (int) round($outW / $escalaCover);
            $janelaH = (int) round($outH / $escalaCover);
            imagecopyresampled(
                $dst, $src,
                0, 0,
                (int) round(($sw - $janelaW) / 2), (int) round(($sh - $janelaH) / 2),
                $outW, $outH, $janelaW, $janelaH
            );
        } else {
            // 1) fundo: a própria peça cobrindo o quadro, depois borrada.
            $janelaW = (int) round($outW / $escalaCover);
            $janelaH = (int) round($outH / $escalaCover);
            imagecopyresampled(
                $dst, $src,
                0, 0,
                (int) round(($sw - $janelaW) / 2), (int) round(($sh - $janelaH) / 2),
                $outW, $outH, $janelaW, $janelaH
            );
            for ($i = 0; $i < self::BLUR_PASSES; $i++) {
                imagefilter($dst, IMG_FILTER_GAUSSIAN_BLUR);
            }
            // Escurece um tico: o fundo tem que ficar atrás, não competir com a peça.
            imagefilter($dst, IMG_FILTER_BRIGHTNESS, -25);

            // 2) frente: a peça INTEIRA (contain), centrada. Nada do conteúdo se perde.
            $escalaContain = min($outW / $sw, $outH / $sh);
            $fw = max(1, (int) round($sw * $escalaContain));
            $fh = max(1, (int) round($sh * $escalaContain));
            imagecopyresampled(
                $dst, $src,
                (int) round(($outW - $fw) / 2), (int) round(($outH - $fh) / 2),
                0, 0,
                $fw, $fh, $sw, $sh
            );
        }

        ob_start();
        imagejpeg($dst, null, 92);
        $bytes = (string) ob_get_clean();
        // Sem imagedestroy(): no-op desde o PHP 8.0 e DEPRECATED no 8.5 (enche o log de aviso).

        return $bytes !== '' ? $bytes : null;
    }

    /** true quando o formato-alvo vai exigir fundo borrado (a peça não cabe cortando pouco). */
    public static function precisaDeFundo(int $sw, int $sh, int $outW, int $outH): bool
    {
        if ($sw < 1 || $sh < 1) {
            return false;
        }
        $escala = max($outW / $sw, $outH / $sh);
        $perda = max(
            ($sw * $escala - $outW) / max(1e-6, $sw * $escala),
            ($sh * $escala - $outH) / max(1e-6, $sh * $escala),
        );

        return $perda > self::MAX_PERDA;
    }
}
