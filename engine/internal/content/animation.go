// animation.go — 🎬 ESTÚDIO DE ANIMAÇÃO (roteiro → desenho pronto). Este arquivo cobre o
// PASSO 1 do fluxo: o parser que transforma um roteiro livre (ou uma ideia) em DADOS —
// elementos (personagens/locações/objetos com visual_prompt pronto pra gerar referência) e
// cenas (ação + diálogo por personagem + Ficha de Cena). Os passos seguintes reusam o que já
// existe: refs via /v1/image + /v1/characterbible, keyframes via /v1/image multi-ref, i2v via
// /v1/filmclip, e o áudio de diálogo multi-voz entra aqui (GenerateDialogueAudio/MuxSceneAudio,
// finos sobre o ffmpeg-service /dialogue e /mux-audio).
package content

import (
	"context"
	"encoding/json"
	"fmt"
	"strings"
	"time"

	"github.com/redfoxcode/reachyn/engine/internal/media"
)

// AnimElement — UM elemento extraído do roteiro (personagem, locação ou objeto). VisualPrompt
// nasce em EN, detalhado o bastante pra gerar a referência visual sozinho (o usuário pode
// editar antes de gerar — a UX de curadoria do Estúdio).
type AnimElement struct {
	Name         string `json:"name"`
	Kind         string `json:"kind,omitempty"`       // child|adult|animal|creature|object|place…
	VisualPrompt string `json:"visual_prompt"`        // EN, autocontido (estilo entra na geração)
	VoiceHint    string `json:"voice_hint,omitempty"` // só personagens: "menino ~8 anos", "mulher adulta calma"…
}

// AnimDialogueLine — uma fala de UMA cena: quem fala (nome igual ao do elemento) e o texto
// verbatim do roteiro (idioma original preservado — vira TTS com a voz do personagem).
type AnimDialogueLine struct {
	Character string `json:"character"`
	Line      string `json:"line"`
}

// AnimScene — uma cena do storyboard: ação (PT, pro operador), image_prompt (EN, composição da
// cena SEM identidade — a identidade entra pelos locks/refs dos personagens na hora do keyframe),
// diálogo, elenco/locação/props da cena e a Ficha de Cena (spec). VideoPrompt é derivado do
// spec.movement (determinístico, só câmera — nunca redesenha o personagem).
type AnimScene struct {
	Title       string             `json:"title"`
	Action      string             `json:"action"`
	ImagePrompt string             `json:"image_prompt"`
	Narration   string             `json:"narration,omitempty"` // modo narrado (Histórias/Quadrinhos): 1-2 frases de VOZ ÚNICA que contam a cena
	VideoPrompt string             `json:"video_prompt,omitempty"`
	Dialogue    []AnimDialogueLine `json:"dialogue,omitempty"`
	Characters  []string           `json:"characters,omitempty"`
	Location    string             `json:"location,omitempty"`
	Props       []string           `json:"props,omitempty"`
	Spec        *SceneSpec         `json:"spec,omitempty"`
}

// ScriptParseResult — o roteiro inteiro virado DADOS: título + elementos + cenas.
type ScriptParseResult struct {
	Title      string        `json:"title"`
	Characters []AnimElement `json:"characters"`
	Locations  []AnimElement `json:"locations"`
	Props      []AnimElement `json:"props"`
	Scenes     []AnimScene   `json:"scenes"`
}

// Tetos do parse — limitam gasto (cada elemento vira imagem; cada cena vira keyframe+clipe)
// mantendo o formato útil. Cenas seguem a faixa das Histórias, com teto menor (animação é cara).
const (
	animMaxCharacters = 8
	animMaxLocations  = 6
	animMaxProps      = 10
	animMinScenes     = 1
	animMaxScenes     = 20
	animDefaultScenes = 6
)

func clampAnimScenes(n int) int {
	if n <= 0 {
		return animDefaultScenes
	}
	if n < animMinScenes {
		return animMinScenes
	}
	if n > animMaxScenes {
		return animMaxScenes
	}
	return n
}

// ParseScript — lê um roteiro livre (só diálogos, prosa, ou apenas uma IDEIA) e devolve o
// projeto estruturado. style orienta os visual_prompts (ex: "3D fofo estilo desenho infantil",
// "fotorrealista live-action"); lang define o idioma da ação/título ("pt-BR" default) — as
// FALAS ficam sempre no idioma original do roteiro. maxScenes limita o storyboard.
// narration=true (modos Histórias/Quadrinhos do wizard): cada cena ganha "narration" — 1-2
// frases de NARRADOR ÚNICO no estilo de shorts (o TTS central da montagem lê esse texto);
// dialogue só entra se o roteiro trouxer falas explícitas. persona (opcional) = direção de
// roteiro (Voz da Marca + roteirista escolhido), mesma semântica do /v1/story.
func (s *Service) ParseScript(ctx context.Context, script, style, lang string, maxScenes int, narration bool, persona string, gl GenLines) (ScriptParseResult, error) {
	script = strings.TrimSpace(script)
	if script == "" {
		return ScriptParseResult{}, fmt.Errorf("roteiro vazio")
	}
	n := clampAnimScenes(maxScenes)
	actionLang := "português do Brasil"
	if lang == "en-US" {
		actionLang = "US English"
	}
	styleHint := strings.TrimSpace(style)
	if styleHint == "" {
		styleHint = "3D animated cartoon, cute and expressive"
	}

	sys := animScriptSystem(n, actionLang, styleHint, persona, narration)

	user := "ROTEIRO:\n" + clip(script, 24000)

	ctx, cancel := context.WithTimeout(ctx, 240*time.Second)
	defer cancel()
	raw, err := s.genTextOrdered(ctx, gl.WithDefaults().Text, sys, user, 12000, false, "")
	if err != nil {
		return ScriptParseResult{}, err
	}
	var out ScriptParseResult
	if err := json.Unmarshal([]byte(extractJSON(raw)), &out); err != nil {
		return ScriptParseResult{}, fmt.Errorf("parse do roteiro: resposta inválida: %w", err)
	}
	normalizeParsed(&out, n)
	if len(out.Scenes) == 0 || len(out.Characters) == 0 {
		return ScriptParseResult{}, fmt.Errorf("parse do roteiro: nada extraído")
	}
	return out, nil
}

// animScriptSystem — o SYSTEM PROMPT do desenvolvimento visual, extraído da ParseScript pra ser
// testável. A animação é multi-cena e o estilo é livre (pode ser fotorrealista), então recebe os
// DOIS blocos novos: os limites duros do motor e a geografia fixa entre planos.
func animScriptSystem(n int, actionLang, styleHint, persona string, narration bool) string {
	sys := `Você é o departamento de desenvolvimento visual de um estúdio de animação. Recebe um ROTEIRO (ou só uma ideia) e o transforma em um projeto estruturado de produção. Responda APENAS com um objeto JSON válido, sem markdown, sem cercas, sem comentários, neste formato exato:
{"title":"...",
 "characters":[{"name":"...","kind":"child|adult|animal|creature|robot|other","visual_prompt":"...","voice_hint":"..."}],
 "locations":[{"name":"...","visual_prompt":"..."}],
 "props":[{"name":"...","visual_prompt":"..."}],
 "scenes":[{"title":"...","action":"...","image_prompt":"...","dialogue":[{"character":"...","line":"..."}],"characters":["..."],"location":"...","props":["..."],"spec":{"shot":"...","movement":"...","light":"...","emotion":"..."}}]}

REGRAS:
- characters: TODO personagem que aparece ou fala (máx ` + fmt.Sprint(animMaxCharacters) + `). name curto no idioma do roteiro. visual_prompt em ENGLISH, autocontido e detalhado (espécie/idade/corpo/rosto/cabelo/roupa/cores) — vai gerar a imagem de referência sozinho; NÃO cite o nome de outros personagens nele. voice_hint em ` + actionLang + ` (gênero + idade + tom de voz).
- locations (máx ` + fmt.Sprint(animMaxLocations) + `) e props (máx ` + fmt.Sprint(animMaxProps) + `): só o que importa visualmente; visual_prompt em ENGLISH, sem pessoas dentro.
- scenes: EXATAMENTE ` + fmt.Sprint(n) + ` cenas contando a história completa (arco com começo/meio/fim). title e action em ` + actionLang + ` (action = o que ACONTECE na cena, 1-3 frases). image_prompt em ENGLISH descrevendo a COMPOSIÇÃO da cena EM CAMADAS, nesta ordem: [sujeito + o que faz] → [ambiente/cenário concreto] → [luz + atmosfera/clima] → [enquadramento/composição]. SEM descrever a aparência dos personagens (a identidade vem por referência). Use descritores CONCRETOS e sensoriais (cor, textura, material, escala, qualidade de luz) — o modelo pensa em probabilidades: quanto mais específico, mais controle. dialogue = as falas da cena NO IDIOMA ORIGINAL do roteiro, na ordem, character casando com characters. characters/location/props referenciam os names extraídos.
- spec por cena: shot ∈ {` + shotKeysList + `}; movement ∈ {` + moveKeysList + `}; light ∈ {` + lightKeysList + `}; emotion = direção de ator curta. VARIE os planos entre cenas; mude a luz só quando a narrativa muda de tempo/lugar. CENA COM DIÁLOGO (quando a cena tem "dialogue"): prefira um plano MAIS FECHADO (closeup/medio/overshoulder_fechado) com o rosto do personagem que fala bem visível, e movement "static" (talking-head: câmera parada, o personagem age) — respiração sutil e micro-movimento de cabeça, sem câmera viajando.
- Se receber só uma IDEIA (sem falas), você mesmo escreve a história e os diálogos.
- REGRA DE OURO dos prompts visuais (image_prompt e visual_prompt): NUNCA palavras vagas ("bonito", "legal", "incrível", "uma cidade") — elas são invisíveis pro modelo. Sempre descritores técnicos e sensoriais CONCRETOS (cor, textura, material, escala, tipo de luz, referência). Denso e específico; o sujeito principal primeiro.
- Estilo visual do projeto (orienta os visual_prompts): ` + styleHint + `.
- JAMAIS use caracteres chineses, japoneses ou coreanos.` + videoHardLimits + actorPhysicsRule + sceneGeographyRule + whiteLabelRule

	if narration {
		sys += `
- MODO NARRAÇÃO ÚNICA (obrigatório): cada cena ganha TAMBÉM o campo "narration" — 1-2 frases em ` + actionLang + ` que NARRAM a cena pro espectador, no estilo de shorts virais (anatomia testada): a 1ª cena ABRE COM UM GANCHO forte nos primeiros segundos — fato chocante, pergunta ou virada (76% dos shorts virais prendem nos 3 primeiros segundos, então a promessa mais forte vem já na abertura). Frases curtas e faladas, presente do indicativo. Cada cena ativa UMA EMOÇÃO (curiosidade, surpresa, medo, satisfação ou humor) e puxa a próxima (retenção progressiva). A ÚLTIMA cena FECHA COM UM CTA de engajamento — uma pergunta ou provocação que convida o espectador a comentar. A história é contada pela narração; NÃO crie "dialogue" a menos que o roteiro traga falas explícitas entre personagens.`
	}
	if p := strings.TrimSpace(persona); p != "" {
		sys += "\n\nDIREÇÃO DE ROTEIRO (aplique na escrita da história, dos diálogos e da narração):\n" + clip(p, 3000)
	}
	return sys
}

// normalizeParsed — saneia o resultado do LLM: clamps de quantidade, CJK fora, elenco das cenas
// restrito aos personagens extraídos, e video_prompt derivado do spec.movement (determinístico).
// Exposta (minúscula) pra teste unitário puro.
func normalizeParsed(out *ScriptParseResult, maxScenes int) {
	out.Title = clip(strings.TrimSpace(stripCJK(out.Title)), 160)

	cleanEls := func(els []AnimElement, max int, wantVoice bool) []AnimElement {
		kept := make([]AnimElement, 0, len(els))
		for _, e := range els {
			e.Name = clip(strings.TrimSpace(stripCJK(e.Name)), 80)
			e.Kind = clip(strings.TrimSpace(stripCJK(e.Kind)), 40)
			e.VisualPrompt = clip(strings.TrimSpace(stripCJK(e.VisualPrompt)), 1200)
			if wantVoice {
				e.VoiceHint = clip(strings.TrimSpace(stripCJK(e.VoiceHint)), 160)
			} else {
				e.VoiceHint = ""
			}
			if e.Name == "" || e.VisualPrompt == "" {
				continue
			}
			kept = append(kept, e)
			if len(kept) >= max {
				break
			}
		}
		return kept
	}
	out.Characters = cleanEls(out.Characters, animMaxCharacters, true)
	out.Locations = cleanEls(out.Locations, animMaxLocations, false)
	out.Props = cleanEls(out.Props, animMaxProps, false)

	names := make(map[string]bool, len(out.Characters))
	for _, c := range out.Characters {
		names[strings.ToLower(c.Name)] = true
	}
	locs := make(map[string]bool, len(out.Locations))
	for _, l := range out.Locations {
		locs[strings.ToLower(l.Name)] = true
	}

	scenes := make([]AnimScene, 0, len(out.Scenes))
	for _, sc := range out.Scenes {
		sc.Title = clip(strings.TrimSpace(stripCJK(sc.Title)), 160)
		sc.Action = clip(strings.TrimSpace(stripCJK(sc.Action)), 800)
		sc.ImagePrompt = clip(strings.TrimSpace(stripCJK(sc.ImagePrompt)), 1200)
		sc.Narration = clip(strings.TrimSpace(stripCJK(sc.Narration)), 600)
		if sc.Action == "" && sc.ImagePrompt == "" {
			continue
		}
		// Elenco da cena restrito aos personagens extraídos (o LLM às vezes inventa figurante).
		cast := make([]string, 0, len(sc.Characters))
		for _, nm := range sc.Characters {
			nm = strings.TrimSpace(stripCJK(nm))
			if names[strings.ToLower(nm)] {
				cast = append(cast, nm)
			}
		}
		sc.Characters = cast
		if !locs[strings.ToLower(strings.TrimSpace(sc.Location))] {
			sc.Location = ""
		} else {
			sc.Location = strings.TrimSpace(sc.Location)
		}
		lines := make([]AnimDialogueLine, 0, len(sc.Dialogue))
		for _, dl := range sc.Dialogue {
			dl.Character = clip(strings.TrimSpace(stripCJK(dl.Character)), 80)
			dl.Line = clip(strings.TrimSpace(stripCJK(dl.Line)), 500)
			if dl.Line == "" {
				continue
			}
			lines = append(lines, dl)
		}
		sc.Dialogue = lines
		// Câmera dirigida: o movimento do spec vira o video_prompt (só câmera — seguro pro i2v).
		if mv := specMoveDirective(sc.Spec); mv != "" {
			sc.VideoPrompt = mv
		}
		scenes = append(scenes, sc)
		if len(scenes) >= maxScenes {
			break
		}
	}
	out.Scenes = scenes
}

// GenerateDialogueAudio — sintetiza o diálogo multi-voz de UMA cena (1 TTS por fala, voz do
// personagem, respiro entre falas) e devolve o MP3 durável + duração total.
func (s *Service) GenerateDialogueAudio(ctx context.Context, lines []media.SpeechLine, ttsModel, ttsFormat string) (string, float64, error) {
	if len(lines) == 0 {
		return "", 0, fmt.Errorf("diálogo vazio")
	}
	return s.media.Dialogue(ctx, lines, ttsModel, ttsFormat, 0)
}

// MuxSceneAudio — casa o áudio do diálogo com o clipe i2v (mudo) da cena.
func (s *Service) MuxSceneAudio(ctx context.Context, videoURL, audioURL string) (string, error) {
	return s.media.MuxAudio(ctx, videoURL, audioURL)
}
