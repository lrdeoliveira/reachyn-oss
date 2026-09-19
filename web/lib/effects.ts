// 🎇 Estúdio de Efeitos — catálogos compartilhados (Histórias/Quadrinhos/Filme/Mídia).
// Espelham as allowlists do console (StudioController::GRADES / VFX_KINDS). Tudo cobrado
// em créditos (bucket effect): filtro 2 · VFX 2/cena · SFX 3/cena · transição 1/corte.

// Filtros de cor "estilo Instagram" (determinísticos, aplicados na montagem/foto).
export const GRADE_OPTIONS: [string, string][] = [
  ["natural", "Natural (sem filtro)"],
  ["cinema_quente", "🎬 Cinema Quente"],
  ["teal_orange", "🎬 Teal & Orange"],
  ["dourado", "🌇 Dourado (golden hour)"],
  ["gelo", "❄️ Gelo (frio)"],
  ["pastel", "🌸 Pastel"],
  ["tropical", "🌴 Tropical (vibrante)"],
  ["drama", "🎭 Drama (contraste)"],
  ["noir", "🎞️ Noir (P&B)"],
  ["pb_suave", "◻️ P&B suave"],
  ["vintage", "📺 Vintage"],
  ["retro_vhs", "📼 Retrô VHS"],
];

// Efeitos visuais por cena/trecho (ffmpeg determinístico — duração preservada).
export const VFX_OPTIONS: [string, string][] = [
  ["", "Sem efeito"],
  ["shake", "📳 Tremida de câmera"],
  ["zoom_pulse", "🫀 Zoom pulsante"],
  ["punch_in", "👊 Soco de zoom (início)"],
  ["glitch", "📟 Glitch digital"],
  ["vhs", "📼 VHS (fita antiga)"],
  ["freeze", "🧊 Congelar no final"],
];
