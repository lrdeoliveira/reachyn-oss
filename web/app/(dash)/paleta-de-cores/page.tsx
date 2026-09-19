"use client";

// PALETA DE CORES — guia de referência rápida pra escolher cores de personagem/cenário/marca
// dentro do FoxAssets. Inspirado no formato do guia da Sansão Udemy (filtro por categoria,
// impacto/aplicação por paleta, clique pra copiar HEX); dados e textos são autorais
// (web/lib/colorPalettes.ts), não uma cópia do conteúdo de lá.

import { useMemo, useState } from "react";
import { Palette as PaletteIcon, Copy } from "lucide-react";
import { useToast } from "@/components/ui/Toast";
import { CATEGORIES, PALETTES, type Palette } from "@/lib/colorPalettes";

export default function PaletaDeCoresPage() {
  const toast = useToast();
  const [filtro, setFiltro] = useState<string>("Todos");

  const visiveis = useMemo(
    () => (filtro === "Todos" ? PALETTES : PALETTES.filter((p) => p.tags.includes(filtro))),
    [filtro],
  );

  async function copiar(texto: string, msg: string) {
    try {
      await navigator.clipboard.writeText(texto);
      toast.ok(msg);
    } catch {
      toast.err("Não deu pra copiar — o navegador bloqueou o acesso à área de transferência.");
    }
  }

  return (
    <>
      <h1 className="h1">Paleta de Cores</h1>
      <p className="sub">
        {`${PALETTES.length} paletas curadas por psicologia das cores, pra dar identidade visual consistente a personagens, cenários e marcas. Clique numa cor pra copiar o HEX, ou use "Copiar tudo" pra levar a paleta inteira.`}
      </p>

      <div style={{ display: "flex", gap: 8, flexWrap: "wrap", marginBottom: 28 }}>
        <button type="button" className={`chip${filtro === "Todos" ? " on" : ""}`} onClick={() => setFiltro("Todos")}>
          Todos
        </button>
        {CATEGORIES.map((c) => (
          <button key={c} type="button" className={`chip${filtro === c ? " on" : ""}`} onClick={() => setFiltro(c)}>
            {c}
          </button>
        ))}
      </div>

      {visiveis.length === 0 ? (
        <div className="empty">Nenhuma paleta nessa categoria ainda.</div>
      ) : (
        <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fill, minmax(260px, 1fr))", gap: 16 }}>
          {visiveis.map((p) => (
            <Cartao key={p.id} p={p} onCopiarCor={(hex) => copiar(hex, `${hex} copiado.`)}
              onCopiarTudo={() => copiar(p.colors.join(", "), `Paleta "${p.name}" copiada.`)} />
          ))}
        </div>
      )}
    </>
  );
}

function Cartao({ p, onCopiarCor, onCopiarTudo }: {
  p: Palette; onCopiarCor: (hex: string) => void; onCopiarTudo: () => void;
}) {
  return (
    <div className="card">
      <div className="body" style={{ gap: 10 }}>
        <div className="tag" style={{ display: "flex", alignItems: "center", gap: 6 }}>
          <PaletteIcon size={12} /> N° {String(p.id).padStart(3, "0")}
        </div>
        <div className="title">{p.name}</div>

        <div style={{ display: "flex", borderRadius: 8, overflow: "hidden", height: 44 }}>
          {p.colors.map((hex) => (
            <button key={hex} type="button" onClick={() => onCopiarCor(hex)} title={`Copiar ${hex}`}
              style={{ flex: 1, background: hex, border: 0, cursor: "pointer" }} />
          ))}
        </div>

        <div className="txt"><strong style={{ color: "var(--text)" }}>Impacto:</strong> {p.impact}</div>
        <div className="txt"><strong style={{ color: "var(--text)" }}>Aplicação:</strong> {p.application}</div>

        <div style={{ display: "flex", gap: 6, flexWrap: "wrap" }}>
          {p.tags.map((t) => (
            <span key={t} className="tag" style={{ border: "1px solid var(--line)", borderRadius: 999, padding: "2px 9px" }}>{t}</span>
          ))}
        </div>
      </div>
      <div className="acts">
        <button type="button" className="btn edit" onClick={onCopiarTudo}
          style={{ display: "inline-flex", alignItems: "center", justifyContent: "center", gap: 6 }}>
          <Copy size={13} /> Copiar tudo
        </button>
      </div>
    </div>
  );
}
