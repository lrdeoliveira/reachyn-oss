// 🎮 Catálogo de ASSETS DE JOGO da aba Sprites (modo "Assets de jogo") — tipos, estilos,
// tamanhos e o montador de prompt. Derivado de duas fontes (2026-08-09):
//   1. O acervo Craftpix do Luciano (564 packs em /Volumes/M5SSD/Unity Assets/Craftpix):
//      as categorias reais de mercado — characters, tilesets, backgrounds c/ parallax,
//      GUI kits, ícones, props — e os estilos que se repetem (vetor cartoon, pixel art,
//      chibi, tiny style, flat, top-down/TDS).
//   2. Pesquisa de convenções da indústria (craftpix.net / itch.io / Unity Asset Store):
//      tile sizes 16/32/64/128, power-of-two, parallax em camadas, e o que "seamless"
//      exige (borda esquerda casa com a direita pixel a pixel; topo com a base).
// A UX espelha os 3 eixos ortogonais dos marketplaces: TIPO × ESTILO × TAMANHO (+ seamless).

export type AssetKind =
  | "tileset-platformer"
  | "tileset-topdown"
  | "tileset-isometrico"
  | "background"
  | "textura"
  | "gui"
  | "icones"
  | "props";

export type AssetKindInfo = {
  label: string;
  hint: string;
  aspect: string; // aspecto pedido ao motor
  sizes: [number, string][]; // tamanho lógico (tile/ícone/base) → rótulo
  sizeLabel: string;
  seamless: "off" | "opt" | "on" | "x"; // off = não se aplica · opt = opcional · on = sempre · x = só horizontal
};

export const ASSET_KINDS: Record<AssetKind, AssetKindInfo> = {
  "tileset-platformer": {
    label: "🧱 Tileset · plataforma",
    hint: "Chão, bordas, rampas, plataformas flutuantes e decoração — visão lateral.",
    aspect: "1:1",
    sizes: [[16, "16 px (retrô denso)"], [32, "32 px (padrão)"], [64, "64 px (HD)"], [128, "128 px (HD+)"]],
    sizeLabel: "Tile",
    seamless: "opt",
  },
  "tileset-topdown": {
    label: "🗺️ Tileset · top-down",
    hint: "Terreno visto de cima (grama, pedra, água, caminhos) com transições — estilo RPG/TDS.",
    aspect: "1:1",
    sizes: [[16, "16 px (retrô denso)"], [32, "32 px (padrão)"], [64, "64 px (HD)"], [128, "128 px (HD+)"]],
    sizeLabel: "Tile",
    seamless: "opt",
  },
  "tileset-isometrico": {
    label: "💎 Tileset · isométrico",
    hint: "Losangos 2:1 (estratégia/city-builder) — chão, blocos e props na mesma projeção.",
    aspect: "1:1",
    sizes: [[64, "64×32 (padrão)"], [128, "128×64 (HD)"], [256, "256×128 (HD+)"]],
    sizeLabel: "Tile (base)",
    seamless: "opt",
  },
  background: {
    label: "🏞️ Background · parallax",
    hint: "Cenário horizontal de fase — céu, longe, meio e perto, pronto pra rolagem.",
    aspect: "16:9",
    sizes: [[1920, "1920×1080 (padrão)"], [1080, "1080×1920 (vertical mobile)"]],
    sizeLabel: "Base",
    seamless: "x",
  },
  textura: {
    label: "🧵 Textura seamless",
    hint: "Pedra, madeira, grama, metal… repete nos dois eixos sem costura.",
    aspect: "1:1",
    sizes: [[512, "512 px (mobile)"], [1024, "1024 px (padrão)"], [2048, "2048 px (2K)"]],
    sizeLabel: "Textura",
    seamless: "on",
  },
  gui: {
    label: "🖼️ GUI / interface",
    hint: "Kit de UI do jogo: botões (normal/hover/pressed), painéis, barras de HP, molduras.",
    aspect: "1:1",
    sizes: [[1024, "prancha 1024 px"], [2048, "prancha 2048 px"]],
    sizeLabel: "Prancha",
    seamless: "off",
  },
  icones: {
    label: "⚔️ Ícones de itens",
    hint: "Grade de ícones RPG (armas, poções, skills, loot) no mesmo enquadramento.",
    aspect: "1:1",
    sizes: [[32, "32 px cada"], [64, "64 px cada"], [128, "128 px cada"]],
    sizeLabel: "Ícone",
    seamless: "off",
  },
  props: {
    label: "📦 Props / objetos",
    hint: "Objetos avulsos (baú, porta, árvore, pedra, móvel) em fundo chroma pra recorte.",
    aspect: "1:1",
    sizes: [[128, "128 px"], [256, "256 px (padrão)"], [512, "512 px"]],
    sizeLabel: "Objeto",
    seamless: "off",
  },
};

// Estilos visuais consagrados de jogo 2D/2.5D — cada um vira um bloco "Style:" do prompt.
// A taxonomia vem da pesquisa (famílias pixel art / desenhada / 2.5D) + o que o acervo
// Craftpix confirma como estilo de mercado (vetor cartoon é a maioria; pixel art, chibi e
// tiny style são as três famílias nomeadas nos títulos dos packs).
export const GAME_STYLES: [string, string, string][] = [
  // [slug, rótulo, bloco de estilo]
  ["vetor-cartoon", "Vetor cartoon (Craftpix)", "clean vector cartoon game art, thick smooth outlines, flat saturated colors with simple two-tone shading, rounded friendly shapes, mobile-game polish"],
  ["pixel-16bit", "Pixel art 16-bit (SNES)", "16-bit SNES-era pixel art, crisp hard pixel edges, limited 16-32 color palette, subtle dithering, chunky readable silhouette, no anti-aliasing"],
  ["pixel-8bit", "Pixel art 8-bit (retrô)", "8-bit NES-era pixel art, very limited 4-color palette per element, coarse pixel grid, hard outlines, no anti-aliasing, retro arcade look"],
  ["pixel-hibit", "Pixel art moderna (hi-bit)", "modern hi-bit pixel art, rich palette, dynamic lighting and glow accents, detailed clusters, Dead Cells / Eastward inspired, still crisp pixel edges"],
  ["chibi", "Chibi", "chibi super-deformed style, big head and small compact body (1:2 proportion), large expressive eyes, cute rounded shapes, clean outlines"],
  ["tiny", "Tiny style (mini)", "tiny-style miniature game characters and props, extremely simplified readable shapes, small scale, bold silhouettes, minimal detail"],
  ["flat", "Flat / minimalista", "flat design game art, simple geometric shapes, solid colors, no outlines, generous negative space, modern minimal look"],
  ["hand-painted", "Pintado à mão", "hand-painted game art, visible painterly brushwork, soft atmospheric lighting, storybook illustration quality, Hollow Knight / Ori inspired"],
  ["dark-fantasy", "Dark fantasy", "dark fantasy game art, desaturated grim palette, high contrast, heavy ink shading, gothic ominous mood, Darkest Dungeon inspired"],
  ["sci-fi", "Sci-fi / cyberpunk", "sci-fi game art, sleek futuristic metal surfaces, neon glow accents, holographic details, cyberpunk atmosphere"],
  ["pre-rendered", "Pré-renderizado 3D (anos 90)", "pre-rendered 3D sprite look, 90s CGI render with glossy plastic shading and studio lighting, Donkey Kong Country inspired, delivered as clean 2D game art"],
];

export const GAME_STYLE_LABELS: [string, string][] = GAME_STYLES.map(([v, l]) => [v, l]);

export function styleBlock(slug: string): string {
  return GAME_STYLES.find(([v]) => v === slug)?.[2] ?? GAME_STYLES[0][2];
}

// Cláusula tileable — o requisito técnico do seamless: as bordas opostas casam pixel a
// pixel e nada de alto contraste morre cortado na borda (senão a repetição denuncia).
function seamlessClause(axes: "x" | "xy"): string {
  return axes === "xy"
    ? `Seamless/tileable requirement (critical):
- the LEFT edge must continue perfectly into the RIGHT edge, and the TOP edge into the BOTTOM edge, pixel-for-pixel
- no distinct high-contrast element may be cut off at any border
- no obvious focal "hotspot" that would reveal the repetition when tiled
- even lighting across the whole image, no vignette, no directional gradient`
    : `Horizontal loop requirement (critical):
- the LEFT edge must continue perfectly into the RIGHT edge, pixel-for-pixel, so the image scrolls in an endless loop
- no distinct element may be cut off at the left or right border
- no horizontal lighting gradient (the loop seam would show)`;
}

const AVOID = `Avoid:
- photorealism, text, letters, numbers, logo, watermark, UI overlays
- anti-aliased halos around cutout elements
- perspective inconsistency between elements`;

// Miolo por tipo — o que a indústria espera de cada categoria (seção 2 da pesquisa +
// estrutura interna dos packs Craftpix amostrados).
function kindBody(kind: AssetKind, size: number): string {
  switch (kind) {
    case "tileset-platformer":
      return `Asset type: side-view PLATFORMER TILESET sheet on a ${size}x${size}px tile grid.
Include, each aligned to the ${size}px grid: ground top tiles with left/right edges and inner corners, slope tiles, a floating platform (left/middle/right), and a few decoration elements (bush, rock, small plant).
Lay the tiles out cleanly separated on the sheet, consistent light direction across all tiles.`;
    case "tileset-topdown":
      return `Asset type: TOP-DOWN TILESET sheet on a ${size}x${size}px tile grid, seen from directly above (RPG style).
Include, each aligned to the ${size}px grid: base terrain tiles, edge/transition tiles between two terrains, corner tiles, and a few decoration tiles (small plant, stones, path detail).
Consistent overhead lighting, no cast perspective shadows.`;
    case "tileset-isometrico":
      return `Asset type: ISOMETRIC TILESET in strict 2:1 dimetric projection (isometric game grade), diamond floor tiles with a ${size}px-wide base.
Include: flat ground diamonds in 2-3 terrain variants, one raised block tile, and 2-3 props in the exact same projection.
All elements share the same isometric angle — no vanishing point, no perspective distortion.`;
    case "background":
      return `Asset type: horizontal 2D game BACKGROUND for a side-scrolling level, composed as clear depth bands (sky, far layer, middle layer, near/ground layer) so each band can be separated for parallax scrolling.
Wide panoramic composition designed for a ${size === 1080 ? "1080x1920 vertical" : "1920x1080"} screen, painterly depth via color/value separation between bands.`;
    case "textura":
      return `Asset type: SEAMLESS TEXTURE, ${size}x${size}px, a uniform surface material filling the entire frame edge to edge.
Pure material only — no objects, no horizon, no borders or frames.`;
    case "gui":
      return `Asset type: game GUI KIT sheet with the core interface pieces of one cohesive theme:
a large panel/window frame (9-slice friendly, plain center), 3 button states of the same button (normal / hover / pressed), a progress or HP bar (empty + full), 2 small square icon frames, and a close button.
Pieces laid out cleanly separated on a neutral dark backdrop, generous spacing, consistent theme across all pieces.`;
    case "icones":
      return `Asset type: RPG ITEM ICON set — a clean grid of 3x3 distinct item icons, each designed to read at ${size}x${size}px, all in identical square frames with the same background treatment, same camera angle and same lighting.
Icons centered in their cells with even margins.`;
    case "props":
      return `Asset type: standalone game PROP/OBJECT designed to read at ${size}x${size}px, a single object centered in the frame, full object visible.
Background: solid removable chroma color #FF00FF filling everything outside the object silhouette — no scenery, no floor, no shadow outside the silhouette.`;
  }
}

/** Monta o prompt final do asset: tipo + tema + estilo + (seamless) + negativos. */
export function assetPrompt(kind: AssetKind, tema: string, styleSlug: string, size: number, seamless: boolean): string {
  const info = ASSET_KINDS[kind];
  const partes = [
    kindBody(kind, size),
    `Theme: ${tema}.`,
    `Style:\n- ${styleBlock(styleSlug)}\n- consistent style, palette and light direction across every element`,
  ];
  if (info.seamless === "on" || (seamless && info.seamless !== "off")) {
    partes.push(seamlessClause(info.seamless === "x" ? "x" : "xy"));
  }
  partes.push(AVOID);
  return partes.join("\n\n");
}
