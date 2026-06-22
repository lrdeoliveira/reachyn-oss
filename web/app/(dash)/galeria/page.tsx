"use client";

import { sfetch } from "@/lib/api";
import { Trash2 } from "lucide-react";
import { useEffect, useState } from "react";

// Item da galeria: o backend (GET /api/media/list) já devolve a URL completa da mídia
// e a origem (keyword/draft). Multi-tenant: o endpoint filtra por tenant_id no banco
// (+ global scope do trait BelongsToTenant), então só vem mídia do tenant logado.
type Item = {
  id: string;
  kind: string;
  url: string;
  style: string | null;
  draft_id: number;
  keyword: string | null;
  date: string;
};

// Vídeo = qualquer kind que não seja imagem (video/premium/short/mp4...).
const isVideo = (kind: string) => kind !== "image";

// Host do nosso storage de mídia (via env, sem domínio hardcoded — white-label).
const MEDIA_HOST = process.env.NEXT_PUBLIC_MEDIA_HOST ?? "";

// Só o nosso storage carrega; o resto (link de provedor expirado, storage antigo) costuma
// estar morto. Marcamos esses como "indisponível" pra orientar a limpeza, mas mantemos o
// card com o botão excluir.
const isOwnMedia = (url: string) => !!MEDIA_HOST && url.includes(MEDIA_HOST);

// Placeholder visual quando a mídia não carrega — no estilo do tema (sem ícone do browser).
function Broken() {
  return (
    <div
      style={{
        width: "100%",
        height: "100%",
        display: "flex",
        flexDirection: "column",
        alignItems: "center",
        justifyContent: "center",
        gap: 8,
        color: "var(--muted)",
        background: "var(--bg2)",
      }}
    >
      <Trash2 size={22} strokeWidth={1.6} style={{ opacity: 0.6 }} />
      <span style={{ fontSize: ".78rem" }}>mídia indisponível</span>
    </div>
  );
}

export default function GaleriaPage() {
  const [items, setItems] = useState<Item[]>([]);
  const [loading, setLoading] = useState(true);
  // ids (draft_id-item.id) cuja mídia não carregou → mostramos o placeholder.
  const [broken, setBroken] = useState<Set<string>>(new Set());
  // ids em processo de exclusão (desabilita o botão).
  const [deleting, setDeleting] = useState<Set<string>>(new Set());
  // estado do "Limpar quebradas" em massa (desabilita o botão durante a chamada).
  const [cleaning, setCleaning] = useState(false);

  useEffect(() => {
    sfetch("/api/media/list")
      .then((r) => r.json())
      .then((d) => setItems(d.items ?? []))
      .catch(() => setItems([]))
      .finally(() => setLoading(false));
  }, []);

  const key = (it: Item) => `${it.draft_id}-${it.id}`;

  const markBroken = (it: Item) =>
    setBroken((prev) => {
      const next = new Set(prev);
      next.add(key(it));
      return next;
    });

  async function remove(it: Item) {
    if (!confirm("Excluir esta mídia?")) return;
    const k = key(it);
    setDeleting((prev) => new Set(prev).add(k));
    try {
      const r = await sfetch("/api/media/item", {
        method: "DELETE",
        body: JSON.stringify({ draft_id: it.draft_id, id: it.id }),
      });
      if (!r.ok) throw new Error("falha ao excluir");
      // Sucesso: remove o card do estado local (sem recarregar tudo).
      setItems((prev) => prev.filter((x) => key(x) !== k));
    } catch {
      alert("Não foi possível excluir a mídia. Tente novamente.");
    } finally {
      setDeleting((prev) => {
        const next = new Set(prev);
        next.delete(k);
        return next;
      });
    }
  }

  // Mídias mortas = URL fora do nosso storage (link de provedor expirado, storage antigo).
  // É a mesma marca "indisponível" que o front já mostra em cada card.
  const brokenCount = items.filter((it) => !isOwnMedia(it.url)).length;

  async function cleanBroken() {
    if (brokenCount === 0 || cleaning) return;
    if (!confirm(`Remover ${brokenCount} mídias indisponíveis?`)) return;
    setCleaning(true);
    try {
      const r = await sfetch("/api/media/clean-broken", { method: "POST" });
      if (!r.ok) throw new Error("falha ao limpar");
      const d = await r.json();
      // Sucesso: tira do estado local os itens mortos (sem recarregar tudo).
      setItems((prev) => prev.filter((x) => isOwnMedia(x.url)));
      alert(`${d.removed ?? brokenCount} mídias removidas.`);
    } catch {
      alert("Não foi possível limpar as mídias indisponíveis. Tente novamente.");
    } finally {
      setCleaning(false);
    }
  }

  return (
    <>
      <div style={{ display: "flex", alignItems: "flex-start", justifyContent: "space-between", gap: 16, flexWrap: "wrap" }}>
        <div>
          <h1 className="h1">Galeria</h1>
          <p className="sub">Toda a mídia gerada do seu workspace.</p>
        </div>
        {brokenCount > 0 && (
          <button
            type="button"
            onClick={cleanBroken}
            disabled={cleaning}
            title="Remover todas as mídias indisponíveis"
            className="btn no"
            style={{ flex: "0 0 auto", display: "inline-flex", alignItems: "center", gap: 8 }}
          >
            <Trash2 size={15} strokeWidth={1.8} />
            {cleaning ? "Limpando…" : `Limpar quebradas (${brokenCount})`}
          </button>
        )}
      </div>

      {loading ? (
        <div className="empty">Carregando…</div>
      ) : items.length === 0 ? (
        <div className="empty">Nenhuma mídia ainda. Gere imagens ou vídeos na aba Mídia.</div>
      ) : (
        <div
          style={{
            display: "grid",
            gap: 16,
            gridTemplateColumns: "repeat(auto-fill, minmax(220px, 1fr))",
          }}
        >
          {items.map((it) => {
            const k = key(it);
            const isBroken = broken.has(k);
            const unavailable = !isOwnMedia(it.url); // provavelmente morta
            return (
              <div key={k} className="card">
                <div style={{ position: "relative", aspectRatio: "1 / 1", background: "var(--bg2)", display: "flex", alignItems: "center", justifyContent: "center", overflow: "hidden" }}>
                  {unavailable && !isBroken && (
                    <span
                      style={{
                        position: "absolute",
                        top: 8,
                        left: 8,
                        zIndex: 2,
                        background: "rgba(0,0,0,.65)",
                        color: "#ff9b8a",
                        fontSize: ".68rem",
                        fontWeight: 700,
                        letterSpacing: ".04em",
                        textTransform: "uppercase",
                        padding: "3px 7px",
                        borderRadius: 7,
                      }}
                    >
                      indisponível
                    </span>
                  )}
                  {isBroken ? (
                    <Broken />
                  ) : it.kind === "image" ? (
                    <img
                      src={it.url}
                      loading="lazy"
                      alt={it.keyword ?? "mídia"}
                      onError={() => markBroken(it)}
                      style={{ width: "100%", height: "100%", objectFit: "cover" }}
                    />
                  ) : isVideo(it.kind) ? (
                    <video
                      src={it.url}
                      controls
                      preload="metadata"
                      onError={() => markBroken(it)}
                      style={{ width: "100%", height: "100%", objectFit: "cover" }}
                    />
                  ) : (
                    <span style={{ color: "var(--muted)", fontSize: ".8rem" }}>sem preview</span>
                  )}
                </div>
                <div style={{ padding: "12px 14px", display: "flex", flexDirection: "column", gap: 4 }}>
                  <span style={{ fontWeight: 600, fontSize: ".9rem", overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap" }}>
                    {it.keyword || "(sem tema)"}
                  </span>
                  <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", gap: 8 }}>
                    <span className="tag">{it.style || it.kind || "mídia"}</span>
                    <button
                      type="button"
                      onClick={() => remove(it)}
                      disabled={deleting.has(k)}
                      title="Excluir mídia"
                      aria-label="Excluir mídia"
                      className="btn no"
                      style={{ flex: "0 0 auto", display: "inline-flex", alignItems: "center", justifyContent: "center", padding: "7px 9px" }}
                    >
                      <Trash2 size={15} strokeWidth={1.8} />
                    </button>
                  </div>
                </div>
              </div>
            );
          })}
        </div>
      )}
    </>
  );
}
