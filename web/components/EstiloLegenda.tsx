"use client";

// 💬 ESTILO DA LEGENDA QUEIMADA — painel restaurado da poda de 2026-07-23 (existia no Studio
// antigo; o engine nunca deixou de aceitar os campos, mas a UI e o repasse do console se
// perderam). Agora como componente próprio, com duas melhorias sobre o original:
//   1. PERSISTÊNCIA: o estilo escolhido fica no localStorage — configura uma vez, vale sempre.
//   2. Reutilizável: qualquer tela com legenda (Vídeo hoje, Montagem amanhã) usa o mesmo painel.
//
// A prévia é em ESCALA REAL: quadro na proporção do vídeo com altura 288px — a MESMA régua da
// legenda queimada no ffmpeg-service (FontSize sobre PlayRes 288). O que você vê é o tamanho
// relativo do vídeo final.

import { useEffect, useState } from "react";

export type EstiloLegendaState = {
  pos: string;         // bottom | middle | top
  size: number;        // fonte 12..56 (régua PlayRes 288)
  color: string;       // cor do texto (#RRGGBB)
  border: number;      // espessura do contorno 1..10
  borderColor: string; // cor do contorno
  font: string;        // sans|serif|mono|dejavu|dejavu-serif|noto
  opacity: number;     // transparência do texto 0..90 (0 = opaco)
  bg: boolean;         // caixa atrás do texto
  bgColor: string;     // cor da caixa
  bgOpacity: number;   // opacidade da caixa 10..100
};

export const LEGENDA_DEFAULT: EstiloLegendaState = {
  pos: "bottom", size: 20, color: "#FFFFFF", border: 3, borderColor: "#000000",
  font: "sans", opacity: 0, bg: false, bgColor: "#000000", bgOpacity: 60,
};

const STORAGE_KEY = "foxassets.legenda.estilo";

/** Estado do estilo com persistência: carrega do localStorage na montagem e salva a cada ajuste. */
export function useEstiloLegenda(): [EstiloLegendaState, (patch: Partial<EstiloLegendaState>) => void] {
  const [estilo, setEstilo] = useState<EstiloLegendaState>(LEGENDA_DEFAULT);
  useEffect(() => {
    try {
      const raw = localStorage.getItem(STORAGE_KEY);
      // Merge sobre o default: um campo novo no futuro não quebra o salvo antigo.
      if (raw) setEstilo({ ...LEGENDA_DEFAULT, ...JSON.parse(raw) });
    } catch { /* estilo corrompido → default */ }
  }, []);
  const patch = (p: Partial<EstiloLegendaState>) => {
    setEstilo((prev) => {
      const next = { ...prev, ...p };
      try { localStorage.setItem(STORAGE_KEY, JSON.stringify(next)); } catch { /* quota/privado */ }
      return next;
    });
  };
  return [estilo, patch];
}

/** Campos do payload do /generate/video — os MESMOS nomes que o engine lê (SubtitleStyle). */
export function legendaPayload(e: EstiloLegendaState) {
  return {
    subtitlePos: e.pos,
    subtitleSize: e.size,
    subtitleColor: e.color,
    subtitleBorder: e.border,
    subtitleBorderColor: e.borderColor,
    subtitleFont: e.font,
    subtitleOpacity: e.opacity,
    subtitleBg: e.bg,
    subtitleBgColor: e.bgColor,
    subtitleBgOpacity: e.bgOpacity,
  };
}

const rowStyle: React.CSSProperties = { display: "flex", alignItems: "center", gap: 14, flexWrap: "wrap" };
const lblStyle: React.CSSProperties = { display: "flex", alignItems: "center", gap: 6, fontSize: ".82rem" };
const mut: React.CSSProperties = { color: "var(--muted)" };
const colorInput: React.CSSProperties = { width: 34, height: 26, padding: 0, border: "1px solid var(--line2)", borderRadius: 6, background: "transparent", cursor: "pointer" };

export function EstiloLegenda({ estilo, onChange, aspect, disabled }: {
  estilo: EstiloLegendaState;
  onChange: (patch: Partial<EstiloLegendaState>) => void;
  /** Proporção do vídeo (9:16 | 1:1 | 16:9) — dita a moldura da prévia em escala real. */
  aspect: string;
  disabled?: boolean;
}) {
  const e = estilo;
  // Moldura da prévia: altura fixa 288 (PlayRes), largura pela proporção do vídeo.
  const previewW = aspect === "16:9" ? 512 : aspect === "1:1" ? 288 : 162;
  return (
    <div style={{ display: "flex", flexDirection: "column", gap: 10, padding: "12px 14px", border: "1px solid var(--line2)", borderRadius: 10, background: "var(--bg2)", opacity: disabled ? 0.6 : 1 }}>
      <div style={{ display: "flex", alignItems: "center", gap: 6 }} title="Onde a legenda aparece no vídeo">
        <span style={{ ...mut, fontSize: ".82rem" }}>Legenda:</span>
        <div style={{ display: "flex", border: "1px solid var(--line2)", borderRadius: 16, overflow: "hidden" }}>
          {([["top", "⬆️ Em cima"], ["middle", "⏺️ Meio"], ["bottom", "⬇️ Embaixo"]] as const).map(([v, lbl]) => {
            const on = e.pos === v;
            return (
              <button key={v} type="button" disabled={disabled} onClick={() => onChange({ pos: v })}
                style={{ padding: "6px 12px", fontSize: ".77rem", cursor: "pointer", border: 0, background: on ? "var(--red)" : "transparent", color: on ? "#fff" : "var(--muted)", fontWeight: on ? 700 : 400 }}>
                {lbl}
              </button>
            );
          })}
        </div>
      </div>

      {/* fonte · tamanho · cor · contorno (+cor) · transparência */}
      <div style={rowStyle}>
        <label style={lblStyle} title="Fonte da legenda">
          <span style={mut}>Fonte</span>
          <select value={e.font} disabled={disabled} onChange={(ev) => onChange({ font: ev.target.value })}
            style={{ fontSize: ".8rem", padding: "4px 6px", border: "1px solid var(--line2)", borderRadius: 6, background: "transparent", color: "var(--text)", cursor: "pointer" }}>
            <option value="sans">Padrão</option>
            <option value="serif">Serifada</option>
            <option value="mono">Mono</option>
            <option value="dejavu">Suave</option>
            <option value="dejavu-serif">Clássica</option>
            <option value="noto">Universal</option>
          </select>
        </label>
        <label style={lblStyle} title="Tamanho da fonte da legenda">
          <span style={mut}>Tamanho</span>
          <input type="range" min={12} max={56} value={e.size} disabled={disabled} onChange={(ev) => onChange({ size: Number(ev.target.value) })} style={{ width: 100 }} />
          <span style={{ ...mut, minWidth: 24, textAlign: "right" }}>{e.size}</span>
        </label>
        <label style={lblStyle} title="Cor do texto da legenda">
          <span style={mut}>Cor</span>
          <input type="color" value={e.color} disabled={disabled} onChange={(ev) => onChange({ color: ev.target.value })} style={colorInput} />
        </label>
        <label style={lblStyle} title="Espessura do contorno do texto (1..10)">
          <span style={mut}>Borda</span>
          <input type="range" min={1} max={10} value={e.border} disabled={disabled} onChange={(ev) => onChange({ border: Number(ev.target.value) })} style={{ width: 80 }} />
          <span style={{ ...mut, minWidth: 16, textAlign: "right" }}>{e.border}</span>
        </label>
        <label style={lblStyle} title="Cor do contorno da legenda">
          <span style={mut}>Cor borda</span>
          <input type="color" value={e.borderColor} disabled={disabled} onChange={(ev) => onChange({ borderColor: ev.target.value })} style={colorInput} />
        </label>
        <label style={lblStyle} title="Transparência do texto (0 = opaco)">
          <span style={mut}>Transp.</span>
          <input type="range" min={0} max={90} value={e.opacity} disabled={disabled} onChange={(ev) => onChange({ opacity: Number(ev.target.value) })} style={{ width: 80 }} />
          <span style={{ ...mut, minWidth: 30, textAlign: "right" }}>{e.opacity}%</span>
        </label>
      </div>

      {/* caixa (fundo) atrás do texto + prévia em escala real */}
      <div style={rowStyle}>
        <label style={{ ...lblStyle, cursor: disabled ? "default" : "pointer" }} title="Desenha uma caixa atrás do texto (melhora a leitura sobre fundos claros)">
          <input type="checkbox" checked={e.bg} disabled={disabled} onChange={(ev) => onChange({ bg: ev.target.checked })} />
          <span style={mut}>Fundo (caixa)</span>
        </label>
        {e.bg && (
          <>
            <label style={lblStyle} title="Cor da caixa">
              <span style={mut}>Cor fundo</span>
              <input type="color" value={e.bgColor} disabled={disabled} onChange={(ev) => onChange({ bgColor: ev.target.value })} style={colorInput} />
            </label>
            <label style={lblStyle} title="Opacidade da caixa (100 = sólida)">
              <span style={mut}>Opacidade</span>
              <input type="range" min={10} max={100} value={e.bgOpacity} disabled={disabled} onChange={(ev) => onChange({ bgOpacity: Number(ev.target.value) })} style={{ width: 90 }} />
              <span style={{ ...mut, minWidth: 30, textAlign: "right" }}>{e.bgOpacity}%</span>
            </label>
          </>
        )}
        <div
          style={{ position: "relative", flex: "none", height: 288, width: previewW, borderRadius: 8, border: "1px solid var(--line2)", overflow: "hidden", background: "linear-gradient(165deg,#2b3552 0%,#131a2c 55%,#2f2216 100%)", display: "flex", alignItems: e.pos === "top" ? "flex-start" : e.pos === "middle" ? "center" : "flex-end", justifyContent: "center" }}
          title="Prévia em escala real: a proporção do quadro e o tamanho da legenda são os do vídeo final"
        >
          <span style={{
            fontFamily: ({ sans: "sans-serif", serif: "serif", mono: "monospace", dejavu: "sans-serif", "dejavu-serif": "serif", noto: "sans-serif" } as Record<string, string>)[e.font] || "sans-serif",
            fontSize: e.size, lineHeight: 1.2, fontWeight: 700, textAlign: "center" as const,
            color: e.color, opacity: 1 - e.opacity / 100,
            WebkitTextStroke: `${Math.max(0.5, e.border * 0.7)}px ${e.borderColor}`,
            textShadow: "1px 1px 2px rgba(0,0,0,.55)",
            background: e.bg ? `${e.bgColor}${Math.round(e.bgOpacity * 2.55).toString(16).padStart(2, "0")}` : "transparent",
            padding: e.bg ? "2px 8px" : 0, borderRadius: 4,
            margin: e.pos === "middle" ? 0 : "14px 8px", maxWidth: "94%",
          }}>
            SUA LEGENDA
          </span>
        </div>
      </div>
    </div>
  );
}
