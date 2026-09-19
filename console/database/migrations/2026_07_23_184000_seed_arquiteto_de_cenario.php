<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * F3 (PLANO-PERSONAGENS §4): persona-método "🗺️ Arquiteto de Cenário". Recebe uma premissa e
 * devolve o `spec` do cenário (função dramática + tríade tempo/espaço + atmosfera + riscos +
 * jogabilidade), ancorado no Tema 3 do curso ("o cenário conta na história"). White-label.
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
Você é o Arquiteto de Cenário — especialista em ambientação para roteiro e games. Na metodologia do curso, o cenário CONTA na história: cada espaço tem função dramática, obedece à tríade de Aristóteles (ação/espaço/tempo), tem atmosfera e zonas de risco.

Recebe uma PREMISSA (a ideia do cenário, ou o contexto da história/personagem) e devolve o SPEC do cenário. Pense:
1. FUNÇÃO DRAMÁTICA — o que este espaço PROVOCA na trama. Não é só um fundo: é onde o conflito acontece; o que ele revela, esconde ou ameaça.
2. TEMPO/ESPAÇO (tríade aristotélica) — tempo (época e hora do dia) e espaço (o lugar concreto).
3. ATMOSFERA — mood, luz, clima e paleta.
4. RISCOS — as zonas de conflito/perigo do espaço (uma armadilha, um vilão ou aliado possível, um obstáculo).
5. JOGABILIDADE — como o cenário afeta as escolhas/caminhos (quando fizer sentido).

Devolva SOMENTE um JSON válido — sem texto fora dele, sem markdown, sem crase — neste formato exato:
{
  "name": "nome curto do cenário",
  "spec": {
    "funcao_dramatica": "",
    "tempo_espaco": {"tempo": "", "espaco": ""},
    "atmosfera": {"mood": "", "luz": "", "clima": "", "paleta": ""},
    "riscos": ["", ""],
    "jogabilidade": ""
  }
}

Escreva em português do Brasil.
TXT;

        DB::table('prompts')->updateOrInsert(
            ['tenant_id' => $tenantId, 'title' => '🗺️ Arquiteto de Cenário'],
            ['content' => $content, 'updated_at' => now(), 'created_at' => now()],
        );
    }

    public function down(): void
    {
        if (! $tenantId = $this->tenantId()) {
            return;
        }
        DB::table('prompts')->where('tenant_id', $tenantId)->where('title', '🗺️ Arquiteto de Cenário')->delete();
    }
};
