"use client";

import { sfetch } from "@/lib/api";
import { useEffect, useState } from "react";

type P = { id: number; title: string; content: string; kind?: string | null };

// Modal pra escolher um prompt salvo (aba Prompts) e carregá-lo num campo. Reutilizável.
export function PromptPicker({ onPick, onClose }: { onPick: (content: string, p: P) => void; onClose: () => void }) {
  const [items, setItems] = useState<P[]>([]);
  const [q, setQ] = useState("");
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    sfetch("/api/prompts").then((r) => r.json()).then((d) => setItems(Array.isArray(d) ? d : [])).catch(() => setItems([])).finally(() => setLoading(false));
  }, []);

  // Personas de estilo (kind image/video) ficam de fora: têm select próprio na geração, e
  // aqui só atrapalhariam quem procura um prompt de texto. Editar/criar: aba Prompts.
  const filtered = items.filter((p) => {
    if (p.kind) return false;
    const s = q.trim().toLowerCase();
    return !s || p.title.toLowerCase().includes(s) || (p.content || "").toLowerCase().includes(s);
  });

  return (
    <div onClick={onClose} style={{ position: "fixed", inset: 0, zIndex: 60, background: "rgba(0,0,0,.7)", display: "flex", alignItems: "center", justifyContent: "center", padding: 20 }}>
      <div onClick={(e) => e.stopPropagation()} style={{ background: "var(--panel)", border: "1px solid var(--line)", borderRadius: 14, padding: 18, width: "min(720px,95vw)", maxHeight: "85vh", overflow: "auto" }}>
        <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: 12, gap: 10 }}>
          <strong style={{ fontSize: "1.05rem", color: "var(--peach)" }}>Carregar dos Prompts</strong>
          <a href="/prompts" className="btn edit" style={{ flex: "none", padding: "6px 12px", textDecoration: "none" }}>Gerenciar →</a>
        </div>
        <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="🔎 Buscar…" style={{ background: "var(--bg2)", color: "var(--text)", border: "1px solid var(--line)", borderRadius: 10, padding: "9px 12px", width: "100%", marginBottom: 12 }} />
        {loading ? (
          <p className="txt" style={{ color: "var(--muted)" }}>Carregando…</p>
        ) : filtered.length === 0 ? (
          <p className="txt" style={{ color: "var(--muted)" }}>Nenhum prompt salvo ainda. Crie na aba <a href="/prompts" style={{ color: "var(--peach)" }}>Prompts</a> (ou salve este personagem).</p>
        ) : (
          <div style={{ display: "flex", flexDirection: "column", gap: 8 }}>
            {filtered.map((p) => (
              <button key={p.id} onClick={() => onPick(p.content || "", p)} style={{ textAlign: "left", background: "var(--bg2)", border: "1px solid var(--line)", borderRadius: 10, padding: "10px 12px", cursor: "pointer", color: "var(--text)" }}>
                <strong style={{ fontSize: ".9rem", display: "block", marginBottom: 4 }}>{p.title || "(sem título)"}</strong>
                <span className="txt" style={{ color: "var(--muted)", fontSize: ".8rem", display: "block", whiteSpace: "pre-wrap", maxHeight: 60, overflow: "hidden" }}>{p.content}</span>
              </button>
            ))}
          </div>
        )}
        <div style={{ display: "flex", justifyContent: "flex-end", marginTop: 12 }}>
          <button className="btn edit" style={{ flex: "none", padding: "7px 14px" }} onClick={onClose}>Fechar</button>
        </div>
      </div>
    </div>
  );
}
