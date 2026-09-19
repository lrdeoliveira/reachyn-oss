"use client";

import { RichTextArea } from "@/components/RichTextArea";
import { Modal } from "@/components/ui/Modal";
import { SkeletonCards } from "@/components/ui/Spinner";
import { sfetch } from "@/lib/api";
import { useEffect, useState } from "react";

type Approval = {
  id: number;
  keyword: string;
  preview_text: string;
  image_url: string;
  video_url: string;
  status: string;
};

export default function AprovacoesPage() {
  const [items, setItems] = useState<Approval[]>([]);
  const [err, setErr] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  // Peça aberta para visualização (texto completo + mídia ampliada).
  const [view, setView] = useState<Approval | null>(null);

  async function load() {
    setLoading(true);
    try {
      const r = await sfetch("/api/approvals");
      const d = await r.json();
      setItems(Array.isArray(d) ? d.filter((a: Approval) => a.status === "pendente") : []);
      setErr(null);
    } catch (e) {
      setErr((e as Error).message);
    }
    setLoading(false);
  }

  useEffect(() => {
    load();
    const t = setInterval(load, 15000);
    return () => clearInterval(t);
  }, []);

  async function act(id: number, action: "approve" | "reject") {
    await sfetch(`/api/approvals/${id}/${action}`, { method: "POST" }).catch(() => {});
    setItems((prev) => prev.filter((i) => i.id !== id));
  }

  // Reflete a edição salva na lista (e no modal aberto), sem recarregar tudo.
  function onSaved(updated: Approval) {
    setItems((prev) => prev.map((i) => (i.id === updated.id ? { ...i, ...updated } : i)));
    setView((v) => (v && v.id === updated.id ? { ...v, ...updated } : v));
  }

  return (
    <>
      <h1 className="h1">Aprovações</h1>
      <p className="sub">Peças prontas esperando seu OK. Aprove para publicar nas redes, ou rejeite.</p>

      {err && <div className="err">Não consegui carregar a fila: {err}.</div>}
      {loading && items.length === 0 && !err && <SkeletonCards n={3} />}
      {!loading && items.length === 0 && !err && (
        <div className="empty">Nenhuma peça na fila agora. Crie conteúdo em Conteúdo/Mídia e envie para aprovação. ✨</div>
      )}

      <div className="grid">
        {items.map((it) => (
          <article key={it.id} className="card">
            {it.image_url ? (
              // eslint-disable-next-line @next/next/no-img-element
              <img className="media" src={it.image_url} alt={it.keyword} />
            ) : (
              <div className="media" />
            )}
            <div className="body">
              <span className="title">{it.keyword || "(sem tema)"}</span>
              <span className="badge">⏳ pendente</span>
              {it.preview_text && (
                <p className="txt" style={{ whiteSpace: "pre-wrap", maxHeight: 96, overflow: "hidden", maskImage: "linear-gradient(180deg,#000 60%,transparent)", WebkitMaskImage: "linear-gradient(180deg,#000 60%,transparent)" }}>{it.preview_text}</p>
              )}
              <button className="btn edit" style={{ alignSelf: "flex-start", padding: "5px 12px", marginTop: 2 }} onClick={() => setView(it)}>👁 Visualizar / editar</button>
            </div>
            <div className="acts">
              <button className="btn ok" onClick={() => act(it.id, "approve")}>Aprovar</button>
              <button className="btn no" onClick={() => act(it.id, "reject")}>Rejeitar</button>
            </div>
          </article>
        ))}
      </div>

      {view && <EditModal item={view} onClose={() => setView(null)} onSaved={onSaved} />}
    </>
  );
}

// Modal de visualizar + editar uma peça pendente: mídia ampliada + tema + texto
// (editor rico). Salva via PATCH /api/approvals/{id} e devolve a versão atualizada.
function EditModal({ item, onClose, onSaved }: { item: Approval; onClose: () => void; onSaved: (a: Approval) => void }) {
  const [keyword, setKeyword] = useState(item.keyword || "");
  const [text, setText] = useState(item.preview_text || "");
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<string | null>(null);
  const dirty = keyword !== (item.keyword || "") || text !== (item.preview_text || "");

  async function salvar() {
    setBusy(true); setMsg(null);
    try {
      const r = await sfetch(`/api/approvals/${item.id}`, {
        method: "PATCH",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ keyword, preview_text: text }),
      });
      const d = await r.json();
      if (!r.ok || !d?.ok) { setMsg("❌ " + (d?.message || "não foi possível salvar")); setBusy(false); return; }
      onSaved(d.approval as Approval);
      setMsg("✅ Alterações salvas.");
    } catch {
      setMsg("❌ não foi possível salvar agora.");
    }
    setBusy(false);
  }

  // S4: usa o <Modal> do kit — ganha Esc, foco preso e scroll lock de graça.
  return (
    <Modal onClose={onClose} label="Revisar peça" maxWidth={720}>
      <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: 12, gap: 10 }}>
        <strong style={{ fontSize: "1.05rem", color: "var(--peach)" }}>Revisar peça</strong>
        <div style={{ display: "flex", gap: 8 }}>
          <button className="btn ok" style={{ flex: "none", padding: "6px 14px" }} disabled={busy || !dirty} onClick={salvar}>{busy ? "Salvando…" : "💾 Salvar"}</button>
          <button className="btn edit" style={{ flex: "none", padding: "6px 12px" }} onClick={onClose}>Fechar</button>
        </div>
      </div>

      {item.image_url && <img src={item.image_url} alt={keyword} style={{ width: "100%", borderRadius: 10, marginBottom: 14, display: "block" }} />}
      {item.video_url && <video src={item.video_url} controls style={{ width: "100%", borderRadius: 10, marginBottom: 14 }} />}

      <label className="txt" style={{ color: "var(--muted)", fontSize: ".82rem", display: "block", marginBottom: 4 }}>Tema</label>
      <input value={keyword} onChange={(e) => setKeyword(e.target.value)} placeholder="tema da peça"
        style={{ background: "var(--bg2)", color: "var(--text)", border: "1px solid var(--line)", borderRadius: 10, padding: "10px 12px", fontSize: ".95rem", width: "100%", marginBottom: 12 }} />

      <label className="txt" style={{ color: "var(--muted)", fontSize: ".82rem", display: "block", marginBottom: 4 }}>Texto</label>
      <RichTextArea value={text} onChange={setText} placeholder="Texto da peça…" />

      {msg && <p className="txt" style={{ marginTop: 12, fontSize: ".95rem" }}>{msg}</p>}
    </Modal>
  );
}
