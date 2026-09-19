<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "📝 Escaleta" — quem ESCREVE as cenas. O texto era hardcoded no `ProjectController::plan`,
 * enquanto as quatro personas-método do §4 (🧬 Arquiteto de Personagem, 🎨 Ficha → Prompt,
 * 🗺️ Arquiteto de Cenário, 📐 Doutor de Roteiro) já viviam na aba Prompts, editáveis sem deploy.
 * Assimetria sem motivo: justamente a etapa que decide a forma do filme inteiro era a única que
 * exigia rebuild pra ajustar. Passa a ser a 5ª persona da família.
 *
 * ⚠️ O FORMATO do JSON é contrato com o parser (`cenasDoJson` + a criação das Scene): quem editar
 * este texto pode mudar as REGRAS à vontade, mas mexer nas CHAVES quebra a escrita das cenas.
 * O conteúdo abaixo é o mesmo que estava no controller, verbatim.
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
{"cenarios":[{"nome":"","descricao":""}],"cenas":[{"local":"","int_ext":"INT|EXT","tempo":"DIA|NOITE|ENTARDECER","resumo":"","narracao":"","objetivo_cena":"","conflito_cena":"","virada":false,"character_ids":[],"scenario_id":null,"cenario":""}]}
Regras:
- `resumo` = a AÇÃO da cena em uma frase concreta e filmável (o que a câmera vê), nunca abstração.
- `narracao` = o que se OUVE na cena: 1 a 2 frases de locução, no tempo de fala de um clipe curto
  (~12 a 25 palavras). NÃO descreva o que já se vê — a narração acrescenta o que a imagem não diz
  (o que passa na cabeça, o que está em jogo, a passagem de tempo). Emendada com as outras, tem que
  soar como UM texto contínuo do começo ao fim do filme.
- `objetivo_cena` = o que o personagem quer NESTA cena. `conflito_cena` = o que se opõe. Ambos curtos.
- `virada` = true só na cena que muda o rumo da história (no máximo duas no filme inteiro).
- `character_ids`: use SOMENTE os ids do ELENCO fornecido; se ninguém servir, [].
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
        if (! $tenantId = $this->tenantId()) {
            return;
        }
        DB::table('prompts')->where('tenant_id', $tenantId)->where('title', '📝 Escaleta')->delete();
    }
};
