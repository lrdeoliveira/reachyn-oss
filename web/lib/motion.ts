// 🎞️ MOTION — vocabulário do card de motion graphics da Mídia.
//
// A regra do card: NÃO SE ANIMA DO NADA. Primeiro sai uma TELA ESTÁTICA com todos os elementos
// dentro; o usuário aprova; só então o modelo de vídeo a decompõe e a remonta. Animar a partir
// de texto puro devolve movimento genérico; animar a partir de um quadro aprovado devolve a
// SUA peça se mexendo.
//
// ⚠️ A allowlist de estruturas espelha `MotionPrompt::ESTRUTURAS` (console). O prompt de
// ANIMAÇÃO é montado no BACK-END de propósito — cada estrutura é um template com marcação de
// tempo e regras fixas; deixar o usuário escrevê-lo devolveria o problema que o card resolve.
// Aqui mora só o prompt da TELA (imagem), que passa pela mesma rota do card de Imagem.

/** [slug, rótulo no seletor, quando usar]. Só duas nesta versão — cobrem a maioria dos casos. */
export const MOTION_STRUCTURES: [string, string, string][] = [
  ["camadas", "🧱 Monta em camadas", "Processo, jornada, “como funciona”, passo a passo."],
  ["cartelas", "🃏 Cartelas de texto", "Frases curtas em sequência: manifesto, “3 motivos”."],
];

/** Faixa que o modelo de vídeo aceita. Fora dela o provedor recusa. */
export const MOTION_DUR_MIN = 4;
export const MOTION_DUR_MAX = 15;
export const MAX_FRASES = 6;

/** Frases das cartelas: uma por linha, sem vazias, no teto do back-end. */
export const parseFrases = (texto: string): string[] =>
  texto.split("\n").map((l) => l.trim()).filter(Boolean).slice(0, MAX_FRASES);

/**
 * Prompt da TELA ESTÁTICA — o quadro que vai ser aprovado e depois animado. Ele descreve uma
 * composição PARADA e completa (todos os elementos já dentro), porque é isso que o i2v precisa:
 * um quadro para desmontar. Pedir "animação" aqui devolveria um frame de vídeo borrado.
 */
export function telaEstaticaPrompt(descricao: string, estrutura: string, frases: string[]): string {
  const base = descricao.trim();
  if (estrutura === "cartelas") {
    const primeira = frases[0] || base;
    return [
      `A single static motion-graphics title frame for a piece about: ${base || primeira}.`,
      `Full-frame text card reading exactly: "${primeira.replace(/"/g, "'")}".`,
      "Bold clean typography centered on a flat solid color field, generous negative space,",
      "no characters, no photographic background — a still frame, not a video still.",
    ].join(" ");
  }
  return [
    `A single static motion-graphics composition for a piece about: ${base}.`,
    "Every element of the piece is already present and arranged in the frame:",
    "a flat background field, a few clearly separated figures/objects, and a detail layer",
    "(marks, arrows, underlines, textures) on top. Elements are laid out as separate,",
    "non-overlapping layers with generous negative space, so they can later be revealed one",
    "at a time. Flat, graphic, no camera depth of field — a still frame, not a video still.",
  ].join(" ");
}
