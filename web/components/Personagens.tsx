"use client";

import { sfetch } from "@/lib/api";
import { TextModelSelect } from "@/components/TextModelSelect";
import { PromptPicker } from "@/components/PromptPicker";
import { FichaPersonagem } from "@/components/FichaPersonagem";
import { HistoricoDeVersoes } from "@/components/HistoricoDeVersoes";
import { useDialogo } from "@/components/ui/Dialogo";
import { useJobs } from "@/lib/jobs";
import { useCallback, useEffect, useRef, useState } from "react";

// Biblioteca de PERSONAGENS reutilizáveis. Cada personagem é uma BASE: descrição + estilo →
// imagem-base (retrato frontal, âncora i2i) → MODEL SHEET híbrido (turnaround + bíblia: lock
// canônico + paleta/traços/acessórios/expressões). Reutilizável em Histórias e Mídia.

type Swatch = { hex: string; label: string };
// O bible carrega DOIS conjuntos: a bíblia VISUAL do model sheet (palette/traits/accessories/
// expressões) e a FICHA metodológica (desejo/conflito/arco… — editada em FichaPersonagem). A
// index signature deixa os dois coexistirem no mesmo JSON.
type Bible = { palette?: Swatch[]; traits?: string[]; accessories?: string[]; expressions?: string[]; [k: string]: unknown };
type Sheet = { kind: string; url: string; id?: string; label?: string }; // v3: angles|head|poses|palette + outfit (figurino, id/label próprios)
type Character = {
  id: number;
  name: string;
  description: string;
  style: string;
  image_model?: string | null; // modelo de imagem SALVO do personagem (regenerar reusa)
  text_model?: string | null;  // modelo de texto SALVO (bíblia do model sheet)
  base_url?: string | null;
  sheet_url?: string | null;
  sheets?: Sheet[] | null; // pranchas do model sheet v3 (angles + head + poses + palette) + figurinos (outfit)
  lock?: string | null;
  // MALHA (GLB): é dela que sai a âncora no ângulo exato de cada plano da decupagem.
  mesh_url?: string | null;
  // Estado da geração ASSÍNCRONA da malha: "gerando" | "erro" | "aviso" | null. A malha leva ~5min
  // de GPU — segurar isso numa requisição HTTP dava HTTP 499 (o navegador fechava a conexão antes
  // do fim). Agora o POST só enfileira e este campo, lido por polling, é quem diz que terminou.
  mesh_status?: string | null;
  // O motivo por trás do status: ressalva do juiz ('aviso') ou causa da falha ('erro').
  mesh_msg?: string | null;
  bible?: Bible | null;
  archetype?: string | null; // F1: papel/arquétipo (heroi|mentor|…)
  logline?: string | null;   // F1: "quem quer o quê, contra o quê"
  status?: string; // ''=pronto | base|sheet|edit = gerando
  updated_at?: string;
};

// Prompts RECONSTRUÍDOS (não persistidos) da base + de cada shot do model sheet — pra copiar e
// usar numa ferramenta externa sem API quando o Reachyn não consegue gerar (ex: agregador sem
// saldo). GET /api/characters/{id}/prompts — determinístico, sem custo.
type CharPromptShot = { label: string; prompt: string };
type CharPromptPanel = { kind: string; title: string; subtitle: string; shots: CharPromptShot[] };
type CharPrompts = { base: string | null; lock: string | null; style: string; sheets: CharPromptPanel[] };

// Kinds que dá pra subir manualmente como prancha do model sheet (o bundle canônico + os 2 opt-in).
const UPLOAD_KINDS: [string, string][] = [
  ["angles", "🧭 Turnaround (ângulos)"], ["head", "😀 Cabeça & expressões"], ["poses", "🤸 Poses"],
  ["accessories", "👗 Acessórios & vestimenta"], ["palette", "🎨 Paleta de cores"],
  ["shots", "🎬 Planos de câmera"], ["lighting", "💡 Testes de iluminação"],
];

// Rótulo amigável de cada prancha do model sheet (v3 + legado v2). PANEL_ORDER = ordem canônica na
// galeria; kinds que o backend regenera/remove são os 4 do v3 (angles|head|poses|palette).
const SHEET_LABELS: Record<string, string> = {
  angles: "🧭 Turnaround (ângulos)",
  head: "😀 Cabeça & expressões",
  poses: "🤸 Poses",
  accessories: "👗 Acessórios & vestimenta",
  palette: "🎨 Paleta de cores",
  shots: "🎬 Planos de câmera",
  lighting: "💡 Testes de iluminação",
  outfit: "👕 Figurino",
  // legado v2 (personagens gerados antes):
  turnaround: "🔄 Turnaround (8 vistas)",
  expressions: "😀 Expressões & cabeça",
};
const PANEL_ORDER = ["angles", "head", "poses", "accessories", "palette", "shots", "lighting", "turnaround", "expressions", "outfit"];
// Todos os kinds (menos figurino manual) são regeneráveis — os LEGADO regeneram como o grupo novo
// equivalente (backend mapeia turnaround→angles, expressions→head e substitui a folha antiga).
const REGENERABLE = new Set(["angles", "head", "poses", "accessories", "palette", "shots", "lighting", "turnaround", "expressions"]);
const sheetLabel = (k: string) => SHEET_LABELS[k] ?? "📋 Prancha";

// Técnica da imagem — MESMO catálogo da Mídia (lib/imageStyles, espelho do engine). Antes esta
// tela tinha uma cópia com 11 das 19 opções: um personagem não podia nascer em épico, macro,
// livro infantil, arquitetura, editorial nem claymation, que a Mídia já oferecia. E ainda listava
// `vintage`, que é look de COR e migrou pro seletor 🎨 Cor.
import { IMAGE_STYLES as STYLES, imageStyleLabel as styleLabel } from "@/lib/imageStyles";

const sleep = (ms: number) => new Promise((r) => setTimeout(r, ms));

export function Personagens() {
  const jobs = useJobs();
  const { perguntar, dialogo } = useDialogo();
  const [items, setItems] = useState<Character[]>([]);
  const [loading, setLoading] = useState(true);
  const [q, setQ] = useState("");
  const [msg, setMsg] = useState<string | null>(null);
  const [open, setOpen] = useState(false);
  const [editId, setEditId] = useState<number | null>(null); // null = criando
  const [name, setName] = useState("");
  const [description, setDescription] = useState("");
  const [style, setStyle] = useState("realista");
  const [busy, setBusy] = useState(false);
  const [selId, setSelId] = useState<number | null>(null); // card expandido (detalhe)
  const [editBaseText, setEditBaseText] = useState<Record<number, string>>({}); // instrução i2i por personagem
  const [panelEdit, setPanelEdit] = useState<string | null>(null); // `${id}:${kind}` da prancha com editor de ajuste aberto
  const [panelTweak, setPanelTweak] = useState<Record<string, string>>({}); // ajuste livre por prancha
  const [outfitText, setOutfitText] = useState<Record<number, string>>({}); // descrição da roupa (figurino manual) por personagem
  const [outfitReplaceId, setOutfitReplaceId] = useState<Record<number, string>>({}); // id do figurino em edição (regera no lugar)
  const [viewer, setViewer] = useState<{ url: string; title: string } | null>(null); // visualizador em tela cheia
  const [promptPickerOpen, setPromptPickerOpen] = useState(false); // modal "carregar da aba Prompts" → descrição
  // MOLDES (personas "Molde: …" da aba Prompts): homem, mulher, menino, menina, objeto. Cada
  // categoria erra de um jeito — criança sai como adulto em miniatura, objeto sai em escala de
  // vitrine — então o texto que guia a descrição é por categoria. Aqui eles engordam uma IDEIA
  // curta até virar a descrição densa; na extração, é a foto que passa pelo mesmo molde.
  const [moldes, setMoldes] = useState<{ id: number; title: string }[]>([]);
  const [molde, setMolde] = useState("");
  const [moldeBusy, setMoldeBusy] = useState(false);
  // Molde escolhido PARA A EXTRAÇÃO, por personagem. Vazio = automático (o detector decide pelo que
  // a vision viu). Existe porque a detecção acerta o caso claro e erra o ambíguo — e aí o dono da
  // foto sabe o que é melhor que qualquer heurística.
  const [moldeExtrair, setMoldeExtrair] = useState<Record<number, string>>({});
  const [charPromptsOpen, setCharPromptsOpen] = useState<Record<number, boolean>>({}); // seção "📝 Prompts" aberta por personagem
  const [charPromptsData, setCharPromptsData] = useState<Record<number, CharPrompts>>({}); // cache — só busca 1x por personagem
  const [charPromptsLoading, setCharPromptsLoading] = useState<Record<number, boolean>>({});
  const [uploadKind, setUploadKind] = useState<Record<number, string>>({}); // kind selecionado no "adicionar prancha externa" por personagem
  // Modelo de imagem da BASE (text-to-image). Cada modelo tem qualidade e custo diferentes (Fase 2).
  const [imageModels, setImageModels] = useState<{ slug: string; display_name: string; cost_credits: number | null; real_name?: string; own_account?: boolean }[]>([]);
  const [imageModel, setImageModel] = useState("");
  const [textModel, setTextModel] = useState(""); // seletor de modelo da bíblia (texto)
  // 🧊 Gerar malha DIRETO daqui (a malha é do personagem — a aba 3D é o acervo/viewer).
  // Só aparece com o gerador instalado no Estúdio (mesma luz da aba 3D).
  const [temGeradorMalha, setTemGeradorMalha] = useState(false);
  const [gerandoMalhaId, setGerandoMalhaId] = useState<number | null>(null);
  useEffect(() => {
    sfetch("/api/mesh-health").then((r) => r.json()).then((d) => setTemGeradorMalha(!!d?.ok)).catch(() => setTemGeradorMalha(false));
  }, []);
  useEffect(() => {
    sfetch("/api/gen-models?kind=image").then((r) => r.json()).then((j) => {
      const list = (Array.isArray(j) ? j : j?.data ?? []).filter((m: { subtype?: string }) => m.subtype === "text_to_image");
      setImageModels(list);
      // Default = o TOPO (img-ultra): a base é a ÂNCORA de identidade do personagem inteiro
      // (model sheet + cenas) — o 1º da lista era o modelo mais simples e o "Regerar base"
      // do card usava ele silenciosamente (qualidade > custo aqui; gera 1× por personagem).
      const best = list.find((m: { slug: string }) => m.slug === "img-ultra") ?? list[0];
      if (best) setImageModel((cur) => cur || best.slug);
    }).catch(() => {});
  }, []);
  const editorRef = useRef<HTMLDivElement | null>(null);
  const alive = useRef(true);
  useEffect(() => () => { alive.current = false; }, []);

  // Fecha o visualizador em tela cheia com Esc.
  useEffect(() => {
    if (!viewer) return;
    const onKey = (e: KeyboardEvent) => { if (e.key === "Escape") setViewer(null); };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [viewer]);

  // Os moldes vêm da aba Prompts (não de lista fixa no código): renomear, ajustar ou criar um novo
  // molde é edição de texto lá, sem deploy — mesmo trato das personas do fluxo de filme.
  useEffect(() => {
    sfetch("/api/prompts").then((r) => r.json()).then((j) => {
      const list: { id: number; title: string }[] = (Array.isArray(j) ? j : j?.data ?? []);
      setMoldes(list.filter((p) => (p.title || "").includes("Molde:")));
    }).catch(() => {});
  }, []);

  // `upsert` e `poll` ficam ANTES de todo mundo que os chama (load, gerar, salvar…). Estavam
  // declarados no meio do arquivo e só funcionavam por hoisting — o que o lint reprova, porque
  // uma referência capturada antes da declaração não acompanha o valor se ele mudar.
  function upsert(c: Character) {
    setItems((prev) => (prev.some((x) => x.id === c.id) ? prev.map((x) => (x.id === c.id ? { ...x, ...c } : x)) : [c, ...prev]));
  }

  // Polling da geração assíncrona (job): lê /api/characters/{id} até status sair de '' (~10min).
  // O model sheet v3 são 4 pranchas (jobs) — se o worker for único elas correm em série; janela
  // folgada cobre o pior caso. Se estourar, o load() retoma o polling na próxima abertura (e o
  // backend destrava sozinho status preso > 30min).
  async function poll(id: number): Promise<Character | null> {
    for (let n = 0; n < 150 && alive.current; n++) {
      await sleep(4000);
      try {
        const r = await sfetch(`/api/characters/${id}`);
        if (!r.ok) continue;
        const c = (await r.json()) as Character;
        upsert(c);
        if (!c.status) return c;
      } catch { /* tenta de novo */ }
    }
    return null;
  }

  // Baixa a imagem (blob → download; fallback abre em nova aba). Mesmo padrão do Story.tsx (mesmo S3).
  async function baixar(url: string, name: string) {
    try {
      const res = await fetch(url);
      if (!res.ok) throw new Error("fetch falhou");
      const obj = URL.createObjectURL(await res.blob());
      const a = document.createElement("a");
      a.href = obj; a.download = name;
      document.body.appendChild(a); a.click(); a.remove();
      URL.revokeObjectURL(obj);
    } catch { window.open(url, "_blank"); }
  }

  // Copia a imagem pro clipboard (converte pra PNG via canvas se preciso); fallback = copia o link.
  async function copiarImagem(url: string) {
    try {
      const blob = await (await fetch(url)).blob();
      let png = blob;
      if (blob.type !== "image/png") {
        const bmp = await createImageBitmap(blob);
        const c = document.createElement("canvas");
        c.width = bmp.width; c.height = bmp.height;
        c.getContext("2d")!.drawImage(bmp, 0, 0);
        png = await new Promise<Blob>((resolve) => c.toBlob((b) => resolve(b!), "image/png"));
      }
      await navigator.clipboard.write([new ClipboardItem({ "image/png": png })]);
      setMsg("📋 Imagem copiada!");
    } catch {
      try { await navigator.clipboard.writeText(url); setMsg("🔗 Link da imagem copiado."); }
      catch { setMsg("❌ não foi possível copiar a imagem."); }
    }
  }

  // Copia TEXTO (prompt/lock) — distinto de copiarImagem (que copia a imagem em si).
  async function copiarTexto(text: string) {
    try { await navigator.clipboard.writeText(text); setMsg("📝 Prompt copiado!"); }
    catch { setMsg("❌ não foi possível copiar o texto."); }
  }

  // Abre/fecha a seção de prompts; busca UMA VEZ (cache em charPromptsData) — evita recomputar o
  // model sheet inteiro a cada toggle (a rota reconstrói os prompts on-demand, não é grátis).
  async function togglePrompts(c: Character) {
    const abrir = !charPromptsOpen[c.id];
    setCharPromptsOpen((p) => ({ ...p, [c.id]: abrir }));
    if (abrir && !charPromptsData[c.id]) {
      setCharPromptsLoading((p) => ({ ...p, [c.id]: true }));
      try {
        const r = await sfetch(`/api/characters/${c.id}/prompts`);
        const d = await r.json();
        if (r.ok) setCharPromptsData((p) => ({ ...p, [c.id]: d as CharPrompts }));
        else setMsg("❌ não foi possível carregar os prompts.");
      } catch { setMsg("❌ não foi possível carregar os prompts."); }
      setCharPromptsLoading((p) => ({ ...p, [c.id]: false }));
    }
  }

  // Round-trip inverso: sobe uma imagem gerada FORA do Reachyn (ferramenta sem API) como base ou
  // como prancha do model sheet. Sem IA, sem cota — só troca a URL no personagem.
  // A malha entra PRONTA, de onde o usuário quiser (3D Gen Studio, Tripo, Blender): gerar malha
  // exige GPU local ou API 3D paga. O que fazemos com ela é o que importa — renderizar o ângulo
  // que a decupagem pediu e usar como âncora daquele plano.
  async function uploadMalha(c: Character, file: File) {
    setMsg("📤 Enviando a malha…");
    try {
      const fd = new FormData();
      fd.append("tipo", "character"); fd.append("id", String(c.id)); fd.append("file", file);
      const r = await sfetch("/api/mesh-upload", { method: "POST", body: fd });
      const d = await r.json().catch(() => ({}));
      if (!r.ok || !d?.ok) { setMsg("❌ " + (d?.error || "não foi possível subir a malha")); return; }
      upsert(d.asset as Character);
      setMsg("✅ Malha no personagem. Agora cada plano da decupagem pode gerar a âncora do ângulo dele.");
    } catch { setMsg("❌ não foi possível subir agora."); }
  }

  // 🔁 POLLING da malha — irmão do poll() da imagem, mas olhando `mesh_status` (que é estado
  // INDEPENDENTE do `status`: dá pra ter malha na fila sem geração de imagem em curso).
  // Existe porque a geração é assíncrona: 150 × 4s = 10 min de teto, folga larga sobre os ~5 min
  // do pior caso medido. Se estourar, o servidor destrava o asset sozinho (auto-heal de 30 min).
  async function pollMalha(id: number): Promise<{ fim: string | null | "timeout"; msg: string }> {
    for (let n = 0; n < 150 && alive.current; n++) {
      await sleep(4000);
      try {
        const r = await sfetch(`/api/characters/${id}`);
        if (!r.ok) continue;
        const c = (await r.json()) as Character;
        upsert(c);
        // O MOTIVO volta junto com o status: é ele que diz o que fazer. Sem isso a tela só
        // sabia dizer "falhou", e o usuário reclicava contra um problema que estava no dado.
        if (c.mesh_status !== "gerando") return { fim: c.mesh_status ?? null, msg: (c.mesh_msg ?? "").trim() };
      } catch { /* tenta de novo */ }
    }
    return { fim: "timeout", msg: "" };
  }

  // 🧊 GERAR a malha no Estúdio a partir daqui: o engine faz a ficha de malha (estilo
  // Pixar, julgada pela visão), a malha (Hunyuan3D), a limpeza no Blender e o juiz final —
  // se o juiz tiver ressalva, ela chega como mesh_status "aviso". 1 malha por clique.
  //
  // ASSÍNCRONO desde 2026-08-02: antes o clique esperava a resposta HTTP da geração inteira e
  // NUNCA chegava — o navegador desistia no meio (HTTP 499 no servidor) enquanto a GPU seguia
  // trabalhando. Agora o POST só enfileira e quem descobre o fim é o polling.
  async function gerarMalha(c: Character) {
    if (!c.base_url) { setMsg("🖼️ Gere ou suba a imagem-base antes — a malha nasce dela."); return; }
    setGerandoMalhaId(c.id);
    setMsg("🧊 Gerando a malha no Estúdio… (ficha → malha → limpeza; leva alguns minutos)");
    try {
      const r = await sfetch("/api/mesh-generate", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ tipo: "character", id: c.id, imageUrl: c.base_url }),
      });
      const d = await r.json().catch(() => ({}));
      if (!r.ok || !d?.ok) { setMsg("❌ " + (d?.error || "a geração de malha falhou")); return; }
      upsert(d.asset as Character);

      const { fim, msg: motivo } = await pollMalha(c.id);
      if (fim === "erro") setMsg("❌ " + (motivo || "a geração de malha falhou no Estúdio — tente de novo ou suba um .glb."));
      else if (fim === "aviso") setMsg("⚠️ Malha pronta, mas com ressalva do controle de qualidade" + (motivo ? `: ${motivo}` : " — confira na aba 3D."));
      else if (fim === "timeout") setMsg("⏳ A malha ainda está na fila — recarregue em alguns minutos.");
      else setMsg("✅ Malha pronta — inspecione na aba 3D ou gere âncoras de ângulo na decupagem.");
    } catch { setMsg("❌ não foi possível gerar a malha agora."); } finally { setGerandoMalhaId(null); }
  }

  async function uploadBase(c: Character, file: File) {
    if (c.status) { setMsg("⏳ Já existe uma geração em andamento para este personagem."); return; }
    setMsg("📤 Enviando imagem-base…");
    try {
      const fd = new FormData(); fd.append("file", file);
      const r = await sfetch(`/api/characters/${c.id}/base-upload`, { method: "POST", body: fd });
      const d = await r.json();
      if (!r.ok || !d?.ok) { setMsg("❌ " + (d?.error || "não foi possível subir a imagem")); return; }
      upsert(d.character as Character);
      setMsg("✅ Base atualizada com a imagem enviada.");
    } catch { setMsg("❌ não foi possível subir agora."); }
  }
  async function uploadPanel(c: Character, kind: string, files: FileList | File[]) {
    if (c.status) { setMsg("⏳ Já existe uma geração em andamento para este personagem."); return; }
    const list = Array.from(files).filter(Boolean);
    if (list.length === 0) return;
    const multi = list.length >= 2;
    setMsg(multi
      ? `📤 Enviando ${list.length} vistas e montando a prancha…`
      : "📤 Enviando prancha…");
    try {
      const fd = new FormData();
      fd.append("kind", kind);
      if (multi) {
        list.forEach((f) => fd.append("files[]", f));
      } else {
        fd.append("file", list[0]);
      }
      const r = await sfetch(`/api/characters/${c.id}/panel-upload`, { method: "POST", body: fd });
      const d = await r.json();
      if (!r.ok || !d?.ok) { setMsg("❌ " + (d?.error || "não foi possível subir a prancha")); return; }
      upsert(d.character as Character);
      setMsg(multi
        ? `✅ Prancha montada com ${list.length} vistas.`
        : "✅ Prancha atualizada com a imagem enviada.");
    } catch { setMsg("❌ não foi possível subir agora."); }
  }

  async function load() {
    setLoading(true);
    try {
      const r = await sfetch("/api/characters");
      const d = await r.json();
      const list: Character[] = Array.isArray(d) ? d : [];
      setItems(list);
      // Retoma o polling de qualquer personagem que ficou gerando (sessão anterior).
      list.filter((c) => c.status).forEach((c) => poll(c.id));
      // Idem para a MALHA: a fila 3D continua rodando no servidor mesmo com a página fechada,
      // então ao voltar a tela reencontra a geração em vez de mostrar o botão como se nada houvesse.
      list.filter((c) => c.mesh_status === "gerando").forEach((c) => {
        setGerandoMalhaId(c.id);
        void pollMalha(c.id).finally(() => setGerandoMalhaId((id) => (id === c.id ? null : id)));
      });
    } catch { setItems([]); }
    setLoading(false);
  }
  useEffect(() => { load(); }, []); // eslint-disable-line react-hooks/exhaustive-deps

  function novo() {
    setEditId(null); setName(""); setDescription(""); setStyle("realista"); setOpen(true); setMsg(null);
    setTimeout(() => editorRef.current?.scrollIntoView({ behavior: "smooth", block: "center" }), 50);
  }
  function editarCampos(c: Character) {
    // Personagem extraído de imagem tem description = o CHARACTER LOCK inteiro — não jogar isso no
    // campo de descrição (era o que "repetia o lock" no editar). A identidade fica travada à parte.
    const desc = (c.description || "").startsWith("MANDATORY CHARACTER LOCK") ? "" : (c.description || "");
    setEditId(c.id); setName(c.name); setDescription(desc); setStyle(c.style || "realista"); if (c.image_model) setImageModel(c.image_model); if (c.text_model) setTextModel(c.text_model); setOpen(true); setMsg(null);
    setTimeout(() => editorRef.current?.scrollIntoView({ behavior: "smooth", block: "center" }), 50);
  }
  function fechar() { setOpen(false); setEditId(null); setName(""); setDescription(""); setStyle("realista"); }

  async function salvar() {
    // Descrição é obrigatória só na CRIAÇÃO (é a base da geração). Ao EDITAR um personagem que já
    // existe — ex.: criado por "Extrair de imagem", que não gera descrição textual — não bloquear a
    // edição de nome/estilo/modelo por falta de descrição (era o que travava mudar estilo/qualidade).
    if (!editId && !description.trim()) { setMsg("❌ Descreva o personagem (traços, cores, roupa…)."); return; }
    setBusy(true); setMsg(null);
    try {
      const body = JSON.stringify({ name: name.trim(), description: description.trim(), style, image_model: imageModel || undefined, text_model: textModel || undefined });
      const r = editId
        ? await sfetch(`/api/characters/${editId}`, { method: "PATCH", body })
        : await sfetch("/api/characters", { method: "POST", body });
      const d = await r.json();
      if (!r.ok || !d?.ok) { setMsg("❌ " + (d?.error || d?.message || "não foi possível salvar")); setBusy(false); return; }
      upsert(d.character as Character);
      setSelId((d.character as Character).id);
      setMsg(editId ? "✅ Personagem atualizado." : "✅ Personagem criado. Agora gere a imagem-base.");
      fechar();
    } catch { setMsg("❌ não foi possível salvar agora."); }
    setBusy(false);
  }

  async function excluir(c: Character) {
    if (!(await perguntar({
      titulo: `Excluir "${c.name}"?`,
      mensagem: "As imagens já usadas em histórias e filmes não são apagadas.\nO personagem sai da biblioteca e deixa de ficar disponível para novas gerações.",
      confirmar: "Excluir personagem",
      perigo: true,
    }))) return;
    setItems((prev) => prev.filter((x) => x.id !== c.id));
    if (selId === c.id) setSelId(null);
    await sfetch(`/api/characters/${c.id}`, { method: "DELETE" }).catch(() => {});
  }

  // Dispara uma geração assíncrona (base/sheet/edit-base) + faz polling até concluir.
  async function gerar(c: Character, path: string, body: Record<string, unknown>, okMsg: string): Promise<Character | null> {
    if (c.status) { setMsg("⏳ Já existe uma geração em andamento para este personagem."); return null; }
    setMsg(null);
    const beforeBase = c.base_url || ""; // p/ detectar se a base REALMENTE mudou (regerar não zera a antiga)
    // Feedback IMEDIATO: o card mostra "Gerando…" já no clique. O POST do sheet gera a bíblia de forma
    // síncrona (~40s) antes de responder; sem isto a UI ficava muda e parecia que "não fazia nada".
    upsert({ ...c, status: path });
    try {
      const r = await sfetch(`/api/characters/${c.id}/${path}`, { method: "POST", body: JSON.stringify(body) });
      const d = await r.json();
      if (!r.ok || !d?.ok) { upsert({ ...c, status: "" }); setMsg("❌ " + (d?.error || d?.message || "não foi possível gerar")); return null; }
      upsert(d.character as Character); // status confirmado pelo servidor
      // Central de Tarefas: o acompanhamento segue mesmo se o usuário sair da página (S1).
      jobs.registerJob({ type: "character", charId: c.id, href: "/personagens", label: `🧍 ${c.name} — ${path === "sheet" ? "model sheet" : "imagem-base"}` });
      const done = await poll(c.id);
      // Sucesso REAL = artefato produzido, não só o status esvaziado (um job que falha também limpa o
      // status). Base: base_url novo (diferente do anterior). Model sheet: pelo menos 1 prancha gerada.
      const ok = !!done && !done.status && (
        path === "sheet"
          ? Array.isArray(done.sheets) && done.sheets.length > 0
          : !!done.base_url && done.base_url !== beforeBase
      );
      setMsg(ok ? okMsg : "❌ A geração não concluiu — o servidor não retornou a imagem. Tente de novo (a cota foi estornada).");
      return ok ? done : null;
    } catch { upsert({ ...c, status: "" }); setMsg("❌ não foi possível gerar agora."); return null; }
  }

  // Card "Regerar base": manda o modelo SALVO do personagem (servidor também cai no salvo se
  // vazio) — antes usava o estado global do editor e regenerava no modelo mais simples.
  const gerarBase = (c: Character) => gerar(c, "base", c.image_model ? { model: c.image_model } : {}, "✅ Imagem-base gerada.");
  // Card "Gerar model sheet": usa o modelo de texto SALVO do personagem (servidor também cai no
  // salvo) — a escolha é feita na criação e só muda em edição explícita.
  const gerarSheet = (c: Character) => gerar(c, "sheet", { lang: "pt-BR", textModel: c.text_model || undefined }, "✅ Model sheet gerado (turnaround + cabeça & expressões + poses + acessórios + paleta).");

  // Regenera/edita UMA prancha do model sheet (mantém as outras). tweak = ajuste livre opcional.
  async function regenerarPrancha(c: Character, kind: string, tweak?: string) {
    if (c.status) { setMsg("⏳ Já existe uma geração em andamento para este personagem."); return; }
    setPanelEdit(null);
    setMsg(null);
    try {
      const r = await sfetch(`/api/characters/${c.id}/panel`, { method: "POST", body: JSON.stringify({ kind, tweak: (tweak || "").trim(), lang: "pt-BR" }) });
      const d = await r.json();
      if (!r.ok || !d?.ok) { setMsg("❌ " + (d?.error || d?.message || "não foi possível regerar a prancha")); return; }
      upsert(d.character as Character); // status='sheet' → card mostra "gerando…"
      const done = await poll(c.id);
      setMsg(done && !done.status ? "✅ Prancha atualizada." : "❌ A geração falhou (ou demorou demais). Sua cota foi estornada.");
    } catch { setMsg("❌ não foi possível regerar agora."); }
  }

  // Remove UMA prancha do model sheet (pelo índice no array sheets[]). Não apaga a mídia do S3.
  async function removerPrancha(c: Character, index: number) {
    if (!(await perguntar({
      titulo: "Remover esta prancha?",
      mensagem: "Sai do model sheet deste personagem. A imagem continua na Galeria.",
      confirmar: "Remover prancha",
      perigo: true,
    }))) return;
    try {
      const r = await sfetch(`/api/characters/${c.id}/panel`, { method: "DELETE", body: JSON.stringify({ index }) });
      const d = await r.json();
      if (!r.ok || !d?.ok) { setMsg("❌ " + (d?.error || d?.message || "não foi possível remover")); return; }
      upsert(d.character as Character);
    } catch { setMsg("❌ não foi possível remover agora."); }
  }

  // Figurino manual: veste o personagem com a roupa DESCRITA (peças separadas + personagem vestido),
  // i2i da base. replaceId regera um figurino existente no lugar; vazio cria um novo (vários coexistem).
  async function gerarFigurino(c: Character, description: string, replaceId?: string) {
    if (!description.trim()) { setMsg("❌ Descreva a roupa/figurino (ex: jaqueta de couro, calça jeans, tênis branco)."); return; }
    if (c.status) { setMsg("⏳ Já existe uma geração em andamento para este personagem."); return; }
    setMsg(null);
    try {
      const r = await sfetch(`/api/characters/${c.id}/outfit`, { method: "POST", body: JSON.stringify({ description: description.trim(), replaceId: replaceId || "", lang: "pt-BR" }) });
      const d = await r.json();
      if (!r.ok || !d?.ok) { setMsg("❌ " + (d?.error || d?.message || "não foi possível gerar o figurino")); return; }
      upsert(d.character as Character); // status='sheet' → card mostra "gerando…"
      const done = await poll(c.id);
      setMsg(done && !done.status ? "✅ Figurino gerado." : "❌ A geração falhou (ou demorou demais). Sua cota foi estornada.");
    } catch { setMsg("❌ não foi possível gerar o figurino agora."); }
  }
  // Botão "Adicionar/Salvar figurino": usa o textarea + (se editando) o replaceId; limpa os campos.
  function enviarFigurino(c: Character) {
    const desc = outfitText[c.id] || "";
    const replaceId = outfitReplaceId[c.id] || "";
    setOutfitText((t) => ({ ...t, [c.id]: "" }));
    setOutfitReplaceId((t) => ({ ...t, [c.id]: "" }));
    gerarFigurino(c, desc, replaceId);
  }
  // "Editar" um figurino: joga a descrição no textarea + marca o id p/ regerar no lugar ao salvar.
  function editarFigurino(c: Character, sh: Sheet) {
    setOutfitText((t) => ({ ...t, [c.id]: sh.label || "" }));
    setOutfitReplaceId((t) => ({ ...t, [c.id]: sh.id || "" }));
    setMsg("✏️ Ajuste a descrição do figurino e clique em Salvar figurino.");
  }

  // Destrava um personagem preso em "gerando" (job órfão/perdido). Zera o status no servidor.
  async function cancelarGeracao(c: Character) {
    try {
      const r = await sfetch(`/api/characters/${c.id}/reset`, { method: "POST" });
      const d = await r.json();
      if (d?.character) upsert(d.character as Character);
      setMsg("⏹️ Geração cancelada.");
    } catch { setMsg("❌ não foi possível cancelar agora."); }
  }
  function editarBase(c: Character) {
    const prompt = (editBaseText[c.id] || "").trim();
    if (!prompt) { setMsg("❌ Escreva o que alterar na imagem-base."); return; }
    setEditBaseText((t) => ({ ...t, [c.id]: "" }));
    gerar(c, "edit-base", { prompt }, "✅ Imagem-base editada.");
  }
  // 🎭 Tirar fundo / ✨ Melhorar qualidade da BASE (síncrono, atualiza a base no lugar). Ideal
  // pra limpar uma foto de celular ANTES de gerar o model sheet (fundo bagunçado vaza cenário).
  const [enhBusy, setEnhBusy] = useState<number | null>(null);
  async function enhanceBase(c: Character, op: "remove_bg" | "upscale") {
    if (c.status || enhBusy) { setMsg("⏳ Já existe uma geração em andamento para este personagem."); return; }
    setEnhBusy(c.id); setMsg(op === "remove_bg" ? "🎭 Tirando o fundo da base…" : "✨ Melhorando a qualidade da base…");
    try {
      const r = await sfetch(`/api/characters/${c.id}/enhance-base`, { method: "POST", body: JSON.stringify({ op }) });
      const d = await r.json();
      if (d?.ok && d.character) { upsert(d.character as Character); setMsg(op === "remove_bg" ? "✅ Fundo removido da base." : "✅ Qualidade da base melhorada."); }
      else setMsg("❌ " + (d?.error || "não foi possível processar a base"));
    } catch { setMsg("❌ não foi possível processar agora."); }
    setEnhBusy(null);
  }
  // DETALHAR a ideia com o molde da categoria: "menina de 8 anos, cabelo cacheado" vira a descrição
  // densa (proporção infantil, ossatura, pele, roupa, mãos) que o prompt-base pede. O molde é o
  // mesmo texto que a extração aplica na foto — um jeito só de descrever gente e objeto.
  async function detalharComMolde() {
    const ideia = description.trim();
    if (!molde || !ideia) { setMsg("❌ Escreva a ideia e escolha o molde."); return; }
    setMoldeBusy(true); setMsg("🧬 Detalhando com o molde…");
    try {
      const r = await sfetch("/api/characters/persona-chat", {
        method: "POST",
        body: JSON.stringify({ persona: molde, message: `IDEIA: ${ideia}`, textModel: textModel || undefined }),
      });
      const d = await r.json();
      if (!r.ok || !d?.ok || !d?.text) { setMsg("❌ " + (d?.error || "o molde não respondeu")); return; }
      setDescription(String(d.text).trim());
      setMsg("✅ Descrição detalhada pelo molde. Leia e ajuste o que quiser antes de criar.");
    } catch { setMsg("❌ não foi possível detalhar agora."); }
    setMoldeBusy(false);
  }
  // EXTRAIR da foto JÁ ENVIADA: a IA (vision) olha a base e escreve o character lock + a bíblia.
  // NÃO gera model sheet — a prancha é outro botão. Antes escolher o arquivo disparava upload +
  // vision + as 3 pranchas de uma vez, e quem ainda ia recortar ou ajustar a foto já tinha gastado
  // as pranchas na versão errada (pedido do Luciano, 2026-07-26). Agora é: sobe → olha → extrai.
  async function extrairDaBase(c: Character) {
    if (c.status) { setMsg("⏳ Já existe uma geração em andamento para este personagem."); return; }
    setMsg("🧬 Lendo a foto e escrevendo a identidade…");
    try {
      const r = await sfetch(`/api/characters/${c.id}/extract`, {
        // Molde vazio = deixa a detecção decidir pelo que a vision viu. Escolhido = manda esse,
        // que é a saída pra quando a detecção erra a categoria (o sujeito ambíguo na foto).
        method: "POST", body: JSON.stringify({ lang: "pt-BR", textModel: c.text_model || undefined, molde: moldeExtrair[c.id] || undefined }),
      });
      const d = await r.json();
      if (!r.ok || !d?.ok) { setMsg("❌ " + (d?.error || d?.message || "não foi possível extrair da foto")); return; }
      upsert(d.character as Character);
      // Dizer QUAL molde entrou importa: é o que explica a descrição que apareceu no personagem —
      // e avisa quando nenhum casou (bicho, criatura), caso em que só a identidade foi escrita.
      const m = typeof d.molde === "string" ? d.molde.replace("Molde: ", "") : null;
      setMsg(m
        ? `✅ Identidade + descrição extraídas da foto (molde: ${m}). O model sheet sai no botão dele, quando você quiser.`
        : "✅ Identidade extraída da foto (lock + bíblia). Nenhum molde casou com este sujeito, então a descrição ficou como estava.");
    } catch { setMsg("❌ não foi possível extrair agora."); }
  }
  // NOVO personagem a partir de uma FOTO: cria a ficha e sobe a imagem como base. Só isso — nada de
  // IA aqui. A leitura da foto e o model sheet ficam nos botões do card, cada um uma decisão.
  async function criarComFoto(file: File) {
    if (busy) return;
    setBusy(true); setMsg("📤 Criando personagem e enviando a foto…");
    try {
      const r = await sfetch("/api/characters", { method: "POST", body: JSON.stringify({ name: name.trim(), style, image_model: imageModel || undefined, text_model: textModel || undefined }) });
      const d = await r.json();
      if (!r.ok || !d?.ok) { setMsg("❌ " + (d?.error || d?.message || "não foi possível criar o personagem")); setBusy(false); return; }
      const c = d.character as Character;
      upsert(c); setSelId(c.id); fechar(); setBusy(false);
      await uploadBase(c, file);
      setMsg("✅ Foto enviada como base. Ajuste a imagem se precisar e clique em 🧬 Extrair da foto quando estiver boa.");
    } catch { setMsg("❌ não foi possível agora."); setBusy(false); }
  }
  // Criar e gerar deixaram de ser o mesmo clique (2026-07-25, pedido do Luciano): criar só grava
  // a ficha; a base sai no botão do card. Cada geração é uma decisão sua, não um efeito colateral
  // de salvar — mesma razão que já separava o model sheet da base.

  const filtered = items.filter((c) => {
    const s = q.trim().toLowerCase();
    return !s || c.name.toLowerCase().includes(s) || (c.description || "").toLowerCase().includes(s);
  });
  // Paginação (client-side — o index já traz até 50). Coluna única + páginas de PER_PAGE.
  const PER_PAGE = 8;
  const [page, setPage] = useState(1);
  // Volta pra página 1 quando o filtro muda (senão pode ficar numa página vazia). Ajuste DURANTE
  // o render em vez de `useEffect(… , [q])`: é o padrão que o React recomenda para "corrigir
  // estado quando outro valor muda" — o efeito rodava um render tarde e o lint reprova setState
  // síncrono dentro dele.
  const [prevQ, setPrevQ] = useState(q);
  if (prevQ !== q) { setPrevQ(q); setPage(1); }
  const pageCount = Math.max(1, Math.ceil(filtered.length / PER_PAGE));
  const pageSafe = Math.min(page, pageCount);
  const paged = filtered.slice((pageSafe - 1) * PER_PAGE, pageSafe * PER_PAGE);
  // Trava GLOBAL de execução: enquanto QUALQUER coisa estiver processando — modal (busy),
  // enhance de base (enhBusy) ou qualquer personagem gerando (status setado) — todos os
  // botões de execução ficam travados. Evita duplo-clique e execuções concorrentes.
  const travado = busy || enhBusy !== null || items.some((c) => !!c.status);

  const inp = { background: "var(--bg2)", color: "var(--text)", border: "1px solid var(--line)", borderRadius: 10, padding: "10px 12px", fontSize: ".95rem", width: "100%" } as const;
  const chip = { fontSize: ".74rem", padding: "3px 9px", borderRadius: 999, background: "var(--bg2)", border: "1px solid var(--line)", color: "var(--muted)" } as const;
  const miniBtn = { flex: "none", padding: "5px 10px", fontSize: ".76rem" } as const;
  const statusLabel = (s?: string) => (s === "base" ? "Gerando base…" : s === "sheet" ? "Gerando model sheet…" : s === "edit" ? "Editando base…" : "");

  return (
    <>
      {dialogo}
      <div style={{ display: "flex", alignItems: "flex-start", justifyContent: "space-between", gap: "12px 20px", flexWrap: "wrap", marginBottom: 8 }}>
        <div style={{ maxWidth: 640, minWidth: 0 }}>
          <h1 className="h1">Personagens</h1>
          <p className="sub" style={{ marginBottom: 0 }}>Crie um personagem-base com todas as opções de estilo e gere o <strong>model sheet</strong> de referência — pra manter a integridade dele em todas as cenas.</p>
        </div>
        <button className="btn ok" style={{ flex: "0 0 auto", padding: "9px 16px" }} onClick={novo}>+ Novo personagem</button>
      </div>

      {open && (
        <div ref={editorRef} style={{ ...card, marginTop: 14, display: "flex", flexDirection: "column", gap: 10, borderColor: "rgba(34,197,94,.4)" }}>
          <strong style={{ fontSize: ".95rem", color: "var(--peach)" }}>{editId ? "Editar personagem" : "Novo personagem"}</strong>
          <input value={name} onChange={(e) => setName(e.target.value)} placeholder="Nome (ex: Burro Estiloso)" style={inp} />
          <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", gap: 8, flexWrap: "wrap" }}>
            <label className="txt" style={{ color: "var(--muted)", fontSize: ".78rem" }}>Descrição visual</label>
            <div style={{ display: "flex", gap: 6, flexWrap: "wrap", alignItems: "center" }}>
              {/* MOLDE: engorda uma ideia curta até a descrição densa que o prompt-base pede.
                  Cada categoria tem os erros dela — por isso não é um molde só pra tudo. */}
              {moldes.length > 0 && (
                <>
                  <select value={molde} onChange={(e) => setMolde(e.target.value)} title="Molde da categoria — o que cada tipo de sujeito exige na descrição"
                    style={{ background: "var(--bg2)", color: "var(--text)", border: "1px solid var(--line2)", borderRadius: 8, padding: "5px 9px", fontSize: ".76rem", fontFamily: "inherit" }}>
                    <option value="">molde…</option>
                    {moldes.map((m) => <option key={m.id} value={m.title}>{m.title.replace("Molde: ", "")}</option>)}
                  </select>
                  <button className="btn edit" style={{ flex: "none", padding: "5px 11px", fontSize: ".76rem" }} disabled={moldeBusy || !molde || !description.trim()}
                    onClick={() => void detalharComMolde()} title="Escreva a ideia em uma linha e deixe o molde detalhar (anatomia, idade, pele, roupa, mãos)">
                    {moldeBusy ? "🧬 detalhando…" : "🧬 Detalhar"}
                  </button>
                </>
              )}
              <button className="btn edit" style={{ flex: "none", padding: "5px 11px", fontSize: ".76rem" }} onClick={() => setPromptPickerOpen(true)} title="Carregar um prompt salvo da aba Prompts">📂 Carregar prompt</button>
            </div>
          </div>
          <textarea value={description} onChange={(e) => setDescription(e.target.value)} placeholder="Descrição visual: traços, cores, roupa, acessórios… (ex: burro cinza antropomórfico, óculos escuros, shorts e chinelos pretos, expressão confiante)" style={{ ...inp, minHeight: 120, lineHeight: 1.5, resize: "vertical" }} />
          <div style={{ display: "flex", gap: 16, flexWrap: "wrap" }}>
            <div style={{ display: "flex", flexDirection: "column", gap: 5 }}>
              <label className="txt" style={{ color: "var(--muted)", fontSize: ".78rem" }}>Estilo</label>
              <select value={style} onChange={(e) => setStyle(e.target.value)} style={{ ...inp, width: "auto", padding: "8px 12px" }}>
                {STYLES.map(([v, lbl]) => <option key={v} value={v}>{lbl}</option>)}
              </select>
            </div>
            {imageModels.length > 1 && (
              <div style={{ display: "flex", flexDirection: "column", gap: 5 }}>
                <label className="txt" style={{ color: "var(--muted)", fontSize: ".78rem" }}>Modelo da base</label>
                <select value={imageModel} onChange={(e) => setImageModel(e.target.value)} style={{ ...inp, width: "auto", padding: "8px 12px" }} title="Modelo de IA que gera a imagem-base — cada um tem qualidade e custo diferentes. 🔋 = capacidade própria, não afetada quando a geração externa fica indisponível">
                  {imageModels.map((m) => <option key={m.slug} value={m.slug}>{m.own_account ? "🔋 " : ""}{m.real_name || m.display_name}{m.cost_credits != null ? ` · ${m.cost_credits} créd` : ""}</option>)}
                </select>
              </div>
            )}
            <TextModelSelect value={textModel} onChange={setTextModel} label="Texto do model sheet"
              title="Modelo de IA que escreve a bíblia do personagem (lock, traços, expressões) ao gerar o model sheet" />
          </div>
          <div style={{ display: "flex", gap: 8, flexWrap: "wrap" }}>
            {/* CRIAR ≠ GERAR: o botão principal só cria a ficha. A base sai no card, no botão
                "Gerar base", quando VOCÊ mandar — encadear gasto na criação tirava de você a
                decisão de olhar a descrição antes de pagar pela imagem. */}
            <button className="btn ok" style={{ flex: "none", padding: "9px 16px", background: editId ? undefined : "linear-gradient(135deg,#22c55e,#16a34a)" }} disabled={travado || (!editId && !description.trim())} onClick={salvar} title={editId ? "Salva as alterações" : "Cria o personagem — sem gerar imagem. A base você gera depois, no botão do card."}>{busy ? "Salvando…" : (editId ? "💾 Salvar" : "✨ Criar personagem")}</button>
            {!editId && (
              <label className="btn ok" style={{ flex: "none", padding: "9px 16px", cursor: travado ? "not-allowed" : "pointer", opacity: travado ? 0.5 : 1 }} title="Cria o personagem com a foto como base — sem gastar cota. A leitura da identidade (🧬 Extrair da foto) e o model sheet ficam nos botões do card, quando a imagem estiver do jeito que você quer">📤 Criar com uma foto<input type="file" accept="image/*" disabled={travado} style={{ display: "none" }} onChange={(e) => { const f = e.target.files?.[0]; e.currentTarget.value = ""; if (f) criarComFoto(f); }} /></label>
            )}
            <button className="btn edit" style={{ flex: "none", padding: "9px 14px" }} onClick={fechar}>Cancelar</button>
          </div>
        </div>
      )}

      {items.length > 0 && (
        <div style={{ marginTop: 16 }}>
          <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="🔎 Buscar por nome ou descrição…" style={{ ...inp, maxWidth: 420 }} />
        </div>
      )}

      {loading ? (
        <div className="empty" style={{ marginTop: 14 }}>Carregando…</div>
      ) : items.length === 0 ? (
        <div className="empty" style={{ marginTop: 14 }}>Nenhum personagem ainda. Clique em <strong>+ Novo personagem</strong> pra criar a primeira base reutilizável. ✨</div>
      ) : (
        <div style={{ display: "flex", flexDirection: "column", gap: 12, marginTop: 14 }}>
          {paged.map((c) => {
            const gerando = !!c.status;
            const aberto = selId === c.id;
            // Pranchas do model sheet v3 (galeria). Fallback: personagens antigos só têm sheet_url.
            // Guarda o índice ORIGINAL (idx) no array c.sheets — é o que o backend usa pra remover —
            // e ordena pela ordem canônica (PANEL_ORDER) só pra exibir.
            const rawSheets: Sheet[] = c.sheets && c.sheets.length ? c.sheets : c.sheet_url ? [{ kind: "turnaround", url: c.sheet_url }] : [];
            const sheets = rawSheets
              .map((sh, idx) => ({ ...sh, idx }))
              .sort((a, b) => {
                const ia = PANEL_ORDER.indexOf(a.kind), ib = PANEL_ORDER.indexOf(b.kind);
                return (ia < 0 ? 99 : ia) - (ib < 0 ? 99 : ib);
              });
            const hasSheet = rawSheets.length > 0;
            return (
              <div key={c.id} className="card" style={{ display: "flex", flexDirection: "column", gap: 10, padding: 14 }}>
                <div style={{ display: "flex", gap: 12 }}>
                  <div style={{ width: 84, height: 112, borderRadius: 8, overflow: "hidden", flex: "none", background: "#0006", border: "1px solid var(--line)", display: "flex", alignItems: "center", justifyContent: "center" }}>
                    {c.base_url ? <img key={c.base_url} src={c.base_url} alt={c.name} title="Ver em tela cheia" onClick={() => setViewer({ url: c.base_url!, title: `${c.name} — base` })} style={{ width: "100%", height: "100%", objectFit: "cover", cursor: "zoom-in" }} /> : <span style={{ fontSize: "1.6rem", opacity: .5 }}>🎭</span>}
                  </div>
                  <div style={{ flex: 1, minWidth: 0, display: "flex", flexDirection: "column", gap: 6 }}>
                    <strong style={{ fontSize: ".98rem", overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap" }}>{c.name || "(sem nome)"}</strong>
                    <span style={chip}>{styleLabel(c.style)}</span>
                    {gerando ? (
                      <span className="txt" style={{ color: "var(--peach)", fontSize: ".8rem" }}>⏳ {statusLabel(c.status)}</span>
                    ) : (
                      <span className="txt" style={{ color: "var(--muted)", fontSize: ".78rem", lineHeight: 1.4, overflow: "hidden", display: "-webkit-box", WebkitLineClamp: 3, WebkitBoxOrient: "vertical" }}>{(c.description || "").startsWith("MANDATORY") ? "Personagem extraído de imagem — identidade travada (veja Detalhes)." : c.description}</span>
                    )}
                  </div>
                </div>

                {c.base_url && (
                  <div style={{ marginBottom: 8 }}>
                    {/* Regerar a base não apaga mais a anterior. */}
                    <HistoricoDeVersoes tipo="character" id={c.id} campo="base_url"
                      onRestaurado={(a: Record<string, unknown>) => upsert(a as unknown as Character)} />
                  </div>
                )}
                <div style={{ display: "flex", gap: 6, flexWrap: "wrap" }}>
                  <button className="btn ok" style={{ flex: "none", padding: "6px 11px", fontSize: ".78rem" }} disabled={travado} onClick={() => gerarBase(c)}>{c.base_url ? "↻ Regerar base" : "🖼️ Gerar base"}</button>
                  {/* Round-trip: subir uma base gerada FORA (ferramenta sem API) usando o prompt copiado em 📝 Prompts */}
                  <label className="btn edit" style={{ flex: "none", padding: "6px 11px", fontSize: ".78rem", cursor: travado ? "not-allowed" : "pointer", opacity: travado ? 0.5 : 1 }} title="Já gerou a imagem numa ferramenta externa (sem API)? Suba ela aqui como base — sem gastar cota">📤 Subir base<input type="file" accept="image/*" disabled={travado} style={{ display: "none" }} onChange={(e) => { const f = e.target.files?.[0]; e.currentTarget.value = ""; if (f) uploadBase(c, f); }} /></label>
                  {/* Ler a FOTO que já está aqui: identidade (lock + bíblia), sem model sheet. Fica
                      logo depois do "Subir base" porque é a ordem do trabalho — sobe, olha, ajusta
                      se precisar, e só então paga a leitura. */}
                  {c.base_url && (
                    <span style={{ display: "inline-flex", alignItems: "center", gap: 4 }}>
                      <button className="btn ok" style={{ flex: "none", padding: "6px 11px", fontSize: ".78rem" }} disabled={travado} title="A IA olha a foto e escreve o character lock, a bíblia e a descrição do personagem. Não gera pranchas — o model sheet continua no botão dele." onClick={() => void extrairDaBase(c)}>{c.lock ? "↻ Reextrair da foto" : "🧬 Extrair da foto"}</button>
                      {/* Automático acerta o caso claro (um homem, um carro) e erra o ambíguo.
                          Aqui você manda o molde direto, sem depender da heurística. */}
                      {moldes.length > 0 && (
                        <select value={moldeExtrair[c.id] ?? ""} onChange={(e) => setMoldeExtrair((p) => ({ ...p, [c.id]: e.target.value }))}
                          title="Molde usado na extração — automático detecta pela foto; escolha um pra mandar o tipo certo quando a detecção errar"
                          style={{ background: "var(--bg2)", color: "var(--text)", border: "1px solid var(--line2)", borderRadius: 8, padding: "5px 7px", fontSize: ".72rem", fontFamily: "inherit" }}>
                          <option value="">molde: auto</option>
                          {moldes.map((m) => <option key={m.id} value={m.title}>{m.title.replace("Molde: ", "")}</option>)}
                        </select>
                      )}
                    </span>
                  )}
                  {temGeradorMalha && (
                    <button className="btn ok" style={{ flex: "none", padding: "6px 11px", fontSize: ".78rem" }}
                      /* "gerando" vem do BANCO (`mesh_status`) além do flag local: a fila corre no
                         servidor e o botão tem que continuar travado após um F5 ou noutra aba. */
                      disabled={travado || gerandoMalhaId === c.id || c.mesh_status === "gerando" || !c.base_url}
                      title={!c.base_url
                        ? "Gere ou suba a imagem-base antes — a malha nasce dela."
                        /* White-label (#6): a dica fala das ETAPAS e do LUGAR, nunca do nome das
                           ferramentas por baixo (o pipeline real está no comentário acima). */
                        : "Gera a malha 3D no seu Estúdio: ficha de referência → geometria → limpeza → juiz de visão. Leva alguns minutos e não gasta crédito."}
                      onClick={() => gerarMalha(c)}>
                      {gerandoMalhaId === c.id || c.mesh_status === "gerando"
                        ? "🧊 Gerando malha…"
                        : c.mesh_url ? "🧊 Regerar malha" : "🧊 Gerar malha"}
                    </button>
                  )}
                  <label className="btn edit" style={{ flex: "none", padding: "6px 11px", fontSize: ".78rem", cursor: travado ? "not-allowed" : "pointer", opacity: travado ? 0.5 : 1 }} title="Malha 3D (.glb) gerada fora — com ela, cada plano da decupagem vira um render no ângulo exato pedido">🧊 {c.mesh_url ? "Trocar malha" : "Subir malha"}<input type="file" accept=".glb,model/gltf-binary" disabled={travado} style={{ display: "none" }} onChange={(e) => { const f = e.target.files?.[0]; e.currentTarget.value = ""; if (f) uploadMalha(c, f); }} /></label>
                  {/* 🛠 preparar a base (sempre visível quando há base) — limpar fundo antes do model sheet */}
                  {c.base_url && <button className="btn edit" style={{ flex: "none", padding: "6px 11px", fontSize: ".78rem" }} disabled={travado} title="Recorta a pessoa e troca por fundo de estúdio liso (evita cenário vazando no model sheet)" onClick={() => void enhanceBase(c, "remove_bg")}>{enhBusy === c.id ? "⏳ processando…" : "🎭 Tirar fundo"}</button>}
                  {c.base_url && <button className="btn edit" style={{ flex: "none", padding: "6px 11px", fontSize: ".78rem" }} disabled={travado} title="Aumenta a resolução e a nitidez da imagem-base" onClick={() => void enhanceBase(c, "upscale")}>{enhBusy === c.id ? "⏳ processando…" : "✨ Melhorar qualidade"}</button>}
                  <button className="btn ok" style={{ flex: "none", padding: "6px 11px", fontSize: ".78rem" }} disabled={travado || !c.base_url} title={!c.base_url ? "Gere a imagem-base primeiro" : undefined} onClick={() => gerarSheet(c)}>{hasSheet ? "↻ Regerar model sheet" : "📋 Gerar model sheet"}</button>
                  <button className="btn edit" style={{ flex: "none", padding: "6px 11px", fontSize: ".78rem" }} disabled={travado || !c.base_url} title={!c.base_url ? "Gere a imagem-base primeiro" : "Prancha extra (opcional): 15 enquadramentos cinematográficos do personagem (close-up, over shoulder, contra-plongée, hero shot…)"} onClick={() => regenerarPrancha(c, "shots")}>{sheets.some((s) => s.kind === "shots") ? "↻ Regerar planos de câmera" : "🎬 Planos de câmera"}</button>
                  <button className="btn edit" style={{ flex: "none", padding: "6px 11px", fontSize: ".78rem" }} disabled={travado || !c.base_url} title={!c.base_url ? "Gere a imagem-base primeiro" : "Prancha extra (opcional): o mesmo personagem sob 4 setups de luz (dia suave, tungstênio quente, noite azul, luz lateral dramática) — ajuda a identidade a se segurar em cenas com luzes diferentes"} onClick={() => regenerarPrancha(c, "lighting")}>{sheets.some((s) => s.kind === "lighting") ? "↻ Regerar iluminação" : "💡 Testes de iluminação"}</button>
                  <button className="btn edit" style={{ flex: "none", padding: "6px 11px", fontSize: ".78rem" }} onClick={() => setSelId(aberto ? null : c.id)}>{aberto ? "Recolher" : "🔍 Detalhes"}</button>
                  <button className="btn edit" style={{ flex: "none", padding: "6px 11px", fontSize: ".78rem" }} title="Ver e copiar os prompts (base + model sheet) pra usar numa ferramenta externa sem API" onClick={() => togglePrompts(c)}>{charPromptsOpen[c.id] ? "📝 Ocultar prompts" : "📝 Prompts"}</button>
                  <button className="btn edit" style={{ flex: "none", padding: "6px 11px", fontSize: ".78rem" }} onClick={() => editarCampos(c)}>✏️ Editar</button>
                  {gerando && (
                    <button className="btn no" style={{ flex: "none", padding: "6px 11px", fontSize: ".78rem" }} title="Destravar: cancela uma geração presa/órfã" onClick={() => cancelarGeracao(c)}>⏹️ Cancelar</button>
                  )}
                  {/* Excluir NÃO é execução: não gasta cota nem concorre com uma geração de OUTRO
                      personagem. Por isso olha só o próprio card (`gerando`) e não a trava global —
                      com a global, um personagem preso deixava o 🗑️ de todos os outros morto. */}
                  <button className="btn no" style={{ flex: "none", padding: "6px 11px", fontSize: ".78rem" }} disabled={gerando} title={gerando ? "Aguarde a geração deste personagem terminar (ou ⏹️ Cancelar)" : "Excluir personagem"} onClick={() => excluir(c)}>🗑️</button>
                </div>

                {charPromptsOpen[c.id] && (
                  <div style={{ display: "flex", flexDirection: "column", gap: 10, borderTop: "1px solid var(--line)", paddingTop: 12 }}>
                    <span className="txt" style={{ color: "var(--muted)", fontSize: ".74rem", textTransform: "uppercase", letterSpacing: ".04em" }}>📝 Prompts (copiar pra usar numa ferramenta externa sem API)</span>
                    {charPromptsLoading[c.id] ? (
                      <span className="txt" style={{ color: "var(--muted)", fontSize: ".8rem" }}>⏳ montando os prompts…</span>
                    ) : (() => {
                      const pd = charPromptsData[c.id];
                      if (!pd) return null;
                      return (
                        <>
                          {pd.base ? (
                            <div style={{ display: "flex", flexDirection: "column", gap: 4 }}>
                              <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", gap: 8 }}>
                                <span className="txt" style={{ fontSize: ".76rem", color: "var(--muted)" }}>🖼️ Imagem-base</span>
                                <button className="btn edit" style={miniBtn} onClick={() => copiarTexto(pd.base!)}>📝 Copiar</button>
                              </div>
                              <pre style={{ whiteSpace: "pre-wrap", fontSize: ".72rem", color: "var(--muted)", background: "var(--bg2)", border: "1px solid var(--line)", borderRadius: 8, padding: 10, fontFamily: "ui-monospace, SFMono-Regular, Menlo, monospace" }}>{pd.base}</pre>
                            </div>
                          ) : (
                            <span className="txt" style={{ color: "var(--muted)", fontSize: ".78rem" }}>Preencha a descrição do personagem pra ver o prompt da base.</span>
                          )}
                          {pd.sheets.length === 0 && (
                            <span className="txt" style={{ color: "var(--muted)", fontSize: ".78rem" }}>Gere (ou já tenha) a imagem-base pra ver os prompts das pranchas do model sheet.</span>
                          )}
                          {pd.sheets.map((panel) => (
                            <div key={panel.kind} style={{ display: "flex", flexDirection: "column", gap: 6 }}>
                              <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", gap: 8 }}>
                                <span className="txt" style={{ fontSize: ".76rem", color: "var(--muted)" }}>{sheetLabel(panel.kind)}{panel.subtitle ? ` — ${panel.subtitle}` : ""}</span>
                                <button className="btn edit" style={miniBtn} onClick={() => copiarTexto(panel.shots.map((s) => `[${s.label}]\n${s.prompt}`).join("\n\n"))}>📝 Copiar todos</button>
                              </div>
                              <div style={{ display: "flex", flexDirection: "column", gap: 6 }}>
                                {panel.shots.map((s, i) => (
                                  <div key={i} style={{ display: "flex", justifyContent: "space-between", alignItems: "flex-start", gap: 8, background: "var(--bg2)", border: "1px solid var(--line)", borderRadius: 8, padding: 8 }}>
                                    <div style={{ minWidth: 0 }}>
                                      <div className="txt" style={{ fontSize: ".7rem", color: "var(--peach)", marginBottom: 2 }}>{s.label}</div>
                                      <div className="txt" style={{ fontSize: ".72rem", color: "var(--muted)" }}>{s.prompt}</div>
                                    </div>
                                    <button className="btn edit" style={{ ...miniBtn, flex: "none" }} onClick={() => copiarTexto(s.prompt)}>📝</button>
                                  </div>
                                ))}
                              </div>
                            </div>
                          ))}
                        </>
                      );
                    })()}
                  </div>
                )}

                {aberto && (
                  <div style={{ display: "flex", flexDirection: "column", gap: 12, borderTop: "1px solid var(--line)", paddingTop: 12 }}>
                    {/* Round-trip: adicionar/substituir uma prancha gerada FORA do Reachyn (ferramenta
                        sem API) — cobre o kind que ainda não existe ou substitui um já gerado. */}
                    {c.base_url && (
                      <div style={{ display: "flex", gap: 6, flexWrap: "wrap", alignItems: "center" }}>
                        <span className="txt" style={{ color: "var(--muted)", fontSize: ".76rem" }}>📤 Adicionar prancha externa</span>
                        <select value={uploadKind[c.id] || "angles"} onChange={(e) => setUploadKind((u) => ({ ...u, [c.id]: e.target.value }))} style={{ ...inp, width: "auto", padding: "6px 10px", fontSize: ".78rem" }}>
                          {UPLOAD_KINDS.map(([k, l]) => <option key={k} value={k}>{l}</option>)}
                        </select>
                        <label className="btn ok" style={{ ...miniBtn, cursor: travado ? "not-allowed" : "pointer", opacity: travado ? 0.5 : 1 }} title="1 imagem = prancha pronta · várias (ex.: 10 do turnaround) = monta a folha automaticamente">📤 Subir<input type="file" accept="image/*" multiple disabled={travado} style={{ display: "none" }} onChange={(e) => { const fs = e.target.files; e.currentTarget.value = ""; if (fs?.length) uploadPanel(c, uploadKind[c.id] || "angles", fs); }} /></label>
                      </div>
                    )}

                    {/* MODEL SHEET v3 — galeria de pranchas (ângulos + cabeça + poses + paleta). Cada
                        prancha pode ser regenerada, editada (ajuste livre i2i) e removida. */}
                    {sheets.length > 0 && (
                      <div style={{ display: "flex", flexDirection: "column", gap: 10 }}>
                        <span className="txt" style={{ color: "var(--muted)", fontSize: ".74rem", textTransform: "uppercase", letterSpacing: ".04em" }}>📋 Model sheet{gerando && c.status === "sheet" ? " · gerando pranchas…" : ""}</span>
                        {sheets.map((sh) => {
                          const isOutfit = sh.kind === "outfit";
                          const label = isOutfit ? `👕 ${sh.label || "Figurino"}` : sheetLabel(sh.kind);
                          const title = `${c.name} — ${label.replace(/^\S+\s/, "")}`;
                          const key = `${c.id}:${sh.id || sh.kind}`;
                          const canRegen = REGENERABLE.has(sh.kind);
                          const editing = panelEdit === key;
                          return (
                            <div key={sh.url} style={{ display: "flex", flexDirection: "column", gap: 6 }}>
                              <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", gap: 8, flexWrap: "wrap" }}>
                                <span className="txt" style={{ color: "var(--muted)", fontSize: ".76rem" }}>{label}</span>
                                <div style={{ display: "flex", gap: 6, flexWrap: "wrap" }}>
                                  <button className="btn edit" style={miniBtn} onClick={() => setViewer({ url: sh.url, title })}>🔍 Tela cheia</button>
                                  <button className="btn edit" style={miniBtn} onClick={() => baixar(sh.url, `${slug(c.name)}-${sh.id || sh.kind}.jpg`)}>⬇ Baixar</button>
                                  <button className="btn edit" style={miniBtn} onClick={() => copiarImagem(sh.url)}>📋 Copiar</button>
                                  {canRegen && (
                                    <label className="btn edit" style={{ ...miniBtn, cursor: travado ? "not-allowed" : "pointer", opacity: travado ? 0.5 : 1 }} title="1 imagem = prancha pronta · várias vistas (ex.: 10 do turnaround) = monta a folha no template — sem gastar cota">📤 Substituir<input type="file" accept="image/*" multiple disabled={travado} style={{ display: "none" }} onChange={(e) => { const fs = e.target.files; e.currentTarget.value = ""; if (fs?.length) uploadPanel(c, sh.kind, fs); }} /></label>
                                  )}
                                  {canRegen && <button className="btn ok" style={miniBtn} disabled={travado} title="Gerar esta prancha de novo" onClick={() => regenerarPrancha(c, sh.kind)}>↻ Regerar</button>}
                                  {canRegen && <button className="btn ok" style={miniBtn} disabled={travado} title="Editar esta prancha com uma instrução" onClick={() => setPanelEdit(editing ? null : key)}>✏️ Editar</button>}
                                  {isOutfit && <button className="btn ok" style={miniBtn} disabled={travado} title="Gerar este figurino de novo" onClick={() => gerarFigurino(c, sh.label || "", sh.id)}>↻ Regerar</button>}
                                  {isOutfit && <button className="btn ok" style={miniBtn} disabled={travado} title="Editar a descrição deste figurino" onClick={() => editarFigurino(c, sh)}>✏️ Editar</button>}
                                  <button className="btn no" style={miniBtn} disabled={gerando} title="Remover esta prancha" onClick={() => removerPrancha(c, sh.idx)}>🗑️ Remover</button>
                                </div>
                              </div>
                              {editing && (
                                <div style={{ display: "flex", gap: 6, flexWrap: "wrap", alignItems: "flex-start" }}>
                                  <textarea value={panelTweak[key] || ""} onChange={(e) => setPanelTweak((t) => ({ ...t, [key]: e.target.value }))} placeholder="ajuste desta prancha (ex: expressões mais alegres; mais poses de ação; incluir vista sentada)" style={{ ...inp, minHeight: 52, resize: "vertical", flex: 1, minWidth: 220 }} />
                                  <button className="btn ok" style={{ ...miniBtn, alignSelf: "flex-start" }} disabled={travado} onClick={() => { regenerarPrancha(c, sh.kind, panelTweak[key]); setPanelTweak((t) => ({ ...t, [key]: "" })); }}>Aplicar ajuste</button>
                                </div>
                              )}
                              <img key={sh.url} src={sh.url} alt={title} title="Ver em tela cheia" onClick={() => setViewer({ url: sh.url, title })} style={{ width: "100%", borderRadius: 8, border: "1px solid var(--line)", cursor: "zoom-in" }} />
                            </div>
                          );
                        })}
                      </div>
                    )}

                    {/* FIGURINO MANUAL — descreve uma roupa e a IA veste o personagem (peças separadas +
                        personagem vestido). Vários figurinos coexistem; aparecem na galeria acima. */}
                    {c.base_url && (
                      <div style={{ display: "flex", flexDirection: "column", gap: 6 }}>
                        <span className="txt" style={{ color: "var(--muted)", fontSize: ".74rem", textTransform: "uppercase", letterSpacing: ".04em" }}>👕 Adicionar figurino {outfitReplaceId[c.id] ? "· editando" : ""}</span>
                        <div style={{ display: "flex", gap: 6, flexWrap: "wrap", alignItems: "flex-start" }}>
                          <textarea value={outfitText[c.id] || ""} onChange={(e) => setOutfitText((t) => ({ ...t, [c.id]: e.target.value }))} placeholder="descreva a roupa: jaqueta de couro preta, camiseta branca, calça jeans, tênis… (a IA mantém o rosto/corpo, só troca a roupa)" style={{ ...inp, minHeight: 56, resize: "vertical", flex: 1, minWidth: 240 }} />
                          <button className="btn ok" style={{ ...miniBtn, alignSelf: "flex-start" }} disabled={travado || !(outfitText[c.id] || "").trim()} onClick={() => enviarFigurino(c)}>{outfitReplaceId[c.id] ? "💾 Salvar figurino" : "👕 Adicionar figurino"}</button>
                          {outfitReplaceId[c.id] && <button className="btn edit" style={{ ...miniBtn, alignSelf: "flex-start" }} onClick={() => { setOutfitReplaceId((t) => ({ ...t, [c.id]: "" })); setOutfitText((t) => ({ ...t, [c.id]: "" })); }}>Cancelar</button>}
                        </div>
                      </div>
                    )}

                    {/* 🧬 FICHA metodológica (F1): desejo/conflito/arco/arquétipo + o Arquiteto (mmx local) */}
                    <details>
                      <summary className="txt" style={{ color: "var(--muted)", fontSize: ".74rem", cursor: "pointer", textTransform: "uppercase", letterSpacing: ".04em" }}>
                        🧬 Ficha do personagem{c.archetype ? ` · ${c.archetype}` : ""}
                      </summary>
                      <div style={{ marginTop: 8 }}>
                        <FichaPersonagem char={c} onSaved={(cc) => upsert(cc as Character)} />
                      </div>
                    </details>

                    {/* BÍBLIA: paleta + traços + acessórios + expressões */}
                    {c.bible && (c.bible.palette?.length || c.bible.traits?.length || c.bible.accessories?.length || c.bible.expressions?.length) ? (
                      <div style={{ display: "flex", flexDirection: "column", gap: 8 }}>
                        <span className="txt" style={{ color: "var(--muted)", fontSize: ".74rem", textTransform: "uppercase", letterSpacing: ".04em" }}>📖 Bíblia do personagem</span>
                        {!!c.bible.palette?.length && (
                          <div style={{ display: "flex", gap: 8, flexWrap: "wrap" }}>
                            {c.bible.palette.map((sw, k) => (
                              <div key={k} title={sw.label} style={{ display: "flex", flexDirection: "column", alignItems: "center", gap: 3, width: 56 }}>
                                <span style={{ width: 40, height: 40, borderRadius: 8, background: sw.hex, border: "1px solid var(--line)", display: "block" }} />
                                <span className="txt" style={{ fontSize: ".62rem", color: "var(--muted)", textAlign: "center", lineHeight: 1.1 }}>{sw.label}</span>
                              </div>
                            ))}
                          </div>
                        )}
                        {bibleList("Traços", c.bible.traits, chip)}
                        {bibleList("Acessórios", c.bible.accessories, chip)}
                        {bibleList("Expressões", c.bible.expressions, chip)}
                      </div>
                    ) : null}

                    {/* CHARACTER LOCK gerado */}
                    {c.lock && (
                      <details>
                        <summary className="txt" style={{ color: "var(--muted)", fontSize: ".74rem", cursor: "pointer", textTransform: "uppercase", letterSpacing: ".04em" }}>🔒 Character lock</summary>
                        <div style={{ display: "flex", justifyContent: "flex-end", marginTop: 6 }}>
                          <button className="btn edit" style={miniBtn} onClick={() => copiarTexto(c.lock!)}>📝 Copiar</button>
                        </div>
                        <pre style={{ whiteSpace: "pre-wrap", fontSize: ".74rem", color: "var(--muted)", background: "var(--bg2)", border: "1px solid var(--line)", borderRadius: 8, padding: 10, marginTop: 6, fontFamily: "ui-monospace, SFMono-Regular, Menlo, monospace" }}>{c.lock}</pre>
                      </details>
                    )}

                    {/* Editar a imagem-base (i2i in place) */}
                    {c.base_url && (
                      <div style={{ display: "flex", flexDirection: "column", gap: 6 }}>
                        <span className="txt" style={{ color: "var(--muted)", fontSize: ".74rem", textTransform: "uppercase", letterSpacing: ".04em" }}>✏️ Ajustar a imagem-base (i2i)</span>
                        <textarea value={editBaseText[c.id] || ""} onChange={(e) => setEditBaseText((t) => ({ ...t, [c.id]: e.target.value }))} placeholder="o que alterar (ex: óculos um pouco maiores; pose de braços cruzados)" style={{ ...inp, minHeight: 56, resize: "vertical" }} />
                        <button className="btn ok" style={{ flex: "none", padding: "6px 12px", fontSize: ".78rem", alignSelf: "flex-start" }} disabled={travado || !(editBaseText[c.id] || "").trim()} onClick={() => editarBase(c)}>✏️ Editar base</button>
                      </div>
                    )}
                  </div>
                )}
              </div>
            );
          })}
          {filtered.length === 0 && <div className="empty">Nenhum personagem encontrado pra “{q}”.</div>}
          {/* Paginação — só aparece quando há mais de uma página */}
          {pageCount > 1 && (
            <div style={{ display: "flex", alignItems: "center", justifyContent: "center", gap: 10, marginTop: 6 }}>
              <button className="btn edit" style={{ flex: "none", padding: "6px 12px", fontSize: ".82rem" }} disabled={pageSafe <= 1} onClick={() => setPage((p) => Math.max(1, p - 1))}>← Anterior</button>
              <span className="txt" style={{ color: "var(--muted)", fontSize: ".85rem" }}>Página {pageSafe} de {pageCount} · {filtered.length} personagens</span>
              <button className="btn edit" style={{ flex: "none", padding: "6px 12px", fontSize: ".82rem" }} disabled={pageSafe >= pageCount} onClick={() => setPage((p) => Math.min(pageCount, p + 1))}>Próxima →</button>
            </div>
          )}
        </div>
      )}

      {promptPickerOpen && (
        <PromptPicker
          onPick={(content) => { setDescription(content); setPromptPickerOpen(false); setMsg("✅ Prompt carregado na descrição."); }}
          onClose={() => setPromptPickerOpen(false)}
        />
      )}

      {viewer && (
        <div onClick={() => setViewer(null)} style={{ position: "fixed", inset: 0, zIndex: 1000, background: "rgba(0,0,0,.92)", display: "flex", flexDirection: "column" }}>
          <div onClick={(e) => e.stopPropagation()} style={{ display: "flex", alignItems: "center", gap: 8, padding: "12px 16px", flexWrap: "wrap" }}>
            <strong style={{ color: "#fff", flex: 1, minWidth: 120, fontSize: ".95rem", overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap" }}>{viewer.title}</strong>
            <button className="btn ok" style={miniBtn} onClick={() => baixar(viewer.url, `${slug(viewer.title)}.jpg`)}>⬇ Baixar</button>
            <button className="btn edit" style={miniBtn} onClick={() => copiarImagem(viewer.url)}>📋 Copiar</button>
            <a className="btn edit" style={{ ...miniBtn, textDecoration: "none" }} href={viewer.url} target="_blank" rel="noreferrer">↗ Abrir</a>
            <button className="btn no" style={miniBtn} onClick={() => setViewer(null)}>✕ Fechar</button>
          </div>
          <div onClick={() => setViewer(null)} style={{ flex: 1, display: "flex", alignItems: "center", justifyContent: "center", padding: "0 16px 16px", overflow: "auto" }}>
            <img src={viewer.url} alt={viewer.title} onClick={(e) => e.stopPropagation()} style={{ maxWidth: "100%", maxHeight: "100%", objectFit: "contain", borderRadius: 8 }} />
          </div>
        </div>
      )}

      {/* Os CENÁRIOS saíram daqui em 2026-07-25: existiam nesta tela E na aba Cenários, com
          criação nos dois lugares. Duas portas pro mesmo dado é onde o usuário cria metade dos
          ambientes num lugar e metade no outro. A aba Cenários é a dona — e o Roteiro cria
          direto de lá quando a cena precisa de um lugar novo. */}

      {msg && <p className="txt" style={{ marginTop: 16, fontSize: "1rem" }}>{msg}</p>}
    </>
  );
}

function bibleList(label: string, vals: string[] | undefined, chip: React.CSSProperties) {
  if (!vals?.length) return null;
  return (
    <div style={{ display: "flex", gap: 6, flexWrap: "wrap", alignItems: "center" }}>
      <span className="txt" style={{ fontSize: ".7rem", color: "var(--muted)", minWidth: 72 }}>{label}:</span>
      {vals.map((v, k) => <span key={k} style={chip}>{v}</span>)}
    </div>
  );
}

// slug pra nome de arquivo (download): tira acentos/símbolos → kebab-case.
const slug = (s: string) => (s || "personagem").toLowerCase().normalize("NFD").replace(/\p{Diacritic}/gu, "").replace(/[^a-z0-9]+/g, "-").replace(/^-+|-+$/g, "") || "personagem";

const card = { background: "var(--panel)", border: "1px solid var(--line)", borderRadius: 14, padding: 16 } as const;
