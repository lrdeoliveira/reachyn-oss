// 🎨 PARTS de estilo pro motor de imagem LOCAL (mmx). Espelha engine/internal/provider/image/
// styles.go — a FONTE DA VERDADE. Se mudar lá, mudar aqui (igual lib/imageStyles.ts faz com os
// rótulos). O engine monta o prompt como: PREFIXO (início, peso máx no difusor) + prompt + SUFIXO
// (qualidade + negativos). Reproduzimos o MESMO resultado do online no fluxo mmx, pra a mesma
// escolha de estilo dar a mesma imagem que o usuário já conhece.
//
// ⚠️ SERVER-ONLY (`.server`): usado só pela rota /api/image (Node). Os rótulos da UI vivem em
// lib/imageStyles.ts (client). A allowlist de estilos oferecidos ao usuário é IMAGE_STYLES de lá —
// este mapa cobre os mesmos slugs (+ os aposentados, caso uma peça antiga peça um deles).

// Prefixo — vai no INÍCIO do prompt (peso máximo). Sem ele, temas com forte viés visual
// sobrepõem o estilo e tudo vira realista.
export const STYLE_PREFIX: Record<string, string> = {
  realista: "Photorealistic cinematic photograph of ",
  "3d": "3D animated movie still in Pixar/Disney CGI style, stylized non-photorealistic characters: ",
  anime: "2D anime illustration in Japanese manga/anime art style (flat cel shading, not photorealistic, not 3D): ",
  comic: "2D American comic-book illustration, bold inked comic art (not photorealistic, not 3D): ",
  aquarela: "Watercolor painting illustration, soft washes and bleeds (not photorealistic): ",
  cyberpunk: "Cyberpunk neon-lit scene, futuristic high-tech atmosphere: ",
  minimalista: "Minimalist clean flat-design illustration, lots of negative space (not photorealistic): ",
  produto: "Professional product photography, studio lighting, clean seamless background: ",
  pintura: "Digital painting, painterly brushwork, concept-art style (not photorealistic): ",
  pixel: "Pixel-art illustration, retro 8-bit/16-bit video-game sprite style, crisp pixels and limited palette (not photorealistic, not 3D, not vector): ",
  epico: "Epic fantasy digital painting, monumental cinematic scale: ",
  macro: "Extreme macro photograph, shallow depth of field: ",
  livro: "Whimsical children's picture-book illustration, soft painterly style (not photorealistic): ",
  arquitetura: "Architectural HDR photograph, wide-angle composition: ",
  editorial: "High-fashion editorial magazine photograph, dramatic studio lighting: ",
  claymation: "Stop-motion claymation still, hand-sculpted clay texture (not photorealistic, not CGI): ",
};

// Sufixo — direcionamento de qualidade + negativos colado no FIM do prompt. Cópia literal do
// engine (com "Avoid: …") pra paridade byte-a-byte com o online.
export const STYLE_SUFFIX: Record<string, string> = {
  realista: ", cinematic premium commercial look, dramatic soft key light with rim light, professional color grading, rich textures, realistic materials and reflections, sharp focus on subject, shallow depth of field with creamy bokeh, ultra-high detail, 8K quality. Avoid: distorted anatomy, extra limbs, warped or fake text, letters, watermark, logo, visible AI artifacts, plastic look, blurry or noisy areas, banding, oversaturation.",
  "3d": ", 3D animated blockbuster look with Pixar-like charm, high-quality CGI, appealing character design, expressive emotion, physically-based rendering, ray-traced reflections, global illumination, cinematic key/fill/rim lighting, clean gradients, 4K-8K clarity. Avoid: text, logos, watermarks, uncanny faces, broken anatomy, extra fingers or limbs, low-res textures, banding, cheap plastic look, AI artifacts.",
  anime: ", anime illustration, crisp confident outlines with varied line weight, soft cel shading, expressive eyes and clean anatomy, layered detailed background, cinematic framing, harmonized vibrant palette, high resolution, stable facial features. Avoid: text overlays, logos, watermarks, low-res, blurry lines, muddy shading, distorted anatomy, extra fingers or limbs, uncanny faces, AI artifacts.",
  comic: ", modern American comic-book illustration (not photoreal), bold clean inks, confident dynamic anatomy, strong silhouette readability, dynamic perspective and cinematic panel framing, rich color separation, detailed costumes, atmospheric effects. Avoid: text, logos, watermarks, distorted anatomy, extra fingers or limbs, muddy colors, low-res textures, banding, visible AI artifacts.",
  aquarela: ", delicate watercolor textures, soft pigment bleeds, visible paper grain, organic color blending, light and airy, hand-painted feel. Avoid: text, logos, watermarks, harsh digital edges, photorealism, distorted anatomy, extra fingers, AI artifacts.",
  cyberpunk: ", neon magenta and cyan lighting, rain-slick reflections, holographic interfaces, dense futuristic city mood, cinematic high contrast, ultra-detailed. Avoid: text, logos, watermarks, distorted anatomy, extra limbs, muddy colors, banding, AI artifacts.",
  minimalista: ", simple geometric shapes, limited harmonious palette, flat vector look, generous negative space, modern and clean. Avoid: clutter, text, logos, watermarks, photorealism, noisy gradients, distorted shapes, AI artifacts.",
  produto: ", crisp studio softbox lighting, seamless clean background, sharp focus, premium commercial look, subtle reflections, ultra-high detail, 8K. Avoid: text, logos, watermarks, clutter, distorted shapes, oversaturation, AI artifacts.",
  pintura: ", expressive brush strokes, rich layered color, dramatic lighting, concept-art quality, detailed and atmospheric. Avoid: text, logos, watermarks, photorealism, flat lighting, distorted anatomy, extra fingers, AI artifacts.",
  pixel: ", pixel art, retro 8-bit/16-bit video-game sprite aesthetic, crisp hard pixel edges, limited vibrant palette, subtle dithering, clean readable shapes. Avoid: text, logos, watermarks, photorealism, blur, smooth gradients, anti-aliasing, 3D shading, AI artifacts.",
  epico: ", epic fantasy concept-art, dramatic god-rays and volumetric light, sweeping monumental scale, intricate ornate detail, rich saturated palette, painterly realism, cinematic wide framing. Avoid: text, logos, watermarks, modern elements, distorted anatomy, extra limbs, low detail, AI artifacts.",
  macro: ", extreme macro photography, razor-thin depth of field, intricate surface texture magnified, soft diffused lighting, striking detail on the subject, creamy out-of-focus background. Avoid: text, logos, watermarks, distorted proportions, blur on the subject itself, low detail, AI artifacts.",
  livro: ", whimsical children's picture-book illustration, soft rounded shapes, warm inviting color palette, gentle painterly texture, charming and friendly characters, storybook framing. Avoid: text, logos, watermarks, photorealism, dark or scary mood, distorted anatomy, extra fingers, AI artifacts.",
  arquitetura: ", architectural HDR photography, wide-angle interior/exterior composition, balanced bright exposure, crisp geometric lines, true-to-life materials, pristine polished finish, magazine-quality clarity. Avoid: text, logos, watermarks, distorted perspective, warped lines, clutter, AI artifacts.",
  editorial: ", high-fashion editorial magazine photography, bold confident styling, dramatic studio lighting, striking composition with generous negative space, premium glossy finish, art-directed mood. Avoid: text, logos, watermarks, distorted anatomy, extra limbs, amateur look, AI artifacts.",
  claymation: ", stop-motion claymation aesthetic, hand-sculpted clay texture with visible fingerprints and tool marks, charming imperfect forms, warm practical studio lighting, tactile handcrafted feel. Avoid: text, logos, watermarks, smooth CGI look, photorealism, distorted anatomy, AI artifacts.",
};

// Slugs oferecidos ao usuário (default = realista). Bate com IMAGE_STYLES de lib/imageStyles.ts.
export const STYLE_SLUGS = Object.keys(STYLE_PREFIX);

/** Prefixo + sufixo do estilo. Estilo desconhecido cai em "realista" (igual StyleParts do engine). */
export function styleParts(style: string): { prefix: string; suffix: string } {
  const s = STYLE_PREFIX[style] ? style : "realista";
  return { prefix: STYLE_PREFIX[s], suffix: STYLE_SUFFIX[s] };
}

/** Tamanho do wrapper (prefixo+sufixo) — pra clampar a parte VARIÁVEL do prompt com folga certa
 *  e o prompt final não estourar o limite do image-01 (~1500 chars). Igual WrapLen do engine. */
export function wrapLen(style: string): number {
  const { prefix, suffix } = styleParts(style);
  return prefix.length + suffix.length;
}

/** Breve do meio/estilo pra guiar a EXPANSÃO do prompt (stage-1): reusa o prefixo sem os dois-pontos
 *  finais, pra o corpo gerado já nascer no meio certo (ilustração/3D/pintura), não em foto. */
export function mediumBrief(style: string): string {
  return styleParts(style).prefix.replace(/[:,]\s*$/, "").trim();
}
