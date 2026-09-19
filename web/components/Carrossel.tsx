"use client";

// 🎠 CARROSSEL — post de slides com motor editorial próprio.
//
// O fluxo tem DOIS passos separados de propósito, e essa separação é a feature:
//
//   1. PLANO (texto, barato) — headline vencedora, arquitetura narrativa e brief de imagem por
//      slide. Sai na tela pra ser lido e editado.
//   2. RENDER (imagem, caro) — N slides = N imagens. Só acontece depois que o plano foi revisado.
//
// Juntar os dois num botão só transformaria um tema mal interpretado em N cobranças. O custo
// aparece na tela ANTES do clique que gasta.

import { useCallback, useEffect, useRef, useState } from "react";
import { sfetch } from "@/lib/api";
import { TextModelSelect } from "@/components/TextModelSelect";
import { SeletorModeloImagem } from "@/components/SeletorModeloImagem";

type Slide = {
  index: number;
  role: string;
  tag?: string;
  blocks: string[];
  accent?: string[];
  image_url?: string;
  slide_url?: string;
  status?: string;
  error?: string;
};

type Plano = {
  topic?: string;
  headline?: string;
  family?: string;
  axis?: string;
  caption?: string;
  slides?: Slide[];
  status?: string;
  error?: string;
  mode?: string;
  format?: string;
  tone?: string;
};

const PAPEIS: Record<string, string> = {
  capa: "Capa",
  hook: "Gancho",
  contexto: "Contexto",
  mecanismo: "Mecanismo",
  prova: "Prova",
  expansao: "Virada",
  aplicacao: "Na prática",
  direcao: "Direção",
  assinatura: "Assinatura",
};

const sel = { background: "var(--bg2)", color: "var(--text)", border: "1px solid var(--line)", borderRadius: 8, padding: "9px 12px", fontSize: ".82rem" } as const;

export function Carrossel({
  draftId, card, onDraft, onMedia,
}: {
  draftId: string | null;
  card: React.CSSProperties;
  onDraft?: (id: string) => void;
  onMedia?: () => void;
}) {
  const [topic, setTopic] = useState("");
  const [slides, setSlides] = useState(9);
  const [format, setFormat] = useState("retrato");
  const [mode, setMode] = useState("editorial");
  const [tone, setTone] = useState("light");
  // Modelo de TEXTO: é ele que escreve headline, narrativa e direção de arte. Nasce em PREMIUM,
  // e não no Equilibrado que é o default do resto do app, porque aqui a economia sai cara: o plano
  // é UMA geração de texto contra N imagens no render, e é a copy que decide se o post funciona.
  // Poupar na headline pra depois gastar 9 imagens num carrossel morno é trocar o barato pelo caro.
  //
  // "Profundo" e não "Avançado": em 03/08/2026 a linha do Avançado estava recusando as chamadas no
  // provedor e a geração caía calada na reserva — o cliente pagava o tier caro e recebia o de
  // baixo. Premium só vale se ENTREGAR; quando a linha voltar, é uma palavra aqui. O seletor
  // mostra ⚠️ nos modelos instáveis e continua aceitando qualquer tier.
  const [textModel, setTextModel] = useState("txt-profundo");
  // Modelo de IMAGEM do render. O servidor JÁ aceitava `model` (imageT2IModel lê do request) — o
  // que faltava era a tela oferecer a escolha: o carrossel é a peça que mais gasta imagem (N
  // slides por clique) e era a única que não deixava escolher o motor.
  const [imageModels, setImageModels] = useState<{ slug: string; display_name: string; cost_credits: number | null; real_name?: string; qualities?: unknown[]; origem?: string }[]>([]);
  const [imageModel, setImageModel] = useState("");
  const [busy, setBusy] = useState<"" | "plano" | "render" | "video">("");
  const [msg, setMsg] = useState("");
  const [plano, setPlano] = useState<Plano | null>(null);
  const pollRef = useRef<ReturnType<typeof setInterval> | null>(null);

  const parar = useCallback(() => {
    if (pollRef.current) { clearInterval(pollRef.current); pollRef.current = null; }
  }, []);
  useEffect(() => parar, [parar]);

  // Só os t2i: o render do slide parte do texto. O i2i entra sozinho no servidor quando o slide
  // herda a capa — e ali a escolha é do backend, não desta lista.
  useEffect(() => {
    sfetch("/api/gen-models?kind=image").then((r) => r.json()).then((j) => {
      const list = (Array.isArray(j) ? j : j?.data ?? []).filter((m: { subtype?: string }) => m.subtype === "text_to_image");
      setImageModels(list);
      // Default = o MAIS BARATO, igual ao card de Imagem. Aqui pesa mais: um clique = N slides,
      // então estrear no caro multiplica a conta por N sem ninguém ter pedido.
      const barato = [...list].sort((a: { cost_credits: number | null }, b: { cost_credits: number | null }) =>
        (a.cost_credits ?? 1e9) - (b.cost_credits ?? 1e9))[0];
      if (barato) setImageModel((cur) => cur || barato.slug);
    }).catch(() => {});
  }, []);

  // Polling do rascunho: o plano e o render são assíncronos (fila), então a tela lê o estado real
  // em vez de adivinhar pelo tempo. Para sozinho quando não há mais nada em andamento.
  const acompanhar = useCallback((did: string) => {
    parar();
    let n = 0;
    pollRef.current = setInterval(async () => {
      n++;
      const r = await sfetch(`/api/studio/draft?id=${did}`).then((x) => x.json()).catch(() => null);
      const c: Plano | null = r?.ok ? (r.draft.carousel ?? null) : null;
      if (c) {
        setPlano(c);
        onMedia?.();
        const gerando = c.status === "generating";
        const renderizando = (c.slides ?? []).some((s) => s.status === "rendering");
        if (!gerando && !renderizando) {
          parar(); setBusy("");
          setMsg(c.status === "error" ? `❌ ${c.error || "falhou"}` : "✅ Pronto.");
          return;
        }
        if (gerando) setMsg("✍️ Escrevendo o carrossel — headline, narrativa e direção de arte…");
        else {
          const prontos = (c.slides ?? []).filter((s) => s.slide_url).length;
          setMsg(`🎨 Renderizando… ${prontos}/${(c.slides ?? []).length} slides prontos`);
        }
      }
      if (n > 120) { parar(); setBusy(""); setMsg("⏳ Está demorando — a Central de Tarefas (🔔 no topo) avisa quando terminar, mesmo se você sair desta tela."); }
    }, 4000);
  }, [parar, onMedia]);

  async function gerarPlano() {
    if (!topic.trim()) { setMsg("❌ Descreva o tema ou cole o conteúdo."); return; }
    setBusy("plano"); setMsg("✍️ Escrevendo o carrossel…");
    const r = await sfetch("/api/studio/carousel", {
      method: "POST", headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ draftId: draftId || undefined, topic: topic.trim(), slides, format, mode, textModel: textModel || undefined }),
    });
    const d = await r.json();
    if (!d.ok) { setBusy(""); setMsg("❌ " + (d.message || d.error)); return; }
    onDraft?.(String(d.draftId));
    acompanhar(String(d.draftId));
  }

  async function renderizar(only?: number) {
    const did = draftId || "";
    if (!did) { setMsg("❌ Gere o plano primeiro."); return; }
    setBusy("render"); setMsg("🎨 Enfileirando o render…");
    const r = await sfetch("/api/studio/carousel-render", {
      method: "POST", headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ draftId: did, tone, ...(imageModel ? { model: imageModel } : {}), ...(only !== undefined ? { only } : {}) }),
    });
    const d = await r.json();
    if (!d.ok) { setBusy(""); setMsg("❌ " + (d.message || d.error)); return; }
    setMsg("🎨 " + d.message);
    acompanhar(did);
  }

  // 🎬 Junta os slides prontos num vídeo (o backend /studio/carousel-video já existia e nunca
  // teve botão: o carrossel virava vídeo só por quem chamasse a API na mão).
  async function virarVideo() {
    const did = draftId || "";
    if (!did) { setMsg("❌ Gere o plano primeiro."); return; }
    setBusy("video"); setMsg("🎬 Montando o vídeo do carrossel…");
    const r = await sfetch("/api/studio/carousel-video", {
      method: "POST", headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ draftId: did, narration: false }),
    });
    const d = await r.json();
    if (!d.ok) { setBusy(""); setMsg("❌ " + (d.message || d.error)); return; }
    setMsg("🎬 " + (d.message || "Vídeo em montagem — aparece na galeria."));
    acompanhar(did);
  }

  const lista = plano?.slides ?? [];
  const temPlano = lista.length > 0 && plano?.status !== "generating";
  const custo = lista.length;

  return (
    <div style={{ ...card, borderColor: "rgba(99,102,241,.4)", display: "flex", flexDirection: "column", gap: 12 }}>
      <strong style={{ fontSize: "1rem", color: "var(--peach)" }}>🎠 Carrossel</strong>
      <p className="txt" style={{ color: "var(--muted)", margin: "-4px 0 0", fontSize: ".82rem" }}>
        Um tema vira uma peça editorial: <strong>headline</strong>, arquitetura narrativa de {slides} slides e direção de arte por slide.
        Você <strong>lê e edita o plano antes</strong> — o render só acontece depois, e é ele que gasta imagem.
        O texto sai num <strong>modelo premium</strong> por padrão: é a copy que decide se o post funciona.
      </p>

      <textarea
        value={topic} onChange={(e) => setTopic(e.target.value)} rows={3}
        placeholder="Ex.: bicicletas elétricas em São Paulo — ou cole aqui um texto, uma transcrição, um rascunho"
        style={{ width: "100%", resize: "vertical", padding: 8, borderRadius: 8, border: "1px solid var(--line)", background: "transparent", color: "var(--fg)", fontSize: ".85rem", boxSizing: "border-box" }}
      />

      <div style={{ display: "flex", gap: 14, flexWrap: "wrap", alignItems: "flex-end" }}>
        <div style={{ display: "flex", flexDirection: "column", gap: 4 }}>
          <label className="txt" style={{ color: "var(--muted)", fontSize: ".78rem" }}>Slides</label>
          <select value={slides} onChange={(e) => setSlides(Number(e.target.value))} style={sel} title="9 é o padrão: capa, gancho, mecanismo, prova, virada, prática, direção e assinatura.">
            <option value={5}>5 — curto</option>
            <option value={7}>7 — médio</option>
            <option value={9}>9 — padrão</option>
            <option value={12}>12 — longo</option>
          </select>
        </div>
        <div style={{ display: "flex", flexDirection: "column", gap: 4 }}>
          <label className="txt" style={{ color: "var(--muted)", fontSize: ".78rem" }}>Formato</label>
          <select value={format} onChange={(e) => setFormat(e.target.value)} style={sel} title="Retrato 4:5 é o formato de feed que mais ocupa a tela no Instagram.">
            <option value="retrato">🖼️ 4:5 retrato</option>
            <option value="feed">🖼️ 1:1 quadrado</option>
            <option value="story">🖼️ 9:16 story</option>
            <option value="paisagem">🖼️ 16:9 paisagem</option>
          </select>
        </div>
        <div style={{ display: "flex", flexDirection: "column", gap: 4 }}>
          <label className="txt" style={{ color: "var(--muted)", fontSize: ".78rem" }}>Fundo</label>
          <select value={tone} onChange={(e) => setTone(e.target.value)} style={sel} title="O registro tonal do feed. Escuro é escolha, não padrão: escurecer uma marca de feed claro é o erro mais comum do formato.">
            <option value="light">☀️ Claro</option>
            <option value="dark">🌙 Escuro</option>
          </select>
        </div>
        <TextModelSelect value={textModel} onChange={setTextModel} label="Modelo do texto"
          title="Quem ESCREVE o carrossel: headline, narrativa e direção de arte. O plano custa uma geração de texto — é o lugar mais barato pra pagar por qualidade, porque a copy decide se o post funciona." />
        {/* Quem DESENHA os slides. Separado do modelo de texto de propósito: um escreve, o outro
            renderiza, e o custo mora aqui — um clique de render = N imagens. */}
        <SeletorModeloImagem modelos={imageModels} valor={imageModel} onChange={setImageModel} estilo={sel}
          rotulo="Modelo da imagem" />
        <div style={{ display: "flex", flexDirection: "column", gap: 4 }}>
          <label className="txt" style={{ color: "var(--muted)", fontSize: ".78rem" }}>Acabamento</label>
          <select value={mode} onChange={(e) => setMode(e.target.value)} style={sel} title="Editorial: a IA gera o fundo e o texto é composto por cima — tipografia perfeita e igual em todos os slides. Arte-total: a IA escreve o texto dentro da imagem (mais livre, mais sujeito a erro de ortografia).">
            <option value="editorial">✒️ Editorial (recomendado)</option>
            <option value="arte-total">🎨 Arte-total (avançado)</option>
          </select>
        </div>
      </div>

      <div style={{ display: "flex", gap: 8, flexWrap: "wrap", alignItems: "center" }}>
        <button className="btn ok" style={{ flex: "none", padding: "9px 14px" }} disabled={busy !== ""} onClick={gerarPlano}>
          {busy === "plano" ? "✍️ Escrevendo…" : "✍️ Escrever o carrossel"}
        </button>
        {temPlano && (
          <button className="btn" style={{ flex: "none", padding: "9px 14px" }} disabled={busy !== ""} onClick={() => renderizar()}
            title={`Gera ${custo} imagens — uma por slide. A capa sai primeiro e vira a referência visual das outras.`}>
            {busy === "render" ? "🎨 Renderizando…" : `🎨 Renderizar ${custo} slides`}
          </button>
        )}
        {/* Slides prontos viram VÍDEO — mesma lógica do "juntar" do Motion: a peça existe em
            pedaços e alguém precisa montá-la. Só aparece quando há slide renderizado. */}
        {temPlano && lista.some((sl) => sl.image_url) && (
          <button className="btn edit" style={{ flex: "none", padding: "9px 14px" }} disabled={busy !== ""} onClick={virarVideo}
            title="Monta os slides já renderizados num vídeo, na ordem. Não gera imagem nova.">
            {busy === "video" ? "🎬 Montando…" : "🎬 Juntar num vídeo"}
          </button>
        )}
        {temPlano && (
          <span className="txt" style={{ fontSize: ".76rem", color: "var(--muted)" }}>
            Custo do render: <strong style={{ color: "var(--text)" }}>{custo} imagens</strong> (1 por slide).
          </span>
        )}
      </div>

      {msg && <p className="txt" style={{ fontSize: ".82rem", margin: 0, color: "var(--muted)" }}>{msg}</p>}

      {temPlano && (
        <div style={{ display: "flex", flexDirection: "column", gap: 10, borderTop: "1px solid var(--line)", paddingTop: 12 }}>
          <div>
            <div className="txt" style={{ fontSize: ".7rem", color: "var(--muted)", textTransform: "uppercase", letterSpacing: ".08em" }}>
              Headline{plano?.family ? ` · ${plano.family}` : ""}{plano?.axis ? ` · ${plano.axis}` : ""}
            </div>
            <div style={{ fontSize: "1.02rem", fontWeight: 800, marginTop: 3 }}>{plano?.headline}</div>
          </div>

          <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fill, minmax(220px, 1fr))", gap: 10 }}>
            {lista.map((s, i) => (
              <div key={i} style={{ border: "1px solid var(--line)", borderRadius: 10, padding: 10, background: "var(--bg2)", display: "flex", flexDirection: "column", gap: 6 }}>
                <div style={{ display: "flex", alignItems: "center", gap: 6 }}>
                  <span style={{ fontSize: ".62rem", fontWeight: 800, letterSpacing: ".06em", textTransform: "uppercase", color: "var(--peach)" }}>
                    {PAPEIS[s.role] || s.role}
                  </span>
                  {s.tag && <span className="txt" style={{ fontSize: ".62rem", color: "var(--muted)" }}>{s.tag}</span>}
                  {s.status === "rendering" && <span className="txt" style={{ marginLeft: "auto", fontSize: ".62rem", color: "var(--muted)" }}>⏳</span>}
                  {s.status === "error" && <span title={s.error} style={{ marginLeft: "auto", fontSize: ".62rem", color: "#ef4444" }}>❌</span>}
                </div>
                {s.slide_url ? (
                  // eslint-disable-next-line @next/next/no-img-element
                  <img src={s.slide_url} alt="" style={{ width: "100%", borderRadius: 6, display: "block" }} />
                ) : null}
                {(s.blocks || []).map((b, j) => (
                  <p key={j} className="txt" style={{ margin: 0, fontSize: j === 0 ? ".84rem" : ".76rem", fontWeight: j === 0 ? 700 : 400, color: j === 0 ? "var(--text)" : "var(--muted)" }}>{b}</p>
                ))}
                {s.slide_url || s.status === "error" ? (
                  // Re-render POR SLIDE: consertar um slide fraco não deve custar o carrossel inteiro.
                  <button className="btn edit" style={{ padding: "4px 0", fontSize: ".72rem" }} disabled={busy !== ""} onClick={() => renderizar(i)}
                    title="Gera de novo só este slide, usando a capa já pronta como referência (1 imagem).">
                    🔁 Refazer este slide
                  </button>
                ) : null}
              </div>
            ))}
          </div>

          {plano?.caption && (
            <div>
              <div className="txt" style={{ fontSize: ".7rem", color: "var(--muted)", textTransform: "uppercase", letterSpacing: ".08em" }}>Legenda</div>
              <p className="txt" style={{ whiteSpace: "pre-wrap", margin: "3px 0 0", fontSize: ".82rem" }}>{plano.caption}</p>
            </div>
          )}
        </div>
      )}
    </div>
  );
}
