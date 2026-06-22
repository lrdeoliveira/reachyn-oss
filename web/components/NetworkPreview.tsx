"use client";

import { useState } from "react";

// Pré-visualização "como fica na rede" + metadados das plataformas (rótulo, cor da
// marca, limite de caracteres). Compartilhado entre o editor (/editar), o passo
// Aprovar e o Publicar — onde o texto precisa ser visualizado por rede.

// Plataformas: rótulo, cor da marca e limite de caracteres (0 = sem limite).
export const NET: Record<string, { name: string; color: string; limit: number }> = {
  blog: { name: "Blog", color: "#f59e0b", limit: 0 },
  linkedin: { name: "LinkedIn", color: "#0a66c2", limit: 3000 },
  instagram: { name: "Instagram", color: "#e1306c", limit: 2200 },
  facebook: { name: "Facebook", color: "#1877f2", limit: 63206 },
  threads: { name: "Threads", color: "#8b8b8b", limit: 500 },
  twitter: { name: "X / Twitter", color: "#1d9bf0", limit: 280 },
  youtube: { name: "YouTube", color: "#ff0000", limit: 5000 },
};
export const PLATFORMS = Object.keys(NET);
// Rótulo curto e seguro mesmo para uma plataforma fora do mapa.
export const netName = (p: string) => NET[p]?.name ?? p;

// netLimit — teto EFETIVO de caracteres: 98% do limite real da rede (2% de folga), pra
// o texto nunca encostar no limite e ser rejeitado na publicação. 0 = rede sem limite.
export const netLimit = (p: string): number => {
  const l = NET[p]?.limit ?? 0;
  return l > 0 ? Math.floor(l * 0.98) : 0;
};

// Pré-visualização leve no "look" de cada rede — header com a marca + texto + 1ª imagem.
export function NetworkPreview({ platform, text, medias = [] }: { platform: string; text: string; medias?: { url: string; kind: string }[] }) {
  const n = NET[platform] ?? { name: platform, color: "var(--line)", limit: 0 };
  const lim = netLimit(platform);
  const over = lim > 0 && text.length > lim;
  const [idx, setIdx] = useState(0);
  const pos = medias.length ? ((idx % medias.length) + medias.length) % medias.length : 0; // índice circular
  const cur = medias[pos] ?? null;
  return (
    <div style={{ marginTop: 8, background: "var(--bg2)", border: "1px solid var(--line)", borderRadius: 10, overflow: "hidden" }}>
      <div style={{ display: "flex", alignItems: "center", gap: 8, padding: "10px 12px", borderBottom: "1px solid var(--line)" }}>
        <div style={{ width: 30, height: 30, borderRadius: "50%", background: n.color, display: "flex", alignItems: "center", justifyContent: "center", color: "#fff", fontWeight: 800, fontSize: ".8rem" }}>🦊</div>
        <div style={{ lineHeight: 1.1 }}>
          <div style={{ fontWeight: 700, fontSize: ".82rem" }}>Reachyn</div>
          <div style={{ color: "var(--muted)", fontSize: ".7rem" }}>{n.name}</div>
        </div>
      </div>
      <div style={{ padding: "10px 12px" }}>
        {text ? <p className="txt" style={{ whiteSpace: "pre-wrap", fontSize: ".84rem", margin: 0 }}>{text}</p>
              : <p className="txt" style={{ color: "var(--muted)", fontSize: ".82rem", margin: 0 }}>O texto aparece aqui conforme você edita.</p>}
        {cur && (
          <div style={{ position: "relative", marginTop: 10 }}>
            {cur.kind === "image"
              ? <img src={cur.url} alt="" style={{ width: "100%", borderRadius: 8, display: "block" }} />
              : <video src={cur.url} controls style={{ width: "100%", borderRadius: 8, display: "block" }} />}
            {medias.length > 1 && (
              <>
                <button type="button" onClick={() => setIdx(pos - 1)} aria-label="Mídia anterior" style={{ position: "absolute", top: "50%", left: 6, transform: "translateY(-50%)", width: 30, height: 30, borderRadius: "50%", border: 0, background: "rgba(0,0,0,.55)", color: "#fff", cursor: "pointer", fontSize: "1.1rem", lineHeight: 1 }}>‹</button>
                <button type="button" onClick={() => setIdx(pos + 1)} aria-label="Próxima mídia" style={{ position: "absolute", top: "50%", right: 6, transform: "translateY(-50%)", width: 30, height: 30, borderRadius: "50%", border: 0, background: "rgba(0,0,0,.55)", color: "#fff", cursor: "pointer", fontSize: "1.1rem", lineHeight: 1 }}>›</button>
                <span style={{ position: "absolute", bottom: 8, right: 8, background: "rgba(0,0,0,.6)", color: "#fff", fontSize: ".7rem", padding: "2px 7px", borderRadius: 10 }}>{pos + 1}/{medias.length}</span>
              </>
            )}
          </div>
        )}
      </div>
      {lim > 0 && (
        <div style={{ padding: "6px 12px", borderTop: "1px solid var(--line)", fontSize: ".72rem", color: over ? "#ef4444" : "var(--muted)", fontWeight: over ? 700 : 400 }}>
          {text.length}/{lim} caracteres{over ? " · acima do limite desta rede" : ""}
        </div>
      )}
    </div>
  );
}

// Bloco reutilizável: abas de rede + a pré-visualização da rede ativa. Usado na
// coluna de revisão do Aprovar/Publicar. O estado da aba ativa fica no pai.
export function NetworkPreviewTabs({
  platforms, active, onActive, texts, medias = [],
}: {
  platforms: string[]; active: string; onActive: (p: string) => void;
  texts: Record<string, string>; medias?: { url: string; kind: string }[];
}) {
  const cur = platforms.includes(active) ? active : platforms[0];
  return (
    <div>
      <div style={{ display: "flex", gap: 6, flexWrap: "wrap", marginBottom: 10 }}>
        {platforms.map((p) => {
          const on = cur === p;
          const hasText = !!(texts[p] && texts[p].trim());
          return (
            <button key={p} onClick={() => onActive(p)}
              style={{ padding: "5px 12px", borderRadius: 16, fontSize: ".8rem", cursor: "pointer", textTransform: "capitalize", fontWeight: on ? 700 : 400,
                border: "1px solid " + (NET[p]?.color ?? "var(--line)"),
                background: on ? (NET[p]?.color ?? "var(--line)") : (NET[p]?.color ?? "var(--line)") + "22",
                color: on ? "#fff" : "var(--text)" }}>
              {hasText ? "● " : ""}{netName(p)}
            </button>
          );
        })}
      </div>
      <span className="txt" style={{ color: "var(--muted)", fontSize: ".78rem" }}>Pré-visualização</span>
      <NetworkPreview platform={cur} text={texts[cur] || ""} medias={medias} />
    </div>
  );
}
