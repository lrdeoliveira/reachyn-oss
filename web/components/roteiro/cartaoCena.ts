// 🎴 LINGUAGEM VISUAL DO CARTÃO DE CENA — a mesma da MONTAGEM (aba /roteiro, SceneNode.tsx).
//
// POR QUE ESTE ARQUIVO EXISTE: o cartão de cena da Montagem (mídia em cima, texto no meio,
// botões no rodapé) virou o padrão da casa — o Luciano pediu o storyboard da aba /video com essa
// mesma cara. O SceneNode NÃO é reusável como componente: ele é um nó do React Flow (recebe
// `NodeProps`, desenha `Handle`s de ligação, lê as ações do `RoteiroCtx` e edita um `SceneData`
// do canvas). Emprestar aquele componente pro Vox obrigaria a arrastar o canvas junto.
//
// O que dá pra compartilhar de verdade é a CASCA: medidas, cores e estados. Então os estilos
// saíram do SceneNode pra cá e os dois cartões os IMPORTAM — mexeu aqui, as duas telas mudam
// juntas. O resto (quais campos, quais botões) é de cada tela, como tem de ser.

import type { CSSProperties } from "react";

/** Moldura do cartão. `width` fica por conta de quem usa: na Montagem o cartão é um nó de largura
 *  fixa (380) no canvas; no Vox ele é uma célula de grade, que estica. */
export const cartaoBox: CSSProperties = {
  background: "var(--panel)", border: "1px solid var(--line)", borderRadius: "var(--radius)",
  color: "var(--text)", fontSize: 12, boxShadow: "0 6px 24px rgba(0,0,0,.35)", overflow: "hidden",
};

/** Campo de texto/select de dentro do cartão. */
export const cartaoField: CSSProperties = {
  width: "100%", background: "var(--bg)", border: "1px solid var(--line2)", borderRadius: 8,
  color: "var(--text)", fontSize: 12, padding: "6px 8px", resize: "vertical", outline: "none",
  fontFamily: "inherit",
};

/** Botão do RODAPÉ do cartão (a fileira de ações). */
export const cartaoBtn: CSSProperties = {
  display: "flex", alignItems: "center", gap: 5, flex: 1, justifyContent: "center",
  background: "var(--panel2)", border: "1px solid var(--line2)", borderRadius: 8, color: "var(--peach)",
  fontSize: 11, padding: "6px 4px", cursor: "pointer", fontFamily: "inherit", fontWeight: 600,
};

/** Botãozinho sobre a mídia (ampliar / baixar / descartar): discreto, some no fundo escuro. */
export const cartaoIconBtn: CSSProperties = {
  display: "grid", placeItems: "center", background: "rgba(0,0,0,.6)", border: "1px solid rgba(255,255,255,.18)",
  borderRadius: 8, color: "#fff", cursor: "pointer", padding: 4, lineHeight: 0,
};

/** Selo de estado no cabeçalho do cartão (rascunho / gerando / pronto / erro). */
export const cartaoBadge = (s?: string): CSSProperties => ({
  fontSize: 10, padding: "2px 8px", borderRadius: 999,
  background: s === "error" ? "rgba(226,74,49,.14)" : s === "done" ? "rgba(63,185,80,.16)" : "var(--panel2)",
  color: s === "error" ? "var(--peach)" : s === "done" ? "var(--green)" : "var(--muted)",
});

/** Altura do quadro/preview no topo do cartão — a mesma nas duas telas, senão a grade do Vox
 *  ficaria com um "vídeo maior" e as duas telas deixariam de parecer a mesma ferramenta. */
export const CARTAO_MIDIA_H = 150;

/** Grade de cartões (Vox). Cartão mínimo de 300px: abaixo disso o rodapé de botões quebra em
 *  duas linhas e o cartão deixa de ser lido de uma vez. */
export const cartaoGrade: CSSProperties = {
  display: "grid", gridTemplateColumns: "repeat(auto-fill, minmax(300px, 1fr))", gap: 12,
};
