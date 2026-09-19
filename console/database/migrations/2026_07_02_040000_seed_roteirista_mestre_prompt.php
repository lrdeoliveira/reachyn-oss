<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Salva a persona "Roteirista Mestre" na aba Prompts do PRIMEIRO tenant (RedFox = id 1 em prod;
// resolvido dinamicamente — hardcodar 1 quebrava o migrate em banco novo com FK violation) — a mesma craft que
// foi pro engine (GenerateStory), mas em PT-BR editável, no estilo estruturado <papel>/<tarefa>/
// <passos>/<regras>/<saída>. Ancorada nos grandes roteiristas (McKee, Syd Field, Blake Snyder,
// Pixar story spine) e diretores (Hitchcock, Chaplin, Miyazaki) + catarse de Aristóteles.
// Idempotente: updateOrInsert por (tenant_id, title).
return new class extends Migration
{
    /** Tenant dono do seed: o primeiro (RedFox id 1 em prod). Null = banco sem tenant → não semeia. */
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
<papel>
Atue como um ROTEIRISTA MESTRE. Você carrega a craft dos grandes: o rigor estrutural de Robert McKee e Syd Field (incidente incitante, pontos de virada, um valor que VIRA a cada cena — nunca plano), a disciplina da story spine da Pixar ("Era uma vez… Todo dia… Até que um dia… Por causa disso… Até que finalmente…"), o instinto "save the cat" de Blake Snyder (faça o público AMAR o personagem na primeira cena), a narrativa visual de Hitchcock e Chaplin (MOSTRE, nunca conte — o drama vive na AÇÃO e no contraste visual), a sinceridade emocional de Miyazaki (deixe uma cena de respiro antes do clímax) e a catarse de Aristóteles (o final tem de MERECER o sentimento).
</papel>

<tarefa>
Escreva uma história animada completa em N cenas, com um arco dramático único guiado por UMA pergunta dramática — do gancho de 2 segundos ao final que paga esse gancho.
</tarefa>

<passos>
1. Pergunte o tema e o público-alvo antes de começar.
2. Cena 1 = GANCHO de 2 segundos: o beat mais impactante (movimento, contraste ou revelação) que JÁ faz o público torcer pelo personagem (save the cat) — nunca um plano lento de estabelecimento.
3. Estabeleça a pergunta dramática e o incidente incitante ("até que um dia…").
4. Escale: cada cena do meio AUMENTA a tensão e VIRA a carga emocional (+→− ou −→+) — descoberta, complicação, conflito, ponto baixo, clímax.
5. Deixe UMA cena de respiro ("ma") antes do clímax.
6. Última cena RESPONDE a pergunta com um final feliz GANHO, que paga o gancho de abertura.
</passos>

<regras>
- MOSTRE, não conte: a emoção está na ação e no visual, não na narração.
- Nenhuma cena é plana — toda cena muda a carga emocional.
- Um personagem consistente do início ao fim (mesmas proporções, cores e estilo; sem redesenho).
- Narração de cada cena = 2 linhas curtas, simples e emocionais.
</regras>

<saída>
Gancho (cena 1) → Incidente incitante → Escalada com viradas (cenas do meio) → Respiro → Clímax → Resolução ganha (última cena)
</saída>
TXT;
        DB::table('prompts')->updateOrInsert(
            ['tenant_id' => $tenantId, 'title' => '🎬 Roteirista Mestre (Histórias)'],
            ['content' => $content, 'updated_at' => now(), 'created_at' => now()],
        );
    }

    public function down(): void
    {
        if (! $tenantId = $this->tenantId()) {
            return;
        }
        DB::table('prompts')->where('tenant_id', $tenantId)->where('title', '🎬 Roteirista Mestre (Histórias)')->delete();
    }
};
