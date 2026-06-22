// Long-form / mídia pesada do Reachyn (F3): vídeo, short-form sincronizado, vídeo premium,
// clipper, transcribe, thumbnail, viral. Porta fiel do lib/studio.ts.
// Outputs FINAIS persistidos no Scality (fonte única de verdade).
package content

import (
	"context"
	"encoding/json"
	"fmt"
	"strings"
	"sync"
	"time"

	"github.com/lrdeoliveira/reachyn-oss/engine/internal/media"
	"github.com/lrdeoliveira/reachyn-oss/engine/internal/provider/speech"
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

// GenerateVideo — texto → vídeo (slot de fila), persistido.
// videoPersona — persona de vídeo (Padrão de Excelência #81): text2video camera-angle-first.
const videoPersona = `You are a senior prompt engineer for text-to-video generation. From the user's theme, write ONE final prompt in ENGLISH and output ONLY the prompt text (no preface, no commentary). ALWAYS start with the camera angle/shot (e.g. "Low angle close-up", "Establishing wide shot", "First-person POV", "Overhead tracking shot"). Include: main subject (vivid, concise), scene/context, clear motion over 5-10 seconds, camera movement (push-in, pull-back, pan, tracking, wrap-around), and aesthetic atmosphere (mood, color tone, light, weather). Simple, physically plausible sentences. End with: no subtitles, no on-screen text, no logos, no watermark.`

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
// (slot de fila i2v, anima a imagem de input com o prompt); vazio → text-to-video.
// duration OPCIONAL: "6" ou "10" segundos (default "6" se vazio/inválido) — repassado ao slot de vídeo.
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
// (caption/script da narracao e fala do vídeo premium). Default pt-BR quando vazio/invalido
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

// beatScriptSize — tamanho-alvo do roteiro falado de cada beat conforme a duração de CADA clipe.
// É o que sincroniza o clipe de 10s: o ffmpeg-service encaixa o clipe na fala (narração) ou
// distribui a legenda na duração do clipe; um roteiro fixo de ~5s deixava o clipe de 10s
// truncado em ~5s (narração) ou com legenda lenta (sem narração). ~2.6 palavras/segundo.
func beatScriptSize(duration string) (secs, words int) {
	if validDuration(duration) == "10" {
		return 9, 24
	}
	return 5, 13
}

// beatBatchMax — beats por chamada do LLM. Acima disso o JSON estoura o maxTokens e trunca;
// vídeos longos (até 50 cenas p/ 5 min) são segmentados em lotes e concatenados.
const beatBatchMax = 8

// SegmentBeats — segmenta o tema em n beats via LLM, cada beat = 1 clipe de `duration`
// segundos. O roteiro de cada beat é dimensionado pela duração (beatScriptSize) p/ sincronizar
// 6s e 10s. n é o nº de cenas JÁ clampado pelo orquestrador (1..maxScenesFor); como uma chamada
// só trunca acima de ~8 beats, segmentamos em lotes de beatBatchMax e concatenamos. Tolerante:
// se um lote falhar mas já houver beats, devolve o que veio.
func (s *Service) SegmentBeats(ctx context.Context, keyword, brief string, n int, lang, duration, aspect string) ([]Beat, error) {
	if n < 1 {
		n = 1
	}
	idioma := langName(lang)
	secs, words := beatScriptSize(duration)
	var all []Beat
	for start := 0; start < n; start += beatBatchMax {
		count := n - start
		if count > beatBatchMax {
			count = beatBatchMax
		}
		part, err := s.segmentBeatsBatch(ctx, keyword, brief, count, start, n, idioma, secs, words, aspect)
		if err != nil || len(part) == 0 {
			if len(all) > 0 {
				break // tolerância: usa os beats que já vieram
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
	return all, nil
}

// segmentBeatsBatch — gera UM lote de `count` beats (parte do total `n`, a partir de `start`).
// Passa o intervalo do lote ao LLM pra manter a progressão narrativa (hook→desenvolvimento→
// recompensa) ao longo do vídeo inteiro. maxTokens escala com o nº de beats do lote.
func (s *Service) segmentBeatsBatch(ctx context.Context, keyword, brief string, count, start, n int, idioma string, secs, words int, aspect string) ([]Beat, error) {
	pos := ""
	if n > count {
		pos = fmt.Sprintf(" Estes são os beats %d a %d de um total de %d (mantenha a progressão narrativa: o começo faz o hook, o meio desenvolve, o fim entrega a recompensa).", start+1, start+count, n)
	}
	orient, frame := "VERTICAL (9:16)", "vertical PORTRAIT 9:16"
	if validVideoAspect(aspect) == "16:9" {
		orient, frame = "HORIZONTAL (16:9)", "horizontal LANDSCAPE 16:9"
	}
	sys := fmt.Sprintf(`Você segmenta um tema em %d BEATS para um vídeo short-form %s sincronizado. Cada beat = 1 clipe de ~%d segundos.%s SAÍDA: responda DIRETO só o JSON, SEM raciocínio, SEM <think>: {"beats":[{"caption":"5-10 palavras em %s","script":"frase falável em ~%ds, ~%d palavras, em %s","image_prompt":"cena cinematográfica em inglês; %s; sem texto"}]} com EXATAMENTE %d beats.`, count, orient, secs, pos, idioma, secs, words, idioma, frame, count)
	user := "Tema: " + keyword + "\n\nContexto:\n" + clip(brief, 1500)
	raw, err := s.llm.GenText(ctx, sys, user, 512+count*256)
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
	Prompt    string // tema/prompt base do vídeo
	Style     string // estilo de vídeo (videoStyleDirective) — usado só no atalho de 1 clipe
	ImageURL  string // opcional: base i2v (URL pública do nosso S3)
	Aspect    string // default "9:16"
	Scenes    int    // 1 = curto (1 clipe); >1 = longo (concatenado). clamp 1..maxScenesFor (teto de 5 min).
	Duration  string // "6"|"10": duração de CADA clipe de vídeo (default "6")
	Narration bool   // gerar narração TTS por cena
	VoiceID   string // voz da narração (usada se Narration || Subtitles)
	Lang      string // "pt-BR"|"en-US" (default pt-BR) — narração/legenda
	Subtitles bool   // queimar legenda
	Music     bool   // trilha de fundo

	// Modelo de vídeo PRINCIPAL/FALLBACK (vem da gen_lines.video: video-a|video-b|video-c).
	// Vazios → defaults video-a→video-b em normalize(). Quando o modelo principal falha
	// na geração do clipe, re-submetemos com o fallback. premium=true NÃO usa isto.
	VideoModel    string // slot principal de vídeo (default video-a)
	VideoFallback string // slot reserva (default video-b; vazio = sem reserva)
}

// maxVideoSeconds — teto de duração TOTAL do vídeo montado: 5 min. Cada cena gera um clipe
// (custo de IA por clipe), então o limite é também a barreira anti-gasto-excessivo (denial-of-
// wallet): o nº de cenas é capado pra duração total nunca passar disso.
const maxVideoSeconds = 300

// maxScenesFor — nº máximo de cenas que cabe em maxVideoSeconds dada a duração REAL de CADA
// clipe (o provider gera 5s ou 10s): 5s → 60 cenas; 10s → 30 cenas.
func maxScenesFor(duration string) int {
	per := 5
	if validDuration(duration) == "10" {
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

// validDuration — normaliza a duração de cada clipe: só "6" ou "10"; default "6".
func validDuration(d string) string {
	if d == "10" {
		return "10"
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
	// Modelo de vídeo: defaults do contrato gen_lines.video (video-a→video-b) quando ausentes.
	if o.VideoModel == "" {
		o.VideoModel = "video-a"
	}
	if o.VideoFallback == "" {
		o.VideoFallback = "video-b"
	}
	return o
}

// clipModelOrdered — gera UM clipe (i2v se imageURL, senão t2v) tentando o MODELO principal
// e, se falhar/vier vazio, RE-SUBMETENDO com o modelo fallback. Cada modelo tem seu próprio
// retry interno (oscilação momentânea do serviço). fallback vazio = sem reserva.
func (s *Service) clipModelOrdered(ctx context.Context, model, fallback, imageURL, prompt, duration, aspect string) (string, error) {
	gen := func(m string) (string, error) {
		return retry(ctx, 3, 3*time.Second, func() (string, error) {
			if imageURL != "" {
				return s.video.ClipImage2Video(ctx, m, imageURL, prompt, duration, aspect)
			}
			return s.video.ClipText2Video(ctx, m, prompt, duration, aspect)
		})
	}
	url, err := gen(model)
	if (err != nil || url == "") && fallback != "" {
		url, err = gen(fallback)
	}
	return url, err
}

// GenerateVideoUnified — ORQUESTRADOR ÚNICO de vídeo do Reachyn, com etapas CONDICIONAIS
// (cada opção desligada PULA a etapa, economizando recurso). Substitui os dois caminhos
// antigos (vídeo simples + short sincronizado) por um só fluxo:
//
//  1. ATALHO BARATO — scenes==1 && !narration && !subtitles && !music: gera 1 clipe direto
//     (i2v se imageUrl, senão t2v) e persiste. = comportamento do /v1/video simples.
//  2. CASO GERAL — scenes>1 OU qualquer etapa de áudio/legenda/música ligada: monta os beats
//     (1 beat derivado do prompt quando scenes==1; SegmentBeats quando scenes>1), gera o clipe
//     de cada beat (gerador de imagem+i2v, ou imageUrl como base) COM RETRY, e chama media.Shortform passando
//     AS FLAGS — o ffmpeg-service pula TTS/legenda/música conforme cada flag.
//
// Tolerante: no caso geral, um beat que falha NÃO derruba o vídeo (segue com os que vieram).
func (s *Service) GenerateVideoUnified(ctx context.Context, opt VideoOptions) (ShortResult, error) {
	opt = opt.normalize()

	// ── 1. ATALHO BARATO: vídeo simples de 1 clipe, sem áudio/legenda/música ──
	// Pula segmentação, ffmpeg-service e qualquer chamada de TTS/música — gera 1 clipe e persiste.
	if opt.Scenes == 1 && !opt.Narration && !opt.Subtitles && !opt.Music {
		url, err := s.generateSingleClip(ctx, opt.Prompt, opt.Style, opt.ImageURL, opt.Duration, opt.VideoModel, opt.VideoFallback, opt.Aspect)
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
	} else {
		// scenes>1: segmenta o tema em N beats (caption/script/image_prompt por beat).
		var err error
		beats, err = s.SegmentBeats(ctx, opt.Prompt, opt.Prompt, opt.Scenes, opt.Lang, opt.Duration, opt.Aspect)
		if err != nil {
			return ShortResult{}, err
		}
		if len(beats) == 0 {
			return ShortResult{}, fmt.Errorf("segmentação não retornou beats")
		}
	}

	// Gera o clipe de cada beat em paralelo (gerador de imagem+i2v, ou imageUrl como base), com RETRY.
	var (
		mu  sync.Mutex
		out []media.ShortBeat
		wg  sync.WaitGroup
	)
	for _, b := range beats {
		wg.Add(1)
		go func(b Beat) {
			defer wg.Done()
			// Base do i2v: se veio uma imagem de input, usa-a em todos os beats (sem gerar do zero);
			// senão, gera a imagem do beat do zero.
			img := opt.ImageURL
			if img == "" {
				var err error
				// Geração de imagem é instável sob carga: tenta até 3x com backoff (2s, 4s).
				img, err = retry(ctx, 3, 2*time.Second, func() (string, error) {
					return s.image.ImageGenerate(ctx, b.ImagePrompt+compFor(opt.Aspect), opt.Aspect, "realista")
				})
				if err != nil || img == "" {
					return // beat pulado após os retries (tolerância mantida).
				}
			}
			// Cada beat é um clipe curto — usa a duração escolhida (default "6"); o vídeo
			// longo vem do nº de cenas concatenadas (ffmpeg faz o merge), não do clipe.
			// Modelo PRINCIPAL→FALLBACK da gen_lines.video; cada um com retry interno (3x).
			clipURL, err := s.clipModelOrdered(ctx, opt.VideoModel, opt.VideoFallback, img, b.ImagePrompt+compFor(opt.Aspect), opt.Duration, opt.Aspect)
			if err != nil || clipURL == "" {
				return // beat pulado após os retries (tolerância mantida).
			}
			mu.Lock()
			out = append(out, media.ShortBeat{ClipURL: clipURL, Caption: b.Caption, Script: b.Script})
			mu.Unlock()
		}(b)
	}
	wg.Wait()

	// Tolerância: 1 cena exige 1 clipe; várias cenas exigem ao menos 2 pra valer a concatenação.
	minClips := 2
	if opt.Scenes == 1 {
		minClips = 1
	}
	if len(out) < minClips {
		return ShortResult{}, fmt.Errorf("poucos clipes ok (%d/%d) — serviço de vídeo instável, tente novamente", len(out), len(beats))
	}
	// Montagem com etapas CONDICIONAIS: o ffmpeg-service pula TTS/legenda/música conforme as flags.
	videoURL, err := s.media.Shortform(ctx, out, opt.VoiceID, opt.Narration, opt.Subtitles, opt.Music, opt.Lang, opt.Aspect)
	if err != nil {
		return ShortResult{}, err
	}
	return ShortResult{URL: s.media.Persist(ctx, videoURL, "short", "mp4"), Beats: beats}, nil
}

// generateSingleClip — gera UM clipe direto (i2v se imageURL, senão t2v) e persiste.
// Mesma lógica do antigo GenerateVideo (elabora o prompt com a persona de vídeo + estilo),
// agora com o MODELO principal→fallback da gen_lines.video (default video-a→video-b).
func (s *Service) generateSingleClip(ctx context.Context, prompt, style, imageURL, duration, model, fallback, aspect string) (string, error) {
	final := prompt
	sys := videoPersona
	if d, ok := videoStyleDirective[style]; ok {
		sys += " " + d
	}
	if validVideoAspect(aspect) == "16:9" {
		sys += " Frame for a HORIZONTAL 16:9 landscape video."
	} else {
		sys += " Frame for a VERTICAL 9:16 portrait video (full-frame phone screen)."
	}
	if c, err := s.llm.GenText(ctx, sys, "Theme: "+prompt, 2048); err == nil && strings.TrimSpace(c) != "" {
		final = strings.TrimSpace(c)
	}
	// Modelo PRINCIPAL→FALLBACK: o clipModelOrdered já faz retry interno por modelo e cai
	// pro fallback se o principal falhar (instabilidade/timeout do serviço de vídeo).
	url, err := s.clipModelOrdered(ctx, model, fallback, imageURL, final, duration, aspect)
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
	// Modelo de vídeo da gen_lines.video (default video-a→video-b aplicado em withDefaults).
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

// GeneratePremiumShort — o LLM escreve prompt cinematográfico (cena + fala no idioma `lang`)
// → vídeo premium → persiste. lang: idioma da fala curta de hook. Vazio/inválido -> pt-BR.
func (s *Service) GeneratePremiumShort(ctx context.Context, keyword, brief, style, lang string) (string, error) {
	// Persona premium (Padrão de Excelência #76): TEXT_PROMPT rico, áudio integrado, sem texto na tela.
	// A fala do hook sai no idioma escolhido (langName); o resto do prompt segue em inglês (técnico/visual).
	sys := fmt.Sprintf(`Você é diretor criativo e prompt engineer de geração de vídeo premium. Escreva UM TEXT_PROMPT em inglês para um vídeo VERTICAL 9:16 de ~8 segundos com áudio integrado. Inclua: Subject, Context, Action, Visual Style, Camera Movement, Composition, Atmosphere e Audio (SFX + música/mood). Cinematográfico e realista. Inclua UMA fala curta de hook EM %s no formato Personagem: "fala". Termine SEMPRE com "And there is no text overlay." Responda DIRETO só o texto do prompt, SEM JSON, SEM <think>.`, strings.ToUpper(langName(lang)))
	if d, ok := videoStyleDirective[style]; ok {
		sys += " " + d
	}
	prompt, err := s.llm.GenText(ctx, sys, "Tema: "+keyword+"\n\nContexto:\n"+clip(brief, 800), 2048)
	if err != nil {
		return "", err
	}
	// Geração premium: devolve a URI + o header de auth necessário pra baixá-la.
	// O header é repassado ao ffmpeg-service, que persiste o vídeo no Scality.
	uri, hdr, err := s.video.PremiumVideo(ctx, clip(prompt, 1500), "9:16")
	if err != nil {
		return "", err
	}
	if uri == "" {
		return "", fmt.Errorf("vídeo premium não retornou vídeo")
	}
	return s.media.PersistWithHeaders(ctx, uri, "premium", "mp4", hdr), nil
}

// GeneratePremiumNarrated — vídeo premium MUDO + narração própria (voz do tenant) + legenda.
// Gera o premium, remove o áudio nativo, escreve um roteiro curto a partir da referência e
// monta voz+legenda via /shortform (1 cena). Fallbacks graciosos: se o strip ou a montagem
// falharem, devolve o melhor disponível (vídeo mudo ou o premium original).
func (s *Service) GeneratePremiumNarrated(ctx context.Context, keyword, brief, style, lang, voiceID string) (string, error) {
	premiumURL, err := s.GeneratePremiumShort(ctx, keyword, brief, style, lang)
	if err != nil {
		return "", err
	}
	mute, err := s.media.StripAudio(ctx, premiumURL)
	if err != nil || mute == "" {
		return premiumURL, nil // fallback: mantém o premium com áudio nativo
	}
	script := s.suggestNarrationScript(ctx, keyword, brief, lang)
	beat := media.ShortBeat{ClipURL: mute, Caption: script, Script: script}
	out, err := s.media.Shortform(ctx, []media.ShortBeat{beat}, voiceID, true, true, false, lang, "9:16")
	if err != nil || out == "" {
		return mute, nil // fallback: ao menos o vídeo mudo
	}
	return out, nil
}

// suggestNarrationScript — UMA fala de narração curta (~8s, cabe no clipe premium) no
// idioma, a partir do tema/referência. Fallback determinístico = keyword.
func (s *Service) suggestNarrationScript(ctx context.Context, keyword, brief, lang string) string {
	sys := fmt.Sprintf("Você escreve a NARRAÇÃO falada de um vídeo curto de marketing de ~8 segundos. A partir do TEMA e da REFERÊNCIA, escreva UMA fala envolvente em %s com no MÁXIMO 22 palavras (cabe em ~8s de locução), tom direto e natural. Sem hashtags, sem emojis, sem aspas, sem rótulos. Responda SÓ a fala.", strings.ToUpper(langName(lang)))
	user := "Tema: " + keyword + "\n\nReferência:\n" + clip(brief, 1500)
	if c, err := s.llm.GenText(ctx, sys, user, 512); err == nil && len(strings.Fields(c)) >= 3 && !hasCJK(c) {
		return strings.TrimSpace(c)
	}
	if c, err := s.llm.AltChat(ctx, sys, user, false); err == nil && len(strings.Fields(c)) >= 3 {
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

// VIRAL_TEMPLATES — foto do cliente → action figure / funko / etc.
var ViralTemplates = []struct{ Key, Label string }{
	{"action_figure", "🎬 Action Figure (na caixa)"},
	{"funko", "🧸 Funko Pop"},
	{"lego", "🧱 Minifigura LEGO"},
	{"diorama", "🏙️ Diorama 3D"},
	{"caricature_3d", "🎨 Caricatura 3D (Pixar)"},
}

func viralPrompt(template, title, theme string) string {
	t := title
	if t == "" {
		t = "REACHYN"
	}
	th := ""
	if theme != "" {
		th = " Theme/accessories related to: " + theme + "."
	}
	base := "Keep the EXACT same face and identity of the person in the photo. "
	switch template {
	case "funko":
		return base + `Transform this exact person into a cute collectible Funko Pop vinyl figure: oversized head, small body, large solid black eyes, glossy vinyl, inside a Funko-style window box with the name "` + t + `" on top.` + th + " Clean studio background, photorealistic product shot, ultra-detailed."
	case "lego":
		return base + `Transform this exact person into a LEGO minifigure: blocky plastic body, cylindrical head, printed face resembling the person, inside a LEGO-style box labeled "` + t + `".` + th + " Studio background, photorealistic plastic textures, ultra-detailed."
	case "diorama":
		return base + `Place this exact person as a hyperrealistic miniature figure inside a detailed 3D collectible diorama scene labeled "` + t + `".` + th + " Dramatic cinematic lighting, depth of field, photorealistic, ultra-detailed."
	case "caricature_3d":
		return base + `Transform this exact person into a charming 3D Pixar-style caricature, expressive friendly features, cinematic studio lighting, high-end 3D render, ultra-detailed. Small badge with the name "` + t + `".` + th
	default: // action_figure
		return base + `Transform this exact person into a hyperrealistic full-body collectible action figure inside a deluxe cardboard window box with a clear acrylic front, the box top reads "` + t + `". Posed standing with miniature realistic accessories.` + th + " Neutral studio background, cinematic lighting, photorealistic, ultra-detailed."
	}
}

// GenerateViral — template viral a partir da foto do cliente (editor de imagem), persistido.
func (s *Service) GenerateViral(ctx context.Context, photoURL, template, title, theme string) (string, error) {
	url, err := s.image.ImageEdit(ctx, viralPrompt(template, title, theme), []string{photoURL})
	if err != nil {
		return "", err
	}
	return s.media.Persist(ctx, url, "viral", "jpg"), nil
}

// Transcribe — expõe a transcrição direto.
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

// PickHighlights — o LLM gera candidatos → rerank por viralidade → dedup overlap → top N.
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
	raw, err := s.llm.GenText(ctx, sys, transcript, 4000)
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
