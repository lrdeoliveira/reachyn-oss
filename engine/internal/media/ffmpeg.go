// Package media — cliente da stack de mídia da Reachyn (ffmpeg-service + Scality).
// Resolve por NOME DE SERVIÇO (MEDIA_FFMPEG_URL), nunca IP fixo (lição do IP stale).
// Porta de persist/shortform/clip/ingest/thumbnail do lib/studio.ts.
package media

import (
	"bytes"
	"context"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"net/http"
	"strings"
	"time"

	"github.com/redfoxcode/reachyn/engine/internal/provider/speech"
)

type Client struct {
	base  string
	token string // X-Service-Token exigido pelo ffmpeg-service (AUD-008)
	http  *http.Client
}

func New(baseURL, token string) *Client {
	return &Client{base: strings.TrimRight(baseURL, "/"), token: token, http: &http.Client{Timeout: 600 * time.Second}}
}

// PostJSON — POST genérico pro ffmpeg-service devolvendo status + JSON cru. Existe pro
// pipeline local de sprites (/sprite-frames, /sprite-normalize): o contrato é do serviço
// e o engine só repassa — re-modelar aqui criaria uma segunda fonte de verdade do shape.
func (c *Client) PostJSON(ctx context.Context, path string, in map[string]any) (int, map[string]any, error) {
	raw, _ := json.Marshal(in)
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, c.base+path, bytes.NewReader(raw))
	if err != nil {
		return 0, nil, err
	}
	req.Header.Set("Content-Type", "application/json")
	if c.token != "" {
		req.Header.Set("X-Service-Token", c.token) // AUD-008
	}
	resp, err := c.http.Do(req)
	if err != nil {
		return 0, nil, err
	}
	defer resp.Body.Close()
	var out map[string]any
	if err := json.NewDecoder(resp.Body).Decode(&out); err != nil {
		return resp.StatusCode, nil, err
	}
	return resp.StatusCode, out, nil
}

func (c *Client) post(ctx context.Context, path string, in any, out any) error {
	raw, _ := json.Marshal(in)
	req, _ := http.NewRequestWithContext(ctx, http.MethodPost, c.base+path, bytes.NewReader(raw))
	req.Header.Set("Content-Type", "application/json")
	if c.token != "" {
		req.Header.Set("X-Service-Token", c.token) // AUD-008
	}
	resp, err := c.http.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	return json.NewDecoder(resp.Body).Decode(out)
}

// ShortBeat — um beat de mídia + legenda + fala, pronto pra montagem sincronizada. A fonte de
// vídeo é OU um clipe pronto (ClipURL) OU uma imagem estática (ImageURL) que o ffmpeg-service
// transforma em slide com Ken Burns. Exatamente um dos dois é preenchido por beat.
type ShortBeat struct {
	ClipURL  string `json:"clip_url,omitempty"`
	ImageURL string `json:"image_url,omitempty"` // slideshow: imagem vira clipe (zoom suave) no ffmpeg-service
	Caption  string `json:"caption"`
	Script   string `json:"script"`
	// Duração (s) DESTE slide quando não há narração. 0 = usa o image_duration global do serviço.
	// Slideshow de texto não tolera duração uniforme: no carrossel editorial a capa tem ≤12
	// palavras e um slide interno chega a 32 — com o mesmo tempo, ou a capa se arrasta ou o
	// interno fica ilegível. Quem monta calcula o tempo de leitura e manda aqui.
	Duration float64 `json:"duration,omitempty"`
	// Slide PARADO (sem Ken Burns). No revelado por camadas um slide vira vários beats — o mesmo
	// fundo com um bloco de texto a mais em cada — e o zoompan REINICIA a cada beat, dando um
	// salto no meio do slide. Parado, os beats se alinham pixel a pixel e o corte lê como
	// "o texto apareceu". Só faz sentido com ImageURL.
	Still bool `json:"still,omitempty"`
	// Zoom CONTÍNUO entre beats do mesmo slide (revelado por camadas): o beat começa no zoom em
	// que o anterior parou. Sem isso o zoompan reinicia em 1.0 a cada camada e o slide salta
	// pra trás toda vez que um bloco entra. 0/0 = sem zoom (ver Still).
	ZoomFrom float64 `json:"zoom_from,omitempty"`
	ZoomTo   float64 `json:"zoom_to,omitempty"`
	// ✂️ RITMO DE CORTE: tamanho-alvo (s) de cada PLANO dentro desta cena. O ffmpeg-service parte
	// a cena em K planos do mesmo clipe, cada um num enquadramento diferente (geral → fechado →
	// detalhe), sem mudar a duração dela nem gerar vídeo novo. 0 = plano único (histórico).
	ShotSecs float64 `json:"shot_secs,omitempty"`
	// ✂️ CABEÇA DO CLIPE descartada (s). O i2v recebe a imagem-base como primeiro quadro e leva um
	// instante pra engatar o movimento — o clipe abre PARADO. Num explicativo cortado a cada 2s
	// isso é imagem congelada em toda entrada de plano, e a peça lê como slideshow. 0 = intacto.
	HeadTrim float64 `json:"head_trim,omitempty"`
	// 🎇 Estúdio de Efeitos (F4) — por cena, todos opcionais:
	Sfx        string  `json:"sfx,omitempty"`         // efeito sonoro da cena (prompt curto; ex "porta rangendo")
	SfxGain    float64 `json:"sfx_gain,omitempty"`    // volume do SFX (0.1..1.5; 0 = default 0.9)
	Vfx        string  `json:"vfx,omitempty"`         // efeito visual: shake|zoom_pulse|punch_in|glitch|vhs|freeze
	OverlayURL string  `json:"overlay_url,omitempty"` // vídeo de partículas (fundo preto, nosso S3) em blend=screen
}

// FinishOpts — acabamento global do Short (F2, paridade com o Filme). Zero value = sem efeito
// (comportamento histórico). GradeStrength 0 = 100 (look cheio); 1..99 = blend com o original.
type FinishOpts struct {
	Grade          string
	GradeStrength  int
	Grain          bool
	AmbiencePrompt string // som-ambiente sob tudo (chuva, rua...); "" = sem
	EndcardURL     string // cartela final ~2s (imagem já composta, nosso storage)
	ColorMatch     bool   // casa a exposição das cenas com a 1ª
	Smooth         bool   // interpolação de movimento 2× (best-effort)
	// ATRASO DE FPS — quadros/s efetivos da peça (o arquivo continua no frame rate normal, só o
	// movimento avança em degraus). É a assinatura visual do documentário explicativo animado:
	// sem isso a peça parece slideshow liso. Faixa útil 12..24; 0 = desligado.
	FPSDelay int
	// 🎬 TRANSIÇÃO COM RASTRO (tracking transition do formato Vox): micro push-in + blur curto
	// nas BORDAS de cada segmento, com pico no corte. Duração preservada, concat intacto — é o
	// que faz os cortes lerem como um fluxo único em vez de uma sequência de slides.
	CutSting bool
}

// TransitionOpts — transições entre cenas/trechos (Estúdio de Efeitos F1). Default aplica em todo
// corte sem override; Cuts[i] = transição ENTRE o segmento i e o i+1 ("" = default; "cut" = seco).
// Kinds: cut | fadeblack | fadewhite (duração-preservada) | fade | slideleft/right | wipeleft/right
// | circleopen | zoomin (xfade — só no Filme; no shortform degradam pra fadeblack no serviço).
type TransitionOpts struct {
	Default string   // kind default de todo corte ("" ou "cut" = sem transição)
	Dur     float64  // duração (s) da transição (clamp 0.2..1.5 no serviço; 0 = 0.5)
	Cuts    []string // override por corte (len = nº de segmentos - 1; "" = default)
}

// body — shape do serviço: {default:{kind,dur}, cuts:[...]}. nil quando não há transição alguma.
func (t TransitionOpts) body() map[string]any {
	has := t.Default != "" && t.Default != "cut"
	for _, k := range t.Cuts {
		if k != "" && k != "cut" {
			has = true
			break
		}
	}
	if !has {
		return nil
	}
	return map[string]any{
		"default": map[string]any{"kind": t.Default, "dur": t.Dur},
		"cuts":    t.Cuts,
	}
}

// SubtitleStyle — estilo da legenda queimada, repassado ao ffmpeg-service (vira force_style ASS).
// O zero value reproduz EXATAMENTE o look histórico (Liberation Sans 20, texto branco, borda 3 preta,
// sem fundo). Campos vazios/0 viram default no ffmpeg-service — então passar SubtitleStyle{} é seguro.
type SubtitleStyle struct {
	Pos         string // "bottom" (default) | "middle" | "top"
	Size        int    // tamanho da fonte (0 = default 20)
	Color       string // cor do texto (#RRGGBB; vazio = branco)
	Border      int    // espessura do contorno (0 = default 3)
	BorderColor string // cor do contorno (#RRGGBB; vazio = preto)
	Font        string // chave de fonte allowlistada (sans|serif|mono|dejavu|noto; vazio = sans)
	Opacity     int    // transparência do texto/borda 0..90 (0 = opaco)
	Bg          bool   // desenha caixa (fundo) atrás do texto
	BgColor     string // cor da caixa (#RRGGBB; vazio = preto)
	BgOpacity   int    // opacidade da caixa 0..100 (100 = opaca; vazio = 60)
	Anim        string // 🎞️ legenda ANIMADA: "pop"|"karaoke"|"bounce"|"vox" (overlay Remotion); vazio = queimada
	AccentColor string // cor de realce da palavra ativa no modo animado (#RRGGBB; vazio = dourado)
}

// NarrationOpts — opções de narração/sincronismo da montagem (tier/preset do catálogo kind=audio
// + ajustes finos do operador). Zero value = comportamento histórico (defaults do serviço).
type NarrationOpts struct {
	Model     string  // modelo de síntese (vazio = default do serviço)
	Format    string  // formato/bitrate do MP3 (vazio = default)
	Style     string  // preset de entrega da voz (vazio = neutro)
	Delay     float64 // atraso (s) da narração dentro de cada cena (0..2)
	SubOffset float64 // deslocamento (s) extra da legenda relativo ao áudio (-1..+1)
	// ⚠️ Flags NEGATIVAS de propósito: o zero value tem de continuar sendo o comportamento
	// histórico (narração falada + legenda ligadas). Uma flag positiva `Narration bool` faria
	// todo chamador existente que passa NarrationOpts{} virar um vídeo MUDO em silêncio.
	Muted       bool // true = sem narração TTS (slideshow sem voz; a duração vem do beat)
	NoSubtitles bool // true = sem legenda queimada (o texto já está desenhado no slide)
}

// Shortform — monta os beats num short vertical (ffmpeg-service /shortform) com etapas
// CONDICIONAIS: cada flag em false PULA a etapa no ffmpeg-service (economiza recurso).
//   - narration: gera narração TTS por cena (e dita a duração do segmento);
//   - subtitles: queima legenda (word-level se há narração; timing estimado se não);
//   - music: gera trilha de fundo mixada sob o vídeo.
//   - lang: idioma da narração/legenda (repassado; informativo no body).
//   - sub: estilo da legenda (ver SubtitleStyle; zero value = look histórico).
//   - nar: qualidade/entrega/sincronismo da narração (ver NarrationOpts; zero value = histórico).
//
// O ffmpeg-service usa default true em todas as flags quando ausentes — então um body sem
// flags reproduz EXATAMENTE o comportamento histórico (narração+legenda+música).
func (c *Client) Shortform(ctx context.Context, beats []ShortBeat, voiceID string, narration, subtitles, music bool, musicPrompt string, sungNarration bool, lang, aspect string, sub SubtitleStyle, nar NarrationOpts, fin FinishOpts, trans TransitionOpts) (string, error) {
	var out struct {
		VideoURL string `json:"video_url"`
	}
	// Defaults do look histórico nos campos cujo zero value (0) colidiria com "não informado": o Go
	// SEMPRE serializa estes ints (sem omitempty), então é AQUI que o default vale — não no ffmpeg-service.
	if sub.Border <= 0 {
		sub.Border = 3 // sem espessura explícita → contorno histórico (legibilidade); a UI tem mínimo 1
	}
	if sub.Bg && sub.BgOpacity <= 0 {
		sub.BgOpacity = 60 // fundo ligado sem opacidade explícita → caixa semitransparente (não invisível)
	}
	body := map[string]any{
		"beats":           beats,
		"voice_id":        voiceID,
		"narration":       narration,
		"subtitles":       subtitles,
		"music":           music,
		"music_prompt":    musicPrompt,   // estilo da trilha (vazio = default histórico do serviço)
		"sung_narration":  sungNarration, // true = a voz CANTA o roteiro (letra = script), via Eleven Music
		"lang":            lang,
		"aspect":          aspect,        // "9:16" (default) ou "16:9" — dimensões da montagem no ffmpeg-service
		"tts_model":       nar.Model,     // modelo de síntese da narração (vazio = default do serviço)
		"tts_format":      nar.Format,    // formato/bitrate do MP3 da narração (vazio = default)
		"tts_style":       nar.Style,     // preset de entrega da voz (vazio = neutro/histórico)
		"audio_delay":     nar.Delay,     // atraso (s) da narração dentro de cada cena (0 = histórico)
		"subtitle_offset": nar.SubOffset, // deslocamento (s) da legenda relativo ao áudio (0 = alinhada)
		// Estilo da legenda (vide SubtitleStyle): vazio/0 = default no ffmpeg-service.
		"subtitle_pos":          sub.Pos,
		"subtitle_size":         sub.Size,
		"subtitle_color":        sub.Color,
		"subtitle_border":       sub.Border,
		"subtitle_border_color": sub.BorderColor,
		"subtitle_font":         sub.Font,
		"subtitle_opacity":      sub.Opacity,
		"subtitle_bg":           sub.Bg,
		"subtitle_bg_color":     sub.BgColor,
		"subtitle_bg_opacity":   sub.BgOpacity,
		"subtitle_anim":         sub.Anim,        // preset do overlay animado (vazio = legenda queimada)
		"subtitle_accent_color": sub.AccentColor, // realce da palavra ativa (só no modo animado)
		"grade":                 fin.Grade,       // color grade (natural|cinema_quente|...|dourado|gelo|pastel|tropical|drama|pb_suave|retro_vhs)
		"grain":                 fin.Grain,       // film grain/halation sutil
	}
	if fin.GradeStrength > 0 && fin.GradeStrength < 100 {
		body["grade_strength"] = fin.GradeStrength // F2: blend do look com o original (intensidade)
	}
	// F2 — paridade de acabamento com o Filme (todos opcionais; ausente = sem efeito):
	if fin.AmbiencePrompt != "" {
		body["ambience_prompt"] = fin.AmbiencePrompt
	}
	if fin.EndcardURL != "" {
		body["endcard_url"] = fin.EndcardURL
	}
	if fin.ColorMatch {
		body["color_match"] = true
	}
	if fin.Smooth {
		body["smooth"] = true
	}
	// ⚠️ Este mapeamento FALTAVA: fin.FPSDelay era setado pelo preset Vox e morria aqui — o
	// serviço lê body['fps_delay'] e o campo nunca era enviado, então a assinatura stop-motion
	// do formato nunca chegou a NENHUMA peça (escrito e não ligado, achado 2026-08-05).
	if fin.FPSDelay > 0 {
		body["fps_delay"] = fin.FPSDelay
	}
	if fin.CutSting {
		body["cut_sting"] = true // 🎬 transição com rastro nas bordas dos segmentos (Vox)
	}
	if tb := trans.body(); tb != nil {
		body["transitions"] = tb // 🎬 F1 (o serviço degrada xfade→fadeblack no shortform)
	}
	if err := c.post(ctx, "/shortform", body, &out); err != nil {
		return "", err
	}
	if out.VideoURL == "" {
		return "", fmt.Errorf("montagem falhou (sem video_url)")
	}
	return out.VideoURL, nil
}

// TTS — sintetiza a fala de um texto (preview de narração por cena) e devolve a URL durável
// no Scality. Reusa o léxico fonético de marca + a chave de voz do ffmpeg-service (/tts).
// lang é informativo (o modelo é multilíngue). model/format/style opcionais (qualidade e entrega
// da narração): vazios = defaults históricos do serviço. White-label: o erro nunca cita o provedor.
func (c *Client) TTS(ctx context.Context, text, voiceID, lang, model, format, style string) (string, error) {
	var out struct {
		URL   string `json:"url"`
		Error string `json:"error"`
	}
	if err := c.post(ctx, "/tts", map[string]any{"text": text, "voice_id": voiceID, "lang": lang, "tts_model": model, "tts_format": format, "tts_style": style}, &out); err != nil {
		return "", err
	}
	if out.URL == "" {
		return "", fmt.Errorf("síntese de narração falhou: %s", clip(out.Error, 160))
	}
	return out.URL, nil
}

// Word — uma palavra da narração com o instante REAL em que é falada (segundos, relativos ao
// início do MP3). É o mesmo relógio que a montagem interna usa pra legenda word-level.
type Word struct {
	Word  string  `json:"word"`
	Start float64 `json:"start"`
	End   float64 `json:"end"`
}

// TTSWords — TTS com alinhamento por palavra. Igual ao TTS, mas pede timestamps ao serviço e
// devolve, junto da URL, as palavras com start/end reais — pra quem monta FORA da nossa
// montagem (editor externo, agente) sincronizar legenda/corte com a fala.
func (c *Client) TTSWords(ctx context.Context, text, voiceID, lang, model, format, style string) (string, []Word, error) {
	var out struct {
		URL   string `json:"url"`
		Words []Word `json:"words"`
		Error string `json:"error"`
	}
	if err := c.post(ctx, "/tts", map[string]any{"text": text, "voice_id": voiceID, "lang": lang,
		"tts_model": model, "tts_format": format, "tts_style": style, "timestamps": true}, &out); err != nil {
		return "", nil, err
	}
	if out.URL == "" {
		return "", nil, fmt.Errorf("síntese de narração falhou: %s", clip(out.Error, 160))
	}
	return out.URL, out.Words, nil
}

// SpeechLine — UMA fala do diálogo de uma cena (Estúdio de Animação): texto + voz do
// personagem. Style opcional (preset de entrega, ex "dramatico").
type SpeechLine struct {
	Text    string `json:"text"`
	VoiceID string `json:"voice_id"`
	Style   string `json:"tts_style,omitempty"`
}

// Dialogue — sintetiza o DIÁLOGO multi-voz de uma cena (1 TTS por fala, vozes distintas,
// respiro entre falas) e devolve o MP3 durável + a duração total (o chamador dimensiona o
// clipe i2v da cena a partir dela). gap em segundos (0 = default do serviço).
func (c *Client) Dialogue(ctx context.Context, lines []SpeechLine, model, format string, gap float64) (string, float64, error) {
	var out struct {
		URL      string  `json:"url"`
		Duration float64 `json:"duration"`
		Error    string  `json:"error"`
	}
	if err := c.post(ctx, "/dialogue", map[string]any{"lines": lines, "tts_model": model, "tts_format": format, "gap": gap}, &out); err != nil {
		return "", 0, err
	}
	if out.URL == "" {
		return "", 0, fmt.Errorf("síntese do diálogo falhou: %s", clip(out.Error, 160))
	}
	return out.URL, out.Duration, nil
}

// MuxAudio — casa o áudio do diálogo com o clipe i2v (mudo) da cena. Vídeo curto congela o
// último frame; áudio curto ganha silêncio — a saída dura max(vídeo, áudio).
func (c *Client) MuxAudio(ctx context.Context, videoURL, audioURL string) (string, error) {
	var out struct {
		URL   string `json:"url"`
		Error string `json:"error"`
	}
	if err := c.post(ctx, "/mux-audio", map[string]any{"video_url": videoURL, "audio_url": audioURL}, &out); err != nil {
		return "", err
	}
	if out.URL == "" {
		return "", fmt.Errorf("mixagem da cena falhou: %s", clip(out.Error, 160))
	}
	return out.URL, nil
}

// FilmMixOpts — opções da MONTAGEM do filme contínuo: trilha, narração contínua (a locução do
// roteiro inteiro entra por cima do filme já concatenado — durações intactas) e legenda
// word-level. Zero value = concat puro e mudo.
type FilmMixOpts struct {
	Music       bool
	MusicPrompt string
	Aspect      string
	Narration   bool   // locução contínua sobre o filme
	Script      string // roteiro inteiro (voiceovers dos trechos em sequência) — fallback
	// Scripts — locução POR CENA: um item por clipe (alinhado a clipURLs), cada fala ancorada no
	// INÍCIO da sua cena. É o que o console já enviava desde 2026-07-26 e o engine descartava (o
	// campo nem existia), fazendo a narração virar texto corrido no segundo 0. Vazio = usa Script.
	Scripts   []string
	VoiceID   string        // voz da locução
	Nar       NarrationOpts // modelo/formato/estilo do TTS + sincronismo (Delay/SubOffset)
	Subtitles bool          // legenda word-level queimada (só com narração)
	Sub       SubtitleStyle // estilo COMPLETO da legenda (mesmo das Histórias/Mídia)
	// Acabamento "Hollywood" (Sprint B) — todos opcionais, zero value = sem efeito.
	Grade      string // color grade: natural (default)|cinema_quente|teal_orange|noir|vintage
	Grain      bool   // film grain/halation sutil
	Letterbox  bool   // barras cinemascope 2.39:1 (só faz efeito com Aspect=16:9)
	EndcardURL string // imagem (já composta: logo+CTA) do nosso storage — cartela final ~2s
	// SFX/ambience (Sprint D) — camada de ambiente BEM baixa sob a trilha/narração. "" = sem efeito.
	AmbiencePrompt string
	// COLOR-MATCH (S3) — casa a exposição de cada trecho contra o 1º clipe (anti-drift de cor no
	// plano-sequência). Opcional; false = comportamento antigo.
	ColorMatch bool
	// PadFit — encaixa o clipe inteiro no quadro com barras (contain+pad) em vez de cortar. Usado
	// no lip-sync PER-FALA (talking-heads 3:4 num quadro 16:9 — sem pad, o crop come o rosto).
	PadFit bool
	// 🌊 FLUIDEZ — interpolação de movimento (fps 2×, teto 48) no vídeo final: movimento mais
	// fluido que os 24fps nativos do i2v. Opt-in (montagem demora minutos a mais); best-effort.
	Smooth bool
	// 🎬 Transições entre trechos (F1): no Filme o xfade completo é seguro (narração/música/
	// legenda entram DEPOIS da montagem). Zero value = corte seco (comportamento histórico).
	Transitions TransitionOpts
	// 🎇 Estúdio de Efeitos (F2/F4) — opcionais:
	GradeStrength int      // 1..99 = blend do color grade com o original (0/100 = look cheio)
	Vfx           []string // efeito visual POR TRECHO (alinhado aos clipes; "" = sem)
	Sfx           []string // efeito sonoro POR TRECHO (prompt curto; "" = sem)
	OverlayURLs   []string // overlay de partículas POR TRECHO (nosso S3; "" = sem)
}

// ConcatClips — MONTAGEM do filme contínuo (ffmpeg-service /concat-clips): concatena os clipes
// na ordem, normalizados (mesmos fps/resolução), SEM transição — a continuidade visual vem dos
// keyframes compartilhados. Trilha instrumental (protagonista sem narração; fundo com) e
// locução/legenda conforme FilmMixOpts. Retorna a URL do serviço; o caller persiste no Scality.
func (c *Client) ConcatClips(ctx context.Context, clipURLs []string, o FilmMixOpts) (string, error) {
	var out struct {
		VideoURL string `json:"video_url"`
		Error    string `json:"error"`
	}
	body := map[string]any{
		"clip_urls": clipURLs, "music": o.Music, "music_prompt": o.MusicPrompt, "aspect": o.Aspect,
		"pad_fit":   o.PadFit,
		"narration": o.Narration, "script": o.Script, "voice_id": o.VoiceID,
		"tts_model": o.Nar.Model, "tts_format": o.Nar.Format, "tts_style": o.Nar.Style,
		"audio_delay": o.Nar.Delay, "subtitle_offset": o.Nar.SubOffset,
		"subtitles":             o.Subtitles,
		"subtitle_pos":          o.Sub.Pos,
		"subtitle_size":         o.Sub.Size,
		"subtitle_color":        o.Sub.Color,
		"subtitle_border":       o.Sub.Border,
		"subtitle_border_color": o.Sub.BorderColor,
		"subtitle_font":         o.Sub.Font,
		"subtitle_opacity":      o.Sub.Opacity,
		"subtitle_bg":           o.Sub.Bg,
		"subtitle_bg_color":     o.Sub.BgColor,
		"subtitle_bg_opacity":   o.Sub.BgOpacity,
		"grade":                 o.Grade,
		"grain":                 o.Grain,
		"letterbox":             o.Letterbox,
		"endcard_url":           o.EndcardURL,
		"ambience_prompt":       o.AmbiencePrompt,
		"color_match":           o.ColorMatch,
		"smooth":                o.Smooth,
	}
	// 🎞️ Legenda ANIMADA (overlay do caption-service, igual ao /shortform): só entra no body
	// quando o preset foi escolhido — sem ele o payload fica byte-a-byte igual ao histórico e o
	// serviço queima a legenda ASS de sempre. Até agora o campo nem chegava aqui (o handler do
	// filmassemble não lia subtitleAnim), então o Filme ignorava a escolha em silêncio.
	if o.Sub.Anim != "" {
		body["subtitle_anim"] = o.Sub.Anim
		body["subtitle_accent_color"] = o.Sub.AccentColor // vazio = dourado (default do serviço)
	}
	if len(o.Scripts) > 0 {
		body["scripts"] = o.Scripts // locução POR CENA (só vai quando existe: body idêntico ao antigo sem ela)
	}
	if tb := o.Transitions.body(); tb != nil {
		body["transitions"] = tb // 🎬 F1: xfade/fades entre trechos
	}
	if o.GradeStrength > 0 && o.GradeStrength < 100 {
		body["grade_strength"] = o.GradeStrength // F2: intensidade do filtro
	}
	if len(o.Vfx) > 0 {
		body["vfx"] = o.Vfx // F4: efeito visual por trecho
	}
	if len(o.Sfx) > 0 {
		body["sfx"] = o.Sfx // F4: efeito sonoro por trecho
	}
	if len(o.OverlayURLs) > 0 {
		body["overlay_urls"] = o.OverlayURLs // F4: overlay de partículas por trecho
	}
	if err := c.post(ctx, "/concat-clips", body, &out); err != nil {
		return "", err
	}
	if out.VideoURL == "" {
		return "", fmt.Errorf("montagem do filme falhou (sem video_url): %s", clip(out.Error, 160))
	}
	return out.VideoURL, nil
}

// ImageFilter — aplica um filtro Instagram (color grade + intensidade) numa FOTO
// (ffmpeg-service /image-filter). Determinístico, sem custo de API. Retorna a URL do serviço.
func (c *Client) ImageFilter(ctx context.Context, imageURL, grade string, strength int) (string, error) {
	var out struct {
		URL   string `json:"url"`
		Error string `json:"error"`
	}
	body := map[string]any{"image_url": imageURL, "grade": grade}
	if strength > 0 && strength < 100 {
		body["grade_strength"] = strength
	}
	if err := c.post(ctx, "/image-filter", body, &out); err != nil {
		return "", err
	}
	if out.URL == "" {
		return "", fmt.Errorf("filtro falhou: %s", clip(out.Error, 160))
	}
	return out.URL, nil
}

// LastFrame — extrai o ÚLTIMO frame de um vídeo (ffmpeg-service /last-frame) → JPG durável no
// Scality. Usado pra re-ancorar keyframes no fim real de um trecho (filme, modo corrente).
func (c *Client) LastFrame(ctx context.Context, videoURL string) (string, error) {
	var out struct {
		URL   string `json:"url"`
		Error string `json:"error"`
	}
	if err := c.post(ctx, "/last-frame", map[string]any{"video_url": videoURL}, &out); err != nil {
		return "", err
	}
	if out.URL == "" {
		return "", fmt.Errorf("extração do frame falhou: %s", clip(out.Error, 160))
	}
	return out.URL, nil
}

// FramesAt — extrai frames em VÁRIAS posições (frações 0.0-1.0 da duração) de um vídeo, na
// mesma ordem das frações pedidas (ffmpeg-service /frames-at) → URLs JPG duráveis no Scality.
// Posição que falhar na extração vira "" na resposta (índice preservado, não derruba as outras).
func (c *Client) FramesAt(ctx context.Context, videoURL string, fractions []float64) ([]string, error) {
	var out struct {
		URLs  []string `json:"urls"`
		Error string   `json:"error"`
	}
	if err := c.post(ctx, "/frames-at", map[string]any{"video_url": videoURL, "fractions": fractions}, &out); err != nil {
		return nil, err
	}
	if out.Error != "" {
		return nil, fmt.Errorf("extração de frames falhou: %s", clip(out.Error, 160))
	}
	if len(out.URLs) == 0 {
		return nil, fmt.Errorf("extração de frames: sem resultado")
	}
	return out.URLs, nil
}

// StripAudio — remove o áudio de um vídeo (ffmpeg-service /strip-audio) → vídeo
// MUDO no Scality. Usado pelo "Veo + narração própria" antes de sobrepor a voz.
func (c *Client) StripAudio(ctx context.Context, videoURL string) (string, error) {
	var out struct {
		URL   string `json:"url"`
		Error string `json:"error"`
	}
	if err := c.post(ctx, "/strip-audio", map[string]any{"url": videoURL}, &out); err != nil {
		return "", err
	}
	if out.URL == "" {
		return "", fmt.Errorf("strip-audio falhou: %s", clip(out.Error, 160))
	}
	return out.URL, nil
}

// Gif — converte um vídeo (mp4) em GIF animado otimizado (ffmpeg-service /gif) e persiste no
// Scality. fps/width viram o tamanho/suavidade do gif (0 = defaults do serviço: 15fps, 480px).
// White-label: o erro nunca cita o provedor.
func (c *Client) Gif(ctx context.Context, videoURL string, fps, width int) (string, error) {
	var out struct {
		URL   string `json:"url"`
		Error string `json:"error"`
	}
	if err := c.post(ctx, "/gif", map[string]any{"url": videoURL, "fps": fps, "width": width, "loop": 0}, &out); err != nil {
		return "", err
	}
	if out.URL == "" {
		return "", fmt.Errorf("conversão para GIF falhou: %s", clip(out.Error, 160))
	}
	return out.URL, nil
}

// ClipSpec — um corte a extrair (start/end em segundos + palavras pra legenda).
type ClipSpec struct {
	Start float64         `json:"start"`
	End   float64         `json:"end"`
	Title string          `json:"title"`
	Score int             `json:"score"`
	Words []speech.WordTS `json:"words"`
}

type ClipResult struct {
	OK    bool   `json:"ok"`
	URL   string `json:"url"`
	Title string `json:"title"`
	Score int    `json:"score"`
}

// Clip — corta + reframe 9:16 (rosto) + legenda (ffmpeg-service /clip).
func (c *Client) Clip(ctx context.Context, videoURL string, clips []ClipSpec) ([]ClipResult, error) {
	var out struct {
		Clips []ClipResult `json:"clips"`
	}
	if err := c.post(ctx, "/clip", map[string]any{"video_url": videoURL, "clips": clips}, &out); err != nil {
		return nil, err
	}
	return out.Clips, nil
}

// Ingest — resolve um link (YouTube/genérico) pra MP4 hospedado (yt-dlp via /ingest).
func (c *Client) Ingest(ctx context.Context, url string) (string, error) {
	var out struct {
		VideoURL string `json:"video_url"`
		Error    string `json:"error"`
	}
	if err := c.post(ctx, "/ingest", map[string]any{"url": url}, &out); err != nil {
		return "", err
	}
	if out.VideoURL == "" {
		return "", fmt.Errorf("ingest falhou: %s", clip(out.Error, 180))
	}
	return out.VideoURL, nil
}

// Thumbnail — frame + título queimado (ffmpeg-service /thumbnail).
func (c *Client) Thumbnail(ctx context.Context, videoURL, title string) (string, error) {
	var out struct {
		URL   string `json:"url"`
		Error string `json:"error"`
	}
	if err := c.post(ctx, "/thumbnail", map[string]any{"video_url": videoURL, "title": title}, &out); err != nil {
		return "", err
	}
	if out.URL == "" {
		return "", fmt.Errorf("thumbnail falhou: %s", clip(out.Error, 160))
	}
	return out.URL, nil
}

// Persist — torna a mídia durável no Scality (s3.example.com). Fallback gracioso:
// qualquer falha devolve a URL original — NUNCA derruba a geração.
func (c *Client) Persist(ctx context.Context, url, kind, ext string) string {
	return c.PersistWithHeaders(ctx, url, kind, ext, nil)
}

// PersistWithHeaders — igual ao Persist, mas inclui headers no fetch da URL de origem
// (ex.: a URI do Veo exige o header de auth pra baixar). Quando há headers, eles vão
// no campo "fetch_headers" do POST /persist; o ffmpeg-service os repassa ao safe_fetch.
func (c *Client) PersistWithHeaders(ctx context.Context, url, kind, ext string, headers map[string]string) string {
	if url == "" || strings.Contains(url, "s3.example.com") {
		return url // já durável
	}
	var out struct {
		URL string `json:"url"`
	}
	body := map[string]any{"url": url, "kind": kind, "ext": ext}
	if len(headers) > 0 {
		body["fetch_headers"] = headers
	}
	if err := c.post(ctx, "/persist", body, &out); err != nil || out.URL == "" {
		return url
	}
	return out.URL
}

// PersistBytes — sobe BYTES direto pro Scality via /persist-bytes do ffmpeg-service.
// Existe pro produtor sem URL alcançável de lá (ex.: cli-bridge no host — o safe_fetch
// bloqueia IP privado por anti-SSRF). Diferente do Persist, NÃO tem fallback gracioso:
// bytes não são URL — sem persistir, a geração falha de verdade (e o console estorna).
func (c *Client) PersistBytes(ctx context.Context, data []byte, kind, ext string) (string, error) {
	var out struct {
		URL   string `json:"url"`
		Error string `json:"error"`
	}
	body := map[string]any{"b64": base64.StdEncoding.EncodeToString(data), "kind": kind, "ext": ext}
	if err := c.post(ctx, "/persist-bytes", body, &out); err != nil {
		return "", err
	}
	if out.URL == "" {
		return "", fmt.Errorf("persist-bytes falhou: %s", clip(out.Error, 160))
	}
	return out.URL, nil
}

func clip(s string, n int) string {
	if len(s) > n {
		return s[:n]
	}
	return s
}
