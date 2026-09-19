<?php

namespace App\Support;

use App\Models\Character;

/**
 * IDENTITY LOCK nas cenas do Roteiro — a metade TEXTUAL da trava de identidade.
 *
 * POR QUE EXISTE: a Escaleta amarra cada cena a personagens da biblioteca, mas até 2026-07-25 só
 * a IMAGEM-base deles chegava na geração (âncora i2i/i2v). O `lock` — a descrição destilada do
 * model sheet, que diz "coleira rosa com plaquinha dourada, pelo honey-brown liso, proporção
 * 1:3" — ficava no banco sem ser lido: `roteiroRender` nem consultava Character. Ou seja, gerar
 * o model sheet custava crédito e NÃO influenciava nada no caminho do Roteiro, ao contrário do
 * Estúdio de Animação e da aba Filme, que já mandavam o lock como `visual_prompt`.
 *
 * Resolver no SERVIDOR (por id), e não no front, é de propósito: o lock fica sempre o ATUAL do
 * personagem. Congelado no documento do canvas, um roteiro salvo continuaria gerando com uma
 * identidade velha depois de você refinar a ficha — e o sintoma seria drift sem explicação.
 */
class IdentityLock
{
    /** Teto por personagem. O prompt do clipe divide espaço com a descrição da cena — em modelo
     *  com teto curto (mmx clampa em 1500) o excesso comeria justamente a ação. O teto é APERTADO
     *  de propósito: o que entra nele é escolhido por `essencial()`, não pela ordem do texto. */
    private const POR_PERSONAGEM = 600;

    /** Teto do bloco todo: uma cena com 3 personagens não pode virar um prompt só de fichas. */
    private const TOTAL = 1400;

    /** Traços que NÃO podem faltar, na ordem de prioridade — é o que o modelo de vídeo inventa
     *  quando não está dito: acessórios (a coleira rosa da Mel virou coleira branca no clipe de
     *  2026-07-25) e cor de pelo/olhos. */
    private const PRIORIDADE = [
        '/\b(collar|tag|harness|leash|clothing|clothes|wearing|accessor|scarf|hat|cap|boots|sweater|jacket|bandana|ribbon|glasses)\b/iu',
        '/\b(color|colour|coat|fur|hair|skin|eyes|markings?|palette)\b/iu',
    ];

    /** Cláusulas que o lock repete e que o prompt NÃO precisa carregar: a preservação de
     *  identidade entre cenas já é imposta pela regra de i2i/i2v no engine, e o estilo de arte
     *  viaja no campo `estilo` da cena. Ocupavam ~150 dos 900 caracteres do lock da Mel — espaço
     *  que fazia falta justamente pro acessório. */
    private const REDUNDANTE = '/^(identical\b|no redesigns?\b|100% visual|.*\bvisual consistency\b|realistic art style|.*\bart style\b.*)/iu';

    /**
     * O ESSENCIAL do lock, dentro de `$teto` caracteres — escolhido por importância, não pela
     * ordem em que o model sheet escreveu.
     *
     * POR QUE: o lock da Mel tem 900 caracteres e o teto por personagem é 600. Truncar na ordem
     * do texto cortava no meio de "small circular gold metal t" e jogava fora tudo o que vinha
     * depois — e o que vem no fim de um lock é justamente acessório e cláusula de consistência. No
     * clipe de 2026-07-25 a coleira rosa com plaquinha virou coleira branca sem plaquinha: o
     * modelo não estava desobedecendo, ele nunca recebeu a informação inteira.
     *
     * A ordem aqui é: identidade (1º segmento — espécie/sexo, o que o personagem É) → acessórios →
     * cores → o resto, descartando o que é redundante com as regras do engine.
     */
    public static function essencial(string $lock, int $teto = self::POR_PERSONAGEM): string
    {
        $lock = trim(preg_replace('/[ \t]+/u', ' ', $lock));
        // O header não é traço nenhum, é rótulo — ocupa 26 caracteres do orçamento.
        $lock = preg_replace('/^\s*MANDATORY CHARACTER (LOCK|SPECIFICATIONS)\s*:\s*/iu', '', $lock);
        $segs = array_values(array_filter(array_map(
            fn ($s) => trim($s, " \t\n\r;.,"),
            preg_split('/[\n;]+/u', $lock) ?: [],
        ), fn ($s) => $s !== ''));
        if ($segs === []) {
            return '';
        }

        // Identidade primeiro, sempre: é a linha que diz a espécie e o sexo.
        $ordenados = [array_shift($segs)];
        $restantes = array_filter($segs, fn ($s) => ! preg_match(self::REDUNDANTE, $s));
        foreach (self::PRIORIDADE as $re) {
            foreach ($restantes as $k => $s) {
                if (preg_match($re, $s)) {
                    $ordenados[] = $s;
                    unset($restantes[$k]);
                }
            }
        }
        foreach ($restantes as $s) {
            $ordenados[] = $s;
        }

        // Encaixa segmento INTEIRO: meia frase ("gold metal t") não descreve nada.
        $saida = [];
        $tamanho = 0;
        foreach ($ordenados as $s) {
            $custo = mb_strlen($s) + ($saida ? 2 : 0);
            if ($tamanho + $custo > $teto) {
                continue;   // segmento grande demais não bloqueia os menores que vêm depois
            }
            $saida[] = $s;
            $tamanho += $custo;
        }
        // Nenhum segmento inteiro caberia (lock de uma frase só, gigante): corta na palavra.
        if ($saida === []) {
            $corte = mb_substr($ordenados[0], 0, $teto);
            $espaco = mb_strrpos($corte, ' ');

            return $espaco !== false ? mb_substr($corte, 0, $espaco) : $corte;
        }

        return implode('; ', $saida);
    }

    /** Sexo declarado no texto de identidade: 'female', 'male' ou '' quando não dá pra saber.
     *  Mesma heurística do negative_prompt do model sheet (ModelSheetService::motorSpecFor) — aqui
     *  ela serve pra AFIRMAR o sexo no prompt, porque os modelos de vídeo em uso (seedance 1.5
     *  pro, seedance 2, kling v3 turbo) não têm campo `negative_prompt` onde negar o oposto. */
    public static function sexo(string $texto): string
    {
        $t = mb_strtolower($texto);
        if (preg_match('/\b(female|fêmea|femea|mulher|woman|girl|cadela|she|her)\b/u', $t)) {
            return 'female';
        }
        if (preg_match('/\b(male|macho|homem|man|boy|he|his)\b/u', $t)) {
            return 'male';
        }

        return '';
    }

    /**
     * Monta o bloco de identidade dos personagens da cena. Só personagens DO TENANT (o id vem do
     * cliente) e só quem tem lock — quem não gerou model sheet simplesmente não contribui.
     *
     * @param  array<int|string>  $ids  character_ids da cena (vindos da Escaleta)
     */
    public static function bloco(int $tenantId, array $ids): string
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return '';
        }

        $chars = Character::where('tenant_id', $tenantId)
            ->whereIn('id', array_slice($ids, 0, 5))   // cena com mais de 5 personagens não cabe no prompt
            ->get(['id', 'name', 'lock', 'description']);

        $partes = [];
        $sexos = [];
        $tamanho = 0;
        foreach ($chars as $c) {
            $lock = (string) $c->lock;
            if (trim($lock) === '') {
                continue;
            }
            $nome = trim((string) $c->name);
            $trecho = $nome.': '.self::essencial($lock);
            if ($tamanho + mb_strlen($trecho) > self::TOTAL) {
                break;
            }
            $partes[] = $trecho;
            $tamanho += mb_strlen($trecho);
            // O sexo é AFIRMADO à parte, no fim do bloco: enterrado no meio da ficha ele se dilui,
            // e é justamente o traço que o modelo de vídeo troca ao mostrar um ângulo que a
            // referência não tem (a Mel, fêmea, saiu macho no clipe de 2026-07-25).
            if ($sexo = self::sexo($lock.' '.(string) $c->description)) {
                $sexos[] = $nome.' is '.strtoupper($sexo);
            }
        }

        if ($partes === []) {
            return '';
        }

        // SEXO/ANATOMIA: em POSITIVO, porque é o que os modelos em uso entendem — os de vídeo
        // (seedance 1.5 pro, seedance 2, kling v3 turbo) NÃO têm campo `negative_prompt` onde negar
        // o oposto (doc oficial conferida em 2026-07-26), então "male anatomy" como negativa não
        // tem onde entrar. Dizemos o que DEVE aparecer: ventre liso, nada de genitália.
        $sexoRegra = $sexos === [] ? '' : ' SEX: '.implode(', ', $sexos)
            .' — keep this sex in EVERY frame, from every camera angle, including rear and low '
            .'angles the reference does not show. Underbelly and groin stay smooth and featureless: '
            .'never add genitalia, sheath, teats or any anatomy the reference does not show.';

        // GUARDA-ROUPA: o acessório é o primeiro traço que o clipe perde (a coleira rosa com
        // plaquinha dourada desapareceu na cena 3 do filme de 2026-07-25, embora estivesse no
        // keyframe). Repetir aqui é barato e é o que se vê num vídeo.
        $roupa = ' WARDROBE: every collar, tag, clothing item and accessory described above stays '
            .'on the character, in the same color and position, in every frame — never removed, '
            .'recolored or restyled.';

        // ESCALA: sem isto o personagem sai GIGANTE no cenário. Com duas referências (o retrato
        // do personagem, que ocupa o quadro inteiro, e o plano aberto do lugar), o modelo tende a
        // preservar o tamanho relativo das REFERÊNCIAS em vez do tamanho do mundo — e uma
        // dachshund aparece do tamanho de um carro no meio do beco (visto em 2026-07-25).
        // A frase fala de PROPORÇÃO, não de enquadramento: não atrapalha um close legítimo.
        // Duas ordens, porque são dois erros diferentes: (1) o modelo copia o ENQUADRAMENTO da
        // foto de referência — um retrato que preenche o quadro vira um bicho gigante na rua;
        // (2) sem âncora de tamanho, ele não infere a escala da espécie. Testado em 2026-07-25:
        // só a frase de proporção não bastou, a dachshund seguia do tamanho da caçamba.
        return 'IDENTITY LOCK — '.implode(' | ', $partes)
            .'.'.$sexoRegra.$roupa
            .' SCALE: infer the real-world size of the character from its species and description, '
            .'and place it at that size in the location — measured against doors, steps, bins, '
            .'vehicles and people in frame. Do NOT copy the framing or the on-screen size of the '
            .'reference portrait: the reference defines WHO the character is, never how big it is '
            .'or how close the camera stands.';
    }

    /** Anexa o bloco ao prompt da cena. A ação vem PRIMEIRO: se o modelo cortar por tamanho,
     *  quem se perde é a ficha, não o que a cena faz. */
    public static function aplicar(string $prompt, int $tenantId, array $ids): string
    {
        $bloco = self::bloco($tenantId, $ids);

        return $bloco === '' ? $prompt : trim($prompt).'. '.$bloco;
    }
}
