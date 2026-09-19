// spec.go — FICHA DE CENA estruturada (S1). Cada cena/beat carrega um `spec` de DADOS
// (plano de câmera, movimento, iluminação, emoção) que o roteirista/diretor (LLM) preenche por
// cena — em vez de o enquadramento/luz serem string solta montada no front. Benefícios:
//  1. Planos VARIADOS por cena por padrão (a IA distribui a cobertura) — mata a monotonia visual.
//  2. Luz COERENTE (a IA só muda quando a narrativa muda de tempo/lugar).
//  3. Movimento de câmera DIRIGIDO nas Histórias (o video_prompt deixa de nascer "sem direção").
//  4. Vira fonte da verdade do shot-card (o card lê os mesmos dados que alimentam a geração).
//
// O vocabulário espelha o SHOT_OPTIONS/LIGHT_OPTIONS da UI (web/components/*.tsx) — mesma key,
// mesma frase EN — pra a Ficha e os seletores manuais falarem a mesma língua.
package content

import "strings"

// SceneSpec — a "ficha" cinematográfica de UMA cena/beat. Todos os campos são OPCIONAIS (spec
// nulo/vazio = comportamento antigo, retrocompatível): a key casa com o vocabulário; valor fora
// do vocabulário é ignorado na composição (mas preservado pro card). Emotion é texto curto livre
// (direção de ator/energia) — não tem enum.
type SceneSpec struct {
	Shot     string `json:"shot,omitempty"`     // plano de câmera (key de shotDirectives)
	Movement string `json:"movement,omitempty"` // movimento de câmera (key de moveDirectives)
	Light    string `json:"light,omitempty"`    // iluminação (key de lightDirectives)
	Emotion  string `json:"emotion,omitempty"`  // energia/expressão do sujeito (texto curto, sem enum)
}

// shotDirectives — 15 planos (idênticos ao SHOT_OPTIONS da UI). Frase EN prependável ao sujeito.
var shotDirectives = map[string]string{
	"medio":                "Medium shot",
	"americano":            "American shot (cowboy shot, from mid-thigh up)",
	"closeup":              "Close-up shot on the subject",
	"perfil":               "Profile shot (side view)",
	"contraplongee":        "Low-angle shot (camera looking up, emphasizing power and grandeur)",
	"plongee":              "High-angle shot (camera looking down)",
	"overshoulder":         "Over-the-shoulder shot",
	"panoramico":           "Wide establishing shot of the environment, subject small in the frame",
	"heroshot":             "Hero shot, subject centered in an epic, powerful pose",
	"overshoulder_aberto":  "Wide over-the-shoulder shot, environment dominating the frame",
	"overshoulder_fechado": "Tight over-the-shoulder shot, subject filling most of the frame",
	"zenital":              "Zenithal shot (straight top-down bird's-eye view)",
	"extreme_closeup":      "Extreme close-up, tightly framed",
	"holandes":             "Dutch angle shot (tilted, diagonal horizon)",
	"aberto_final":         "Wide cinematic establishing shot, epic final wide frame",
}

// lightDirectives — 10 estilos de luz (idênticos ao LIGHT_OPTIONS da UI).
var lightDirectives = map[string]string{
	"low_key":    "dramatic low-key chiaroscuro lighting, deep shadows, a single hard key light, moody high contrast",
	"rim":        "strong backlight and rim lighting outlining the subject against a dark background",
	"volumetric": "cinematic volumetric lighting with god rays cutting through atmospheric haze",
	"golden":     "warm golden-hour sunlight, low sun, long soft shadows, gentle lens flare",
	"blue_hour":  "cool blue-hour twilight, soft ambient light, distant lights beginning to glow",
	"neon":       "moody nighttime neon lighting, saturated colored light and reflections on wet surfaces",
	"hard_sun":   "harsh direct midday sunlight, hard-edged shadows, very high contrast",
	"soft":       "soft even studio lighting from a large softbox, gentle wraparound shadows",
	"firelight":  "warm flickering firelight and candlelight, intimate low glow, amber tones",
	"overcast":   "soft diffused overcast light, flat even shadows, muted natural tones",
}

// moveDirectives — movimento de CÂMERA (não descreve o sujeito → seguro pra i2v: nunca redesenha
// o personagem). Preenche o video_prompt das Histórias (antes nascia vazio, "sem direção").
//
// ⚠️ Câmera parada ≠ cena parada. O texto de "static" era "only the scene breathes", que o modelo
// lê como "não mexa em nada": medido no projeto 20, as duas cenas com este preset ficaram entre
// 0,09 e 0,51 de movimento — contra 3,3 da cena com push-in, e contra 1,09 da pior cena do
// projeto 19, a que o cliente já tinha reclamado que estava "agarrada". Agora a frase trava a
// CÂMERA e pede explicitamente que o sujeito e o ambiente continuem se movendo dentro do quadro.
var moveDirectives = map[string]string{
	"static":    "The camera is locked off on a tripod and does not move, pan or zoom; within that fixed frame the subject keeps moving and acting naturally, and the environment stays alive (fabric, hair, dust and light shifting).",
	"push_in":   "Slow push-in: the camera glides forward toward the subject at a calm pace.",
	"pull_out":  "Slow pull-out: the camera glides backward, gradually revealing the surroundings.",
	"pan_left":  "The camera pans left in one smooth continuous move.",
	"pan_right": "The camera pans right in one smooth continuous move.",
	"tilt_up":   "The camera tilts up smoothly, lifting the gaze.",
	"tilt_down": "The camera tilts down smoothly.",
	"orbit":     "The camera orbits around the subject in a smooth arc.",
	"crane_up":  "The camera cranes up and back, opening up the frame.",
	"handheld":  "Subtle handheld motion, organic and alive, a gentle drift.",
	"dolly":     "Lateral dolly move across the scene at a calm, steady pace.",
}

// ShotKeys/LightKeys/MoveKeys — o vocabulário aceito, pra o prompt LISTAR ao modelo (assim ele
// escolhe uma key válida em vez de inventar). Ordenação estável (a mesma da UI onde importa).
var (
	shotKeysList  = "medio, americano, closeup, perfil, contraplongee, plongee, overshoulder, panoramico, heroshot, overshoulder_aberto, overshoulder_fechado, zenital, extreme_closeup, holandes, aberto_final"
	lightKeysList = "low_key, rim, volumetric, golden, blue_hour, neon, hard_sun, soft, firelight, overcast"
	moveKeysList  = "static, push_in, pull_out, pan_left, pan_right, tilt_up, tilt_down, orbit, crane_up, handheld, dolly"
)

// specImageDirective — a frase a APENSAR ao prompt de IMAGEM (plano + luz + emoção). Vazio quando
// o spec não tem nada aproveitável (retrocompatível). Não descreve identidade — só enquadramento,
// luz e energia (a identidade vem do lock/da referência).
func specImageDirective(sp *SceneSpec) string {
	if sp == nil {
		return ""
	}
	var parts []string
	if d := shotDirectives[strings.TrimSpace(sp.Shot)]; d != "" {
		parts = append(parts, d)
	}
	if d := lightDirectives[strings.TrimSpace(sp.Light)]; d != "" {
		parts = append(parts, d)
	}
	if e := strings.TrimSpace(sp.Emotion); e != "" {
		parts = append(parts, "the subject's expression and energy read as "+clip(e, 120))
	}
	if len(parts) == 0 {
		return ""
	}
	// Frase própria, no fim do prompt: "Cinematography: <plano>, <luz>, <emoção>."
	return " Cinematography: " + strings.Join(parts, ", ") + "."
}

// specMoveDirective — a frase de MOVIMENTO de câmera (só câmera). Preenche/inicia o video_prompt.
func specMoveDirective(sp *SceneSpec) string {
	if sp == nil {
		return ""
	}
	return strings.TrimSpace(moveDirectives[strings.TrimSpace(sp.Movement)])
}

// paletteDirectives — PALETA DE COR do projeto (S3, direção de arte). Preset → frase EN de cor.
// Texto livre também é aceito (o console passa a key OU o texto direto). É o que faz N cenas
// parecerem UM filme (coordena com o color grade da montagem). Vazio = sem direção de cor.
var paletteDirectives = map[string]string{
	"teal_orange": "teal-and-orange blockbuster grade, teal shadows, warm orange skin tones, high subject/background separation",
	"warm_gold":   "warm golden earthy palette, amber and honey tones, cozy and premium",
	"cold_blue":   "cool desaturated blue-gray palette, clean and modern, cinematic and moody",
	"pastel":      "soft pastel palette, gentle muted colors, airy and light",
	"neon_noir":   "neon noir grade: deep blacks, magenta-cyan practicals, wet reflections, sodium amber accents, anamorphic flare",
	"nordic":      "muted nordic palette, cold neutrals, soft daylight, understated and elegant",
	"vibrant":     "vibrant saturated palette, bold punchy colors, high energy",
	"mono_bw":     "classic noir black and white: chiaroscuro contrast, venetian-blind shadows, silver highlights, 35mm B&W grain",
	"sepia":       "warm sepia/vintage palette, faded film tones, nostalgic",
	"earth":       "natural earth-tone palette, greens, browns and stone, grounded and organic",
	// 🎨 Presets cinematográficos (biblioteca de color grading da casa, 2026-07-17): looks
	// nomeados por referência de cinema — frases compactas prontas pro prompt de imagem/vídeo.
	"golden_hour":    "golden hour naturalism: honey-gold backlight, organic lens flares, lifted warm shadows, filmic natural light",
	"matrix_green":   "digital green grade: emerald midtone cast, olive skin, crushed cool blacks, monochrome-green world",
	"mad_max":        "saturated wasteland grade: hyper-saturated burnt orange, crunchy high contrast, teal-blue day-for-night, weathered amber skin",
	"wes_pastel":     "symmetrical storybook pastel grade: salmon pink, mustard, powder blue, flat matte contrast, faded film look",
	"bleach_bypass":  "bleach bypass grade: very low saturation, elevated grey blacks, heavy 35mm grain, steel-blue undertone, war-torn grit",
	"blade_runner":   "neo-noir amber grade: monochromatic amber fog, cyan-magenta neon accents, volumetric haze, deep contrast",
	"fincher":        "clinical digital grade: green-yellow midtones, detailed cyan shadows, restrained saturation, pallid skin, precise and clean",
	"kodachrome_70s": "1970s Kodachrome grade: amber highlights, olive greens, faded brown blacks, heavy grain, halation glow",
	"sin_city":       "graphic noir grade: hard black and white, the hero subject in a single fully-saturated spot color, ink-dark shadows",
	"korean_clean":   "bright commercial clean grade: high-key light, neutral whites, pastel accents, luminous skin, soft bloom",
	"cold_horror":    "cold horror grade: blue-green cast, low saturation, pallid skin, dense shadows, a single warm practical light",
}

// paletteDirective — a frase de paleta a APENSAR (preset conhecido → frase; senão o texto livre do
// usuário, saneado). Instrui a MANTER a paleta consistente em todo o projeto (direção de arte).
func paletteDirective(palette string) string {
	p := strings.TrimSpace(palette)
	if p == "" {
		return ""
	}
	phrase := paletteDirectives[p]
	if phrase == "" {
		phrase = clip(p, 200) // texto livre do usuário
	}
	return " Global art direction — apply this color palette consistently across the whole project: " + phrase + "."
}

// personaDirective — a persona (estilo) escolhida no console, apensada ao prompt. Ao
// contrário da paleta, NÃO há tabela de presets aqui: a persona é texto editável na aba
// Prompts (o cliente cria as dele), então o engine recebe o conteúdo pronto e só o
// formata. Teto de 1200 runas — persona é direção de estilo, não um roteiro; passar
// disso afoga o pedido do usuário no prompt final.
func personaDirective(persona string) string {
	p := strings.TrimSpace(persona)
	if p == "" {
		return ""
	}
	return " Style direction — compose the shot following this creative brief: " + clip(p, 1200)
}
