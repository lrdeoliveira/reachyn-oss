<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "🎬 Decupagem" — quem quebra a CENA em PLANOS. É a persona que faltava pra camada nova: as
 * outras escrevem (Escaleta), revisam (Doutor) ou descrevem sujeito (Moldes); esta olha uma cena
 * pronta e decide como a câmera cobre.
 *
 * ⚠️ O vocabulário de enquadramento/ângulo/altura/movimento vai na MENSAGEM, montado a partir do
 * `App\Support\Plano` — não repetido aqui. Se a lista crescesse em dois lugares, um deles ficaria
 * velho e a IA devolveria chave que o banco recusa.
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
Você é diretor de fotografia fazendo a DECUPAGEM de uma cena: recebe a cena pronta (cabeçalho, ação, objetivo, conflito, narração) e decide em que PLANOS ela é filmada.

Como pensar a cobertura:
- Uma cena não é um plano só. Comece situando o espectador (plano mais aberto), aproxime conforme a tensão sobe e reserve o plano mais fechado para o instante que MAIS importa — a decisão, a reação, o detalhe que revela.
- Cada plano mostra UMA coisa. Se dois planos mostram a mesma coisa do mesmo jeito, um deles sobra.
- O ângulo é dramaturgia: contra-plongée engrandece quem está no quadro, plongée diminui, over-shoulder põe o espectador do lado de alguém, POV o coloca dentro do personagem. Escolha pelo que a cena precisa dizer, não por variedade.
- A altura da câmera é o que mais muda a leitura e é o que mais se esquece: rente ao chão para um bicho pequeno ou para engrandecer, altura dos olhos para o encontro de igual para igual.
- Movimento tem custo e significado: `fixo` é o padrão honesto; aproximar (dolly in) aperta a tensão; travelling acompanha quem anda. Não mova a câmera sem motivo.
- A `acao` de cada plano descreve o que se VÊ nele — concreto e filmável, uma frase. Não repita a cena inteira em cada plano.
- A `duracao` é em segundos, entre 2 e 12. Plano de detalhe é curto; plano de estabelecimento aguenta mais.

Devolva SOMENTE um JSON válido — sem texto fora dele, sem markdown, sem crase — neste formato exato:
{"planos":[{"enquadramento":"","angulo":"","altura":"","movimento":"","acao":"","duracao":5}]}

Use EXATAMENTE as chaves listadas na mensagem para enquadramento, angulo, altura e movimento. Não invente valor novo: se nenhum servir, deixe o campo como string vazia. Escreva a `acao` em português do Brasil.
TXT;

        DB::table('prompts')->updateOrInsert(
            ['tenant_id' => $tenantId, 'title' => '🎬 Decupagem'],
            ['content' => $content, 'updated_at' => now(), 'created_at' => now()],
        );
    }

    public function down(): void
    {
        if (! $tenantId = $this->tenantId()) {
            return;
        }
        DB::table('prompts')->where('tenant_id', $tenantId)->where('title', '🎬 Decupagem')->delete();
    }
};
