"use client";

import { sfetch } from "@/lib/api";
import { NetworkPreviewTabs } from "@/components/NetworkPreview";
import { useParams } from "next/navigation";
import { useEffect, useState } from "react";

// Detalhe de uma publicação (snapshot permanente). Multi-tenant: o backend valida que
// a publicação pertence ao tenant logado (403 caso contrário) — o cliente nunca abre a de outro.
// `text`/`media` por rede = o que foi publicado NAQUELA rede (o detalhe alterna por aba).
type Network = { platform: string; ok: boolean; post_id: string | null; url: string | null; published_at: string | null; detail?: string | null; text?: string | null; media?: Media[] | null };
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
  const [deleting, setDeleting] = useState(false);
  const [active, setActive] = useState(""); // rede ativa nas abas de conteúdo

  useEffect(() => {
    if (!id) return;
    sfetch(`/api/publications/${id}`)
      .then((r) => (r.ok ? r.json() : Promise.reject()))
      .then((d) => setItem(d.item ?? null))
      .catch(() => setErro(true))
      .finally(() => setLoading(false));
  }, [id]);

  // Excluir SÓ do Reachyn: remove o registro do arquivo; os posts continuam no ar nas redes.
  async function excluir() {
    if (!id || deleting) return;
    if (!confirm("Remover esta publicação só do Reachyn?\n\nOs posts já publicados continuam no ar nas redes sociais — isso apaga apenas o registro aqui no painel.")) return;
    setDeleting(true);
    try {
      const r = await sfetch(`/api/publications/${id}`, { method: "DELETE" });
      if (r.ok) { window.location.href = "/publicacoes"; return; }
      setDeleting(false);
      alert("Não consegui remover agora. Tente de novo.");
    } catch {
      setDeleting(false);
      alert("Não consegui remover agora. Tente de novo.");
    }
  }

  if (loading) return <div className="empty">Carregando…</div>;
  if (erro || !item) return <div className="empty">Publicação não encontrada.</div>;

  const st = STATUS[item.status] ?? { label: item.status, color: "var(--muted)" };

  return (
    <>
      <a href="/publicacoes" className="sub" style={{ textDecoration: "none" }}>← Publicações</a>
      <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", gap: 12, marginTop: 4 }}>
        <h1 className="h1" style={{ margin: 0 }}>{item.keyword || "(sem tema)"}</h1>
        <div style={{ display: "flex", alignItems: "center", gap: 12 }}>
          <span style={{ color: st.color, fontSize: ".9rem", fontWeight: 700 }}>{st.label}</span>
          <button className="btn no" style={{ flex: "none", padding: "6px 12px", fontSize: ".82rem" }} disabled={deleting} onClick={excluir}
            title="Remove só o registro no Reachyn; os posts continuam no ar nas redes">
            {deleting ? "Removendo…" : "🗑 Excluir"}
          </button>
        </div>
      </div>
      <p className="sub">Publicada em {fmtDate(item.published_at) || "—"}</p>

      <div style={{ display: "flex", flexDirection: "column", gap: 20 }}>
        {/* Conteúdo publicado POR REDE — abas pra alternar e ver o texto+mídia de cada rede.
            Fallback (publicações antigas, sem conteúdo por rede): mídia geral + texto único. */}
        {(() => {
          const platforms = item.networks.map((n) => n.platform).filter(Boolean);
          const hasPerNetwork = item.networks.some((n) => (n.text && n.text.trim()) || (n.media && n.media.length));
          if (hasPerNetwork && platforms.length > 0) {
            const cur = platforms.includes(active) ? active : platforms[0];
            const texts = Object.fromEntries(item.networks.map((n) => [n.platform, n.text || ""]));
            const medias = item.networks.find((n) => n.platform === cur)?.media || [];
            return (
              <div>
                <div className="navgroup">Conteúdo publicado</div>
                <NetworkPreviewTabs platforms={platforms} active={cur} onActive={setActive} texts={texts} medias={medias} />
              </div>
            );
          }
          return (
            <>
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
              {item.content_text && (
                <div>
                  <div className="navgroup">Texto publicado</div>
                  <div style={{ background: "var(--panel)", border: "1px solid var(--line)", borderRadius: 10, padding: "14px 16px", whiteSpace: "pre-wrap", lineHeight: 1.5 }}>
                    {item.content_text}
                  </div>
                </div>
              )}
            </>
          );
        })()}

        {/* Redes onde foi publicado (status + link pro post, se houver) */}
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
