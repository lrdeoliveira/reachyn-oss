"use client";

// Spinner + Skeleton do kit compartilhado (S1). Antes o app não tinha NENHUM indicador animado
// de carregamento (só o texto "Carregando…"). Spinner pra ações pontuais; Skeleton pra grids
// (galeria, publicações, aprovações) enquanto a lista carrega.

export function Spinner({ size = 16 }: { size?: number }) {
  return <span className="spinner" style={{ width: size, height: size }} aria-label="Carregando" role="status" />;
}

/** Bloco cinza pulsante no formato do conteúdo que vai chegar. */
export function Skeleton({ h = 16, w = "100%", br = 8, style }: { h?: number | string; w?: number | string; br?: number; style?: React.CSSProperties }) {
  return <span className="skeleton" style={{ height: h, width: w, borderRadius: br, display: "block", ...style }} aria-hidden="true" />;
}

/** Grid de cards-fantasma (padrão .grid da casa) pra telas de acervo. */
export function SkeletonCards({ n = 6, mediaH = 150 }: { n?: number; mediaH?: number }) {
  return (
    <div className="grid" aria-label="Carregando" role="status">
      {Array.from({ length: n }, (_, i) => (
        <div key={i} className="card">
          <Skeleton h={mediaH} br={0} />
          <div className="body">
            <Skeleton h={13} w="40%" />
            <Skeleton h={17} w="80%" />
            <Skeleton h={13} w="60%" />
          </div>
        </div>
      ))}
    </div>
  );
}
