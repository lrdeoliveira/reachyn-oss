"use client";

import { useState } from "react";

// Pré-visualização "como fica na rede" + metadados das plataformas (rótulo, cor da
// marca, limite de caracteres). Compartilhado entre o editor (/editar), o passo
// Aprovar e o Publicar — onde o texto precisa ser visualizado por rede.

// Plataformas: rótulo, cor da marca e limite de caracteres (0 = sem limite).
// Espelha conector socialService::NETWORKS no backend (as redes conectáveis via conector social).
export const NET: Record<string, { name: string; color: string; limit: number }> = {
  linkedin: { name: "LinkedIn", color: "#0a66c2", limit: 3000 },
  instagram: { name: "Instagram", color: "#e1306c", limit: 2200 },
  facebook: { name: "Facebook", color: "#1877f2", limit: 63206 },
  tiktok: { name: "TikTok", color: "#ff0050", limit: 2200 },
  youtube: { name: "YouTube", color: "#ff0000", limit: 5000 },
  twitter: { name: "X / Twitter", color: "#1d9bf0", limit: 280 },
  threads: { name: "Threads", color: "#8b8b8b", limit: 500 },
  pinterest: { name: "Pinterest", color: "#e60023", limit: 500 },
  reddit: { name: "Reddit", color: "#ff4500", limit: 40000 },
  bluesky: { name: "Bluesky", color: "#0085ff", limit: 300 },
  googlebusiness: { name: "Google Business", color: "#4285f4", limit: 1500 },
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

// Razões de aspecto comuns em vídeo social — pra rotular "9:16" em vez de "500:889".
const COMMON_RATIOS: [number, number][] = [
  [9, 16], [16, 9], [1, 1], [4, 5], [5, 4], [3, 4], [4, 3], [2, 3], [3, 2], [21, 9],
];
// aspectLabel — deriva a razão (ex: "9:16") de largura×altura reais. Casa com a razão
// comum mais próxima (tolerância ~2%); fora disso, reduz pelo gcd (ex: "40:21").
function aspectLabel(w: number, h: number): string {
  if (!w || !h) return "";
  const r = w / h;
  let best = "", bestDiff = Infinity;
  for (const [a, b] of COMMON_RATIOS) {
    const d = Math.abs(r - a / b);
    if (d < bestDiff) { bestDiff = d; best = `${a}:${b}`; }
  }
  if (bestDiff <= 0.02 * r) return best;
  const gcd = (x: number, y: number): number => (y ? gcd(y, x % y) : x);
  const k = gcd(w, h) || 1;
  return `${Math.round(w / k)}:${Math.round(h / k)}`;
}
// formatFromUrl — container do arquivo pela extensão da URL (MP4/WEBM/MOV). "VÍDEO" se não der.
function formatFromUrl(url: string): string {
  const clean = url.split("?")[0].split("#")[0];
  const ext = clean.slice(clean.lastIndexOf(".") + 1).toLowerCase();
  return /^[a-z0-9]{2,4}$/.test(ext) ? ext.toUpperCase() : "VÍDEO";
}

// VideoWithMeta — <video> com um selo de RESOLUÇÃO + RAZÃO + FORMATO sondado no browser
// (videoWidth×videoHeight ao carregar os metadados). Ex: "1080×1920 · 9:16 · MP4". Usado no
// detalhe da publicação, onde o cliente quer conferir a resolução/formato reais do que foi ao ar.
export function VideoWithMeta({ src, radius = 8 }: { src: string; radius?: number }) {
  const [dim, setDim] = useState<{ w: number; h: number } | null>(null);
  const label = dim ? `${dim.w}×${dim.h} · ${aspectLabel(dim.w, dim.h)} · ${formatFromUrl(src)}` : "";
  return (
    <div style={{ position: "relative" }}>
      <video
        src={src}
        controls
        preload="metadata"
        onLoadedMetadata={(e) => setDim({ w: e.currentTarget.videoWidth, h: e.currentTarget.videoHeight })}
        style={{ width: "100%", borderRadius: radius, display: "block" }}
      />
      {label && (
        <span style={{ position: "absolute", top: 8, left: 8, background: "rgba(0,0,0,.72)", color: "#fff",
          fontSize: ".68rem", fontWeight: 700, padding: "3px 8px", borderRadius: 8, letterSpacing: ".02em",
          pointerEvents: "none", fontVariantNumeric: "tabular-nums" }}>
          {label}
        </span>
      )}
    </div>
  );
}

// Pré-visualização leve no "look" de cada rede — header com a marca + texto + 1ª imagem.
// videoMeta: mostra o selo de resolução/formato no vídeo (usado no detalhe da publicação).
export function NetworkPreview({ platform, text, medias = [], videoMeta = false }: { platform: string; text: string; medias?: { url: string; kind: string }[]; videoMeta?: boolean }) {
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
          <div style={{ fontWeight: 700, fontSize: ".82rem" }}>RedFoxCode</div>
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
              : videoMeta
                ? <VideoWithMeta src={cur.url} />
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
  platforms, active, onActive, texts, medias = [], videoMeta = false,
}: {
  platforms: string[]; active: string; onActive: (p: string) => void;
  texts: Record<string, string>; medias?: { url: string; kind: string }[]; videoMeta?: boolean;
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
      <NetworkPreview platform={cur} text={texts[cur] || ""} medias={medias} videoMeta={videoMeta} />
    </div>
  );
}
