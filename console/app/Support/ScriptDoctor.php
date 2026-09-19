<?php

namespace App\Support;

use App\Models\Project;

/**
 * DOUTOR DE ROTEIRO — a 4ª persona-método do §4 do PLANO-PERSONAGENS-CENARIOS-METODO.
 *
 * POR QUE EXISTE: o fluxo escrevia e produzia, mas ninguém LIA de volta. A IA que escreve a
 * escaleta (`ProjectController::plan`) e a Montagem que produz confiam no que está lá; se uma cena
 * chega sem conflito — "sem conflito não há cena", o princípio do curso — o defeito só aparece
 * depois de gastar clipe. Esta classe monta o que o Doutor lê e normaliza o que ele responde.
 *
 * NÃO altera cena nenhuma de propósito: o fluxo é "menos automático" (§5 do plano) — o Doutor
 * aponta, quem decide é o autor. Devolver notas em vez de reescrever também evita o pior caso, que
 * seria uma "correção" apagar a intenção de uma cena boa que o modelo não entendeu.
 */
final class ScriptDoctor
{
    /** Níveis aceitos na resposta, do mais grave ao menos. A ordem é a de exibição. */
    public const NIVEIS = ['grave', 'atencao', 'ok'];

    /** Sinônimos que os modelos usam no lugar dos três níveis canônicos. */
    private const SINONIMOS = [
        'critico' => 'grave', 'crítico' => 'grave', 'alto' => 'grave', 'erro' => 'grave',
        'alerta' => 'atencao', 'aviso' => 'atencao', 'atenção' => 'atencao', 'medio' => 'atencao',
        'médio' => 'atencao', 'warning' => 'atencao', 'baixo' => 'ok', 'bom' => 'ok', 'boa' => 'ok',
    ];

    /**
     * O DOCUMENTO que o Doutor lê: argumento + escaleta numerada, com o cabeçalho e a dramaturgia
     * de cada cena. Numeração 1..N (não id): o modelo erra menos referenciando posição, e é a
     * mesma numeração que o autor vê na tela.
     *
     * @param  \Illuminate\Support\Collection|array  $chars  personagens indexados por id
     * @param  \Illuminate\Support\Collection|array  $scens  cenários indexados por id
     */
    public static function escaleta(Project $p, $chars, $scens): string
    {
        $nome = fn ($col, $id) => is_array($col) ? ($col[$id]->name ?? null) : ($col->has($id) ? $col[$id]->name : null);

        $L = ['HISTÓRIA: '.$p->name];
        if (trim((string) $p->argumento) !== '') {
            $L[] = 'ARGUMENTO: '.trim((string) $p->argumento);
        }
        $L[] = '';
        $L[] = 'ESCALETA:';

        $n = 0;
        $atoAtual = null;
        foreach ($p->scenes as $cena) {
            $n++;
            // O ATO é a régua de estrutura: sem ele o Doutor julga "a virada está no lugar certo?"
            // sem saber onde um bloco termina e o outro começa.
            $ato = (int) ($cena->ato ?: 1);
            if ($ato !== $atoAtual) {
                $atoAtual = $ato;
                $L[] = '';
                $L[] = '=== ATO '.$ato.' ===';
            }
            $cab = array_filter([$cena->local, $cena->int_ext, $cena->tempo], fn ($v) => trim((string) $v) !== '');
            $elenco = array_values(array_filter(array_map(
                fn ($id) => $nome($chars, $id),
                is_array($cena->character_ids) ? $cena->character_ids : []
            )));

            $L[] = '';
            $L[] = 'CENA '.$n.($cab ? ' — '.implode('/', $cab) : '').($cena->virada ? ' [VIRADA]' : '');
            foreach ([
                'Cenário' => $cena->scenario_id ? $nome($scens, $cena->scenario_id) : null,
                'Quem entra' => $elenco ? implode(', ', $elenco) : null,
                'Ação' => $cena->resumo,
                'Objetivo' => $cena->objetivo_cena,
                'Conflito' => $cena->conflito_cena,
                'Narração' => $cena->narracao,
            ] as $rot => $val) {
                if (trim((string) $val) !== '') {
                    $L[] = '  '.$rot.': '.trim((string) $val);
                }
            }
            // O vazio também é informação — some da lista acima e o Doutor não teria como saber
            // que o campo está em branco (e não apenas curto).
            $faltando = array_keys(array_filter([
                'ação' => trim((string) $cena->resumo) === '',
                'objetivo' => trim((string) $cena->objetivo_cena) === '',
                'conflito' => trim((string) $cena->conflito_cena) === '',
                'cenário' => ! $cena->scenario_id,
                'elenco' => $elenco === [],
            ]));
            if ($faltando) {
                $L[] = '  (em branco: '.implode(', ', $faltando).')';
            }
        }

        if ($n === 0) {
            $L[] = '(nenhuma cena)';
        }

        return implode("\n", $L);
    }

    /**
     * Normaliza o diagnóstico do modelo. Tolerante de propósito — o que chega é texto: pode vir em
     * cerca ```json, com um parágrafo antes, com nível em sinônimo ou apontando uma cena que não
     * existe (alucinação comum quando a escaleta é curta).
     *
     * @param  int  $total  quantas cenas a história tem — nota fora da faixa vira nota GERAL
     * @return array{veredito: string, notas: array<int, array{cena: int|null, nivel: string, problema: string, sugestao: string}>}
     */
    public static function diagnostico(string $raw, int $total): array
    {
        $j = self::json($raw);
        $notas = [];

        foreach ((is_array($j['notas'] ?? null) ? $j['notas'] : []) as $n) {
            if (! is_array($n)) {
                continue;
            }
            $problema = trim((string) ($n['problema'] ?? ''));
            if ($problema === '') {
                continue;   // nota sem problema não diz nada — ocupa a tela e esconde as que importam
            }
            $cena = isset($n['cena']) && is_numeric($n['cena']) ? (int) $n['cena'] : null;
            $notas[] = [
                // Fora de 1..N o apontamento não tem onde ancorar: vira nota geral em vez de
                // mandar o autor procurar uma "cena 14" num roteiro de 8.
                'cena' => ($cena !== null && $cena >= 1 && $cena <= $total) ? $cena : null,
                'nivel' => self::nivel((string) ($n['nivel'] ?? '')),
                'problema' => $problema,
                'sugestao' => trim((string) ($n['sugestao'] ?? '')),
            ];
        }

        // Grave primeiro, e dentro do nível na ordem da história — é a ordem em que o autor
        // trabalha. As notas gerais (sem cena) fecham o bloco do seu nível.
        usort($notas, function (array $a, array $b) {
            $pa = array_search($a['nivel'], self::NIVEIS, true);
            $pb = array_search($b['nivel'], self::NIVEIS, true);

            return $pa <=> $pb ?: ($a['cena'] ?? PHP_INT_MAX) <=> ($b['cena'] ?? PHP_INT_MAX);
        });

        return ['veredito' => trim((string) ($j['veredito'] ?? '')), 'notas' => $notas];
    }

    /** Nível canônico. Desconhecido vira 'atencao': some da tela é pior do que ser exibido. */
    private static function nivel(string $v): string
    {
        $v = mb_strtolower(trim($v));

        return in_array($v, self::NIVEIS, true) ? $v : (self::SINONIMOS[$v] ?? 'atencao');
    }

    /** Lê o objeto JSON da resposta, mesmo com cerca de código ou texto em volta. */
    private static function json(string $raw): array
    {
        $txt = trim($raw);
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $txt, $m)) {
            $txt = trim($m[1]);
        }
        $j = json_decode($txt, true);
        if (! is_array($j)) {
            $i = strpos($txt, '{');
            $f = strrpos($txt, '}');
            $j = ($i !== false && $f !== false && $f > $i) ? json_decode(substr($txt, $i, $f - $i + 1), true) : null;
        }

        return is_array($j) ? $j : [];
    }
}
