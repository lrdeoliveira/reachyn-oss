"use client";

import { sfetch } from "@/lib/api";
import { useParams } from "next/navigation";
import { useEffect, useState } from "react";

// Detalhe de uma publicação (snapshot permanente). Multi-tenant: o backend valida que
// a publicação pertence ao tenant logado (403 caso contrário) — o cliente nunca abre a de outro.
type Network = { platform: string; ok: boolean; post_id: string | null; url: string | null; published_at: string | null; detail?: string | null };
type Media = { kind: string; url: string };
type Item = {
  id: number;
  keyword: string;
  status: string;
  content_text: string | null;
  media: Media[];
  networks: Network[];
  published_at: string | null;
};

const STATUS: Record<string, { label: string; color: string }> = {
  publicado: { label: "🚀 publicado", color: "var(--green)" },
  parcial: { label: "◐ parcial", color: "var(--amber)" },
  falhou: { label: "✕ falhou", color: "var(--muted)" },
};

const NET_LABEL: Record<string, string> = {
  instagram: "Instagram", facebook: "Facebook", linkedin: "LinkedIn",
  tiktok: "TikTok", youtube: "YouTube", twitter: "X", threads: "Threads",
  pinterest: "Pinterest", blog: "Blog",
};

const isVideo = (kind: string) => kind !== "image";

function fmtDate(iso: string | null) {
  if (!iso) return "";
  try {
    return new Date(iso).toLocaleString("pt-BR", { day: "2-digit", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" });
  } catch {
    return "";
  }
}

export default function PublicacaoDetalhePage() {
  const params = useParams<{ id: string }>();
  const id = params?.id;
  const [item, setItem] = useState<Item | null>(null);
  const [loading, setLoading] = useState(true);
  const [erro, setErro] = useState(false);

  useEffect(() => {
    if (!id) return;
    sfetch(`/api/publications/${id}`)
      .then((r) => (r.ok ? r.json() : Promise.reject()))
      .then((d) => setItem(d.item ?? null))
      .catch(() => setErro(true))
      .finally(() => setLoading(false));
  }, [id]);

  if (loading) return <div className="empty">Carregando…</div>;
  if (erro || !item) return <div className="empty">Publicação não encontrada.</div>;

  const st = STATUS[item.status] ?? { label: item.status, color: "var(--muted)" };

  return (
    <>
      <a href="/publicacoes" className="sub" style={{ textDecoration: "none" }}>← Publicações</a>
      <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", gap: 12, marginTop: 4 }}>
        <h1 className="h1" style={{ margin: 0 }}>{item.keyword || "(sem tema)"}</h1>
        <span style={{ color: st.color, fontSize: ".9rem", fontWeight: 700 }}>{st.label}</span>
      </div>
      <p className="sub">Publicada em {fmtDate(item.published_at) || "—"}</p>

      <div style={{ display: "flex", flexDirection: "column", gap: 20 }}>
        {/* Mídia que foi ao ar */}
        {item.media.length > 0 && (
          <div style={{ display: "grid", gap: 12, gridTemplateColumns: "repeat(auto-fill, minmax(180px, 1fr))" }}>
            {item.media.map((m, i) => (
              <div key={i} style={{ aspectRatio: "1 / 1", background: "var(--bg2)", borderRadius: 10, overflow: "hidden", display: "flex", alignItems: "center", justifyContent: "center" }}>
                {m.kind === "image" ? (
                  <img src={m.url} loading="lazy" alt={item.keyword} style={{ width: "100%", height: "100%", objectFit: "cover" }} />
                ) : isVideo(m.kind) ? (
                  <video src={m.url} controls preload="metadata" style={{ width: "100%", height: "100%", objectFit: "cover" }} />
                ) : (
                  <span style={{ color: "var(--muted)", fontSize: ".8rem" }}>sem preview</span>
                )}
              </div>
            ))}
          </div>
        )}

        {/* Texto final publicado */}
        {item.content_text && (
          <div>
            <div className="navgroup">Texto publicado</div>
            <div style={{ background: "var(--panel)", border: "1px solid var(--line)", borderRadius: 10, padding: "14px 16px", whiteSpace: "pre-wrap", lineHeight: 1.5 }}>
              {item.content_text}
            </div>
          </div>
        )}

        {/* Redes onde foi publicado (com link pro post, se houver) */}
        <div>
          <div className="navgroup">Redes</div>
          <div style={{ display: "flex", flexDirection: "column", gap: 8 }}>
            {item.networks.map((n, i) => (
              <div key={i} style={{ display: "flex", justifyContent: "space-between", alignItems: "center", background: "var(--panel)", border: "1px solid var(--line)", borderRadius: 10, padding: "10px 14px" }}>
                <span style={{ fontWeight: 600 }}>
                  {n.ok ? "✓" : "✕"} {NET_LABEL[n.platform] ?? n.platform}
                  {!n.ok && n.detail && <span style={{ color: "var(--muted)", fontWeight: 400, fontSize: ".8rem" }}> — {n.detail}</span>}
                </span>
                {n.ok && n.url ? (
                  <a href={n.url} target="_blank" rel="noopener noreferrer" style={{ color: "var(--accent, var(--green))", fontSize: ".85rem", fontWeight: 700 }}>
                    ver post ↗
                  </a>
                ) : (
                  <span style={{ color: "var(--muted)", fontSize: ".8rem" }}>{fmtDate(n.published_at) || (n.ok ? "publicado" : "—")}</span>
                )}
              </div>
            ))}
          </div>
        </div>
      </div>
    </>
  );
}
