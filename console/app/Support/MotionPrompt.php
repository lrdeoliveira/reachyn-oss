<?php

namespace App\Support;

/**
 * 🎞️ MOTION — montagem do prompt de animação a partir de uma TELA ESTÁTICA aprovada.
 *
 * Por que o prompt nasce AQUI e não no navegador: cada estrutura é um template com marcação de
 * tempo e duas regras inegociáveis. Se o usuário escrevesse o prompt, voltaria o problema que o
 * card resolve — motion genérico. O usuário escolhe a INTENÇÃO (estrutura); o texto é nosso.
 *
 * A regra que sustenta o card: NÃO SE ANIMA DO NADA. O clipe parte de um quadro que já contém
 * todos os elementos; a animação só os revela, move e remove. Animar de texto puro devolve
 * movimento genérico; animar de um quadro aprovado devolve a PEÇA se mexendo.
 *
 * ⚠️ A allowlist de técnicas espelha `web/lib/imageStyles.ts` (que por sua vez espelha
 * `engine/internal/provider/image/styles.go`). Slug fora da lista não vira texto no prompt —
 * cai no default `colagem`, que é a técnica de motion graphics editorial (web-doc).
 */
final class MotionPrompt
{
    /** Estruturas da primeira versão. As outras duas do plano (imagem+texto, câmera) ficam pra Fatia 3. */
    public const ESTRUTURAS = ['camadas', 'cartelas'];

    /** Técnicas aceitas (allowlist fechada — viram texto no prompt, então nada de campo livre). */
    public const TECNICAS = [
        'realista', '3d', 'anime', 'comic', 'aquarela', 'cyberpunk', 'minimalista', 'produto',
        'pintura', 'pixel', 'epico', 'macro', 'livro', 'arquitetura', 'editorial', 'claymation',
        'colagem',
    ];

    /** Duração pedida pelo usuário (o teto real do modelo de vídeo é 15s; abaixo de 4s não há peça). */
    public const DUR_MIN = 4;

    public const DUR_MAX = 15;

    /** Máximo de cartelas — mais que isso não cabe em 15s sem virar texto piscando. */
    public const MAX_FRASES = 6;

    /**
     * REGRA 1 (fixa em todo prompt). Sem ela o modelo REDESENHA os elementos a cada quadro e a
     * identidade da peça — produto, logo, personagem — se perde entre o segundo 1 e o 3.
     */
    public const REGRA_ELEMENTOS = 'Never redraw, change or reinterpret the elements — only reveal, move and remove them in stages. Every element must stay pixel-identical to the attached still frame.';

    /** REGRA 2 (fixa). Motion não tem locução: narração é outro produto, com montagem própria. */
    public const REGRA_SEM_VOZ = 'no voiceover, no dialogue, no lyrics, no singing, no readable new text beyond what is already in the frame.';

    /** Trava do ESTILO, no fim do prompt: o negativo da técnica escolhida. Mesmo mecanismo do
     *  negativo que a geração de imagem já usa — é o que impede o clipe de derivar pra outro look
     *  no meio da animação. Técnica sem entrada aqui usa a trava genérica. */
    private const TRAVA_ESTILO = [
        'colagem' => 'no realistic shadows, no 3D render, no photographic texture, no live-action footage, no camera depth of field — flat cut-paper collage only.',
        'minimalista' => 'no texture, no gradients, no realistic lighting, no 3D render — flat geometric shapes only.',
        'comic' => 'no photorealism, no 3D render, no soft airbrush — inked comic linework only.',
        'anime' => 'no photorealism, no 3D render, no western cartoon proportions — cel-shaded anime only.',
        'aquarela' => 'no vector flatness, no 3D render, no photographic texture — watercolor paper and pigment only.',
        'pixel' => 'no anti-aliasing, no smooth gradients, no 3D render — hard pixel grid only.',
        'claymation' => 'no smooth CGI, no photorealism, no vector flatness — sculpted clay surface only.',
        '3d' => 'no flat vector look, no hand-drawn linework, no live-action footage — rendered 3D only.',
        'realista' => 'no cartoon linework, no flat vector shapes, no illustrated look — photographic rendering only.',
    ];

    public static function estrutura(mixed $v): string
    {
        $v = is_string($v) ? trim($v) : '';

        return in_array($v, self::ESTRUTURAS, true) ? $v : 'camadas';
    }

    public static function tecnica(mixed $v): string
    {
        $v = is_string($v) ? trim($v) : '';

        return in_array($v, self::TECNICAS, true) ? $v : 'colagem';
    }

    /** Duração pedida, em segundos, presa na faixa que o modelo aceita. */
    public static function duracao(mixed $v): int
    {
        return max(self::DUR_MIN, min(self::DUR_MAX, (int) $v ?: 8));
    }

    /**
     * Duração REAL do clipe. O /v1/video só produz clipe de 6s ou 10s (ver `validDuration` no
     * engine) — a faixa de 4 a 15s do seletor é a do modelo, não a do caminho que roteia hoje.
     * Em vez de mentir pro usuário, escolhemos o clipe mais próximo e devolvemos qual foi, e a
     * coreografia do prompt é marcada NESSA duração (senão as entradas cairiam fora do clipe).
     *
     * @return array{0: string, 1: int} [valor mandado ao engine, segundos efetivos]
     */
    public static function clipe(int $segundos): array
    {
        return $segundos >= 8 ? ['10', 10] : ['5', 6];
    }

    /** Frases das cartelas: até MAX_FRASES, curtas, sem vazio. */
    public static function frases(mixed $v): array
    {
        $out = [];
        foreach ((array) $v as $f) {
            $f = trim((string) $f);
            if ($f !== '') {
                $out[] = mb_substr($f, 0, 120);
            }
        }

        return array_slice($out, 0, self::MAX_FRASES);
    }

    /**
     * Monta o prompt de animação.
     *
     * @param  string  $descricao  o que é a peça (texto do usuário — entra como contexto, não como direção)
     * @param  int  $segundos  duração EFETIVA do clipe (a de `clipe()`), que marca os tempos
     * @param  string[]  $frases  cartelas, quando a estrutura é `cartelas`
     */
    public static function build(string $descricao, string $estrutura, int $segundos, array $frases, string $tecnica): string
    {
        $descricao = trim(mb_substr($descricao, 0, 600));
        $meio = round($segundos / 2, 1);
        $outro = round($segundos * 0.87, 1);
        $s = fn (float|int $n) => rtrim(rtrim(number_format((float) $n, 1, '.', ''), '0'), '.');

        $linhas = [
            'Motion-graphics animation built FROM the attached still frame. The still already contains every element of the piece; the animation only stages them in time.',
        ];
        if ($descricao !== '') {
            $linhas[] = 'The piece is about: '.$descricao;
        }
        $linhas[] = 'Total duration: '.$segundos.' seconds. Timeline:';

        if ($estrutura === 'cartelas') {
            // CARTELAS — cada frase é um quadro cheio de texto que entra palavra a palavra e sai
            // como bloco. O tempo é dividido igualmente; a última cartela segura o quadro limpo.
            $n = max(1, count($frases));
            $fatia = $segundos / $n;
            foreach ($frases as $i => $frase) {
                $a = $s(round($i * $fatia, 1));
                $b = $s(round(($i + 1) * $fatia, 1));
                $linhas[] = sprintf(
                    '%ss–%ss · CARD %d: the line "%s" enters one word at a time from the bottom, each word with a short overshoot as it lands; the finished line holds still, then the whole line leaves as a single block before the next card.',
                    $a, $b, $i + 1, str_replace('"', "'", $frase)
                );
            }
            $linhas[] = sprintf('%ss–%ss · OUTRO: the last card leaves the frame; hold the clean empty frame to the end.', $s($outro), $s($segundos));
        } else {
            // CAMADAS — a composição se MONTA elemento a elemento, segura, se desmonta na
            // transição enquanto a próxima nasce através dela, e sai limpa no fim.
            $linhas[] = sprintf('0s–%ss · SCENE 1: open on the empty background field; the background layer arrives first, then each figure is revealed one at a time with a short overshoot as it lands, and the detail layer (marks, arrows, underlines, textures) comes in last; hold the finished composition still for a beat.', $s($meio));
            $linhas[] = sprintf('%ss · TRANSITION: the composition comes apart — elements leave in the order they arrived — while the next composition assembles through it, element by element. A single whoosh carries the change.', $s($meio));
            $linhas[] = sprintf('%ss–%ss · SCENE 2: the same staging with the second composition — background, then figures one at a time, then the detail layer; the frame settles and holds.', $s($meio), $s($outro));
            $linhas[] = sprintf('%ss–%ss · OUTRO: every element leaves the frame in staggered order; hold the clean empty frame to the end.', $s($outro), $s($segundos));
        }

        $linhas[] = 'SOUND: a soft foley tick for each element entering, a low thump when a layer lands, one whoosh on the transition — '.self::REGRA_SEM_VOZ;
        $linhas[] = self::REGRA_ELEMENTOS;
        $linhas[] = 'Camera is locked: no pan, no zoom, no parallax drift — only the elements move.';
        $linhas[] = 'STYLE LOCK: keep the exact visual technique of the attached still — '
            .(self::TRAVA_ESTILO[$tecnica] ?? 'no style drift, no re-rendering, no change of medium or palette between frames.');

        return implode("\n", $linhas);
    }
}
