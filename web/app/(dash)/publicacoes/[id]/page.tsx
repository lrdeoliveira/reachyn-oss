"use client";

import Link from "next/link";
import { sfetch } from "@/lib/api";
import { useToast } from "@/components/ui/Toast";
import { NetworkPreviewTabs, VideoWithMeta } from "@/components/NetworkPreview";
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
  pinterest: "Pinterest", reddit: "Reddit", bluesky: "Bluesky", googlebusiness: "Google Business", blog: "Blog",
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
  const toast = useToast();
  const params = useParams<{ id: string }>();
  const id = params?.id;
  const [item, setItem] = useState<Item | null>(null);
  const [loading, setLoading] = useState(true);
  const [erro, setErro] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [retrying, setRetrying] = useState(false);
  const [republishing, setRepublishing] = useState(false);
  const [retryMsg, setRetryMsg] = useState("");
  const [active, setActive] = useState(""); // rede ativa nas abas de conteúdo
  const [pickOpen, setPickOpen] = useState(false); // painel "publicar em outras redes"
  const [pick, setPick] = useState<string[]>([]);   // redes escolhidas p/ repostar
  const [reposting, setReposting] = useState(false);

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
      toast.err("Não consegui remover agora. Tente de novo.");
    } catch {
      setDeleting(false);
      toast.err("Não consegui remover agora. Tente de novo.");
    }
  }

  // 🔁 Reposta SÓ as redes que falharam, com o snapshot salvo (texto+mídia daquela rede).
  async function repostar() {
    if (!id || retrying) return;
    setRetrying(true);
    setRetryMsg("");
    try {
      const r = await sfetch(`/api/publications/${id}/retry`, { method: "POST" });
      const d = await r.json().catch(() => ({}));
      if (!r.ok || !d.ok) {
        setRetryMsg(d.error || `Não consegui repostar agora (erro ${r.status}).`);
        return;
      }
      setRetryMsg(`✓ ${d.reposted_ok} rede(s) publicada(s)` + (d.reposted_fail > 0 ? ` · ${d.reposted_fail} ainda com falha` : ""));
      // recarrega o snapshot atualizado (status + redes)
      const rr = await sfetch(`/api/publications/${id}`);
      if (rr.ok) { const dd = await rr.json(); setItem(dd.item ?? item); }
    } catch {
      setRetryMsg("Não consegui repostar agora. Tente de novo.");
    } finally {
      setRetrying(false);
    }
  }

  // ♻️ Republica TODAS as redes de novo (mesmo as que já estavam no ar), com o snapshot salvo.
  // Cria posts NOVOS nas redes — não remove os antigos. Confirma antes (é uma ação que duplica).
  async function republicar() {
    if (!id || republishing) return;
    if (!confirm("Republicar em TODAS as redes desta publicação?\n\nIsso cria posts NOVOS nas redes conectadas (os posts antigos continuam no ar). Útil pra postar de novo ou depois de reconectar contas.")) return;
    setRepublishing(true);
    setRetryMsg("");
    try {
      const r = await sfetch(`/api/publications/${id}/republish`, { method: "POST" });
      const d = await r.json().catch(() => ({}));
      if (!r.ok || !d.ok) {
        setRetryMsg(d.error || `Não consegui republicar agora (erro ${r.status}).`);
        return;
      }
      setRetryMsg(`✓ Republicado em ${d.reposted_ok} rede(s)` + (d.reposted_fail > 0 ? ` · ${d.reposted_fail} com falha` : ""));
      const rr = await sfetch(`/api/publications/${id}`);
      if (rr.ok) { const dd = await rr.json(); setItem(dd.item ?? item); }
    } catch {
      setRetryMsg("Não consegui republicar agora. Tente de novo.");
    } finally {
      setRepublishing(false);
    }
  }

  // ➕ Publica esta publicação nas REDES ESCOLHIDAS (inclui redes novas que não estavam no post).
  async function repostEscolhidas() {
    if (!id || reposting || pick.length === 0) return;
    setReposting(true); setRetryMsg("");
    try {
      const r = await sfetch(`/api/publications/${id}/repost`, { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ platforms: pick }) });
      const d = await r.json().catch(() => ({}));
      if (!r.ok || !d?.ok) { setRetryMsg(d?.error || `Não consegui publicar agora (erro ${r.status}).`); return; }
      setRetryMsg(`✓ Publicado em ${d.reposted_ok} rede(s)` + (d.reposted_fail > 0 ? ` · ${d.reposted_fail} com falha/sem conta` : ""));
      setPickOpen(false); setPick([]);
      const rr = await sfetch(`/api/publications/${id}`).then((x) => x.json()).catch(() => null);
      if (rr?.item) setItem(rr.item);
    } catch { setRetryMsg("Não consegui publicar agora. Tente de novo."); }
    finally { setReposting(false); }
  }
  const ALL_NETS: [string, string][] = [
    ["instagram", "Instagram"], ["facebook", "Facebook"], ["linkedin", "LinkedIn"], ["tiktok", "TikTok"],
    ["youtube", "YouTube"], ["twitter", "X / Twitter"], ["threads", "Threads"], ["pinterest", "Pinterest"],
    ["reddit", "Reddit"], ["bluesky", "Bluesky"], ["googlebusiness", "Google Business"],
  ];

  if (loading) return <div className="empty">Carregando…</div>;
  if (erro || !item) return <div className="empty">Publicação não encontrada.</div>;

  const st = STATUS[item.status] ?? { label: item.status, color: "var(--muted)" };
  const failedCount = item.networks.filter((n) => !n.ok).length;

  return (
    <>
      <Link href="/publicacoes" className="sub" style={{ textDecoration: "none" }}>← Publicações</Link>
      <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", gap: 12, marginTop: 4 }}>
        <h1 className="h1" style={{ margin: 0 }}>{item.keyword || "(sem tema)"}</h1>
        <div style={{ display: "flex", alignItems: "center", gap: 12 }}>
          <span style={{ color: st.color, fontSize: ".9rem", fontWeight: 700 }}>{st.label}</span>
          {failedCount > 0 && (
            <button className="btn" style={{ flex: "none", padding: "6px 12px", fontSize: ".82rem" }} disabled={retrying} onClick={repostar}
              title="Reposta só as redes que falharam, com o texto e a mídia salvos desta publicação">
              {retrying ? "Repostando…" : `🔁 Repostar ${failedCount} rede(s) com falha`}
            </button>
          )}
          <button className="btn" style={{ flex: "none", padding: "6px 12px", fontSize: ".82rem" }} disabled={republishing} onClick={republicar}
            title="Reposta TODAS as redes de novo (cria posts novos; os antigos continuam no ar)">
            {republishing ? "Republicando…" : "♻️ Republicar tudo"}
          </button>
          <button className="btn" style={{ flex: "none", padding: "6px 12px", fontSize: ".82rem" }} onClick={() => setPickOpen((o) => !o)}
            title="Publica esta publicação em redes escolhidas — inclusive redes novas que não estavam no post original">
            {pickOpen ? "Fechar" : "➕ Outras redes"}
          </button>
          <button className="btn no" style={{ flex: "none", padding: "6px 12px", fontSize: ".82rem" }} disabled={deleting} onClick={excluir}
            title="Remove só o registro no Reachyn; os posts continuam no ar nas redes">
            {deleting ? "Removendo…" : "🗑 Excluir"}
          </button>
        </div>
      </div>
      <p className="sub">Publicada em {fmtDate(item.published_at) || "—"}{retryMsg ? ` · ${retryMsg}` : ""}</p>

      {pickOpen && (
        <div style={{ background: "var(--panel)", border: "1px solid var(--line)", borderRadius: 12, padding: 16, marginBottom: 18, display: "flex", flexDirection: "column", gap: 12 }}>
          <strong>➕ Publicar em outras redes</strong>
          <p style={{ color: "var(--muted)", fontSize: ".82rem", margin: 0, lineHeight: 1.5 }}>Escolha as redes. As já usadas reaproveitam o texto e a mídia daquela rede; as <strong>novas</strong> ganham uma <strong>legenda gerada</strong> pra elas (a partir do tema desta publicação). Redes sem conta conectada são puladas. Cria posts novos.</p>
          <div style={{ display: "flex", flexWrap: "wrap", gap: 8 }}>
            {ALL_NETS.map(([k, label]) => {
              const on = pick.includes(k);
              return (
                <button key={k} type="button" onClick={() => setPick((s) => (on ? s.filter((x) => x !== k) : [...s, k]))}
                  style={{ padding: "7px 13px", fontSize: ".84rem", cursor: "pointer", borderRadius: 999, border: "1px solid " + (on ? "var(--red)" : "var(--line)"), background: on ? "var(--red)" : "transparent", color: on ? "#fff" : "var(--muted)", fontWeight: on ? 700 : 400 }}>
                  {label}
                </button>
              );
            })}
          </div>
          <div style={{ display: "flex", gap: 8 }}>
            <button className="btn ok" style={{ flex: "none", padding: "8px 16px" }} disabled={reposting || pick.length === 0} onClick={repostEscolhidas}>
              {reposting ? "Publicando…" : `🚀 Publicar em ${pick.length || 0} rede(s)`}
            </button>
            <button className="btn no" style={{ flex: "none", padding: "8px 16px" }} onClick={() => { setPickOpen(false); setPick([]); }}>Cancelar</button>
          </div>
        </div>
      )}

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
                <NetworkPreviewTabs platforms={platforms} active={cur} onActive={setActive} texts={texts} medias={medias} videoMeta />
              </div>
            );
          }
          return (
            <>
              {item.media.length > 0 && (
                <div style={{ display: "grid", gap: 12, gridTemplateColumns: "repeat(auto-fill, minmax(min(180px, 100%), 1fr))" }}>
                  {item.media.map((m, i) =>
                    // Vídeo: sem corte quadrado — mostra no formato real + selo de resolução/formato.
                    m.kind === "video" ? (
                      <VideoWithMeta key={i} src={m.url} radius={10} />
                    ) : (
                      <div key={i} style={{ aspectRatio: "1 / 1", background: "var(--bg2)", borderRadius: 10, overflow: "hidden", display: "flex", alignItems: "center", justifyContent: "center" }}>
                        {m.kind === "image" ? (
                          <img src={m.url} loading="lazy" alt={item.keyword} style={{ width: "100%", height: "100%", objectFit: "cover" }} />
                        ) : isVideo(m.kind) ? (
                          <video src={m.url} controls preload="metadata" style={{ width: "100%", height: "100%", objectFit: "cover" }} />
                        ) : (
                          <span style={{ color: "var(--muted)", fontSize: ".8rem" }}>sem preview</span>
                        )}
                      </div>
                    )
                  )}
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
