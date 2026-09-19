// Package image — geração de imagem (text-to-image) via MiniMax image-01, com presets de
// estilo (biblioteca Padrão de Excelência: 95-premium / 54-pixar / 62-anime / 63-comic).
package image

// Styles — sufixo de direcionamento colado no prompt do assunto. Default "realista" = master 95.
var Styles = map[string]string{
	"realista":    ", cinematic premium commercial look, dramatic soft key light with rim light, professional color grading, rich textures, realistic materials and reflections, sharp focus on subject, shallow depth of field with creamy bokeh, ultra-high detail, 8K quality. Avoid: distorted anatomy, extra limbs, warped or fake text, letters, watermark, logo, visible AI artifacts, plastic look, blurry or noisy areas, banding, oversaturation.",
	"3d":          ", 3D animated blockbuster look with Pixar-like charm, high-quality CGI, appealing character design, expressive emotion, physically-based rendering, ray-traced reflections, global illumination, cinematic key/fill/rim lighting, clean gradients, 4K-8K clarity. Avoid: text, logos, watermarks, uncanny faces, broken anatomy, extra fingers or limbs, low-res textures, banding, cheap plastic look, AI artifacts.",
	"anime":       ", anime illustration, crisp confident outlines with varied line weight, soft cel shading, expressive eyes and clean anatomy, layered detailed background, cinematic framing, harmonized vibrant palette, high resolution, stable facial features. Avoid: text overlays, logos, watermarks, low-res, blurry lines, muddy shading, distorted anatomy, extra fingers or limbs, uncanny faces, AI artifacts.",
	"comic":       ", modern American comic-book illustration (not photoreal), bold clean inks, confident dynamic anatomy, strong silhouette readability, dynamic perspective and cinematic panel framing, rich color separation, detailed costumes, atmospheric effects. Avoid: text, logos, watermarks, distorted anatomy, extra fingers or limbs, muddy colors, low-res textures, banding, visible AI artifacts.",
	"aquarela":    ", delicate watercolor textures, soft pigment bleeds, visible paper grain, organic color blending, light and airy, hand-painted feel. Avoid: text, logos, watermarks, harsh digital edges, photorealism, distorted anatomy, extra fingers, AI artifacts.",
	"cyberpunk":   ", neon magenta and cyan lighting, rain-slick reflections, holographic interfaces, dense futuristic city mood, cinematic high contrast, ultra-detailed. Avoid: text, logos, watermarks, distorted anatomy, extra limbs, muddy colors, banding, AI artifacts.",
	"minimalista": ", simple geometric shapes, limited harmonious palette, flat vector look, generous negative space, modern and clean. Avoid: clutter, text, logos, watermarks, photorealism, noisy gradients, distorted shapes, AI artifacts.",
	"vintage":     ", analog film grain, faded warm tones, retro color grading, soft vignette, nostalgic 70s/80s mood. Avoid: text, logos, watermarks, modern elements, distorted anatomy, extra fingers, AI artifacts.",
	"produto":     ", crisp studio softbox lighting, seamless clean background, sharp focus, premium commercial look, subtle reflections, ultra-high detail, 8K. Avoid: text, logos, watermarks, clutter, distorted shapes, oversaturation, AI artifacts.",
	"pintura":     ", expressive brush strokes, rich layered color, dramatic lighting, concept-art quality, detailed and atmospheric. Avoid: text, logos, watermarks, photorealism, flat lighting, distorted anatomy, extra fingers, AI artifacts.",
	"pixel":       ", pixel art, retro 8-bit/16-bit video-game sprite aesthetic, crisp hard pixel edges, limited vibrant palette, subtle dithering, clean readable shapes. Avoid: text, logos, watermarks, photorealism, blur, smooth gradients, anti-aliasing, 3D shading, AI artifacts.",
	// logo — tratamento genérico de LOGOTIPO (o look específico vem do preset montado no console:
	// minimalista/mascote/emblema/lettering/moderno). Puxa marca vetorial, centrada, fundo limpo.
	"logo": ", clean crisp vector shapes, bold readable silhouette, balanced centered composition, solid flat colors, strong contrast, scalable brand icon, plain uncluttered background, professional brand identity, high quality. Avoid: photorealism, 3D render, busy or cluttered background, distorted or misspelled text, watermark, extra unrelated elements, noise, AI artifacts.",
	// +7 estilos (Sprint C "Qualidade Hollywood", 2026-07-04).
	"noir":        ", film noir cinematography, high-contrast black-and-white, deep chiaroscuro shadows, venetian-blind light patterns, dramatic moody atmosphere, 1940s detective-movie aesthetic, sharp focus. Avoid: color, text, logos, watermarks, distorted anatomy, extra limbs, flat lighting, AI artifacts.",
	"epico":       ", epic fantasy concept-art, dramatic god-rays and volumetric light, sweeping monumental scale, intricate ornate detail, rich saturated palette, painterly realism, cinematic wide framing. Avoid: text, logos, watermarks, modern elements, distorted anatomy, extra limbs, low detail, AI artifacts.",
	"macro":       ", extreme macro photography, razor-thin depth of field, intricate surface texture magnified, soft diffused lighting, striking detail on the subject, creamy out-of-focus background. Avoid: text, logos, watermarks, distorted proportions, blur on the subject itself, low detail, AI artifacts.",
	"livro":       ", whimsical children's picture-book illustration, soft rounded shapes, warm inviting color palette, gentle painterly texture, charming and friendly characters, storybook framing. Avoid: text, logos, watermarks, photorealism, dark or scary mood, distorted anatomy, extra fingers, AI artifacts.",
	"arquitetura": ", architectural HDR photography, wide-angle interior/exterior composition, balanced bright exposure, crisp geometric lines, true-to-life materials, pristine polished finish, magazine-quality clarity. Avoid: text, logos, watermarks, distorted perspective, warped lines, clutter, AI artifacts.",
	"editorial":   ", high-fashion editorial magazine photography, bold confident styling, dramatic studio lighting, striking composition with generous negative space, premium glossy finish, art-directed mood. Avoid: text, logos, watermarks, distorted anatomy, extra limbs, amateur look, AI artifacts.",
	// 📰 COLAGEM EDITORIAL (web-doc): a linguagem de motion graphics jornalístico — recorte de
	// arquivo sobre campo de cor chapado, não cena filmada. O NEGATIVO é a parte que faz funcionar:
	// sem barrar fotorrealismo e texto legível o modelo devolve foto bonita (medido em 2026-08-02:
	// com a técnica "realista" o mesmo pedido saiu foto de balão; com esta, colagem correta).
	// Texto legível é barrado de propósito — IA erra letra, e a legenda real é queimada na montagem.
	"colagem":    ", editorial mixed-media collage in the style of explanatory-journalism motion graphics: archival photo cutouts with rough torn white paper borders, flat bold color fields (warm yellow, off-white paper, deep navy, coral red), halftone dot texture and paper grain, tape strips and subtle drop shadows, hand-drawn black marker circles, arrows and underlines, abstract unlabeled charts and stylized flat maps, generous negative space with FEW elements per frame, one dominant color per composition. Avoid: photorealism, live-action footage, 3D render, readable text, letters, words, numbers, captions, watermark, logo, talking characters, lip-sync, cluttered composition, color drift.",
	"claymation": ", stop-motion claymation aesthetic, hand-sculpted clay texture with visible fingerprints and tool marks, charming imperfect forms, warm practical studio lighting, tactile handcrafted feel. Avoid: text, logos, watermarks, smooth CGI look, photorealism, distorted anatomy, AI artifacts.",
}

// StylePrefix — vai no INÍCIO do prompt (peso máximo no difusor). Sem isso, temas
// com forte viés visual (ex: carro futurista) sobrepõem o estilo e tudo vira realista.
var StylePrefix = map[string]string{
	"realista":    "Photorealistic cinematic photograph of ",
	"3d":          "3D animated movie still in Pixar/Disney CGI style, stylized non-photorealistic characters: ",
	"anime":       "2D anime illustration in Japanese manga/anime art style (flat cel shading, not photorealistic, not 3D): ",
	"comic":       "2D American comic-book illustration, bold inked comic art (not photorealistic, not 3D): ",
	"aquarela":    "Watercolor painting illustration, soft washes and bleeds (not photorealistic): ",
	"cyberpunk":   "Cyberpunk neon-lit scene, futuristic high-tech atmosphere: ",
	"minimalista": "Minimalist clean flat-design illustration, lots of negative space (not photorealistic): ",
	"vintage":     "Vintage retro aesthetic, aged analog film look, nostalgic tones: ",
	"produto":     "Professional product photography, studio lighting, clean seamless background: ",
	"pintura":     "Digital painting, painterly brushwork, concept-art style (not photorealistic): ",
	"pixel":       "Pixel-art illustration, retro 8-bit/16-bit video-game sprite style, crisp pixels and limited palette (not photorealistic, not 3D, not vector): ",
	"logo":        "Professional logo design, flat vector brand mark, centered on a plain solid background: ",
	"noir":        "Film noir black-and-white photograph, high-contrast dramatic shadows: ",
	"epico":       "Epic fantasy digital painting, monumental cinematic scale: ",
	"macro":       "Extreme macro photograph, shallow depth of field: ",
	"livro":       "Whimsical children's picture-book illustration, soft painterly style (not photorealistic): ",
	"arquitetura": "Architectural HDR photograph, wide-angle composition: ",
	"editorial":   "High-fashion editorial magazine photograph, dramatic studio lighting: ",
	"colagem":     "Editorial mixed-media paper-collage motion-graphics frame, flat non-photorealistic cut-paper illustration (NOT a photograph, NOT live-action): ",
	"claymation":  "Stop-motion claymation still, hand-sculpted clay texture (not photorealistic, not CGI): ",
}

// StyledPrompt — monta o prompt com o estilo: prefixo (início, peso máx) + prompt + sufixo
// de qualidade. Estilo desconhecido cai em "realista".
func StyledPrompt(prompt, style string) string {
	prefix, suffix := StyleParts(style)
	return prefix + prompt + suffix
}

// StyleParts — prefixo (início, peso máx) e sufixo (qualidade/negativos) que StyledPrompt cola
// em volta do prompt pro estilo dado. Estilo desconhecido cai em "realista". Exportada pro
// endpoint /v1/stylewrap (o console monta o prompt EXATO pro cliente copiar/usar fora do Reachyn).
func StyleParts(style string) (prefix, suffix string) {
	suffix, ok := Styles[style]
	if !ok {
		suffix = Styles["realista"]
		style = "realista"
	}
	return StylePrefix[style], suffix
}

// WrapLen — tamanho (em runas) do prefixo+sufixo que StyledPrompt cola em volta do prompt
// pro estilo dado. Usado pra clampar a parte VARIÁVEL do prompt com a folga certa (o maior
// wrapper — "anime" — tem ~496 runas; um clamp fixo sem contar isso estoura o limite do
// provider mesmo depois de truncar, caso real 2026-07-17: 1300+wrapper > 1500 sempre 422).
func WrapLen(style string) int {
	prefix, suffix := StyleParts(style)
	return len([]rune(prefix)) + len([]rune(suffix))
}
