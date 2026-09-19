<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Semeia a família WEB-DOC (colagem editorial em motion graphics) na aba Prompts: o estilo
// de "canal dark" explicativo — fotos de arquivo recortadas sobre campos de cor chapados,
// anotações à mão se desenhando, gráficos abstratos, tudo em movimento estalado, com a
// narração entrando só na montagem. É o oposto do live-action: o quadro é uma COLAGEM.
//
// Três entradas, uma por eixo (mesmo desenho do seed de personas de estilo de 2026-07-20):
//   · 🎨 Estilo: ... (kind=image) → composição do frame (o que existe no quadro)
//   · 🎥 Estilo: ... (kind=video) → movimento (câmera, ritmo, impactos)
//   · 🎬 Roteirista: ... (kind NULL) → a fórmula do texto falado
//
// Sobre o kind do roteirista: a coluna `kind` aceita SÓ 'image'|'video' (PromptController
// valida `in:image,video` e o index aborta 422 fora disso) — 'text' não existe no contrato.
// Prompt de texto é kind NULL por convenção, e a UI (web/app/(dash)/prompts/page.tsx)
// agrupa o roteirista pelo prefixo do título "🎬 Roteirista:". Por isso a persona de
// roteiro entra como kind NULL com esse prefixo, e não com um kind novo.
//
// Origem: receita destilada de uma referência de jornalismo explicativo em vídeo; o nome do
// veículo não vai pro produto (é marca de terceiro) — o estilo se chama "web-doc".
// White-label: nenhum nome de provedor de IA aparece no conteúdo.
//
// Idempotente por (tenant_id, title). O down() remove exatamente estes três títulos.
return new class extends Migration
{
    /** Tenant dono do seed: o primeiro (RedFox id 1 em prod). Null = banco sem tenant → não semeia. */
    private function tenantId(): ?int
    {
        return DB::table('tenants')->orderBy('id')->value('id');
    }

    /** @return array<int,array{kind:?string,title:string,content:string}> */
    private function personas(): array
    {
        return [
            [
                'kind' => 'image',
                'title' => '🎨 Estilo: Colagem Editorial (web-doc)',
                'content' => 'Componha cada quadro como COLAGEM editorial animada, nunca como cena filmada: a fotografia existe DENTRO da colagem, como elemento recortado, e o quadro inteiro jamais é live-action. '
                    ."\n\n"
                    .'RECORTES DE ARQUIVO: sujeitos fotográficos — pessoas, prédios, objetos — recortados com borda de papel branca irregular (cut-out with rough white paper edge), flutuando ou encaixando sobre fundos chapados, com sombra projetada sutil (drop shadow) que vende o "recortado e colado à mão".'
                    ."\n"
                    .'CAMPOS DE COR CHAPADOS: amarelo quente, off-white de papel, azul-marinho profundo, vermelho coral. UMA cor dominante por cena; a paleta de acento é a mesma no vídeo inteiro (flat color field, solid background).'
                    ."\n"
                    .'TEXTURA DE PAPEL E IMPRESSÃO: grão, retícula de meio-tom (halftone dots), papel jornal (newsprint), bordas rasgadas (torn paper edge), tiras de fita adesiva (tape strips), leve desalinho de registro de impressão.'
                    ."\n"
                    .'ANOTAÇÃO À MÃO: círculo de marcador se desenhando ao redor de um recorte, sublinhado varrendo, seta conectando dois elementos, traço de ênfase (hand-drawn marker annotation). APENAS traços abstratos — nunca letras, nunca palavras.'
                    ."\n"
                    .'GRÁFICO DE DADOS ABSTRATO: barras crescendo, linha se desenhando, fatia de pizza se separando — sem rótulo, sem numeral, sem texto de eixo. Pura forma e movimento.'
                    ."\n"
                    .'MAPA: mapa estilizado e chapado com rota animada, ponto pulsante, região preenchendo de cor.'
                    ."\n"
                    .'CENSURA E DESTAQUE: barra sólida deslizando sobre uma área, vinheta de holofote isolando um recorte enquanto o resto escurece.'
                    ."\n"
                    .'COMPARAÇÃO DE ESCALA: um objeto multiplicando em fileiras, recorte pequeno ao lado de um gigante, pilha crescendo.'
                    ."\n\n"
                    .'Combine DOIS OU TRÊS desses recursos por cena, escolhidos para ilustrar LITERALMENTE a frase narrada — a imagem é legenda visual, não decoração.'
                    ."\n\n"
                    .'EVITE (negativo): texto legível, letras, palavras, números, legenda, marca d\'água, logotipo, fotorrealismo, filmagem live-action, render 3D, sincronia labial, personagem falando, deriva de cor entre cenas.',
            ],
            [
                'kind' => 'video',
                'title' => '🎥 Estilo: Motion Graphics Editorial (web-doc)',
                'content' => 'Anime a colagem com movimento ESTALADO e intencional — cada deslocamento tem motivo, nada flutua à toa.'
                    ."\n\n"
                    .'ENTRADAS: ease-out rápido; elementos deslizam ou estouram no lugar com leve overshoot. Push-in lento e deliberado nos momentos de "escuta isso". Whip-pan ou virada de página entre ideias. Parallax entre as camadas da colagem (recorte na frente, campo de cor atrás).'
                    ."\n"
                    .'DENSIDADE: algo deve estar SEMPRE se movendo, mas só UMA coisa é "alta" por vez — o resto é deriva de fundo.'
                    ."\n"
                    .'IMPACTO: um a cada ~3 segundos — batida, carimbo, onda de choque, estalo — e pelo menos uma rampa de velocidade por bloco (batida em câmera lenta que vira whip).'
                    ."\n"
                    .'ESCALA: alterne macro extremo e plano geral; provoque chicote de escala (rosto gigante → figuras minúsculas → objeto colossal).'
                    ."\n"
                    .'CONTINUIDADE: escreva cada clipe como UM movimento contínuo de câmera FPV que começa e termina em pleno motion blur — assim o corte duro entre blocos lê como um plano só.'
                    ."\n"
                    .'ÁUDIO: ninguém fala em cena. O som é ambiente e efeito; a narração entra na montagem.'
                    ."\n\n"
                    .'EVITE: texto legível, letras, números, legenda, logotipo, fotorrealismo, live-action, render 3D, sincronia labial, personagem falando, câmera parada por mais de dois segundos.',
            ],
            [
                'kind' => null,
                'title' => '🎬 Roteirista: Web-doc (colagem editorial)',
                'content' => 'Escreva o roteiro em N blocos, um por clipe de aproximadamente 10 segundos. Cada bloco tem de 20 a 24 palavras (cerca de 8 a 9 segundos falados; teto absoluto de 9,5 segundos).'
                    ."\n\n"
                    .'FORMA: só texto falado. Sem rubrica, sem parêntese, sem indicação de cena. Números sempre por extenso ("setenta por cento", "dois mil e vinte e quatro").'
                    ."\n\n"
                    .'ESTRUTURA:'
                    ."\n".'· Bloco 1 — abertura fria: o fato ou a pergunta mais surpreendente, dito seco. Sem saudação, sem "neste vídeo".'
                    ."\n".'· Bloco 2 — o que está em jogo: por que isso é estranho, ou por que importa para quem assiste.'
                    ."\n".'· Blocos do meio — evidência: UMA ideia por bloco, cada uma ancorada em número, data, lugar ou comparação concreta. Escalando em peso.'
                    ."\n".'· Bloco N−1 — a virada: a revelação contraintuitiva, o "mas acontece que".'
                    ."\n".'· Bloco N — resolução e chute final: entrega a resposta e termina numa frase que ressignifica o fato da abertura.'
                    ."\n\n"
                    .'TOM: curioso, preciso, um pouco irônico. Frases declarativas curtas. O narrador explica, nunca faz hype.'
                    ."\n\n"
                    .'OBJETO CONDUTOR: escolha UMA metáfora física única e faça ela atravessar TODOS os blocos, escalando — um pavio queimando, um balão sendo inflado rumo a uma agulha. O bloco final paga esse fio.'
                    ."\n\n"
                    .'GANCHO: plante uma pergunta na abertura e responda só no fim.',
            ],
        ];
    }

    public function up(): void
    {
        if (! $tenantId = $this->tenantId()) {
            return;
        }
        $now = now();
        foreach ($this->personas() as $p) {
            DB::table('prompts')->updateOrInsert(
                ['tenant_id' => $tenantId, 'title' => $p['title']],
                ['kind' => $p['kind'], 'content' => $p['content'], 'updated_at' => $now, 'created_at' => $now],
            );
        }
    }

    public function down(): void
    {
        if (! $tenantId = $this->tenantId()) {
            return;
        }
        // Só os três títulos que este seed criou — nada do cliente é tocado.
        DB::table('prompts')
            ->where('tenant_id', $tenantId)
            ->whereIn('title', array_column($this->personas(), 'title'))
            ->delete();
    }
};
