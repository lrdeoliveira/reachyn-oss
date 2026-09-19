"use client";

import { sfetch } from "@/lib/api";
// Só o nosso storage carrega; o resto (fal.media expirado, storage antigo) costuma estar morto.
// Marcamos esses como "indisponível" pra orientar a limpeza, mas mantemos o card com o excluir.
import { isOwnMedia } from "@/lib/media";
import { SkeletonCards } from "@/components/ui/Spinner";
import { useToast } from "@/components/ui/Toast";
// 🩹 CONSERTO PONTUAL (inpaint) — a modal existia desde a fusão FoxAssets (2026-08-01) com o
// endpoint pronto e gated, mas NENHUMA tela a renderizava: feature paga, inalcançável pelo
// cliente. A galeria é o lugar natural (é onde a imagem "quase boa" é vista).
import { InpaintModal } from "@/components/InpaintModal";
// ✨/🎞️ Mesma história do inpaint: endpoints prontos e gated desde a fusão FoxAssets, sem tela
// nenhuma até a auditoria de 2026-08-07. Refino = imagem; quadro-para-base = vídeo.
import { RefinarModal } from "@/components/galeria/RefinarModal";
import { QuadroParaBaseModal } from "@/components/galeria/QuadroParaBaseModal";
import { Download, Trash2, Maximize2, X, Box, Wand2, Sparkles, UserSquare2 } from "lucide-react";
import { useEffect, useState } from "react";

// Item da galeria: o backend (GET /api/media/list) já devolve a URL completa da mídia
// e a origem (keyword/draft). Multi-tenant: o endpoint filtra por tenant_id no banco
// (+ global scope do trait BelongsToTenant), então só vem mídia do tenant logado.
type Item = {
  id: string;
  kind: string;
  url: string;
  style: string | null;
  scene?: number | null; // cena N de uma história = peça intermediária (não é a história final)
  // Ficha técnica — só existe em mídia gerada a partir de 2026-07-20. Item antigo vem null e o
  // card simplesmente não mostra a linha (não inventamos dimensão nem motor).
  model?: string | null;
  w?: number | null;
  h?: number | null;
  bytes?: number | null;
  draft_id: number;
  keyword: string | null;
  date: string;
};

/** Proporção legível a partir das dimensões — é o que o usuário reconhece ("16:9"), não 1.78.
 *  Casa com o formato conhecido mais próximo; sem match, mostra a razão crua. */
function proporcao(w?: number | null, h?: number | null): string {
  if (!w || !h) return "";
  const alvos: [string, number][] = [["1:1", 1], ["16:9", 16 / 9], ["9:16", 9 / 16], ["4:5", 0.8], ["4:3", 4 / 3], ["3:4", 0.75], ["3:2", 1.5], ["2:3", 2 / 3]];
  const r = w / h;
  const [nome, val] = alvos.reduce((a, b) => (Math.abs(b[1] - r) < Math.abs(a[1] - r) ? b : a));
  return Math.abs(val - r) / r < 0.04 ? nome : `${r.toFixed(2)}:1`;
}

function peso(b?: number | null): string {
  if (!b) return "";
  return b >= 1 << 20 ? `${(b / (1 << 20)).toFixed(1)} MB` : `${Math.round(b / 1024)} KB`;
}

// Malha 3D (.glb): kind PRÓPRIO, porque não é imagem, nem áudio, nem vídeo. Sem esta exceção
// o `isVideo` abaixo a classificaria como vídeo e o .glb cairia dentro de um <video> — card
// quebrado, que parece mídia morta em vez de formato que o browser não toca inline.
const isMesh = (kind: string) => kind === "mesh3d";
// Vídeo = qualquer kind que não seja imagem, áudio nem malha (video/veo/short/mp4...).
const isVideo = (kind: string) => kind !== "image" && kind !== "audio" && !isMesh(kind);
// Categoria do item para o filtro/seção. Considera o STYLE além do kind: GIF e Logo são
// kind=image (style gif/logo); História é o Short final (kind=video, style=historia).
const catOf = (it: Item) => {
  const s = (it.style || "").toLowerCase();
  // Cena N de uma história = peça INTERMEDIÁRIA (clipe/imagem/narração de 5s, muda) → entra pelo
  // TIPO BASE (o clipe de cena vai pra Vídeos), NUNCA como História/GIF/Logo. A História é só o
  // vídeo FINAL montado (grande, com áudio narração+música), que NÃO tem scene.
  if (it.scene != null) return it.kind === "audio" ? "audio" : it.kind === "image" ? "image" : "video";
  if (s === "logo") return "logo";
  if (s === "gif") return "gif";
  if (s === "historia" || s === "história") return "story";
  if (s === "filme" || s === "aventura") return "filme"; // final da aba Filme / filmão da aba Aventuras
  // 📰 Vox — jornalismo explicativo animado. Aba PRÓPRIA e não "Vídeos": é uma peça editorial
  // acabada (voz conduzindo + colagem de papel), não um clipe solto. Misturada com os clipes
  // simples, a peça que dá mais trabalho some no meio da lista.
  if (s === "vox") return "vox";
  // Carrossel virado vídeo — mesma razão: peça acabada, não clipe.
  if (s === "carrossel-video") return "vox";
  if (isMesh(it.kind)) return "mesh3d";
  if (it.kind === "audio") return "audio";
  if (it.kind === "image") return "image";
  return "video";
};
// GIF/Logo animam/renderizam como imagem; História é vídeo (Short final) → abrem no visualizador.
const isImageLike = (it: Item) => catOf(it) === "image" || catOf(it) === "gif" || catOf(it) === "logo";
const isVideoLike = (it: Item) => catOf(it) === "video" || catOf(it) === "story" || catOf(it) === "filme" || catOf(it) === "vox";
const CATS: { k: string; lbl: string }[] = [
  { k: "", lbl: "Tudo" },
  { k: "image", lbl: "Imagens" },
  { k: "gif", lbl: "GIFs" },
  { k: "logo", lbl: "Logos" },
  { k: "story", lbl: "Histórias" },
  { k: "filme", lbl: "Filmes" },
  { k: "vox", lbl: "Vox" },
  { k: "video", lbl: "Vídeos" },
  { k: "audio", lbl: "Áudios" },
  { k: "mesh3d", lbl: "3D" },
];

// Placeholder visual quando a mídia não carrega — no estilo do tema (sem ícone do browser).
function Broken() {
  return (
    <div
      style={{
        width: "100%",
        height: "100%",
        display: "flex",
        flexDirection: "column",
        alignItems: "center",
        justifyContent: "center",
        gap: 8,
        color: "var(--muted)",
        background: "var(--bg2)",
      }}
    >
      <Trash2 size={22} strokeWidth={1.6} style={{ opacity: 0.6 }} />
      <span style={{ fontSize: ".78rem" }}>mídia indisponível</span>
    </div>
  );
}

export default function GaleriaPage() {
  const toast = useToast();
  const [items, setItems] = useState<Item[]>([]);
  const [loading, setLoading] = useState(true);
  // ids (draft_id-item.id) cuja mídia não carregou → mostramos o placeholder.
  const [broken, setBroken] = useState<Set<string>>(new Set());
  // ids em processo de exclusão (desabilita o botão).
  const [deleting, setDeleting] = useState<Set<string>>(new Set());
  // estado do "Limpar quebradas" em massa (desabilita o botão durante a chamada).
  const [cleaning, setCleaning] = useState(false);
  // filtro por TIPO de criação: "" (tudo) | image | gif | logo | story | video | audio.
  const [kindFilter, setKindFilter] = useState("");
  // item aberto no visualizador em tela cheia (clicar pra ver maior). null = fechado.
  const [viewer, setViewer] = useState<Item | null>(null);
  // custo (créditos) de cada operação de PÓS-PROCESSAMENTO, vindo do catálogo (kind='edit').
  // Mostrado nos botões — o cliente vê quanto custa antes de clicar.
  const [editCosts, setEditCosts] = useState<Record<string, number | null>>({});
  // operação de enhance em andamento no viewer (upscale|upscale_pro|remove_bg|relight) ou null.
  const [enhancing, setEnhancing] = useState<string | null>(null);
  const [enhanceMsg, setEnhanceMsg] = useState("");
  // REILUMINAR: painel aberto? e os ajustes de luz escolhidos.
  //
  // O padrão "fml" (frente, altura do rosto, esquerda) é o de retrato clássico — luz de janela.
  // Vale como default porque é o que quase nunca estraga a foto; direções de contraluz ("b…")
  // são bonitas, mas escurecem o assunto e precisam de escolha consciente.
  const [luzAberta, setLuzAberta] = useState(false);
  const [luz, setLuz] = useState({
    light_source: "fml",
    light_quality: "soft",
    brightness: 50,
    color: "neutral",
  });
  // SELEÇÃO MÚLTIPLA (checkbox nos cards) → excluir várias de uma vez.
  const [selected, setSelected] = useState<Set<string>>(new Set());
  // 🩹 Item aberto no conserto pontual (inpaint). null = modal fechada.
  const [consertando, setConsertando] = useState<Item | null>(null);
  // ✨ Item aberto no refino local (imagem) e 🎞️ no quadro-para-base (vídeo).
  const [refinando, setRefinando] = useState<Item | null>(null);
  const [congelando, setCongelando] = useState<Item | null>(null);

  /** Recarrega o acervo. Inpaint e refino entram na fila e devolvem mídia NOVA — sem isto o
   *  resultado só apareceria no F5, e o cliente acharia que não funcionou. */
  function recarregar() {
    sfetch("/api/media/list")
      .then((r) => r.json())
      .then((d) => setItems(d.items ?? []))
      .catch(() => {});
  }

  useEffect(() => {
    sfetch("/api/media/list")
      .then((r) => r.json())
      .then((d) => setItems(d.items ?? []))
      .catch(() => setItems([]))
      .finally(() => setLoading(false));
  }, []);

  // Custos das operações de pós-processamento (upscale / remover fundo). Mapeia slug do catálogo → op.
  useEffect(() => {
    // Duas fontes porque a pós-produção mora em dois lugares: o bucket `edit` (herança do
    // agregador) e os modelos de imagem marcados como pós-produção, onde entrou a reiluminação.
    // Buscar só `kind=edit` deixaria o botão de Reiluminar sem preço — e um botão que não diz
    // quanto custa é o tipo de detalhe que só aparece na fatura.
    Promise.all([
      sfetch("/api/gen-models?kind=edit").then((r) => r.json()).catch(() => ({})),
      sfetch("/api/gen-models?uso=postproducao").then((r) => r.json()).catch(() => ({})),
    ])
      .then(([edit, pos]) => {
        const map: Record<string, number | null> = {};
        for (const m of edit.data ?? []) {
          const op = m.slug === "edit-upscale" ? "upscale" : m.slug === "edit-upscale-pro" ? "upscale_pro" : m.slug === "edit-remove-bg" ? "remove_bg" : null;
          if (op) map[op] = m.cost_credits;
        }
        for (const m of pos.data ?? []) {
          if (m.slug === "hf-nano-banana-2-relight") map.relight = m.cost_credits;
        }
        setEditCosts(map);
      })
      .catch(() => {});
  }, []);

  // Limpa a mensagem de erro do enhance ao trocar/abrir outro item no viewer.
  useEffect(() => { setEnhanceMsg(""); }, [viewer?.id]);

  // Fecha o visualizador com Esc.
  useEffect(() => {
    if (!viewer) return;
    const onKey = (e: KeyboardEvent) => { if (e.key === "Escape") setViewer(null); };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [viewer]);

  const key = (it: Item) => `${it.draft_id}-${it.id}`;

  const markBroken = (it: Item) =>
    setBroken((prev) => {
      const next = new Set(prev);
      next.add(key(it));
      return next;
    });

  function toggleSelect(it: Item) {
    const k = key(it);
    setSelected((prev) => {
      const next = new Set(prev);
      if (next.has(k)) next.delete(k); else next.add(k);
      return next;
    });
  }

  // Exclusão EM MASSA das selecionadas (sequencial — o endpoint é por item; falha de uma não
  // trava as demais). Confirmação única com a contagem.
  async function removeSelected() {
    if (selected.size === 0 || cleaning) return;
    if (!confirm(`Excluir ${selected.size} mídia(s) selecionada(s)? Elas saem da galeria e do storage.`)) return;
    setCleaning(true);
    try {
      const alvo = items.filter((x) => selected.has(key(x)));
      for (const it of alvo) {
        await sfetch("/api/media/item", { method: "DELETE", body: JSON.stringify({ draft_id: it.draft_id, id: it.id }) }).catch(() => {});
      }
      setItems((prev) => prev.filter((x) => !selected.has(key(x))));
      setSelected(new Set());
    } finally {
      setCleaning(false);
    }
  }

  async function remove(it: Item) {
    if (!confirm("Excluir esta mídia?")) return;
    const k = key(it);
    setDeleting((prev) => new Set(prev).add(k));
    try {
      const r = await sfetch("/api/media/item", {
        method: "DELETE",
        body: JSON.stringify({ draft_id: it.draft_id, id: it.id }),
      });
      if (!r.ok) throw new Error("falha ao excluir");
      // Sucesso: remove o card do estado local (sem recarregar tudo).
      setItems((prev) => prev.filter((x) => key(x) !== k));
    } catch {
      toast.err("Não foi possível excluir a mídia. Tente novamente.");
    } finally {
      setDeleting((prev) => {
        const next = new Set(prev);
        next.delete(k);
        return next;
      });
    }
  }

  // Baixa a mídia (imagem/vídeo/áudio). A URL é cross-origin (Scality) — buscamos o blob e
  // disparamos o download com nome de arquivo do próprio path; se o CORS bloquear, abre numa aba.
  async function download(it: Item) {
    try {
      const res = await fetch(it.url);
      if (!res.ok) throw new Error("fetch falhou");
      const blob = await res.blob();
      const obj = URL.createObjectURL(blob);
      const a = document.createElement("a");
      a.href = obj;
      a.download = it.url.split("/").pop() || `${it.kind}-${it.id}`;
      document.body.appendChild(a);
      a.click();
      a.remove();
      URL.revokeObjectURL(obj);
    } catch {
      window.open(it.url, "_blank");
    }
  }

  // Pós-processamento só faz sentido em IMAGEM ESTÁTICA do nosso storage (imagem/logo). GIF (animado),
  // vídeo e áudio ficam de fora — os modelos de edição processam um quadro só.
  const canEnhance = (it: Item) => isOwnMedia(it.url) && (catOf(it) === "image" || catOf(it) === "logo");

  // reformat — a MESMA arte em outra proporção, via /api/studio/reformat. Não é geração: é
  // reenquadramento (GD no servidor), então NÃO consome crédito e o resultado é idêntico entre
  // formatos — ao contrário de gerar de novo, que sorteia uma imagem diferente a cada vez.
  const FORMATOS: [string, string, string][] = [
    ["feed", "1:1", "Feed do Instagram/Facebook"],
    ["retrato", "4:5", "Retrato — ocupa mais feed"],
    ["story", "9:16", "Story/Reels/TikTok"],
    ["paisagem", "16:9", "YouTube/LinkedIn/X"],
  ];

  async function reformat(it: Item, format: string) {
    if (enhancing) return;
    setEnhancing(`fmt-${format}`);
    setEnhanceMsg("");
    try {
      const r = await sfetch("/api/studio/reformat", {
        method: "POST",
        body: JSON.stringify({ imageUrl: it.url, formats: [format] }),
      });
      const d = await r.json().catch(() => ({}));
      const v = Array.isArray(d?.variants) ? d.variants[0] : null;
      if (!r.ok || !d.ok || !v?.url) throw new Error(d.error || "Não foi possível adaptar o formato.");
      const nit: Item = {
        id: `fmt-${format}-${v.url}`,
        kind: "image",
        url: v.url,
        style: it.style ?? null,
        scene: null,
        draft_id: it.draft_id,
        keyword: it.keyword,
        date: "",
      };
      setItems((prev) => [nit, ...prev]);
      setViewer(nit);
    } catch (e) {
      setEnhanceMsg(e instanceof Error ? e.message : "Não foi possível adaptar o formato.");
    } finally {
      setEnhancing(null);
    }
  }

  // enhance — PÓS-PROCESSA a imagem aberta no viewer (upscale / remover fundo) via /api/studio/enhance.
  // Síncrono (o processamento leva alguns segundos); ao terminar, o resultado entra na galeria e o
  // viewer passa a mostrá-lo. Cobra créditos no backend (estorna se falhar).
  async function enhance(it: Item, op: "upscale" | "upscale_pro" | "remove_bg" | "relight") {
    if (enhancing) return;
    setEnhancing(op);
    setEnhanceMsg("");
    try {
      const r = await sfetch("/api/studio/enhance", {
        method: "POST",
        // Reiluminar manda a direção da luz junto; as demais operações ignoram esses campos.
        body: JSON.stringify(
          op === "relight"
            ? { draftId: it.draft_id, imageUrl: it.url, op, ...luz }
            : { draftId: it.draft_id, imageUrl: it.url, op },
        ),
      });
      const d = await r.json().catch(() => ({}));
      if (!r.ok || !d.ok || !d.item) throw new Error(d.error || "Não foi possível processar a imagem.");
      const nit: Item = {
        id: String(d.item.id),
        kind: d.item.kind,
        url: d.item.url,
        style: d.item.style ?? null,
        scene: null,
        draft_id: d.draftId ?? it.draft_id,
        keyword: it.keyword,
        date: "",
      };
      setItems((prev) => [nit, ...prev]); // resultado no topo da galeria
      setViewer(nit); // mostra o resultado no viewer
    } catch (e) {
      setEnhanceMsg(e instanceof Error ? e.message : "Não foi possível processar a imagem.");
    } finally {
      setEnhancing(null);
    }
  }

  // Mídias mortas = URL fora do nosso storage (fal.media expirado, storage antigo).
  // É a mesma marca "indisponível" que o front já mostra em cada card.
  const brokenCount = items.filter((it) => !isOwnMedia(it.url)).length;

  // Itens VISÍVEIS = os que a aba ativa mostra. "Selecionar todos" tem de seguir o filtro: marcar
  // o que está fora da vista seria seleção invisível — e o botão vizinho apaga de verdade.
  const visiveis = items.filter((it) => (kindFilter ? catOf(it) === kindFilter : true));
  const todosMarcados = visiveis.length > 0 && visiveis.every((it) => selected.has(key(it)));
  const toggleTodos = () =>
    setSelected((prev) => {
      const next = new Set(prev);
      // Desmarca só os visíveis: o que foi selecionado em outra aba continua selecionado.
      visiveis.forEach((it) => (todosMarcados ? next.delete(key(it)) : next.add(key(it))));
      return next;
    });

  async function cleanBroken() {
    if (brokenCount === 0 || cleaning) return;
    if (!confirm(`Remover ${brokenCount} mídias indisponíveis?`)) return;
    setCleaning(true);
    try {
      const r = await sfetch("/api/media/clean-broken", { method: "POST" });
      if (!r.ok) throw new Error("falha ao limpar");
      const d = await r.json();
      // Sucesso: tira do estado local os itens mortos (sem recarregar tudo).
      setItems((prev) => prev.filter((x) => isOwnMedia(x.url)));
      toast.ok(`${d.removed ?? brokenCount} mídias removidas.`);
    } catch {
      toast.err("Não foi possível limpar as mídias indisponíveis. Tente novamente.");
    } finally {
      setCleaning(false);
    }
  }

  return (
    <>
      <div style={{ display: "flex", alignItems: "flex-start", justifyContent: "space-between", gap: 16, flexWrap: "wrap" }}>
        <div>
          <h1 className="h1">Galeria</h1>
          <p className="sub">Toda a mídia gerada do seu workspace.</p>
        </div>
        <div style={{ display: "flex", gap: 10, alignItems: "center", flexWrap: "wrap" }}>
          {visiveis.length > 0 && (
            <button
              type="button"
              onClick={toggleTodos}
              className="btn"
              title={todosMarcados ? "Desmarcar as mídias desta aba" : "Selecionar todas as mídias desta aba"}
              style={{ flex: "0 0 auto" }}
            >
              {todosMarcados ? "Desmarcar todos" : `Selecionar todos (${visiveis.length})`}
            </button>
          )}
          {selected.size > 0 && (
            <>
              <button type="button" onClick={removeSelected} disabled={cleaning} title="Excluir todas as mídias selecionadas" className="btn no" style={{ flex: "0 0 auto", display: "inline-flex", alignItems: "center", gap: 8 }}>
                <Trash2 size={15} strokeWidth={1.8} />
                {cleaning ? "Excluindo…" : `Excluir selecionadas (${selected.size})`}
              </button>
              <button type="button" onClick={() => setSelected(new Set())} className="btn" style={{ flex: "0 0 auto" }}>Limpar seleção</button>
            </>
          )}
          {brokenCount > 0 && (
            <button
              type="button"
              onClick={cleanBroken}
              disabled={cleaning}
              title="Remover todas as mídias indisponíveis"
              className="btn no"
              style={{ flex: "0 0 auto", display: "inline-flex", alignItems: "center", gap: 8 }}
            >
              <Trash2 size={15} strokeWidth={1.8} />
              {cleaning ? "Limpando…" : `Limpar quebradas (${brokenCount})`}
            </button>
          )}
        </div>
      </div>

      {/* Filtro por TIPO de criação — itens diferentes (imagens, vídeos, áudios) separados. */}
      {items.length > 0 && (
        <div style={{ display: "flex", gap: 10, flexWrap: "wrap", marginTop: 16, marginBottom: 22 }}>
          {CATS.map(({ k, lbl }) => {
            const n = k === "" ? items.length : items.filter((it) => catOf(it) === k).length;
            const on = kindFilter === k;
            return (
              <button key={k || "all"} type="button" onClick={() => setKindFilter(k)} style={{
                padding: "9px 18px", borderRadius: 999, fontSize: ".85rem", fontWeight: 700, cursor: "pointer",
                transition: "background .15s, color .15s, border-color .15s, box-shadow .15s",
                border: "1px solid " + (on ? "var(--red)" : "var(--line)"),
                background: on ? "var(--red)" : "var(--bg2)",
                color: on ? "#fff" : "var(--text)",
                boxShadow: on ? "0 2px 12px var(--red)55" : "none" }}>
                {lbl} ({n})
              </button>
            );
          })}
        </div>
      )}

      {loading ? (
        <SkeletonCards n={8} mediaH={150} />
      ) : items.length === 0 ? (
        <div className="empty">Nenhuma mídia ainda. Gere imagens ou vídeos na aba Mídia.</div>
      ) : (
        <div
          style={{
            display: "grid",
            gap: 16,
            gridTemplateColumns: "repeat(auto-fill, minmax(min(220px, 100%), 1fr))",
          }}
        >
          {items.filter((it) => (kindFilter ? catOf(it) === kindFilter : true)).map((it) => {
            const k = key(it);
            const isBroken = broken.has(k);
            const unavailable = !isOwnMedia(it.url); // provavelmente morta
            return (
              <div key={k} className="card">
                <div style={{ position: "relative", aspectRatio: "1 / 1", background: "var(--bg2)", display: "flex", alignItems: "center", justifyContent: "center", overflow: "hidden" }}>
                  {/* checkbox de SELEÇÃO MÚLTIPLA (excluir várias de uma vez) */}
                  <input
                    type="checkbox"
                    checked={selected.has(k)}
                    onChange={() => toggleSelect(it)}
                    onClick={(e) => e.stopPropagation()}
                    title="Selecionar pra exclusão em massa"
                    style={{ position: "absolute", top: 8, left: 8, zIndex: 4, width: 18, height: 18, cursor: "pointer", accentColor: "var(--red)" }}
                  />
                  {unavailable && !isBroken && (
                    <span
                      style={{
                        position: "absolute",
                        top: 8,
                        left: 34,
                        zIndex: 2,
                        background: "rgba(0,0,0,.65)",
                        color: "#ff9b8a",
                        fontSize: ".68rem",
                        fontWeight: 700,
                        letterSpacing: ".04em",
                        textTransform: "uppercase",
                        padding: "3px 7px",
                        borderRadius: 7,
                      }}
                    >
                      indisponível
                    </span>
                  )}
                  {!isBroken && !unavailable && (isImageLike(it) || isVideoLike(it)) && (
                    <button
                      type="button"
                      onClick={() => setViewer(it)}
                      title="Ver maior"
                      aria-label="Ver maior"
                      style={{ position: "absolute", top: 8, right: 8, zIndex: 3, display: "inline-flex", alignItems: "center", justifyContent: "center", padding: 6, borderRadius: 8, border: "1px solid rgba(255,255,255,.25)", background: "rgba(0,0,0,.55)", color: "#fff", cursor: "pointer" }}
                    >
                      <Maximize2 size={15} strokeWidth={1.8} />
                    </button>
                  )}
                  {isBroken ? (
                    <Broken />
                  ) : it.kind === "image" ? (
                    <img
                      src={it.url}
                      loading="lazy"
                      alt={it.keyword ?? "mídia"}
                      onError={() => markBroken(it)}
                      onClick={() => setViewer(it)}
                      title="Ver maior"
                      style={{ width: "100%", height: "100%", objectFit: "cover", cursor: "zoom-in" }}
                    />
                  ) : it.kind === "audio" ? (
                    <audio
                      src={it.url}
                      controls
                      preload="metadata"
                      onError={() => markBroken(it)}
                      style={{ width: "90%" }}
                    />
                  ) : isMesh(it.kind) ? (
                    // Malha não tem preview inline no browser (o viewer 3D vive na aba /3d, com o
                    // three.js). Card honesto: diz o que é e leva pro lugar que sabe mostrar —
                    // melhor que um <video> vazio fingindo que a mídia quebrou.
                    <a
                      href="/3d"
                      title="Abrir no visualizador 3D"
                      style={{ display: "flex", flexDirection: "column", alignItems: "center", justifyContent: "center", gap: 6, width: "100%", height: "100%", color: "var(--peach)", textDecoration: "none" }}
                    >
                      <Box size={34} strokeWidth={1.4} />
                      <span style={{ fontSize: ".78rem", color: "var(--muted)" }}>malha 3D (.glb)</span>
                    </a>
                  ) : isVideo(it.kind) ? (
                    <video
                      src={it.url}
                      controls
                      preload="metadata"
                      onError={() => markBroken(it)}
                      style={{ width: "100%", height: "100%", objectFit: "cover" }}
                    />
                  ) : (
                    <span style={{ color: "var(--muted)", fontSize: ".8rem" }}>sem preview</span>
                  )}
                </div>
                <div style={{ padding: "14px 14px 14px", display: "flex", flexDirection: "column", gap: 10, borderTop: "1px solid var(--line)" }}>
                  <span style={{ fontWeight: 600, fontSize: ".9rem", overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap" }}>
                    {it.keyword || "(sem tema)"}
                  </span>
                  {/* Ficha técnica: motor · dimensão · proporção · peso. Só aparece quando o item
                      tem os dados (gerados a partir de 2026-07-20) — item antigo fica sem a linha
                      em vez de mostrar campo vazio. É o que permite comparar motores de relance. */}
                  {(it.model || it.w) && (
                    <span
                      className="txt"
                      style={{ color: "var(--muted)", fontSize: ".72rem", overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap" }}
                      title={[it.model && `motor: ${it.model}`, it.w && it.h && `${it.w}×${it.h} (${proporcao(it.w, it.h)})`, peso(it.bytes)].filter(Boolean).join(" · ")}
                    >
                      {[it.model && `⚙️ ${it.model}`, it.w && it.h && `${it.w}×${it.h}`, proporcao(it.w, it.h), peso(it.bytes)].filter(Boolean).join(" · ")}
                    </span>
                  )}
                  <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", gap: 8 }}>
                    <span
                      style={{
                        display: "inline-block",
                        background: "var(--red)1f",
                        color: "var(--peach)",
                        border: "1px solid var(--red)55",
                        fontSize: ".72rem",
                        fontWeight: 700,
                        letterSpacing: ".04em",
                        textTransform: "uppercase",
                        padding: "4px 11px",
                        borderRadius: 999,
                        whiteSpace: "nowrap",
                        overflow: "hidden",
                        textOverflow: "ellipsis",
                        maxWidth: "62%",
                      }}
                      title={it.style || it.kind || "mídia"}
                    >
                      {it.style || it.kind || "mídia"}
                    </span>
                    <div style={{ display: "flex", alignItems: "center", gap: 6, flex: "0 0 auto" }}>
                      {/* 🩹 Consertar: só IMAGEM nossa e viva — o inpaint pinta sobre o arquivo,
                          então vídeo, áudio, malha e link morto ficam de fora. */}
                      {!isBroken && !unavailable && it.kind === "image" && (
                        <button
                          type="button"
                          onClick={() => setConsertando(it)}
                          title="Consertar um trecho da imagem"
                          aria-label="Consertar um trecho da imagem"
                          className="btn"
                          style={{ display: "inline-flex", alignItems: "center", justifyContent: "center", padding: "7px 9px" }}
                        >
                          <Wand2 size={15} strokeWidth={1.8} />
                        </button>
                      )}
                      {/* ✨ Refinar: acabamento na imagem inteira, no motor local (sem crédito). */}
                      {!isBroken && !unavailable && it.kind === "image" && (
                        <button
                          type="button"
                          onClick={() => setRefinando(it)}
                          title="Refinar a imagem no estúdio local"
                          aria-label="Refinar a imagem no estúdio local"
                          className="btn"
                          style={{ display: "inline-flex", alignItems: "center", justifyContent: "center", padding: "7px 9px" }}
                        >
                          <Sparkles size={15} strokeWidth={1.8} />
                        </button>
                      )}
                      {/* 🎞️ Quadro → base: só VÍDEO (o isVideo já tira áudio e malha 3D, que não
                          têm quadro pra congelar). */}
                      {!isBroken && !unavailable && isVideo(it.kind) && (
                        <button
                          type="button"
                          onClick={() => setCongelando(it)}
                          title="Usar um quadro como base de personagem ou cenário"
                          aria-label="Usar um quadro como base de personagem ou cenário"
                          className="btn"
                          style={{ display: "inline-flex", alignItems: "center", justifyContent: "center", padding: "7px 9px" }}
                        >
                          <UserSquare2 size={15} strokeWidth={1.8} />
                        </button>
                      )}
                      {!isBroken && !unavailable && (
                        <button
                          type="button"
                          onClick={() => download(it)}
                          title="Baixar mídia"
                          aria-label="Baixar mídia"
                          className="btn"
                          style={{ display: "inline-flex", alignItems: "center", justifyContent: "center", padding: "7px 9px" }}
                        >
                          <Download size={15} strokeWidth={1.8} />
                        </button>
                      )}
                      <button
                        type="button"
                        onClick={() => remove(it)}
                        disabled={deleting.has(k)}
                        title="Excluir mídia"
                        aria-label="Excluir mídia"
                        className="btn no"
                        style={{ display: "inline-flex", alignItems: "center", justifyContent: "center", padding: "7px 9px" }}
                      >
                        <Trash2 size={15} strokeWidth={1.8} />
                      </button>
                    </div>
                  </div>
                </div>
              </div>
            );
          })}
        </div>
      )}
      {viewer && (
        <div onClick={() => setViewer(null)} style={{ position: "fixed", inset: 0, zIndex: 1000, background: "rgba(0,0,0,.92)", display: "flex", flexDirection: "column" }}>
          <div onClick={(e) => e.stopPropagation()} style={{ display: "flex", alignItems: "center", gap: 8, padding: "12px 16px", flexWrap: "wrap" }}>
            <strong style={{ color: "#fff", flex: 1, minWidth: 120, fontSize: ".95rem", overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap" }}>{viewer.keyword || viewer.style || viewer.kind || "mídia"}</strong>
            {enhanceMsg && <span style={{ color: "#ff9b8a", fontSize: ".8rem", flex: "0 0 auto" }}>{enhanceMsg}</span>}
            {canEnhance(viewer) && (
              <>
                <button type="button" className="btn" disabled={!!enhancing} onClick={() => enhance(viewer, "upscale")} title="Aumenta a nitidez e a resolução da imagem" style={{ display: "inline-flex", alignItems: "center", gap: 6, padding: "7px 12px" }}>
                  {enhancing === "upscale" ? "Processando…" : `✨ Melhorar${editCosts.upscale != null ? ` (${editCosts.upscale} créd)` : ""}`}
                </button>
                <button type="button" className="btn" disabled={!!enhancing} onClick={() => enhance(viewer, "upscale_pro")} title="Upscale premium — resultado mais nítido" style={{ display: "inline-flex", alignItems: "center", gap: 6, padding: "7px 12px" }}>
                  {enhancing === "upscale_pro" ? "Processando…" : `🔍 Pro${editCosts.upscale_pro != null ? ` (${editCosts.upscale_pro} créd)` : ""}`}
                </button>
                <button type="button" className="btn" disabled={!!enhancing} onClick={() => enhance(viewer, "remove_bg")} title="Remove o fundo (PNG transparente)" style={{ display: "inline-flex", alignItems: "center", gap: 6, padding: "7px 12px" }}>
                  {enhancing === "remove_bg" ? "Processando…" : `✂️ Remover fundo${editCosts.remove_bg != null ? ` (${editCosts.remove_bg} créd)` : ""}`}
                </button>
                {/* REILUMINAR: abre o painel em vez de disparar direto. A luz é uma DECISÃO
                    (de onde vem, dura ou suave, quente ou fria) — um clique único devolveria
                    sempre o mesmo resultado e desperdiçaria o que o modelo tem de melhor. */}
                <button type="button" className="btn" disabled={!!enhancing} onClick={() => setLuzAberta((v) => !v)} title="Refaz a iluminação da foto mantendo a pessoa/objeto" style={{ display: "inline-flex", alignItems: "center", gap: 6, padding: "7px 12px" }}>
                  {enhancing === "relight" ? "Reiluminando…" : `💡 Reiluminar${editCosts.relight != null ? ` (${editCosts.relight} créd)` : ""}`}
                </button>
                {/* Adaptar formato: mesma arte, outra proporção. Sem crédito — é reenquadramento,
                    não geração; e por isso as versões ficam idênticas entre si. */}
                <span style={{ display: "inline-flex", alignItems: "center", gap: 4, padding: "3px 8px", borderRadius: 999, border: "1px solid rgba(255,255,255,.22)" }}>
                  <span style={{ color: "rgba(255,255,255,.6)", fontSize: ".72rem" }} title="Reenquadra a MESMA imagem — sem gerar de novo e sem gastar crédito">🖼️ formato</span>
                  {FORMATOS.map(([key, rotulo, ajuda]) => (
                    <button
                      key={key}
                      type="button"
                      className="btn"
                      disabled={!!enhancing}
                      onClick={() => reformat(viewer, key)}
                      title={`${ajuda} — sem crédito`}
                      style={{ padding: "3px 8px", fontSize: ".72rem" }}
                    >{enhancing === `fmt-${key}` ? "…" : rotulo}</button>
                  ))}
                </span>
              </>
            )}
            {/* Painel de REILUMINAÇÃO. A grade 3x3 é a posição da luz vista de frente para o
                assunto: a coluna escolhe o lado, a linha escolhe a altura, e as três abas de
                profundidade dizem se a luz vem da frente, do lado ou de trás. É o mesmo
                vocabulário de um set de fotografia — mais direto que expor os códigos "fdl".  */}
            {luzAberta && viewer.kind === "image" && (
              <div style={{ flexBasis: "100%", marginTop: 10, padding: 12, borderRadius: 10, border: "1px solid rgba(255,255,255,.18)", background: "rgba(255,255,255,.04)", display: "flex", flexWrap: "wrap", gap: 18, alignItems: "flex-start" }}>
                <div>
                  <div style={{ fontSize: ".72rem", color: "rgba(255,255,255,.6)", marginBottom: 6 }}>De onde vem a luz</div>
                  <div style={{ display: "flex", gap: 4, marginBottom: 6 }}>
                    {([["f", "Frente"], ["m", "Lateral"], ["b", "Contraluz"]] as const).map(([p, rotulo]) => (
                      <button
                        key={p}
                        type="button"
                        className="btn"
                        onClick={() => setLuz((l) => ({ ...l, light_source: p + l.light_source.slice(1) }))}
                        style={{ padding: "3px 8px", fontSize: ".72rem", opacity: luz.light_source[0] === p ? 1 : 0.5 }}
                      >{rotulo}</button>
                    ))}
                  </div>
                  <div style={{ display: "grid", gridTemplateColumns: "repeat(3, 30px)", gap: 3 }}>
                    {(["u", "m", "d"] as const).map((altura) =>
                      (["l", "m", "r"] as const).map((lado) => {
                        const codigo = luz.light_source[0] + altura + lado;
                        const ativo = luz.light_source === codigo;
                        return (
                          <button
                            key={codigo}
                            type="button"
                            onClick={() => setLuz((l) => ({ ...l, light_source: codigo }))}
                            title={`${altura === "u" ? "alta" : altura === "d" ? "baixa" : "na altura do rosto"}, ${lado === "l" ? "pela esquerda" : lado === "r" ? "pela direita" : "central"}`}
                            style={{
                              width: 30, height: 30, borderRadius: 6, cursor: "pointer",
                              border: ativo ? "1px solid #ffd479" : "1px solid rgba(255,255,255,.18)",
                              background: ativo ? "rgba(255,212,121,.25)" : "rgba(255,255,255,.05)",
                              color: "inherit", fontSize: ".8rem", lineHeight: 1,
                            }}
                          >{ativo ? "☀" : ""}</button>
                        );
                      }),
                    )}
                  </div>
                </div>
                <div>
                  <div style={{ fontSize: ".72rem", color: "rgba(255,255,255,.6)", marginBottom: 6 }}>Tipo de luz</div>
                  {([["soft", "Suave"], ["sharp", "Definida"], ["hard", "Dura"]] as const).map(([q, rotulo]) => (
                    <button
                      key={q}
                      type="button"
                      className="btn"
                      onClick={() => setLuz((l) => ({ ...l, light_quality: q }))}
                      style={{ padding: "3px 8px", fontSize: ".72rem", marginRight: 4, opacity: luz.light_quality === q ? 1 : 0.5 }}
                    >{rotulo}</button>
                  ))}
                  <div style={{ fontSize: ".72rem", color: "rgba(255,255,255,.6)", margin: "10px 0 6px" }}>Temperatura</div>
                  {([["warm", "Quente"], ["neutral", "Neutra"], ["cool", "Fria"]] as const).map(([c, rotulo]) => (
                    <button
                      key={c}
                      type="button"
                      className="btn"
                      onClick={() => setLuz((l) => ({ ...l, color: c }))}
                      style={{ padding: "3px 8px", fontSize: ".72rem", marginRight: 4, opacity: luz.color === c ? 1 : 0.5 }}
                    >{rotulo}</button>
                  ))}
                </div>
                <div style={{ minWidth: 150 }}>
                  <div style={{ fontSize: ".72rem", color: "rgba(255,255,255,.6)", marginBottom: 6 }}>Intensidade: {luz.brightness}</div>
                  <input
                    type="range" min={0} max={100} step={5} value={luz.brightness}
                    onChange={(e) => setLuz((l) => ({ ...l, brightness: Number(e.target.value) }))}
                    style={{ width: "100%" }}
                  />
                  <button
                    type="button"
                    className="btn ok"
                    disabled={!!enhancing}
                    onClick={() => enhance(viewer, "relight")}
                    style={{ marginTop: 10, padding: "7px 12px" }}
                  >{enhancing === "relight" ? "Reiluminando…" : "💡 Aplicar"}</button>
                </div>
              </div>
            )}
            {/* PUBLICAR a partir da galeria: ativa o rascunho DESTA mídia e abre a aba Aprovar
                (o fluxo de publicação opera por rascunho). Peças intermediárias de cena ficam fora. */}
            {viewer.scene == null && (
              <button type="button" className="btn ok" onClick={() => { localStorage.setItem("reachyn_draft", String(viewer.draft_id)); window.location.href = "/aprovar"; }} title="Carrega o rascunho desta mídia na aba Aprovar para escolher redes, texto e publicar" style={{ display: "inline-flex", alignItems: "center", gap: 6, padding: "7px 12px" }}>
                📣 Publicar
              </button>
            )}
            <button type="button" className="btn" onClick={() => download(viewer)} style={{ display: "inline-flex", alignItems: "center", gap: 6, padding: "7px 12px" }}><Download size={15} strokeWidth={1.8} /> Baixar</button>
            <a className="btn" href={viewer.url} target="_blank" rel="noreferrer" style={{ display: "inline-flex", alignItems: "center", gap: 6, padding: "7px 12px", textDecoration: "none" }}>↗ Abrir</a>
            <button type="button" className="btn no" onClick={() => setViewer(null)} style={{ display: "inline-flex", alignItems: "center", gap: 6, padding: "7px 12px" }}><X size={15} strokeWidth={1.8} /> Fechar</button>
          </div>
          <div onClick={() => setViewer(null)} style={{ flex: 1, display: "flex", alignItems: "center", justifyContent: "center", padding: "0 16px 16px", overflow: "auto" }}>
            {isVideoLike(viewer) ? (
              <video src={viewer.url} controls autoPlay onClick={(e) => e.stopPropagation()} style={{ maxWidth: "100%", maxHeight: "100%", borderRadius: 8 }} />
            ) : (
              <img src={viewer.url} alt={viewer.keyword ?? "mídia"} onClick={(e) => e.stopPropagation()} style={{ maxWidth: "100%", maxHeight: "100%", objectFit: "contain", borderRadius: 8 }} />
            )}
          </div>
        </div>
      )}

      {/* 🩹 Conserto pontual (inpaint). O job é assíncrono: a imagem corrigida entra como mídia
          NOVA no mesmo rascunho, então avisamos e recarregamos a lista — a original fica. */}
      {consertando && (
        <InpaintModal
          url={consertando.url}
          draftId={consertando.draft_id}
          onClose={() => setConsertando(null)}
          onQueued={() => {
            toast.ok("Conserto na fila — a imagem corrigida aparece aqui em ~2 minutos.");
            recarregar();
          }}
        />
      )}

      {/* ✨ Refino local: assíncrono como o inpaint — entra como mídia NOVA, a original fica. */}
      {refinando && (
        <RefinarModal
          url={refinando.url}
          draftId={refinando.draft_id}
          onClose={() => setRefinando(null)}
          onQueued={() => {
            toast.ok("Refino na fila — a versão polida aparece aqui em ~2 minutos.");
            recarregar();
          }}
        />
      )}

      {/* 🎞️ Quadro → base: SÍNCRONO (só congela e grava na ficha), então a mensagem já é o fato. */}
      {congelando && (
        <QuadroParaBaseModal
          url={congelando.url}
          onClose={() => setCongelando(null)}
          onDone={(m) => toast.ok(m)}
        />
      )}
    </>
  );
}
