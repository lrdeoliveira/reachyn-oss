"use client";

// 🌐 SELETOR DE REDES — badges/chips com o catálogo COMPLETO do produto.
//
// Fonte única: Networks::ALL no console (11 redes), servido por GET /api/connections em
// `networks` (key/label/icon) + `accounts` (as que a marca já conectou). Antes cada tela tinha a
// própria listinha hardcoded — Movies com 5 redes, Animação com 3 fixas e SEM seletor — então
// TikTok, X, Threads, Pinterest, Reddit, Bluesky e Google Business simplesmente não existiam no
// fluxo de vídeo: o operador não tinha como escolhê-las, e sem legenda a rede nem aparece no
// Aprovar. Uma rede nova em Networks::ALL passa a aparecer aqui sozinha.

import { useEffect, useState } from "react";
import { sfetch } from "@/lib/api";

export type Rede = { key: string; label: string; icon: string };

/** Fallback só pro caso de /api/connections falhar — ninguém fica sem poder escolher rede. */
const REDES_FALLBACK: Rede[] = [
  { key: "instagram", label: "Instagram", icon: "📸" },
  { key: "facebook", label: "Facebook", icon: "👍" },
  { key: "linkedin", label: "LinkedIn", icon: "💼" },
  { key: "tiktok", label: "TikTok", icon: "🎵" },
  { key: "youtube", label: "YouTube", icon: "▶️" },
  { key: "twitter", label: "X / Twitter", icon: "𝕏" },
  { key: "threads", label: "Threads", icon: "🧵" },
  { key: "pinterest", label: "Pinterest", icon: "📌" },
  { key: "reddit", label: "Reddit", icon: "👽" },
  { key: "bluesky", label: "Bluesky", icon: "🦋" },
  { key: "googlebusiness", label: "Google Business", icon: "📍" },
];

/** Redes pré-marcadas quando a marca ainda não conectou nenhuma conta (comportamento histórico). */
export const REDES_PADRAO = ["youtube", "instagram", "linkedin"];

/**
 * Catálogo + conectadas + seleção inicial. A seleção nasce nas redes CONECTADAS (é onde o post
 * realmente sai); sem nenhuma conectada, cai no padrão histórico — nunca vazia, senão o botão de
 * gerar texto nasce desabilitado sem o operador entender por quê.
 */
export function useRedes(): { redes: Rede[]; conectadas: string[]; sugestao: string[]; pronto: boolean } {
  const [redes, setRedes] = useState<Rede[]>(REDES_FALLBACK);
  const [conectadas, setConectadas] = useState<string[]>([]);
  const [pronto, setPronto] = useState(false);

  useEffect(() => {
    let vivo = true;
    void sfetch("/api/connections")
      .then((r) => r.json())
      .then((d: { ok?: boolean; networks?: Rede[]; accounts?: { platform: string }[] }) => {
        if (!vivo || !d?.ok) return;
        if (Array.isArray(d.networks) && d.networks.length) setRedes(d.networks);
        setConectadas([...new Set((d.accounts ?? []).map((a) => a.platform))]);
      })
      .catch(() => {})
      .finally(() => { if (vivo) setPronto(true); });
    return () => { vivo = false; };
  }, []);

  const sugestao = conectadas.length ? conectadas : REDES_PADRAO;

  return { redes, conectadas, sugestao, pronto };
}

/**
 * As badges em si. `sel` é a lista marcada; o pai é dono do estado (as telas usam a mesma seleção
 * pra gerar os textos E pra marcar o destino da mídia).
 */
export function RedesChips({
  redes, conectadas, sel, onChange, disabled = false,
}: {
  redes: Rede[];
  conectadas: string[];
  sel: string[];
  onChange: (redes: string[]) => void;
  disabled?: boolean;
}) {
  const alternar = (k: string) => onChange(sel.includes(k) ? sel.filter((x) => x !== k) : [...sel, k]);
  const todas = redes.map((r) => r.key);

  return (
    <div style={{ display: "flex", flexDirection: "column", gap: 8 }}>
      <div style={{ display: "flex", gap: 7, flexWrap: "wrap" }}>
        {redes.map((r) => {
          const on = sel.includes(r.key);
          const conectada = conectadas.includes(r.key);
          return (
            <button
              key={r.key}
              type="button"
              className={`chip ${on ? "on" : ""}`}
              disabled={disabled}
              onClick={() => alternar(r.key)}
              /* A conta desconectada NÃO é bloqueada: dá pra gerar a legenda agora e conectar
                 depois (o Publicar é quem exige a conexão). O selo só avisa. */
              title={conectada ? `${r.label} — conta conectada` : `${r.label} — ainda não conectada (dá pra gerar o texto e conectar depois)`}
            >
              {r.icon} {r.label}{conectada ? " ✅" : ""}
            </button>
          );
        })}
      </div>
      <div style={{ display: "flex", gap: 8, alignItems: "center", flexWrap: "wrap" }}>
        <button type="button" className="btn edit" style={{ flex: "none", padding: "4px 10px", fontSize: ".72rem" }} disabled={disabled} onClick={() => onChange(sel.length === todas.length ? [] : todas)}>
          {sel.length === todas.length ? "Limpar" : "Marcar todas"}
        </button>
        {conectadas.length > 0 && (
          <button type="button" className="btn edit" style={{ flex: "none", padding: "4px 10px", fontSize: ".72rem" }} disabled={disabled} onClick={() => onChange(conectadas)}>
            Só as conectadas ({conectadas.length})
          </button>
        )}
        <span className="txt" style={{ color: "var(--muted)", fontSize: ".72rem" }}>
          ✅ = conta conectada. Cada rede marcada gera 1 legenda (1 crédito de texto).
        </span>
      </div>
    </div>
  );
}
