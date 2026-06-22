// Package api — superfície HTTP do engine (consumida pelo console Laravel e pelo web Next.js).
// F2: geração rápida (research, texto, imagem). F3 adiciona os jobs longos (vídeo/clipper).
package api

import (
	"encoding/json"
	"log"
	"net/http"
	"os"
	"strconv"
	"sync/atomic"
	"time"

	"github.com/lrdeoliveira/reachyn-oss/engine/internal/content"
	"github.com/lrdeoliveira/reachyn-oss/engine/internal/genkeys"
	"github.com/lrdeoliveira/reachyn-oss/engine/internal/provider/scraper"
	"github.com/lrdeoliveira/reachyn-oss/engine/internal/provider/search"
)

// maxBodyBytes — teto de corpo aceito em qualquer endpoint (AUD-011, CWE-400). Defesa
// contra DoS de memória: o decode lê só até este limite. 1MB cobre folgado os payloads
// legítimos (keyword + brief + fontes já clipadas pelo console); acima disso é abuso.
const maxBodyBytes = 1 << 20 // 1MB

type Server struct {
	cur      atomic.Pointer[content.Service]    // serviço vivo (swap atômico ao trocar chave)
	build    func(genkeys.Set) *content.Service // reconstrói o serviço com chaves sobrepostas
	admin    string                             // token de /v1/admin/* (compartilhado c/ console)
	genLimit *rateLimiter                       // RBK-005: teto global das rotas caras de geração
}

func New(build func(genkeys.Set) *content.Service, admin string) *Server {
	s := &Server{build: build, admin: admin}
	s.cur.Store(build(genkeys.Set{})) // arranca do .env

	// RBK-005: teto de segurança das gerações caras (anti denial-of-wallet). Configurável via
	// ENGINE_GEN_RATE_PER_MIN (default 60/min). É um teto GLOBAL do engine, independente da
	// quota por-plano do console — só morde se algo escapar dela (token vazado, abuso interno).
	rpm := 60.0
	if v := os.Getenv("ENGINE_GEN_RATE_PER_MIN"); v != "" {
		if n, err := strconv.ParseFloat(v, 64); err == nil && n > 0 {
			rpm = n
		}
	}
	s.genLimit = newRateLimiter(rpm, rpm/60.0)
	return s
}

// rateLimited — aplica o teto global das rotas caras (RBK-005). 429 quando estourado.
func (s *Server) rateLimited(h http.HandlerFunc) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		if !s.genLimit.allow() {
			writeJSON(w, http.StatusTooManyRequests, map[string]string{"error": "rate_limited"})
			return
		}
		h(w, r)
	}
}

// svc devolve o serviço vivo (com as chaves de geração atuais).
func (s *Server) svc() *content.Service { return s.cur.Load() }

// requireAdmin protege as rotas /v1/* de geração (AUD-002, CWE-306). O ÚNICO chamador
// legítimo é o CONSOLE (o web fala só com o console), que reenvia o token compartilhado
// ENGINE_ADMIN_TOKEN no header X-Admin-Token. Defesa em profundidade: o console já
// autentica o tenant/quota; aqui o engine recusa qualquer chamador sem o token. Mesma
// comparação constant-time do authAdmin (s.tokenOK).
func (s *Server) requireAdmin(h http.HandlerFunc) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		if !s.tokenOK(r) {
			writeJSON(w, http.StatusForbidden, map[string]string{"error": "forbidden"})
			return
		}
		h(w, r)
	}
}

func (s *Server) Routes() http.Handler {
	mux := http.NewServeMux()
	// /health é PÚBLICO (probe de liveness/Traefik) — não exige token.
	mux.HandleFunc("GET /health", func(w http.ResponseWriter, r *http.Request) {
		writeJSON(w, http.StatusOK, map[string]string{"status": "ok", "service": "reachyn-engine"})
	})
	// Geração — protegida pelo token compartilhado engine⇄console (X-Admin-Token).
	mux.HandleFunc("POST /v1/research", s.requireAdmin(s.research))
	mux.HandleFunc("POST /v1/deepsearch", s.requireAdmin(s.deepsearch))
	mux.HandleFunc("POST /v1/search-test", s.requireAdmin(s.searchTest))
	mux.HandleFunc("POST /v1/summarize", s.requireAdmin(s.summarize))
	mux.HandleFunc("POST /v1/text", s.requireAdmin(s.text))
	mux.HandleFunc("POST /v1/image", s.requireAdmin(s.rateLimited(s.image)))
	mux.HandleFunc("POST /v1/imageprompt", s.requireAdmin(s.imageprompt))
	mux.HandleFunc("POST /v1/mediaprompts", s.requireAdmin(s.mediaprompts))
	// F3 — mídia pesada (jobs longos)
	mux.HandleFunc("POST /v1/video", s.requireAdmin(s.rateLimited(s.video)))
	mux.HandleFunc("POST /v1/short", s.requireAdmin(s.rateLimited(s.short)))
	mux.HandleFunc("POST /v1/premium-video", s.requireAdmin(s.rateLimited(s.premiumVideo)))
	mux.HandleFunc("POST /v1/thumbnail", s.requireAdmin(s.rateLimited(s.thumbnail)))
	mux.HandleFunc("POST /v1/viral", s.requireAdmin(s.rateLimited(s.viral)))
	mux.HandleFunc("GET /v1/voices", s.requireAdmin(s.voices))
	mux.HandleFunc("POST /v1/transcribe", s.requireAdmin(s.transcribe))
	mux.HandleFunc("POST /v1/clip", s.requireAdmin(s.rateLimited(s.clip)))
	// Admin (token compartilhado com o console) — chaves de geração geridas pelo operador.
	mux.HandleFunc("PUT /v1/admin/gen-keys", s.setGenKeys)
	mux.HandleFunc("POST /v1/admin/test-key", s.testKey)
	return mux
}

func (s *Server) research(w http.ResponseWriter, r *http.Request) {
	var in struct {
		Keyword string            `json:"keyword"`
		Sources []string          `json:"sources"`
		Keys    map[string]string `json:"keys"`  // BYOK: chaves de pesquisa do tenant
		Lines   content.Lines     `json:"lines"` // principal/reserva por função (ausente ⇒ defaults)
	}
	if !decode(w, r, &in) {
		return
	}
	out, err := s.svc().Research(r.Context(), in.Keyword, in.Sources, in.Keys, in.Lines)
	if err != nil {
		writeErr(w, err)
		return
	}
	writeJSON(w, http.StatusOK, out)
}

func (s *Server) deepsearch(w http.ResponseWriter, r *http.Request) {
	var in struct {
		Keyword string            `json:"keyword"`
		Keys    map[string]string `json:"keys"`
		Lines   content.Lines     `json:"lines"` // principal/reserva por função (ausente ⇒ defaults)
	}
	if !decode(w, r, &in) {
		return
	}
	out, err := s.svc().DeepResearch(r.Context(), in.Keyword, in.Keys, in.Lines)
	if err != nil {
		writeErr(w, err)
		return
	}
	writeJSON(w, http.StatusOK, out)
}

// search-test — valida a chave de UM provedor de pesquisa/scraper SEM gerar conteúdo pago.
// Mesmo padrão de proteção de /v1/research (requireAdmin). Body {provider, key}.
// Resposta: {"ok": bool, "latency_ms": int, "error"?: string}.
func (s *Server) searchTest(w http.ResponseWriter, r *http.Request) {
	var in struct {
		Provider string `json:"provider"`
		Key      string `json:"key"`
	}
	if !decode(w, r, &in) {
		return
	}
	start := time.Now()
	var err error
	switch in.Provider {
	case "scraper":
		err = scraper.Ping(r.Context(), in.Key)
	default:
		// slots de busca (search-primary | search-alt | reader);
		// qualquer outro → erro claro de provedor inválido.
		err = search.Ping(r.Context(), in.Provider, in.Key)
	}
	res := map[string]any{"ok": err == nil, "latency_ms": time.Since(start).Milliseconds()}
	if err != nil {
		res["error"] = err.Error()
	}
	writeJSON(w, http.StatusOK, res)
}

func (s *Server) summarize(w http.ResponseWriter, r *http.Request) {
	var in struct {
		Keyword string           `json:"keyword"`
		Sources []content.Source `json:"sources"`
	}
	if !decode(w, r, &in) {
		return
	}
	out, err := s.svc().Summarize(r.Context(), in.Keyword, in.Sources)
	if err != nil {
		writeErr(w, err)
		return
	}
	writeJSON(w, http.StatusOK, out)
}

func (s *Server) text(w http.ResponseWriter, r *http.Request) {
	var in struct {
		Keyword  string           `json:"keyword"`
		Brief    string           `json:"brief"`
		Facts    string           `json:"facts"` // material cru das fontes (base factual + grounding)
		Platform string           `json:"platform"`
		GenLines content.GenLines `json:"gen_lines"` // principal/reserva por função de geração (ausente ⇒ defaults)
	}
	if !decode(w, r, &in) {
		return
	}
	out, err := s.svc().GenerateText(r.Context(), in.Keyword, in.Brief, in.Facts, in.Platform, in.GenLines)
	if err != nil {
		writeErr(w, err)
		return
	}
	writeJSON(w, http.StatusOK, out)
}

func (s *Server) image(w http.ResponseWriter, r *http.Request) {
	var in struct {
		Prompt   string           `json:"prompt"`
		Aspect   string           `json:"aspect"`
		Style    string           `json:"style"`
		ImageURL string           `json:"imageUrl"`  // opcional: URL pública do nosso S3 → image-to-image
		GenLines content.GenLines `json:"gen_lines"` // principal/reserva por função de geração (ausente ⇒ defaults)
	}
	if !decode(w, r, &in) {
		return
	}
	if in.Aspect == "" {
		in.Aspect = "1:1"
	}
	if in.Style == "" {
		in.Style = "realista"
	}
	url, err := s.svc().GenerateImage(r.Context(), in.Prompt, in.Aspect, in.Style, in.ImageURL, in.GenLines)
	if err != nil {
		writeErr(w, err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]string{"url": url})
}

func (s *Server) imageprompt(w http.ResponseWriter, r *http.Request) {
	var in struct {
		Keyword string `json:"keyword"`
		Summary string `json:"summary"`
		Aspect  string `json:"aspect"` // opcional: enquadramento condizente com o tamanho da imagem
	}
	if !decode(w, r, &in) {
		return
	}
	writeJSON(w, http.StatusOK, map[string]string{"prompt": s.svc().SuggestImagePrompt(r.Context(), in.Keyword, in.Summary, in.Aspect)})
}

// mediaprompts — sugere o(s) prompt(s) de mídia JÁ condizentes com o tipo/tamanho/duração
// escolhidos. kind="image"|"video" gera só o do tipo pedido; vazio = ambos (retrocompat).
func (s *Server) mediaprompts(w http.ResponseWriter, r *http.Request) {
	var in struct {
		Keyword  string   `json:"keyword"`
		Summary  string   `json:"summary"`
		Texts    []string `json:"texts"`    // posts já gerados, ordenados por aderência ao resumo (priorização)
		Kind     string   `json:"kind"`     // opcional: "image"|"video" (vazio = ambos)
		Aspect   string   `json:"aspect"`   // opcional: enquadramento condizente com o tamanho
		Duration string   `json:"duration"` // opcional: duração do clipe (vídeo) — calibra a ação
	}
	if !decode(w, r, &in) {
		return
	}
	img, vid := s.svc().SuggestMediaPrompts(r.Context(), in.Keyword, in.Summary, in.Texts, in.Kind, in.Aspect, in.Duration)
	writeJSON(w, http.StatusOK, map[string]string{"imagePrompt": img, "videoPrompt": vid})
}

// voices — lista as vozes disponíveis na conta do provedor (seletor de narração).
func (s *Server) voices(w http.ResponseWriter, r *http.Request) {
	vs, err := s.svc().ListVoices(r.Context())
	if err != nil {
		writeErr(w, err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"voices": vs})
}

// video — ORQUESTRADOR ÚNICO de vídeo (/v1/video estendido). Todos os campos são opcionais
// com defaults retrocompatíveis: um body antigo {prompt,style,imageUrl,duration} cai no atalho
// barato (1 clipe, sem áudio/legenda/música) — comportamento idêntico ao anterior. Ligar
// scenes>1 OU qualquer etapa (narration/subtitles/music) entra no fluxo geral; cada etapa
// desligada PULA a etapa correspondente (economia de recurso). Resposta: {"url": ...} (e
// "beats" quando há segmentação). Premium NÃO entra aqui — continua no endpoint premium.
func (s *Server) video(w http.ResponseWriter, r *http.Request) {
	var in struct {
		Prompt    string           `json:"prompt"`
		Style     string           `json:"style"`
		ImageURL  string           `json:"imageUrl"`  // opcional: URL pública do nosso S3 → base i2v
		Aspect    string           `json:"aspect"`    // opcional: default "9:16"
		Scenes    int              `json:"scenes"`    // opcional: 1=curto (1 clipe), 3..12=longo (clamp 1..12, default 1)
		Duration  string           `json:"duration"`  // opcional: "6"|"10" (default "6") — duração de cada clipe
		Narration bool             `json:"narration"` // opcional: narração TTS por cena (default false)
		VoiceID   string           `json:"voiceId"`   // opcional: voz (usada se narration||subtitles)
		Lang      string           `json:"lang"`      // opcional: "pt-BR"|"en-US" (default pt-BR)
		Subtitles bool             `json:"subtitles"` // opcional: queimar legenda (default false)
		Music     bool             `json:"music"`     // opcional: trilha de fundo (default false)
		GenLines  content.GenLines `json:"gen_lines"` // principal/reserva por função (video: video-a|video-b|video-c; ausente ⇒ defaults)
	}
	if !decode(w, r, &in) {
		return
	}
	// Modelo de vídeo PRINCIPAL/FALLBACK da gen_lines.video (default video-a→video-b).
	vln := in.GenLines.WithDefaults().Video
	out, err := s.svc().GenerateVideoUnified(r.Context(), content.VideoOptions{
		Prompt:        in.Prompt,
		Style:         in.Style,
		ImageURL:      in.ImageURL,
		Aspect:        in.Aspect,
		Scenes:        in.Scenes,
		Duration:      in.Duration,
		Narration:     in.Narration,
		VoiceID:       in.VoiceID,
		Lang:          in.Lang,
		Subtitles:     in.Subtitles,
		Music:         in.Music,
		VideoModel:    vln.Primary,
		VideoFallback: vln.Fallback,
	})
	if err != nil {
		writeErr(w, err)
		return
	}
	// Resposta retrocompatível: sempre {"url"}; "beats" só quando houve segmentação (>0).
	resp := map[string]any{"url": out.URL}
	if len(out.Beats) > 0 {
		resp["beats"] = out.Beats
	}
	writeJSON(w, http.StatusOK, resp)
}

func (s *Server) short(w http.ResponseWriter, r *http.Request) {
	var in struct {
		Keyword     string           `json:"keyword"`
		Brief       string           `json:"brief"`
		VoiceID     string           `json:"voiceId"`
		ImageURL    string           `json:"imageUrl"`    // opcional: URL pública do nosso S3 → base i2v de todos os beats
		Scenes      int              `json:"scenes"`      // opcional: nº de cenas (default 5, clamp 3..12 no service) — mais cenas = vídeo maior
		VideoPrompt string           `json:"videoPrompt"` // opcional: quando preenchido, as cenas derivam DESSE prompt do usuário
		Lang        string           `json:"lang"`        // opcional: idioma da narração "pt-BR"|"en-US" (default pt-BR)
		GenLines    content.GenLines `json:"gen_lines"`   // principal/reserva por função (video: video-a|video-b|video-c; ausente ⇒ defaults)
	}
	if !decode(w, r, &in) {
		return
	}
	out, err := s.svc().GenerateShortVideo(r.Context(), in.Keyword, in.Brief, in.VoiceID, in.ImageURL, in.Scenes, in.VideoPrompt, in.Lang, in.GenLines)
	if err != nil {
		writeErr(w, err)
		return
	}
	writeJSON(w, http.StatusOK, out)
}

func (s *Server) premiumVideo(w http.ResponseWriter, r *http.Request) {
	var in struct {
		Keyword   string `json:"keyword"`
		Brief     string `json:"brief"`
		Style     string `json:"style"`
		Lang      string `json:"lang"`      // opcional: idioma da fala "pt-BR"|"en-US" (default pt-BR)
		Narration bool   `json:"narration"` // true = premium MUDO + narração própria (voz do tenant) + legenda
		VoiceID   string `json:"voiceId"`   // voz do tenant (usada quando narration)
	}
	if !decode(w, r, &in) {
		return
	}
	var (
		url string
		err error
	)
	if in.Narration {
		url, err = s.svc().GeneratePremiumNarrated(r.Context(), in.Keyword, in.Brief, in.Style, in.Lang, in.VoiceID)
	} else {
		url, err = s.svc().GeneratePremiumShort(r.Context(), in.Keyword, in.Brief, in.Style, in.Lang)
	}
	if err != nil {
		writeErr(w, err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]string{"url": url})
}

func (s *Server) thumbnail(w http.ResponseWriter, r *http.Request) {
	var in struct {
		VideoURL string `json:"videoUrl"`
		Title    string `json:"title"`
	}
	if !decode(w, r, &in) {
		return
	}
	url, err := s.svc().GenerateThumbnail(r.Context(), in.VideoURL, in.Title)
	if err != nil {
		writeErr(w, err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]string{"url": url})
}

func (s *Server) viral(w http.ResponseWriter, r *http.Request) {
	var in struct {
		PhotoURL string `json:"photoUrl"`
		Template string `json:"template"`
		Title    string `json:"title"`
		Theme    string `json:"theme"`
	}
	if !decode(w, r, &in) {
		return
	}
	url, err := s.svc().GenerateViral(r.Context(), in.PhotoURL, in.Template, in.Title, in.Theme)
	if err != nil {
		writeErr(w, err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]string{"url": url})
}

func (s *Server) transcribe(w http.ResponseWriter, r *http.Request) {
	var in struct {
		VideoURL string `json:"videoUrl"`
	}
	if !decode(w, r, &in) {
		return
	}
	out, err := s.svc().Transcribe(r.Context(), in.VideoURL)
	if err != nil {
		writeErr(w, err)
		return
	}
	writeJSON(w, http.StatusOK, out)
}

func (s *Server) clip(w http.ResponseWriter, r *http.Request) {
	var in struct {
		VideoURL string `json:"videoUrl"`
		N        int    `json:"n"`
	}
	if !decode(w, r, &in) {
		return
	}
	out, err := s.svc().ClipLongVideo(r.Context(), in.VideoURL, in.N)
	if err != nil {
		writeErr(w, err)
		return
	}
	writeJSON(w, http.StatusOK, out)
}

// ── helpers ──

func decode(w http.ResponseWriter, r *http.Request, v any) bool {
	// Limita o corpo lido (AUD-011): MaxBytesReader aborta o decode se passar do teto,
	// evitando que um corpo gigante consuma memória do engine.
	r.Body = http.MaxBytesReader(w, r.Body, maxBodyBytes)
	if err := json.NewDecoder(r.Body).Decode(v); err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": "json inválido: " + err.Error()})
		return false
	}
	return true
}

func writeJSON(w http.ResponseWriter, status int, v any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(v)
}

// writeErr — falha de geração. O erro REAL (que cita providers internos de IA
// etc.) é LOGADO server-side, mas o cliente recebe só uma
// mensagem GENÉRICA — white-label (AUD-013): nunca vazar nome de provedor de IA no público.
func writeErr(w http.ResponseWriter, err error) {
	log.Printf("erro de geração: %v", err)
	writeJSON(w, http.StatusBadGateway, map[string]string{"error": "a IA está indisponível no momento, tente novamente"})
}
