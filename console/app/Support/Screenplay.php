<?php

namespace App\Support;

use App\Models\Project;

/**
 * O ROTEIRO SAINDO DAQUI — escaleta → Fountain (texto puro) e FDX (XML do Final Draft).
 *
 * POR QUE EXISTE: a escaleta era um beco sem saída. Dava pra escrever, revisar e produzir dentro do
 * FoxAssets, mas o único arquivo que saía era a Bíblia em Markdown — que nenhum produtor, montador
 * ou roteirista abre na ferramenta dele. Fountain e FDX são os dois formatos que o mercado inteiro
 * lê (Final Draft, WriterDuet, Celtx, Arc Studio, Highland, Beat, Fade In), e descrevem exatamente
 * a estrutura que as `scenes` já têm no banco: cabeçalho, ação, personagem, fala.
 *
 * O MAPA:
 *   local + int_ext + tempo  → cabeçalho de cena ("INT. COZINHA - DIA")
 *   resumo                   → ação
 *   narracao                 → fala do NARRADOR (o roteiro não tem elemento "narração"; quem narra
 *                              é um personagem, e é assim que qualquer app vai renderizar)
 *   objetivo/conflito/virada → SINOPSE da cena (`=` no Fountain): não imprime no roteiro, mas
 *                              aparece no outline do Beat/Highland. É dramaturgia, não página.
 */
final class Screenplay
{
    private const NARRADOR = 'NARRADOR';

    /**
     * Fountain: texto puro que qualquer editor moderno importa.
     *
     * @param  \Illuminate\Support\Collection|array  $scens  cenários indexados por id
     */
    public static function fountain(Project $p, $scens = []): string
    {
        // Folha de rosto só com o que se sabe. `Credit:` ("escrito por") sem `Author:` renderiza a
        // linha órfã no editor do outro lado — e autor é coisa que o FoxAssets não tem pra dizer.
        $L = ['Title: '.$p->name, 'Source: FoxAssets', ''];

        if (trim((string) $p->argumento) !== '') {
            // Seção + sinopse: fica no OUTLINE do editor, fora da página impressa. O argumento é
            // documento de trabalho — imprimi-lo junto do roteiro seria erro de formato.
            $L[] = '# ARGUMENTO';
            $L[] = '';
            $L[] = '= '.self::umaLinha((string) $p->argumento);
            $L[] = '';
        }

        $ato = null;
        foreach (self::cenas($p, $scens) as $c) {
            // Ato vira SEÇÃO (`#`): aparece no outline do editor e fica fora da página impressa,
            // que é onde a divisão de atos pertence.
            if ($c['ato'] !== $ato) {
                $ato = $c['ato'];
                $L[] = '# ATO '.$ato;
                $L[] = '';
            }
            // Cabeçalho sem INT/EXT não é reconhecido como cena: o ponto na frente FORÇA o
            // cabeçalho no Fountain, e é o que salva a cena escrita pela metade.
            $L[] = $c['forcado'] ? '.'.$c['cabecalho'] : $c['cabecalho'];
            $L[] = '';
            if ($c['sinopse'] !== '') {
                $L[] = '= '.$c['sinopse'];
                $L[] = '';
            }
            if ($c['acao'] !== '') {
                $L[] = $c['acao'];
                $L[] = '';
            }
            if ($c['narracao'] !== '') {
                $L[] = self::NARRADOR;
                $L[] = $c['narracao'];
                $L[] = '';
            }
        }

        return rtrim(implode("\n", $L))."\n";
    }

    /**
     * FDX: o XML do Final Draft. Cada parágrafo carrega o tipo, que é o que faz outro programa
     * saber o que é cabeçalho e o que é fala sem adivinhar pela indentação.
     *
     * @param  \Illuminate\Support\Collection|array  $scens
     */
    public static function fdx(Project $p, $scens = []): string
    {
        $X = ['<?xml version="1.0" encoding="UTF-8" standalone="no" ?>',
            '<FinalDraft DocumentType="Script" Template="No" Version="1">', '  <Content>'];

        $par = function (string $tipo, string $texto) use (&$X): void {
            if (trim($texto) === '') {
                return;
            }
            $X[] = '    <Paragraph Type="'.$tipo.'">';
            $X[] = '      <Text>'.htmlspecialchars($texto, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</Text>';
            $X[] = '    </Paragraph>';
        };

        foreach (self::cenas($p, $scens) as $c) {
            $par('Scene Heading', $c['cabecalho']);
            $par('Action', $c['acao']);
            if ($c['narracao'] !== '') {
                $par('Character', self::NARRADOR);
                $par('Dialogue', $c['narracao']);
            }
        }

        $X[] = '  </Content>';
        $X[] = '</FinalDraft>';

        return implode("\n", $X)."\n";
    }

    /**
     * A escaleta normalizada — a mesma para os dois formatos, pra não existirem duas verdades sobre
     * o que é o cabeçalho de uma cena.
     *
     * @return array<int, array{ato: int, cabecalho: string, forcado: bool, acao: string, narracao: string, sinopse: string}>
     */
    private static function cenas(Project $p, $scens): array
    {
        $nome = fn ($id) => is_array($scens) ? ($scens[$id]->name ?? null) : ($scens->has($id) ? $scens[$id]->name : null);
        $out = [];

        foreach ($p->scenes as $s) {
            // Sem `local`, o nome do cenário serve de locação — é o lugar que a cena usa como
            // âncora de imagem, então é o mesmo lugar do cabeçalho.
            $local = trim((string) $s->local) ?: trim((string) ($s->scenario_id ? $nome($s->scenario_id) : ''));
            $local = $local !== '' ? mb_strtoupper($local) : 'CENA';
            $intExt = strtoupper(trim((string) $s->int_ext));
            $tempo = mb_strtoupper(trim((string) $s->tempo));

            $cab = trim(($intExt !== '' ? $intExt.'. ' : '').$local.($tempo !== '' ? ' - '.$tempo : ''));

            $sin = array_filter([
                trim((string) $s->objetivo_cena) !== '' ? 'Objetivo: '.self::umaLinha((string) $s->objetivo_cena) : null,
                trim((string) $s->conflito_cena) !== '' ? 'Conflito: '.self::umaLinha((string) $s->conflito_cena) : null,
                $s->virada ? 'VIRADA' : null,
            ]);

            $out[] = [
                'ato' => (int) ($s->ato ?: 1),
                'cabecalho' => $cab,
                'forcado' => $intExt === '',
                'acao' => self::umaLinha((string) $s->resumo),
                'narracao' => self::umaLinha((string) $s->narracao),
                'sinopse' => implode(' · ', $sin),
            ];
        }

        return $out;
    }

    /** Quebra de linha vira espaço: no Fountain, linha em branco no meio troca o TIPO do bloco. */
    private static function umaLinha(string $s): string
    {
        return trim(preg_replace('/\s*\R\s*/u', ' ', trim($s)) ?? '');
    }
}
