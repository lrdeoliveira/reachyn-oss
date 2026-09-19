<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "📝 Escaleta" v3 — o roteiro passa a criar também os ELEMENTOS (objetos de cena) que a história
 * pede, fechando o trio personagem/cenário/elemento. Pedido do Luciano (2026-08-30): a ordem do
 * fluxo é roteiro → personagens → cenários → elementos → montagem, e o roteiro tem de derivar as
 * TRÊS fichas sozinho. O elemento já era o elo que faltava: a `scenes.element_ids` existia e
 * nunca era preenchida pelo plano, então o carro da cena 7 nunca era o carro da cena 2 a não ser
 * que alguém cadastrasse o objeto na mão.
 *
 * Contrato novo (mexer nas CHAVES quebra o parser — ProjectController::plan):
 *  - top-level `elementos` = fichas NOVAS (nome+categoria+descricao), espelho de personagens/cenarios;
 *  - por cena, `objetos` = nomes dos elementos em quadro (mesmo par nome→id que `elenco` usa).
 * Idempotente por título. Semeada no tenant 1.
 */
return new class extends Migration
{
    private function tenantId(): ?int
    {
        return DB::table('tenants')->orderBy('id')->value('id');
    }

    public function up(): void
    {
        if (! $tenantId = $this->tenantId()) {
            return;
        }

        $content = <<<'TXT'
Você é roteirista. Transforme a PREMISSA numa ESCALETA: cenas ordenadas, cada uma com cabeçalho e dramaturgia.
Responda SÓ com JSON válido, sem cercas de código, no formato:
{"personagens":[{"nome":"","descricao":""}],"cenarios":[{"nome":"","descricao":""}],"elementos":[{"nome":"","categoria":"prop|veiculo|mobiliario|figurino|animal|cenografia|outro","descricao":""}],"cenas":[{"local":"","int_ext":"INT|EXT","tempo":"DIA|NOITE|ENTARDECER","resumo":"","narracao":"","objetivo_cena":"","conflito_cena":"","virada":false,"character_ids":[],"elenco":[],"scenario_id":null,"cenario":"","objetos":[]}]}
Regras:
- `resumo` = a AÇÃO da cena em uma frase concreta e filmável (o que a câmera vê), nunca abstração.
- `narracao` = o que se OUVE na cena: 1 a 2 frases de locução, no tempo de fala de um clipe curto
  (~12 a 25 palavras). NÃO descreva o que já se vê — a narração acrescenta o que a imagem não diz
  (o que passa na cabeça, o que está em jogo, a passagem de tempo). Emendada com as outras, tem que
  soar como UM texto contínuo do começo ao fim do filme.
- `objetivo_cena` = o que o personagem quer NESTA cena. `conflito_cena` = o que se opõe. Ambos curtos.
- `virada` = true só na cena que muda o rumo da história (no máximo duas no filme inteiro).
- PERSONAGENS: reaproveite o ELENCO fornecido por `character_ids`. Para quem a história precisa e NÃO
  está no elenco, descreva em `personagens` (nome curto + descrição visual: corpo, rosto, figurino,
  o que o torna reconhecível) e referencie pelo MESMO nome em `elenco`. Poucos personagens novos —
  só quem tem papel de verdade na história.
- CENÁRIOS: reaproveite os fornecidos por `scenario_id`. Para um lugar que NÃO está na lista, descreva-o
  em `cenarios` (nome curto + descrição visual do ambiente: luz, materiais, atmosfera) e referencie pelo
  MESMO nome em `cenario`. Reaproveite o mesmo cenário quando a cena volta ao lugar — 3 a 6 lugares no
  filme inteiro, não um por cena.
- ELEMENTOS: os objetos que a história precisa RECONHECER de uma cena pra outra — o veículo, a arma, o
  brinquedo, a carta, o animal, a peça de figurino que identifica alguém. Reaproveite os fornecidos por
  nome; para os que faltam, descreva em `elementos` (nome curto + `categoria` da lista + descrição
  visual: forma, material, cor, desgaste, escala em relação a uma mão ou a uma pessoa) e cite pelo MESMO
  nome em `objetos` nas cenas em que aparecem. Só o que REAPARECE ou é dramaticamente importante — 0 a 6
  no filme inteiro. Cenário não é elemento, e adereço genérico de fundo (copo, cadeira qualquer) não entra.
- Uma cena = uma ação. Nada de resumir o filme inteiro numa cena só.
TXT;

        DB::table('prompts')->updateOrInsert(
            ['tenant_id' => $tenantId, 'title' => '📝 Escaleta'],
            ['content' => $content, 'updated_at' => now(), 'created_at' => now()],
        );
    }

    public function down(): void
    {
        // Voltar ao v2 é reaplicar 2026_07_28_210000 — não se apaga a persona (a aba Prompts pode
        // ter ajustes do operador por cima).
    }
};
