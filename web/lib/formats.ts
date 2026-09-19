// Biblioteca de FORMATOS VIRAIS da Fábrica de Conteúdo Reachyn — curadoria própria dos formatos
// consagrados de vídeo faceless (taxonomia pública da indústria de conteúdo). Cada formato traz a
// estrutura (gancho → desenvolvimento → clímax → CTA), o gatilho psicológico, a duração ideal e a
// dificuldade. É a fonte dos "templates 1-clique": clicar num formato pré-preenche o Estúdio com um
// roteiro-semente daquele formato, que a IA estrutura em cenas.
//
// Convenção de handoff (ideia/formato → roteiro): grava um IdeaSeed em sessionStorage
// (chave SEED_KEY) e navega pro /estudio; o Animacao lê a semente no mount e aplica.

export type Dificuldade = "fácil" | "médio" | "difícil";

export type ViralFormat = {
  nome: string;
  descricao: string;
  estrutura: string; // gancho → desenvolvimento → clímax → CTA (adaptado ao formato)
  gatilho: string; // o gatilho psicológico
  duracao: string; // duração ideal
  dificuldade: Dificuldade;
};

export const VIRAL_FORMATS: ViralFormat[] = [
  { nome: "O que acontece se…", descricao: "Explora a consequência de uma ação extrema ao longo do tempo.", estrutura: "Gancho (a consequência mais chocante primeiro) → dia a dia / fase por fase em ordem → clímax (efeito mais dramático no fim) → CTA ('qual te surpreendeu mais?')", gatilho: "Curiosidade instintiva (o cérebro precisa saber a resposta)", duracao: "30-60s (ou 8-12min)", dificuldade: "médio" },
  { nome: "Coisas que você usa e não sabe pra quê", descricao: "Revela o propósito oculto de objetos do cotidiano.", estrutura: "Gancho (o objeto mais surpreendente) → cada item 15-30s em close → CTA ('qual mais te surpreendeu? comenta')", gatilho: "Curiosidade + familiaridade ('como eu não sabia disso?')", duracao: "30-45s (ou 5-8min)", dificuldade: "fácil" },
  { nome: "Antes e Depois / Restauração", descricao: "Transformação completa de um objeto destruído até ficar como novo.", estrutura: "Abertura (objeto destruído em close) → processo etapa por etapa → reveal final em câmera lenta → side-by-side antes/depois", gatilho: "Transformação (o contraste visual libera dopamina; viciante)", duracao: "30-60s (ou 10-15min)", dificuldade: "difícil" },
  { nome: "Comparação (barato vs caro)", descricao: "Compara produto barato vs caro no mesmo critério — vale a diferença?", estrutura: "Gancho (os dois lado a lado com preços) → testes 1,2,3 em close → reveal (quem venceu cada teste) → veredicto", gatilho: "Validação de gasto + debate (prova social)", duracao: "45-60s (ou 8-12min)", dificuldade: "médio" },
  { nome: "Mistério / História que você nunca ouviu", descricao: "Storytelling sobre um fato fascinante e exclusivo.", estrutura: "Gancho (frase chocante) → contexto (quando/onde/quem) → tensão progressiva → clímax → desfecho + reflexão", gatilho: "Curiosidade + exclusividade + narrativa", duracao: "12-20min (ou 60s resumo)", dificuldade: "difícil" },
  { nome: "Satisfying (sem narração)", descricao: "Sequência de clipes hipnóticos com som ASMR natural.", estrutura: "Sem narração → clipes de 3-8s → do menos ao mais satisfying → loop (o final conecta ao início)", gatilho: "Recompensa sensorial (retenção altíssima, replay)", duracao: "15-60s", dificuldade: "fácil" },
  { nome: "Explicando como se você tivesse 5 anos", descricao: "Simplifica um conceito complexo com analogias do cotidiano.", estrutura: "Gancho ('todo mundo fala de X mas ninguém entende') → analogia simples → visual por conceito → teste ('agora você sabe mais que 90%')", gatilho: "Alívio da frustração + gratidão (compartilhável)", duracao: "5-10min", dificuldade: "difícil" },
  { nome: "Life hack / Truque de X segundos", descricao: "Truque rápido que resolve um problema comum.", estrutura: "Mostra o problema (0-2s) → demonstra o hack com as mãos → resultado → CTA ('salva pra testar depois')", gatilho: "Atalho / valor máximo em tempo mínimo", duracao: "15-30s", dificuldade: "fácil" },
  { nome: "Reação / Análise ('por que X viralizou?')", descricao: "Disseca por que um viral/fenômeno funcionou.", estrutura: "Gancho (mostra o viral, 2s) → pergunta ('por que isso viralizou?') → análise (gancho, emoção, timing) → lição → CTA", gatilho: "Curiosidade sobre o porquê + conhecimento exclusivo", duracao: "8-15min", dificuldade: "médio" },
  { nome: "Top N / Ranking", descricao: "Lista rankeada de um tema com audiência grande.", estrutura: "Gancho ('comenta se acha que acerta o nº1') → itens 10-4 → item 3 ('agora fica sério') → item 2 (pausa) → item 1 (reveal épico)", gatilho: "Curiosidade + debate + progressão (retenção até o nº1)", duracao: "10-15min (ou 30-60s)", dificuldade: "fácil" },
  { nome: "Financeiro ('como X ganha dinheiro')", descricao: "Revela o modelo de negócio de uma pessoa/empresa.", estrutura: "Gancho (faturamento impressionante) → contexto → revelação das fontes de receita → números → lição", gatilho: "Curiosidade + inveja produtiva ('posso fazer isso também')", duracao: "10-15min", dificuldade: "difícil" },
  { nome: "Countdown / Contagem regressiva", descricao: "Timer na tela conta até um reveal dramático.", estrutura: "0s (timer + contexto) → cada intervalo um novo fato → 10s finais (música intensifica) → 0 (o reveal) → reação/consequência", gatilho: "Urgência artificial (sair antes do zero gera frustração)", duracao: "60s (ou 5-10min)", dificuldade: "médio" },
  { nome: "Tutorial rápido ('aprenda X em Y')", descricao: "Ensina uma habilidade com resultado visual em tempo curtíssimo.", estrutura: "Gancho ('em [tempo] você vai saber fazer [coisa]') → demonstração acelerada (3-5 passos em close) → resultado → CTA ('agora tenta')", gatilho: "Promessa de aprender rápido (atalho irresistível)", duracao: "60s-3min", dificuldade: "fácil" },
  { nome: "Polêmica / Opinião contrária", descricao: "Afirmação contraintuitiva contra o senso comum.", estrutura: "Gancho (afirmação bombástica) → 3-5 argumentos com dados → reconhece o outro lado → conclusão → CTA ('concorda ou discorda?')", gatilho: "Dissonância cognitiva (defender posição = comentário)", duracao: "8-15min", dificuldade: "difícil" },
  { nome: "Unboxing / Teste ('vale a pena?')", descricao: "Testa um produto viral e dá veredicto de compra.", estrutura: "Gancho ('gastei R$X pra você não precisar') → unboxing → 3-5 testes práticos em close → veredicto (nota 0-10) → CTA", gatilho: "Intenção de compra (gatilho de busca forte; CPM alto)", duracao: "30-60s (ou 5-10min)", dificuldade: "médio" },
  { nome: "Horror / Mistério ('a verdade que ninguém conta')", descricao: "Revela gradualmente a verdade perturbadora sobre algo.", estrutura: "Gancho (imagem/som perturbador + pergunta) → o que pensam que sabem → revelação gradual → clímax → mistério que permanece", gatilho: "Adrenalina + medo + exclusividade", duracao: "12-20min", dificuldade: "difícil" },
  { nome: "Curiosidade rápida ('isso existe e você não sabia')", descricao: "Compilado de clipes curtos surpreendentes com narração calma.", estrutura: "Cada clip 5-15s → uma frase de narração por clip → corte direto → 8-15 clips → o mais impressionante no fim", gatilho: "Curiosidade em série ('o próximo pode ser melhor')", duracao: "3-5min (ou Shorts)", dificuldade: "fácil" },
  { nome: "Desafio com restrição", descricao: "Desafio com obstáculo e desfecho incerto (estilo MrBeast).", estrutura: "Gancho (restrição + recompensa) → preparação → dificuldades/momentos → reta final → reveal (conseguiu ou não?)", gatilho: "Narrativa herói-obstáculo-desfecho (reality)", duracao: "60s (ou 10-20min)", dificuldade: "médio" },
  { nome: "ASMR visual (close extremo)", descricao: "Só som natural + close extremo das mãos executando ações.", estrutura: "Sem narração → clips de 5-15s → foco no som em alta qualidade → do suave ao impactante → loop", gatilho: "Relaxamento sensorial (replay infinito)", duracao: "15-60s (ou 5-30min)", dificuldade: "fácil" },
  { nome: "Recriando trend viral", descricao: "Recria uma trend com twist pessoal pra pegar carona no algoritmo.", estrutura: "Gancho (a trend original, 1-2s) → 'vou tentar do meu jeito' → recriação → compara com o original → veredicto", gatilho: "Pega-carona / medo de perder a onda", duracao: "15-60s", dificuldade: "fácil" },
  { nome: "Um dia na vida / Vlog", descricao: "Mostra a rotina de uma profissão ou situação (faceless via bastidores narrados).", estrutura: "Gancho (a parte mais inusitada do dia) → cronológico (só os melhores trechos) → clímax → CTA", gatilho: "Curiosidade sobre o cotidiano alheio", duracao: "8-15min", dificuldade: "médio" },
  { nome: "Draw My Life / História ilustrada", descricao: "Conta uma história com desenhos narrados e humor.", estrutura: "Gancho (o desfecho intrigante) → situação inicial → desenvolvimento com desenhos → clímax → resolução", gatilho: "Empatia + narrativa + humor", duracao: "5-10min", dificuldade: "difícil" },
  { nome: "Tag / Perguntas", descricao: "Formato pronto ('50 fatos sobre X', 'eu nunca', 'quem é mais provável').", estrutura: "Gancho (a pergunta mais polêmica) → sequência de perguntas/respostas → resposta surpreendente → CTA (marca alguém)", gatilho: "Identificação + curiosidade + interação", duracao: "30-60s (ou 5-10min)", dificuldade: "fácil" },
  { nome: "React", descricao: "Reação a um conteúdo em hype (trailer, música, meme, notícia).", estrutura: "Começa a reação imediatamente → reações nos momentos-chave → comentário/opinião → CTA", gatilho: "Hype / prova social (palavra-chave em alta)", duracao: "60s (ou 5-15min)", dificuldade: "fácil" },
  { nome: "Tutorial ('como fazer X')", descricao: "Ensina passo a passo a resolver um problema.", estrutura: "Gancho (o resultado final / o problema) → passos objetivos em close → resultado → CTA (salvar/inscrever)", gatilho: "Solução de um problema (alta conversão em inscrito)", duracao: "60s-3min (ou 5-10min)", dificuldade: "fácil" },
  { nome: "DIY / Faça você mesmo", descricao: "Cria algo do zero com materiais acessíveis.", estrutura: "Gancho (o resultado pronto) → materiais → processo em close/time-lapse → reveal final → CTA", gatilho: "Transformação + inspiração criativa", duracao: "30-60s (ou 5-10min)", dificuldade: "médio" },
  { nome: "Culinária / Receita (close top-down)", descricao: "Prepara uma receita filmada de cima, só as mãos.", estrutura: "Gancho (prato pronto / ingrediente inusitado) → preparo em close acelerado → reveal do prato → CTA", gatilho: "Apetite + satisfação (ASMR de comida)", duracao: "30-60s (ou 5-10min)", dificuldade: "fácil" },
  { nome: "Haul / Compras", descricao: "Mostra produtos recém-comprados / novidades.", estrutura: "Gancho (o item mais legal / o valor gasto) → mostra item a item → destaque → CTA", gatilho: "Curiosidade + intenção de compra", duracao: "30-60s (ou 5-8min)", dificuldade: "fácil" },
  { nome: "Q&A / Respondendo comentários", descricao: "Responde perguntas/comentários da audiência.", estrutura: "Gancho (a pergunta mais provocativa) → respostas curtas em sequência → a mais aguardada → CTA", gatilho: "Interação + pertencimento à comunidade", duracao: "3-8min", dificuldade: "fácil" },
  { nome: "Quiz / Desafio pronto", descricao: "Desafio de conhecimento com o espectador ('só 5% acertam').", estrutura: "Gancho (a promessa) → perguntas com timer → respostas → placar final → CTA ('quantos você acertou?')", gatilho: "Competição / autovalidação", duracao: "30-60s (ou 3-8min)", dificuldade: "fácil" },
  { nome: "Newsjacking / Notícias quentes", descricao: "Traz uma notícia em hype rapidamente pra pegar a onda de busca.", estrutura: "Gancho (a manchete bombástica) → o que aconteceu → contexto/impacto → CTA ('o que você acha?')", gatilho: "Novidade + urgência (timing é tudo)", duracao: "60s (ou 3-8min)", dificuldade: "médio" },
  { nome: "Compilado / Melhores momentos", descricao: "Reúne os melhores clipes de um tema.", estrutura: "Gancho (o clipe mais forte primeiro) → sequência ritmada → o melhor guardado pro final → CTA", gatilho: "Curiosidade em série + entretenimento", duracao: "30-60s (ou 5-10min)", dificuldade: "fácil" },
  { nome: "Cortes / Clips", descricao: "Corta o melhor momento de um conteúdo longo num clipe clicável.", estrutura: "Gancho (o pico do momento em 1-2s) → contexto rápido → desenvolvimento → punch final → CTA (vídeo completo)", gatilho: "Curiosidade + humor / choque", duracao: "15-60s", dificuldade: "fácil" },
  { nome: "Animais / Pets", descricao: "Conteúdo com animais (fofura, curiosidade, comportamento).", estrutura: "Gancho (a cena mais fofa/inusitada) → desenvolvimento → clímax → CTA", gatilho: "Fofura / emoção (altamente compartilhável)", duracao: "15-60s", dificuldade: "fácil" },
  { nome: "Experimento ('o que acontece quando…')", descricao: "Testa um experimento visual com desfecho incerto.", estrutura: "Gancho (a pergunta / o setup) → o experimento → clímax (o resultado) → reação/explicação → CTA", gatilho: "Curiosidade + surpresa", duracao: "30-60s (ou 5-8min)", dificuldade: "médio" },
  { nome: "Curiosidades (fatos de um tema)", descricao: "Fatos surpreendentes sobre um tema específico.", estrutura: "Gancho (o fato mais chocante) → fatos em sequência → o mais surpreendente no fim → CTA", gatilho: "Curiosidade + valor informativo", duracao: "30-60s (ou 5-10min)", dificuldade: "fácil" },
  { nome: "Série (conteúdo recorrente)", descricao: "Episódios encadeados que escalam em dificuldade e criam expectativa.", estrutura: "(por episódio) Gancho → conteúdo → clímax → teaser do próximo (mais desafiador)", gatilho: "Expectativa / continuidade (fideliza)", duracao: "varia (Shorts a longo)", dificuldade: "médio" },
  { nome: "Novidades / Lançamentos", descricao: "Mostra o que há de novo num nicho.", estrutura: "Gancho (o lançamento mais importante) → destaques → opinião rápida → CTA", gatilho: "Novidade + FOMO", duracao: "60s (ou 5-8min)", dificuldade: "fácil" },
  { nome: "Vídeo interativo ('escolha seu caminho')", descricao: "O espectador decide o rumo e o vídeo responde.", estrutura: "Gancho (a escolha) → caminhos alternativos → desfecho por escolha → CTA ('comente sua escolha')", gatilho: "Participação / agência", duracao: "30-60s (ou 5-10min)", dificuldade: "médio" },
  { nome: "Paródia", descricao: "Versão humorística/satírica de algo em hype.", estrutura: "Gancho (o exagero cômico) → desenvolvimento da piada → punch final → CTA", gatilho: "Humor + hype", duracao: "15-60s (ou 3-8min)", dificuldade: "médio" },
  { nome: "Aula / Autoridade", descricao: "Ensina algo em profundidade demonstrando autoridade.", estrutura: "Gancho (a promessa / erro comum) → conteúdo estruturado → aplicação prática → CTA", gatilho: "Autoridade + utilidade (fã fiel e comprador)", duracao: "5-15min", dificuldade: "médio" },
  { nome: "Viral internacional (recriar/traduzir)", descricao: "Traz um viral estrangeiro para o público local antes que chegue.", estrutura: "Gancho (o viral original) → contexto/tradução → sua versão ou reação → CTA", gatilho: "Novidade + pega-carona (chega antes)", duracao: "15-60s (ou 3-8min)", dificuldade: "fácil" },
  { nome: "Cortes de Podcast", descricao: "Foco nos cortes clicáveis de uma conversa.", estrutura: "Gancho (a fala mais polêmica) → contexto → desenvolvimento → punch → CTA (episódio completo)", gatilho: "Autoridade + curiosidade + polêmica", duracao: "30-60s (cortes)", dificuldade: "médio" },
  { nome: "Vídeo relaxante (natureza/lo-fi/sono)", descricao: "Conteúdo pra relaxar/dormir sem narração.", estrutura: "Sem gancho verbal → ambiente contínuo e imersivo → loop", gatilho: "Relaxamento (sessões longas, replay)", duracao: "10min-1h+", dificuldade: "fácil" },
  { nome: "Gameplay (sem rosto)", descricao: "Jogabilidade narrada ou legendada sem mostrar o rosto.", estrutura: "Gancho (o momento épico / desafio) → gameplay comentado → clímax → CTA", gatilho: "Entretenimento + identificação", duracao: "60s (ou 10-20min)", dificuldade: "fácil" },
  { nome: "Infográfico / Dados", descricao: "Explica dados/estatísticas com gráficos animados e narração.", estrutura: "Gancho (o dado mais impressionante) → construção visual dos dados → insight → CTA", gatilho: "Curiosidade + autoridade (dados concretos)", duracao: "5-12min (ou 30-60s)", dificuldade: "médio" },
  { nome: "Vídeo narrado (voz-over sobre imagens)", descricao: "Narração sobre b-roll — a base de qualquer canal faceless.", estrutura: "Gancho narrado (0-3s) → desenvolvimento com imagens de apoio → clímax → CTA", gatilho: "Storytelling / curiosidade", duracao: "60s (ou 5-15min)", dificuldade: "fácil" },
  { nome: "Entrevista / Q&A com convidado", descricao: "Perguntas a um especialista (faceless via áudio + b-roll).", estrutura: "Gancho (a resposta mais forte) → perguntas encadeadas → clímax → CTA", gatilho: "Autoridade + curiosidade", duracao: "cortes 30-60s (ou 10-30min)", dificuldade: "médio" },
  { nome: "Vídeo motivacional", descricao: "Mensagem inspiradora com narração + imagens/música.", estrutura: "Gancho (a frase de impacto) → construção emocional crescente → clímax motivacional → CTA", gatilho: "Emoção / inspiração (alto compartilhamento)", duracao: "30-60s (ou 3-8min)", dificuldade: "fácil" },
  { nome: "Super Lista (mega-ranking)", descricao: "Lista extensa e escaneável de um tema com muita audiência.", estrutura: "Gancho (o item mais forte / 'espera até o nº1') → itens ritmados → melhores no fim → CTA", gatilho: "Curiosidade + progressão + debate", duracao: "10-20min", dificuldade: "médio" },
];

// ── Handoff ideia/formato → roteiro pré-preenchido (lido pelo Animacao no mount) ──────────────
export const SEED_KEY = "reachyn_idea_seed";

export type IdeaSeed = {
  script: string; // o TEMA / semente que a IA vai estruturar
  modo?: "historia" | "animacao" | "quadrinhos";
  style?: string;
  scenes?: number;
  aspect?: string;
  pace?: string;
  // Campos extras dos TEMPLATES da aba Rápido (opcionais — a Ideia/formato não os usa):
  quality?: string;
  sequenceMode?: "solto" | "encadeado" | "plano";
  palette?: string;
  preCharId?: number | null; // 🎭 personagem da biblioteca escolhido no template (amarra pós-parse)
  from?: string; // rótulo de origem (ex: "ideia" | "formato" | "template") pra telemetria/UX
};

// Grava a semente e leva pra aba Mídia (criar a mídia do post a partir da ideia). O Estúdio de
// Animação (short narrado / história / quadrinhos) foi pro FoxAssets em 2026-07-23; online a
// ideia vira a mídia do post na aba Mídia. A semente segue no sessionStorage pra referência.
export function seedStudioAndGo(seed: IdeaSeed) {
  try {
    sessionStorage.setItem(SEED_KEY, JSON.stringify(seed));
  } catch {
    /* sessionStorage indisponível (modo privado) — segue mesmo assim */
  }
  window.location.href = `/midia`;
}
