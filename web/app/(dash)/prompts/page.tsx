"use client";

import { sfetch } from "@/lib/api";
import { SkeletonCards } from "@/components/ui/Spinner";
import { useEffect, useRef, useState } from "react";

type Prompt = { id: number; title: string; kind?: string | null; content: string; updated_at?: string };

// CATEGORIA — organiza a biblioteca em seções (pedido do operador): personagens (locks
// salvos), roteiristas (Histórias), diretores (Filme), estilos (personas de imagem/vídeo)
// e o resto (gerais). Estilo vem do campo `kind`, não do prefixo: é dado, e o título é
// editável pelo cliente. Os demais seguem por prefixo, como já era.
const catOfPrompt = (p: Prompt) => {
  if (p.kind) return "estilo";
  const t = (p.title || "").trim();
  if (t.startsWith("🎬 Roteirista:")) return "roteirista";
  if (t.startsWith("🎥 Diretor:")) return "diretor";
  if (t.startsWith("🎭") || t.toLowerCase().startsWith("personagem")) return "personagem";
  return "geral";
};
const PCATS: { k: string; lbl: string }[] = [
  { k: "", lbl: "Tudo" },
  { k: "geral", lbl: "📝 Prompts" },
  { k: "personagem", lbl: "🎭 Personagens" },
  { k: "roteirista", lbl: "🎬 Roteiristas" },
  { k: "diretor", lbl: "🎥 Diretores" },
  { k: "estilo", lbl: "🎨 Estilos" },
];
const CAT_TITLES: Record<string, string> = { geral: "📝 Prompts", personagem: "🎭 Personagens", roteirista: "🎬 Roteiristas (Histórias)", diretor: "🎥 Diretores (Filme)", estilo: "🎨 Estilos (personas de imagem e vídeo)" };

export default function PromptsPage() {
  const [items, setItems] = useState<Prompt[]>([]);
  const [loading, setLoading] = useState(true);
  const [q, setQ] = useState("");
  const [catFilter, setCatFilter] = useState(""); // "" = todas as seções
  const [msg, setMsg] = useState<string | null>(null);
  // editor: id null = criando novo; id preenchido = editando.
  const [editId, setEditId] = useState<number | null>(null);
  const [title, setTitle] = useState("");
  const [content, setContent] = useState("");
  const [busy, setBusy] = useState(false);
  const [open, setOpen] = useState(false);
  const editorRef = useRef<HTMLDivElement | null>(null);

  // 🗣️ Voz da Marca (tenant): tom/vocabulário/restrições PREFIXADOS em toda geração de texto
  // (posts por rede, resumos, roteiro das Histórias, plano/locução do Filme).
  const [brandVoice, setBrandVoice] = useState("");
  const [bvOpen, setBvOpen] = useState(false);
  const [bvBusy, setBvBusy] = useState(false);
  const [bvSaved, setBvSaved] = useState(false);

  // 🎨 Brand Kit visual (tenant): cor/CTA/logo/@ usados pelo compositor de posts ("Vestir com a marca").
  const [bkPrimary, setBkPrimary] = useState("#E23744");
  const [bkInk, setBkInk] = useState("#141414");
  const [bkHandle, setBkHandle] = useState("");
  const [bkLogo, setBkLogo] = useState("");
  const [bkName, setBkName] = useState("");
  const [bkOpen, setBkOpen] = useState(false);
  const [bkBusy, setBkBusy] = useState(false);

  async function load() {
    setLoading(true);
    try {
      const r = await sfetch("/api/prompts");
      const d = await r.json();
      setItems(Array.isArray(d) ? d : []);
    } catch { setItems([]); }
    setLoading(false);
  }
  useEffect(() => { load(); }, []);
  useEffect(() => {
    sfetch("/api/studio/brand-voice").then((r) => r.json()).then((d) => {
      if (d?.ok) { setBrandVoice(d.brand_voice || ""); setBvSaved(!!(d.brand_voice || "").trim()); }
    }).catch(() => {});
    sfetch("/api/studio/brand-kit").then((r) => r.json()).then((d) => {
      if (d?.ok && d.brand_kit) {
        setBkPrimary(d.brand_kit.primary || "#E23744");
        setBkInk(d.brand_kit.ink || "#141414");
        setBkHandle(d.brand_kit.handle || "");
        setBkLogo(d.brand_kit.logoUrl || "");
        setBkName(d.brand_kit.name || "");
      }
    }).catch(() => {});
  }, []);

  async function salvarVozDaMarca() {
    if (bvBusy) return;
    setBvBusy(true); setMsg(null);
    try {
      const r = await sfetch("/api/studio/brand-voice", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ brand_voice: brandVoice }) });
      const d = await r.json();
      if (!r.ok || !d?.ok) { setMsg(`❌ Não consegui salvar a Voz da Marca (erro ${r.status}).`); return; }
      setBrandVoice(d.brand_voice || "");
      setBvSaved(!!(d.brand_voice || "").trim());
      setMsg("✅ Voz da Marca salva — ela passa a assinar todos os textos gerados.");
    } catch { setMsg("❌ Não consegui salvar a Voz da Marca agora."); }
    setBvBusy(false);
  }

  async function salvarBrandKit() {
    if (bkBusy) return;
    setBkBusy(true); setMsg(null);
    try {
      const r = await sfetch("/api/studio/brand-kit", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ brand_primary: bkPrimary, brand_ink: bkInk, brand_handle: bkHandle, brand_logo_url: bkLogo }) });
      const d = await r.json();
      if (!r.ok || !d?.ok) { setMsg(`❌ Não consegui salvar o Brand Kit (erro ${r.status}).`); return; }
      if (d.brand_kit) { setBkPrimary(d.brand_kit.primary); setBkInk(d.brand_kit.ink); setBkHandle(d.brand_kit.handle || ""); setBkLogo(d.brand_kit.logoUrl || ""); }
      setMsg("✅ Brand Kit salvo — os posts compostos passam a usar essa identidade.");
    } catch { setMsg("❌ Não consegui salvar o Brand Kit agora."); }
    setBkBusy(false);
  }

  function novo() {
    setEditId(null); setTitle(""); setContent(""); setOpen(true); setMsg(null);
    setTimeout(() => editorRef.current?.scrollIntoView({ behavior: "smooth", block: "center" }), 50);
  }
  function editar(p: Prompt) {
    setEditId(p.id); setTitle(p.title); setContent(p.content || ""); setOpen(true); setMsg(null);
    setTimeout(() => editorRef.current?.scrollIntoView({ behavior: "smooth", block: "center" }), 50);
  }
  function fechar() { setOpen(false); setEditId(null); setTitle(""); setContent(""); }

  async function salvar() {
    if (!content.trim()) { setMsg("❌ Escreva o conteúdo do prompt."); return; }
    setBusy(true); setMsg(null);
    try {
      const body = JSON.stringify({ title: title.trim(), content });
      const r = editId
        ? await sfetch(`/api/prompts/${editId}`, { method: "PATCH", headers: { "Content-Type": "application/json" }, body })
        : await sfetch("/api/prompts", { method: "POST", headers: { "Content-Type": "application/json" }, body });
      const d = await r.json();
      if (!r.ok || !d?.ok) { setMsg("❌ " + (d?.message || "não foi possível salvar")); setBusy(false); return; }
      const saved = d.prompt as Prompt;
      setItems((prev) => editId ? prev.map((x) => (x.id === saved.id ? saved : x)) : [saved, ...prev]);
      setMsg(editId ? "✅ Prompt atualizado." : "✅ Prompt salvo.");
      fechar();
    } catch { setMsg("❌ não foi possível salvar agora."); }
    setBusy(false);
  }

  async function excluir(p: Prompt) {
    if (!confirm(`Excluir o prompt "${p.title}"?`)) return;
    setItems((prev) => prev.filter((x) => x.id !== p.id));
    await sfetch(`/api/prompts/${p.id}`, { method: "DELETE" }).catch(() => {});
    if (editId === p.id) fechar();
  }

  function copiar(p: Prompt) {
    navigator.clipboard?.writeText(p.content || "").then(() => {
      setMsg(`📋 "${p.title}" copiado!`);
      setTimeout(() => setMsg((m) => (m?.startsWith("📋") ? null : m)), 1500);
    }).catch(() => {});
  }

  const filtered = items.filter((p) => {
    if (catFilter && catOfPrompt(p) !== catFilter) return false;
    const s = q.trim().toLowerCase();
    return !s || p.title.toLowerCase().includes(s) || (p.content || "").toLowerCase().includes(s);
  });

  const inp = { background: "var(--bg2)", color: "var(--text)", border: "1px solid var(--line)", borderRadius: 10, padding: "10px 12px", fontSize: ".95rem", width: "100%" } as const;

  return (
    <>
      <div style={{ display: "flex", alignItems: "flex-start", justifyContent: "space-between", gap: 16, flexWrap: "wrap" }}>
        <div>
          <h1 className="h1">Prompts</h1>
          <p className="sub">Sua biblioteca de prompts — crie, edite, salve e copie pra reusar.</p>
        </div>
        <button className="btn ok" style={{ flex: "0 0 auto", padding: "9px 16px" }} onClick={novo}>+ Novo prompt</button>
      </div>

      {/* 🗣️ VOZ DA MARCA — colapsada por padrão; expande pra editar */}
      <div style={{ ...card, marginTop: 14, display: "flex", flexDirection: "column", gap: 8 }}>
        <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", gap: 10, flexWrap: "wrap" }}>
          <div>
            <strong style={{ fontSize: ".95rem" }}>🗣️ Voz da Marca {bvSaved && <span style={{ fontSize: ".72rem", color: "var(--green, #22c55e)", border: "1px solid currentColor", borderRadius: 10, padding: "1px 8px", marginLeft: 6 }}>ativa</span>}</strong>
            <p className="sub" style={{ margin: "4px 0 0", fontSize: ".8rem" }}>Tom, vocabulário e restrições da SUA marca — aplicados automaticamente em todo texto gerado: posts, resumos, roteiros e locuções.</p>
          </div>
          <button className="btn edit" style={{ flex: "none", padding: "7px 14px", fontSize: ".82rem" }} onClick={() => setBvOpen((o) => !o)}>{bvOpen ? "Recolher" : bvSaved ? "✏️ Editar" : "＋ Definir"}</button>
        </div>
        {bvOpen && (
          <>
            <textarea value={brandVoice} onChange={(e) => setBrandVoice(e.target.value)} maxLength={1200}
              placeholder={"Ex.: Fale como a [marca]: tom acolhedor e direto, sempre 'você', frases curtas. Nunca usar gírias nem promessas de resultado. Palavras da marca: 'praticidade', 'sem complicação'. Proibido: 'barato', 'grátis'."}
              style={{ background: "var(--bg2)", color: "var(--text)", border: "1px solid var(--line)", borderRadius: 10, padding: "10px 12px", fontSize: ".9rem", width: "100%", minHeight: 110, lineHeight: 1.5, resize: "vertical" }} />
            <div style={{ display: "flex", gap: 8, alignItems: "center", flexWrap: "wrap" }}>
              <button className="btn ok" style={{ flex: "none", padding: "8px 16px" }} disabled={bvBusy} onClick={salvarVozDaMarca}>{bvBusy ? "Salvando…" : "💾 Salvar Voz da Marca"}</button>
              <span className="txt" style={{ color: "var(--muted)", fontSize: ".76rem" }}>{brandVoice.length}/1200 · deixe em branco e salve pra desativar</span>
            </div>
          </>
        )}
      </div>

      {/* 🎨 BRAND KIT VISUAL — cor/logo/@ usados pelo compositor de posts ("Vestir com a marca") */}
      <div style={{ ...card, marginTop: 14, display: "flex", flexDirection: "column", gap: 8 }}>
        <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", gap: 10, flexWrap: "wrap" }}>
          <div>
            <strong style={{ fontSize: ".95rem" }}>🎨 Brand Kit visual</strong>
            <p className="sub" style={{ margin: "4px 0 0", fontSize: ".8rem" }}>Cor, logo e @ da SUA marca — aplicados quando você usa “Vestir com a marca” numa imagem da Mídia (título, logo e CTA sobre o post).</p>
          </div>
          <button className="btn edit" style={{ flex: "none", padding: "7px 14px", fontSize: ".82rem" }} onClick={() => setBkOpen((o) => !o)}>{bkOpen ? "Recolher" : "✏️ Editar"}</button>
        </div>
        {bkOpen && (
          <>
            <div style={{ display: "flex", gap: 16, flexWrap: "wrap", alignItems: "center" }}>
              <label className="txt" style={{ display: "flex", alignItems: "center", gap: 8, fontSize: ".85rem" }}>Cor da marca
                <input type="color" value={bkPrimary} onChange={(e) => setBkPrimary(e.target.value)} style={{ width: 44, height: 30, border: "1px solid var(--line)", borderRadius: 8, background: "none", cursor: "pointer" }} />
              </label>
              <label className="txt" style={{ display: "flex", alignItems: "center", gap: 8, fontSize: ".85rem" }}>Texto do botão
                <input type="color" value={bkInk} onChange={(e) => setBkInk(e.target.value)} style={{ width: 44, height: 30, border: "1px solid var(--line)", borderRadius: 8, background: "none", cursor: "pointer" }} />
              </label>
              <div style={{ display: "flex", alignItems: "center", gap: 10, marginLeft: "auto" }}>
                {bkLogo
                  ? <img src={bkLogo} alt="" style={{ width: 40, height: 40, borderRadius: 10, objectFit: "cover" }} />
                  : <div style={{ width: 40, height: 40, borderRadius: 10, background: bkPrimary, color: "#fff", display: "flex", alignItems: "center", justifyContent: "center", fontWeight: 800 }}>{(bkName || "R").charAt(0).toUpperCase()}</div>}
                <div style={{ fontSize: ".8rem", lineHeight: 1.3 }}><strong>{bkName || "Sua marca"}</strong><br /><span className="txt" style={{ color: "var(--muted)" }}>{bkHandle || "@suamarca"}</span></div>
              </div>
            </div>
            <input value={bkHandle} onChange={(e) => setBkHandle(e.target.value)} placeholder="@ da marca (ex: @auroracafe)" maxLength={40} style={inp} />
            <input value={bkLogo} onChange={(e) => setBkLogo(e.target.value)} placeholder="URL do logo (opcional — precisa estar na sua Mídia)" style={inp} />
            <div style={{ display: "flex", gap: 8, alignItems: "center", flexWrap: "wrap" }}>
              <button className="btn ok" style={{ flex: "none", padding: "8px 16px" }} disabled={bkBusy} onClick={salvarBrandKit}>{bkBusy ? "Salvando…" : "💾 Salvar Brand Kit"}</button>
              <span className="txt" style={{ color: "var(--muted)", fontSize: ".76rem" }}>a cor vira o selo/etiqueta/CTA dos posts compostos</span>
            </div>
          </>
        )}
      </div>

      {open && (
        <div ref={editorRef} style={{ ...card, marginTop: 14, display: "flex", flexDirection: "column", gap: 10, borderColor: "rgba(34,197,94,.4)" }}>
          <strong style={{ fontSize: ".95rem", color: "var(--peach)" }}>{editId ? "Editar prompt" : "Novo prompt"}</strong>
          <input value={title} onChange={(e) => setTitle(e.target.value)} placeholder="Título (ex: Estilo pixel — herói)" style={inp} />
          <textarea value={content} onChange={(e) => setContent(e.target.value)} placeholder="Escreva o prompt aqui…" style={{ ...inp, minHeight: 160, lineHeight: 1.5, fontFamily: "ui-monospace, SFMono-Regular, Menlo, monospace", resize: "vertical" }} />
          <div style={{ display: "flex", gap: 8, flexWrap: "wrap" }}>
            <button className="btn ok" style={{ flex: "none", padding: "9px 16px" }} disabled={busy || !content.trim()} onClick={salvar}>{busy ? "Salvando…" : "💾 Salvar"}</button>
            <button className="btn edit" style={{ flex: "none", padding: "9px 14px" }} onClick={fechar}>Cancelar</button>
          </div>
        </div>
      )}

      {items.length > 0 && (
        <div style={{ marginTop: 16, display: "flex", gap: 10, alignItems: "center", flexWrap: "wrap" }}>
          <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="🔎 Buscar por título ou conteúdo…" style={{ ...inp, maxWidth: 420, width: "auto", flex: "1 1 260px" }} />
          {PCATS.map(({ k, lbl }) => {
            const n = k === "" ? items.length : items.filter((it) => catOfPrompt(it) === k).length;
            const on = catFilter === k;
            return (
              <button key={k || "all"} type="button" onClick={() => setCatFilter(k)} style={{
                padding: "8px 15px", borderRadius: 999, fontSize: ".82rem", fontWeight: 700, cursor: "pointer",
                border: "1px solid " + (on ? "var(--red)" : "var(--line)"),
                background: on ? "var(--red)" : "var(--bg2)", color: on ? "#fff" : "var(--text)" }}>
                {lbl} ({n})
              </button>
            );
          })}
        </div>
      )}

      {loading ? (
        <SkeletonCards n={6} mediaH={0} />
      ) : items.length === 0 ? (
        <div className="empty">Nenhum prompt salvo ainda. Clique em <strong>+ Novo prompt</strong> pra criar o primeiro. ✨</div>
      ) : (
        <div style={{ display: "flex", flexDirection: "column", gap: 8, marginTop: 14 }}>
          {(["personagem", "roteirista", "diretor", "estilo", "geral"] as const).filter((c) => (!catFilter || catFilter === c) && filtered.some((p) => catOfPrompt(p) === c)).map((c) => (
            <div key={c} style={{ display: "flex", flexDirection: "column", gap: 10 }}>
              <h2 className="txt" style={{ margin: "10px 0 0", fontSize: ".85rem", color: "var(--muted)", textTransform: "uppercase", letterSpacing: ".06em" }}>{CAT_TITLES[c]} ({filtered.filter((p) => catOfPrompt(p) === c).length})</h2>
              <div style={{ display: "grid", gap: 14, gridTemplateColumns: "repeat(auto-fill, minmax(min(300px, 100%), 1fr))" }}>
                {filtered.filter((p) => catOfPrompt(p) === c).map((p) => (
            <div key={p.id} className="card" style={{ display: "flex", flexDirection: "column", gap: 8, padding: 14 }}>
              <strong style={{ fontSize: ".95rem", overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap" }}>{p.title || "(sem título)"}</strong>
              <p className="txt" style={{ color: "var(--muted)", fontSize: ".82rem", lineHeight: 1.5, margin: 0, whiteSpace: "pre-wrap", maxHeight: 120, overflow: "hidden", maskImage: "linear-gradient(180deg,#000 65%,transparent)", WebkitMaskImage: "linear-gradient(180deg,#000 65%,transparent)" }}>{p.content}</p>
              <div style={{ display: "flex", gap: 6, flexWrap: "wrap", marginTop: 2 }}>
                <button className="btn ok" style={{ flex: "none", padding: "6px 12px", fontSize: ".82rem" }} onClick={() => copiar(p)}>📋 Copiar</button>
                <button className="btn edit" style={{ flex: "none", padding: "6px 12px", fontSize: ".82rem" }} onClick={() => editar(p)}>✏️ Editar</button>
                <button className="btn no" style={{ flex: "none", padding: "6px 12px", fontSize: ".82rem" }} onClick={() => excluir(p)}>🗑️ Excluir</button>
              </div>
            </div>
                ))}
              </div>
            </div>
          ))}
          {filtered.length === 0 && <div className="empty">Nenhum prompt encontrado{q ? ` pra “${q}”` : ""}.</div>}
        </div>
      )}

      {msg && <p className="txt" style={{ marginTop: 16, fontSize: "1rem" }}>{msg}</p>}
    </>
  );
}

const card = { background: "var(--panel)", border: "1px solid var(--line)", borderRadius: 14, padding: 16 } as const;
