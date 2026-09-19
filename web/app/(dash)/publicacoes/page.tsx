"use client";

import { sfetch } from "@/lib/api";
import { SkeletonCards } from "@/components/ui/Spinner";
import { useToast } from "@/components/ui/Toast";
import { NET } from "@/components/NetworkPreview";
import { useCallback, useEffect, useRef, useState } from "react";

// Arquivo de publicações: o backend (GET /api/publications) devolve o snapshot permanente
// do que foi ao ar, paginado (24/página) e filtrável por rede/status/busca. Multi-tenant:
// o endpoint filtra por tenant_id no banco (+ global scope BelongsToTenant).
type Network = { platform: string; ok: boolean };
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

// Rótulo curto da rede (espelha conector socialService::NETWORKS no backend).
const NET_LABEL: Record<string, string> = {
  instagram: "Instagram", facebook: "Facebook", linkedin: "LinkedIn",
  tiktok: "TikTok", youtube: "YouTube", twitter: "X", threads: "Threads",
  pinterest: "Pinterest", reddit: "Reddit", bluesky: "Bluesky", googlebusiness: "Google Business", blog: "Blog",
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
  const toast = useToast();
  const [items, setItems] = useState<Item[]>([]);
  const [loading, setLoading] = useState(true);
  const [page, setPage] = useState(1);
  const [hasMore, setHasMore] = useState(false);
  const [total, setTotal] = useState(0);
  // filtros
  const [q, setQ] = useState("");
  const [network, setNetwork] = useState("");
  const [status, setStatus] = useState("");
  // seleção múltipla p/ excluir (mesmo padrão da Galeria)
  const [selected, setSelected] = useState<Set<number>>(new Set());
  const [deleting, setDeleting] = useState(false);
  const debounce = useRef<ReturnType<typeof setTimeout> | null>(null);

  const load = useCallback(async (p: number, append: boolean, qv: string, net: string, st: string) => {
    setLoading(true);
    try {
      const params = new URLSearchParams({ page: String(p) });
      if (qv.trim()) params.set("q", qv.trim());
      if (net) params.set("network", net);
      if (st) params.set("status", st);
      const r = await sfetch(`/api/publications?${params.toString()}`);
      const d = await r.json();
      setItems((prev) => (append ? [...prev, ...(d.items ?? [])] : (d.items ?? [])));
      setHasMore(!!d.has_more);
      setTotal(d.total ?? 0);
      setPage(p);
    } catch {
      if (!append) setItems([]);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { load(1, false, "", "", ""); }, [load]);

  // filtros: rede/status recarregam na hora; busca com debounce de 400ms
  function applyNetwork(n: string) { const v = n === network ? "" : n; setNetwork(v); setSelected(new Set()); load(1, false, q, v, status); }
  function applyStatus(s: string) { const v = s === status ? "" : s; setStatus(v); setSelected(new Set()); load(1, false, q, network, v); }
  function applyQ(v: string) {
    setQ(v);
    if (debounce.current) clearTimeout(debounce.current);
    debounce.current = setTimeout(() => { setSelected(new Set()); load(1, false, v, network, status); }, 400);
  }

  function toggleSelect(id: number) {
    setSelected((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id); else next.add(id);
      return next;
    });
  }

  // Excluir selecionadas: remove SÓ os registros no Reachyn (os posts continuam no ar).
  async function removeSelected() {
    if (selected.size === 0 || deleting) return;
    if (!confirm(`Remover ${selected.size} publicação(ões) só do Reachyn?\n\nOs posts já publicados continuam no ar nas redes — isso apaga apenas os registros aqui no painel.`)) return;
    setDeleting(true);
    try {
      const r = await sfetch("/api/publications/bulk-delete", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ ids: Array.from(selected) }),
      });
      if (!r.ok) { toast.err(`Não consegui remover agora (erro ${r.status}). Tente de novo.`); return; }
      setSelected(new Set());
      await load(1, false, q, network, status);
    } catch {
      toast.err("Não consegui remover agora. Tente de novo.");
    } finally {
      setDeleting(false);
    }
  }

  return (
    <>
      <h1 className="h1">Publicações</h1>
      <p className="sub">Tudo que você já publicou nas suas redes.{total > 0 ? ` ${total} publicação(ões).` : ""}</p>

      {/* Filtros: busca + chips por rede e por status */}
      <div style={{ display: "flex", flexDirection: "column", gap: 10, marginBottom: 16 }}>
        <input
          placeholder="🔎 Buscar por tema…"
          value={q}
          onChange={(e) => applyQ(e.target.value)}
          style={{ maxWidth: 360, width: "100%", background: "var(--bg2)", color: "var(--text)", border: "1px solid var(--line)", borderRadius: 10, padding: "10px 14px", fontSize: ".95rem" }}
        />
        <div style={{ display: "flex", flexWrap: "wrap", gap: 6, alignItems: "center" }}>
          <span style={{ color: "var(--muted)", fontSize: ".78rem", marginRight: 2 }}>Rede:</span>
          {Object.entries(NET_LABEL).map(([key, label]) => (
            <button key={key} type="button" className={network === key ? "chip on" : "chip"} onClick={() => applyNetwork(key)}>{label}</button>
          ))}
        </div>
        <div style={{ display: "flex", flexWrap: "wrap", gap: 6, alignItems: "center" }}>
          <span style={{ color: "var(--muted)", fontSize: ".78rem", marginRight: 2 }}>Status:</span>
          {Object.entries(STATUS).map(([key, st]) => (
            <button key={key} type="button" className={status === key ? "chip on" : "chip"} onClick={() => applyStatus(key)}>{st.label}</button>
          ))}
        </div>
      </div>

      {/* Barra de seleção múltipla (aparece quando há itens marcados) */}
      {selected.size > 0 && (
        <div style={{ display: "flex", alignItems: "center", gap: 12, marginBottom: 14, background: "var(--panel)", border: "1px solid var(--line)", borderRadius: 10, padding: "8px 14px" }}>
          <span style={{ fontSize: ".85rem", fontWeight: 600 }}>{selected.size} selecionada(s)</span>
          <button className="btn no" style={{ flex: "none", padding: "6px 12px", fontSize: ".82rem" }} disabled={deleting} onClick={removeSelected}
            title="Remove só os registros no Reachyn; os posts continuam no ar nas redes">
            {deleting ? "Removendo…" : "🗑 Excluir selecionadas"}
          </button>
          <button className="btn" style={{ flex: "none", padding: "6px 12px", fontSize: ".82rem" }} onClick={() => setSelected(new Set())}>
            Limpar seleção
          </button>
        </div>
      )}

      {loading && items.length === 0 ? (
        <SkeletonCards n={6} mediaH={140} />
      ) : items.length === 0 ? (
        <div className="empty">{q || network || status ? "Nenhuma publicação com esses filtros." : "Nenhuma publicação ainda. Aprove ou publique uma peça pra ela aparecer aqui."}</div>
      ) : (
        <>
          <div className="grid">
            {items.map((it) => {
              const st = STATUS[it.status] ?? { label: it.status, color: "var(--muted)" };
              const checked = selected.has(it.id);
              return (
                <a key={it.id} href={`/publicacoes/${it.id}`} className="card" style={{ textDecoration: "none", color: "inherit", display: "flex", flexDirection: "column", position: "relative", outline: checked ? "2px solid var(--green)" : "none" }}>
                  {/* checkbox de seleção múltipla (não navega) */}
                  <span
                    role="checkbox"
                    aria-checked={checked}
                    onClick={(e) => { e.preventDefault(); e.stopPropagation(); toggleSelect(it.id); }}
                    style={{
                      position: "absolute", top: 8, left: 8, zIndex: 2, width: 24, height: 24, borderRadius: 6,
                      display: "flex", alignItems: "center", justifyContent: "center", cursor: "pointer",
                      background: checked ? "var(--green)" : "rgba(0,0,0,.45)",
                      border: "1px solid " + (checked ? "var(--green)" : "rgba(255,255,255,.5)"),
                      color: "#fff", fontSize: ".8rem", fontWeight: 800,
                    }}
                  >
                    {checked ? "✓" : ""}
                  </span>
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
                      {it.networks.length === 0 ? (
                        <span style={{ color: "var(--muted)", fontSize: ".74rem", fontStyle: "italic" }}>sem redes registradas</span>
                      ) : it.networks.map((n, i) => {
                        // Pílula visível por rede (dot com a cor da marca + ✓/✕). Substitui a
                        // classe `.tag` (texto cinza apagado) que sumia no card escuro.
                        const color = NET[n.platform]?.color ?? "var(--line)";
                        return (
                          <span key={i} style={{
                            display: "inline-flex", alignItems: "center", gap: 5,
                            padding: "3px 9px", borderRadius: 999, fontSize: ".72rem", fontWeight: 600,
                            border: "1px solid var(--line)", background: "var(--bg2)",
                            color: n.ok ? "var(--text)" : "var(--muted)",
                          }}>
                            <span style={{ width: 7, height: 7, borderRadius: "50%", background: color, flex: "none" }} />
                            {NET_LABEL[n.platform] ?? n.platform}
                            <span style={{ color: n.ok ? "var(--green)" : "var(--muted)", fontWeight: 800 }}>{n.ok ? "✓" : "✕"}</span>
                          </span>
                        );
                      })}
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
          {hasMore && (
            <div style={{ display: "flex", justifyContent: "center", marginTop: 18 }}>
              <button className="btn" disabled={loading} onClick={() => load(page + 1, true, q, network, status)}>
                {loading ? "Carregando…" : "Carregar mais"}
              </button>
            </div>
          )}
        </>
      )}
    </>
  );
}
