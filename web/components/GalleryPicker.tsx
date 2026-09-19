"use client";

import { sfetch } from "@/lib/api";
// Só itens com URL do nosso storage carregam (igual à página Galeria).
import { isOwnMedia } from "@/lib/media";
import { useEffect, useState } from "react";

// Item da galeria cross-rascunho (GET /api/media/list) — mesmo shape da página Galeria.
type GalleryItem = { id: string; kind: string; url: string; style?: string | null; scene?: number | null; draft_id?: number; keyword?: string | null; date?: string };

// Modal: escolher uma mídia da galeria (cross-rascunho) como input das gerações (i2i/i2v), como
// referência de personagem ou pra FIXAR direto numa cena. Reutilizado pelo Studio, Histórias e Filme.
// kind: "image" (default) lista imagens; "video" lista vídeos (thumb = <video> com preview no
// hover); "all" mostra os dois com um alternador. O "all" existe porque em "Subir e publicar" a
// peça pode ser imagem OU vídeo, e o picker abria travado em imagem — os vídeos do Vox
// simplesmente não apareciam, sem nada na tela explicando por quê (relato de 2026-08-04).
export function GalleryPicker({ onPick, onClose, kind = "image" }: { onPick: (url: string) => void; onClose: () => void; kind?: "image" | "video" | "all" }) {
  // No modo "all" o filtro é escolha do usuário; começa em imagem (o caso mais comum).
  const [aba, setAba] = useState<"image" | "video">(kind === "video" ? "video" : "image");
  const tipo = kind === "all" ? aba : kind;
  const [items, setItems] = useState<GalleryItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [err, setErr] = useState<string | null>(null);
  useEffect(() => {
    sfetch("/api/media/list")
      .then((r) => r.json())
      .then((d) => { if (d?.ok) setItems(d.items ?? []); else setErr("Não foi possível carregar a galeria."); })
      .catch(() => setErr("Não foi possível carregar a galeria."))
      .finally(() => setLoading(false));
  }, []);
  // Só mídia do nosso storage serve como input. EXCLUI as mídias de CENA (scene=N) das
  // histórias — são peças intermediárias e poluíam o picker com dezenas de itens.
  const usable = items.filter((it) => it.kind === tipo && it.scene == null && isOwnMedia(it.url));
  const contagem = (k: "image" | "video") => items.filter((it) => it.kind === k && it.scene == null && isOwnMedia(it.url)).length;
  return (
    <div onClick={onClose} style={{ position: "fixed", inset: 0, zIndex: 60, background: "rgba(0,0,0,.7)", display: "flex", alignItems: "center", justifyContent: "center", padding: 20 }}>
      <div onClick={(e) => e.stopPropagation()} style={{ background: "var(--panel)", border: "1px solid var(--line)", borderRadius: 14, padding: 18, width: "min(900px,95vw)", maxHeight: "85vh", overflow: "auto" }}>
        <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: 12 }}>
          <strong style={{ fontSize: "1rem", color: "var(--peach)" }}>
            {kind === "all" ? "Escolher da galeria" : tipo === "video" ? "Escolher vídeo da galeria" : "Escolher imagem da galeria"}
          </strong>
          <button className="btn edit" style={{ flex: "none", padding: "6px 12px" }} onClick={onClose}>Fechar</button>
        </div>
        {kind === "all" && (
          <div style={{ display: "flex", gap: 8, marginBottom: 12 }}>
            {([["image", "🖼️ Imagens"], ["video", "🎬 Vídeos"]] as const).map(([k, rotulo]) => (
              <button key={k} type="button" onClick={() => setAba(k)}
                className={aba === k ? "btn ok" : "btn edit"}
                style={{ flex: "none", padding: "6px 14px", fontSize: ".82rem" }}>
                {rotulo}{!loading && ` (${contagem(k)})`}
              </button>
            ))}
          </div>
        )}
        {loading ? (
          <p className="txt" style={{ color: "var(--muted)" }}>Carregando galeria…</p>
        ) : err ? (
          <p className="txt" style={{ color: "#ff9b8a" }}>{err}</p>
        ) : usable.length === 0 ? (
          <p className="txt" style={{ color: "var(--muted)" }}>{tipo === "video" ? "Nenhum vídeo disponível na galeria. Gere ou envie um vídeo primeiro." : "Nenhuma imagem disponível na galeria. Gere ou envie uma imagem primeiro."}</p>
        ) : (
          <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fill,minmax(min(130px, 100%), 1fr))", gap: 10 }}>
            {usable.map((it) => (
              <button key={`${it.draft_id}-${it.id}`} type="button" onClick={() => onPick(it.url)} title={it.keyword || tipo}
                style={{ padding: 0, border: "1px solid var(--line)", borderRadius: 10, overflow: "hidden", cursor: "pointer", background: "var(--bg2)", aspectRatio: "1 / 1" }}>
                {tipo === "video" ? (
                  <video src={it.url} muted preload="metadata" onMouseEnter={(e) => void e.currentTarget.play().catch(() => {})} onMouseLeave={(e) => { e.currentTarget.pause(); e.currentTarget.currentTime = 0; }} style={{ width: "100%", height: "100%", objectFit: "cover", display: "block" }} />
                ) : (
                  <img src={it.url} alt={it.keyword || "imagem"} loading="lazy" style={{ width: "100%", height: "100%", objectFit: "cover", display: "block" }} />
                )}
              </button>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}
