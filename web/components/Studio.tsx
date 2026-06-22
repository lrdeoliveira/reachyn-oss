"use client";

import { useEffect, useRef, useState } from "react";
import { RichTextArea } from "@/components/RichTextArea";
import { NetworkPreviewTabs, NET } from "@/components/NetworkPreview";
import { sfetch } from "@/lib/api";

const CONSOLE = process.env.NEXT_PUBLIC_CONSOLE_URL ?? "";

// Textarea que cresce com o conteúdo (sem scroll interno) e continua editável.
function AutoTextarea({ value, onChange, style, placeholder, minHeight = 70 }: { value: string; onChange: (v: string) => void; style?: React.CSSProperties; placeholder?: string; minHeight?: number }) {
  const ref = useRef<HTMLTextAreaElement>(null);
  useEffect(() => {
    const el = ref.current;
    if (el) { el.style.height = "auto"; el.style.height = Math.max(minHeight, el.scrollHeight) + "px"; }
  }, [value, minHeight]);
  return <textarea ref={ref} value={value} placeholder={placeholder} onChange={(e) => onChange(e.target.value)} style={{ ...style, minHeight, overflow: "hidden", resize: "none" }} />;
}

// Item da galeria cross-rascunho (GET /api/media/list) — mesmo shape da página Galeria.
type GalleryItem = { id: string; kind: string; url: string; style?: string | null; draft_id?: number; keyword?: string | null; date?: string };
// Só itens com URL do nosso storage carregam (igual à página Galeria).
// Host via env (sem domínio hardcoded — white-label).
const MEDIA_HOST = process.env.NEXT_PUBLIC_MEDIA_HOST ?? "";
const isOwnMedia = (url: string) => !!MEDIA_HOST && url.includes(MEDIA_HOST);

// Modal: escolher uma IMAGEM da galeria (cross-rascunho) como input das gerações.
function GalleryPicker({ onPick, onClose }: { onPick: (url: string) => void; onClose: () => void }) {
  const [items, setItems] = useState<GalleryItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [err, setErr] = useState<string | null>(null);
  useEffect(() => {
    sfetch("/api/media/list")
      .then((r) => r.json())
      .then((d) => { if (d?.ok) setItems(d.items ?? []); else setErr("Não foi possível carregar a galeria."); })
      .catch(() => setErr("Não foi possível carregar a galeria."))
      .finally(() => setLoading(false));
  }, []);
  // Só imagens do nosso storage servem como input de i2i/i2v.
  const usable = items.filter((it) => it.kind === "image" && isOwnMedia(it.url));
  return (
    <div onClick={onClose} style={{ position: "fixed", inset: 0, zIndex: 60, background: "rgba(0,0,0,.7)", display: "flex", alignItems: "center", justifyContent: "center", padding: 20 }}>
      <div onClick={(e) => e.stopPropagation()} style={{ background: "var(--panel)", border: "1px solid var(--line)", borderRadius: 14, padding: 18, width: "min(900px,95vw)", maxHeight: "85vh", overflow: "auto" }}>
        <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: 12 }}>
          <strong style={{ fontSize: "1rem", color: "var(--peach)" }}>Escolher imagem da galeria</strong>
          <button className="btn edit" style={{ flex: "none", padding: "6px 12px" }} onClick={onClose}>Fechar</button>
        </div>
        {loading ? (
          <p className="txt" style={{ color: "var(--muted)" }}>Carregando galeria…</p>
        ) : err ? (
          <p className="txt" style={{ color: "#ff9b8a" }}>{err}</p>
        ) : usable.length === 0 ? (
          <p className="txt" style={{ color: "var(--muted)" }}>Nenhuma imagem disponível na galeria. Gere ou envie uma imagem primeiro.</p>
        ) : (
          <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fill,minmax(130px,1fr))", gap: 10 }}>
            {usable.map((it) => (
              <button key={`${it.draft_id}-${it.id}`} type="button" onClick={() => onPick(it.url)} title={it.keyword || "imagem"}
                style={{ padding: 0, border: "1px solid var(--line)", borderRadius: 10, overflow: "hidden", cursor: "pointer", background: "var(--bg2)", aspectRatio: "1 / 1" }}>
                <img src={it.url} alt={it.keyword || "imagem"} loading="lazy" style={{ width: "100%", height: "100%", objectFit: "cover", display: "block" }} />
              </button>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}

const PLATFORMS = ["blog", "linkedin", "instagram", "facebook", "threads", "twitter", "youtube"];
type Research = { answer: string; brief: string; summary: string; results: { title: string; url: string; source?: string }[] };
type Media = { id: string; kind: string; url: string; style?: string };
const STYLES: [string, string][] = [
  ["realista", "📷 Realista (foto)"], ["3d", "🧸 3D / Pixar"], ["anime", "🎌 Anime / Mangá"],
  ["comic", "💥 Quadrinhos"], ["aquarela", "🎨 Aquarela"], ["cyberpunk", "🌃 Cyberpunk"],
  ["minimalista", "◻️ Minimalista"], ["vintage", "📺 Vintage / Retrô"], ["produto", "📦 Foto de produto"],
  ["pintura", "🖌️ Pintura digital"],
];
const VIDEO_STYLES: [string, string][] = [
  ["cinematografico", "🎬 Cinematográfico"], ["dinamico", "⚡ Dinâmico"], ["documental", "📹 Documental"],
  ["timelapse", "⏱️ Timelapse"], ["anime", "🎌 Anime"], ["3d", "🧸 3D / Pixar"],
];
const STYLE_LABELS: Record<string, string> = { ...Object.fromEntries(STYLES), ...Object.fromEntries(VIDEO_STYLES), viral: "✨ Viral" };
type Step = "research" | "content" | "media" | "approve" | "publish";
const KEY = "reachyn_draft";
// Opções de duração do vídeo direto (o provider gera 5s ou 10s por clipe).
const DURATIONS: [string, string][] = [["5", "5s"], ["10", "10s"]];
// Formatos: imagem aceita os 4; vídeo só vertical (9:16) ou horizontal (16:9).
const IMG_ASPECTS: [string, string][] = [["9:16", "9:16 vertical"], ["1:1", "1:1 quadrado"], ["16:9", "16:9 horizontal"], ["4:5", "4:5 retrato"]];
const VID_ASPECTS: [string, string][] = [["9:16", "9:16 vertical"], ["16:9", "16:9 horizontal"]];
// Teto de duração total do vídeo: 5 min (300s). nº máx de cenas = 300 / duração-do-clipe.
const MAX_VIDEO_SECONDS = 300;
const maxScenesFor = (dur: string) => Math.floor(MAX_VIDEO_SECONDS / (dur === "10" ? 10 : 5)); // 300/5=60; 300/10=30
// Idioma da narração — vale para o vídeo sincronizado E para o Vídeo Premium.
const LANGS: [string, string][] = [["pt-BR", "Português (BR)"], ["en-US", "Inglês (US)"]];
// Fallback das vozes (BR) — usado só se a lista dinâmica da conta falhar/vier vazia, p/ nunca
// regredir a zero vozes. A lista real vem de GET /api/studio/voices.
const VOICES_FALLBACK = [
  { id: "Ey5AWb48tVX1IOcikcht", name: "Marcelo", gender: "male", accent: "BR" },
  { id: "pzfB7SVzqAOWhzxYtlEZ", name: "Wagner", gender: "male", accent: "BR" },
  { id: "YD2yOZItFdFEh7WxPLnp", name: "Juliana", gender: "female", accent: "BR" },
  { id: "jXut6osIUq8fzeZmDtEf", name: "Alina", gender: "female", accent: "BR" },
];

export function Studio({ step }: { step: Step }) {
  const [draftId, setDraftId] = useState<string | null>(null);
  const [keyword, setKeyword] = useState("");
  const [sources, setSources] = useState<string[]>(["web"]);
  const [research, setResearch] = useState<Research | null>(null);
  const [platforms, setPlatforms] = useState<string[]>(["linkedin", "instagram"]);
  const [texts, setTexts] = useState<Record<string, string>>({});
  const [textMeta, setTextMeta] = useState<Record<string, { grounding: number; rank?: number; flags: string[] }>>({});
  const [editRef, setEditRef] = useState(false);
  const [refDraft, setRefDraft] = useState("");
  const [refOpen, setRefOpen] = useState(false); // Mídia: referência colapsada por padrão (expansível)
  const [media, setMedia] = useState<Media[]>([]);
  const [imagePrompt, setImagePrompt] = useState("");
  const [videoPrompt, setVideoPrompt] = useState("");
  const [imgStyle, setImgStyle] = useState("realista");
  const [vidStyle, setVidStyle] = useState("cinematografico");
  const [duration, setDuration] = useState("5"); // duração de cada clipe (s) — provider: 5/10
  const [scenes, setScenes] = useState(5); // nº de cenas do vídeo sincronizado (input manual)
  const [imgAspect, setImgAspect] = useState("1:1"); // formato da imagem: 9:16|1:1|16:9|4:5
  const [vidAspect, setVidAspect] = useState("9:16"); // formato do vídeo: 9:16|16:9
  const [voice, setVoice] = useState("Ey5AWb48tVX1IOcikcht"); // voz default
  const [voices, setVoices] = useState<{ id: string; name: string; gender?: string; accent?: string; language?: string; category?: string }[]>(VOICES_FALLBACK); // vozes da conta (dinâmicas; fallback BR)
  const [lang, setLang] = useState("pt-BR"); // idioma da narração: vale p/ sincronizado E Vídeo Premium
  // Painel unificado "Gerar vídeo": opções combináveis.
  const [narration, setNarration] = useState(false); // narração com voz (mostra voz+idioma)
  const [subtitles, setSubtitles] = useState(false);  // legenda na tela (permitida sem narração)
  const [music, setMusic] = useState(false);          // trilha musical de fundo
  const [premium, setPremium] = useState(false);      // qualidade Premium: 1 cena, áudio nativo
  const [premiumNarration, setPremiumNarration] = useState(false); // Premium: troca o áudio nativo pela narração própria (voz do tenant) + legenda
  const [clipUrl, setClipUrl] = useState("");
  const [clipN, setClipN] = useState(3);
  const [viralTpl, setViralTpl] = useState("action_figure");
  const [viralTitle, setViralTitle] = useState("");
  const [viralTheme, setViralTheme] = useState("");
  const [viralSrc, setViralSrc] = useState("");
  const [myVoice, setMyVoice] = useState<string | null>(null);
  // Imagem usada como INPUT das gerações (i2i/i2v/short). Vazio = geração por texto (padrão).
  const [inputImageUrl, setInputImageUrl] = useState("");
  const [inputImageSource, setInputImageSource] = useState<"" | "upload" | "galeria">("");
  const [pickerOpen, setPickerOpen] = useState(false);
  useEffect(() => { sfetch("/api/usage").then((r) => r.json()).then((j) => { if (j?.ok) setMyVoice(j.voice_id || null); }).catch(() => {}); }, []);
  // Vozes de narração vêm da conta do provedor (dinâmico) — não mais hardcoded. Se a voz
  // selecionada não estiver na lista carregada, cai na primeira disponível.
  useEffect(() => {
    sfetch("/api/studio/voices").then((r) => r.json()).then((j) => {
      const vs = (j?.ok && Array.isArray(j.voices)) ? j.voices : [];
      if (vs.length) { setVoices(vs); setVoice((cur) => (vs.some((v: { id: string }) => v.id === cur) ? cur : vs[0].id)); }
    }).catch(() => {});
  }, []);
  const [busy, setBusy] = useState<string | null>(null);
  const [msg, setMsg] = useState<string | null>(null);
  const [loaded, setLoaded] = useState(false);
  // Rede ativa na pré-visualização (Aprovar/Publicar). "" = usa a 1ª com texto.
  const [previewNet, setPreviewNet] = useState("");
  // Conexões Zernio do tenant (carregadas nos passos Aprovar/Publicar p/ casar texto×conta).
  const [conns, setConns] = useState<{ accounts: { platform: string; name: string }[]; manual: { platform: string; status: string }[]; networks: { key: string; label: string; icon: string }[] }>({ accounts: [], manual: [], networks: [] });

  // Carrega o rascunho atual (localStorage) ao montar — estado compartilhado entre os itens da sidebar.
  useEffect(() => {
    const id = typeof window !== "undefined" ? localStorage.getItem(KEY) : null;
    if (!id) { setLoaded(true); return; }
    sfetch(`/api/studio/draft?id=${id}`).then((r) => r.json()).then((d) => {
      if (d.ok) {
        setDraftId(d.draft.id); setKeyword(d.draft.keyword || "");
        setResearch(d.draft.research || null); setTexts(d.draft.texts || {});
        if (d.draft.texts_meta && typeof d.draft.texts_meta === "object") setTextMeta(d.draft.texts_meta);
        setMedia(d.draft.media || []);
        if (Object.keys(d.draft.texts || {}).length) setPlatforms(Object.keys(d.draft.texts));
      } else localStorage.removeItem(KEY);
      setLoaded(true);
    }).catch(() => setLoaded(true));
  }, []);

  // Nos passos de revisão/publicação, busca as contas conectadas (Zernio) e blog.
  useEffect(() => {
    if (step !== "approve" && step !== "publish") return;
    sfetch("/api/connections").then((r) => r.json()).then((d) => {
      if (d?.ok) setConns({ accounts: d.accounts || [], manual: d.connections || [], networks: d.networks || [] });
    }).catch(() => {});
  }, [step]);

  const toggle = (v: string) => setPlatforms((a) => (a.includes(v) ? a.filter((x) => x !== v) : [...a, v]));
  const go = (path: string) => { window.location.href = path; };
  // Mídia gerada sem pesquisa cria um rascunho no servidor — adota o id retornado pra galeria/aprovação fluírem.
  const adotarDraft = (id?: string) => { if (id && id !== draftId) { setDraftId(id); localStorage.setItem(KEY, id); } };

  // Gera o prompt do tipo escolhido (imagem OU vídeo), SOB DEMANDA (clique) e JÁ condizente
  // com o formato/duração selecionados — o prompt nunca é gerado automaticamente nem toca o
  // texto da publicação. Sobrescreve só o prompt do tipo pedido.
  async function gerarPrompt(kind: "image" | "video") {
    if (!draftId) { setMsg("❌ Faça a pesquisa primeiro (ou abra um rascunho)."); return; }
    setBusy(kind === "image" ? "imgprompt" : "vidprompt"); setMsg(null);
    try {
      const body = { draftId, kind, aspect: kind === "image" ? imgAspect : vidAspect, duration };
      const r = await sfetch("/api/studio/mediaprompts", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(body) });
      const d = await r.json();
      if (!d.ok) { setMsg("❌ " + (d.error || "não foi possível sugerir")); return; }
      if (kind === "image" && d.imagePrompt) setImagePrompt(d.imagePrompt);
      if (kind === "video" && d.videoPrompt) setVideoPrompt(d.videoPrompt);
    } catch {
      setMsg("❌ não foi possível sugerir agora — tente de novo.");
    } finally {
      setBusy(null);
    }
  }
  async function pesquisar() {
    setBusy("research"); setMsg(null);
    const r = await sfetch("/api/studio/research", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ keyword, sources }) });
    const d = await r.json(); setBusy(null);
    if (!d.ok) return setMsg("❌ " + d.error);
    setDraftId(d.draftId); setResearch(d.research); localStorage.setItem(KEY, d.draftId);
    // Prompt NÃO é gerado aqui: ele é opt-in na etapa de Mídia, depois de escolher tipo/formato/duração.
  }
  async function pesquisaProfunda() {
    setBusy("deep"); setMsg("🔬 Pesquisa profunda em andamento (pode levar 1-2 min)...");
    const r = await sfetch("/api/studio/deepsearch", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ keyword }) });
    const d = await r.json(); setBusy(null);
    if (!d.ok) return setMsg("❌ " + (d.error || "falha"));
    setDraftId(d.draftId); setResearch(d.research); localStorage.setItem(KEY, d.draftId); setMsg(null);
    // Prompt NÃO é gerado aqui: opt-in na etapa de Mídia, depois de escolher tipo/formato/duração.
  }
  // Cria um rascunho EM BRANCO (sem pesquisa) e abre o editor direto. O tema é
  // OPCIONAL — sem tema, abre um rascunho "Novo conteúdo" pra escrever/gerar do zero.
  async function criarSemPesquisa() {
    setBusy("blank"); setMsg(null);
    try {
      const r = await sfetch("/api/studio/draft", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ keyword: keyword.trim() || "Novo conteúdo" }) });
      const d = await r.json();
      if (!d.ok) { setBusy(null); return setMsg("❌ " + (d.error || "não foi possível criar")); }
      localStorage.setItem(KEY, String(d.draftId));
      window.location.href = "/editar";
    } catch {
      setBusy(null); setMsg("❌ não foi possível criar agora — tente de novo.");
    }
  }
  async function gerarTexto(p: string) {
    setBusy("t-" + p);
    const r = await sfetch("/api/studio/text", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ draftId, platform: p }) });
    const d = await r.json(); setBusy(null);
    if (d.ok) { setTexts((t) => ({ ...t, [p]: d.post })); setTextMeta((m) => ({ ...m, [p]: { grounding: d.grounding || 0, rank: d.rank_summary || 0, flags: d.flags || [] } })); }
  }
  async function salvarResumo() {
    setBusy("ref");
    const r = await sfetch("/api/studio/research", { method: "PATCH", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ draftId, summary: refDraft }) });
    const d = await r.json(); setBusy(null);
    if (d.ok) { setResearch((x) => (x ? { ...x, summary: refDraft } : x)); setEditRef(false); }
  }
  function salvarTexto(p: string, text: string) {
    setTexts((t) => ({ ...t, [p]: text }));
    sfetch("/api/studio/text", { method: "PATCH", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ draftId, platform: p, text }) }).catch(() => {});
  }
  // gerarMidia: geração por texto (padrão) OU a partir de uma imagem de input (i2i/i2v).
  // opts.imageUrl, quando presente, vai no body → o engine transforma/anima/usa-a como base.
  // Para kind "video", o painel unificado envia as opções combináveis em opts.video
  // (cenas, duração, narração, legenda, música, premium, voz, idioma, estilo).
  async function gerarMidia(
    kind: "image" | "video",
    opts?: {
      imageUrl?: string;
      video?: {
        scenes: number; duration: string; narration: boolean; subtitles: boolean;
        music: boolean; premium: boolean; voice_id?: string; lang: string; style: string;
      };
    },
  ) {
    setBusy(kind === "image" ? "image" : "video");
    setMsg(null);
    const imageUrl = opts?.imageUrl ?? "";
    const before = media.length;
    if (kind === "image") {
      const body: Record<string, unknown> = {
        draftId, kind, prompt: imagePrompt || keyword, aspect: imgAspect, style: imgStyle,
      };
      if (imageUrl) body.imageUrl = imageUrl;
      const r = await sfetch("/api/studio/media", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(body) });
      const d = await r.json();
      if (!d.ok) { setBusy(null); return setMsg("❌ " + d.error); }
      // i2i (transformar imagem) é job longo → MANTÉM o botão travado durante o polling
      // (evita gerações duplicadas). Geração direta (text→image) volta na hora → libera já.
      if (imageUrl) {
        setMsg("🎨 " + (d.message || "Transformando imagem… leva alguns instantes."));
        const did = d.draftId || draftId; adotarDraft(d.draftId);
        let n = 0;
        const iv = setInterval(async () => {
          n++;
          const rr = await sfetch(`/api/studio/draft?id=${did}`).then((x) => x.json()).catch(() => null);
          if (rr?.ok) {
            setMedia(rr.draft.media || []);
            if ((rr.draft.media || []).length > before) { setMsg("✅ Pronto na galeria!"); clearInterval(iv); setBusy(null); }
          }
          if (n > 30) { clearInterval(iv); setBusy(null); setMsg("⏳ Está demorando — a imagem aparece na galeria quando ficar pronta."); }
        }, 18000);
        return;
      }
      if (d.media) setMedia(d.media);
      adotarDraft(d.draftId);
      setBusy(null);
      return;
    }
    // kind === "video": painel unificado. Monta o body com as opções combináveis.
    const v = opts?.video ?? { scenes, duration, narration, subtitles, music, premium, voice_id: narration ? voice : undefined, lang, style: vidStyle };
    const body: Record<string, unknown> = {
      draftId,
      kind: "video",
      prompt: videoPrompt || keyword,
      aspect: vidAspect,
      style: v.style,
      scenes: v.scenes,
      duration: v.duration,
      narration: v.narration,
      subtitles: v.subtitles,
      music: v.music,
      premium: v.premium,
      lang: v.lang,
    };
    if (v.narration && v.voice_id) body.voice_id = v.voice_id;
    if (imageUrl) body.imageUrl = imageUrl;
    const r = await sfetch("/api/studio/media", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(body) });
    const d = await r.json();
    if (!d.ok) { setBusy(null); return setMsg("❌ " + d.error); }
    // Vídeo é job longo. MANTÉM o botão travado (busy="video") durante TODO o polling,
    // pra não disparar várias gerações em paralelo. Só libera quando o vídeo fica pronto
    // (aparece na galeria) ou no timeout — aí o botão volta a "🎬 Gerar vídeo".
    setMsg("🎬 " + (d.message || "Gerando vídeo… leva alguns minutos. Pode deixar esta aba aberta."));
    const did = d.draftId || draftId; adotarDraft(d.draftId);
    let n = 0;
    const iv = setInterval(async () => {
      n++;
      const rr = await sfetch(`/api/studio/draft?id=${did}`).then((x) => x.json()).catch(() => null);
      if (rr?.ok) {
        setMedia(rr.draft.media || []);
        if ((rr.draft.media || []).length > before) { setMsg("✅ Vídeo pronto na galeria!"); clearInterval(iv); setBusy(null); }
      }
      if (n > 60) { clearInterval(iv); setBusy(null); setMsg("⏳ O vídeo está demorando mais que o normal — ele aparece na galeria sozinho quando ficar pronto."); }
    }, 18000);
  }
  async function upload(kind: "image" | "video", file: File) {
    setBusy("up-" + kind);
    const fd = new FormData(); fd.append("file", file); fd.append("draftId", draftId || ""); fd.append("kind", kind);
    const r = await sfetch("/api/studio/upload", { method: "POST", body: fd });
    const d = await r.json(); setBusy(null);
    if (d.ok) { setMedia(d.media); adotarDraft(d.draftId); } else setMsg("❌ " + d.error);
  }
  // Upload de uma imagem do PC pra usar como INPUT (não entra na galeria do rascunho como item final;
  // reusa o mesmo endpoint /api/studio/upload e adota a URL retornada como imagem de input).
  async function uploadInput(file: File) {
    setBusy("up-input"); setMsg(null);
    const fd = new FormData(); fd.append("file", file); fd.append("draftId", draftId || ""); fd.append("kind", "image");
    const r = await sfetch("/api/studio/upload", { method: "POST", body: fd });
    const d = await r.json(); setBusy(null);
    if (!d.ok) return setMsg("❌ " + d.error);
    adotarDraft(d.draftId);
    if (Array.isArray(d.media)) setMedia(d.media);
    // a URL recém-enviada é o item mais recente do tipo imagem.
    const url = d.url || (Array.isArray(d.media) ? [...d.media].reverse().find((m: Media) => m.kind === "image")?.url : "");
    if (url) { setInputImageUrl(url); setInputImageSource("upload"); }
    else setMsg("❌ Não foi possível obter a URL da imagem enviada.");
  }
  function escolherDaGaleria(url: string) {
    setInputImageUrl(url); setInputImageSource("galeria"); setPickerOpen(false);
  }
  function limparInput() { setInputImageUrl(""); setInputImageSource(""); }
  async function refreshMedia() {
    if (!draftId) return;
    const r = await sfetch(`/api/studio/draft?id=${draftId}`);
    const d = await r.json(); if (d.ok) setMedia(d.draft.media || []);
  }
  async function clonarVoz(file: File) {
    setBusy("voice"); setMsg(null);
    const fd = new FormData(); fd.append("file", file);
    const r = await sfetch("/api/studio/voice-clone", { method: "POST", body: fd });
    const d = await r.json(); setBusy(null);
    if (d.ok) { setMyVoice(d.voice_id); setVoice(d.voice_id); setMsg("✅ Sua voz foi clonada! Já dá pra usar nos vídeos."); } else setMsg("❌ " + d.error);
  }
  async function dublar(videoUrl: string, lang: string) {
    setBusy("dub"); setMsg(null);
    const before = media.length;
    const r = await sfetch("/api/studio/dub", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ draftId, videoUrl, lang }) });
    const d = await r.json(); setBusy(null);
    if (!d.ok) return setMsg("❌ " + d.error);
    setMsg("🌎 " + d.message);
    let n = 0;
    const iv = setInterval(async () => {
      n++;
      const rr = await sfetch(`/api/studio/draft?id=${draftId}`).then((x) => x.json()).catch(() => null);
      if (rr?.ok) { setMedia(rr.draft.media || []); if ((rr.draft.media || []).length > before) { setMsg("✅ Versão dublada pronta na galeria!"); clearInterval(iv); } }
      if (n > 70) clearInterval(iv);
    }, 18000);
  }
  async function clipar() {
    if (!clipUrl.trim()) return setMsg("❌ Cole a URL do vídeo longo (MP4) ou faça upload acima.");
    setBusy("clip"); setMsg(null);
    const before = media.length;
    const r = await sfetch("/api/studio/clip", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ draftId, videoUrl: clipUrl.trim(), n: clipN }) });
    const d = await r.json(); setBusy(null);
    if (!d.ok) return setMsg("❌ " + d.error);
    setMsg("✂️ " + d.message);
    let n = 0;
    const iv = setInterval(async () => {
      n++;
      const rr = await sfetch(`/api/studio/draft?id=${draftId}`).then((x) => x.json()).catch(() => null);
      if (rr?.ok) { setMedia(rr.draft.media || []); if ((rr.draft.media || []).length > before) { setMsg(`✅ ${(rr.draft.media || []).length - before} short(s) prontos na galeria!`); clearInterval(iv); } }
      if (n > 30) clearInterval(iv);
    }, 18000);
  }
  async function excluirMidia(id: string) {
    const r = await sfetch("/api/studio/media", { method: "DELETE", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ draftId, id }) });
    const d = await r.json(); if (d.ok) setMedia(d.media);
  }
  async function gerarViral() {
    const src = viralSrc || media.find((m) => m.kind === "image")?.url;
    if (!src) return setMsg("❌ Faça upload de uma foto (com rosto) primeiro — use '+ Upload imagem' acima.");
    setBusy("viral"); setMsg(null);
    const r = await sfetch("/api/studio/viral", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ draftId, photoUrl: src, template: viralTpl, title: viralTitle, theme: viralTheme }) });
    const d = await r.json(); setBusy(null);
    if (d.ok) { setMedia(d.media); setMsg("✨ Template viral gerado na galeria!"); } else setMsg("❌ " + d.error);
  }
  async function gerarThumb(videoUrl: string) {
    setBusy("thumb"); setMsg(null);
    const r = await sfetch("/api/studio/thumbnail", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ draftId, videoUrl }) });
    const d = await r.json(); setBusy(null);
    if (d.ok) { setMedia(d.media); setMsg("✅ Thumbnail gerada na galeria!"); } else setMsg("❌ " + d.error);
  }
  async function publicar() {
    setBusy("submit");
    const r = await sfetch("/api/studio/submit", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ draftId }) });
    const d = await r.json();
    if (!d.ok) { setBusy(null); return setMsg("❌ " + (d.error || "falha ao publicar")); }
    // Publish assíncrono: acompanha o worker por polling do status.
    setMsg("⏳ Publicando em " + (d.platforms || []).join(", ") + "… (pode levar até ~1 min por rede)");
    const fmt = (results: { platform: string; ok: boolean }[]) =>
      results.map((x) => `${x.platform} ${x.ok ? "✅" : "❌"}`).join("  ");
    const poll = async () => {
      try {
        const sr = await sfetch(`/api/studio/publish-status?draftId=${draftId}`);
        const sd = await sr.json();
        const p = sd.publish;
        if (p?.state === "done") { setBusy(null); setMsg("🚀 Publicado: " + fmt(p.results || [])); return; }
        if (p?.state === "failed") { setBusy(null); setMsg("❌ Falha na publicação: " + (p.error || "")); return; }
      } catch { /* segue tentando */ }
      setTimeout(poll, 3000);
    };
    setTimeout(poll, 3000);
  }
  function novo() { localStorage.removeItem(KEY); window.location.href = "/"; }

  const inp = { background: "var(--bg2)", color: "var(--text)", border: "1px solid var(--line)", borderRadius: 10, padding: "12px 14px", fontSize: ".95rem", width: "100%" } as const;
  const card = { background: "var(--panel)", border: "1px solid var(--line)", borderRadius: 12, padding: 16 } as const;
  const titles: Record<Step, string> = { research: "Pesquisar", content: "Conteúdo", media: "Mídia", approve: "Aprovar", publish: "Publicar" };
  // Card de Referência (resumo da pesquisa, editável) — reusado em Conteúdo e Mídia.
  const referenciaCard = (research && (research.summary || research.answer)) ? (
    <div style={card}>
      <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: 10, gap: 8 }}>
        <strong>📋 Referência da pesquisa</strong>
        {!editRef ? (
          <button className="btn edit" style={{ flex: "none", padding: "6px 12px" }} onClick={() => { setRefDraft(research?.summary || research?.answer || ""); setEditRef(true); }}>✏️ Editar resumo</button>
        ) : (
          <div style={{ display: "flex", gap: 8 }}>
            <button className="btn edit" style={{ flex: "none", padding: "6px 12px" }} onClick={() => setEditRef(false)}>Cancelar</button>
            <button className="btn ok" style={{ flex: "none", padding: "6px 14px" }} disabled={busy === "ref"} onClick={salvarResumo}>{busy === "ref" ? "Salvando…" : "Salvar"}</button>
          </div>
        )}
      </div>
      {editRef ? (
        <AutoTextarea value={refDraft} onChange={setRefDraft} minHeight={220} style={{ ...inp, lineHeight: 1.55, fontFamily: "inherit" }} />
      ) : (
        <p className="txt" style={{ whiteSpace: "pre-wrap", margin: 0 }}>{research?.summary || research?.answer}</p>
      )}
    </div>
  ) : null;
  // Versão COMPACTA da referência pra aba Mídia: colapsada (caixa pequena) e expansível, pra
  // não empurrar os controles de geração. Mesma edição (editRef/refDraft/salvarResumo).
  const referenciaCompacta = (research && (research.summary || research.answer)) ? (
    <div style={card}>
      <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", gap: 8 }}>
        <strong style={{ fontSize: ".9rem" }}>📋 Referência da pesquisa</strong>
        <div style={{ display: "flex", gap: 8 }}>
          {!editRef ? (<>
            <button className="btn edit" style={{ flex: "none", padding: "5px 11px", fontSize: ".78rem" }} onClick={() => setRefOpen((o) => !o)}>{refOpen ? "Recolher" : "Expandir"}</button>
            <button className="btn edit" style={{ flex: "none", padding: "5px 11px", fontSize: ".78rem" }} onClick={() => { setRefDraft(research?.summary || research?.answer || ""); setEditRef(true); setRefOpen(true); }}>✏️ Editar</button>
          </>) : (<>
            <button className="btn edit" style={{ flex: "none", padding: "5px 11px", fontSize: ".78rem" }} onClick={() => setEditRef(false)}>Cancelar</button>
            <button className="btn ok" style={{ flex: "none", padding: "5px 12px", fontSize: ".78rem" }} disabled={busy === "ref"} onClick={salvarResumo}>{busy === "ref" ? "Salvando…" : "Salvar"}</button>
          </>)}
        </div>
      </div>
      {editRef ? (
        <AutoTextarea value={refDraft} onChange={setRefDraft} minHeight={140} style={{ ...inp, lineHeight: 1.55, fontFamily: "inherit", marginTop: 8 }} />
      ) : (
        <div style={{ position: "relative", marginTop: 8 }}>
          <p className="txt" style={{ whiteSpace: "pre-wrap", margin: 0, color: "var(--muted)", fontSize: ".85rem", maxHeight: refOpen ? "none" : 54, overflow: "hidden" }}>{research?.summary || research?.answer}</p>
          {!refOpen && <div style={{ position: "absolute", left: 0, right: 0, bottom: 0, height: 26, background: "linear-gradient(transparent, var(--panel))", pointerEvents: "none" }} />}
        </div>
      )}
    </div>
  ) : null;
  // Mídia funciona avulsa (sem pesquisa): gera "qualquer imagem" e cria o rascunho na hora. Os demais passos ainda exigem rascunho.
  const needDraft = step !== "research" && step !== "media" && !draftId;
  // Plataformas com texto pronto + se cada uma tem conta conectada (blog = WordPress por chave; demais = conta Zernio).
  const pubPlatforms = Object.keys(texts).filter((p) => texts[p]?.trim());
  const isConnected = (p: string) => p === "blog"
    ? conns.manual.some((c) => c.platform === "wordpress" && c.status === "connected")
    : conns.accounts.some((a) => a.platform === p);
  const netMeta = (p: string) => conns.networks.find((n) => n.key === p);

  if (!loaded) return <p className="sub">Carregando rascunho...</p>;

  return (
    <>
      <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center" }}>
        <h1 className="h1">{titles[step]}{keyword ? <span style={{ color: "var(--muted)", fontWeight: 400, fontSize: "1rem" }}> · {keyword}</span> : ""}</h1>
        <button className="btn edit" style={{ flex: "none", padding: "7px 13px" }} onClick={novo}>+ Novo</button>
      </div>

      {needDraft && <div className="empty">Nenhum rascunho ativo. Comece em <a href="/" style={{ color: "var(--peach)" }}>Pesquisar</a>.</div>}

      {/* PESQUISAR */}
      {step === "research" && (
        <div style={{ display: "flex", flexDirection: "column", gap: 18, marginTop: 14, maxWidth: 820 }}>
          <div style={{ display: "flex", flexDirection: "column", gap: 12 }}>
            <div style={{ display: "flex", gap: 8, flexWrap: "wrap", alignItems: "center" }}>
              <span className="txt" style={{ color: "var(--muted)", fontSize: ".82rem", marginRight: 2 }}>Pesquisar em:</span>
              {([["web", "🌐 Web"], ["instagram", "📸 Instagram"], ["twitter", "𝕏 Twitter"], ["youtube", "▶️ YouTube"]] as const).map(([k, label]) => (
                <button key={k} type="button" onClick={() => setSources((a) => (a.includes(k) ? a.filter((x) => x !== k) : [...a, k]))}
                  style={{ padding: "6px 13px", borderRadius: 20, fontSize: ".8rem", cursor: "pointer",
                    border: "1px solid " + (sources.includes(k) ? "var(--red)" : "var(--line)"),
                    background: sources.includes(k) ? "rgba(226,74,49,.15)" : "var(--bg2)",
                    color: sources.includes(k) ? "var(--peach)" : "var(--muted)" }}>{label}</button>
              ))}
            </div>
            <input style={inp} placeholder="tema (ex: tendências de IA em 2026)" value={keyword} onChange={(e) => setKeyword(e.target.value)} />
            <div style={{ display: "flex", gap: 10, flexWrap: "wrap", alignItems: "center" }}>
              <button className="btn ok" disabled={busy !== null || !keyword.trim() || sources.length === 0} onClick={pesquisar}>{busy === "research" ? "Pesquisando..." : "Pesquisar →"}</button>
              <button className="btn edit" disabled={busy !== null || !keyword.trim()} onClick={pesquisaProfunda}
                title="Pesquisa aprofundada com IA: raciocina, lê várias fontes e já entrega o resumo sintetizado. Mais lenta (1-2 min). Plano Studio.">
                {busy === "deep" ? "Pesquisando a fundo…" : "🔬 Pesquisa profunda · Studio"}</button>
              <button className="btn edit" disabled={busy !== null} onClick={criarSemPesquisa}
                title="Pula a pesquisa e abre o editor direto (o tema é opcional).">
                {busy === "blank" ? "Criando…" : "✍️ Criar sem pesquisa →"}</button>
            </div>
            <p className="txt" style={{ color: "var(--muted)", fontSize: ".82rem", margin: 0 }}>Sem tempo pra pesquisar? <strong>Criar sem pesquisa</strong> abre o editor na hora (com ou sem tema).</p>
          </div>
          <div style={card}>
            {research ? (<>
              <strong>Resumo (IA)</strong>
              <p className="txt" style={{ whiteSpace: "pre-wrap" }}>{research.summary || research.answer}</p>
              <strong>Fontes</strong>
              <ul style={{ margin: "6px 0", paddingLeft: 18 }}>{research.results.map((r, i) => <li key={i}>{r.source && r.source !== "web" && <span style={{ fontSize: ".7rem", textTransform: "uppercase", color: "var(--muted)", marginRight: 6 }}>[{r.source}]</span>}<a href={r.url} target="_blank" rel="noopener" style={{ color: "var(--peach)" }}>{r.title}</a></li>)}</ul>
              <button className="btn ok" style={{ maxWidth: 150 }} onClick={() => go("/editar")}>Ir pra Conteúdo →</button>
            </>) : <span className="txt">O resumo da IA + fontes aparecem aqui.</span>}
          </div>
        </div>
      )}

      {/* CONTEÚDO */}
      {step === "content" && draftId && (
        <div style={{ display: "flex", flexDirection: "column", gap: 16, marginTop: 14, maxWidth: 900 }}>
          {referenciaCard}

          {/* Seletor de redes */}
          <div style={card}>
            <strong style={{ fontSize: ".9rem" }}>Redes para gerar conteúdo</strong>
            <div style={{ display: "flex", gap: 6, flexWrap: "wrap", marginTop: 10 }}>
              {PLATFORMS.map((p) => <button key={p} onClick={() => toggle(p)} style={{ padding: "5px 12px", borderRadius: 16, fontSize: ".78rem", textTransform: "capitalize", cursor: "pointer", border: "1px solid " + (platforms.includes(p) ? "var(--red)" : "var(--line)"), background: platforms.includes(p) ? "rgba(217,61,38,.15)" : "var(--bg2)", color: platforms.includes(p) ? "var(--peach)" : "var(--muted)" }}>{p}</button>)}
            </div>
          </div>

          {/* Cards de texto por rede */}
          {platforms.map((p) => (
            <div key={p} style={card}>
              <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: 8 }}>
                <strong style={{ textTransform: "capitalize" }}>{p}</strong>
                <button className="btn edit" style={{ flex: "none", padding: "6px 12px" }} disabled={busy === "t-" + p} onClick={() => gerarTexto(p)}>{busy === "t-" + p ? "Gerando..." : texts[p] ? "Regerar" : "Gerar texto IA"}</button>
              </div>
              <RichTextArea value={texts[p] || ""} onChange={(v) => salvarTexto(p, v)} placeholder="Gere com IA ou escreva aqui..." />
              {textMeta[p] && (texts[p] || "") && (
                <div style={{ display: "flex", gap: 8, flexWrap: "wrap", alignItems: "center", marginTop: 6, fontSize: ".74rem" }}>
                  {textMeta[p].grounding > 0 && (() => { const g = textMeta[p].grounding; const c = g >= 0.5 ? "#22c55e" : g >= 0.3 ? "#f59e0b" : "#ef4444"; return (
                    <span title="Embasamento: o quanto o texto se apoia nas fontes pesquisadas" style={{ color: c, border: "1px solid " + c, borderRadius: 12, padding: "1px 8px" }}>● embasamento {Math.round(g * 100)}%</span>
                  ); })()}
                  {textMeta[p].flags.map((f, i) => <span key={i} style={{ color: "var(--muted)", border: "1px solid var(--line)", borderRadius: 12, padding: "1px 8px" }}>⚠ {f}</span>)}
                </div>
              )}
            </div>
          ))}
          {platforms.length === 0 && <div className="empty" style={{ ...card, color: "var(--muted)", textAlign: "center" }}>Selecione ao menos uma rede acima para gerar conteúdo.</div>}

          <button className="btn ok" style={{ alignSelf: "flex-start", flex: "none", padding: "10px 18px" }} onClick={() => go("/midia")}>Ir pra Mídia →</button>
        </div>
      )}

      {/* MÍDIA */}
      {step === "media" && (
        <div style={{ display: "flex", flexDirection: "column", gap: 16, marginTop: 14 }}>
          {referenciaCompacta}

          {/* O que vai ser publicado — textos gerados por rede (guia pra a mídia combinar com o post) */}
          {pubPlatforms.length > 0 && (
            <div style={card}>
              <strong style={{ fontSize: ".9rem" }}>📝 Textos das publicações</strong>
              <p className="txt" style={{ color: "var(--muted)", fontSize: ".78rem", margin: "4px 0 10px" }}>O que será publicado em cada rede — guia pra a mídia combinar com o post. Edite em <a href="/editar" style={{ color: "var(--peach)" }}>Conteúdo</a>.</p>
              <div style={{ display: "flex", flexDirection: "column", gap: 8 }}>
                {pubPlatforms.map((p) => (
                  <details key={p} style={{ border: "1px solid var(--line)", borderRadius: 8, padding: "8px 12px", background: "var(--bg2)" }}>
                    <summary style={{ cursor: "pointer", display: "flex", justifyContent: "space-between", alignItems: "center", gap: 8, fontSize: ".85rem" }}>
                      <span style={{ textTransform: "capitalize", color: NET[p]?.color || "var(--text)" }}>{NET[p]?.name || p}</span>
                      {typeof textMeta[p]?.rank === "number" && (textMeta[p].rank as number) > 0 && (() => { const rk = textMeta[p].rank as number; const c = rk >= 0.5 ? "#22c55e" : rk >= 0.3 ? "#f59e0b" : "#ef4444"; return <span title="Aderência ao resumo (rank) — prioriza este texto nos prompts de mídia" style={{ fontSize: ".72rem", color: c, border: "1px solid " + c, borderRadius: 12, padding: "1px 8px" }}>rank {Math.round(rk * 100)}%</span>; })()}
                    </summary>
                    <p className="txt" style={{ whiteSpace: "pre-wrap", margin: "8px 0 0", fontSize: ".85rem" }}>{texts[p]}</p>
                  </details>
                ))}
              </div>
            </div>
          )}

          {/* 🖼️ IMAGEM — formato + prompt (sob demanda) + estilo + gerar */}
          {(() => {
            const sel = { background: "var(--bg2)", color: "var(--text)", border: "1px solid var(--line)", borderRadius: 8, padding: "9px 12px", fontSize: ".82rem" } as const;
            return (
              <div style={{ ...card, borderColor: "rgba(14,165,233,.35)", display: "flex", flexDirection: "column", gap: 12 }}>
                <strong style={{ fontSize: "1rem", color: "var(--peach)" }}>🖼️ Imagem</strong>
                <div style={{ display: "flex", gap: 14, flexWrap: "wrap", alignItems: "flex-end" }}>
                  <div style={{ display: "flex", flexDirection: "column", gap: 4 }}>
                    <label className="txt" style={{ color: "var(--muted)", fontSize: ".78rem" }}>Formato</label>
                    <select value={imgAspect} onChange={(e) => setImgAspect(e.target.value)} style={sel} title="Tamanho/proporção da imagem">
                      {IMG_ASPECTS.map(([v, label]) => <option key={v} value={v}>🖼️ {label}</option>)}
                    </select>
                  </div>
                  <div style={{ display: "flex", flexDirection: "column", gap: 4 }}>
                    <label className="txt" style={{ color: "var(--muted)", fontSize: ".78rem" }}>Estilo</label>
                    <select value={imgStyle} onChange={(e) => setImgStyle(e.target.value)} style={sel} title="Estilo visual da imagem">
                      {STYLES.map(([v, label]) => <option key={v} value={v}>{label}</option>)}
                    </select>
                  </div>
                </div>
                {/* Prompt opcional — gerado SÓ sob demanda, já condizente com o formato escolhido */}
                <div style={{ display: "flex", flexDirection: "column", gap: 6 }}>
                  <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", gap: 8, flexWrap: "wrap" }}>
                    <label className="txt" style={{ color: "var(--muted)", fontSize: ".82rem" }}>Prompt da imagem (opcional)</label>
                    {draftId && (research?.summary || research?.answer) && (
                      <button className="btn edit" style={{ flex: "none", padding: "6px 11px", fontSize: ".78rem" }} disabled={busy === "imgprompt"} onClick={() => gerarPrompt("image")} title="Gera um prompt de imagem a partir da referência, já no formato escolhido — cada clique varia">{busy === "imgprompt" ? "Gerando…" : "🎲 Gerar prompt de imagem"}</button>
                    )}
                  </div>
                  <AutoTextarea value={imagePrompt} onChange={setImagePrompt} minHeight={70} style={{ ...inp, lineHeight: 1.5, fontFamily: "inherit" }} placeholder="ex: uma pequena empresária sorrindo no balcão da loja com um tablet mostrando gráficos de crescimento" />
                </div>
                <div style={{ display: "flex", gap: 8, flexWrap: "wrap", alignItems: "center" }}>
                  <button className="btn ok" style={{ flex: "none", padding: "9px 14px" }} disabled={busy === "image"} onClick={() => gerarMidia("image")}>{busy === "image" ? "Gerando..." : "+ Imagem (IA)"}</button>
                  <span style={{ width: 1, height: 26, background: "var(--line)", margin: "0 4px" }} />
                  <label className="btn edit" style={{ flex: "none", padding: "9px 14px", cursor: "pointer" }}>+ Upload imagem<input type="file" accept="image/*" style={{ display: "none" }} onChange={(e) => e.target.files?.[0] && upload("image", e.target.files[0])} /></label>
                  <label className="btn edit" style={{ flex: "none", padding: "9px 14px", cursor: "pointer" }}>+ Upload vídeo<input type="file" accept="video/*" style={{ display: "none" }} onChange={(e) => e.target.files?.[0] && upload("video", e.target.files[0])} /></label>
                  <button className="btn edit" style={{ flex: "none", padding: "9px 14px" }} onClick={refreshMedia}>↻ Atualizar</button>
                </div>
              </div>
            );
          })()}

          {/* 🖼️ Usar uma imagem como base (i2i / i2v / vídeo sincronizado) */}
          <div style={{ ...card, borderColor: "rgba(14,165,233,.4)" }}>
            <strong>🖼️ Partir de uma imagem (opcional)</strong>
            <p className="txt" style={{ color: "var(--muted)", margin: "4px 0 12px" }}>
              Envie ou escolha uma imagem para a IA <strong>transformar</strong>, <strong>animar</strong> ou usar como <strong>base</strong> do vídeo sincronizado. Sem imagem, a geração é por texto (acima).
            </p>
            {!inputImageUrl ? (
              <div style={{ display: "flex", gap: 8, alignItems: "center", flexWrap: "wrap" }}>
                <label className="btn edit" style={{ flex: "none", padding: "9px 14px", cursor: "pointer" }}>
                  {busy === "up-input" ? "Enviando..." : "⬆️ Enviar do computador"}
                  <input type="file" accept="image/*" style={{ display: "none" }} disabled={busy === "up-input"} onChange={(e) => e.target.files?.[0] && uploadInput(e.target.files[0])} />
                </label>
                <button type="button" className="btn edit" style={{ flex: "none", padding: "9px 14px" }} onClick={() => setPickerOpen(true)}>🖼️ Escolher da galeria</button>
              </div>
            ) : (
              <div style={{ display: "flex", gap: 14, alignItems: "flex-start", flexWrap: "wrap" }}>
                <div style={{ position: "relative" }}>
                  <img src={inputImageUrl} alt="imagem de input" style={{ width: 140, height: 140, objectFit: "cover", borderRadius: 10, border: "1px solid var(--line)", display: "block" }} />
                  <button type="button" onClick={limparInput} title="Remover seleção" style={{ position: "absolute", top: 6, right: 6, width: 26, height: 26, borderRadius: "50%", border: 0, background: "rgba(0,0,0,.65)", color: "#fff", cursor: "pointer", fontWeight: 700 }}>✕</button>
                </div>
                <div style={{ display: "flex", flexDirection: "column", gap: 8, flex: 1, minWidth: 220 }}>
                  <span className="txt" style={{ color: "var(--muted)", fontSize: ".8rem" }}>
                    Fonte: {inputImageSource === "upload" ? "envio do computador" : "galeria"} · escolha o que fazer:
                  </span>
                  <div style={{ display: "flex", gap: 8, flexWrap: "wrap" }}>
                    <button className="btn ok" style={{ flex: "none", padding: "9px 14px" }} disabled={busy === "image"} onClick={() => gerarMidia("image", { imageUrl: inputImageUrl })} title="Transforma/edita a imagem com base no prompt e no estilo selecionados (i2i)">{busy === "image" ? "Transformando..." : "🎨 Transformar (imagem)"}</button>
                  </div>
                  <span className="txt" style={{ color: "var(--muted)", fontSize: ".75rem" }}>Dica: ajuste os prompts (imagem/vídeo) e o estilo acima. Esta imagem também é usada como <strong>base</strong> no painel &quot;Gerar vídeo&quot; abaixo (i2v / vídeo sincronizado), enquanto estiver selecionada.</span>
                </div>
              </div>
            )}
          </div>

          {/* 🎬 GERAR VÍDEO — painel unificado com opções combináveis */}
          {(() => {
            // Premium = 1 cena com áudio nativo: incompatível com cenas>1, narração e legenda.
            const maxScenes = maxScenesFor(duration); // teto por 5 min: 60 (clipe 5s) ou 30 (clipe 10s)
            const effScenes = premium ? 1 : Math.max(1, Math.min(maxScenes, scenes || 1));
            const totalSec = effScenes * Number(duration);
            const tgl = (on: boolean): React.CSSProperties => ({
              padding: "7px 13px", borderRadius: 20, fontSize: ".8rem", cursor: "pointer",
              border: "1px solid " + (on ? "var(--red)" : "var(--line)"),
              background: on ? "rgba(226,74,49,.15)" : "var(--bg2)",
              color: on ? "var(--peach)" : "var(--muted)",
            });
            const sel = { background: "var(--bg2)", color: "var(--text)", border: "1px solid var(--line)", borderRadius: 8, padding: "9px 12px", fontSize: ".82rem" } as const;
            return (
              <div style={{ ...card, borderColor: "rgba(124,58,237,.4)", display: "flex", flexDirection: "column", gap: 14 }}>
                <strong style={{ fontSize: "1rem", color: "var(--peach)" }}>🎬 Gerar vídeo</strong>

                {/* Prompt do vídeo (opcional) — gerado SÓ sob demanda, já no formato/duração escolhidos */}
                <div style={{ display: "flex", flexDirection: "column", gap: 6 }}>
                  <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", gap: 8, flexWrap: "wrap" }}>
                    <label className="txt" style={{ color: "var(--muted)", fontSize: ".82rem" }}>Prompt do vídeo (opcional)</label>
                    {draftId && (research?.summary || research?.answer) && (
                      <button className="btn edit" style={{ flex: "none", padding: "6px 11px", fontSize: ".78rem" }} disabled={busy === "vidprompt"} onClick={() => gerarPrompt("video")} title="Gera um prompt de vídeo a partir da referência, já no formato/duração escolhidos — cada clique varia">{busy === "vidprompt" ? "Gerando…" : "🎲 Gerar prompt de vídeo"}</button>
                    )}
                  </div>
                  <AutoTextarea value={videoPrompt} onChange={setVideoPrompt} minHeight={70} style={{ ...inp, lineHeight: 1.5, fontFamily: "inherit" }} placeholder="ex: a empresária caminhando pela loja, câmera acompanhando, luz natural, ritmo dinâmico" />
                </div>

                {/* Linha 1: formato + cenas (manual) + duração de cada clipe + estilo */}
                <div style={{ display: "flex", gap: 14, flexWrap: "wrap", alignItems: "flex-end" }}>
                  <div style={{ display: "flex", flexDirection: "column", gap: 4 }}>
                    <label className="txt" style={{ color: "var(--muted)", fontSize: ".78rem" }}>Formato</label>
                    <select value={vidAspect} onChange={(e) => setVidAspect(e.target.value)} disabled={premium} style={{ ...sel, opacity: premium ? 0.55 : 1 }} title="Vertical (9:16) ou horizontal (16:9)">
                      {VID_ASPECTS.map(([v, label]) => <option key={v} value={v}>🎬 {label}</option>)}
                    </select>
                  </div>
                  <div style={{ display: "flex", flexDirection: "column", gap: 4 }}>
                    <label className="txt" style={{ color: "var(--muted)", fontSize: ".78rem" }}>Cenas (1–{maxScenes})</label>
                    <input type="number" min={1} max={maxScenes} value={effScenes} disabled={premium}
                      onChange={(e) => setScenes(Math.max(1, Math.min(maxScenes, Number(e.target.value) || 1)))}
                      style={{ ...sel, width: 96, opacity: premium ? 0.55 : 1 }} title={`Quantas cenas/clipes o vídeo terá (1 = curto; até ${maxScenes} para ~5 min). Cada cena gasta uma geração.`} />
                  </div>
                  <div style={{ display: "flex", flexDirection: "column", gap: 4 }}>
                    <label className="txt" style={{ color: "var(--muted)", fontSize: ".78rem" }}>Duração de cada clipe</label>
                    <select value={duration} onChange={(e) => setDuration(e.target.value)} style={sel} title="Duração (em segundos) de cada cena/clipe">
                      {DURATIONS.map(([v, label]) => <option key={v} value={v}>⏱️ {label}</option>)}
                    </select>
                  </div>
                  <div style={{ display: "flex", flexDirection: "column", gap: 4 }}>
                    <label className="txt" style={{ color: "var(--muted)", fontSize: ".78rem" }}>Estilo</label>
                    <select value={vidStyle} onChange={(e) => setVidStyle(e.target.value)} style={sel} title="Estilo do vídeo">
                      {VIDEO_STYLES.map(([v, label]) => <option key={v} value={v}>{label}</option>)}
                    </select>
                  </div>
                </div>
                {/* Duração total = nº de cenas × duração de cada clipe (dinâmico). */}
                <p className="txt" style={{ color: "var(--muted)", fontSize: ".75rem", margin: "-4px 0 0" }}>
                  ⏳ Duração total ≈ <strong style={{ color: "var(--peach)" }}>{totalSec >= 60 ? `${Math.floor(totalSec / 60)}min${totalSec % 60 ? ` ${totalSec % 60}s` : ""}` : `${totalSec}s`}</strong> ({effScenes} {effScenes === 1 ? "cena" : "cenas"} × {duration}s · máx 5 min){narration && !premium ? " · com narração, cada cena se ajusta à fala (pode variar)" : ""}
                  {effScenes >= 6 && <span style={{ color: "#f59e0b" }}> · ⚠️ {effScenes} cenas = {effScenes} gerações de IA (mais tempo e custo)</span>}
                </p>

                {/* Linha 2: toggles combináveis */}
                <div style={{ display: "flex", gap: 8, flexWrap: "wrap", alignItems: "center" }}>
                  <span className="txt" style={{ color: "var(--muted)", fontSize: ".78rem", marginRight: 2 }}>Opções:</span>
                  <button type="button" onClick={() => setNarration((v) => !v)} disabled={premium} style={{ ...tgl(narration && !premium), opacity: premium ? 0.55 : 1 }} title="Adiciona voz narrando o conteúdo">🎙️ Narração{narration && !premium ? " ✓" : ""}</button>
                  <button type="button" onClick={() => setSubtitles((v) => !v)} disabled={premium} style={{ ...tgl(subtitles && !premium), opacity: premium ? 0.55 : 1 }} title="Legenda na tela (permitida mesmo sem narração)">💬 Legenda{subtitles && !premium ? " ✓" : ""}</button>
                  <button type="button" onClick={() => setMusic((v) => !v)} style={tgl(music)} title="Trilha musical de fundo">🎵 Música{music ? " ✓" : ""}</button>
                  <span style={{ width: 1, height: 22, background: "var(--line)", margin: "0 2px" }} />
                  <button type="button" onClick={() => setPremium((v) => !v)} style={tgl(premium)} title="Qualidade Premium: 1 cena com áudio nativo">⭐ {premium ? "Premium ✓" : "Qualidade: Padrão"}</button>
                </div>

                {/* Voz + idioma — narração no modo Padrão OU "minha narração" no Premium */}
                {((narration && !premium) || (premium && premiumNarration)) && (
                  <div style={{ display: "flex", gap: 10, flexWrap: "wrap", alignItems: "flex-end" }}>
                    <div style={{ display: "flex", flexDirection: "column", gap: 4 }}>
                      <label className="txt" style={{ color: "var(--muted)", fontSize: ".78rem" }}>Voz</label>
                      <select value={voice} onChange={(e) => setVoice(e.target.value)} style={sel}>
                        {voices.map((v) => {
                          const g = v.gender === "female" ? "feminina" : v.gender === "male" ? "masculina" : v.gender;
                          const det = [g, v.accent].filter(Boolean).join(", ");
                          return <option key={v.id} value={v.id}>🎙️ {v.name}{det ? ` — ${det}` : ""}</option>;
                        })}
                        {myVoice && <option value={myVoice}>🎙️ Minha voz (clonada)</option>}
                      </select>
                    </div>
                    <div style={{ display: "flex", flexDirection: "column", gap: 4 }}>
                      <label className="txt" style={{ color: "var(--muted)", fontSize: ".78rem" }}>Idioma</label>
                      <select value={lang} onChange={(e) => setLang(e.target.value)} style={sel}>
                        {LANGS.map(([v, label]) => <option key={v} value={v}>🗣️ {label}</option>)}
                      </select>
                    </div>
                    <label className="btn edit" style={{ flex: "none", padding: "9px 12px", cursor: "pointer", fontSize: ".82rem" }} title="Clonar sua voz a partir de uma amostra de áudio (plano Studio)">{busy === "voice" ? "Clonando..." : (myVoice ? "↻ Reclonar voz" : "🎙️ Clonar minha voz")}<input type="file" accept="audio/*" style={{ display: "none" }} onChange={(e) => e.target.files?.[0] && clonarVoz(e.target.files[0])} /></label>
                  </div>
                )}

                {/* Premium: opção de trocar o áudio nativo pela narração própria */}
                {premium && (
                  <div style={{ display: "flex", gap: 8, alignItems: "center", flexWrap: "wrap" }}>
                    <button type="button" onClick={() => setPremiumNarration((v) => !v)} style={tgl(premiumNarration)} title="Gera o vídeo premium SEM o áudio nativo e coloca a SUA narração (voz) por cima, com legenda">🎙️ Minha narração{premiumNarration ? " ✓" : ""}</button>
                    <span className="txt" style={{ color: "var(--muted)", fontSize: ".74rem" }}>{premiumNarration ? "Vídeo premium mudo + sua voz + legenda (a IA escreve um roteiro curto da referência)." : "Padrão: 1 cena com o áudio nativo do vídeo premium."}</span>
                  </div>
                )}

                {/* Base de imagem ativa */}
                {inputImageUrl && (
                  <p className="txt" style={{ color: "var(--muted)", fontSize: ".76rem", margin: 0 }}>🖼️ Usando a imagem selecionada acima como base do vídeo (i2v).</p>
                )}

                {/* Botão único */}
                <div style={{ display: "flex", gap: 10, alignItems: "center", flexWrap: "wrap" }}>
                  <button
                    className="btn ok"
                    style={{ flex: "none", padding: "10px 18px", background: "linear-gradient(135deg,#7c3aed,#5b21b6)" }}
                    disabled={busy === "video"}
                    onClick={() => gerarMidia("video", {
                      imageUrl: inputImageUrl || undefined,
                      video: {
                        scenes: effScenes, duration,
                        narration: premium ? premiumNarration : narration,
                        subtitles: premium ? premiumNarration : subtitles,
                        music, premium,
                        voice_id: (premium ? premiumNarration : narration) ? voice : undefined,
                        lang, style: vidStyle,
                      },
                    })}
                    title="Gera o vídeo com as opções selecionadas"
                  >{busy === "video" ? "⏳ Gerando vídeo… (aguarde)" : "🎬 Gerar vídeo"}</button>
                </div>

                {/* Avisos de custo/tempo */}
                <p className="txt" style={{ color: "var(--muted)", fontSize: ".76rem", margin: 0 }}>
                  Cada opção ligada aumenta tempo e custo. Sem narração, a legenda usa um tempo estimado por cena.
                </p>
              </div>
            );
          })()}

          {/* ✂️ Shorts Clipper: vídeo longo → vários shorts */}
          <div style={{ ...card, borderColor: "rgba(124,58,237,.4)" }}>
            <strong>✂️ Clipar vídeo longo → shorts</strong>
            <p className="txt" style={{ color: "var(--muted)", margin: "4px 0 10px" }}>Cole a URL de um vídeo (MP4) ou use o &quot;Upload vídeo&quot; acima. A IA transcreve, acha os melhores trechos e gera shorts 9:16 legendados. (Pro)</p>
            <div style={{ display: "flex", gap: 8, alignItems: "center", flexWrap: "wrap" }}>
              <input style={{ ...inp, flex: 1, minWidth: 240 }} placeholder="https://.../video-longo.mp4" value={clipUrl} onChange={(e) => setClipUrl(e.target.value)} />
              <select value={clipN} onChange={(e) => setClipN(Number(e.target.value))} style={{ background: "var(--bg2)", color: "var(--text)", border: "1px solid var(--line)", borderRadius: 8, padding: "9px 12px", fontSize: ".88rem" }}>
                {[1, 2, 3, 4, 5].map((k) => <option key={k} value={k}>{k} short{k > 1 ? "s" : ""}</option>)}
              </select>
              <button className="btn ok" style={{ flex: "none", padding: "9px 16px", background: "linear-gradient(135deg,#7c3aed,#5b21b6)" }} disabled={busy === "clip"} onClick={clipar}>{busy === "clip" ? "Cortando..." : "✂️ Gerar shorts"}</button>
            </div>
          </div>

          {/* ✨ Template viral: foto do cliente → action figure / funko / lego... */}
          <div style={{ ...card, borderColor: "rgba(217,61,38,.4)" }}>
            <strong>✨ Template viral (a partir de uma foto)</strong>
            <p className="txt" style={{ color: "var(--muted)", margin: "4px 0 10px" }}>Faça &quot;+ Upload imagem&quot; de uma foto com rosto, escolha o estilo e a IA transforma a pessoa em action figure, Funko, LEGO… Ótimo gancho de engajamento.</p>
            <div style={{ display: "flex", gap: 8, alignItems: "center", flexWrap: "wrap" }}>
              <select value={viralTpl} onChange={(e) => setViralTpl(e.target.value)} style={{ background: "var(--bg2)", color: "var(--text)", border: "1px solid var(--line)", borderRadius: 8, padding: "9px 12px", fontSize: ".88rem" }}>
                <option value="action_figure">🎬 Action Figure (na caixa)</option>
                <option value="funko">🧸 Funko Pop</option>
                <option value="lego">🧱 Minifigura LEGO</option>
                <option value="diorama">🏙️ Diorama 3D</option>
                <option value="caricature_3d">🎨 Caricatura 3D (Pixar)</option>
              </select>
              <select value={viralSrc} onChange={(e) => setViralSrc(e.target.value)} style={{ background: "var(--bg2)", color: "var(--text)", border: "1px solid var(--line)", borderRadius: 8, padding: "9px 12px", fontSize: ".88rem", maxWidth: 200 }}>
                <option value="">Foto de origem (1ª imagem)</option>
                {media.filter((m) => m.kind === "image").map((m, i) => <option key={m.id} value={m.url}>Imagem {i + 1}</option>)}
              </select>
              <input style={{ ...inp, flex: "none", width: 150 }} placeholder="título (ex: REACHYN)" value={viralTitle} onChange={(e) => setViralTitle(e.target.value)} />
              <input style={{ ...inp, flex: 1, minWidth: 160 }} placeholder="tema/acessórios (opcional)" value={viralTheme} onChange={(e) => setViralTheme(e.target.value)} />
              <button className="btn ok" style={{ flex: "none", padding: "9px 16px", background: "linear-gradient(135deg,#d93d26,#ff7a59)" }} disabled={busy === "viral"} onClick={gerarViral}>{busy === "viral" ? "Gerando..." : "✨ Gerar viral"}</button>
            </div>
          </div>
          <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fill,minmax(180px,1fr))", gap: 14 }}>
            {media.length === 0 && <span className="txt">Nenhuma mídia. Gere com IA ou faça upload — adicione várias.</span>}
            {media.map((m) => (
              <div key={m.id} style={{ ...card, padding: 8, position: "relative" }}>
                <button onClick={() => excluirMidia(m.id)} title="Excluir" style={{ position: "absolute", top: 6, right: 6, width: 26, height: 26, borderRadius: "50%", border: 0, background: "rgba(0,0,0,.6)", color: "#fff", cursor: "pointer", fontWeight: 700 }}>✕</button>
                {m.kind === "image" ? <img src={m.url} alt="" style={{ width: "100%", borderRadius: 8, display: "block" }} /> : <video src={m.url} controls style={{ width: "100%", borderRadius: 8 }} />}
                {m.style && <div style={{ textAlign: "center", fontSize: ".72rem", color: "var(--muted)", marginTop: 5 }}>{STYLE_LABELS[m.style] || m.style}</div>}
                {m.kind === "video" && <button className="btn edit" style={{ width: "100%", marginTop: 6, padding: "5px 0", fontSize: ".78rem" }} disabled={busy === "thumb"} onClick={() => gerarThumb(m.url)}>{busy === "thumb" ? "..." : "🖼️ Gerar thumbnail"}</button>}
                {m.kind === "video" && <div style={{ display: "flex", gap: 4, marginTop: 4 }}>
                  <button className="btn edit" style={{ flex: 1, padding: "5px 0", fontSize: ".74rem" }} disabled={busy === "dub"} onClick={() => dublar(m.url, "en")} title="Dublar para inglês (plano Studio)">🌎 EN</button>
                  <button className="btn edit" style={{ flex: 1, padding: "5px 0", fontSize: ".74rem" }} disabled={busy === "dub"} onClick={() => dublar(m.url, "es")} title="Dublar para espanhol (plano Studio)">🌎 ES</button>
                </div>}
              </div>
            ))}
          </div>
          <button className="btn ok" style={{ maxWidth: 150 }} onClick={() => go("/aprovar")}>Ir pra Aprovar →</button>

          {pickerOpen && <GalleryPicker onPick={escolherDaGaleria} onClose={() => setPickerOpen(false)} />}
        </div>
      )}

      {/* APROVAR (revisão + ajustes finais) — editor à esquerda, pré-visualização por rede à direita */}
      {step === "approve" && draftId && (
        <div style={{ display: "flex", flexDirection: "column", gap: 14, marginTop: 14 }}>
          <p className="sub">Revise e faça os ajustes finais antes de publicar. Veja ao lado como fica em cada rede. Selecione um trecho e use <strong>B</strong> pra deixar em negrito (ou <em>I</em> pra itálico).</p>
          {pubPlatforms.length === 0 ? (
            <div className="empty" style={{ ...card, color: "var(--muted)", textAlign: "center" }}>Nenhum texto pra revisar. Volte em <a href="/editar" style={{ color: "var(--peach)" }}>Conteúdo</a>.</div>
          ) : (
            <div style={{ display: "flex", flexDirection: "column", gap: 14 }}>
              {/* abas de rede + editor da ativa (as próprias abas já são a pré-visualização por rede) */}
              <div style={{ display: "flex", flexDirection: "column", gap: 10 }}>
                {(() => {
                  const cur = previewNet && pubPlatforms.includes(previewNet) ? previewNet : pubPlatforms[0];
                  return (
                    <>
                      <div style={{ display: "flex", gap: 6, flexWrap: "wrap" }}>
                        {pubPlatforms.map((p) => {
                          const active = p === cur;
                          const hasText = !!(texts[p] && texts[p].trim());
                          return (
                            <button key={p} onClick={() => setPreviewNet(p)} title={"Editar " + p}
                              style={{ padding: "5px 12px", borderRadius: 16, fontSize: ".8rem", cursor: "pointer", textTransform: "capitalize", fontWeight: active ? 700 : 400,
                                border: "1px solid " + (NET[p]?.color || "var(--line)"),
                                background: active ? (NET[p]?.color || "var(--line)") : (NET[p]?.color || "var(--line)") + "22",
                                color: active ? "#fff" : "var(--text)" }}>
                              {hasText ? "● " : ""}{p}
                            </button>
                          );
                        })}
                      </div>
                      <div style={card}>
                        <strong style={{ textTransform: "capitalize", display: "block", marginBottom: 8 }}>{cur}</strong>
                        <RichTextArea value={texts[cur] || ""} onChange={(v) => salvarTexto(cur, v)} placeholder="Ajuste o texto aqui..." />
                      </div>
                    </>
                  );
                })()}
              </div>
              {/* Mídia da peça (full width, abaixo do editor) */}
              {media.length > 0 && (
                <div style={card}>
                  <strong style={{ fontSize: ".9rem" }}>Mídia ({media.length})</strong>
                  <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fill,minmax(90px,1fr))", gap: 6, marginTop: 8 }}>
                    {media.map((m) => m.kind === "image"
                      ? <img key={m.id} src={m.url} alt="" style={{ width: "100%", borderRadius: 6, display: "block" }} />
                      : <video key={m.id} src={m.url} style={{ width: "100%", borderRadius: 6 }} />)}
                  </div>
                </div>
              )}
            </div>
          )}
          <div style={{ display: "flex", gap: 10, flexWrap: "wrap" }}>
            <button className="btn ok" style={{ flex: "none", padding: "10px 18px" }} onClick={() => go("/publicar")}>Aprovar e ir pra Publicar →</button>
            <button className="btn edit" style={{ flex: "none", padding: "10px 18px" }} onClick={() => go("/editar")}>✏️ Editar no editor completo</button>
            <button className="btn no" style={{ flex: "none", padding: "10px 18px" }} onClick={() => {
              if (!confirm("Rejeitar e descartar este rascunho? Ele não será publicado.")) return;
              setDraftId(null); setResearch(null); setTexts({}); setMedia([]);
              localStorage.removeItem(KEY);
              setMsg("Rascunho rejeitado e descartado.");
              go("/");
            }}>✕ Rejeitar / descartar</button>
          </div>
        </div>
      )}

      {/* PUBLICAR */}
      {step === "publish" && draftId && (
        <div style={{ display: "flex", flexDirection: "column", gap: 14, marginTop: 14 }}>
          <div style={card}>
            <p><strong>Tema:</strong> {keyword}</p>
            <p><strong>Plataformas:</strong> {pubPlatforms.join(", ") || "—"}</p>
            <p><strong>Mídia:</strong> {media.filter((m) => m.kind === "image").length} imagem(ns) · {media.filter((m) => m.kind === "video").length} vídeo(s)</p>
            <p style={{ color: "var(--muted)", fontSize: ".85rem" }}>
              {media.some((m) => m.kind === "video")
                ? "→ Será anexado o vídeo mais recente nas redes."
                : media.some((m) => m.kind === "image")
                ? `→ Serão anexadas até 4 imagens nas redes; a 1ª vira capa no blog.`
                : "→ Sem mídia: publica só o texto."}
            </p>
          </div>

          {/* Pré-visualização do que vai ao ar (com atalho pra editar) */}
          {pubPlatforms.length > 0 && (
            <div style={card}>
              <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: 6, gap: 8 }}>
                <strong style={{ fontSize: ".95rem" }}>👁 Como vai ficar</strong>
                <button className="btn edit" style={{ flex: "none", padding: "6px 12px" }} onClick={() => go("/editar")}>✏️ Editar conteúdo</button>
              </div>
              <NetworkPreviewTabs
                platforms={pubPlatforms}
                active={previewNet || pubPlatforms[0]}
                onActive={setPreviewNet}
                texts={texts}
                medias={media.map((m) => ({ url: m.url, kind: m.kind }))}
              />
            </div>
          )}

          {/* Conexões de destino — casa cada texto com a conta conectada (Zernio) */}
          <div style={card}>
            <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: 8 }}>
              <strong>🔗 Conexões de destino</strong>
              <a className="btn edit" style={{ flex: "none", padding: "6px 12px", textDecoration: "none" }} href="/conexoes">Gerenciar</a>
            </div>
            {pubPlatforms.length === 0 ? (
              <p className="txt" style={{ color: "var(--muted)", margin: 0 }}>Nenhuma plataforma com texto.</p>
            ) : pubPlatforms.map((p) => {
              const ok = isConnected(p); const meta = netMeta(p);
              return (
                <div key={p} style={{ display: "flex", justifyContent: "space-between", alignItems: "center", gap: 8, padding: "8px 0", borderTop: "1px solid var(--line)" }}>
                  <span style={{ textTransform: "capitalize" }}>{meta?.icon ? meta.icon + " " : ""}{meta?.label || p}</span>
                  {ok
                    ? <span style={{ fontSize: ".78rem", color: "var(--green,#3fb950)" }}>✅ conectada</span>
                    : <a className="btn ok" style={{ flex: "none", padding: "4px 12px", fontSize: ".76rem", textDecoration: "none" }} href={p === "blog" ? "/conexoes" : `${CONSOLE}/connect/${p}`}>Conectar →</a>}
                </div>
              );
            })}
          </div>

          {pubPlatforms.length > 0 && !pubPlatforms.some(isConnected) && (
            <p className="txt" style={{ color: "#ff9b8a", fontSize: ".85rem", margin: 0 }}>⚠ Nenhuma rede conectada — conecte ao menos uma para publicar.</p>
          )}
          <button className="btn ok" style={{ maxWidth: 220 }} disabled={busy === "submit" || (pubPlatforms.length > 0 && !pubPlatforms.some(isConnected))} onClick={publicar}>{busy === "submit" ? "Publicando..." : "🚀 Publicar agora"}</button>
        </div>
      )}

      {msg && <p className="txt" style={{ marginTop: 18, fontSize: "1rem" }}>{msg}</p>}
    </>
  );
}
