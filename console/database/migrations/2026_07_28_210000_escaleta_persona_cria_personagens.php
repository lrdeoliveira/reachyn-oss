<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "📝 Escaleta" v2 — o roteiro passa a CRIAR as fichas de PERSONAGEM que a história pede,
 * como já fazia com cenários. Pedido do Luciano (2026-07-28): o fluxo do filme começa no
 * PROMPT ("Prompt do filme" → roteiro → personagens/cenários derivados), não na biblioteca —
 * personagem que a IA inventa não pode mais ser descartado em silêncio pela interseção de ids.
 *
 * Contrato novo (mexer nas CHAVES quebra o parser — ProjectController::plan):
 *  - top-level `personagens` = fichas NOVAS (nome+descricao), espelho do `cenarios`;
 *  - por cena, `elenco` = nomes (como a IA referencia quem ela mesma criou), fallback do
 *    `character_ids` — mesmo par id/nome que `scenario_id`/`cenario` já usa.
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
{"personagens":[{"nome":"","descricao":""}],"cenarios":[{"nome":"","descricao":""}],"cenas":[{"local":"","int_ext":"INT|EXT","tempo":"DIA|NOITE|ENTARDECER","resumo":"","narracao":"","objetivo_cena":"","conflito_cena":"","virada":false,"character_ids":[],"elenco":[],"scenario_id":null,"cenario":""}]}
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
- Uma cena = uma ação. Nada de resumir o filme inteiro numa cena só.
TXT;

        DB::table('prompts')->updateOrInsert(
            ['tenant_id' => $tenantId, 'title' => '📝 Escaleta'],
            ['content' => $content, 'updated_at' => now(), 'created_at' => now()],
        );
    }

    public function down(): void
    {
        // Volta ao contrato v1 é reaplicar a migration 2026_07_26_150000 — não se apaga a persona
        // (a aba Prompts pode ter ajustes do operador por cima).
    }
};
