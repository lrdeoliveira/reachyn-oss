"use client";

import { sfetch } from "@/lib/api";
import { useEffect, useState } from "react";

// Arquivo de publicações: o backend (GET /api/publications) devolve o snapshot permanente
// do que foi ao ar. Multi-tenant: o endpoint filtra por tenant_id no banco (+ global scope
// do trait BelongsToTenant), então só vem publicação do tenant logado.
type Network = { platform: string; ok: boolean; post_id: string | null; url: string | null; published_at: string | null };
type Media = { kind: string; url: string };
type Item = {
  id: number;
  keyword: string;
  status: string;
  networks: Network[];
  thumb: Media | null;
  media_count: number;
  published_at: string | null;
};

const STATUS: Record<string, { label: string; color: string }> = {
  publicado: { label: "🚀 publicado", color: "var(--green)" },
  parcial: { label: "◐ parcial", color: "var(--amber)" },
  falhou: { label: "✕ falhou", color: "var(--muted)" },
};

// Rótulo curto da rede (espelha ZernioService::NETWORKS no backend).
const NET_LABEL: Record<string, string> = {
  instagram: "Instagram", facebook: "Facebook", linkedin: "LinkedIn",
  tiktok: "TikTok", youtube: "YouTube", twitter: "X", threads: "Threads",
  pinterest: "Pinterest", blog: "Blog",
};

const isVideo = (kind: string) => kind !== "image";

function fmtDate(iso: string | null) {
  if (!iso) return "";
  try {
    return new Date(iso).toLocaleDateString("pt-BR", { day: "2-digit", month: "short", year: "numeric" });
  } catch {
    return "";
  }
}

export default function PublicacoesPage() {
  const [items, setItems] = useState<Item[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    sfetch("/api/publications")
      .then((r) => r.json())
      .then((d) => setItems(d.items ?? []))
      .catch(() => setItems([]))
      .finally(() => setLoading(false));
  }, []);

  return (
    <>
      <h1 className="h1">Publicações</h1>
      <p className="sub">Tudo que você já publicou nas suas redes.</p>

      {loading ? (
        <div className="empty">Carregando…</div>
      ) : items.length === 0 ? (
        <div className="empty">Nenhuma publicação ainda. Aprove ou publique uma peça pra ela aparecer aqui.</div>
      ) : (
        <div className="grid">
          {items.map((it) => {
            const st = STATUS[it.status] ?? { label: it.status, color: "var(--muted)" };
            return (
              <a key={it.id} href={`/publicacoes/${it.id}`} className="card" style={{ textDecoration: "none", color: "inherit", display: "flex", flexDirection: "column" }}>
                <div style={{ aspectRatio: "1 / 1", background: "var(--bg2)", display: "flex", alignItems: "center", justifyContent: "center", overflow: "hidden" }}>
                  {it.thumb ? (
                    it.thumb.kind === "image" ? (
                      <img src={it.thumb.url} loading="lazy" alt={it.keyword || "publicação"} style={{ width: "100%", height: "100%", objectFit: "cover" }} />
                    ) : isVideo(it.thumb.kind) ? (
                      <video src={it.thumb.url} preload="metadata" style={{ width: "100%", height: "100%", objectFit: "cover" }} />
                    ) : (
                      <span style={{ color: "var(--muted)", fontSize: ".8rem" }}>sem preview</span>
                    )
                  ) : (
                    <span style={{ color: "var(--muted)", fontSize: ".8rem" }}>sem mídia</span>
                  )}
                </div>
                <div style={{ padding: "12px 14px", display: "flex", flexDirection: "column", gap: 6 }}>
                  <span style={{ fontWeight: 600, fontSize: ".9rem", overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap" }}>
                    {it.keyword || "(sem tema)"}
                  </span>
                  <div style={{ display: "flex", flexWrap: "wrap", gap: 6 }}>
                    {it.networks.map((n, i) => (
                      <span key={i} className="tag" style={{ opacity: n.ok ? 1 : 0.45 }}>
                        {n.ok ? "✓" : "✕"} {NET_LABEL[n.platform] ?? n.platform}
                      </span>
                    ))}
                  </div>
                  <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginTop: 2 }}>
                    <span style={{ color: "var(--muted)", fontSize: ".78rem" }}>{fmtDate(it.published_at)}</span>
                    <span style={{ color: st.color, fontSize: ".8rem", fontWeight: 700 }}>{st.label}</span>
                  </div>
                </div>
              </a>
            );
          })}
        </div>
      )}
    </>
  );
}
