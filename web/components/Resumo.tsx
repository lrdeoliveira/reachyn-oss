"use client";

import Link from "next/link";
import { useEffect, useRef, useState } from "react";
import { sfetch } from "@/lib/api";
import { TextModelSelect } from "@/components/TextModelSelect";

// Aba "Resumo": edição do texto de referência (research.summary) do rascunho ativo.
// Vive separado do Conteúdo de propósito — este textarea cresce com o texto, e quando
// ficava no topo do editor ele empurrava a página e jogava o scroll pro topo a cada
// troca de aba/geração. A IA usa esta referência pra gerar o texto das redes e os
// prompts de imagem/vídeo na etapa de Mídia. Opera sobre o mesmo rascunho do wizard.
const KEY = "reachyn_draft";

export function Resumo() {
  const [draftId, setDraftId] = useState<string | null>(null);
  const [keyword, setKeyword] = useState("");
  const [reference, setReference] = useState("");
  const [busy, setBusy] = useState(false);
  const [textModel, setTextModel] = useState(""); // seletor de modelo do resumo
  const [saved, setSaved] = useState(false);
  const [msg, setMsg] = useState<string | null>(null);
  const [loaded, setLoaded] = useState(false);
  // ↻ Regerar com roteirista: reusa as FONTES já pesquisadas do rascunho (sem nova pesquisa).
  const [hasSources, setHasSources] = useState(false);
  const [roteiristas, setRoteiristas] = useState<{ name: string; content: string }[]>([]);
  const [roteirista, setRoteirista] = useState("");
  const ref = useRef<HTMLTextAreaElement>(null);

  useEffect(() => {
    const id = typeof window !== "undefined" ? localStorage.getItem(KEY) : null;
    if (!id) { setLoaded(true); return; }
    sfetch(`/api/studio/draft?id=${id}`).then((r) => r.json()).then((d) => {
      if (d.ok) {
        setDraftId(d.draft.id); setKeyword(d.draft.keyword || "");
        setReference(d.draft.research?.summary || d.draft.research?.answer || "");
        setHasSources(Array.isArray(d.draft.research?.results) && d.draft.research.results.length > 0);
      } else localStorage.removeItem(KEY);
      setLoaded(true);
    }).catch(() => setLoaded(true));
  }, []);

  // Auto-resize controlado: roda só quando o texto (ou o load) muda — NÃO a cada render.
  // Como é a única coisa que cresce nesta página, redimensionar aqui não puxa o scroll.
  useEffect(() => {
    const el = ref.current;
    if (el) { el.style.height = "auto"; el.style.height = Math.max(220, el.scrollHeight) + "px"; }
  }, [reference, loaded]);

  // Roteiristas da aba Prompts ("🎬 Roteirista: …") — o 📄 Resumidor Executivo vive aqui.
  useEffect(() => {
    sfetch("/api/prompts").then((r) => r.json()).then((d) => {
      if (Array.isArray(d)) {
        setRoteiristas(d
          .filter((p: { title?: string }) => (p.title || "").startsWith("🎬 Roteirista:"))
          .map((p: { title: string; content: string }) => ({ name: p.title.replace("🎬 Roteirista:", "").trim(), content: p.content || "" })));
      }
    }).catch(() => {});
  }, []);

  const go = (path: string) => { window.location.href = path; };

  async function salvar() {
    if (!draftId) return;
    setBusy(true); setMsg(null);
    try {
      const r = await sfetch("/api/studio/research", { method: "PATCH", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ draftId, summary: reference }) });
      const d = await r.json();
      if (d.ok) { setSaved(true); setTimeout(() => setSaved(false), 2000); }
      else setMsg("❌ " + (d.error || "não salvou o resumo"));
    } catch { setMsg("❌ não foi possível salvar o resumo."); }
    setBusy(false);
  }
  function novo() { localStorage.removeItem(KEY); go("/"); }

  // ↻ Regera o resumo com o roteirista escolhido, reusando as fontes já coletadas.
  async function regerar() {
    if (!draftId || busy) return;
    setBusy(true); setMsg("↻ Regerando o resumo…");
    try {
      const r = await sfetch("/api/studio/resummarize", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ draftId, persona: roteirista, textModel: textModel || undefined }) });
      const d = await r.json();
      if (d.ok && d.research) { setReference(d.research.summary || d.research.answer || ""); setMsg("✅ Resumo regenerado — revise e salve."); }
      else setMsg("❌ " + (d.error || "não foi possível regerar o resumo"));
    } catch { setMsg("❌ não foi possível regerar o resumo agora."); }
    setBusy(false);
  }

  const card = { background: "var(--panel)", border: "1px solid var(--line)", borderRadius: 12, padding: 16 } as const;

  if (!loaded) return <p className="sub">Carregando rascunho...</p>;
  if (!draftId) return (
    <>
      <h1 className="h1">Resumo</h1>
      <div className="empty">Nenhum rascunho ativo. Comece em <Link href="/" style={{ color: "var(--peach)" }}>Pesquisar</Link> — ou use o botão <strong>&quot;Criar sem pesquisa&quot;</strong> lá pra ir direto pro editor.</div>
    </>
  );

  return (
    <>
      <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center" }}>
        <h1 className="h1">Resumo{keyword ? <span style={{ color: "var(--muted)", fontWeight: 400, fontSize: "1rem" }}> · {keyword}</span> : ""}</h1>
        <p className="sub" style={{ marginBottom: 14 }}>Edite o resumo da pesquisa (é a base do conteúdo e da mídia) — ou regere com outro estilo/modelo.</p>
        <div style={{ display: "flex", gap: 8 }}>
          <button className="btn ok" style={{ flex: "none", padding: "7px 14px" }} disabled={busy} onClick={salvar}>{busy ? "Salvando…" : saved ? "✓ Salvo" : "💾 Salvar resumo"}</button>
          <button className="btn edit" style={{ flex: "none", padding: "7px 13px" }} onClick={novo}>+ Novo</button>
        </div>
      </div>

      {/* Texto de referência — base pra gerar o texto das redes e os prompts de mídia */}
      <div style={{ ...card, marginTop: 14 }}>
        <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", gap: 10, flexWrap: "wrap" }}>
          <strong style={{ fontSize: ".95rem" }}>📝 Texto de referência</strong>
          {hasSources && (
            <div style={{ display: "flex", gap: 8, alignItems: "center", flexWrap: "wrap" }}>
              {roteiristas.length > 0 && (
                <select value={roteirista} onChange={(e) => setRoteirista(e.target.value)}
                  style={{ background: "var(--bg2)", color: "var(--text)", border: "1px solid var(--line)", borderRadius: 10, padding: "7px 11px", fontSize: ".8rem", maxWidth: 260 }}
                  title="Estilo do resumo (ex.: 📄 Resumidor Executivo) — roteiristas da aba Prompts">
                  <option value="">🎬 Estilo padrão</option>
                  {roteiristas.map((rt, i) => <option key={i} value={rt.content}>{rt.name}</option>)}
                </select>
              )}
              <TextModelSelect value={textModel} onChange={setTextModel} />
              <button className="btn edit" style={{ flex: "none", padding: "7px 13px", fontSize: ".82rem" }} disabled={busy} onClick={regerar}
                title="Regera o resumo com o estilo escolhido, reusando as fontes já pesquisadas — sem nova pesquisa">
                {busy ? "Regerando…" : "↻ Regerar resumo"}
              </button>
            </div>
          )}
        </div>
        <p className="txt" style={{ color: "var(--muted)", fontSize: ".8rem", margin: "6px 0 8px" }}>A IA usa esta referência pra gerar o texto das redes <strong>e os prompts de imagem/vídeo</strong> na etapa de Mídia. Cole um briefing/notas ou escreva o ângulo do conteúdo. <em>Sem isso, a Mídia não tem de onde tirar o prompt.</em></p>
        <textarea ref={ref} value={reference} onChange={(e) => setReference(e.target.value)} placeholder="Ex: lançamento da v2 do produto, foco em economia de tempo, tom otimista, público dev…"
          style={{ background: "var(--bg2)", color: "var(--text)", border: "1px solid var(--line)", borderRadius: 10, padding: "12px 14px", fontSize: ".9rem", width: "100%", minHeight: 220, overflow: "hidden", resize: "none", lineHeight: 1.5 }} />
      </div>

      <div style={{ display: "flex", gap: 10, flexWrap: "wrap", marginTop: 14 }}>
        <button className="btn edit" style={{ flex: "none", padding: "10px 18px" }} onClick={async () => { await salvar(); go("/editar"); }}>Salvar e ir pro Conteúdo →</button>
      </div>

      {msg && <p className="txt" style={{ marginTop: 16, fontSize: "1rem" }}>{msg}</p>}
    </>
  );
}
