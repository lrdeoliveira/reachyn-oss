// Long-form / mídia pesada do Reachyn (F3): vídeo, short-form sincronizado, Veo3,
// clipper, transcribe, thumbnail, viral. Porta fiel do lib/studio.ts.
// Outputs FINAIS persistidos no Scality (fonte única de verdade).
package content

import (
	"context"
	"encoding/json"
	"fmt"
	"log"
	"os"
	"strconv"
	"strings"
	"sync"
	"time"

	"github.com/redfoxcode/reachyn/engine/internal/gerr"
	"github.com/redfoxcode/reachyn/engine/internal/media"
	"github.com/redfoxcode/reachyn/engine/internal/provider/speech"
	"github.com/redfoxcode/reachyn/engine/internal/provider/video"
)

// compFor — sufixo de composição do prompt da imagem-base do clipe, conforme o FORMATO do vídeo:
// 16:9 = horizontal/paisagem; qualquer outro = vertical 9:16 (padrão do produto).
func compFor(aspect string) string {
	if aspect == "16:9" {
		return ", horizontal 16:9 landscape composition, full-frame; subject centered and fully visible, cinematic wide framing, never cropped, never vertical"
	}
	return ", vertical 9:16 portrait composition, full-frame; any smartphone or screen MUST be upright in vertical portrait orientation, centered, fully visible, never landscape, never cropped"
}

const defaultVoice = "Ey5AWb48tVX1IOcikcht" // Marcelo Lucas — voz BRASILEIRA (sotaque BR; antes era "George", inglês)

// GenerateVideo — texto → vídeo (Hailuo-02), persistido.
// videoPersona — persona Hailuo (Padrão de Excelência #81): text2video camera-angle-first.
const videoPersona = `You are a senior prompt engineer for Hailuo AI text-to-video. From the user's theme, write ONE final prompt in ENGLISH and output ONLY the prompt text (no preface, no commentary). ALWAYS start with the camera angle/shot (e.g. "Low angle close-up", "Establishing wide shot", "First-person POV", "Overhead tracking shot"). Include: main subject (vivid, concise), scene/context, clear motion over 5-10 seconds, camera movement (push-in, pull-back, pan, tracking, wrap-around), and aesthetic atmosphere (mood, color tone, light, weather). Simple, physically plausible sentences. End with: no subtitles, no on-screen text, no logos, no watermark.` + budoVideoRules + videoHardLimits + actorPhysicsRule

// videoStyleDirective — estilos PRÓPRIOS de vídeo (não os de imagem).
var videoStyleDirective = map[string]string{
	"cinematografico": "Style: cinematic film look, dramatic lighting, shallow depth of field, smooth professional camera.",
	"dinamico":        "Style: high-energy, fast dynamic camera movement, punchy and vibrant.",
	"documental":      "Style: realistic handheld documentary look, natural light, candid.",
	"timelapse":       "Style: timelapse, accelerated passage of time, moving clouds/crowds/light.",
	"anime":           "Style: 2D anime animation aesthetic, expressive, stylized.",
	"3d":              "Style: 3D animated Pixar-like CGI, polished render.",
	"noir":            "Style: film noir, high-contrast chiaroscuro, deep shadows, moody black-and-white.",
	"vintage":         "Style: vintage retro film, visible grain, faded warm tones, nostalgic 70s/80s.",
	"aereo":           "Style: sweeping aerial drone shot, epic overhead movement, vast scale.",
	"slowmotion":      "Style: elegant slow motion, high frame rate, graceful detail, dreamy.",
	"cyberpunk":       "Style: cyberpunk neon-lit, futuristic city, rain-slick reflections, high contrast.",
	"vlog":            "Style: first-person POV vlog, handheld energy, casual and authentic.",
}

// imageUrl OPCIONAL: quando preenchido (URL pública do nosso S3), faz image-to-video
// (Hailuo i2v, anima a imagem de input com o prompt); vazio → text-to-video.
// duration OPCIONAL: "6", "8" ou "10" segundos (default "6" se vazio/inválido) — repassado ao Hailuo.
// Hoje é um wrapper fino do orquestrador único (atalho barato de 1 clipe, sem áudio/legenda/música).
func (s *Service) GenerateVideo(ctx context.Context, prompt, style, imageURL, duration string) (string, error) {
	out, err := s.GenerateVideoUnified(ctx, VideoOptions{
		Prompt:   prompt,
		Style:    style,
		ImageURL: imageURL,
		Duration: duration,
		Scenes:   1,
		// narration/subtitles/music = false (zero-value) → cai no atalho barato de 1 clipe.
	})
	if err != nil {
		return "", err
	}
	return out.URL, nil
}

type Beat struct {
	Caption     string `json:"caption"`
	Script      string `json:"script"`
	ImagePrompt string `json:"image_prompt"`
	// SEGUNDA imagem do beat: ilustra a 2ª metade da frase falada. Só o preset Vox pede (ver
	// voxSegundoPlano) — vazio em todo o resto, e aí o beat continua sendo um segmento só.
	ImagePromptB string `json:"image_prompt_b,omitempty"`
	// EFEITO SONORO de material da cena (papel deslizando, whoosh, carimbo) — prompt curto em
	// inglês. Só o preset Vox pede; é o que faz a colagem parecer FÍSICA (doutrina do formato:
	// som de coisa, discreto, um por cena). Vazio = cena sem SFX, como sempre.
	Sfx string `json:"sfx,omitempty"`
}

// metadeDoScript — parte a frase falada do beat nas duas metades que vão narrar os dois planos.
//
// Corta na FRONTEIRA DE PONTUAÇÃO mais próxima do meio quando existe uma (vírgula, ponto, dois
// pontos, travessão): cortar no meio de uma oração faz o TTS respirar onde a frase não respira, e
// o corte de imagem cai no lugar errado junto. Sem pontuação, cai na palavra do meio.
//
// Devolve ("", "") quando não há o que partir (frase de até 3 palavras) — nesse caso o chamador
// mantém o beat inteiro num segmento só, porque duas imagens para quatro palavras seria pisca-pisca.
func metadeDoScript(script string) (string, string) {
	palavras := strings.Fields(script)
	if len(palavras) < 4 {
		return "", ""
	}
	meio := len(palavras) / 2
	melhor := -1
	// Fronteira de pontuação: procura do meio pra fora, aceitando desvio de até 1/4 das palavras
	// (mais que isso desequilibra os planos — um de 2s e outro de 6s não é montagem, é acidente).
	limite := len(palavras) / 4
	if limite < 1 {
		limite = 1
	}
	for d := 0; d <= limite; d++ {
		for _, k := range []int{meio - d, meio + d} {
			if k < 1 || k >= len(palavras) {
				continue
			}
			if strings.ContainsAny(palavras[k-1][len(palavras[k-1])-1:], ",;:—-.") {
				melhor = k
				break
			}
		}
		if melhor > 0 {
			break
		}
	}
	if melhor < 0 {
		melhor = meio
	}

	return strings.Join(palavras[:melhor], " "), strings.Join(palavras[melhor:], " ")
}

// clampScenes — nº de cenas/beats do short sincronizado. clamp 3..12 (mais cenas =
// vídeo maior; 12 ~= 60s). Valores fora da faixa (inclusive 0) caem no default 5.
func clampScenes(n int) int {
	if n <= 0 {
		return 5
	}
	if n < 3 {
		return 3
	}
	if n > 12 {
		return 12
	}
	return n
}

// langName mapeia o codigo de idioma do video para o nome usado no system prompt
// (caption/script da narracao e fala do Veo). Default pt-BR quando vazio/invalido
// (retrocompativel). Case-insensitive.
func langName(lang string) string {
	switch strings.ToLower(strings.TrimSpace(lang)) {
	case "en-us":
		return "inglês (American English)"
	case "pt-br":
		return "português do Brasil (PT-BR — use 'você', vocabulário e gramática brasileiros; NUNCA português de Portugal)"
	default: // vazio ou invalido -> default pt-BR (comportamento atual)
		return "português do Brasil (PT-BR — use 'você', vocabulário e gramática brasileiros; NUNCA português de Portugal)"
	}
}

// ─────────────────────────────────────────────────────────────────────────────────────────────
// 📏 RÉGUA DE LOCUÇÃO — UM lugar canônico. Não espalhe estes números.
//
// MEDIDO (não estimado) em locução PT-BR do produto, validado no piloto do FoxAssets:
// ~12,2 CARACTERES por segundo (≈ 2,4 palavras/s). Logo, num clipe de 8s cabem ~97 caracteres
// BRUTOS; descontado o respiro de entrada/saída, o teto seguro fica em ~88-90.
//
// Por que CARACTERE e não palavra: "palavras" é uma régua frouxa — 13 palavras curtas cabem em
// 5s, 13 palavras longas não. O TTS fala caractere, e é o caractere que estoura o clipe.
//
// O bug que isto corrige (Vox, prod): o beat de 6s recebia roteiro de ~13 palavras (~75+
// caracteres) para uma janela falada de ~5,4s (~65 caracteres). Na montagem por cena
// (/concat-clips, filmassemble) a duração do clipe é INTOCÁVEL: a fala que não cabe vaza para a
// cena seguinte e, na última, é cortada no mix — a narração terminava truncada.
const (
	// charsPerSec — régua MEDIDA de locução PT-BR (caracteres falados por segundo).
	charsPerSec = 12.2
	// wordsPerSec — a mesma régua em palavras (≈ charsPerSec / 5,1 caracteres por palavra).
	wordsPerSec = 2.4
	// narrationRespiro — segundos descontados da duração do clipe antes de medir a fala:
	// entrada (a cena precisa aparecer antes da voz) + saída (a frase não pode colar no corte).
	narrationRespiro = 0.6
	// scriptCharsMin — piso do teto de caracteres. Blindagem contra duração absurda vinda de
	// fora: um teto minúsculo entregaria beat mutilado, o que é pior que um beat um pouco longo.
	scriptCharsMin = 40
)

// beatScriptSize — tamanho-alvo do roteiro falado de cada beat conforme a duração de CADA clipe.
// É o que sincroniza o clipe de 10s: o ffmpeg-service encaixa o clipe na fala (narração) ou
// distribui a legenda na duração do clipe; um roteiro fixo de ~5s deixava o clipe de 10s
// truncado em ~5s (narração) ou com legenda lenta (sem narração).
//
// Tudo aqui SAI da régua acima — nenhum número escrito à mão:
//
//	6s  → 5,4s de fala → 65 caracteres / ~12 palavras
//	8s  → 7,4s de fala → 90 caracteres / ~17 palavras  (o beat do Vox Factory, do Google Labs)
//	10s → 9,4s de fala → 114 caracteres / ~22 palavras
//
// `maxChars` é o TETO DURO: o prompt pede e o servidor cobra (ver clampBeatScripts).
func beatScriptSize(duration string) (secs, words, maxChars int) {
	total := 6.0
	switch validDuration(duration) {
	case "8":
		total = 8.0
	case "10":
		total = 10.0
	}
	fala := total - narrationRespiro
	secs = int(fala + 0.5)
	words = int(fala * wordsPerSec)
	maxChars = int(fala * charsPerSec)
	if maxChars < scriptCharsMin {
		maxChars = scriptCharsMin
	}
	return secs, words, maxChars
}

// encurtaScript — VALIDAÇÃO DURA do teto de caracteres, determinística e no servidor.
//
// Por que existir, se o prompt já pede o teto: o LLM estoura teto. Pedir é orientação, não
// garantia — e o custo do estouro é o áudio cortado no meio da frase, que é exatamente o defeito
// que o cliente vê. Regra da casa: o que o usuário recebe não pode depender de o modelo ter
// obedecido.
//
// Por que corte determinístico e NÃO um retry de reescrita no modelo: o retry custa uma chamada,
// tempo e — pior — pode voltar estourado de novo, e aí ou se aceita o estouro ou se corta assim
// mesmo. O corte na fronteira de frase é honesto: entrega uma frase INTEIRA e menor, nunca uma
// frase pela metade. É a mesma decisão que o áudio tomaria, só que no lugar certo.
//
// Ordem de preferência do ponto de corte, sempre dentro do teto:
//  1. fim de frase (. ! ? …) — a fala termina resolvida;
//  2. fronteira de cláusula (; : , — –) — perde-se o complemento, não o sentido;
//  3. última palavra inteira — último recurso; NUNCA corta palavra no meio.
//
// Mede em RUNAS (acento é 2 bytes em UTF-8; medir bytes cortaria PT-BR cedo demais).
func encurtaScript(s string, maxChars int) string {
	s = strings.TrimSpace(s)
	r := []rune(s)
	if maxChars <= 0 || len(r) <= maxChars {
		return s
	}
	corte := r[:maxChars]
	fim, clausula, palavra := -1, -1, -1
	for i, c := range corte {
		switch c {
		case '.', '!', '?', '…':
			fim = i
		case ';', ':', ',', '—', '–':
			clausula = i
		case ' ':
			palavra = i
		}
	}
	switch {
	case fim > 0:
		return strings.TrimSpace(string(corte[:fim+1]))
	case clausula > 0:
		// A pontuação de cláusula não fecha frase: vira ponto final pra fala não morrer suspensa.
		return strings.TrimSpace(string(corte[:clausula])) + "."
	case palavra > 0:
		return strings.TrimSpace(string(corte[:palavra])) + "."
	default:
		// Uma "palavra" só maior que o teto — caso patológico. Corta seco: melhor isso que
		// devolver o texto inteiro e voltar ao bug do áudio truncado.
		return strings.TrimSpace(string(corte))
	}
}

// clampBeatScripts — aplica o teto em TODOS os beats de um lote, no ponto único por onde eles
// passam antes de virar áudio. Vale para os beats recém-segmentados E para os beats aprovados na
// tela (o /vox deixa o usuário EDITAR o script — texto de usuário também estoura).
func clampBeatScripts(beats []Beat, maxChars int) []Beat {
	for i := range beats {
		beats[i].Script = encurtaScript(beats[i].Script, maxChars)
	}
	return beats
}

// beatBatchMax — beats por chamada do LLM. Acima disso o JSON estoura o maxTokens e trunca;
// vídeos longos (até 50 cenas p/ 5 min) são segmentados em lotes e concatenados.
const beatBatchMax = 8

// SegmentBeats — segmenta o tema em n beats via MiniMax-M3, cada beat = 1 clipe de `duration`
// segundos. O roteiro de cada beat é dimensionado pela duração (beatScriptSize) p/ sincronizar
// 6s e 10s. n é o nº de cenas JÁ clampado pelo orquestrador (1..maxScenesFor); como uma chamada
// só trunca acima de ~8 beats, segmentamos em lotes de beatBatchMax e concatenamos. Tolerante:
// se um lote falhar mas já houver beats, devolve o que veio.
// `extraRules` — regras extras de estrutura do preset Vox (aba /video (estilo Vox)); vazio = comportamento
// histórico. Só o voxBeatSystem as lê; no segmentador genérico o campo é ignorado de propósito.
func (s *Service) SegmentBeats(ctx context.Context, keyword, brief string, n int, lang, duration, aspect, preset, extraRules string) ([]Beat, error) {
	if n < 1 {
		n = 1
	}
	idioma := langName(lang)
	secs, words, maxChars := beatScriptSize(duration)
	var all []Beat
	for start := 0; start < n; start += beatBatchMax {
		count := n - start
		if count > beatBatchMax {
			count = beatBatchMax
		}
		part, err := s.segmentBeatsBatch(ctx, keyword, brief, count, start, n, idioma, secs, words, maxChars, aspect, preset, extraRules)
		if err != nil || len(part) == 0 {
			if len(all) > 0 {
				// Tolerância: segue com os beats que já vieram. Mas TRUNCAR EM SILÊNCIO faz a peça
				// curta parecer decisão de roteiro — o cliente pediu n cenas e recebe menos sem
				// nada explicando. O log é o que separa "saiu menor" de "falhou no meio".
				log.Printf("segmentação: lote a partir de %d falhou (%v) — peça sai com %d de %d beats", start, err, len(all), n)

				break
			}
			if err == nil {
				err = fmt.Errorf("segmentação não retornou beats")
			}
			return nil, err
		}
		all = append(all, part...)
	}
	if len(all) > n {
		all = all[:n]
	}
	// 📏 TETO DURO, no ponto de saída do endpoint: nenhum beat sai daqui com script maior do que
	// cabe na cena. O prompt já pediu; isto COBRA (ver encurtaScript).
	return clampBeatScripts(all, maxChars), nil
}

// segmentBeatsBatch — gera UM lote de `count` beats (parte do total `n`, a partir de `start`).
// Passa o intervalo do lote ao LLM pra manter a progressão narrativa (hook→desenvolvimento→
// recompensa) ao longo do vídeo inteiro. maxTokens escala com o nº de beats do lote.
func (s *Service) segmentBeatsBatch(ctx context.Context, keyword, brief string, count, start, n int, idioma string, secs, words, maxChars int, aspect, preset, extraRules string) ([]Beat, error) {
	pos := ""
	if n > count {
		pos = fmt.Sprintf(" Estes são os beats %d a %d de um total de %d (mantenha a progressão narrativa: o começo faz o hook, o meio desenvolve, o fim entrega a recompensa).", start+1, start+count, n)
	}
	orient, frame := "VERTICAL (9:16)", "vertical PORTRAIT 9:16"
	if validVideoAspect(aspect) == "16:9" {
		orient, frame = "HORIZONTAL (16:9)", "horizontal LANDSCAPE 16:9"
	}
	var sys string
	if preset == VoxPreset {
		sys = voxBeatSystem(count, secs, words, maxChars, idioma, orient, frame, pos, extraRules)
	} else {
		// 📝 REGRAS EXTRAS também FORA do Vox (unificação Vídeo+Vox, 2026-08-06). A seção
		// "Roteiro" da tela existe em TODOS os estilos; sem isto o texto digitado ali era
		// aceito, viajava até aqui e sumia em silêncio — a falha "escrito e não ligado" que a
		// casa já pagou caro pra aprender. Apenda, nunca substitui (mesma regra do voxExtra).
		sys = fmt.Sprintf(`Você segmenta um tema em %d BEATS para um vídeo short-form %s sincronizado. Cada beat = 1 clipe de ~%d segundos.%s LIMITE DE FALA: cada "script" tem NO MÁXIMO %d CARACTERES (~%d palavras) — a frase será FALADA em %d segundos, e a locução roda a %.1f caracteres por segundo. Frase mais longa NÃO cabe no clipe e é CORTADA NO MEIO. Conte os caracteres antes de responder. SAÍDA: responda DIRETO só o JSON, SEM raciocínio, SEM <think>: {"beats":[{"caption":"5-10 palavras em %s","script":"frase falável em %ds, no máximo %d caracteres, em %s","image_prompt":"cena cinematográfica em inglês; %s; sem texto"}]} com EXATAMENTE %d beats.`, count, orient, secs, pos, maxChars, words, secs, charsPerSec, idioma, secs, maxChars, idioma, frame, count)
		// 📝 REGRAS EXTRAS também FORA do Vox (unificação Vídeo+Vox, 2026-08-06). A seção
		// "Roteiro" da tela existe em TODOS os estilos; sem isto o texto digitado ali era aceito,
		// viajava até aqui e sumia em SILÊNCIO — a falha "escrito e não ligado" que a casa já
		// pagou caro pra aprender. Apenda, nunca substitui (mesmo clamp do voxExtra).
		if r := voxExtra(extraRules); r != "" {
			sys += "\n\nREGRAS EXTRAS DE ESTRUTURA (obrigatórias, por cima das de cima; o formato da SAÍDA não muda): " + r
		}
	}
	user := "Tema: " + keyword + "\n\nContexto:\n" + clip(brief, 1500)
	raw, err := s.textPrime(ctx, sys, user, 512+count*256)
	if err != nil {
		return nil, err
	}
	var d struct {
		Beats []Beat `json:"beats"`
	}
	if err := json.Unmarshal([]byte(raw), &d); err != nil {
		return nil, err
	}
	if len(d.Beats) > count {
		d.Beats = d.Beats[:count]
	}
	return d.Beats, nil
}

type ShortResult struct {
	URL   string `json:"url"`
	Beats []Beat `json:"beats"`
}

// retry — helper genérico de robustez: executa fn até `attempts` vezes, com backoff
// linear crescente (base, 2*base, 3*base...) entre as tentativas. Para na 1ª chamada
// que retorna sucesso (err == nil E string não vazia). Respeita o ctx: aborta imediato
// se o contexto for cancelado durante a espera. Não loga chaves/segredos (não loga nada).
// Usado nas chamadas instáveis do provider de vídeo (geração de imagem + i2v) por beat,
// pra que oscilações momentâneas do serviço não derrubem o beat (e o vídeo) de imediato.
func retry(ctx context.Context, attempts int, base time.Duration, fn func() (string, error)) (string, error) {
	if attempts < 1 {
		attempts = 1
	}
	var lastErr error
	for i := 0; i < attempts; i++ {
		// Aborta cedo se o contexto já foi cancelado.
		if err := ctx.Err(); err != nil {
			return "", err
		}
		res, err := fn()
		if err == nil && res != "" {
			return res, nil
		}
		if err != nil {
			lastErr = err
		} else {
			lastErr = fmt.Errorf("resposta vazia")
		}
		// Não espera depois da última tentativa.
		if i < attempts-1 {
			// Backoff crescente: base, 2*base, 3*base...
			wait := base * time.Duration(i+1)
			select {
			case <-ctx.Done():
				return "", ctx.Err()
			case <-time.After(wait):
			}
		}
	}
	return "", lastErr
}

// VideoOptions — parâmetros do orquestrador ÚNICO de vídeo (GenerateVideoUnified). Tudo
// opcional, com defaults retrocompatíveis aplicados em normalize(): scenes=1 → vídeo simples
// (1 clipe direto, atalho barato); scenes>1 → vídeo longo (clipes concatenados). As flags de
// áudio/legenda/música são CONDICIONAIS: cada uma desligada PULA a etapa correspondente.
type VideoOptions struct {
	Prompt   string // tema/prompt base do vídeo
	Style    string // estilo de vídeo (videoStyleDirective) — usado só no atalho de 1 clipe
	ImageURL string // opcional: base i2v (URL pública do nosso S3) — 1ª âncora
	// ImageURLs — MULTI-REFERÊNCIA (opcional): âncoras adicionais de identidade além da 1ª.
	// O console (roteiroRender) já enviava `imageUrls` desde o lote de cenas do Roteiro, e o
	// engine só tinha `imageUrl`: da 2ª referência em diante TUDO era descartado em silêncio —
	// personagem/cenário extras simplesmente não chegavam ao modelo. Vazio = usa só ImageURL
	// (comportamento idêntico ao histórico). O teto por modelo é aplicado no provider (refs_max).
	ImageURLs []string
	Aspect    string              // default "9:16"
	Scenes    int                 // 1 = curto (1 clipe); >1 = longo (concatenado). clamp 1..maxScenesFor (teto de 5 min).
	Duration  string              // "6"|"8"|"10": duração de CADA clipe Hailuo (default "6")
	Narration bool                // gerar narração TTS por cena
	VoiceID   string              // voz da narração (usada se Narration || Subtitles)
	Lang      string              // "pt-BR"|"en-US" (default pt-BR) — narração/legenda
	Subtitles bool                // queimar legenda
	Music     bool                // trilha de fundo
	Sub       media.SubtitleStyle // estilo da legenda queimada (posição/fonte/tamanho/cor/borda/caixa). Zero value = look histórico.
	Grade     string              // Sprint B: color grade (natural|cinema_quente|teal_orange|noir|vintage); vazio = natural
	// GradeStrength — intensidade do filtro 1..99 (0/100 = look cheio), MESMA mecânica de
	// /v1/storyvideo e /v1/filmassemble. O card de Vídeo da Mídia já mandava `gradeStrength`
	// e o engine não tinha o campo: o slider de intensidade era decorativo aqui.
	GradeStrength int
	Grain         bool // Sprint B: film grain/halation sutil
	// Persona: direção de estilo escolhida no console (aba Prompts, kind=video) — já em TEXTO,
	// não slug. Entra na craft do clipe (atalho de 1 clipe) e no prompt de cada beat, então
	// vale tanto pro vídeo simples quanto pro montado. Vazio = sem persona.
	Persona string

	// Modelo de vídeo PRINCIPAL/FALLBACK (vem da gen_lines.video: seedance|kling|hailuo).
	// Vazios → defaults seedance→kling em normalize(). Quando o modelo principal falha
	// na geração do clipe, re-submetemos com o fallback. premium=true (Veo) NÃO usa isto.
	VideoModel    string // modelo principal de vídeo (default seedance)
	VideoFallback string // legado: ignorado (ver clipModelOrdered) — a reserva real é o MiniMax pré-pago

	// Roteamento do PRINCIPAL: VideoProvider escolhe o motor (cli-bridge | magnific | minimax)
	// e VideoModel é o identificador que ESSE motor entende.
	VideoProvider string
	// spec do modelo Magnific (quando VideoProvider=="magnific"). Cada endpoint da API tem
	// params próprios, então o formato vem do catálogo, não do código.
	VideoMagnific video.MagnificVideoSpec

	// Preset — DIREÇÃO editorial do vídeo (ver vox.go). Vazio = comportamento histórico.
	// "vox" troca a segmentação, o motor de imagem, a linguagem visual, o prompt de movimento
	// e o acabamento de uma vez: o formato é um conjunto coerente, não uma lista de opções
	// soltas — deixar o operador combinar metade dele produz peça que não é nem uma coisa nem outra.
	Preset string

	// ✅ BEATS JÁ APROVADOS pelo cliente — quando preenchido, a segmentação é PULADA e a peça é
	// gerada exatamente sobre estes.
	//
	// É o que torna o roteiro revisável antes de custar dinheiro. Segmentar custa uma chamada de
	// texto; gerar a peça custa uma imagem + um clipe POR CENA. Sem este campo, o único jeito de
	// ler o roteiro era pagar a peça inteira e assistir — e roteiro ruim descoberto no fim é a
	// peça toda jogada fora (três peças em 2026-08-04 pra entregar uma).
	//
	// Vazio = comportamento histórico (segmenta na hora).
	Beats []Beat

	// 📰 Extras do preset Vox (aba /video (estilo Vox) do dashboard) — texto LIVRE do cliente que APENDA nas
	// direções padrão do formato, nunca as substitui (ver voxImageDirective/voxMotionPrompt).
	// Vazios = padrão Vox intacto. Fora do preset "vox" os dois são ignorados.
	VoxStyleExtra     string // direção de arte extra (entra na diretiva de imagem de cada beat)
	VoxDirectionExtra string // direção de movimento/câmera extra (entra no prompt de i2v)
}

// maxVideoSeconds — teto de duração TOTAL do vídeo montado: 5 min. Cada cena gera um clipe
// (custo de IA por clipe), então o limite é também a barreira anti-gasto-excessivo (denial-of-
// wallet): o nº de cenas é capado pra duração total nunca passar disso.
const maxVideoSeconds = 300

// maxScenesFor — nº máximo de cenas que cabe em maxVideoSeconds dada a duração REAL de CADA
// clipe (o provider gera 5s ou 10s): 5s → 60 cenas; 10s → 30 cenas.
func maxScenesFor(duration string) int {
	per := 5
	switch validDuration(duration) {
	case "8":
		per = 8
	case "10":
		per = 10
	}
	return maxVideoSeconds / per
}

// clampScenesDur — clamp do nº de cenas do orquestrador unificado: piso 1 (vídeo simples de 1
// clipe) e teto pela DURAÇÃO TOTAL (maxScenesFor) — garante o limite de 5 min independente do
// que o cliente enviar. Difere do clampScenes (3..12, default 5) usado pelo PRESET de short.
func clampScenesDur(n int, duration string) int {
	if n <= 0 {
		return 1
	}
	if max := maxScenesFor(duration); n > max {
		return max
	}
	return n
}

// validVideoAspect — formato do vídeo no engine: só "9:16" (vertical) ou "16:9" (horizontal);
// default "9:16". 1:1/4:5 não são suportados em vídeo (escopo: vídeo é vertical ou horizontal).
func validVideoAspect(a string) string {
	if a == "16:9" {
		return "16:9"
	}
	return "9:16"
}

// validDuration — normaliza a duração de cada clipe: "6", "8" ou "10"; default "6".
//
// O "8" entrou em 2026-08-30 pela COMPATIBILIDADE com o Vox Factory (a ferramenta do Google Labs
// Flow que o Luciano usa): lá o beat é de 8s com teto de 90 caracteres, e os dois números saem da
// MESMA régua que a nossa (12,2 c/s menos o respiro). Sem o 8 aqui, um roteiro escrito lá caía em
// silêncio no clipe de 6s — e 90 caracteres não cabem nos 5,4s de fala do beat de 6: a narração
// saía cortada no meio, que é exatamente o defeito que a régua existe pra impedir.
//
// O motor aceita: `duration` do seedance_2_0 é inteiro livre (default 5, sem enum), e a faixa do
// bridge é 4..15. O default do preset Vox continua 6 — é a duração testada do formato; 8 é
// escolha, não novo padrão.
func validDuration(d string) string {
	switch d {
	case "8", "10":
		return d
	}
	return "6"
}

// normalize — aplica os defaults retrocompatíveis às opções.
func (o VideoOptions) normalize() VideoOptions {
	o.Duration = validDuration(o.Duration)
	o.Scenes = clampScenesDur(o.Scenes, o.Duration)
	o.Aspect = validVideoAspect(o.Aspect)
	if o.VoiceID == "" {
		o.VoiceID = defaultVoice
	}
	// SEM default de modelo (2026-07-22): "seedance"→"kling" eram slugs de um provedor nativo cuja
	// conta morreu em 13/07. O default fazia uma chamada sem gen_lines.video parecer válida e falhar
	// lá na frente com 403 de saldo de uma conta que ninguém usa. Modelo ausente agora vira erro de
	// CONFIG explícito em clipModelOrdered — quem chama manda o modelo do catálogo.
	return o
}

// refs — âncoras de imagem EFETIVAS do pedido, na ordem: ImageURL (1ª, contrato histórico)
// seguida das ImageURLs, sem vazias e sem repetidas. Vazio = t2v. Um pedido antigo (só
// ImageURL) devolve exatamente []string{ImageURL} — mesmo payload de sempre pro provider.
func (o VideoOptions) refs() []string {
	var out []string
	seen := map[string]bool{}
	for _, u := range append([]string{o.ImageURL}, o.ImageURLs...) {
		u = strings.TrimSpace(u)
		if u == "" || seen[u] {
			continue
		}
		seen[u] = true
		out = append(out, u)
	}
	return out
}

// refsHead — 1ª âncora da lista ("" quando não há nenhuma). É a única que os caminhos de UMA
// imagem (Hailuo direto, diretivas de prompt i2v) conseguem usar.
func refsHead(refs []string) string {
	if len(refs) == 0 {
		return ""
	}
	return refs[0]
}

// creditoMinimoPorCena — piso de saldo para valer a pena começar.
//
// É deliberadamente GROSSEIRO: o preço real por cena varia por modelo (o clipe de cinema custa
// ~27 e a imagem ~7 na conta do provedor) e essa tabela vive no console, não aqui. O que este
// número precisa garantir é só uma coisa — não começar uma peça com a conta praticamente vazia,
// porque aí as primeiras cenas são pagas e a peça sai pela metade. Errar pra baixo é seguro: o
// pior caso é a peça começar e falhar como falhava antes.
const creditoMinimoPorCena = 35.0

// erroDeCredito — a falha foi "acabou o crédito da conta do provedor"?
//
// Detecta pelo TEXTO porque é o que chega aqui: o bridge devolve 402 com a mensagem, e o provider
// embrulha isso num error. Confundir isto com instabilidade é caro nos dois sentidos — o cliente
// repete uma geração que não pode dar certo, e a mensagem esconde a única coisa acionável, que é
// recarregar a conta. (Caso real 2026-08-04: a conta zerou no meio de uma peça de 6 cenas.)
func erroDeCredito(err error) bool {
	s := strings.ToLower(err.Error())

	return strings.Contains(s, "sem créditos") ||
		strings.Contains(s, "not_enough_credits") ||
		strings.Contains(s, "insufficient")
}

// compactaNaOrdem — tira os slots vazios preservando a ORDEM DOS BEATS.
//
// Os slots vêm de goroutines concorrentes: cada cena escreve no índice dela e as que falharam
// (após os retries) deixam o slot zerado. Compactar por índice é o que garante que a peça saia na
// ordem do roteiro, e não na ordem em que o provedor terminou cada clipe.
// Cada beat ocupa até DOIS segmentos (clipe + still de arte nova, ver voxSegundoPlano). O segundo
// só entra se o primeiro entrou: still órfã seria uma cena sem o clipe que ela complementa, com a
// narração pela metade.
func compactaNaOrdem(slot [][2]media.ShortBeat) []media.ShortBeat {
	out := make([]media.ShortBeat, 0, len(slot)*2)
	for _, par := range slot {
		if par[0].ClipURL == "" {
			continue
		}
		out = append(out, par[0])
		if par[1].ImageURL != "" {
			out = append(out, par[1])
		}
	}

	return out
}

// shotSecs — tamanho-alvo (s) de cada PLANO dentro de uma cena, por preset.
//
// O Vox é jornalismo explicativo: o corte ali é pontuação, não transição. Uma cena de 6s em plano
// único faz a peça inteira ter tantos cortes quantas cenas, e o resultado lê como slideshow por
// mais bonita que seja a arte. O ffmpeg-service recorta o clipe JÁ gerado em planos de ~voxShotSecs
// (geral → fechado → detalhe), então o ritmo sai de graça: nenhum clipe novo, nenhuma cena a mais,
// duração da peça idêntica. Gerar mais cenas daria o mesmo ritmo pagando linearmente mais no
// provedor — foi o que estourou custo em 2026-08-04.
//
// 2,2s é onde o explicativo animado vive: mais curto vira videoclipe, mais longo já é slideshow.
// Fora do Vox, 0 = plano único (comportamento histórico de todo o resto intacto).
func shotSecs(preset string) float64 {
	if preset == VoxPreset {
		return voxShotSecs
	}
	return 0
}

// clipConcorrencia — quantos clipes podem ser gerados AO MESMO TEMPO neste provider.
//
// `cli-bridge` tem teto porque o sidecar tem vagas contadas (BRIDGE_CONCURRENCY): pedir mais não
// acelera, só produz 429 e cena perdida. Os demais são API HTTP e aguentam o fan-out inteiro.
//
// O valor vem de CLI_BRIDGE_CLIPES e PRECISA acompanhar o BRIDGE_CONCURRENCY do sidecar — os dois
// vivem em máquinas diferentes (engine no compose, bridge no systemd do host) e um teto maior aqui
// do que lá volta a produzir 429. Default 2 = o default do bridge, então a configuração ausente é
// segura nos dois lados.
func clipConcorrencia(provider string, beats int) int {
	if beats < 1 {
		beats = 1
	}
	if provider != "cli-bridge" {
		return beats
	}
	teto := 2
	if v := os.Getenv("CLI_BRIDGE_CLIPES"); v != "" {
		if n, err := strconv.Atoi(v); err == nil && n >= 1 && n <= 8 {
			teto = n
		}
	}
	if beats > teto {
		return teto
	}
	return beats
}

// clipModelOrdered — gera UM clipe (i2v se há âncora, senão t2v) com o modelo PRINCIPAL, com retry
// interno por oscilação do serviço e uma RESERVA MiniMax pré-paga quando há keyframe.
//
// Três providers geram clipe aqui: "cli-bridge" (sidecar de CLIs), "magnific" (API
// schema-driven) e "minimax" (Hailuo direto).
// Qualquer outro é erro de CONFIG — ver o ramo final de `gen`. O parâmetro `fallback` sobrevive por
// compatibilidade dos callers e não é mais usado: apontava para um provedor nativo desativado, que
// só mascarava o erro real do principal (que já retenta 3× dentro de `gen`).
// `refs` = âncoras de imagem em ordem (vazio = t2v). A 1ª é a base i2v de sempre; as extras só
// viajam pros motores multi-ref (o spec refs_single manda só a 1ª) — modelo que aceita uma
// imagem só continua funcionando, sem erro.
func (s *Service) clipModelOrdered(ctx context.Context, provider, model, fallback string, refs []string, prompt, duration, aspect string, magSpec video.MagnificVideoSpec) (string, error) {
	// Descarta âncora vazia: `refs` não-vazio é o que liga o modo i2v no provider — um item ""
	// viraria um i2v sem imagem (payload inválido no agregador).
	clean := refs[:0:0]
	for _, u := range refs {
		if strings.TrimSpace(u) != "" {
			clean = append(clean, u)
		}
	}
	refs = clean
	imageURL := refsHead(refs)

	// ── CLI Bridge (sidecar no host da VPS) — FORA do wrapper de retry, de propósito ──
	// `model` = adapter do bridge (job_type fixo por trás). Três motivos pra este ramo ficar aqui
	// em cima e não dentro do `gen`:
	//  1. CUSTO: a chamada roda numa conta de ASSINATURA e leva MINUTOS (medido 2m58s num 480p/5s).
	//     Dentro do retry, uma oscilação viraria 3 gerações pagas e ~9 min presos — encostando no
	//     timeout do job e derrubando o clipe do mesmo jeito, só que mais caro.
	//  2. Escolha EXPLÍCITA do cliente no seletor: sem fallback cross-provider e sem reserva
	//     MiniMax embaixo (mesma regra do "cli-bridge"/"comfy" na imagem). Falha → erro claro →
	//     o console estorna o crédito.
	//  3. Ele devolve BYTES, não URL: o áudio/vídeo nasce no host (IP privado) e o safe_fetch do
	//     ffmpeg-service bloqueia IP privado por anti-SSRF. Sem PersistBytes não existe URL
	//     durável nenhuma pra guardar no rascunho.
	if provider == "cli-bridge" {
		return s.cliBridgeClip(ctx, model, prompt, aspect, duration, imageURL)
	}

	// ── Magnific (API HTTP, schema-driven — ver provider/video/magnific.go) ──
	// Fora do wrapper de retry pelo mesmo motivo do cli-bridge: geração de vídeo custa e
	// demora, e três tentativas de uma oscilação viram três clipes pagos. O provider já faz
	// o poll interno; falha vira erro claro e o console estorna.
	if provider == "magnific" {
		url, err := s.video.MagnificVideo(ctx, model, prompt, aspect, duration, refs, magSpec)
		if err != nil {
			log.Printf("vídeo: magnific (%s) falhou: %v — sem fallback (escolha explícita)", model, err)
			return "", err
		}
		return s.media.Persist(ctx, url, "video", "mp4"), nil
	}

	gen := func(p, m string) (string, error) {
		return retry(ctx, 3, 3*time.Second, func() (string, error) {
			if p == "minimax" {
				// MiniMax Hailuo DIRETO (conta pré-paga) — evita pagar o agregador por geração.
				// Aceita UMA imagem: as extras não têm onde entrar aqui.
				return s.video.HailuoDirect(ctx, m, prompt, duration, imageURL)
			}
			// ⛔ QUALQUER outro provider PARA AQUI, e é de propósito.
			//
			// Até 2026-07-22 este ramo caía num provedor nativo desativado (conta morta desde
			// 13/07). O efeito: o Estúdio de Animação deixava escolher o
			// vid-premium (provider google/Veo), o console não propagava o provider, a chamada
			// escorregava pra cá e morria com 403 "balance is insufficient" — um erro sobre uma
			// conta que ninguém queria usar, mascarando o problema real (modelo incompatível com
			// este fluxo). 6 cenas do projeto 19 morreram assim, cada uma retentada 3×.
			//
			// Veo (google) NÃO entra aqui: é t2v de clipe curto (ver GenerateVeoShort) e não sabe
			// partir de um keyframe. Este caminho é i2v ancorado — o contrato é outro.
			return "", gerr.Configf("modelo de vídeo não suportado neste fluxo (escolha um modelo de clipe a partir de imagem)")
		})
	}
	url, err := gen(provider, model)
	// Contrato errado não tem reserva que salve: cair pro MiniMax aqui só trocaria o modelo que o
	// usuário ESCOLHEU por outro em silêncio. Erro de config sobe direto pra quem chamou corrigir.
	if gerr.KindOf(err) == gerr.Config {
		return "", err
	}
	if err != nil && provider != "minimax" {
		// RESERVA MiniMax Hailuo DIRETO (conta pré-paga): achado 2026-07-18 — o KIE ficou sem
		// crédito (402 "Credits insufficient") por horas e TODO i2v falhava (sem fallback nenhum,
		// ao contrário da imagem, que já cai pro MiniMax). imageURL vazio (t2v) não é suportado
		// aqui — HailuoDirect exige i2v; nesse caso o erro original sobe sem reserva.
		if imageURL != "" {
			log.Printf("vídeo: provedor principal falhou (%v) — caindo pra reserva MiniMax (pré-paga)", err)
			url, rerr := s.video.HailuoDirect(ctx, "", prompt, duration, imageURL)
			if rerr != nil {
				log.Printf("vídeo: reserva MiniMax TAMBÉM falhou (%v)", rerr)
				return "", err
			}
			return url, nil
		}
	}
	return url, err
}

// cliClipResolution — resolução pedida ao bridge para os clipes do fluxo. 720p é uma DECISÃO,
// não o default do adapter: 1080p num clipe de 10s passa fácil dos 25MB que o /persist-bytes do
// ffmpeg-service aceita, e o clipe morreria DEPOIS de gerado (crédito de assinatura já gasto).
// 480p, por outro lado, não aguenta o corte final. Subir daqui exige subir o teto lá primeiro.
const cliClipResolution = "720p"

// cliBridgeClip — 1 clipe pelo CLI Bridge: chama o bridge (sem retry — ver clipModelOrdered),
// recebe os BYTES e os torna duráveis no Scality. `duration` chega como string normalizada do
// fluxo ("6"/"10"); duração ilegível vira 0 = "usa o default do adapter", nunca um erro — o
// pedido continua válido, só perde a precisão da duração.
func (s *Service) cliBridgeClip(ctx context.Context, model, prompt, aspect, duration, imageURL string) (string, error) {
	dur, err := strconv.Atoi(strings.TrimSpace(duration))
	if err != nil || dur < 0 {
		dur = 0
	}
	data, ext, cerr := s.video.CliVideo(ctx, model, prompt, aspect, imageURL, dur, cliClipResolution)
	if cerr != nil {
		log.Printf("vídeo: cli-bridge (%s) falhou: %v — sem fallback (escolha explícita)", model, cerr)
		return "", cerr
	}
	// Bytes não são URL: sem persistir, a geração falhou de verdade (e o console estorna).
	return s.media.PersistBytes(ctx, data, "clip", ext)
}

// GenerateVideoUnified — ORQUESTRADOR ÚNICO de vídeo do Reachyn, com etapas CONDICIONAIS
// (cada opção desligada PULA a etapa, economizando recurso). Substitui os dois caminhos
// antigos (vídeo simples + short sincronizado) por um só fluxo:
//
//  1. ATALHO BARATO — scenes==1 && !narration && !subtitles && !music: gera 1 clipe direto
//     (i2v se imageUrl, senão t2v) e persiste. = comportamento do /v1/video simples.
//  2. CASO GERAL — scenes>1 OU qualquer etapa de áudio/legenda/música ligada: monta os beats
//     (1 beat derivado do prompt quando scenes==1; SegmentBeats quando scenes>1), gera o clipe
//     de cada beat (Flux+i2v, ou imageUrl como base) COM RETRY, e chama media.Shortform passando
//     AS FLAGS — o ffmpeg-service pula TTS/legenda/música conforme cada flag.
//
// Tolerante: no caso geral, um beat que falha NÃO derruba o vídeo (segue com os que vieram).
func (s *Service) GenerateVideoUnified(ctx context.Context, opt VideoOptions) (ShortResult, error) {
	opt = opt.normalize()

	// ── 1. ATALHO BARATO: vídeo simples de 1 clipe, sem áudio/legenda/música ──
	// Pula segmentação, ffmpeg-service e qualquer chamada de TTS/música — gera 1 clipe e persiste.
	if opt.Scenes == 1 && !opt.Narration && !opt.Subtitles && !opt.Music {
		url, err := s.generateSingleClip(ctx, opt.Prompt, opt.Style, opt.Persona, opt.refs(), opt.Duration, opt.VideoProvider, opt.VideoModel, opt.VideoFallback, opt.Aspect, opt.VideoMagnific)
		if err != nil {
			return ShortResult{}, err
		}
		return ShortResult{URL: url}, nil
	}

	// ── 2. CASO GERAL: beats → clipes → montagem condicional no ffmpeg-service ──
	var beats []Beat
	if opt.Scenes == 1 {
		// 1 beat derivado do prompt. O script (narração/legenda) só faz sentido quando há
		// narração OU legenda; senão fica vazio (a etapa correspondente é pulada de qualquer jeito).
		script := ""
		if opt.Narration || opt.Subtitles {
			script = opt.Prompt
		}
		beats = []Beat{{Caption: opt.Prompt, Script: script, ImagePrompt: opt.Prompt}}
	} else if len(opt.Beats) > 0 {
		// ✅ ROTEIRO JÁ APROVADO: usa exatamente o que o cliente leu e aprovou. Re-segmentar aqui
		// entregaria uma peça DIFERENTE da que ele aprovou — e ele só descobriria assistindo.
		//
		// 📏 Só o TETO é cobrado de novo: o script da aba /video (estilo Vox) é EDITÁVEL, e texto digitado
		// estoura o clipe igualzinho ao do modelo. A tela avisa antes; aqui é a rede de baixo.
		_, _, maxChars := beatScriptSize(opt.Duration)
		beats = clampBeatScripts(append([]Beat(nil), opt.Beats...), maxChars)
	} else {
		// scenes>1: segmenta o tema em N beats (caption/script/image_prompt por beat).
		var err error
		// Sem extra_rules aqui de propósito: quem passa pelo /v1/video sem beats aprovados não
		// veio da revisão da aba /video (estilo Vox) — as regras extras entram via /v1/beats (roteiro revisado).
		beats, err = s.SegmentBeats(ctx, opt.Prompt, opt.Prompt, opt.Scenes, opt.Lang, opt.Duration, opt.Aspect, opt.Preset, "")
		if err != nil {
			return ShortResult{}, err
		}
		if len(beats) == 0 {
			return ShortResult{}, fmt.Errorf("segmentação não retornou beats")
		}
	}

	// 💳 PRÉ-VOO DE CRÉDITO. Uma peça multi-cena gasta 1 imagem + 1 clipe POR cena; descobrir que a
	// conta zerou na 4ª cena é ter pago as três primeiras por uma peça que sai pela metade — foi
	// exatamente o que aconteceu em 2026-08-04 (3 de 6 cenas entregues, sem o cliente saber o
	// motivo). Perguntar antes custa uma chamada HTTP.
	//
	// Só BLOQUEIA quando a resposta é confiável E o saldo não paga nem uma cena. Não tentamos
	// adivinhar o preço exato da peça: o custo por modelo vive no catálogo do console, e chutar
	// aqui recusaria geração legítima. Saldo indisponível ⇒ segue em frente (ver CreditosDoBridge).
	if opt.VideoProvider == "cli-bridge" {
		if saldo, ok := s.video.CreditosDoBridge(ctx); ok && saldo < creditoMinimoPorCena {
			// gerr.Quotaf e NÃO fmt.Errorf: o writeErr da API já traduz Quota em HTTP 402 com a
			// mensagem certa pro cliente. Com fmt.Errorf isto caía no default (502 "a IA está
			// indisponível, tente novamente") — mensagem errada E o job retentava, porque 5xx é
			// transitório por definição. O tipo do erro é que carrega essa informação; usar o
			// genérico joga fora as duas coisas.
			return ShortResult{}, gerr.Quotaf("sem créditos no provedor de IA (saldo %.1f) — recarregue a conta antes de gerar esta peça de %d cenas", saldo, len(beats))
		}
	}

	// Gera o clipe de cada beat em paralelo (Flux+i2v, ou imageUrl como base), com RETRY.
	// ⚠️ A POSIÇÃO DE CADA CENA É O ÍNDICE DO BEAT, NUNCA a ordem de chegada.
	//
	// Isto já foi um `append` dentro da goroutine, e o resultado era a peça montada na ordem em que
	// o PROVEDOR terminava cada clipe — sorteio puro, porque o tempo de geração varia de 3 a 6 min
	// por cena. O roteiro saía perfeito do segmentador e chegava embaralhado na tela: a conclusão
	// no meio, o gancho no fim, e frases abrindo com "ela"/"a mesma" sem antecedente (peças 381 e
	// 382, 2026-08-04). Falha silenciosa clássica — nenhum erro, nenhuma cena perdida, só a ordem
	// trocada, que só aparece TRANSCREVENDO a narração; olhando os quadros a peça parece certa.
	//
	// Slot fixo por índice: cada goroutine escreve no SEU lugar e as que falham deixam o slot
	// vazio, compactado depois preservando a ordem.
	var (
		mu sync.Mutex
		// Dois segmentos por beat: [0] é sempre o clipe animado; [1] só existe no Vox com segunda
		// imagem (ver voxSegundoPlano) e é uma still em Ken Burns. compactaNaOrdem achata isto
		// preservando a ordem do roteiro.
		slot = make([][2]media.ShortBeat, len(beats))
		wg   sync.WaitGroup
		// 💳 Alguma cena morreu por FALTA DE CRÉDITO no provedor? Muda o que o cliente lê no fim:
		// "serviço instável, tente novamente" manda ele repetir o que não pode dar certo, e some
		// com a única informação acionável — que é recarregar a conta.
		semCredito bool
	)
	// 🚦 TETO DE CONCORRÊNCIA POR PROVIDER. Paralelismo total aqui era gasto puro: o sidecar de
	// CLIs tem 2 vagas (BRIDGE_CONCURRENCY) e um Vox de 6 cenas mandava as 6 de uma vez — 4
	// voltavam "429 bridge ocupado" na hora, o resultado ficava abaixo do mínimo, o job retentava
	// TUDO e as cenas que já tinham ficado prontas eram jogadas fora. Caso real 2026-08-04: 3
	// rodadas, ~57 min e uma peça de 11s onde se pediu 36s.
	//
	// O teto acompanha o provider porque a restrição é dele, não nossa: o bridge fura em 2, e o
	// resto (magnific/minimax) é API HTTP que aguenta o fan-out inteiro.
	gate := make(chan struct{}, clipConcorrencia(opt.VideoProvider, len(beats)))
	for i, b := range beats {
		wg.Add(1)
		go func(i int, b Beat) {
			defer wg.Done()
			gate <- struct{}{}
			defer func() { <-gate }()
			// Base do i2v: se vieram imagens de input, usa-as em todos os beats (sem gerar) —
			// TODAS as referências, não só a 1ª; senão, gera a imagem do beat via MiniMax do zero.
			refs := opt.refs()
			if len(refs) == 0 {
				var img string
				var err error
				// Geração de imagem é instável sob carga: tenta até 3x com backoff (2s, 4s).
				img, err = retry(ctx, 3, 2*time.Second, func() (string, error) {
					// PRESET VOX: a imagem-base É a peça (o clipe só a faz respirar), e ela precisa
					// ser colagem de papel — o motor default entrega foto, que é justamente o que o
					// formato proíbe. Motor e linguagem visual escolhidos por teste (ver vox.go).
					if opt.Preset == VoxPreset {
						return s.voxImagem(ctx, b.ImagePrompt, opt.Aspect, opt.VoxStyleExtra)
					}
					// A imagem-base define o visual do clipe — a persona precisa entrar aqui também,
					// senão o estilo só chegaria no movimento e o frame sairia genérico.
					return s.image.MinimaxImage(ctx, b.ImagePrompt+personaDirective(opt.Persona)+compFor(opt.Aspect), opt.Aspect, "realista")
				})
				if err != nil || img == "" {
					return // beat pulado após os retries (tolerância mantida).
				}
				refs = []string{img}
			}
			// Cada beat é um clipe curto — usa a duração escolhida (default "6"); o vídeo
			// longo vem do nº de cenas concatenadas (ffmpeg faz o merge), não do clipe.
			// Modelo PRINCIPAL→FALLBACK da gen_lines.video; cada um com retry interno (3x).
			// PRESET VOX: o prompt do clipe NÃO repete o conteúdo do quadro. Descrever de novo o
			// que está na imagem faz o i2v tentar "encenar" a cena e reescrevê-la; aqui ele só
			// recebe a ordem de manter tudo parado e respirar (ver voxMotionPrompt).
			movimento := b.ImagePrompt + personaDirective(opt.Persona) + compFor(opt.Aspect)
			if opt.Preset == VoxPreset {
				movimento = voxMotionPrompt(opt.VoxDirectionExtra)
			}
			clipURL, err := s.clipModelOrdered(ctx, opt.VideoProvider, opt.VideoModel, opt.VideoFallback, refs, movimento, opt.Duration, opt.Aspect, opt.VideoMagnific)
			if err != nil || clipURL == "" {
				if err != nil && erroDeCredito(err) {
					mu.Lock()
					semCredito = true
					mu.Unlock()
				}

				return // beat pulado após os retries (tolerância mantida).
			}
			// 🎬 SEGUNDO PLANO COM ARTE NOVA (preset Vox). O beat vira dois segmentos: o clipe
			// narra a 1ª metade da frase, uma imagem NOVA narra a 2ª. É o que separa "corte no
			// ritmo certo" de "montagem detalhada" — ver voxSegundoPlano.
			//
			// Tolerante de propósito: se a 2ª imagem não sair, o beat volta a ser um segmento só
			// com a frase inteira. Perder o plano extra é perder densidade; abortar o beat seria
			// perder a cena — e a cena está paga.
			s1, s2 := "", ""
			var imgB string
			if opt.Preset == VoxPreset && voxSegundoPlano && b.ImagePromptB != "" {
				if a, z := metadeDoScript(b.Script); a != "" {
					imgB, _ = retry(ctx, 2, 2*time.Second, func() (string, error) {
						return s.voxImagem(ctx, b.ImagePromptB, opt.Aspect, opt.VoxStyleExtra)
					})
					if imgB != "" {
						s1, s2 = a, z
					}
				}
			}

			mu.Lock()
			slot[i][0] = media.ShortBeat{
				ClipURL: clipURL, Caption: b.Caption, Script: orDefault(s1, b.Script),
				// ✂️ O corte é a pontuação do explicativo animado. Sem isto a cena inteira é um
				// plano só, e a peça tem tantos cortes quantas cenas — 6 em 36s, que lê como
				// slideshow. Recortar o clipe já gerado dá o ritmo sem gerar vídeo novo.
				//
				// Com o segundo plano ligado o corte JÁ é troca de arte e o segmento fica curto:
				// recortar de novo aqui dava pisca-pisca dentro de 2s de clipe.
				ShotSecs: shotSecsDoBeat(opt.Preset, imgB != ""),
				// ✂️ Cabeça do clipe: o i2v abre com a imagem-base parada (ver voxHeadTrim).
				HeadTrim: headTrim(opt.Preset),
				// 🔊 SFX de material da cena (só o Vox pede na segmentação; ganho DISCRETO —
				// a doutrina do formato é "só uma coisa alta por vez", e a coisa alta é a voz).
				Sfx: b.Sfx, SfxGain: sfxGain(b.Sfx),
			}
			if imgB != "" {
				slot[i][1] = media.ShortBeat{
					ImageURL: imgB, Caption: b.Caption, Script: s2,
				}
			}
			mu.Unlock()
		}(i, b)
	}
	wg.Wait()

	out := compactaNaOrdem(slot)
	// ⚠️ CENAS, não segmentos. Com o segundo plano ligado uma cena entregue vira DOIS itens em
	// `out`; medir tolerância por len(out) faria 3 cenas de 6 passarem como "6 de 6" e a peça
	// truncada sairia como sucesso — exatamente a falha silenciosa que o mínimo existe pra pegar.
	cenasOK := 0
	for _, sb := range out {
		if sb.ClipURL != "" {
			cenasOK++
		}
	}

	// Tolerância: 1 cena exige 1 clipe; várias cenas exigem MAIORIA. O piso fixo de 2 entregava
	// peça truncada como se fosse sucesso — 2 clipes de 6 viravam um vídeo de 11s onde o cliente
	// pediu (e pagou) 36s, sem nenhum aviso de que 4 cenas tinham sumido (caso real 2026-08-04).
	// Metade arredondada pra cima é o mínimo pra peça ainda ser o que foi pedido.
	minClips := (len(beats) + 1) / 2
	if opt.Scenes == 1 {
		minClips = 1
	}
	if cenasOK < minClips {
		if semCredito {
			return ShortResult{}, gerr.Quotaf("sem créditos no provedor de IA — a peça parou em %d de %d cenas; recarregue a conta e gere de novo", cenasOK, len(beats))
		}

		return ShortResult{}, fmt.Errorf("poucos clipes ok (%d/%d) — serviço de vídeo instável, tente novamente", cenasOK, len(beats))
	}
	// Entregou, mas incompleto: o cliente precisa saber que a peça saiu menor do que pediu — some
	// em silêncio é o que faz "paguei 6 cenas e recebi 2" virar descoberta do cliente, não nossa.
	if cenasOK < len(beats) {
		motivo := "as que faltaram falharam no provider"
		if semCredito {
			motivo = "acabou o crédito do provedor no meio da geração"
		}
		log.Printf("vídeo: peça INCOMPLETA — %d de %d cenas geradas (%s)", cenasOK, len(beats), motivo)
	}
	// Montagem com etapas CONDICIONAIS: o ffmpeg-service pula TTS/legenda/música conforme as flags.
	fin := media.FinishOpts{Grade: opt.Grade, GradeStrength: opt.GradeStrength, Grain: opt.Grain}
	if opt.Preset == VoxPreset {
		fin.FPSDelay = voxFPSDelay // a assinatura visual: movimento em degraus, não liso
		fin.CutSting = true        // 🎬 transição com rastro: micro push+blur com pico no corte
		// A tipografia É o formato (2º canal da ideia: ouvida + LIDA + vista) — com legenda
		// ligada e sem preset explícito, entra a animada "vox": condensada branca carimbando
		// palavra a palavra + sweep de marca-texto na palavra ativa.
		if opt.Subtitles && opt.Sub.Anim == "" {
			opt.Sub.Anim = "vox"
		}
	}
	videoURL, err := s.media.Shortform(ctx, out, opt.VoiceID, opt.Narration, opt.Subtitles, opt.Music, "", false, opt.Lang, opt.Aspect, opt.Sub, media.NarrationOpts{}, fin, media.TransitionOpts{})
	if err != nil {
		return ShortResult{}, err
	}
	return ShortResult{URL: s.media.Persist(ctx, videoURL, "short", "mp4"), Beats: beats}, nil
}

// StoryBeat — uma cena da história já pronta para a montagem final: a fonte de vídeo da cena
// + o texto da narração. A fonte preferida é o CLIPE i2v já gerado (VideoURL); na ausência
// dele, cai pra IMAGEM (ImageURL), que o ffmpeg-service transforma em slide (Ken Burns).
type StoryBeat struct {
	ImageURL string `json:"image_url"`
	VideoURL string `json:"video_url"` // clipe i2v já gerado da cena (preferido); vazio → slide da imagem
	Script   string `json:"script"`    // voiceover da cena (dita a narração + a legenda)
	// Duração (s) DESTE slide quando NÃO há narração. 0 = default do serviço (image_duration).
	// Existe pro carrossel-vídeo: slideshow de TEXTO não tolera duração uniforme — a capa tem
	// ≤12 palavras e um slide interno chega a 32, e com o mesmo tempo ou a capa se arrasta ou o
	// interno fica ilegível. Ignorado quando há narração (aí quem dita a duração é o TTS).
	Duration float64 `json:"duration"`
	// Slide PARADO (sem Ken Burns) — ver media.ShortBeat.Still. Usado no revelado por camadas.
	Still bool `json:"still"`
	// Zoom contínuo entre beats do mesmo slide — ver media.ShortBeat.ZoomFrom/ZoomTo.
	ZoomFrom float64 `json:"zoom_from"`
	ZoomTo   float64 `json:"zoom_to"`
	// 🎬 Transição de SAÍDA desta cena (F1): "" = default global; "cut" = corte seco;
	// fadeblack|fadewhite (shortform aceita só duração-preservada — xfade degrada no serviço).
	Transition string `json:"transition"`
	// 🎇 Estúdio de Efeitos (F4) — por cena, opcionais:
	Sfx        string `json:"sfx"`         // efeito sonoro (prompt curto; "" = sem)
	Vfx        string `json:"vfx"`         // efeito visual (shake|zoom_pulse|punch_in|glitch|vhs|freeze)
	OverlayURL string `json:"overlay_url"` // overlay de partículas (nosso S3; "" = sem)
}

// GenerateStoryVideo — JUNTA as cenas já produzidas num Short: cada cena entra como o seu
// clipe i2v (VideoURL) ou, na falta dele, como slide da imagem; sobre o conjunto o
// ffmpeg-service aplica narração (TTS) + legenda word-level sincronizadas e concatena tudo.
// narration/subtitles ficam SEMPRE ligados; music é opcional. Cenas sem vídeo nem imagem são
// ignoradas (tolerância).
func (s *Service) GenerateStoryVideo(ctx context.Context, beats []StoryBeat, voiceID, lang string, nar media.NarrationOpts, music bool, musicPrompt string, sungNarration bool, aspect string, sub media.SubtitleStyle, fin media.FinishOpts, transDefault string, transDur float64) (string, error) {
	if voiceID == "" {
		voiceID = defaultVoice
	}
	aspect = validVideoAspect(aspect)
	var out []media.ShortBeat
	for _, b := range beats {
		switch {
		case b.VideoURL != "":
			out = append(out, media.ShortBeat{ClipURL: b.VideoURL, Script: b.Script, Caption: b.Script, Sfx: b.Sfx, Vfx: b.Vfx, OverlayURL: b.OverlayURL})
		case b.ImageURL != "":
			out = append(out, media.ShortBeat{ImageURL: b.ImageURL, Script: b.Script, Caption: b.Script, Sfx: b.Sfx, Vfx: b.Vfx, OverlayURL: b.OverlayURL, Duration: b.Duration, Still: b.Still, ZoomFrom: b.ZoomFrom, ZoomTo: b.ZoomTo})
		}
	}
	if len(out) == 0 {
		return "", fmt.Errorf("nenhuma cena com vídeo ou imagem para montar o Short")
	}
	// Narração CANTADA → sem TTS falado (o Eleven Music canta o roteiro); o ffmpeg usa o song
	// como áudio. Narração falada → TTS por cena (e a música instrumental, se houver, fica de fundo).
	narration := !sungNarration && !nar.Muted
	// Legenda: no carrossel-vídeo o texto JÁ está desenhado no slide pelo compositor — queimar
	// legenda por cima duplicaria a mesma frase na tela. Zero value = ligada (histórico).
	subtitles := !nar.NoSubtitles
	// 🎬 F1: transições — default global + override por cena (a transição viaja NO beat; cortes
	// de cenas ignoradas (sem mídia) são naturalmente descartados junto com elas).
	trans := media.TransitionOpts{Default: transDefault, Dur: transDur}
	if len(out) > 1 {
		cuts := make([]string, 0, len(out)-1)
		kept := 0
		for _, b := range beats {
			if b.VideoURL == "" && b.ImageURL == "" {
				continue
			}
			if kept < len(out)-1 { // transição de saída das cenas exceto a última
				cuts = append(cuts, b.Transition)
			}
			kept++
		}
		trans.Cuts = cuts
	}
	videoURL, err := s.media.Shortform(ctx, out, voiceID, narration, subtitles, music, musicPrompt, sungNarration, lang, aspect, sub, nar, fin, trans)
	if err != nil {
		return "", err
	}
	return s.media.Persist(ctx, videoURL, "short", "mp4"), nil
}

// SynthesizeSpeech — gera o áudio (preview de narração) de um voiceover de cena via o
// ffmpeg-service (/tts). Devolve a URL durável do MP3. model/format/style opcionais (qualidade
// e entrega da narração — tier/preset do catálogo kind=audio): vazios = defaults históricos.
func (s *Service) SynthesizeSpeech(ctx context.Context, text, voiceID, lang, model, format, style string) (string, error) {
	// Motor de narração pelo CLI Bridge (assinatura paga). Roteado pelo MODELO e não por um
	// campo `provider` novo porque o contrato do /v1/tts (e de todo mundo que o chama) já leva
	// só o slug do catálogo — inventar um provider aqui obrigaria console e web a mudarem junto.
	// Modelo desconhecido NÃO é erro: segue pelo caminho de sempre (ffmpeg-service), que aplica
	// os defaults históricos. Degradar, nunca explodir.
	if adapter := cliSpeechAdapter(model); adapter != "" {
		if voiceID == defaultVoice {
			voiceID = "" // voz do ElevenLabs não existe lá; "" = voz default do adapter
		}
		data, ext, err := s.speech.CliSpeech(ctx, adapter, text, voiceID, "")
		if err != nil {
			log.Printf("narração: cli-bridge (%s) falhou: %v — sem fallback (escolha explícita)", adapter, err)
			return "", err
		}
		// Mesmo motivo do vídeo: os bytes nascem no host, que o safe_fetch não alcança.
		return s.media.PersistBytes(ctx, data, "tts", ext)
	}
	if voiceID == "" {
		voiceID = defaultVoice
	}
	url, err := s.media.TTS(ctx, text, voiceID, lang, model, format, style)
	if err == nil {
		return url, nil
	}

	// 🔁 RESERVA de voz. O caminho principal passa pelo serviço de mídia, que guarda a chave do
	// provedor de voz no PRÓPRIO env — em 2026-08-06 ele rodou 17h com uma chave legada e TODA
	// narração falhou. Aqui a peça pelo menos sai.
	//
	// ⚠️ SÓ NESTE MÉTODO, de propósito. A reserva fala com OUTRA VOZ (o id do provedor principal
	// não existe lá), e este é o preview de UMA cena — trocar a voz aqui é um preview diferente,
	// não uma peça costurada com dois narradores. A montagem multi-cena continua sem reserva:
	// narrador trocando entre os beats é o defeito que o cliente já reclamou, e entregar isso
	// caladinho seria pior que falhar. `SynthesizeSpeechWords` também fica de fora — a reserva
	// não dá o MESMO alinhamento por palavra, e mentir precisão de legenda é o que este arquivo
	// já se recusa a fazer logo abaixo.
	mmx := s.speech.Minimax()
	if mmx == nil {
		return "", err
	}
	log.Printf("narração: motor principal falhou (%v) — caindo na reserva (a VOZ muda; só preview)", err)
	audioURL, _, rerr := mmx.TTS(ctx, "", text, "", lang, false)
	if rerr != nil {
		log.Printf("narração: reserva TAMBÉM falhou (%v)", rerr)

		return "", err // devolve o erro do principal: é o que descreve a causa real
	}

	return s.media.Persist(ctx, audioURL, "tts", "mp3"), nil
}

// SynthesizeSpeechWords — SynthesizeSpeech + alinhamento por palavra ([{word,start,end}]).
//
// Só o caminho do serviço de mídia sabe dar timestamps; os adapters do CLI Bridge devolvem
// bytes crus sem alinhamento — pra não mentir precisão, modelo de bridge é ERRO aqui (o
// chamador que quer timestamps escolhe um modelo que os tenha, e o erro diz isso).
func (s *Service) SynthesizeSpeechWords(ctx context.Context, text, voiceID, lang, model, format, style string) (string, []media.Word, error) {
	if adapter := cliSpeechAdapter(model); adapter != "" {
		return "", nil, gerr.Configf("o modelo de narração %q não fornece timestamps por palavra — use o modelo padrão", model)
	}
	if voiceID == "" {
		voiceID = defaultVoice
	}
	return s.media.TTSWords(ctx, text, voiceID, lang, model, format, style)
}

// cliSpeechAdapter — traduz o slug do catálogo pro nome do adapter no bridge; "" = não é modelo
// do bridge. Allowlist explícita (e não um prefixo genérico) porque o valor vai virar o campo
// `provider` de uma chamada que GASTA assinatura: só o que existe do outro lado passa.
func cliSpeechAdapter(model string) string {
	switch strings.TrimSpace(model) {
	// "audio-higgsfield-tts" = slug do catálogo (kind=audio); a forma curta é o nome do adapter.
	case "audio-higgsfield-tts", "higgsfield-tts":
		return "higgsfield-tts"
	}
	return ""
}

// generateSingleClip — gera UM clipe direto (i2v se imageURL, senão t2v) e persiste.
// Mesma lógica do antigo GenerateVideo (elabora o prompt com a persona de vídeo + estilo),
// agora com o MODELO principal→fallback da gen_lines.video (default seedance→kling).
// `refs`: âncoras de imagem em ordem (vazio = t2v). A 1ª comanda as diretivas de i2v do prompt;
// as extras seguem como referência adicional pro modelo multi-ref (ver clipModelOrdered).
func (s *Service) generateSingleClip(ctx context.Context, prompt, style, persona string, refs []string, duration, provider, model, fallback, aspect string, magSpec video.MagnificVideoSpec) (string, error) {
	imageURL := refsHead(refs)
	// i2v: o personagem vem da IMAGEM-referência → remove o CHARACTER LOCK do texto do prompt
	// (rascunhos antigos o traziam embutido) pra o modelo ANIMAR a cena, não redesenhar o boneco.
	if imageURL != "" {
		prompt = stripLock(prompt, defaultCharacterLock)
	}
	final := prompt
	sys := videoPersona
	if d, ok := videoStyleDirective[style]; ok {
		sys += " " + d
	}
	sys += personaDirective(persona) // estilo escolhido no console (vazio = no-op)
	// A RAZÃO NUMÉRICA não entra no texto — ela já vai como parâmetro pro provider, e escrita no
	// corpo é redundância que no pior caso o modelo desenha como texto no quadro. Fica só a
	// ORIENTAÇÃO, que é o que muda o enquadramento. (Mesma correção do aspectHint, 2026-08-03.)
	if validVideoAspect(aspect) == "16:9" {
		sys += " Frame for a HORIZONTAL landscape video."
	} else {
		sys += " Frame for a VERTICAL portrait video (full-frame phone screen)."
	}
	// Anti-drift de personagem no i2v: a imagem de origem é a identidade canônica do(s)
	// personagem(ns). Instrui o modelo de vídeo a preservar a aparência exata e aplicar só
	// movimento sutil — sem redesenhar/transformar/trocar o personagem entre os clipes.
	if imageURL != "" {
		sys += " The provided first frame is the FIXED reference for the character(s): preserve their exact identity, appearance, proportions and colors from that image. Apply only subtle, natural motion — never redesign, morph, restyle or replace any character."
	}
	if imageURL != "" && strings.TrimSpace(prompt) == "" {
		// ANTI-ALUCINAÇÃO: i2v SEM prompt (video_prompt vazio é o design das Histórias) NÃO passa
		// pelo LLM — elaborar "Theme: " vazio fazia o modelo INVENTAR um tema do nada e o clipe
		// saía sem relação com a imagem (cenas viravam vídeo sem sentido). Diretiva fixa: animar
		// EXATAMENTE o que a imagem mostra, com movimento sutil.
		final = "Animate exactly what this image shows: subtle, natural motion true to the scene " +
			"(gentle character movement, breathing, light ambient motion). Keep every character, " +
			"object, color and composition unchanged. No new elements, no scene change, minimal camera movement."
	} else {
		// 🐛 A FICHA DE IDENTIDADE ia junto pro redator — e elaborar uma ficha é RESUMI-LA.
		// `splitIdentityBlock` foi escrita em 667d6ad exatamente pra impedir isto ("fix do
		// IDENTITY LOCK — Mel saiu macho") e NUNCA foi chamada por ninguém: nasceu morta, então
		// a trava textual jamais esteve ativa neste caminho, que é o caminho de TODO clipe de
		// cena (/v1/video → GenerateVideoUnified → aqui). O sintoma é silencioso e caro: "pink
		// fabric collar with small circular gold metal tag engraved 'Mel'" volta do LLM como
		// "wearing a collar", e o traço que segurava a identidade some antes do modelo de vídeo.
		//
		// Só a AÇÃO vai pro redator; a ficha é recolada VERBATIM depois. Prompt sem marcador
		// devolve (prompt, "") e o comportamento é idêntico ao de antes.
		acao, ficha := splitIdentityBlock(prompt)
		if c, err := s.textPrime(ctx, sys, "Theme: "+acao, 2048); err == nil && strings.TrimSpace(c) != "" {
			final = strings.TrimSpace(c)
			if ficha != "" {
				final += "\n\n" + ficha
			}
		}
	}
	// Modelo PRINCIPAL→FALLBACK: o clipModelOrdered já faz retry interno por modelo e cai
	// pro fallback se o principal falhar (instabilidade/timeout do serviço de vídeo).
	url, err := s.clipModelOrdered(ctx, provider, model, fallback, refs, final, duration, aspect, magSpec)
	if err != nil {
		return "", err
	}
	return s.media.Persist(ctx, url, "video", "mp4"), nil
}

// GenerateShortVideo — PRESET de short sincronizado (narração + legenda + música LIGADAS).
// Hoje é um wrapper fino do orquestrador único, mantido pra não quebrar o GenerateController/
// automação (/v1/short). scenes cai no clamp 3..12 do preset (default 5) quando 0/inválido.
// videoPrompt OPCIONAL: quando preenchido, as cenas derivam DESSE prompt do usuário em vez do brief.
func (s *Service) GenerateShortVideo(ctx context.Context, keyword, brief, voiceID, imageURL string, scenes int, videoPrompt, lang string, gl GenLines) (ShortResult, error) {
	// O preset usa o clamp histórico do short (3..12, default 5), distinto do clamp 1..12 do
	// orquestrador — aqui o piso é 3 cenas (short sincronizado sempre é multi-cena).
	scenes = clampScenes(scenes)
	// Base da segmentação: o videoPrompt do usuário tem prioridade; senão keyword+brief.
	prompt := strings.TrimSpace(videoPrompt)
	if prompt == "" {
		prompt = keyword
		if b := strings.TrimSpace(brief); b != "" {
			prompt = keyword + "\n\n" + brief
		}
	}
	// Modelo de vídeo da gen_lines.video (default hailuo→seedance aplicado em withDefaults).
	vln := gl.WithDefaults().Video
	return s.GenerateVideoUnified(ctx, VideoOptions{
		Prompt:        prompt,
		ImageURL:      imageURL,
		Scenes:        scenes,
		VoiceID:       voiceID,
		Lang:          lang,
		Narration:     true, // PRESET short: narração + legenda + música ligadas
		Subtitles:     true,
		Music:         true,
		VideoModel:    vln.Primary,
		VideoFallback: vln.Fallback,
	})
}

// GenerateVeoShort — M3 escreve prompt cinematográfico (cena + fala no idioma `lang`) → Veo (Google) → persiste.
// lang: idioma da fala curta de hook no TEXT_PROMPT do Veo. Vazio/inválido -> pt-BR.
func (s *Service) GenerateVeoShort(ctx context.Context, keyword, brief, style, lang string) (string, error) {
	// Persona Veo 3 (Padrão de Excelência #76): TEXT_PROMPT rico, áudio integrado, sem texto na tela.
	// A fala do hook sai no idioma escolhido (langName); o resto do prompt segue em inglês (técnico/visual).
	sys := fmt.Sprintf(`Você é diretor criativo e prompt engineer do Google Veo 3. Escreva UM TEXT_PROMPT em inglês para um vídeo VERTICAL 9:16 de ~8 segundos com áudio integrado. Inclua: Subject, Context, Action, Visual Style, Camera Movement, Composition, Atmosphere e Audio (SFX + música/mood). Cinematográfico e realista. Inclua UMA fala curta de hook EM %s no formato Personagem: "fala". Termine SEMPRE com "And there is no text overlay." Responda DIRETO só o texto do prompt, SEM JSON, SEM <think>.`, strings.ToUpper(langName(lang))) + budoVideoRules + videoHardLimits + actorPhysicsRule
	if d, ok := videoStyleDirective[style]; ok {
		sys += " " + d
	}
	prompt, err := s.textPrime(ctx, sys, "Tema: "+keyword+"\n\nContexto:\n"+clip(brief, 800), 2048)
	if err != nil {
		return "", err
	}
	// Geração premium DIRETA no Google (o atalho pelo agregador, ~25% do custo, saiu em
	// 2026-08-03 junto com ele). Devolve a URI + o header de auth pro download, repassado ao
	// ffmpeg-service, que persiste no Scality.
	uri, hdr, err := s.video.VeoGoogle(ctx, clip(prompt, 1500), "9:16")
	if err != nil {
		return "", err
	}
	if uri == "" {
		return "", fmt.Errorf("Veo não retornou vídeo")
	}
	return s.media.PersistWithHeaders(ctx, uri, "veo", "mp4", hdr), nil
}

// GenerateVeoNarrated — Veo MUDO + narração própria (voz do tenant) + legenda. Gera
// o Veo, remove o áudio nativo, escreve um roteiro curto a partir da referência e
// monta voz+legenda via /shortform (1 cena). Fallbacks graciosos: se o strip ou a
// montagem falharem, devolve o melhor disponível (vídeo mudo ou o Veo original).
func (s *Service) GenerateVeoNarrated(ctx context.Context, keyword, brief, style, lang, voiceID string) (string, error) {
	veoURL, err := s.GenerateVeoShort(ctx, keyword, brief, style, lang)
	if err != nil {
		return "", err
	}
	mute, err := s.media.StripAudio(ctx, veoURL)
	if err != nil || mute == "" {
		return veoURL, nil // fallback: mantém o Veo com áudio nativo
	}
	script := s.suggestNarrationScript(ctx, keyword, brief, lang)
	beat := media.ShortBeat{ClipURL: mute, Caption: script, Script: script}
	out, err := s.media.Shortform(ctx, []media.ShortBeat{beat}, voiceID, true, true, false, "", false, lang, "9:16", media.SubtitleStyle{}, media.NarrationOpts{}, media.FinishOpts{}, media.TransitionOpts{})
	if err != nil || out == "" {
		return mute, nil // fallback: ao menos o vídeo mudo
	}
	return out, nil
}

// suggestNarrationScript — UMA fala de narração curta (~8s, cabe no clipe Veo) no
// idioma, a partir do tema/referência. Fallback determinístico = keyword.
func (s *Service) suggestNarrationScript(ctx context.Context, keyword, brief, lang string) string {
	sys := fmt.Sprintf("Você escreve a NARRAÇÃO falada de um vídeo curto de marketing de ~8 segundos. A partir do TEMA e da REFERÊNCIA, escreva UMA fala envolvente em %s com no MÁXIMO 22 palavras (cabe em ~8s de locução), tom direto e natural. Sem hashtags, sem emojis, sem aspas, sem rótulos. Responda SÓ a fala.", strings.ToUpper(langName(lang)))
	user := "Tema: " + keyword + "\n\nReferência:\n" + clip(brief, 1500)
	// Só MiniMax (Ollama removido — não usar). Falha/curto → cai no keyword.
	if c, err := s.textPrime(ctx, sys, user, 512); err == nil && len(strings.Fields(c)) >= 3 && !hasCJK(c) {
		return strings.TrimSpace(c)
	}
	return keyword
}

// GenerateThumbnail — frame + título queimado, persistido.
func (s *Service) GenerateThumbnail(ctx context.Context, videoURL, title string) (string, error) {
	url, err := s.media.Thumbnail(ctx, videoURL, title)
	if err != nil {
		return "", err
	}
	return s.media.Persist(ctx, url, "thumb", "jpg"), nil
}

// Transcribe — expõe o Scribe direto.
func (s *Service) Transcribe(ctx context.Context, mediaURL string) (speech.Transcript, error) {
	return s.speech.Transcribe(ctx, mediaURL)
}

// ListVoices — vozes disponíveis na conta do provedor de voz (para o seletor de narração).
func (s *Service) ListVoices(ctx context.Context) ([]speech.Voice, error) {
	return s.speech.ListVoices(ctx)
}

type Highlight struct {
	Start float64 `json:"start"`
	End   float64 `json:"end"`
	Title string  `json:"title"`
	Score int     `json:"score"`
}

// PickHighlights — M3 gera candidatos → Jina rerank por viralidade → dedup overlap → top N.
func (s *Service) PickHighlights(ctx context.Context, words []speech.WordTS, n int) ([]Highlight, error) {
	if len(words) == 0 {
		return nil, nil
	}
	var lines []string
	for i := 0; i < len(words); i += 12 {
		end := i + 12
		if end > len(words) {
			end = len(words)
		}
		g := words[i:end]
		var sb strings.Builder
		fmt.Fprintf(&sb, "[%.1f] ", g[0].Start)
		for j, w := range g {
			if j > 0 {
				sb.WriteByte(' ')
			}
			sb.WriteString(w.Text)
		}
		lines = append(lines, sb.String())
	}
	transcript := clip(strings.Join(lines, "\n"), 12000)
	total := words[len(words)-1].End
	ask := n * 2
	if ask < n+2 {
		ask = n + 2
	}
	if ask > 8 {
		ask = 8
	}
	sys := fmt.Sprintf(`Você é editor de cortes virais. Recebe a transcrição com marcações [segundos]. Liste %d CANDIDATOS a short vertical: cada um 20-60s, autocontido, com hook (curiosidade, emoção, dica, revelação). SAÍDA: responda DIRETO só o JSON, SEM <think>: {"clips":[{"start":<seg>,"end":<seg>,"title":"título curto PT-BR","score":<0-100>}]} — start/end dentro de 0..%d.`, ask, int(total))
	raw, err := s.textPrime(ctx, sys, transcript, 4000)
	if err != nil {
		return nil, err
	}
	var d struct {
		Clips []Highlight `json:"clips"`
	}
	if err := json.Unmarshal([]byte(raw), &d); err != nil {
		return nil, err
	}

	type cand struct {
		h    Highlight
		text string
	}
	var cands []cand
	var docs []string
	for _, x := range d.Clips {
		dur := x.End - x.Start
		if x.End <= x.Start || x.Start < 0 || x.End > total+1 || dur < 8 || dur > 90 {
			continue
		}
		var sb strings.Builder
		for _, w := range words {
			if w.End > x.Start && w.Start < x.End {
				sb.WriteString(w.Text)
				sb.WriteByte(' ')
			}
		}
		txt := clip(strings.TrimSpace(sb.String()), 400)
		cands = append(cands, cand{h: x, text: txt})
		docs = append(docs, x.Title+". "+txt)
	}
	if len(cands) == 0 {
		return nil, nil
	}

	order := s.rerank.Rerank(ctx, "trecho viral para short vertical: hook forte, emoção, curiosidade, dica prática acionável ou revelação surpreendente", docs, len(docs))
	var kept []Highlight
	for _, idx := range order {
		c := cands[idx].h
		overlap := false
		for _, k := range kept {
			inter := min(k.End, c.End) - max(k.Start, c.Start)
			if inter > 0.5*min(k.End-k.Start, c.End-c.Start) {
				overlap = true
				break
			}
		}
		if !overlap {
			kept = append(kept, c)
		}
		if len(kept) >= n {
			break
		}
	}
	return kept, nil
}

// ClipLongVideo — (ingest se YouTube) → transcreve → highlights → ffmpeg /clip → persiste.
func (s *Service) ClipLongVideo(ctx context.Context, videoURL string, n int) ([]media.ClipResult, error) {
	if n <= 0 {
		n = 3
	}
	mediaURL := videoURL
	if strings.Contains(videoURL, "youtube.com") || strings.Contains(videoURL, "youtu.be") {
		u, err := s.media.Ingest(ctx, videoURL)
		if err != nil {
			return nil, err
		}
		mediaURL = u
	}
	tr, err := s.speech.Transcribe(ctx, mediaURL)
	if err != nil {
		return nil, err
	}
	if len(tr.Words) == 0 {
		return nil, fmt.Errorf("transcrição vazia")
	}
	hl, err := s.PickHighlights(ctx, tr.Words, n)
	if err != nil {
		return nil, err
	}
	if len(hl) == 0 {
		return nil, fmt.Errorf("nenhum highlight encontrado")
	}
	specs := make([]media.ClipSpec, 0, len(hl))
	for _, h := range hl {
		var ws []speech.WordTS
		for _, w := range tr.Words {
			if w.End > h.Start && w.Start < h.End {
				ws = append(ws, w)
			}
		}
		specs = append(specs, media.ClipSpec{Start: h.Start, End: h.End, Title: h.Title, Score: h.Score, Words: ws})
	}
	clips, err := s.media.Clip(ctx, mediaURL, specs)
	if err != nil {
		return nil, err
	}
	for i := range clips {
		if clips[i].URL != "" {
			clips[i].URL = s.media.Persist(ctx, clips[i].URL, "clip", "mp4")
		}
	}
	return clips, nil
}

// CreditosDoProvedor — saldo da conta que paga as gerações, pra luz de status na tela.
//
// Repassa o que o sidecar informa. `false` = não deu pra saber (bridge desligado, health mudo),
// e nesse caso a tela não mostra nada: mostrar "0" quando na verdade não perguntamos assusta à toa
// e ensina o cliente a ignorar o indicador.
func (s *Service) CreditosDoProvedor(ctx context.Context) (float64, bool) {
	if s.video == nil {
		return 0, false
	}

	return s.video.CreditosDoBridge(ctx)
}
