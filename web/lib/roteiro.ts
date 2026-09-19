// FoxAssets — modelo + persistência do CANVAS DE ROTEIRO (fluxo de nós, estilo Twine/Arcweave).
// Híbrido: linear por padrão (cada nó novo liga do anterior), ramifica quando você puxa uma 2ª
// saída. Cada nó é uma CENA que carrega prompt visual + narração e gera mídia ali mesmo (imagem
// via mmx-bridge, narração via /studio/tts, clipe via Engine.video). O grafo mora no localStorage
// (ferramenta local, 1 usuário) — a MÍDIA gerada mora no S3 (persistente); export/import JSON
// pra backup no M5SSD. Persistir o grafo no console/Postgres fica pra uma fase futura.
import type { Node, Edge } from "@xyflow/react";

export type SceneStatus = "idle" | "img" | "tts" | "clip" | "done" | "error";

export type SceneData = {
  titulo: string;
  prompt: string;      // descrição visual → imagem (keyframe) e → vídeo
  // movePrompt — SÓ o movimento (o que a câmera e o sujeito fazem no clipe). Vem separado do
  // plano porque a imagem é estática e o vídeo é o movimento: misturar os dois num prompt só
  // faz a imagem tentar desenhar movimento e o clipe perder a composição.
  movePrompt?: string;
  narracao: string;    // texto falado → TTS (ElevenLabs)
  estilo: string;      // realista | 3d | anime | colagem | … (slug de IMAGE_STYLES)
  aspect: string;      // 9:16 | 1:1 | 16:9
  duration: string;    // "5" | "10" (segundos do clipe)
  // 🎞️ COMO a cena se move. "ia" = clipe i2v gerado por modelo (custo em crédito, default
  // histórico); "camera" = CÂMERA PROGRAMADA — zoompan do ffmpeg sobre o quadro parado, custo
  // ZERO de crédito e zero drift de identidade (nenhum modelo redesenha nada).
  //
  // Existe porque no web-doc/colagem editorial a maior parte dos planos é imagem parada com
  // push-in: pagar i2v ali é queimar crédito pra fingir um movimento que o ffmpeg faz melhor.
  // Ausente = "ia" (documento antigo continua se comportando exatamente como antes).
  motion?: "ia" | "camera";
  // Movimento da câmera programada — SÓ as keys que o /camclip reproduz (CAM_MOVE_KEYS em
  // lib/shots.ts, espelho de CAM_MOVES no ffmpeg-service). Ignorado quando motion !== "camera".
  camMove?: string;
  // Âncoras de IDENTIDADE da cena: base do personagem e imagem do cenário. Vêm da Escaleta e
  // seguem para a geração como referências, pra o personagem e o lugar não derivarem de uma
  // cena pra outra — é o que separa "um filme" de "clipes soltos parecidos".
  refUrls?: string[];
  refNomes?: string[];  // só rótulo na tela (quem é quem), na mesma ordem de refUrls
  // QUEM está na cena (ids da biblioteca). A imagem-base sozinha trava a identidade pelo lado
  // visual; o `lock` do personagem (destilado do model sheet) trava pelo lado textual — e o
  // servidor só consegue buscá-lo se souber de quem é a cena. Guardamos o ID, não o texto: o
  // lock é resolvido a cada geração, então refinar a ficha vale para os roteiros já salvos.
  charIds?: number[];
  // Cenário (biblioteca de LUGARES, F5) anexado a esta cena. Igual a `charIds`: guardamos o ID,
  // a imagem do cenário entra em `refUrls`/`refNomes` como âncora de AMBIENTE. Antes o Roteiro
  // não tinha esse conceito — só o personagem podia ser anexado — então um cenário gerado aqui
  // nunca virava registro reaproveitável na biblioteca, e não dava pra puxar de volta um lugar
  // já usado numa cena anterior.
  scenarioId?: number;
  imageUrl?: string;   // keyframe gerado (S3) — também vira base i2v do clipe
  clipUrl?: string;    // clipe gerado (S3)
  audioUrl?: string;   // narração gerada (S3)
  status?: SceneStatus;
  error?: string;
  [key: string]: unknown; // React Flow exige index signature no data
};

export type SceneNode = Node<SceneData, "scene">;

export type RoteiroDoc = {
  version: 1;
  name: string;
  musicPrompt?: string; // direção musical do plano — vira a trilha na montagem
  // Motor e fluidez valem para o FILME INTEIRO, não por cena: num filme a consistência entre
  // planos é o que importa, e trocar de modelo no meio muda a cara do vídeo.
  videoModel?: string;
  // Motor das IMAGENS (quadro da cena). Vazio = motor local (mmx). Também é do documento: trocar
  // de motor no meio de um roteiro muda a cara dos quadros, igual ao motor de vídeo.
  imageModel?: string;
  smooth?: boolean;
  // 🎨 ESTILO DO FILME — a técnica visual do conjunto. O estilo é gravado em CADA cena
  // (`SceneData.estilo`, é ele que vai pra geração), mas a ESCOLHA é do filme: com 12 cenas,
  // travar o look mexendo em 12 seletores é garantia de esquecer um — e basta um pra quebrar a
  // consistência. Aqui guardamos a escolha do filme só pra a barra lembrar o que foi aplicado;
  // aplicar continua sendo uma operação em MASSA sobre os nós (o usuário pode ajustar um nó
  // depois, e a barra não desfaz isso sozinha).
  estiloFilme?: string;
  // 🖼️ IMAGEM-CHAVE DE ESTILO: uma imagem de referência do LOOK, enviada em toda geração de
  // quadro com o papel `estilo` (imageRoles). O papel é obrigatório: sem ele a referência é lida
  // como IDENTIDADE e o modelo copia o ASSUNTO da imagem em vez do acabamento — exatamente o bug
  // que este campo existe pra corrigir. Vazio = payload idêntico ao de antes.
  styleRefUrl?: string;
  // Voz da narração — também do FILME inteiro: trocar de locutor entre as cenas soa como dois
  // vídeos colados. Vazio = voz padrão do workspace (e, sem ela, a padrão do motor).
  voiceId?: string;
  // draftId — o roteiro no servidor. É ele que deixa o lote sobreviver a fechar a aba: ao voltar,
  // o canvas pergunta ao servidor o que já ficou pronto.
  draftId?: number;
  nodes: SceneNode[];
  edges: Edge[];
  updatedAt: number;
};

/** ⚠️ LEGADO. Lista curta e própria do canvas, que divergia do catálogo real do engine — um
 *  roteiro não conseguia nascer em `colagem`, `editorial` ou `comic` porque simplesmente não
 *  estavam aqui. O seletor de estilo (do nó E do filme) usa agora `IMAGE_STYLES`
 *  (lib/imageStyles.ts), catálogo único espelhado do engine. Mantida só porque documento antigo
 *  pode ter sido salvo com "cinematico", que saiu do catálogo. */
export const ESTILOS = ["realista", "3d", "anime", "pintura", "cinematico", "produto"] as const;
export const ASPECTS = ["9:16", "1:1", "16:9"] as const;
export const DURATIONS = ["5", "10"] as const;

/** Régua da locução PT-BR, MEDIDA contra os cortes reais do piloto "O Sinal na Colina"
 *  (2026-07-29): 2,4 palavras/s e 12,2 caracteres/s. Antes eram 2 palavras/s chutados, que
 *  superestimavam a fala em ~20% e faziam o aviso disparar em cena que cabia.
 *
 *  ⚠️ Espelho de `App\Support\Locucao` (console) — mudar um, mudar o outro: o servidor agora
 *  devolve o mesmo aviso no /roteiro/render, e duas réguas diferentes dariam respostas
 *  diferentes pra mesma cena. */
export const FALA_PPS = 2.4;
export const FALA_CPS = 12.2;

/** Estimativa de quanto tempo esta narração leva falada, em segundos.
 *
 *  Estima pelos dois caminhos e fica com o MAIOR: palavra longa ("frequência", "transmissor")
 *  engana a conta por palavra, palavra curta engana a conta por caractere. */
export function duracaoFala(texto: string): number {
  const t = (texto || "").trim();
  if (!t) return 0;
  const palavras = t.split(/\s+/).filter(Boolean).length;
  return Math.max(t.length / FALA_CPS, palavras / FALA_PPS);
}

/** Aviso quando a fala não cabe no clipe escolhido — "" quando cabe.
 *
 *  Na montagem cada locução é ancorada NA SUA cena: se a fala é mais longa que o clipe, o trecho
 *  é esticado (slow sutil e, no que sobrar, último frame segurado — o congelamento aparece). Dizer
 *  isso aqui, enquanto o texto é escrito, é mais barato que descobrir no filme montado: trocar a
 *  duração antes de gerar não custa nada, refazer o clipe custa crédito. */
export function avisoFala(narracao: string, duration: string): string {
  const fala = duracaoFala(narracao);
  const clipe = Number(duration) || 0;
  if (!fala || !clipe || fala <= clipe + 0.5) return "";
  const maior = DURATIONS.map(Number).filter((d) => d > clipe).find((d) => fala <= d + 0.5);
  const alvo = Math.round(clipe * FALA_PPS);
  return maior
    ? `Fala de ~${Math.round(fala)}s num clipe de ${clipe}s — troque a duração para ${maior}s (ou a cena esticará).`
    : `Fala de ~${Math.round(fala)}s num clipe de ${clipe}s: a cena vai esticar e o fim congela. Use ~${alvo} palavras ou divida em duas cenas.`;
}

const KEY = "foxassets:roteiro:current";

export function novaCena(partial?: Partial<SceneData>): SceneData {
  return {
    titulo: "Nova cena",
    prompt: "",
    narracao: "",
    estilo: "realista",
    aspect: "9:16",
    duration: "5",
    status: "idle",
    ...partial,
  };
}

// ── plano do filme (uma ideia) → canvas ─────────────────────────────────────
// O plano vem do engine com o filme já quebrado em cenas: o QUADRO de cada uma (estático, vira
// imagem) e o MOVIMENTO dali até a próxima (vira o clipe), mais a locução. Aqui isso vira o
// grafo pronto pra produzir — é o "descreva a ideia e receba o filme planejado".
export type BeatPlano = { title?: string; frame_prompt?: string; move_prompt?: string; voiceover?: string };

export function doPlano(titulo: string, beats: BeatPlano[], musicPrompt?: string, aspect = "9:16", duration = "5"): RoteiroDoc {
  const nodes: SceneNode[] = beats.map((b, i) => ({
    id: `plano-${i + 1}`,
    type: "scene" as const,
    position: { x: 60 + i * 420, y: 80 },
    data: novaCena({
      titulo: b.title?.trim() || `Cena ${i + 1}`,
      prompt: (b.frame_prompt ?? "").trim(),
      movePrompt: (b.move_prompt ?? "").trim(),
      narracao: (b.voiceover ?? "").trim(),
      aspect,
      duration,
    }),
  }));
  const edges: Edge[] = nodes.slice(1).map((n, i) => ({
    id: `e-${nodes[i].id}-${n.id}`, source: nodes[i].id, target: n.id,
  }));
  return { version: 1, name: titulo || "Filme sem título", musicPrompt, nodes, edges, updatedAt: Date.now() };
}

// ── ponte Escaleta → canvas ──────────────────────────────────────────────────
// A Escaleta é onde o filme vira ESTRUTURA (cenas ordenadas, com personagem, cenário e
// dramaturgia); o canvas é onde vira PRODUÇÃO. Sem esta ponte as duas eram ilhas e cada cena
// tinha que ser redigitada — que é exatamente onde a continuidade se perde.
//
// Cada cena da escaleta vira UM nó, ligado ao anterior (o filme já tem ordem; ramificar depois
// é puxar uma segunda aresta). O prompt visual nasce do cabeçalho (INT/EXT · LOCAL · TEMPO) e
// do resumo; a dramaturgia (objetivo/conflito) entra como intenção da cena, não como descrição
// literal — o modelo precisa saber o que a cena QUER, não recitar o roteiro.
export type PlanoEscaleta = {
  id: number; ordem: number; funcao?: string | null;
  enquadramento?: string | null; angulo?: string | null; altura?: string | null; movimento?: string | null;
  acao?: string | null; duracao?: number | null; ancora_url?: string | null;
};
export type CenaEscaleta = {
  id: number; ordem: number; ato?: number | null; scenario_id?: number | null; character_ids?: number[] | null;
  local?: string | null; int_ext?: string | null; tempo?: string | null;
  objetivo_cena?: string | null; conflito_cena?: string | null; resumo?: string | null;
  narracao?: string | null;
  element_ids?: number[] | null;
  shots?: PlanoEscaleta[];
};

// Vocabulário de decupagem → frase de câmera em inglês. É o MESMO conteúdo do `App\Support\Plano`
// no servidor; fica repetido aqui porque a ponte Escaleta→Montagem roda inteira no navegador e
// buscar o vocabulário só pra montar prompt travaria a passagem. ⚠️ Mudou lá, muda aqui.
const CAMERA: Record<string, Record<string, string>> = {
  funcao: {
    master: "master shot covering the whole scene in one framing",
    cutaway: "cutaway to something outside the main action",
    cut_in: "cut-in to a detail of the subject within the action",
    insert: "insert shot of an object or written text",
    reacao: "reaction shot of the character listening or watching",
  },
  enquadramento: {
    geral: "wide establishing shot, full location visible",
    conjunto: "full shot, whole body of the subject in frame",
    americano: "medium-long shot framed from mid-thigh up",
    medio: "medium shot framed from the waist up",
    close: "close-up on the face, shoulders barely in frame",
    detalhe: "extreme close-up on a single detail",
  },
  angulo: {
    frontal: "straight-on frontal angle",
    tres_quartos: "three-quarter angle",
    lateral: "profile side angle",
    costas: "from behind the subject",
    plongee: "high angle looking down at the subject",
    contra_plongee: "low angle looking up at the subject",
    over_shoulder: "over-the-shoulder framing",
    pov: "point-of-view shot from the subject's eyes",
  },
  altura: {
    chao: "camera at ground level",
    joelho: "camera at knee height (~0.5 m)",
    peito: "camera at chest height (~1.3 m)",
    olhos: "camera at eye level (~1.6 m)",
    alto: "camera above head height (~2.2 m)",
  },
  movimento: {
    fixo: "locked-off static camera",
    dolly_in: "slow dolly in toward the subject",
    dolly_out: "slow dolly out away from the subject",
    travelling: "lateral tracking movement following the subject",
    panoramica: "slow pan across the scene",
    tilt: "vertical tilt movement",
    mao: "handheld camera with subtle natural shake",
    grua: "crane movement rising above the scene",
  },
};

/** Frase de câmera do plano. Campo vazio ou desconhecido some — meio plano descrito é melhor que
 *  um padrão inventado. O MOVIMENTO sai separado: ele é do vídeo, não do quadro parado. */
function camera(p: PlanoEscaleta): { quadro: string; movimento: string } {
  const q = (["funcao", "enquadramento", "angulo", "altura"] as const)
    .map((c) => CAMERA[c][(p[c] as string) ?? ""] ?? "")
    .filter(Boolean).join(", ");

  return { quadro: q, movimento: CAMERA.movimento[p.movimento ?? ""] ?? "" };
}
export type RefVisual = { id: number; name: string; url?: string | null };

export function daEscaleta(
  nome: string,
  cenas: CenaEscaleta[],
  cenarios: RefVisual[],
  personagens: RefVisual[],
  elementos: RefVisual[] = [],
): RoteiroDoc {
  const porId = (lista: RefVisual[], id?: number | null) => (id == null ? undefined : lista.find((x) => x.id === id));
  const ordenadas = [...cenas].sort((a, b) => a.ordem - b.ordem);

  // Uma cena pode virar VÁRIOS nós: um por plano da decupagem. Cena sem plano vira um nó só, que
  // é como o produto funcionava antes — ninguém é obrigado a decupar.
  const nodes: SceneNode[] = ordenadas.flatMap((c, i) => {
    const cenario = porId(cenarios, c.scenario_id);
    const elenco = (c.character_ids ?? []).map((id) => porId(personagens, id)).filter(Boolean) as RefVisual[];
    // ELEMENTOS do catálogo (carro, cadeira, mala): entram como âncora igual a personagem e
    // cenário — é o que faz o objeto da cena 7 ser o mesmo da cena 2.
    const objetos = (c.element_ids ?? []).map((id) => porId(elementos, id)).filter(Boolean) as RefVisual[];
    const cabecalho = [c.int_ext, c.local, c.tempo].filter(Boolean).join(" · ");
    const quem = elenco.map((p) => p.name).join(", ");

    // Contexto da CENA — vale para todos os planos dela: quem aparece, onde, fazendo o quê. O
    // conflito entra como tensão a mostrar.
    const contexto = [
      quem && `${quem}`,
      cenario?.name && `em ${cenario.name}`,
      objetos.length > 0 && `com ${objetos.map((o) => o.name).join(", ")}`,
      cabecalho && `(${cabecalho})`,
    ].filter(Boolean).join(" ");

    // Âncoras: personagens primeiro, cenário por último (identidade pesa mais que ambiente).
    // Ordem das âncoras: personagem → objeto → cenário. Identidade de gente pesa mais que a de
    // objeto, e as duas pesam mais que o ambiente — motores com teto de referências cortam do fim.
    const refs = [...elenco, ...objetos, ...(cenario ? [cenario] : [])].filter((r) => r.url);
    const comum = {
      refUrls: refs.map((r) => r.url as string),
      refNomes: refs.map((r) => r.name),
      // Só PERSONAGENS: o lock é ficha de personagem; cenário entra pela imagem.
      charIds: elenco.map((p) => p.id),
    };
    const lugar = c.local || cenario?.name || "Cena";
    const planos = [...(c.shots ?? [])].sort((a, b) => a.ordem - b.ordem);

    if (planos.length === 0) {
      const prompt = [contexto, c.resumo, c.objetivo_cena && `Intenção: ${c.objetivo_cena}`,
        c.conflito_cena && `Tensão visível: ${c.conflito_cena}`].filter(Boolean).join(". ");

      return [{
        id: `esc-${c.id}`,
        type: "scene" as const,
        position: { x: 0, y: 80 },
        data: novaCena({
          titulo: `${i + 1}. ${lugar}`,
          prompt,
          // A locução escrita no Roteiro atravessa a ponte: sem isto a cena chegava muda aqui e o
          // filme montado saía sem narração, mesmo com o texto pronto do outro lado.
          narracao: (c.narracao ?? "").trim(),
          ...comum,
        }),
      }];
    }

    return planos.map((p, k) => {
      const { quadro, movimento } = camera(p);

      return {
        id: `esc-${c.id}-p${p.id}`,
        type: "scene" as const,
        position: { x: 0, y: 80 },
        data: novaCena({
          // "3b" — a numeração diz de que CENA o plano é e em que ordem entra nela.
          titulo: `${i + 1}${String.fromCharCode(97 + k)}. ${lugar}`,
          // A ação do plano manda; o resumo da cena entra só quando o plano não disse nada.
          prompt: [contexto, (p.acao || c.resumo), quadro].filter(Boolean).join(". "),
          // Movimento é do VÍDEO, não do quadro parado: mistura-los faz a imagem tentar desenhar
          // movimento e o clipe perder a composição (mesma razão do movePrompt já existir).
          movePrompt: movimento,

          // A narração fica no PRIMEIRO plano da cena — repeti-la em cada plano faria a locução
          // do filme dizer a mesma frase três vezes seguidas.
          narracao: k === 0 ? (c.narracao ?? "").trim() : "",
          duration: String(p.duracao || 5),
          ...comum,
          // A ÂNCORA DO PLANO (render da malha no ângulo pedido) entra NA FRENTE das outras: ela
          // mostra pose e ângulo exatos, enquanto a base do personagem mostra só identidade.
          // Depois do spread de propósito — antes dele, `comum` sobrescreveria as duas listas.
          refUrls: [...(p.ancora_url ? [p.ancora_url] : []), ...comum.refUrls],
          refNomes: [...(p.ancora_url ? ["ângulo do plano"] : []), ...comum.refNomes],
        }),
      };
    });
  }).map((n, idx) => ({ ...n, position: { x: 60 + idx * 420, y: 80 } }));

  const edges: Edge[] = nodes.slice(1).map((n, i) => ({
    id: `e-${nodes[i].id}-${n.id}`,
    source: nodes[i].id,
    target: n.id,
  }));

  return { version: 1, name: nome || "Roteiro sem título", nodes, edges, updatedAt: Date.now() };
}

export function docVazio(): RoteiroDoc {
  return { version: 1, name: "Roteiro sem título", nodes: [], edges: [], updatedAt: 0 };
}

// ── persistência local ───────────────────────────────────────────────────────
export function carregar(): RoteiroDoc {
  if (typeof window === "undefined") return docVazio();
  try {
    const raw = window.localStorage.getItem(KEY);
    if (!raw) return docVazio();
    const d = JSON.parse(raw) as RoteiroDoc;
    if (!d || d.version !== 1 || !Array.isArray(d.nodes)) return docVazio();
    // Sanitiza status TRANSITÓRIO salvo no meio de uma geração (img/tts/clip) — senão o nó
    // reabre com spinner fantasma. Vira "done" se já tem mídia, senão "idle".
    d.nodes = d.nodes.map((n) => {
      const s = n.data?.status;
      if (s === "img" || s === "tts" || s === "clip") {
        const temMidia = n.data.imageUrl || n.data.clipUrl || n.data.audioUrl;
        return { ...n, data: { ...n.data, status: temMidia ? "done" : "idle", error: undefined } };
      }
      return n;
    });
    return d;
  } catch {
    return docVazio();
  }
}

/** Grava JÁ (sem debounce). A ponte da Escaleta navega logo depois de salvar: com o debounce
 *  de 400ms o canvas abriria antes da escrita e o import se perderia. */
export function salvarAgora(doc: RoteiroDoc): void {
  if (typeof window === "undefined") return;
  try {
    window.localStorage.setItem(KEY, JSON.stringify({ ...doc, updatedAt: doc.updatedAt || Date.now() }));
  } catch {
    /* localStorage cheio/indisponível */
  }
}

let saveTimer: ReturnType<typeof setTimeout> | null = null;
export function salvarDebounced(doc: RoteiroDoc): void {
  if (typeof window === "undefined") return;
  if (saveTimer) clearTimeout(saveTimer);
  saveTimer = setTimeout(() => {
    try {
      window.localStorage.setItem(KEY, JSON.stringify({ ...doc, updatedAt: doc.updatedAt || 1 }));
    } catch {
      /* localStorage cheio/indisponível — ignora (grafo é recuperável do que está na tela) */
    }
  }, 400);
}

// ── caminho: da(s) raiz(es) seguindo as arestas ───────────────────────────────
// Raiz = nó sem aresta de ENTRADA. Numa ramificação, segue a 1ª saída (linear-por-padrão);
// evita ciclo com um Set de visitados. Retorna os nós na ORDEM do caminho principal.
export function caminhoPrincipal(nodes: SceneNode[], edges: Edge[]): SceneNode[] {
  if (!nodes.length) return [];
  const byId = new Map(nodes.map((n) => [n.id, n]));
  const temEntrada = new Set(edges.map((e) => e.target));
  const raiz = nodes.find((n) => !temEntrada.has(n.id)) ?? nodes[0];
  const saidaDe = (id: string) => edges.find((e) => e.source === id)?.target;
  const ordem: SceneNode[] = [];
  const visto = new Set<string>();
  let cur: string | undefined = raiz.id;
  while (cur && !visto.has(cur)) {
    visto.add(cur);
    const n = byId.get(cur);
    if (n) ordem.push(n);
    cur = saidaDe(cur);
  }
  return ordem;
}

// Roteiro em TEXTO (pra export/leitura) a partir do caminho principal.
export function roteiroTexto(doc: RoteiroDoc): string {
  const linhas: string[] = [`# ${doc.name}`, ""];
  caminhoPrincipal(doc.nodes, doc.edges).forEach((n, i) => {
    const d = n.data;
    linhas.push(`## Cena ${i + 1} — ${d.titulo || "(sem título)"}`);
    linhas.push(`Estilo: ${d.estilo} · ${d.aspect} · ${d.duration}s`);
    if (d.prompt) linhas.push(`Visual: ${d.prompt}`);
    if (d.narracao) linhas.push(`Narração: ${d.narracao}`);
    if (d.imageUrl) linhas.push(`Imagem: ${d.imageUrl}`);
    if (d.clipUrl) linhas.push(`Clipe: ${d.clipUrl}`);
    if (d.audioUrl) linhas.push(`Áudio: ${d.audioUrl}`);
    linhas.push("");
  });
  return linhas.join("\n");
}
