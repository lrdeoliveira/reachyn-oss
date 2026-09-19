"use client";

// FoxAssets — CANVAS DE ROTEIRO (fluxo de nós, estilo Twine/Arcweave). Híbrido: cada cena nova
// liga do anterior (linear por padrão); puxe uma 2ª aresta pra ramificar. Cada nó gera mídia
// inline — 🖼️ imagem (mmx-bridge), 🎙️ narração (/studio/tts), 🎬 clipe (Engine.video, i2v a partir
// da imagem se houver). Grafo autosalvo no localStorage; export/import JSON pra backup. A mídia
// vive no S3. "Renderizar caminho" gera um clipe por cena do caminho principal (peças separadas
// pra montar no editor local — a montagem automática sai quebrada, decisão do Luciano).
import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import {
  ReactFlow, ReactFlowProvider, Background, Controls, MiniMap, addEdge,
  useNodesState, useEdgesState, type Connection, type Edge,
} from "@xyflow/react";
import "@xyflow/react/dist/style.css";
import { Plus, Film, Download, Upload, Trash2, Clapperboard, Sparkles, PlayCircle } from "lucide-react";
import SceneNode, { RoteiroCtx, type RoteiroActions, type ElencoItem, type CenarioItem } from "@/components/roteiro/SceneNode";
import { Console, Engine, sfetch, type GenModelInfo, type Voice } from "@/lib/api";
import { useToast } from "@/components/ui/Toast";
import { StoryboardPlayer } from "@/components/StoryboardPlayer";
import { EscolherImagem } from "@/components/EscolherImagem";
import { IMAGE_STYLES } from "@/lib/imageStyles";
import {
  carregar, salvarDebounced, salvarAgora, doPlano, novaCena, caminhoPrincipal, roteiroTexto,
  type SceneNode as TSceneNode, type SceneData, type RoteiroDoc,
} from "@/lib/roteiro";

const nodeTypes = { scene: SceneNode };

/** Reespaça as cenas quando o documento foi salvo com o passo ANTIGO (o cartão cresceu de 300
 *  para 380 e os salvos ficaram sobrepostos). Só mexe quando o layout é claramente GERADO — uma
 *  linha só, todos no mesmo y, em ordem: um canvas arrumado à mão fica como está. */
function reespaca(nodes: TSceneNode[]): TSceneNode[] {
  if (nodes.length < 2) return nodes;
  const y = nodes[0].position.y;
  const emLinha = nodes.every((n) => n.position.y === y);
  const passo = nodes[1].position.x - nodes[0].position.x;
  const crescente = nodes.every((n, i) => i === 0 || n.position.x > nodes[i - 1].position.x);
  if (!emLinha || !crescente || passo >= 400) return nodes;
  return nodes.map((n, i) => ({ ...n, position: { x: 60 + i * 420, y } }));
}

function Canvas() {
  const toast = useToast();
  // Começa VAZIO (igual no server) e carrega o localStorage só depois de montar — ler storage no
  // SSR daria hydration mismatch (server vê vazio, client vê o grafo salvo).
  const [nodes, setNodes, onNodesChange] = useNodesState<TSceneNode>([]);
  const [edges, setEdges, onEdgesChange] = useEdgesState<Edge>([]);
  const [name, setName] = useState("Roteiro sem título");
  const importRef = useRef<HTMLInputElement>(null);
  // Direção musical do plano: pertence ao DOCUMENTO (como o nome), não ao modal — por isso
  // fica aqui em cima, salva no autosave e recuperada ao abrir.
  const [musicPrompt, setMusicPrompt] = useState<string | undefined>(undefined);
  // Motor de vídeo e fluidez do FILME (não por cena): consistência entre planos é o que faz
  // parecer um filme só. Persistidos no documento junto com o resto.
  const [videoModel, setVideoModel] = useState("");
  // Motor de IMAGEM dos quadros. Antes não havia escolha: sem âncora a cena ia SEMPRE pro motor
  // local (mmx), que é o único caminho da rota /api/image — e quando ele falha (transitório do
  // provedor) não havia plano B nem como trocar. Vazio = local; qualquer slug = engine.
  const [imageModel, setImageModel] = useState("");
  const [modelosImg, setModelosImg] = useState<GenModelInfo[]>([]);
  // Personagens da biblioteca (id + base) — o elenco disponível pra marcar em cada cena.
  const [elenco, setElenco] = useState<ElencoItem[]>([]);
  // Cenários da biblioteca (F5) — os lugares disponíveis pra anexar em cada cena. Espelha o
  // elenco: sem isto o Roteiro não tinha como reaproveitar um lugar já usado numa cena anterior.
  const [cenarios, setCenarios] = useState<CenarioItem[]>([]);
  // Mídia aberta em tela cheia (quadro ou clipe da cena). null = fechado.
  const [lightbox, setLightbox] = useState<{ url: string; kind: "image" | "video" } | null>(null);
  // Voz da narração: mesma lógica do motor — pertence ao FILME (trocar de locutor entre cenas
  // soa como dois vídeos colados). Vazio = voz padrão do workspace.
  const [voiceId, setVoiceId] = useState("");
  const [vozes, setVozes] = useState<Voice[]>([]);
  // draftId: o roteiro no servidor. Com ele o lote roda no worker e a aba pode fechar.
  const [draftId, setDraftId] = useState<number | undefined>(undefined);
  const [smooth, setSmooth] = useState(false);
  // 🎨 ESTILO DO FILME: a técnica visual do conjunto. O estilo continua sendo gravado em cada
  // cena (é o que vai pro motor), mas a ESCOLHA é do filme — com 12 cenas, travar o look num
  // seletor por nó é garantia de esquecer um, e um só já quebra a consistência. Escolher aqui
  // aplica em MASSA; ajustar um nó depois continua valendo (a barra não desfaz).
  const [estiloFilme, setEstiloFilme] = useState("");
  // 🖼️ IMAGEM-CHAVE DE ESTILO: vai em TODA geração de quadro com o papel `estilo`. É o jeito de
  // travar acabamento (paleta, textura, luz) que nenhum slug de catálogo descreve inteiro.
  const [styleRefUrl, setStyleRefUrl] = useState("");
  const [escolhendoEstilo, setEscolhendoEstilo] = useState(false);
  const [modelos, setModelos] = useState<GenModelInfo[]>([]);
  const loaded = useRef(false);

  // FILA de geração de imagem (2 por vez). A rota /generate/image é SÍNCRONA e segura um worker
  // do php-fpm por 20-40s; o pool do dev tem 12. Clicar "Imagem" em cinco cenas seguidas prendia
  // o pool inteiro e o app inteiro parecia travado — até /api/characters ia pra fila e o cartão
  // mostrava "#14" no lugar do nome do personagem (2026-07-25). Enfileirar aqui é o que mantém a
  // tela viva enquanto as imagens saem.
  const fila = useRef<Promise<unknown>>(Promise.resolve());
  const vagas = useRef(0);
  const naFila = useCallback(async <T,>(fn: () => Promise<T>): Promise<T> => {
    while (vagas.current >= 2) await new Promise((r) => setTimeout(r, 400));
    vagas.current++;
    try {
      return await fn();
    } finally {
      vagas.current--;
    }
  }, []);

  // refs pra ler o estado atual dentro de callbacks async (geração)
  const nodesRef = useRef(nodes);
  useEffect(() => { nodesRef.current = nodes; }, [nodes]);

  // Catálogo de vídeo: mesma regra da aba Vídeo (sem premium, do mais barato ao mais caro).
  useEffect(() => {
    Console.videoModels()
      .then((r) => {
        const lista = (r.data ?? []).filter((m) => !m.premium).sort((a, b) => (a.cost_credits ?? 0) - (b.cost_credits ?? 0));
        setModelos(lista);
        setVideoModel((atual) => atual || lista[0]?.slug || "");
      })
      .catch(() => {});
  }, []);

  // Biblioteca de personagens: é o que deixa marcar QUEM está na cena direto no cartão. Sem
  // isso, só cena vinda da Escaleta tinha identidade — as criadas aqui geravam um personagem
  // qualquer, mesmo com a Mel pronta na biblioteca.
  useEffect(() => {
    sfetch("/api/characters")
      .then((r) => r.json())
      .then((j) => {
        const lista = (Array.isArray(j) ? j : j?.data ?? []) as ElencoItem[];
        setElenco(lista.map((c) => ({ id: c.id, name: c.name, base_url: c.base_url, sheet_url: c.sheet_url })));
      })
      .catch(() => {});
  }, []);

  // Biblioteca de cenários (F5): mesmo motivo do elenco, do lado dos LUGARES — sem ela, cada
  // cena do Roteiro só sabia gerar um fundo do zero, nunca reaproveitar um lugar já salvo.
  useEffect(() => {
    sfetch("/api/scenarios")
      .then((r) => r.json())
      .then((j) => {
        const lista = (Array.isArray(j) ? j : j?.data ?? []) as CenarioItem[];
        setCenarios(lista.map((c) => ({ id: c.id, name: c.name, image_url: c.image_url })));
      })
      .catch(() => {});
  }, []);

  // Catálogo de imagem (mesma regra do de vídeo: do mais barato ao mais caro).
  useEffect(() => {
    Console.imageModels()
      // utilitários (mapa PBR/refino/conserto) ficam fora: rodam por botão próprio, não geram cena
      .then((r) => setModelosImg((r.data ?? []).filter((m) => !m.utility).sort((a, b) => (a.cost_credits ?? 0) - (b.cost_credits ?? 0))))
      .catch(() => {});
  }, []);

  // Vozes da narração. Sem chave válida vem lista vazia — o seletor some e a narração segue
  // saindo na voz padrão, em vez de travar o fluxo por causa de um enfeite.
  useEffect(() => {
    Console.voices().then((r) => setVozes(r.voices ?? [])).catch(() => {});
  }, []);

  // hidrata do localStorage uma vez, pós-mount
  useEffect(() => {
    const d = carregar();
    setNodes(reespaca(d.nodes)); setEdges(d.edges); setName(d.name); setMusicPrompt(d.musicPrompt);
    setVideoModel(d.videoModel ?? ""); setImageModel(d.imageModel ?? ""); setSmooth(!!d.smooth); setDraftId(d.draftId);
    setVoiceId(d.voiceId ?? "");
    // Campos ADITIVOS (estilo do filme + imagem-chave): documento antigo simplesmente não os tem
    // e abre igual, com string vazia = "nada escolhido". Por isso a `version` do doc segue 1.
    setEstiloFilme(d.estiloFilme ?? ""); setStyleRefUrl(d.styleRefUrl ?? "");
    loaded.current = true;
  }, [setNodes, setEdges]);

  // autosave (debounced) — só depois de hidratar, pra não sobrescrever o salvo com o estado vazio
  useEffect(() => {
    if (!loaded.current) return;
    // musicPrompt entra aqui também: sem ele o autosave (que roda logo depois do plano)
    // sobrescrevia a direção musical recém-salva com undefined, e a trilha sumia da montagem.
    salvarDebounced({
      version: 1, name, musicPrompt, videoModel, imageModel, smooth, voiceId, draftId,
      // Sem estes dois no autosave, a escolha de look sumiria no reload — e o filme voltaria a
      // ser gerado sem a imagem-chave, sem ninguém perceber.
      estiloFilme: estiloFilme || undefined, styleRefUrl: styleRefUrl || undefined,
      nodes, edges, updatedAt: Date.now(),
    });
  }, [nodes, edges, name, musicPrompt, videoModel, imageModel, smooth, voiceId, draftId, estiloFilme, styleRefUrl]);

  const patch = useCallback((id: string, p: Partial<SceneData>) => {
    setNodes((nds) => nds.map((n) => (n.id === id ? { ...n, data: { ...n.data, ...p } } : n)));
  }, [setNodes]);

  const getData = (id: string) => nodesRef.current.find((n) => n.id === id)?.data;

  // Aplica o ESTILO DO FILME em TODAS as cenas de uma vez. É uma escrita em massa mesmo (não um
  // "estilo herdado" calculado na hora da geração): o estilo continua morando em cada cena, que é
  // o que preserva o ajuste fino de um plano específico depois — herança silenciosa apagaria esse
  // ajuste toda vez que a barra mudasse.
  const aplicarEstiloFilme = useCallback((slug: string) => {
    setEstiloFilme(slug);
    if (!slug) return;  // "— manter o de cada cena —": só limpa a escolha, não mexe em nada
    setNodes((nds) => nds.map((n) => ({ ...n, data: { ...n.data, estilo: slug } })));
    if (nodesRef.current.length) toast.ok(`Estilo aplicado nas ${nodesRef.current.length} cenas.`);
  }, [setNodes, toast]);

  const onConnect = useCallback((c: Connection) => setEdges((eds) => addEdge(c, eds)), [setEdges]);

  const addCena = useCallback(() => {
    const id = crypto.randomUUID();
    const arr = nodesRef.current;
    const last = arr[arr.length - 1];
    const position = last ? { x: last.position.x + 420, y: last.position.y } : { x: 80, y: 80 };
    setNodes((nds) => nds.concat({ id, type: "scene", position, data: novaCena() }));
    if (last) setEdges((eds) => addEdge({ source: last.id, target: id, id: `e-${last.id}-${id}` }, eds));
  }, [setNodes, setEdges]);

  const remover = useCallback((id: string) => {
    setNodes((nds) => nds.filter((n) => n.id !== id));
    setEdges((eds) => eds.filter((e) => e.source !== id && e.target !== id));
  }, [setNodes, setEdges]);

  // ── geração por nó ───────────────────────────────────────────────────────────
  const genImage = useCallback(async (id: string) => {
    const d = getData(id);
    if (!d?.prompt.trim()) return;
    // O índice da cena no CAMINHO é a posição no `film.beats` do servidor — é assim que o quadro
    // volta pro nó certo no polling (mesmo contrato do clipe).
    const caminho = caminhoPrincipal(nodesRef.current, edges);
    const index = caminho.findIndex((n) => n.id === id);
    if (index < 0) { toast.err("Ligue esta cena ao caminho principal antes de gerar."); return; }

    patch(id, { status: "img", error: undefined });
    try {
      // Corta as âncoras ao que ESTE modelo aceita, priorizando as primeiras (personagem antes do
      // cenário — identidade pesa mais que ambiente). O servidor corta de novo, por garantia.
      const teto = modelosImg.find((m) => m.slug === imageModel)?.refs_max;
      // Referência viaja como PAR url+papel. O papel é o que separa "copie o assunto desta imagem"
      // (identidade) de "copie só o acabamento" (estilo): mandar a imagem-chave sem papel faz o
      // modelo desenhar o ASSUNTO dela em toda cena — o bug que a imagem-chave existe pra corrigir.
      const todas: { url: string; papel: string; nome: string }[] = [
        // A chave de estilo vem PRIMEIRO: é ela que trava o look do filme, e o corte por refs_max
        // sempre come do fim. Num motor de 1 referência isso custa a âncora de identidade da cena
        // — trade-off explícito, e o aviso abaixo diz exatamente o que ficou de fora.
        ...(styleRefUrl ? [{ url: styleRefUrl, papel: "estilo", nome: "estilo do filme" }] : []),
        ...(d.refUrls ?? []).filter(Boolean).map((u, i) => ({
          url: u as string, papel: "identidade", nome: d.refNomes?.[i] ?? "âncora",
        })),
      ];
      const usadas = typeof teto === "number" ? todas.slice(0, teto) : todas;
      const refs = usadas.map((r) => r.url);
      if (usadas.length < todas.length) {
        toast.ok(teto === 0
          ? "Este motor gera SÓ do texto — as referências da cena serão ignoradas (identidade fica só no lock). Escolha um motor com referência pra ancorar o personagem."
          : `Este motor aceita ${teto} referência(s): usando ${usadas.map((r) => r.nome).join(", ")}.`);
      }
      // FILA DO WORKER: o web só enfileira. Antes isto era uma chamada síncrona de 20-40s que
      // segurava um worker do php-fpm — meia dúzia de cenas travava o app inteiro.
      const r = await Console.roteiroImagem({
        draftId, index, prompt: d.prompt, aspect: d.aspect, style: d.estilo,
        model: imageModel || undefined,
        imageUrls: refs.length ? refs : undefined,
        // Papéis SÓ quando há imagem-chave: sem ela o payload sai idêntico ao de antes (o servidor
        // trata "sem papéis" como tudo-identidade, que é o comportamento histórico).
        imageRoles: refs.length && styleRefUrl ? usadas.map((r) => r.papel) : undefined,
        charIds: d.charIds?.length ? d.charIds : undefined,
        name,
      });
      setDraftId(r.draftId);
      toast.ok("Quadro na fila — aparece aqui quando ficar pronto.");
    } catch (e) {
      const msg = e instanceof Error ? e.message : "erro";
      patch(id, { status: "error", error: msg });
      toast.err(`Imagem falhou: ${msg.slice(0, 140)}`);
    }
  }, [patch, toast, imageModel, modelosImg, edges, draftId, name, styleRefUrl]);

  const genNarracao = useCallback(async (id: string) => {
    const d = getData(id);
    if (!d?.narracao.trim()) return;
    patch(id, { status: "tts", error: undefined });
    try {
      // A voz é a do FILME (barra), não da cena: locutor diferente entre planos denuncia a colagem.
      const j = await Console.tts(d.narracao, voiceId || undefined);
      if (!j.ok || !j.url) throw new Error(j.error || "falha na narração");
      patch(id, { audioUrl: j.url, status: "done" });
    } catch (e) {
      patch(id, { status: "error", error: e instanceof Error ? e.message : "erro" });
      toast.err("Narração falhou.");
    }
  }, [patch, toast, voiceId]);

  // 🎞️ CÂMERA PROGRAMADA: anima o QUADRO já aprovado com zoompan no ffmpeg — sem IA de vídeo,
  // sem crédito e sem drift (nenhum modelo redesenha nada). É o que torna o web-doc barato: a
  // maior parte dos planos é imagem parada com push-in, e pagar i2v ali é queimar crédito.
  // Devolve a URL do clipe, ou lança — quem chama decide o que fazer com o erro.
  const camClip = useCallback(async (id: string): Promise<string> => {
    const d = getData(id);
    if (!d?.imageUrl) throw new Error("Gere o quadro da cena antes: a câmera programada anima a imagem.");
    // Mesmo contrato de índice da imagem e do clipe: a posição no CAMINHO é a posição no
    // `film.beats` do servidor. Cena solta não tem índice — e sem índice o clipe voltaria pro
    // beat errado.
    const index = caminhoPrincipal(nodesRef.current, edges).findIndex((n) => n.id === id);
    if (index < 0) throw new Error("Ligue esta cena ao caminho principal antes de gerar.");
    const j = await Console.roteiroCamclip({
      draftId, index, imageUrl: d.imageUrl, move: d.camMove || "push_in",
      duration: d.duration, aspect: d.aspect, name,
    });
    if (!j?.ok || !j.url) throw new Error(j?.error || "a câmera programada não devolveu o clipe");
    if (j.draftId) setDraftId(j.draftId);
    return j.url;
  }, [draftId, edges, name]);

  const genClip = useCallback(async (id: string) => {
    const d = getData(id);
    // Na câmera programada o insumo é o QUADRO, não o prompt — cena sem texto mas com imagem
    // aprovada é caso legítimo aqui.
    if (!d || (d.motion === "camera" ? !d.imageUrl : !d.prompt.trim())) return;
    patch(id, { status: "clip", error: undefined });
    if (d.motion === "camera") {
      try {
        patch(id, { clipUrl: await camClip(id), status: "done" });
        toast.ok("Câmera aplicada — sem gastar crédito.");
      } catch (e) {
        const msg = e instanceof Error ? e.message : "erro";
        patch(id, { status: "error", error: msg });
        toast.err(`Câmera programada falhou: ${msg.slice(0, 140)}. Troque a cena para "Clipe de IA" se precisar do movimento.`);
      }
      return;
    }
    try {
      // Âncoras do clipe: o keyframe da cena primeiro (é dele que o movimento parte) e, atrás,
      // as referências de identidade da Escaleta. Sem keyframe ainda, as referências sozinhas
      // já seguram quem é o personagem e onde ele está.
      const ancoras = [d.imageUrl, ...(d.refUrls ?? [])].filter(Boolean) as string[];
      // O clipe recebe quadro + MOVIMENTO; a imagem ficou só com o quadro (estático).
      const promptClipe = [d.prompt, d.movePrompt].filter(Boolean).join(". ");
      const j = await Engine.video({
        prompt: promptClipe, imageUrls: ancoras.length ? ancoras : undefined, aspect: d.aspect,
        duration: d.duration, style: d.estilo, narration: false,
        model: videoModel || undefined,
        smooth: smooth || undefined,
        // Mesma trava textual do lote: refazer uma cena não pode sair com identidade mais fraca.
        charIds: d.charIds?.length ? d.charIds : undefined,
      });
      if (!j.url) throw new Error("falha no clipe");
      patch(id, { clipUrl: j.url, status: "done" });
    } catch (e) {
      patch(id, { status: "error", error: e instanceof Error ? e.message : "erro" });
      toast.err("Clipe falhou.");
    }
  }, [patch, toast, videoModel, smooth, camClip]);

  // Pôr/tirar personagem da cena: mexe nos DOIS lados da trava ao mesmo tempo — `charIds` (o
  // servidor resolve o lock e injeta no prompt) e `refUrls`/`refNomes` (a imagem-base vira
  // âncora). Separá-los daria cena com lock e sem âncora, ou o contrário, sem ninguém perceber.
  //
  // Só a BASE (retrato) vira âncora i2i — o MODEL SHEET (turnaround com várias poses lado a
  // lado) tem composição/aspecto muito diferente de uma pose única, e mandar as duas âncoras
  // com peso igual pro provider faz ele tentar encaixar a prancha multi-pose na cena, esticando
  // a anatomia (a Mel saiu desproporcional na cena 5, draft 140, 2026-08-01). Espécie/sexo é
  // seguro pelo LOCK TEXTUAL (`charIds` → `elementsLockText` no servidor), não por imagem extra.
  const addChar = useCallback((id: string, charId: number) => {
    const p = elenco.find((e) => e.id === charId);
    if (!p) return;
    const d = getData(id);
    if (d?.charIds?.includes(charId)) return;
    patch(id, {
      charIds: [...(d?.charIds ?? []), charId],
      ...(p.base_url ? {
        refUrls: [...(d?.refUrls ?? []), p.base_url],
        refNomes: [...(d?.refNomes ?? []), p.name],
      } : {}),
    });
  }, [elenco, patch]);

  const delChar = useCallback((id: string, charId: number) => {
    const p = elenco.find((e) => e.id === charId);
    const d = getData(id);
    if (!d) return;
    const i = p?.base_url ? (d.refUrls ?? []).indexOf(p.base_url) : -1;
    patch(id, {
      charIds: (d.charIds ?? []).filter((c) => c !== charId),
      ...(i >= 0 ? {
        refUrls: (d.refUrls ?? []).filter((_, k) => k !== i),
        refNomes: (d.refNomes ?? []).filter((_, k) => k !== i),
      } : {}),
    });
  }, [elenco, patch]);

  // O "elenco em todas" foi removido a pedido do Luciano (2026-07-25): com o Roteiro definindo
  // quem está em cada cena — e a IA preenchendo isso ao escrever a escaleta — o atalho virou
  // ruído na barra. Quem entra pelo canvas ainda tem o "+ personagem" no cartão da cena.

  // Anexar/tirar CENÁRIO da cena: só um por cena (um lugar por vez, diferente do elenco que
  // aceita vários). A imagem entra em `refUrls` DEPOIS das âncoras de personagem — identidade
  // pesa mais que ambiente (mesma prioridade que `genImage` já aplica ao cortar refs pro motor).
  const addScenario = useCallback((id: string, scenarioId: number) => {
    const sc = cenarios.find((c) => c.id === scenarioId);
    if (!sc) return;
    const d = getData(id);
    patch(id, {
      scenarioId,
      ...(sc.image_url ? {
        refUrls: [...(d?.refUrls ?? []), sc.image_url],
        refNomes: [...(d?.refNomes ?? []), sc.name],
      } : {}),
    });
  }, [cenarios, patch]);

  const delScenario = useCallback((id: string) => {
    const d = getData(id);
    if (!d) return;
    const sc = cenarios.find((c) => c.id === d.scenarioId);
    const i = sc?.image_url ? (d.refUrls ?? []).indexOf(sc.image_url) : -1;
    patch(id, {
      scenarioId: undefined,
      ...(i >= 0 ? {
        refUrls: (d.refUrls ?? []).filter((_, k) => k !== i),
        refNomes: (d.refNomes ?? []).filter((_, k) => k !== i),
      } : {}),
    });
  }, [cenarios, patch]);

  // Salva o QUADRO GERADO da cena como um Cenário novo na biblioteca (F5) — mesma rota que a
  // aba Cenários usa pra anexar uma imagem já existente. Sem isto, o fundo gerado morria na cena
  // e nunca virava um lugar reaproveitável.
  const salvarCenario = useCallback(async (id: string) => {
    const d = getData(id);
    if (!d?.imageUrl) return;
    const nome = window.prompt("Nome do cenário:", d.titulo || "Cenário sem nome");
    if (!nome?.trim()) return;
    try {
      const r = await sfetch("/api/scenarios", {
        method: "POST",
        body: JSON.stringify({ name: nome.trim(), description: d.prompt || "", imageUrl: d.imageUrl }),
      });
      const j = await r.json().catch(() => ({}));
      if (!r.ok || !j?.ok) { toast.err(j?.error || "Não deu pra salvar o cenário."); return; }
      setCenarios((cs) => [{ id: j.scenario.id, name: j.scenario.name, image_url: j.scenario.image_url }, ...cs]);
      addScenario(id, j.scenario.id);
      toast.ok("Cenário salvo na biblioteca.");
    } catch { toast.err("Erro ao salvar o cenário."); }
  }, [toast, addScenario]);

  // Duplicar cena: cria um nó novo com o mesmo conteúdo (prompt, narração, âncoras, cenário,
  // estilo/aspecto), sem mídia gerada — variar um plano parecido sem reescrever do zero.
  const duplicar = useCallback((id: string) => {
    const src = nodesRef.current.find((n) => n.id === id);
    if (!src) return;
    const newId = `cena-${Date.now()}`;
    const position = { x: src.position.x + 40, y: src.position.y + 40 };
    const { imageUrl: _img, clipUrl: _clip, audioUrl: _audio, status: _status, error: _error, ...resto } = src.data;
    setNodes((nds) => nds.concat({ id: newId, type: "scene", position, data: { ...resto, status: "idle" } }));
  }, [setNodes]);

  const actions: RoteiroActions = useMemo(
    () => ({
      update: patch, genImage, genNarracao, genClip, remover, duplicar, elenco, addChar, delChar,
      cenarios, addScenario, delScenario, salvarCenario,
      ampliar: (url: string, kind: "image" | "video") => setLightbox({ url, kind }),
    }),
    [patch, genImage, genNarracao, genClip, remover, duplicar, elenco, addChar, delChar, cenarios, addScenario, delScenario, salvarCenario],
  );

  // ── caminho: gera um clipe por cena (sequencial, peças separadas) ──────────────
  const [rendering, setRendering] = useState(false);
  const [montando, setMontando] = useState(false);
  // "Criar filme": a ideia em texto vira o roteiro inteiro (plano do engine) — é por onde se
  // começa um filme do zero, sem digitar cena por cena.
  const [planoAberto, setPlanoAberto] = useState(false);
  const [ideia, setIdeia] = useState("");
  const [nCenas, setNCenas] = useState(6);
  const [durCena, setDurCena] = useState("5");
  const [planejando, setPlanejando] = useState(false);
  const [filme, setFilme] = useState<string | null>(null); // último filme montado (link pra ver/baixar)
  // Narração CONTÍNUA na montagem: uma locução só atravessando os cortes, em vez de um áudio
  // por cena. É o que faz vários clipes curtos soarem como um vídeo só.
  const [narrarFilme, setNarrarFilme] = useState(true);
  const [legendar, setLegendar] = useState(false);
  const renderCaminho = useCallback(async () => {
    const caminho = caminhoPrincipal(nodesRef.current, edges);
    if (!caminho.length) { toast.err("Sem cenas no caminho."); return; }
    // Só o que FALTA. O lote é longo e vulnerável (recarregar a aba o interrompe): refazer o
    // que já está pronto queimaria crédito de novo a cada retomada. Pra regerar uma cena
    // específica, use o botão Clipe dela.
    const pendentes = caminho.filter((n) => !n.data.clipUrl);
    if (!pendentes.length) { toast.ok("Todas as cenas do caminho já têm clipe."); return; }
    setRendering(true);
    try {
      // CÂMERA PROGRAMADA PRIMEIRO, e fora do lote pago: essas cenas saem em segundos, sem crédito
      // e sem fila. Se fossem junto no /roteiro/render, o servidor as trataria como pendentes e
      // geraria i2v — exatamente o gasto que o modo existe pra evitar.
      const feitasPorCamera = new Map<string, string>();
      for (const n of pendentes.filter((x) => x.data.motion === "camera")) {
        try {
          const url = await camClip(n.id);
          feitasPorCamera.set(n.id, url);
          patch(n.id, { clipUrl: url, status: "done" });
        } catch (e) {
          // Não derruba o lote: a cena fica sem clipe e o usuário decide (gerar o quadro que falta
          // ou trocar pra clipe de IA). Mandá-la pro lote pago em silêncio seria cobrar sem pedir.
          patch(n.id, { status: "error", error: e instanceof Error ? e.message : "erro" });
          toast.err(`Câmera programada falhou em "${n.data.titulo}": ${(e instanceof Error ? e.message : "erro").slice(0, 120)}`);
        }
      }
      // Cena de câmera que NÃO saiu chega ao lote sem clipe — e o servidor renderiza pendente como
      // i2v PAGO. Como o lote não sabe distinguir "de graça" de "pago", quem paga decide: confirma
      // ou cancela o lote inteiro. Cobrar por engano é pior que não renderizar.
      const falharam = pendentes.filter((x) => x.data.motion === "camera" && !feitasPorCamera.has(x.id));
      if (falharam.length && !confirm(
        `${falharam.length} cena(s) de câmera programada não saíram. Continuar vai renderizá-las como CLIPE DE IA, com crédito. Continuar?`)) {
        return;
      }
      // A FILA carrega o lote: cada cena vira um job no worker. A aba deixa de ser obrigatória —
      // pode fechar e voltar depois que o polling recupera o que ficou pronto.
      const r = await Console.roteiroRender({
        name, model: videoModel || undefined, smooth: smooth || undefined, draftId,
        cenas: caminho.map((n) => ({
          titulo: n.data.titulo, prompt: [n.data.prompt, n.data.movePrompt].filter(Boolean).join(". "),
          narracao: n.data.narracao, aspect: n.data.aspect, duration: n.data.duration, estilo: n.data.estilo,
          // O clipe recém-feito pela câmera entra aqui: é ele que faz o servidor PULAR a cena em
          // vez de gerar i2v por cima (o estado do React ainda não voltou pra `caminho`).
          clipUrl: feitasPorCamera.get(n.id) ?? n.data.clipUrl,
          imageUrls: [n.data.imageUrl, ...(n.data.refUrls ?? [])].filter(Boolean),
          // Quem está na cena → o servidor busca o `lock` atual de cada um e o injeta no prompt.
          charIds: n.data.charIds ?? [],
        })),
      });
      setDraftId(r.draftId);
      toast.ok(r.fila
        ? `${r.fila} cena(s) na fila. Pode fechar a aba — o resultado aparece quando voltar.`
        : "Nada a renderizar: todas as cenas já têm clipe.");
      // FALA MAIOR QUE O CLIPE: o servidor mede o texto de cada cena e devolve quais vão esticar.
      // O nó já mostra isso enquanto se escreve, mas na hora de disparar o lote é o último momento
      // em que trocar a duração ainda é de graça — depois o clipe está pago e a cena esticada.
      for (const a of r.avisos ?? []) toast.err(`Cena ${a.cena}: ${a.aviso}`);
      // Narração continua local (é rápida, segundos) e não justifica fila.
      for (const n of pendentes) {
        const d = getData(n.id);
        if (d?.narracao?.trim() && !d.audioUrl) await genNarracao(n.id);
      }
    } catch (e) {
      toast.err(e instanceof Error ? e.message : "Não foi possível enfileirar as cenas.");
    } finally {
      setRendering(false);
    }
  }, [edges, name, videoModel, smooth, draftId, genNarracao, getData, toast, camClip, patch]);

  // ENSAIO antes do gasto: toca os quadros do caminho em sequência, no tempo de cada cena, com a
  // narração por cima. Ritmo errado aparecia só depois de renderizar o lote — ou seja, depois de
  // pagar. Aqui custa zero e usa o que já está no roteiro.
  const [playerAberto, setPlayerAberto] = useState(false);

  // Quanto custa renderizar o caminho inteiro, ANTES de disparar. Era o furo que a avaliação
  // de 5 min apontou: entrar às cegas num lote de dezenas de clipes.
  const caminhoAtual = caminhoPrincipal(nodes, edges);
  const totalCaminho = caminhoAtual.length;
  // Pendente = sem clipe. É o número que importa: é o que vai ser gerado e cobrado.
  const pendentesCount = caminhoAtual.filter((n) => !n.data.clipUrl).length;
  // Só as cenas de CLIPE DE IA entram no custo: a câmera programada é ffmpeg, custo zero de
  // crédito. Somar as duas mostraria um preço que não existe — e é justamente o número que decide
  // se o filme vale a pena.
  const pagasCount = caminhoAtual.filter((n) => !n.data.clipUrl && n.data.motion !== "camera").length;
  const camerasCount = caminhoAtual.filter((n) => !n.data.clipUrl && n.data.motion === "camera").length;
  const custoLote = (() => {
    const m = modelos.find((x) => x.slug === videoModel);
    if (!m?.cost_credits || !pagasCount) return null;
    return { cenas: pagasCount, total: pagasCount * m.cost_credits, unit: m.cost_credits };
  })();

  // Gera o plano e JÁ monta o grafo: cada cena com o quadro (imagem), o movimento (clipe) e a
  // locução. Não gera mídia nenhuma aqui — plano é texto, e é barato refazer.
  async function criarFilme() {
    const brief = ideia.trim();
    if (!brief) { toast.err("Descreva a ideia do filme."); return; }
    if (nodesRef.current.length && !confirm(`O canvas já tem ${nodesRef.current.length} cena(s). Substituir pelo novo filme?`)) return;
    setPlanejando(true);
    try {
      const p = await Console.filmplan({ brief, beats: nCenas, clipDuration: durCena });
      const beats = p?.beats ?? [];
      if (!beats.length) throw new Error("o plano não retornou cenas");
      const doc = doPlano(p.title ?? "", beats, p.music_prompt, "9:16", durCena);
      // O estilo do filme sobrevive ao replanejamento: sem isto, planejar de novo devolvia as
      // cenas no default "realista" e o look travado se perdia em silêncio.
      if (estiloFilme) doc.nodes = doc.nodes.map((n) => ({ ...n, data: { ...n.data, estilo: estiloFilme } }));
      salvarAgora(doc);
      setName(doc.name);
      setMusicPrompt(doc.musicPrompt);
      setNodes(doc.nodes);
      setEdges(doc.edges);
      setPlanoAberto(false);
      setIdeia("");
      toast.ok(`"${doc.name}" planejado em ${beats.length} cenas. Agora é gerar.`);
    } catch (e) {
      toast.err(e instanceof Error ? e.message : "Não foi possível planejar o filme.");
    } finally {
      setPlanejando(false);
    }
  }

  // MONTAR: junta os clipes das cenas do caminho num filme só (concat + color-match no
  // console/engine). Fica AO LADO das peças separadas, não no lugar delas — quem quer montar no
  // editor local continua baixando cena a cena.
  const montar = useCallback(async () => {
    const caminho = caminhoPrincipal(nodesRef.current, edges);
    // As cenas que ENTRAM no filme são as que têm clipe — e é a esta lista que a narração precisa
    // estar alinhada, não ao caminho inteiro: uma cena sem clipe é pulada, e se a fala dela fosse
    // junto todas as locuções seguintes cairiam na cena errada.
    const cenas = caminho.filter((n) => !!n.data.clipUrl);
    const clipes = cenas.map((n) => n.data.clipUrl as string);
    if (clipes.length < 2) {
      toast.err("Renderize ao menos duas cenas antes de montar.");
      return;
    }
    if (clipes.length < caminho.length &&
        !confirm(`${caminho.length - clipes.length} cena(s) do caminho ainda estão sem clipe. Montar só com as ${clipes.length} prontas?`)) return;
    setMontando(true);
    try {
      // 🎙️ A fala de CADA cena viaja separada e alinhada ao seu clipe (cena muda vai como "").
      // O servidor ancora cada locução no primeiro frame da sua cena e estica o trecho quando a
      // fala não cabe — é isso que mantém voz e imagem juntas. Antes tudo era emendado num texto
      // só e colado no segundo 0: as 4 falas do primeiro filme real terminavam em 20,3s de 39,9s,
      // deixando as duas últimas cenas mudas (2026-07-26).
      const falas = cenas.map((n) => (n.data.narracao ?? "").trim());
      const script = falas.filter(Boolean).join(" ");
      const j = await Console.assemble({
        // Com draftId a montagem vai pra FILA: 5 min de filme levam ~6min de CPU, acima do
        // corte do nginx. Sem draftId (roteiro nunca enfileirado) segue o caminho síncrono.
        draftId,
        clipUrls: clipes,
        aspect: caminho[0]?.data.aspect ?? "9:16",
        name: name || "filme",
        narration: narrarFilme && !!script,
        scripts: falas,
        // Corrido como reserva: se um dia o modo por cena estiver indisponível, o filme ainda sai
        // narrado (fora de sincronia, mas narrado) em vez de sair mudo.
        script: script || undefined,
        // Mesma voz das prévias por cena: a locução contínua da montagem precisa soar como a
        // que você ouviu no card, não como um terceiro locutor.
        voiceId: voiceId || undefined,
        subtitles: legendar,
        // Trilha: a direção musical que o plano escreveu para ESTE filme.
        music: !!musicPrompt,
        musicPrompt,
      });
      if (j?.queued) {
        toast.ok("Montagem na fila. Pode fechar a aba — o filme aparece aqui quando terminar.");
        return; // o polling do status traz o filme pronto
      }
      if (!j?.url) throw new Error("a montagem não retornou o filme");
      setFilme(j.url);
      toast.ok("Filme montado!");
    } catch (e) {
      toast.err(e instanceof Error ? e.message : "Não foi possível montar o filme.");
    } finally {
      setMontando(false);
    }
    // `draftId` estava faltando aqui: sem ele o callback congelava o valor do primeiro render
    // (undefined) e a montagem caía no caminho SÍNCRONO mesmo com o roteiro já enfileirado —
    // que é justamente o que estoura o timeout num filme longo.
  }, [edges, name, narrarFilme, legendar, musicPrompt, voiceId, draftId, toast]);

  // Acompanha a fila: enquanto houver cena sem clipe, pergunta ao servidor o que ficou pronto.
  // É isto que permite fechar a aba no meio do lote — ao voltar, o canvas se atualiza sozinho.
  useEffect(() => {
    if (!draftId) return;
    const id = setInterval(async () => {
      try {
        const r = await Console.roteiroStatus(draftId);
        const caminho = caminhoPrincipal(nodesRef.current, edges);
        r.cenas.forEach((c, i) => {
          const alvo = caminho[i];
          if (c.clipUrl && alvo && !alvo.data.clipUrl) patch(alvo.id, { clipUrl: c.clipUrl, status: "done" });
          // O quadro vem pelo mesmo caminho do clipe desde que a imagem foi pra fila.
          if (c.imageUrl && alvo && alvo.data.imageUrl !== c.imageUrl) patch(alvo.id, { imageUrl: c.imageUrl, status: "done" });
        });
        // A montagem também é acompanhada aqui: é o que faz o filme aparecer sozinho depois de
        // fechar e reabrir a aba.
        setMontando(!!r.montando);
        if (r.filmUrl) setFilme(r.filmUrl);
        // Segue pollando enquanto faltar clipe OU quadro: parar no clipe deixava a imagem
        // enfileirada sem quem a buscasse.
        const falta = nodesRef.current.some((n) => !n.data.clipUrl || n.data.status === "img");
        if (!falta && !r.montando) clearInterval(id);
      } catch {
        /* servidor fora do ar por um instante: a próxima volta tenta de novo */
      }
    }, 8000);
    return () => clearInterval(id);
  }, [draftId, edges, patch]);

  // ── export / import ────────────────────────────────────────────────────────────
  const exportar = useCallback(() => {
    // Estilo do filme e imagem-chave viajam no export: o backup tem que reabrir com o MESMO look,
    // senão o JSON restaurado gera quadros diferentes dos originais.
    const doc: RoteiroDoc = {
      version: 1, name, nodes, edges, updatedAt: Date.now(),
      estiloFilme: estiloFilme || undefined, styleRefUrl: styleRefUrl || undefined,
    };
    const blob = new Blob([JSON.stringify(doc, null, 2)], { type: "application/json" });
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url; a.download = `${name.replace(/[^\w-]+/g, "-").toLowerCase() || "roteiro"}.json`;
    a.click(); URL.revokeObjectURL(url);
    // também copia o roteiro em texto pro clipboard (leitura rápida)
    navigator.clipboard?.writeText(roteiroTexto(doc)).catch(() => {});
    toast.ok("Roteiro exportado (JSON) + texto copiado.");
  }, [name, nodes, edges, toast, estiloFilme, styleRefUrl]);

  const importar = useCallback((file: File) => {
    const reader = new FileReader();
    reader.onload = () => {
      try {
        const d = JSON.parse(String(reader.result)) as RoteiroDoc;
        if (d.version !== 1 || !Array.isArray(d.nodes)) throw new Error("formato");
        setName(d.name || "Roteiro importado");
        setNodes(d.nodes); setEdges(d.edges || []);
        // Aditivos: JSON antigo não os tem e importa igual (fica sem look travado, como antes).
        setEstiloFilme(d.estiloFilme ?? ""); setStyleRefUrl(d.styleRefUrl ?? "");
        toast.ok("Roteiro importado.");
      } catch {
        toast.err("JSON de roteiro inválido.");
      }
    };
    reader.readAsText(file);
  }, [setNodes, setEdges, toast]);

  const limpar = useCallback(() => {
    if (!nodesRef.current.length || confirm("Limpar o roteiro inteiro?")) { setNodes([]); setEdges([]); }
  }, [setNodes, setEdges]);

  return (
    // Shell fixo ao LADO da sidebar (grid do .app = 252px + 1fr). Não uso inset:0/absoluto —
    // escapava pro viewport e cobria a sidebar. No mobile (≤820px) a sidebar vira drawer e
    // aparece a .topbar, então o canvas começa no topo-esquerda abaixo dela.
    <div className="roteiro-shell">
      <style>{`@keyframes spin{to{transform:rotate(360deg)}}.spin{animation:spin 1s linear infinite}
        .roteiro-shell{position:fixed;top:0;left:252px;right:0;bottom:0;display:flex;flex-direction:column;background:var(--bg)}
        @media (max-width:820px){ .roteiro-shell{left:0;top:56px} }
        .react-flow__attribution{display:none}
        .react-flow__controls{box-shadow:0 6px 24px rgba(0,0,0,.4);border-radius:10px;overflow:hidden}
        .react-flow__controls-button{background:var(--panel);border-bottom:1px solid var(--line);color:var(--text)}
        .react-flow__controls-button:hover{background:var(--panel2)}
        .react-flow__controls-button svg{fill:var(--text)}
        .react-flow__handle{border:1px solid var(--bg)}
        .react-flow__edge-path{stroke-width:2}`}</style>

      {/* toolbar */}
      <div style={{ display: "flex", alignItems: "center", gap: 8, padding: "10px 14px", borderBottom: "1px solid var(--line)", background: "var(--bg2)", flexWrap: "wrap" }}>
        <input value={name} onChange={(e) => setName(e.target.value)} placeholder="Nome do roteiro"
          style={{ background: "var(--panel)", border: "1px solid var(--line2)", borderRadius: 8, color: "var(--text)", padding: "7px 10px", fontSize: 13, minWidth: 200, fontFamily: "inherit" }} />
        <button onClick={() => setPlanoAberto(true)} title="Descreva a ideia e receba o filme planejado em cenas" style={{ ...tbBtn, background: "var(--red)", color: "#fff", borderColor: "var(--red)" }}>
          <Sparkles size={15} /> Criar filme
        </button>
        <button onClick={addCena} style={tbBtn}><Plus size={15} /> Nova cena</button>
        {/* Assistir ANTES de renderizar: os quadros já existem, o ritmo se julga de graça. */}
        <button onClick={() => setPlayerAberto(true)} disabled={totalCaminho === 0}
          title="Toca os quadros do caminho em sequência, no tempo de cada cena e com a narração — pra sentir o ritmo antes de gastar os clipes"
          style={{ ...tbBtn, opacity: totalCaminho === 0 ? 0.5 : 1 }}>
          <PlayCircle size={15} /> Assistir storyboard
        </button>
        <button onClick={renderCaminho} disabled={rendering || montando} style={{ ...tbBtn, opacity: rendering ? 0.6 : 1 }}>
          <Film size={15} /> {rendering ? "Renderizando…" : pendentesCount === 0 ? "Tudo renderizado" : `Renderizar ${pendentesCount === totalCaminho ? "caminho" : `pendentes (${pendentesCount})`}`}
        </button>
        {modelos.length > 0 && (
          <select
            value={videoModel}
            onChange={(e) => setVideoModel(e.target.value)}
            disabled={rendering || montando}
            title="Motor de vídeo do filme inteiro — trocar no meio muda a cara dos planos"
            style={{ background: "var(--bg2)", color: "var(--text)", border: "1px solid var(--line2)", borderRadius: 8, padding: "6px 8px", fontSize: 12, fontFamily: "inherit", maxWidth: 210 }}
          >
            {modelos.map((m) => (
              <option key={m.slug} value={m.slug}>
                {m.real_name ?? m.display_name}{m.cost_credits != null ? ` · ${m.cost_credits} cr` : ""}
              </option>
            ))}
          </select>
        )}
        {/* MOTOR DE IMAGEM dos quadros. "local (mmx)" = a rota do host, custo marginal zero e sem
            referência de identidade; qualquer outro = engine, que aceita as âncoras da Escaleta. */}
        {modelosImg.length > 0 && (
          <select
            value={imageModel}
            onChange={(e) => setImageModel(e.target.value)}
            disabled={rendering || montando}
            title="Motor das IMAGENS (quadro da cena). Vazio = motor local; escolher um usa o engine, que respeita as âncoras de personagem/cenário"
            style={{ background: "var(--bg2)", color: "var(--text)", border: "1px solid var(--line2)", borderRadius: 8, padding: "6px 8px", fontSize: 12, fontFamily: "inherit", maxWidth: 200 }}
          >
            <option value="">🖼️ local (mmx)</option>
            {modelosImg.map((m) => (
              <option key={m.slug} value={m.slug}>
                🖼️ {m.real_name ?? m.display_name}{m.cost_credits != null ? ` · ${m.cost_credits} cr` : ""}
              </option>
            ))}
          </select>
        )}
        {/* 🎨 ESTILO DO FILME — aplicação em MASSA. Trocar aqui reescreve o estilo de todas as
            cenas de uma vez; é o que trava o look num filme de 12 planos sem passar por 12
            seletores (e sem esquecer um, que é o que quebra a consistência). Ajuste fino de um
            plano específico continua no cartão da cena. */}
        <select
          value={estiloFilme}
          onChange={(e) => aplicarEstiloFilme(e.target.value)}
          disabled={rendering || montando}
          title="Estilo do filme — aplica a TODAS as cenas de uma vez (dá pra ajustar um plano específico depois, no cartão dele)"
          style={{ background: "var(--bg2)", color: "var(--text)", border: "1px solid var(--line2)", borderRadius: 8, padding: "6px 8px", fontSize: 12, fontFamily: "inherit", maxWidth: 220 }}
        >
          <option value="">🎨 estilo do filme (todas)…</option>
          {IMAGE_STYLES.map(([v, label]) => <option key={v} value={v}>{label}</option>)}
        </select>
        {/* 🖼️ IMAGEM-CHAVE DE ESTILO: entra em toda geração de quadro com o papel `estilo` — o
            modelo aproveita paleta/textura/luz dela e NÃO o assunto. É o que um slug de catálogo
            não descreve: "esse recorte de papel, essa retícula, esse azul". */}
        {styleRefUrl ? (
          <span title="Imagem-chave de estilo — enviada em toda geração de quadro como referência de LOOK (papel `estilo`)"
            style={{ display: "inline-flex", alignItems: "center", gap: 6, background: "var(--panel)", border: "1px solid var(--line2)", borderRadius: 8, padding: "3px 8px 3px 3px", fontSize: 12, color: "var(--muted)" }}>
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img src={styleRefUrl} alt="imagem-chave de estilo" style={{ width: 26, height: 26, objectFit: "cover", borderRadius: 6 }} />
            chave de estilo
            <button onClick={() => setEscolhendoEstilo(true)} title="Trocar a imagem-chave"
              style={{ background: "none", border: "none", color: "var(--peach)", cursor: "pointer", padding: 0, fontSize: 12 }}>trocar</button>
            <button onClick={() => setStyleRefUrl("")} title="Tirar a imagem-chave (o payload volta ao de antes)"
              style={{ background: "none", border: "none", color: "inherit", cursor: "pointer", padding: 0, fontSize: 12, lineHeight: 1 }}>✕</button>
          </span>
        ) : (
          <button onClick={() => setEscolhendoEstilo(true)} style={tbBtnGhost}
            title="Escolhe uma imagem de referência do LOOK do filme — vai em toda geração de quadro com o papel `estilo` (o modelo copia o acabamento, não o assunto)">
            🖼️ Imagem-chave de estilo
          </button>
        )}
        {/* VOZ do filme: vale pras prévias por cena (botão Narrar) E pra locução contínua da
            montagem. Sem vozes disponíveis o seletor some — a narração continua saindo na voz
            padrão em vez de bloquear o fluxo. */}
        {vozes.length > 0 && (
          <select
            value={voiceId}
            onChange={(e) => setVoiceId(e.target.value)}
            disabled={rendering || montando}
            title="Voz da narração — vale pro filme inteiro (prévia da cena e locução da montagem)"
            style={{ background: "var(--bg2)", color: "var(--text)", border: "1px solid var(--line2)", borderRadius: 8, padding: "6px 8px", fontSize: 12, fontFamily: "inherit", maxWidth: 170 }}
          >
            <option value="">🎙️ voz padrão</option>
            {vozes.map((v) => (
              <option key={v.id} value={v.id}>🎙️ {v.name}</option>
            ))}
          </select>
        )}
        <label title="Interpola cada clipe de 24 para 48 fps — movimento mais suave, alguns minutos a mais por cena" style={{ display: "inline-flex", alignItems: "center", gap: 5, color: "var(--muted)", fontSize: 12, cursor: "pointer" }}>
          <input type="checkbox" checked={smooth} onChange={(e) => setSmooth(e.target.checked)} disabled={rendering || montando} />
          🌊 fluido
        </label>
        {custoLote && (
          <span title={`${custoLote.cenas} cena(s) de clipe de IA × ${custoLote.unit} créditos${camerasCount ? ` · ${camerasCount} por câmera programada não custam nada` : ""}`}
            style={{ color: "var(--muted)", fontSize: 12, whiteSpace: "nowrap" }}>
            ≈ {custoLote.total} cr{camerasCount ? ` + ${camerasCount} grátis` : ""}
          </span>
        )}
        <button onClick={montar} disabled={rendering || montando} title="Junta os clipes das cenas do caminho num filme só" style={{ ...tbBtn, opacity: montando ? 0.6 : 1 }}>
          <Clapperboard size={15} /> {montando ? "Montando…" : "Montar filme"}
        </button>
        <label title="Lê as narrações das cenas como UMA locução contínua por cima do filme" style={{ display: "inline-flex", alignItems: "center", gap: 5, color: "var(--muted)", fontSize: 12, cursor: "pointer" }}>
          <input type="checkbox" checked={narrarFilme} onChange={(e) => setNarrarFilme(e.target.checked)} disabled={montando} />
          🎙️ narrar
        </label>
        <label title="Legenda sincronizada com a locução (só com narração)" style={{ display: "inline-flex", alignItems: "center", gap: 5, color: "var(--muted)", fontSize: 12, cursor: narrarFilme ? "pointer" : "default", opacity: narrarFilme ? 1 : 0.5 }}>
          <input type="checkbox" checked={legendar} onChange={(e) => setLegendar(e.target.checked)} disabled={montando || !narrarFilme} />
          legenda
        </label>
        {filme && (
          <a href={filme} target="_blank" rel="noreferrer" download title="Abrir/baixar o filme montado" style={{ ...tbBtnGhost, textDecoration: "none" }}>
            <Download size={15} /> Filme pronto
          </a>
        )}
        <div style={{ flex: 1 }} />
        <button onClick={exportar} style={tbBtnGhost}><Download size={15} /> Exportar</button>
        <button onClick={() => importRef.current?.click()} style={tbBtnGhost}><Upload size={15} /> Importar</button>
        <button onClick={limpar} style={{ ...tbBtnGhost, color: "#f2a3a3" }}><Trash2 size={15} /> Limpar</button>
        <input ref={importRef} type="file" accept="application/json" hidden
          onChange={(e) => { const f = e.target.files?.[0]; if (f) importar(f); e.target.value = ""; }} />
      </div>

      {/* Acervo pra escolher a imagem-chave de estilo. Mesmo seletor da âncora de vídeo: a URL
          precisa ser mídia NOSSA, senão o servidor recusa (anti-SSRF). */}
      <EscolherImagem
        aberto={escolhendoEstilo}
        onFechar={() => setEscolhendoEstilo(false)}
        onEscolher={(url) => { setStyleRefUrl(url); toast.ok("Imagem-chave de estilo definida — entra em toda geração de quadro."); }}
        titulo="Imagem-chave de estilo do filme"
      />

      {/* Ensaio do filme: só as cenas do CAMINHO principal, na ordem em que serão montadas. */}
      {playerAberto && totalCaminho > 0 && (
        <StoryboardPlayer
          cenas={caminhoAtual.map((n) => ({ id: n.id, data: n.data }))}
          onClose={() => setPlayerAberto(false)}
        />
      )}

      {/* canvas */}
      <div style={{ flex: 1, position: "relative" }}>
        <RoteiroCtx.Provider value={actions}>
          {planoAberto && (
        <div onClick={() => !planejando && setPlanoAberto(false)}
          style={{ position: "fixed", inset: 0, zIndex: 1200, background: "rgba(0,0,0,.86)", display: "flex", alignItems: "center", justifyContent: "center", padding: 20 }}>
          <div
            className="nodrag nowheel"
            onClick={(e) => e.stopPropagation()}
            // O canvas escuta teclado no documento (deletar nó, mover seleção). Sem cortar a
            // propagação aqui, cada tecla digitada no modal também virava evento do React Flow
            // — re-render do grafo a cada letra, e a aba trava.
            onKeyDown={(e) => e.stopPropagation()}
            onKeyUp={(e) => e.stopPropagation()}
            style={{ background: "var(--panel)", border: "1px solid var(--line2)", borderRadius: 14, width: "min(620px, 100%)", padding: 20, display: "flex", flexDirection: "column", gap: 14 }}>
            <div>
              <h2 style={{ margin: 0, fontSize: "1.05rem" }}>Criar filme</h2>
              <p className="txt" style={{ color: "var(--muted)", fontSize: ".85rem", margin: "6px 0 0" }}>
                Descreva a ideia. Sai o filme dividido em cenas — cada uma com o quadro, o movimento e a locução.
                Aqui nada é gerado ainda: o plano é texto, e refazer é de graça.
              </p>
            </div>
            <textarea
              value={ideia}
              onChange={(e) => setIdeia(e.target.value)}
              placeholder="Ex.: uma raposa vermelha atravessa a floresta nevada ao entardecer para alcançar o vale antes da noite"
              rows={4}
              disabled={planejando}
              style={{ background: "var(--bg2)", color: "var(--text)", border: "1px solid var(--line2)", borderRadius: 10, padding: "12px 14px", fontSize: ".95rem", fontFamily: "inherit", lineHeight: 1.55, resize: "vertical" }}
            />
            <div style={{ display: "flex", gap: 12, alignItems: "flex-end", flexWrap: "wrap" }}>
              <label style={{ display: "flex", flexDirection: "column", gap: 5 }}>
                <span style={{ color: "var(--muted)", fontSize: ".78rem" }}>Cenas</span>
                <input type="number" min={2} max={24} value={nCenas} disabled={planejando}
                  onChange={(e) => setNCenas(Math.max(2, Math.min(24, Number(e.target.value) || 2)))}
                  style={{ width: 86, background: "var(--bg2)", color: "var(--text)", border: "1px solid var(--line2)", borderRadius: 10, padding: "10px 12px", fontFamily: "inherit" }} />
              </label>
              <label style={{ display: "flex", flexDirection: "column", gap: 5 }}>
                <span style={{ color: "var(--muted)", fontSize: ".78rem" }}>Cada cena</span>
                <select value={durCena} onChange={(e) => setDurCena(e.target.value)} disabled={planejando}
                  style={{ background: "var(--bg2)", color: "var(--text)", border: "1px solid var(--line2)", borderRadius: 10, padding: "10px 12px", fontFamily: "inherit" }}>
                  <option value="5">5 segundos</option>
                  <option value="10">10 segundos</option>
                </select>
              </label>
              {/* Duração total à vista: é o número que decide se o filme cabe no que você quer. */}
              <span style={{ color: "var(--muted)", fontSize: ".8rem", paddingBottom: 11 }}>
                ≈ {Math.floor((nCenas * Number(durCena)) / 60)}min {String((nCenas * Number(durCena)) % 60).padStart(2, "0")}s de filme
              </span>
              <div style={{ flex: 1 }} />
              <button className="btn no" onClick={() => setPlanoAberto(false)} disabled={planejando}
                style={{ flex: "0 0 auto", padding: "10px 16px" }}>Cancelar</button>
              <button className="btn ok" onClick={criarFilme} disabled={planejando}
                style={{ flex: "0 0 auto", display: "inline-flex", alignItems: "center", gap: 7, padding: "10px 18px" }}>
                {planejando ? <span className="spinner" style={{ width: 14, height: 14 }} /> : <Sparkles size={15} />}
                {planejando ? "Planejando…" : "Planejar filme"}
              </button>
            </div>
          </div>
        </div>
      )}

      <ReactFlow
            colorMode="dark"
            nodes={nodes} edges={edges} nodeTypes={nodeTypes}
            onNodesChange={onNodesChange} onEdgesChange={onEdgesChange} onConnect={onConnect}
            fitView proOptions={{ hideAttribution: true }} deleteKeyCode={["Backspace", "Delete"]}
            defaultEdgeOptions={{ animated: true, style: { stroke: "var(--red)" } }}
            style={{ background: "var(--bg)" }}
          >
            <Background color="var(--line)" gap={22} />
            <Controls />
            <MiniMap nodeColor={() => "var(--panel2)"} maskColor="rgba(0,0,0,.55)" style={{ background: "var(--bg2)" }} />
          </ReactFlow>
          {!nodes.length && (
            <div style={{ position: "absolute", inset: 0, display: "grid", placeItems: "center", pointerEvents: "none" }}>
              <div style={{ textAlign: "center", color: "var(--muted)" }}>
                <div style={{ fontSize: 15, marginBottom: 6 }}>Canvas vazio</div>
                <div style={{ fontSize: 13 }}>Clique em <b style={{ color: "var(--peach)" }}>Nova cena</b> pra começar o roteiro.</div>
              </div>
            </div>
          )}
        </RoteiroCtx.Provider>

        {/* LIGHTBOX: o quadro/clipe em tela cheia. Julgar a mídia num cartão de 300px é o que
            faz um lote inteiro sair errado — aqui dá pra ver antes de renderizar o caminho.
            Esc ou clique fora fecham; o clique na mídia não propaga (senão fecharia ao dar play). */}
        {lightbox && (
          /* flex, não grid: com grid o maxHeight:100% da mídia não clampa (track auto) e
             imagem alta saía cortada sem scroll — mesmo fix dos elementos/galeria. */
          <div
            onClick={() => setLightbox(null)}
            style={{ position: "fixed", inset: 0, zIndex: 50, background: "rgba(0,0,0,.9)", display: "flex", alignItems: "center", justifyContent: "center", padding: 24 }}
          >
            {lightbox.kind === "video" ? (
              <video src={lightbox.url} controls autoPlay onClick={(e) => e.stopPropagation()}
                style={{ maxWidth: "100%", maxHeight: "100%", borderRadius: 10, background: "#000" }} />
            ) : (
              // eslint-disable-next-line @next/next/no-img-element
              <img src={lightbox.url} alt="quadro da cena" onClick={(e) => e.stopPropagation()}
                style={{ maxWidth: "100%", maxHeight: "100%", objectFit: "contain", borderRadius: 10 }} />
            )}
            <div style={{ position: "fixed", top: 16, right: 20, display: "flex", gap: 8 }} onClick={(e) => e.stopPropagation()}>
              <a className="btn" href={lightbox.url} target="_blank" rel="noreferrer" download
                style={{ ...tbBtnGhost, color: "#fff", borderColor: "rgba(255,255,255,.3)", textDecoration: "none" }}>
                <Download size={15} /> Baixar
              </a>
              <button onClick={() => setLightbox(null)} style={{ ...tbBtnGhost, color: "#fff", borderColor: "rgba(255,255,255,.3)" }}>
                ✕ Fechar
              </button>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}

// Ação primária = gradiente vermelho do tema (igual .btn.ok). Ghost = transparente + borda.
const tbBtn: React.CSSProperties = {
  display: "flex", alignItems: "center", gap: 6, background: "linear-gradient(135deg,var(--red),var(--red2))",
  border: 0, borderRadius: 8, color: "#fff", fontSize: 13, fontWeight: 700, padding: "8px 13px",
  cursor: "pointer", fontFamily: "inherit", boxShadow: "0 4px 16px rgba(226,74,49,.28)",
};
const tbBtnGhost: React.CSSProperties = {
  display: "flex", alignItems: "center", gap: 6, background: "transparent", border: "1px solid var(--line2)",
  borderRadius: 8, color: "var(--muted)", fontSize: 13, padding: "8px 12px", cursor: "pointer", fontFamily: "inherit",
};

export default function RoteiroPage() {
  return (
    <ReactFlowProvider>
      <Canvas />
    </ReactFlowProvider>
  );
}
