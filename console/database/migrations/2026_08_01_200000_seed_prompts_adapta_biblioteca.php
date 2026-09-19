<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 10 prompts da Biblioteca do Adapta ONE (alunos.adapta.org, capturado 2026-08-01) que fecham
 * dois gaps reais do Reachyn, identificados comparando a biblioteca deles com o que já temos:
 *
 * 1) TEMPLATES RÁPIDOS DE IMAGEM AVULSA — o Quick Start (creation_templates) só cobre vídeo/
 *    animação (reels, história, UGC); a página /imagem é só prompt livre + persona, sem nada
 *    pronto pra tarefas comuns (headshot, foto de produto, logo, anúncio). Não criei
 *    CreationTemplate novo pra isso — os targets do model são só animation|draft, nenhum dos
 *    dois serve pra "colar um prompt pronto na caixa da Imagem avulsa". A biblioteca de Prompts
 *    (kind=null, seção "📝 Prompts" — ver categorização em web/app/(dash)/prompts/page.tsx)
 *    já é exatamente esse mecanismo: texto pronto com placeholders [x], que o cliente copia e
 *    cola onde quiser. Mais simples, mesmo resultado.
 *
 * 2) PROMPTS DE COPY que batem direto no fluxo Pesquisar→Resumo→Editar, mas são RECEITA DE
 *    TAREFA (passo a passo com placeholder), não craft/voz de escrita — não viram "🎬
 *    Roteirista: …" (esse prefixo é escaneado por Studio.tsx pra popular o seletor de persona
 *    de texto, e persona é ESTILO, não uma tarefa de 5 passos). Ficam na mesma seção "📝
 *    Prompts", só copiáveis.
 *
 * Idempotente por título. Semeada no tenant 1 (mesmo padrão das outras seed migrations do
 * Estúdio/BUDO).
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

        $prompts = [
            // ── Imagem avulsa (gap: sem "modelos rápidos" hoje) ──────────────────────────
            '📸 Headshot profissional sem fotógrafo' => 'Anexei uma foto minha. Transforme em um retrato profissional de estúdio: fundo [cor ou estilo, ex: cinza claro degradê], iluminação suave e roupa [ex: camisa social azul marinho]. MANTENHA exatamente o meu rosto como está, sem deformar nenhum traço. Entregue em alta qualidade e me ofereça uma variação com um clima mais acolhedor e fundo desfocado.',
            '📸 Foto de produto para e-commerce' => 'Anexei a foto do meu produto: [produto]. Gere uma foto profissional de catálogo para e-commerce: fundo branco puro, iluminação de estúdio, produto nítido e bem posicionado, sem alterar a embalagem. Depois, gere uma variação do mesmo produto em um ambiente [contexto, ex: bancada de banheiro clean], mantendo a embalagem idêntica.',
            '📸 Logo em minutos' => 'Crie um logo [estilo, ex: minimalista e moderno] para o negócio [nome], do ramo de [segmento]. Símbolo: [ideia do ícone, ex: um grão estilizado com duas folhas]. Tipografia [ex: serifa elegante ou sans serif limpa]. Cores: [ex: marrom escuro e dourado]. Fundo [branco ou transparente]. Me entregue três variações e, depois, ajuste a que eu escolher.',
            '📸 Criativo de anúncio pronto' => 'Crie o criativo de um anúncio para [produto ou oferta], no formato [proporção, ex: 9:16 para stories]. A cena: [descreva a imagem, ex: uma pessoa usando o produto]. Deixe o terço superior com um fundo em degradê escuro, criando espaço negativo para eu inserir o texto da oferta depois. Iluminação profissional, visual de campanha. Se eu pedir, gere também uma versão com o texto [headline da oferta] já embutido e legível na imagem.',

            // ── Copy / conteúdo (gap: receita de tarefa, não craft de escrita) ───────────
            '✍️ De um vídeo para LinkedIn, X e Instagram' => 'Esse é o roteiro de um vídeo que gravei: [cole o roteiro ou a transcrição]. Quero reaproveitá-lo em três formatos, sem levar tráfego para o vídeo, com cada peça viralizando no próprio canal. 1) LinkedIn: escolha o melhor trecho e escreva um post de pelo menos 5 parágrafos, em staccato, com uma primeira frase forte. 2) X: pegue o trecho com mais potencial e transforme em uma Twitter Storm, sem hashtags. 3) Instagram: reformule essa Storm em um carrossel de 6 slides e proponha um post único, com ideia de imagem e legenda detalhada. Me entregue os três.',
            '✍️ Super chat do seu negócio (6Ps)' => 'Eu sou [profissão] e ofereço [serviços que você oferece]. Quero montar os 6Ps do meu negócio para gerar conteúdo. Responda com detalhes, em tópicos: 1) todos os problemas que meu prospect tem em relação à minha solução e como é o dia a dia dele; 2) o problema específico que meu serviço resolve, listando pelo menos 15; 3) os maiores medos dele, no curto e no longo prazo; 4) os erros que ele comete em relação a esses problemas; 5) todos os benefícios do meu serviço; 6) como eu provo que sou uma boa opção como [profissão]. Com base nisso, me dê 5 ideias de post para o Instagram com o objetivo de [objetivo, ex: atrair novos clientes].',
            '✍️ Mapa e quebra de objeções' => 'Crie uma lista com 20 objeções específicas do meu produto: [produto]. Dê exemplos de como contornar cada uma delas de forma específica. Agora, dê uma lista com 20 objeções gerais (tempo, dinheiro, habilidade própria etc.) e como passar por cada uma delas.',
            '✍️ Post por engenharia reversa' => 'Esse post viralizou no LinkedIn: [cole o post viral]. Me explique por que você imagina que ele viralizou, destacando os princípios por trás, e, usando esses mesmos princípios, escreva um post novo que comece com: [sua primeira frase ou tese].',
            '✍️ 20 posts de uma vez' => 'Gere 20 ideias de post sobre [tema ou área], pensadas para [seu público]. Varie os formatos (dica, bastidor, história, mito vs verdade, lista, pergunta) e, para cada ideia, traga o título e a primeira frase. Depois que eu escolher as que mais gostei, desenvolva o texto completo de cada uma, mantendo o meu tom: [descreva seu tom ou cole um exemplo].',
            '✍️ Crítico de copy sem filtros' => 'Você é [personalidade de referência na tarefa, ex: um vendedor lendário] e seu objetivo é encontrar falhas em tudo que eu te mandar. Você não deve pegar leve e deve apontar todas as falhas nas minhas criações ou nos meus pensamentos e propor abordagens diferentes para que eu possa ajustar. Leve em consideração a persona que minha empresa atende e o meu produto. Aqui está a persona que atendo: [descrição da persona]. [Cole a peça que quer analisar] Se você tivesse que reescrever a mensagem, como ela ficaria? Simule que você é meu cliente, leu essa mensagem, mas não comprou. Por que você não fez isso?',
        ];

        foreach ($prompts as $title => $content) {
            DB::table('prompts')->updateOrInsert(
                ['tenant_id' => $tenantId, 'title' => $title, 'kind' => null],
                ['content' => $content, 'updated_at' => now(), 'created_at' => now()],
            );
        }
    }

    public function down(): void
    {
        if (! $tenantId = $this->tenantId()) {
            return;
        }
        DB::table('prompts')->where('tenant_id', $tenantId)->whereIn('title', [
            '📸 Headshot profissional sem fotógrafo', '📸 Foto de produto para e-commerce',
            '📸 Logo em minutos', '📸 Criativo de anúncio pronto',
            '✍️ De um vídeo para LinkedIn, X e Instagram', '✍️ Super chat do seu negócio (6Ps)',
            '✍️ Mapa e quebra de objeções', '✍️ Post por engenharia reversa',
            '✍️ 20 posts de uma vez', '✍️ Crítico de copy sem filtros',
        ])->delete();
    }
};
