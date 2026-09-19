// 🖼️ TÉCNICA da imagem — do que ela é feita. Catálogo ÚNICO, espelhando o do engine
// (engine/internal/provider/image/styles.go), que é a fonte da verdade: slug fora dessa lista
// o engine ignora em silêncio e a imagem sai no estilo default.
//
// Por que existe este arquivo: a lista vivia copiada em Studio.tsx e Personagens.tsx, e as
// cópias divergiram — Personagens ficou com 11 das 19 e nunca ganhou epico/macro/livro/
// arquitetura/editorial/claymation, então um personagem não conseguia nascer nos mesmos
// estilos que a Mídia oferecia. Uma cópia só acaba com a divergência por construção.
//
// Fora da lista de propósito:
//  · `logo`   — o engine tem, mas é tratamento de logotipo (botão "Gerar logo" + preset próprio).
//  · `noir` e `vintage` — são LOOK DE COR, não técnica. Migraram para o seletor 🎨 Cor
//    (GRADE_OPTIONS em lib/effects), que aplica filtro determinístico. Deixá-los aqui fazia o
//    usuário escolher cor no lugar errado e brigar com o grade.
//
// ⚠️ O Estúdio de Animação NÃO usa esta lista: ele tem vocabulário próprio de animação
// (`cartoon`, `stickman`) com mapa de prompt próprio em AnimationFlow.php. São catálogos
// diferentes de propósito — não unificar sem mexer no backend de lá.
export const IMAGE_STYLES: [string, string][] = [
  ["realista", "📷 Realista (foto)"], ["3d", "🧸 3D / Pixar"], ["anime", "🎌 Anime / Mangá"],
  ["comic", "💥 Quadrinhos"], ["aquarela", "🎨 Aquarela"], ["cyberpunk", "🌃 Cyberpunk"],
  ["minimalista", "◻️ Minimalista"], ["produto", "📦 Foto de produto"],
  ["pintura", "🖌️ Pintura digital"], ["pixel", "👾 Pixel art"],
  ["epico", "⚔️ Épico / Fantasia"], ["macro", "🔬 Macro"],
  ["livro", "📖 Livro infantil"], ["arquitetura", "🏛️ Arquitetura HDR"], ["editorial", "📰 Editorial"],
  ["claymation", "🧱 Claymation"],
  // 📰 Colagem editorial (web-doc): a linguagem do jornalismo explicativo em motion graphics —
  // recorte de arquivo sobre campo de cor chapado, papel, retícula e traço de marcador.
  // É TÉCNICA e não persona de propósito: medido em 2026-08-02, a persona sozinha perdia a
  // disputa com a técnica "realista" e o mesmo pedido saía como foto. Aqui ela compete de igual.
  ["colagem", "📰 Colagem editorial (web-doc)"],
];

/** Opções de um <select> de técnica, garantindo que o valor ATUAL apareça na lista.
 *  Sem isto, peça antiga gravada com um slug aposentado (ex.: "cinematico", do canvas de roteiro)
 *  abre com o seletor em branco — e o primeiro clique em qualquer lugar troca o estilo dela sem
 *  o usuário pedir. */
export function styleOptions(atual?: string): [string, string][] {
  const s = (atual || "").trim();
  if (!s || IMAGE_STYLES.some(([v]) => v === s)) return IMAGE_STYLES;
  return [...IMAGE_STYLES, [s, `${imageStyleLabel(s)} (antigo)`]];
}

/** Rótulo de um slug de técnica. Aceita slugs aposentados (noir/vintage) e desconhecidos para
 *  que peça ANTIGA, gravada com um estilo que saiu da lista, continue legível na galeria. */
export const imageStyleLabel = (slug: string): string =>
  IMAGE_STYLES.find(([v]) => v === slug)?.[1]
  ?? ({ noir: "🎞️ Noir", vintage: "📺 Vintage", logo: "🏷️ Logo" }[slug] ?? slug);
