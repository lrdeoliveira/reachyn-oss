<?php

namespace App\Support;

/**
 * VOCABULÁRIO DE DECUPAGEM — o blocking do plano vira frase de câmera.
 *
 * POR QUE FECHADO: "plano baixo e dramático" é adjetivo; "low angle, camera at knee height" é
 * instrução. A régua de escala do cenário já mostrou que estes modelos obedecem ao mensurável e
 * inventam em cima do vago — decupagem tem o mesmo problema. Campo com lista fechada também deixa
 * o plano REPRODUZÍVEL: dá pra repetir o mesmo enquadramento em outra cena sem redigitar prosa.
 *
 * A frase sai em INGLÊS porque é a língua dos motores de imagem/vídeo; os rótulos ficam em PT-BR
 * pra tela. Vazio = o modelo escolhe, que é melhor do que empurrar um padrão errado.
 */
final class Plano
{
    /**
     * FUNÇÃO na montagem — o que o plano faz no corte, não como ele é composto. Veio da leitura do
     * Celtx (2026-07-27), cujo vocabulário mistura master/cutaway/cut-in no tipo do plano: num
     * filme gerado por IA o cutaway pesa mais ainda, porque é o corte de alívio que salva uma
     * emenda que não casa entre dois clipes.
     */
    public const FUNCAO = [
        'master' => ['Master (cobre a cena)', 'master shot covering the whole scene in one framing'],
        'cutaway' => ['Cutaway (alívio)', 'cutaway to something outside the main action'],
        'cut_in' => ['Cut-in (detalhe da ação)', 'cut-in to a detail of the subject within the action'],
        'insert' => ['Insert (objeto/texto)', 'insert shot of an object or written text'],
        'reacao' => ['Reação', 'reaction shot of the character listening or watching'],
    ];

    /** chave => [rótulo PT-BR, fragmento em inglês pro prompt] */
    public const ENQUADRAMENTO = [
        'geral' => ['Plano geral', 'wide establishing shot, full location visible'],
        'conjunto' => ['Plano de conjunto', 'full shot, whole body of the subject in frame'],
        'americano' => ['Plano americano', 'medium-long shot framed from mid-thigh up'],
        'medio' => ['Plano médio', 'medium shot framed from the waist up'],
        'close' => ['Close', 'close-up on the face, shoulders barely in frame'],
        'detalhe' => ['Detalhe', 'extreme close-up on a single detail'],
    ];

    public const ANGULO = [
        'frontal' => ['Frontal', 'straight-on frontal angle'],
        'tres_quartos' => ['3/4', 'three-quarter angle'],
        'lateral' => ['Lateral', 'profile side angle'],
        'costas' => ['De costas', 'from behind the subject'],
        'plongee' => ['Plongée (de cima)', 'high angle looking down at the subject'],
        'contra_plongee' => ['Contra-plongée (de baixo)', 'low angle looking up at the subject'],
        'over_shoulder' => ['Over-shoulder', 'over-the-shoulder framing'],
        'pov' => ['POV', "point-of-view shot from the subject's eyes"],
    ];

    /** Altura da câmera — o que mais muda a leitura de um plano, e o que ninguém escreve sozinho. */
    public const ALTURA = [
        'chao' => ['Rente ao chão', 'camera at ground level'],
        'joelho' => ['Altura do joelho', 'camera at knee height (~0.5 m)'],
        'peito' => ['Altura do peito', 'camera at chest height (~1.3 m)'],
        'olhos' => ['Altura dos olhos', 'camera at eye level (~1.6 m)'],
        'alto' => ['Acima da cabeça', 'camera above head height (~2.2 m)'],
    ];

    public const MOVIMENTO = [
        'fixo' => ['Fixo', 'locked-off static camera'],
        'dolly_in' => ['Aproxima (dolly in)', 'slow dolly in toward the subject'],
        'dolly_out' => ['Afasta (dolly out)', 'slow dolly out away from the subject'],
        'travelling' => ['Travelling lateral', 'lateral tracking movement following the subject'],
        'panoramica' => ['Panorâmica', 'slow pan across the scene'],
        'tilt' => ['Tilt', 'vertical tilt movement'],
        'mao' => ['Câmera na mão', 'handheld camera with subtle natural shake'],
        'grua' => ['Grua', 'crane movement rising above the scene'],
    ];

    /** Todos os campos, na ordem em que entram na frase. A FUNÇÃO abre: ela diz o que o plano é
     *  antes de dizer como ele é composto ("cutaway to something outside the main action, extreme
     *  close-up…"). */
    private const CAMPOS = [
        'funcao' => self::FUNCAO,
        'enquadramento' => self::ENQUADRAMENTO,
        'angulo' => self::ANGULO,
        'altura' => self::ALTURA,
        'movimento' => self::MOVIMENTO,
    ];

    /**
     * A frase de câmera do plano, em inglês, pronta pra entrar no prompt. Campo vazio ou
     * desconhecido é ignorado — meio plano descrito é melhor que um padrão inventado.
     *
     * @param  array<string, mixed>  $plano
     */
    public static function frase(array $plano): string
    {
        $partes = [];
        foreach (self::CAMPOS as $campo => $tabela) {
            $v = (string) ($plano[$campo] ?? '');
            if ($v !== '' && isset($tabela[$v])) {
                $partes[] = $tabela[$v][1];
            }
        }

        return implode(', ', $partes);
    }

    /** Rótulo curto pra tela ("Close · contra-plongée · fixo"). Vazio quando nada foi escolhido. */
    public static function rotulo(array $plano): string
    {
        $partes = [];
        foreach (self::CAMPOS as $campo => $tabela) {
            $v = (string) ($plano[$campo] ?? '');
            if ($v !== '' && isset($tabela[$v])) {
                $partes[] = $tabela[$v][0];
            }
        }

        return implode(' · ', $partes);
    }

    /** O vocabulário inteiro, pro front montar os seletores sem duplicar a lista. */
    public static function vocabulario(): array
    {
        $out = [];
        foreach (self::CAMPOS as $campo => $tabela) {
            $out[$campo] = array_map(fn ($k, $v) => ['valor' => $k, 'rotulo' => $v[0]], array_keys($tabela), $tabela);
        }

        return $out;
    }

    /** Valor válido pro campo? Usado na validação do controller (allowlist, baseline #7). */
    public static function valido(string $campo, ?string $valor): bool
    {
        return $valor === null || $valor === '' || isset(self::CAMPOS[$campo][$valor]);
    }
}
