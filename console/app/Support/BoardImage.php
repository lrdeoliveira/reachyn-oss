<?php

namespace App\Support;

/**
 * Geometria do STORYBOARD-SHEET (grid multi-painel) — recortar painéis e colar de volta.
 * Fonte única usada pelo boardSlice (painéis → keyframes), pelo Filme rápido (painel 1 como
 * abertura) e pelo CONSERTO POR PAINEL (edita 1 painel e cola no board sem regenerar o resto).
 * Tudo GD puro (sem I/O de rede — o caller baixa/persiste).
 */
final class BoardImage
{
    /**
     * Descobre a grade REAL desenhada no board, medindo as calhas pretas.
     *
     * 🐛 O modelo não obedece a grade pedida. Caso real (2026-07-22): pedimos 4x4 num filme 9:16 e
     * o modelo entregou 2x6 com painéis DEITADOS. Como cols/rows ficavam gravados com o que foi
     * PEDIDO, tudo que depende da geometria trabalhava em cima de uma mentira: o recorte cortava
     * 16 retângulos numa imagem de 12 painéis, a malha de seleção da tela caía no meio dos quadros
     * e o conserto por painel colava no lugar errado. Medir a imagem é a única fonte confiável.
     *
     * A calha é uma faixa inteira quase preta atravessando a imagem — o prompt já exige que ela
     * seja espaço vazio, sem traço desenhado. Faixas de conteúdo menores que 5% do lado são
     * descartadas (ruído/borda).
     *
     * @return array{cols:int,rows:int}|null null quando não dá pra medir com confiança
     */
    public static function detectGrid(string $boardRaw): ?array
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }
        $im = @imagecreatefromstring($boardRaw);
        if (! $im) {
            return null;
        }
        $W = imagesx($im);
        $H = imagesy($im);
        if ($W < 32 || $H < 32) {
            return null;
        }
        $escuro = static function (int $c): bool {
            return ((($c >> 16) & 255) + (($c >> 8) & 255) + ($c & 255)) / 3 < 18;
        };
        // Uma coluna/linha só é calha se TODA ela for escura (amostragem a cada 8px basta).
        $colVazia = static function (int $x) use ($im, $H, $escuro): bool {
            for ($y = 0; $y < $H; $y += 8) {
                if (! $escuro(imagecolorat($im, $x, $y))) {
                    return false;
                }
            }

            return true;
        };
        $linhaVazia = static function (int $y) use ($im, $W, $escuro): bool {
            for ($x = 0; $x < $W; $x += 8) {
                if (! $escuro(imagecolorat($im, $x, $y))) {
                    return false;
                }
            }

            return true;
        };
        $cols = self::contarFaixas($W, $colVazia);
        $rows = self::contarFaixas($H, $linhaVazia);
        // sem imagedestroy(): deprecado no PHP 8.5 (sem efeito desde o 8.0 — o GC cuida)

        // Fora de 1..6 é medição sem sentido (board totalmente escuro, imagem quebrada…).
        if ($cols < 1 || $cols > 6 || $rows < 1 || $rows > 6) {
            return null;
        }

        return ['cols' => $cols, 'rows' => $rows];
    }

    /**
     * Razão L/A da CÉLULA de um board, dada a grade. É o aspecto que o painel realmente tem —
     * e portanto o que o keyframe herda. Null se a imagem não ler.
     */
    public static function cellAspect(string $boardRaw, int $cols, int $rows): ?float
    {
        if ($cols < 1 || $rows < 1) {
            return null;
        }
        $d = @getimagesizefromstring($boardRaw);
        if (! $d || (int) $d[0] < 1 || (int) $d[1] < 1) {
            return null;
        }

        return (((int) $d[0]) / $cols) / (((int) $d[1]) / $rows);
    }

    /** Quantas faixas de CONTEÚDO existem num eixo (as calhas vazias as separam). */
    private static function contarFaixas(int $tamanho, callable $vazia): int
    {
        $min = (int) round($tamanho * 0.05); // faixa menor que 5% do lado é ruído, não painel
        $faixas = 0;
        $corrente = 0;
        for ($i = 0; $i < $tamanho; $i += 2) {
            if ($vazia($i)) {
                if ($corrente >= $min) {
                    $faixas++;
                }
                $corrente = 0;
            } else {
                $corrente += 2;
            }
        }

        return $corrente >= $min ? $faixas + 1 : $faixas;
    }

    /** Rect [x0, y0, w, h] da célula $index (0-based, linha a linha), com inset ~2% que apara a
     *  calha/linha da grade. Null se a célula ficar pequena demais. */
    public static function cellRect(int $W, int $H, int $index, int $cols, int $rows): ?array
    {
        if ($cols < 1 || $rows < 1) {
            return null;
        }
        $cellW = $W / $cols;
        $cellH = $H / $rows;
        $col = $index % $cols;
        $row = intdiv($index, $cols);
        $mx = (int) round($cellW * 0.02);
        $my = (int) round($cellH * 0.02);
        $x0 = (int) round($col * $cellW) + $mx;
        $y0 = (int) round($row * $cellH) + $my;
        $w = (int) round($cellW) - 2 * $mx;
        $h = (int) round($cellH) - 2 * $my;

        return ($w < 8 || $h < 8) ? null : [$x0, $y0, $w, $h];
    }

    /** Recorta o painel $index do board (bytes → bytes JPEG). $ratio (ex 16/9) ENCAIXA a célula
     *  inteira nesse aspecto com barras pretas (pro keyframe/i2v) — nunca corta; null = célula
     *  crua, sem barras (pro conserto, que cola de volta e precisa cobrir a região toda).
     *  Null em falha. */
    public static function cropPanel(string $boardRaw, int $index, int $cols, int $rows, ?float $ratio): ?string
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }
        $src = @imagecreatefromstring($boardRaw);
        if (! $src) {
            return null;
        }
        $rect = self::cellRect(imagesx($src), imagesy($src), $index, $cols, $rows);
        if (! $rect) {
            return null;
        }
        [$x0, $y0, $w, $h] = $rect;
        if ($ratio === null) {
            $dst = imagecreatetruecolor($w, $h);
            imagecopy($dst, $src, 0, 0, $x0, $y0, $w, $h);
        } else {
            // 🐛 CABER, não cortar. Antes isto era um center-crop ao aspecto do filme: como o modelo
            // desenha o painel na proporção que ele quer (caso real: painel 762x450 = 1.69 num filme
            // 9:16), o crop jogava fora até 2/3 da largura — sumia com metade da cena que o próprio
            // board tinha composto. Agora o painel INTEIRO entra numa tela do aspecto pedido, com
            // barras pretas. Mesma regra do player da montagem e do reenquadre do Instagram: barra é
            // erro visível, corte é conteúdo perdido em silêncio.
            $telaW = $w;
            $telaH = $h;
            if ($w / $h > $ratio) {
                $telaH = (int) round($w / $ratio); // painel mais largo → barras em cima/embaixo
            } else {
                $telaW = (int) round($h * $ratio); // painel mais alto → barras nas laterais
            }
            $dst = imagecreatetruecolor(max($telaW, 1), max($telaH, 1));
            imagefill($dst, 0, 0, imagecolorallocate($dst, 0, 0, 0));
            imagecopy($dst, $src, (int) round(($telaW - $w) / 2), (int) round(($telaH - $h) / 2), $x0, $y0, $w, $h);
        }
        ob_start();
        imagejpeg($dst, null, 92);
        $bytes = (string) ob_get_clean();

        return $bytes !== '' ? $bytes : null;
    }

    /** COLA a imagem editada de volta na célula $index (object-fit: cover — escala pra cobrir a
     *  célula e corta o excesso ao centro, sem distorcer). Retorna o board inteiro em JPEG, ou
     *  null em falha. Os outros painéis ficam byte a byte como estavam. */
    public static function pastePanel(string $boardRaw, string $panelRaw, int $index, int $cols, int $rows): ?string
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }
        $board = @imagecreatefromstring($boardRaw);
        $panel = @imagecreatefromstring($panelRaw);
        if (! $board || ! $panel) {
            return null;
        }
        $rect = self::cellRect(imagesx($board), imagesy($board), $index, $cols, $rows);
        if (! $rect) {
            return null;
        }
        [$x0, $y0, $w, $h] = $rect;
        $pw = imagesx($panel);
        $ph = imagesy($panel);
        // cover: escala pela dimensão que FALTA e corta o excesso ao centro (proporção intacta)
        $scale = max($w / $pw, $h / $ph);
        $sw = (int) round($w / $scale);
        $sh = (int) round($h / $scale);
        $sx = (int) round(($pw - $sw) / 2);
        $sy = (int) round(($ph - $sh) / 2);
        imagecopyresampled($board, $panel, $x0, $y0, $sx, $sy, $w, $h, $sw, $sh);
        ob_start();
        imagejpeg($board, null, 92);
        $bytes = (string) ob_get_clean();

        return $bytes !== '' ? $bytes : null;
    }
}
