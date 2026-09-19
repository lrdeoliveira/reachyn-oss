// Package api — superfície HTTP do engine (consumida pelo console Laravel e pelo web Next.js).
// F2: geração rápida (research, texto, imagem). F3 adiciona os jobs longos (vídeo/clipper).
package api

import (
	"encoding/json"
	"log"
	"net/http"
	"os"
	"strconv"
	"strings"
	"sync/atomic"
	"time"

	"github.com/redfoxcode/reachyn/engine/internal/content"
	"github.com/redfoxcode/reachyn/engine/internal/genkeys"
	"github.com/redfoxcode/reachyn/engine/internal/gerr"
	"github.com/redfoxcode/reachyn/engine/internal/media"
	"github.com/redfoxcode/reachyn/engine/internal/provider/image"
	"github.com/redfoxcode/reachyn/engine/internal/provider/motion"
	"github.com/redfoxcode/reachyn/engine/internal/provider/scraper"
	"github.com/redfoxcode/reachyn/engine/internal/provider/search"
	"github.com/redfoxcode/reachyn/engine/internal/provider/sprite"
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

	// Estúdio Local (ComfyUI/mesh/sprite) — OPCIONAL por topologia: sem COMFY_URL configurado
	// (caso da VPS, sem GPU) estes clients ficam vazios e os handlers respondem com erro claro
	// ("não configurado"), nunca panic. Só existem de fato no Mac do Luciano (dev local).
	spriteCl    atomic.Pointer[sprite.Client] // motor de sprites (aba Sprites) — swap junto com as gen-keys
	spriteMedia *media.Client                 // persistência de artefatos de sprite (Scality via ffmpeg-service)
}

func New(build func(genkeys.Set) *content.Service, admin string) *Server {
	s := &Server{build: build, admin: admin}
	s.cur.Store(build(genkeys.Set{})) // arranca do .env
	s.refreshSprite(genkeys.Set{})
	s.spriteMedia = spriteMediaClient()

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
	mux.HandleFunc("POST /v1/chat", s.requireAdmin(s.chat)) // 💬 persona livre da aba Prompts (Escaleta, Arquiteto, Molde, Decupagem)
	mux.HandleFunc("POST /v1/story", s.requireAdmin(s.story))
	mux.HandleFunc("POST /v1/storystructure", s.requireAdmin(s.storystructure))         // 🎬 espinha dramática (S2 passo 1)
	mux.HandleFunc("POST /v1/storyreview", s.requireAdmin(s.storyreview))               // 🎬 script doctor (S2)
	mux.HandleFunc("POST /v1/ideas", s.requireAdmin(s.ideas))                           // 💡 gerador de ideias (F1 da Fábrica de Conteúdo)
	mux.HandleFunc("POST /v1/optimize", s.requireAdmin(s.optimize))                     // 🚀 pacote de otimização (F4 da Fábrica de Conteúdo)
	mux.HandleFunc("POST /v1/repurpose", s.requireAdmin(s.repurpose))                   // ♻️ transformação de formato (F6 da Fábrica de Conteúdo)
	mux.HandleFunc("POST /v1/calendar", s.requireAdmin(s.calendar))                     // 🗓️ calendário editorial + séries (F5 da Fábrica de Conteúdo)
	mux.HandleFunc("POST /v1/carousel", s.requireAdmin(s.carousel))                     // 🎠 plano editorial do carrossel (headline + narrativa + brief por slide)
	mux.HandleFunc("POST /v1/visualbrief", s.requireAdmin(s.visualbrief))               // 🖼️ lê as referências da marca (visão) → briefing que comanda os briefs de imagem
	mux.HandleFunc("POST /v1/describe", s.requireAdmin(s.rateLimited(s.describeMedia))) // 👁️ lê a MÍDIA do post (imagem ou vídeo) → descrição que ancora a legenda no /v1/text
	mux.HandleFunc("POST /v1/characterbible", s.requireAdmin(s.characterbible))
	mux.HandleFunc("POST /v1/image", s.requireAdmin(s.rateLimited(s.image)))
	mux.HandleFunc("GET /v1/comfy/health", s.requireAdmin(s.comfyHealth))                // 🟢 luz de status do Estúdio Local (ComfyUI): de pé? checkpoint ativo + instalados — sem COMFY_URL, responde "não configurado"
	mux.HandleFunc("GET /v1/stylewrap", s.requireAdmin(s.stylewrap))                     // prefixo+sufixo de estilo (StyledPrompt) — pro console montar o prompt EXATO pra copiar/usar fora
	mux.HandleFunc("POST /v1/anglessheet", s.requireAdmin(s.rateLimited(s.anglessheet))) // turnaround (prancha de ângulos): KIE por vista, ou órbita de câmera (vídeo) se o KIE cair
	mux.HandleFunc("POST /v1/imageprompt", s.requireAdmin(s.imageprompt))
	mux.HandleFunc("POST /v1/mediaprompts", s.requireAdmin(s.mediaprompts))
	// F3 — mídia pesada (jobs longos)
	mux.HandleFunc("POST /v1/video", s.requireAdmin(s.rateLimited(s.video)))
	// ✅ Só o ROTEIRO (sem imagem, sem clipe): custa uma chamada de texto e deixa o cliente ler e
	// corrigir antes de a peça virar dinheiro. Ver o campo Beats em content.VideoOptions.
	// 💳 Saldo do provedor pra TELA: o cliente precisa saber ANTES de clicar em gerar, não no meio.
	mux.HandleFunc("GET /v1/saldo", s.requireAdmin(s.saldo))
	mux.HandleFunc("POST /v1/beats", s.requireAdmin(s.rateLimited(s.beats)))
	// 📰 UMA cena do Vox (imagem + clipe do beat) — regeneração beat a beat do storyboard da aba /video (V2).
	// A montagem final das cenas prontas é o /v1/filmassemble de sempre.
	mux.HandleFunc("POST /v1/voxscene", s.requireAdmin(s.rateLimited(s.voxscene)))
	mux.HandleFunc("POST /v1/gif", s.requireAdmin(s.rateLimited(s.gif)))
	mux.HandleFunc("POST /v1/enhance", s.requireAdmin(s.rateLimited(s.enhance)))
	mux.HandleFunc("POST /v1/motiontransfer", s.requireAdmin(s.rateLimited(s.motionTransfer)))
	mux.HandleFunc("POST /v1/short", s.requireAdmin(s.rateLimited(s.short)))
	mux.HandleFunc("POST /v1/storyvideo", s.requireAdmin(s.rateLimited(s.storyvideo)))
	// 🎬 Estúdio de Animação (roteiro → desenho pronto): parser + diálogo multi-voz + mux.
	// Refs/keyframes/i2v/montagem reusam /v1/image, /v1/characterbible, /v1/filmclip e /v1/filmassemble.
	mux.HandleFunc("POST /v1/scriptparse", s.requireAdmin(s.scriptparse))
	mux.HandleFunc("POST /v1/dialogueaudio", s.requireAdmin(s.rateLimited(s.dialogueaudio)))
	mux.HandleFunc("POST /v1/muxaudio", s.requireAdmin(s.rateLimited(s.muxaudio)))
	// Filme contínuo (plano-sequência): plano de filmagem, clipe com 1º+último frame e montagem.
	mux.HandleFunc("POST /v1/filmplan", s.requireAdmin(s.filmplan))
	mux.HandleFunc("POST /v1/filmsection", s.requireAdmin(s.filmsection)) // regenera 1 seção do plano
	mux.HandleFunc("POST /v1/filmclip", s.requireAdmin(s.rateLimited(s.filmclip)))
	mux.HandleFunc("POST /v1/filmquick", s.requireAdmin(s.rateLimited(s.filmquick))) // ⚡ Filme rápido (multi_shots)
	mux.HandleFunc("POST /v1/filmassemble", s.requireAdmin(s.rateLimited(s.filmassemble)))
	mux.HandleFunc("POST /v1/lipsync", s.requireAdmin(s.rateLimited(s.lipsync))) // 🎬 lip sync: talking-head (imagem+áudio → boca sincronizada)
	mux.HandleFunc("POST /v1/lastframe", s.requireAdmin(s.lastframe))
	mux.HandleFunc("POST /v1/imagefilter", s.requireAdmin(s.imagefilter))
	mux.HandleFunc("POST /v1/tts", s.requireAdmin(s.rateLimited(s.tts)))
	mux.HandleFunc("POST /v1/veo", s.requireAdmin(s.rateLimited(s.veo)))
	mux.HandleFunc("POST /v1/music", s.requireAdmin(s.rateLimited(s.music)))

	// 🕹️ Sprites de jogo (aba /sprite) — proxy pro motor hospedado + pipeline local. Sem
	// config, os handlers devolvem erro claro (ver sprite.go), nunca panic.
	mux.HandleFunc("GET /v1/sprite/me", s.requireAdmin(s.spriteMe))
	mux.HandleFunc("POST /v1/sprite/jobs", s.requireAdmin(s.rateLimited(s.spriteEnqueue)))
	mux.HandleFunc("GET /v1/sprite/jobs", s.requireAdmin(s.spriteList))
	mux.HandleFunc("GET /v1/sprite/jobs/{jobId}", s.requireAdmin(s.spriteJob))
	mux.HandleFunc("POST /v1/sprite/jobs/{jobId}/persist", s.requireAdmin(s.rateLimited(s.spritePersist)))
	mux.HandleFunc("POST /v1/sprite/frames", s.requireAdmin(s.spriteFrames))
	mux.HandleFunc("POST /v1/sprite/normalize", s.requireAdmin(s.spriteNormalize))

	// 🧊 malha 3D (Estúdio) — mesh.New(cfg.ComfyURL) fica com client vazio sem COMFY_URL; os
	// handlers respondem "ComfyUI não configurado" em vez de nil pointer (ver mesh.go/meshgen.go).
	mux.HandleFunc("POST /v1/mesh/generate", s.requireAdmin(s.rateLimited(s.meshGenerate))) // imagem → malha 3D (GLB) no Estúdio
	mux.HandleFunc("GET /v1/mesh/health", s.requireAdmin(s.meshGenHealth))                  // o gerador de malha está instalado?
	mux.HandleFunc("POST /v1/mesh/render", s.requireAdmin(s.rateLimited(s.meshRender)))

	mux.HandleFunc("POST /v1/thumbnail", s.requireAdmin(s.rateLimited(s.thumbnail)))
	mux.HandleFunc("GET /v1/voices", s.requireAdmin(s.voices))
	mux.HandleFunc("POST /v1/transcribe", s.requireAdmin(s.transcribe))
	mux.HandleFunc("POST /v1/clip", s.requireAdmin(s.rateLimited(s.clip)))
	// Admin (token compartilhado com o console) — chaves de geração geridas pelo operador.
	mux.HandleFunc("PUT /v1/admin/gen-keys", s.setGenKeys)
	mux.HandleFunc("POST /v1/admin/test-key", s.testKey)

	// Carimba X-Reachyn-Reserva quando a reserva atendeu — é como o console sabe que não pode
	// cobrar o preço do modelo premium por esta entrega. Ver reserva.go.
	return comMarcadorDeReserva(mux)
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
	case "scrapecreators":
		err = scraper.Ping(r.Context(), in.Key)
	default:
		// tavily | brave | jina (e qualquer outro → erro claro de provedor inválido).
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
		Keyword  string           `json:"keyword"`
		Sources  []content.Source `json:"sources"`
		Persona  string           `json:"persona"`   // estilo do RESUMO (ex: Resumidor Executivo; vazio = analista padrão)
		GenLines content.GenLines `json:"gen_lines"` // line de texto (modelo do seletor; ausente ⇒ default)
	}
	if !decode(w, r, &in) {
		return
	}
	out, err := s.svc().Summarize(r.Context(), in.Keyword, in.Sources, in.Persona, in.GenLines)
	if err != nil {
		writeErr(w, err)
		return
	}
	writeJSON(w, http.StatusOK, out)
}

func (s *Server) text(w http.ResponseWriter, r *http.Request) {
	var in struct {
		Keyword string `json:"keyword"`
		Brief   string `json:"brief"`
		Facts   string `json:"facts"` // material cru das fontes (base factual + grounding)
		// 🖼️ descrição da MÍDIA que acompanha o post (vinda do /v1/describe). Vazio = comportamento
		// histórico. É o que faz a legenda do upload manual falar do arquivo, e não do vazio.
		Visual   string           `json:"visual"`
		Platform string           `json:"platform"`
		Lang     string           `json:"lang"`      // idioma do post: "pt-BR" (default) ou "en-US"
		Persona  string           `json:"persona"`   // 🎬 Roteirista do POST (craft; vazio = redator padrão)
		GenLines content.GenLines `json:"gen_lines"` // principal/reserva por função de geração (ausente ⇒ defaults)
	}
	if !decode(w, r, &in) {
		return
	}
	out, err := s.svc().GenerateText(r.Context(), in.Keyword, in.Brief, in.Facts, in.Visual, in.Platform, in.Lang, in.Persona, in.GenLines)
	if err != nil {
		writeErr(w, err)
		return
	}
	writeJSON(w, http.StatusOK, out)
}

// chat — texto livre com PERSONA do usuário (system = a persona editável da aba Prompts).
//
// É a rota que o console chama em 5 lugares e que NUNCA existiu no engine: a Escaleta
// (ProjectController::gerarEscaleta), o Doutor de Roteiro (ProjectController), o chat de persona /
// Arquiteto de Personagem e o MOLDE de personagem (CharacterController), e a DECUPAGEM de planos
// (ShotController). O serviço (content.Service.Chat) já estava pronto desde sempre — só faltava
// pendurar o handler no mux, então em produção esses 5 botões batiam em 404 enquanto as rotas
// vizinhas respondiam normalmente. É por isso que o handler é fino: ele não inventa lógica nova,
// só honra o contrato que o console já enviava.
//
// Contrato (união dos 5 chamadores): {system, message, json?, maxTokens?, gen_lines?} →
// {ok, text}. `maxTokens` é OBRIGATÓRIO respeitar: os chamadores pedem 6000/4000/3000/1200
// justamente porque o default de 2000 do serviço TRUNCAVA a resposta (JSON cortado não parseia e
// o console acusava "respondeu fora do formato" sem o modelo ter errado). O clamp fica no serviço
// (<=0 ou >8000 ⇒ 2000).
//
// `json:true` liga o JSON-mode do provedor E limpa a resposta com content.ExtractJSON, porque nem
// com JSON-mode o modelo entrega limpo (cerca ```json, frase de abertura, raciocínio vazado da
// linha de reserva). Assim os parsers em PHP recebem objeto puro. Com json:false (chat do
// Arquiteto) o texto sai VERBATIM — ali a resposta é prosa e recortar chave seria destruir.
func (s *Server) chat(w http.ResponseWriter, r *http.Request) {
	var in struct {
		System    string           `json:"system"`  // a persona da aba Prompts (prompt de sistema)
		Message   string           `json:"message"` // mensagem do usuário
		JSON      bool             `json:"json"`    // exige resposta JSON (default false = prosa)
		MaxTokens int              `json:"maxTokens"`
		GenLines  content.GenLines `json:"gen_lines"` // principal/reserva de texto (ausente ⇒ defaults)
	}
	if !decode(w, r, &in) {
		return
	}
	// Campo faltando é erro DO CHAMADOR, não da IA: 400. Se deixasse cair no serviço, o
	// writeErr traduziria pra 502 "a IA está indisponível" e o console retentaria 3× um payload
	// que jamais ia passar.
	if strings.TrimSpace(in.System) == "" || strings.TrimSpace(in.Message) == "" {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": "system e message são obrigatórios"})
		return
	}
	txt, err := s.svc().Chat(r.Context(), in.System, in.Message, in.MaxTokens, in.JSON, in.GenLines)
	if err != nil {
		writeErr(w, err)
		return
	}
	if in.JSON {
		txt = content.ExtractJSON(txt)
	}
	writeJSON(w, http.StatusOK, map[string]any{"ok": true, "text": txt})
}

// story — gera uma história de stickman em N cenas (image/video prompt + voiceover por cena).
func (s *Server) story(w http.ResponseWriter, r *http.Request) {
	var in struct {
		Theme     string                  `json:"theme"`
		Character string                  `json:"character"` // opcional: CHARACTER LOCK customizado (vazio = stickman padrão)
		Scenario  string                  `json:"scenario"`  // opcional: CENÁRIO BASE — mundo/ambiente compartilhado por todas as cenas
		Lang      string                  `json:"lang"`      // "pt-BR" | "en-US" (default en-US): idioma de títulos/descrições/voiceover
		Persona   string                  `json:"persona"`   // opcional: craft/voz do ROTEIRISTA escolhido (aba Prompts); vazio = mestre padrão
		Scenes    int                     `json:"scenes"`    // opcional: nº de cenas (clamp 3..50 no service; 0 = default 8)
		Structure *content.StoryStructure `json:"structure"` // opcional (S2): espinha dramática aprovada (passo 1)
		GenLines  content.GenLines        `json:"gen_lines"`
	}
	if !decode(w, r, &in) {
		return
	}
	out, err := s.svc().GenerateStory(r.Context(), in.Theme, in.Character, in.Scenario, in.Lang, in.Persona, in.Scenes, in.GenLines, in.Structure)
	if err != nil {
		writeErr(w, err)
		return
	}
	writeJSON(w, http.StatusOK, out)
}

// storystructure — passo 1 da SALA DE ROTEIRO (S2): destila o tema numa espinha dramática
// (logline, pergunta, atos, viradas) como DADOS editáveis. Texto puro (sem cota de mídia).
func (s *Server) storystructure(w http.ResponseWriter, r *http.Request) {
	var in struct {
		Theme    string           `json:"theme"`
		Lang     string           `json:"lang"`
		Persona  string           `json:"persona"`
		GenLines content.GenLines `json:"gen_lines"`
	}
	if !decode(w, r, &in) {
		return
	}
	out, err := s.svc().GenerateStoryStructure(r.Context(), in.Theme, in.Lang, in.Persona, in.GenLines)
	if err != nil {
		writeErr(w, err)
		return
	}
	writeJSON(w, http.StatusOK, out)
}

// ideas — F1 da Fábrica de Conteúdo: GERADOR DE IDEIAS. nicho → banco de 20-30 ideias de vídeo viral
// (título + gatilho + formato + dificuldade + nota). Texto puro (a cota é gateada no console).
func (s *Server) ideas(w http.ResponseWriter, r *http.Request) {
	var in struct {
		Niche    string           `json:"niche"`
		Mode     string           `json:"mode"`  // ""|sazonal|dor|desbloqueio|maluca
		Month    string           `json:"month"` // usado no modo sazonal
		Lang     string           `json:"lang"`
		Persona  string           `json:"persona"` // roteirista escolhido (aba Prompts); vazio = estrategista padrão
		Count    int              `json:"count"`   // nº de ideias (clamp 1..40 no service; 0 = default 24)
		GenLines content.GenLines `json:"gen_lines"`
	}
	if !decode(w, r, &in) {
		return
	}
	out, err := s.svc().GenerateIdeas(r.Context(), in.Niche, in.Mode, in.Month, in.Lang, in.Persona, in.Count, in.GenLines)
	if err != nil {
		writeErr(w, err)
		return
	}
	writeJSON(w, http.StatusOK, out)
}

// optimize — F4 da Fábrica de Conteúdo: PACOTE DE OTIMIZAÇÃO. tema/título + rede → 5 títulos com nota,
// descrição SEO, hashtags, tags (YouTube) e conceitos de thumbnail. Texto puro (cota no console).
func (s *Server) optimize(w http.ResponseWriter, r *http.Request) {
	var in struct {
		Topic    string           `json:"topic"`
		Platform string           `json:"platform"`
		Lang     string           `json:"lang"`
		Persona  string           `json:"persona"`
		GenLines content.GenLines `json:"gen_lines"`
	}
	if !decode(w, r, &in) {
		return
	}
	out, err := s.svc().GenerateOptimizationPack(r.Context(), in.Topic, in.Platform, in.Lang, in.Persona, in.GenLines)
	if err != nil {
		writeErr(w, err)
		return
	}
	writeJSON(w, http.StatusOK, out)
}

// repurpose — F6 da Fábrica de Conteúdo: TRANSFORMAÇÃO DE FORMATO. conteúdo longo → 5 shorts + carrossel
// + thread + pin. Texto puro (cota no console).
func (s *Server) repurpose(w http.ResponseWriter, r *http.Request) {
	var in struct {
		Source   string           `json:"source"`
		Lang     string           `json:"lang"`
		Persona  string           `json:"persona"`
		GenLines content.GenLines `json:"gen_lines"`
	}
	if !decode(w, r, &in) {
		return
	}
	out, err := s.svc().GenerateRepurpose(r.Context(), in.Source, in.Lang, in.Persona, in.GenLines)
	if err != nil {
		writeErr(w, err)
		return
	}
	writeJSON(w, http.StatusOK, out)
}

// carousel — 🎠 CARROSSEL: tema (ou texto colado) → headline vencedora + arquitetura narrativa de
// N slides + brief de imagem por slide. TEXTO PURO: não gera imagem nenhuma aqui. O console mostra
// o plano pra revisão e só depois dispara o render, que é a parte que custa crédito.
func (s *Server) carousel(w http.ResponseWriter, r *http.Request) {
	var in content.CarouselInput
	if !decode(w, r, &in) {
		return
	}
	out, err := s.svc().GenerateCarouselPlan(r.Context(), in)
	if err != nil {
		writeErr(w, err)
		return
	}
	writeJSON(w, http.StatusOK, out)
}

// visualbrief — 🖼️ lê as imagens de referência da marca com visão e devolve o briefing textual
// (paleta, registro tonal, tipografia, estilo de imagem, assinatura de rodapé). É o bloco que
// manda nos briefs de imagem do carrossel: as refs comandam, o assunto obedece.
func (s *Server) visualbrief(w http.ResponseWriter, r *http.Request) {
	var in struct {
		Refs []string `json:"refs"`
	}
	if !decode(w, r, &in) {
		return
	}
	brief, err := s.svc().GenerateVisualBrief(r.Context(), in.Refs)
	if err != nil {
		writeErr(w, err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]string{"brief": brief})
}

// describeMedia — 👁️ LÊ a mídia que vai ser publicada e devolve a descrição em PT-BR que o
// /v1/text usa como âncora (campo `visual`). Existe porque no upload manual não há pesquisa: sem
// isto o redator escrevia sobre um arquivo que nunca viu.
//
// Contrato: {url, kind?} → {ok, description}. `kind` = "image" | "video"; vazio deduz pela
// extensão. Vídeo é descrito por UM quadro extraído (a descrição já avisa isso ao redator).
func (s *Server) describeMedia(w http.ResponseWriter, r *http.Request) {
	var in struct {
		URL  string `json:"url"`
		Kind string `json:"kind"` // "image" (default) | "video"
	}
	if !decode(w, r, &in) {
		return
	}
	desc, err := s.svc().DescribeMedia(r.Context(), in.URL, in.Kind)
	if err != nil {
		writeErr(w, err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"ok": true, "description": desc})
}

// calendar — F5 da Fábrica de Conteúdo: CALENDÁRIO EDITORIAL + SÉRIES. nicho → plano de 30 dias (mode
// "mes") ou série de N episódios (mode "serie"). Texto puro (cota no console).
func (s *Server) calendar(w http.ResponseWriter, r *http.Request) {
	var in struct {
		Niche    string           `json:"niche"`
		Mode     string           `json:"mode"` // "mes" (default) | "serie"
		Days     int              `json:"days"` // dias do calendário OU nº de episódios da série
		Lang     string           `json:"lang"`
		Persona  string           `json:"persona"`
		GenLines content.GenLines `json:"gen_lines"`
	}
	if !decode(w, r, &in) {
		return
	}
	out, err := s.svc().GenerateCalendar(r.Context(), in.Niche, in.Mode, in.Days, in.Lang, in.Persona, in.GenLines)
	if err != nil {
		writeErr(w, err)
		return
	}
	writeJSON(w, http.StatusOK, out)
}

// storyreview — SCRIPT DOCTOR (S2): critica o roteiro (títulos + voiceover) e devolve notas por
// cena + veredito geral. Texto puro (sem cota de mídia — a cota é gateada no console). Não regenera.
func (s *Server) storyreview(w http.ResponseWriter, r *http.Request) {
	var in struct {
		Theme    string               `json:"theme"`
		Lang     string               `json:"lang"`
		Scenes   []content.StoryScene `json:"scenes"`
		GenLines content.GenLines     `json:"gen_lines"`
	}
	if !decode(w, r, &in) {
		return
	}
	out, err := s.svc().ReviewStory(r.Context(), in.Theme, in.Lang, in.Scenes, in.GenLines)
	if err != nil {
		writeErr(w, err)
		return
	}
	writeJSON(w, http.StatusOK, out)
}

// characterbible — destila a descrição de um personagem reutilizável numa "bíblia" estruturada
// (CHARACTER LOCK em EN + paleta de cores + traços + acessórios + expressões). Texto puro, sem
// cota de mídia (a cota é aplicada no console nas imagens base/turnaround). Consumido pela aba
// Personagens do console (model sheet híbrido). Resposta: o JSON da CharacterBible.
func (s *Server) characterbible(w http.ResponseWriter, r *http.Request) {
	var in struct {
		Name         string           `json:"name"`
		Description  string           `json:"description"`
		Style        string           `json:"style"`
		Lang         string           `json:"lang"`         // "pt-BR" (default) | "en-US" — idioma dos rótulos (lock fica em EN)
		ImageDataURL string           `json:"imageDataUrl"` // opcional: data URL base64 → a IA (vision KIE) descreve a imagem enviada e gera o lock
		GenLines     content.GenLines `json:"gen_lines"`
	}
	if !decode(w, r, &in) {
		return
	}
	out, err := s.svc().GenerateCharacterBible(r.Context(), in.Name, in.Description, in.Style, in.Lang, in.ImageDataURL, in.GenLines)
	if err != nil {
		writeErr(w, err)
		return
	}
	writeJSON(w, http.StatusOK, out)
}

// stylewrap — devolve o prefixo+sufixo que StyledPrompt cola em volta do prompt pro estilo dado.
// Usado pelo console em GET /api/characters/{id}/prompts pra montar o texto EXATO que o modelo
// recebe (sem isso, o prompt copiado pro cliente usar numa ferramenta externa sem API viria
// incompleto — faltaria a direção de estilo/qualidade que normalmente vem do wrapper).
func (s *Server) stylewrap(w http.ResponseWriter, r *http.Request) {
	prefix, suffix := image.StyleParts(r.URL.Query().Get("style"))
	writeJSON(w, http.StatusOK, map[string]string{"prefix": prefix, "suffix": suffix})
}

// anglessheet — prancha de ÂNGULOS (turnaround) do model sheet: tenta o caminho normal (KIE,
// 1 shot por vista) e só troca pro turnaround via ÓRBITA DE CÂMERA (vídeo i2v + extração de
// frames) se o KIE estiver indisponível — ver content.AnglesSheet pro porquê (subject_reference
// de imagem não segue instrução de ângulo grande de forma confiável; vídeo mantém coerência
// espacial ao longo de um movimento contínuo). Substitui o loop de N chamadas /v1/image que o
// console faz pras OUTRAS pranchas — este grupo precisa de contexto do grupo INTEIRO (o clipe de
// órbita é UM só pra todos os 8 ângulos), que uma chamada por shot não permite expressar.
func (s *Server) anglessheet(w http.ResponseWriter, r *http.Request) {
	var in struct {
		Identity     string          `json:"identity"`     // identity lock (SEM enquadramento por shot) — usado no caminho órbita
		BaseImageURL string          `json:"baseImageUrl"` // imagem-âncora do personagem
		Aspect       string          `json:"aspect"`
		Style        string          `json:"style"`
		Provider     string          `json:"provider"` // motor do catálogo (cli-bridge | magnific | minimax)
		Model        string          `json:"model"`
		Shots        []shotPromptDTO `json:"shots"` // os 8 prompts por vista (caminho KIE)
		// ver a nota em NonHumanSubject no /v1/image: pula o fallback subject_reference, que num
		// personagem não-humano devolve outro personagem em vez de falhar.
		NonHumanSubject bool `json:"nonHumanSubject"`
	}
	if !decode(w, r, &in) {
		return
	}
	if in.Aspect == "" {
		in.Aspect = "3:4"
	}
	if in.Style == "" {
		in.Style = "realista"
	}
	shots := make([]content.ShotPrompt, len(in.Shots))
	for i, sh := range in.Shots {
		shots[i] = content.ShotPrompt{Label: sh.Label, Prompt: sh.Prompt}
	}
	urls, mode, err := s.svc().AnglesSheet(r.Context(), in.Identity, in.BaseImageURL, in.Aspect, in.Style, shots, in.Provider, in.Model, in.NonHumanSubject)
	if err != nil {
		writeErr(w, err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"urls": urls, "mode": mode})
}

type shotPromptDTO struct {
	Label  string `json:"label"`
	Prompt string `json:"prompt"`
}

// comfyHealth — luz de status do Estúdio Local na UI (aba Imagem/Config): o ComfyUI está de
// pé, qual checkpoint e quais LoRAs respondem pelos img-local-* e quais existem pra trocar
// (COMFY_CKPT/COMFY_LORA). loras = corrente ativa ("nome:força"); vazia = desligada. Sem
// COMFY_URL (topologia VPS), image.ComfyHealth devolve remoto=false/ok=false — nunca panic.
func (s *Server) comfyHealth(w http.ResponseWriter, r *http.Request) {
	ok, ckpt, disponiveis, loras, lorasDisp, ckptOk, remoto := image.ComfyHealth(r.Context())
	writeJSON(w, http.StatusOK, map[string]any{
		"ok": ok, "checkpoint": ckpt, "checkpoints": disponiveis,
		"loras": loras, "loras_disponiveis": lorasDisp,
		// `ok` = o servidor respondeu. `checkpoint_instalado` = ele tem o modelo que vamos pedir.
		// Servidor de pé com a pasta de modelos vazia é o estado normal de um Colab recém-aberto,
		// e antes disso a luz acendia verde do mesmo jeito.
		"checkpoint_instalado": ckptOk,
		"remoto":               remoto,
	})
}

func (s *Server) image(w http.ResponseWriter, r *http.Request) {
	var in struct {
		Prompt    string   `json:"prompt"`
		Aspect    string   `json:"aspect"`
		Style     string   `json:"style"`
		ImageURL  string   `json:"imageUrl"`  // legado: 1 referência i2i (âncora única — retrocompat)
		ImageURLs []string `json:"imageUrls"` // elenco: até N referências i2i (multi-personagem consistente)
		Provider  string   `json:"provider"`  // seletor de modelo: cli-bridge|magnific|comfy|minimax. Vazio = roteamento legado.
		Model     string   `json:"model"`     // provider_model_id do catálogo (ex.: "higgsfield:gpt_image_2", "mystic")
		// schema-driven Magnific: mesmo papel do `kie`, para o provider "magnific". Cada
		// endpoint da API tem params próprios, então o formato vem do catálogo, não do código.
		Magnific image.MagnificSpec `json:"magnific"`
		// anchorIdentity: geração i2i que deve PRESERVAR o sujeito da referência (keyframe do filme,
		// imagem de cena ancorada no personagem). Cola o IDENTITY LOCK no prompt (anti-drift). NÃO
		// ligar em EDIÇÕES (ajustar/editar imagem) — lá o usuário quer justamente mudar o sujeito.
		AnchorIdentity bool `json:"anchorIdentity"`
		// nonHumanSubject: o sujeito da referência NÃO é humano (animal, criatura, objeto). Só
		// importa pro FALLBACK: o subject_reference da reserva pré-paga é documentado como "apenas
		// rosto humano" e, num bicho, ignora a referência e devolve SUCESSO com outro personagem —
		// foi assim que a folha da Mel (dachshund) veio com humanos nas células. Com isto ligado o
		// fallback ancorado é pulado: célula vazia é um resultado honesto, personagem trocado não.
		NonHumanSubject bool `json:"nonHumanSubject"`
		// spec: FICHA DE CENA (S1) — plano/luz/emoção. Compõe a diretiva de cinematografia na geração
		// (o console passa o spec da cena/beat). Nulo = sem ficha (retrocompatível).
		Spec *content.SceneSpec `json:"spec"`
		// palette: PALETA DE COR do projeto (S3) — direção de arte global aplicada a TODA imagem, pra
		// as N cenas parecerem UM filme (não cenas soltas). Preset ou texto livre. Vazio = sem paleta.
		Palette string `json:"palette"`
		// persona: ESTILO escolhido no console (aba Prompts) — o texto da persona já resolvido, não
		// um slug: quem guarda o catálogo é o console, onde o cliente edita e cria as dele. Vazio =
		// sem persona (retrocompatível).
		Persona string `json:"persona"`
		// refiner: CLI do host que REESCREVE o pedido aplicando a persona antes de gerar
		// (mmx|cursor|agy, via cli-bridge). Vazio = sem refino; a persona é só apensada.
		Refiner string `json:"refiner"`
		// seed: variação CONTROLADA no motor local (comfy) — mesmo seed + mesmo workflow =
		// mesma composição; muda-se o prompt e a pose fica (Fase 2.4 do docs/ESTUDIO-3D.md).
		// 0/ausente = seed novo por chamada (comportamento padrão). Provedores por API ignoram.
		Seed int64 `json:"seed"`
		// maskUrl: área a REDESENHAR no inpaint local (Fase 2.2) — imagem P&B do mesmo
		// enquadramento da ref (pintado=muda, preto=fica). Só o workflow sdxl-inpaint usa.
		MaskURL string `json:"maskUrl"`
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
	// Normaliza as referências: imageUrls (elenco) tem precedência; se vazio, cai no imageUrl legado.
	refs := in.ImageURLs
	if len(refs) == 0 && in.ImageURL != "" {
		refs = []string{in.ImageURL}
	}
	url, err := s.svc().GenerateImage(r.Context(), in.Prompt, in.Aspect, in.Style, refs, in.Provider, in.Model, in.Magnific, in.AnchorIdentity, in.NonHumanSubject, in.Spec, in.Palette, in.Persona, in.Refiner, in.Seed, in.MaskURL)
	if err != nil {
		writeErr(w, err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]string{"url": url})
}

// enhance — PÓS-PROCESSA uma imagem existente (upscale, remover fundo) via KIE. Sem geração do zero:
// só transforma `imageUrl` (imagem da galeria, URL pública do S3). model + kie vêm do catálogo (o
// console resolve o gen_model da operação). ext = extensão de saída ("png" remove fundo, "jpg" upscale).
func (s *Server) enhance(w http.ResponseWriter, r *http.Request) {
	var in struct {
		ImageURL string `json:"imageUrl"` // imagem de origem (URL pública da galeria/S3)
		Model    string `json:"model"`    // provider_model_id do catálogo (ex. "image-upscaler-creative")
		Ext      string `json:"ext"`      // extensão de saída: "png" (remover fundo) | "jpg" (upscale)
		// provider "magnific" → o upscale/remove-bg vai pela API do Magnific (é o produto-núcleo
		// dele). Vazio = caminho de sempre. Spec própria porque os params do endpoint são outros.
		Provider string             `json:"provider"`
		Magnific image.MagnificSpec `json:"magnific"`
		// Params — campos próprios da ferramenta quando ela roda pelo bridge (provider
		// "cli-bridge"): light_source/brightness/light_quality do relight, output_width/height do
		// upscale, e assim por diante. O bridge valida cada um contra o schema que o modelo
		// publica e descarta o que não for aceito.
		Params map[string]string `json:"params"`
	}
	if !decode(w, r, &in) {
		return
	}
	url, err := s.svc().EnhanceImage(r.Context(), in.ImageURL, in.Model, in.Ext, in.Provider, in.Magnific, in.Params)
	if err != nil {
		writeErr(w, err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]string{"url": url})
}

// motionTransfer — MOTION TRANSFER via workflow ComfyUI curado (RunningHub). Schema-driven: o console
// passa o workflowId + o nodeInfoList (nós que recebem o keyframe da cena e o vídeo-guia, com URLs
// públicas do nosso S3). Operação LENTA (minutos) e CARA — o console cobra crédito alto e é assíncrona.
func (s *Server) motionTransfer(w http.ResponseWriter, r *http.Request) {
	var in struct {
		WorkflowID   string            `json:"workflowId"`
		NodeInfoList []motion.NodeInfo `json:"nodeInfoList"`
		InstanceType string            `json:"instanceType"` // "default" (24G) | "plus" (48G)
	}
	if !decode(w, r, &in) {
		return
	}
	url, err := s.svc().MotionTransfer(r.Context(), in.WorkflowID, in.NodeInfoList, in.InstanceType)
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
// "beats" quando há segmentação). Premium (Veo) NÃO entra aqui — continua em /v1/veo.
func (s *Server) video(w http.ResponseWriter, r *http.Request) {
	var in struct {
		Prompt   string `json:"prompt"`
		Style    string `json:"style"`
		ImageURL string `json:"imageUrl"` // opcional: URL pública do nosso S3 → base i2v (1ª âncora)
		// ImageURLs — MULTI-REFERÊNCIA: âncoras extras de identidade (personagem/cenário) além da
		// 1ª. O console manda `imageUrls` no lote de cenas do Roteiro desde sempre e o engine só
		// lia `imageUrl`: da 2ª ref em diante tudo era DESCARTADO em silêncio. Vazio = idêntico ao
		// comportamento histórico (só `imageUrl`). O teto por modelo (refs_max/refs_single do
		// catálogo) é aplicado no provider — modelo de 1 imagem usa a 1ª e NÃO falha.
		ImageURLs []string `json:"imageUrls"`
		Aspect    string   `json:"aspect"`    // opcional: default "9:16"
		Scenes    int      `json:"scenes"`    // opcional: 1=curto (1 clipe), 3..12=longo (clamp 1..12, default 1)
		Duration  string   `json:"duration"`  // opcional: "6"|"10" (default "6") — duração de cada clipe
		Narration bool     `json:"narration"` // opcional: narração TTS por cena (default false)
		VoiceID   string   `json:"voiceId"`   // opcional: voz (usada se narration||subtitles)
		Lang      string   `json:"lang"`      // opcional: "pt-BR"|"en-US" (default pt-BR)
		Subtitles bool     `json:"subtitles"` // opcional: queimar legenda (default false)
		Music     bool     `json:"music"`     // opcional: trilha de fundo (default false)
		// Estilo da legenda queimada (MESMOS campos do /v1/storyvideo) — pra Mídia gerar o vídeo já
		// com a legenda no estilo escolhido. Todos opcionais (zero value = look histórico).
		SubtitlePos         string `json:"subtitlePos"`         // "bottom" | "middle" | "top"
		SubtitleSize        int    `json:"subtitleSize"`        // tamanho da fonte (0 = default 20)
		SubtitleColor       string `json:"subtitleColor"`       // cor do texto (#RRGGBB; vazio = branco)
		SubtitleBorder      int    `json:"subtitleBorder"`      // espessura do contorno (0 = default 3)
		SubtitleBorderColor string `json:"subtitleBorderColor"` // cor do contorno (#RRGGBB; vazio = preto)
		SubtitleFont        string `json:"subtitleFont"`        // fonte: sans|serif|mono|dejavu|noto (vazio = sans)
		SubtitleOpacity     int    `json:"subtitleOpacity"`     // transparência do texto 0..90 (0 = opaco)
		SubtitleBg          bool   `json:"subtitleBg"`          // caixa (fundo) atrás do texto
		SubtitleBgColor     string `json:"subtitleBgColor"`     // cor da caixa (#RRGGBB; vazio = preto)
		SubtitleBgOpacity   int    `json:"subtitleBgOpacity"`   // opacidade da caixa 0..100 (vazio = 60)
		SubtitleAnim        string `json:"subtitleAnim"`        // 🎞️ legenda animada: pop|karaoke|bounce|vox (vazio = queimada)
		SubtitleAccentColor string `json:"subtitleAccentColor"` // realce da palavra ativa (#RRGGBB; só no modo animado)
		Grade               string `json:"grade"`               // Sprint B: color grade (natural|cinema_quente|teal_orange|noir|vintage)
		// GradeStrength — intensidade do filtro 1..99 (0/100 = look cheio), igual /v1/storyvideo e
		// /v1/filmassemble. O card de Vídeo da Mídia já enviava `gradeStrength` e este struct não
		// tinha o campo: o slider de intensidade era DESCARTADO e o look saía sempre cheio.
		GradeStrength int              `json:"gradeStrength"`
		Grain         bool             `json:"grain"`     // Sprint B: film grain/halation sutil
		Persona       string           `json:"persona"`   // estilo escolhido no console (aba Prompts, kind=video), já em texto
		GenLines      content.GenLines `json:"gen_lines"` // principal/reserva por função (video: seedance|kling|hailuo; ausente ⇒ defaults)
		// Preset — DIREÇÃO editorial completa ("vox" = jornalismo explicativo animado). Troca de
		// uma vez segmentação, motor de imagem, linguagem visual, prompt de movimento e acabamento
		// (ver content/vox.go). Vazio = comportamento histórico.
		Preset string `json:"preset"`
		// ✅ Roteiro JÁ APROVADO pelo cliente (saída de /v1/beats). Preenchido ⇒ a segmentação é
		// pulada e a peça sai exatamente sobre estes beats — o que foi lido é o que é gerado.
		Beats []content.Beat `json:"beats"`
		// 📰 Extras do preset Vox (aba /video (estilo Vox)) — texto livre que APENDA nas direções padrão do
		// formato (arte e movimento), nunca as substitui. O tamanho é clampado no content
		// (voxExtra) — validação do engine, independente da do console. Vazios = padrão Vox.
		VoxStyleExtra     string `json:"vox_style_extra"`
		VoxDirectionExtra string `json:"vox_direction_extra"`
	}
	if !decode(w, r, &in) {
		return
	}
	// Modelo de vídeo PRINCIPAL/FALLBACK da gen_lines.video (default seedance→kling).
	vln := in.GenLines.WithDefaults().Video
	out, err := s.svc().GenerateVideoUnified(r.Context(), content.VideoOptions{
		Prompt:    in.Prompt,
		Style:     in.Style,
		ImageURL:  in.ImageURL,
		ImageURLs: in.ImageURLs,
		Aspect:    in.Aspect,
		Scenes:    in.Scenes,
		Duration:  in.Duration,
		Narration: in.Narration,
		VoiceID:   in.VoiceID,
		Lang:      in.Lang,
		Subtitles: in.Subtitles,
		Music:     in.Music,
		Sub: media.SubtitleStyle{
			Pos: in.SubtitlePos, Size: in.SubtitleSize, Color: in.SubtitleColor, Border: in.SubtitleBorder,
			BorderColor: in.SubtitleBorderColor, Font: in.SubtitleFont, Opacity: in.SubtitleOpacity,
			Bg: in.SubtitleBg, BgColor: in.SubtitleBgColor, BgOpacity: in.SubtitleBgOpacity,
			Anim: in.SubtitleAnim, AccentColor: in.SubtitleAccentColor,
		},
		Grade:         in.Grade,
		GradeStrength: in.GradeStrength,
		Grain:         in.Grain,
		Persona:       in.Persona,
		VideoModel:    vln.Primary,
		VideoFallback: vln.Fallback,
		VideoProvider: vln.Provider, // cli-bridge | magnific | minimax; vazio = erro de config no clipe
		VideoMagnific: vln.Magnific, // spec do modelo Magnific (quando VideoProvider=="magnific")
		Preset:        in.Preset,
		Beats:         in.Beats,

		VoxStyleExtra:     in.VoxStyleExtra,
		VoxDirectionExtra: in.VoxDirectionExtra,
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

// gif — gera um GIF animado (clipe curto em loop → conversão mp4→gif no ffmpeg-service). Reusa o
// orquestrador de vídeo; body {prompt, style, imageUrl?, aspect?, gen_lines?}. Resposta {"url"}.
func (s *Server) gif(w http.ResponseWriter, r *http.Request) {
	var in struct {
		Prompt   string           `json:"prompt"`
		Style    string           `json:"style"`
		ImageURL string           `json:"imageUrl"` // opcional: URL pública do nosso S3 → base i2v
		Aspect   string           `json:"aspect"`   // opcional: default "1:1"
		GenLines content.GenLines `json:"gen_lines"`
	}
	if !decode(w, r, &in) {
		return
	}
	url, err := s.svc().GenerateGif(r.Context(), in.Prompt, in.Style, in.ImageURL, in.Aspect, in.GenLines)
	if err != nil {
		writeErr(w, err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]string{"url": url})
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
		GenLines    content.GenLines `json:"gen_lines"`   // principal/reserva por função (video: seedance|kling|hailuo; ausente ⇒ defaults)
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

// saldo — quanto crédito resta na conta do provedor de IA.
//
// Pra luz de status na tela. Sem isto o cliente só descobre que a conta zerou quando a peça sai
// pela metade (caso real 2026-08-04: 3 de 6 cenas, sem motivo visível). `ok:false` = não deu pra
// saber — a tela então não mostra nada, que é melhor que mostrar zero e assustar à toa.
func (s *Server) saldo(w http.ResponseWriter, r *http.Request) {
	c, ok := s.svc().CreditosDoProvedor(r.Context())
	writeJSON(w, http.StatusOK, map[string]any{"ok": ok, "credits": c})
}

// beats — escreve SÓ o roteiro (segmentação em beats), sem gerar imagem nem clipe.
//
// É a metade barata do vídeo multi-cena: uma chamada de texto contra uma imagem + um clipe POR
// CENA. Existe pro cliente LER e corrigir o raciocínio antes de pagar a peça — sem isto, roteiro
// ruim só se descobre assistindo ao resultado final, e aí a peça inteira foi perdida.
//
// A saída volta no MESMO formato que /v1/video aceita em `beats`, então o que foi aprovado é
// exatamente o que é gerado — sem re-segmentar, que entregaria uma peça diferente da aprovada.
func (s *Server) beats(w http.ResponseWriter, r *http.Request) {
	var in struct {
		Prompt   string `json:"prompt"`
		Scenes   int    `json:"scenes"`
		Lang     string `json:"lang"`
		Duration string `json:"duration"`
		Aspect   string `json:"aspect"`
		Preset   string `json:"preset"`
		// 📰 Regras extras de ESTRUTURA do preset Vox (aba /video (estilo Vox)) — apendadas ao prompt de
		// segmentação padrão, nunca no lugar dele. Clampado no content (voxExtra).
		ExtraRules string `json:"extra_rules"`
	}
	if !decode(w, r, &in) {
		return
	}
	out, err := s.svc().SegmentBeats(r.Context(), in.Prompt, in.Prompt, in.Scenes, in.Lang, in.Duration, in.Aspect, in.Preset, in.ExtraRules)
	if err != nil {
		writeErr(w, err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"beats": out})
}

// voxscene — gera UMA cena do preset Vox (imagem em colagem + clipe i2v) pra regeneração beat a
// beat da aba /video (estilo Vox). Body {image_prompt, aspect?, duration?, gen_lines?, vox_style_extra?,
// vox_direction_extra?}. Resposta {"url": <clipe durável no nosso storage>}.
//
// É a fatia de UMA cena do /v1/video com preset=vox — mesmo motor, mesma diretiva, mesmo prompt
// de movimento (ver content.GenerateVoxScene). A montagem final é o /v1/filmassemble de sempre.
func (s *Server) voxscene(w http.ResponseWriter, r *http.Request) {
	var in struct {
		ImagePrompt string           `json:"image_prompt"`
		Aspect      string           `json:"aspect"`   // opcional: default "9:16"
		Duration    string           `json:"duration"` // opcional: "6"|"10" (default "6")
		GenLines    content.GenLines `json:"gen_lines"`
		// Extras da aba /video (estilo Vox) — apendam nas direções padrão do formato (clamp no content).
		VoxStyleExtra     string `json:"vox_style_extra"`
		VoxDirectionExtra string `json:"vox_direction_extra"`
	}
	if !decode(w, r, &in) {
		return
	}
	vln := in.GenLines.WithDefaults().Video
	url, err := s.svc().GenerateVoxScene(r.Context(), in.ImagePrompt, content.VideoOptions{
		Aspect:   in.Aspect,
		Duration: in.Duration,
		Preset:   content.VoxPreset,

		VideoModel:    vln.Primary,
		VideoFallback: vln.Fallback,
		VideoProvider: vln.Provider,
		VideoMagnific: vln.Magnific,

		VoxStyleExtra:     in.VoxStyleExtra,
		VoxDirectionExtra: in.VoxDirectionExtra,
	})
	if err != nil {
		writeErr(w, err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]string{"url": url})
}

// music — gera UMA faixa (MiniMax) e persiste no S3. Body {prompt, lyrics?, instrumental?, model?}.
// Resposta {"url": <mp3 durável>}. instrumental=true → sem vocais; lyrics vazio = letra auto do prompt.
func (s *Server) music(w http.ResponseWriter, r *http.Request) {
	var in struct {
		Prompt       string `json:"prompt"`
		Lyrics       string `json:"lyrics"`
		Instrumental bool   `json:"instrumental"`
		Model        string `json:"model"` // vazio = music-2.6-free (grátis)
	}
	if !decode(w, r, &in) {
		return
	}
	url, err := s.svc().GenerateMusic(r.Context(), in.Model, in.Prompt, in.Lyrics, in.Instrumental)
	if err != nil {
		writeErr(w, err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]string{"url": url})
}

// storyvideo — monta um Short a partir das cenas JÁ geradas (imagem + voiceover): slideshow
// com narração + legenda + (opcional) música. NÃO gera vídeo por IA (reusa as imagens prontas).
// Resposta {"url": <mp4 durável>}.
func (s *Server) storyvideo(w http.ResponseWriter, r *http.Request) {
	var in struct {
		Beats               []content.StoryBeat `json:"beats"`
		VoiceID             string              `json:"voiceId"`
		Lang                string              `json:"lang"`                // "pt-BR"|"en-US"
		TTSModel            string              `json:"ttsModel"`            // modelo de síntese da narração (catálogo; vazio = default)
		TTSFormat           string              `json:"ttsFormat"`           // formato/bitrate do MP3 da narração (vazio = default)
		TTSStyle            string              `json:"ttsStyle"`            // preset de entrega da voz (vazio = neutro)
		MusicPrompt         string              `json:"musicPrompt"`         // estilo da trilha (vazio = default histórico)
		AudioDelay          float64             `json:"audioDelay"`          // atraso (s) da narração dentro de cada cena (0..2)
		SubtitleOffset      float64             `json:"subtitleOffset"`      // deslocamento (s) da legenda relativo ao áudio (-1..+1)
		Music               bool                `json:"music"`               // trilha de fundo instrumental (opcional)
		SungNarration       bool                `json:"sungNarration"`       // a voz CANTA o roteiro (letra = script), via Eleven Music
		Aspect              string              `json:"aspect"`              // "9:16" (default) | "16:9"
		SubtitlePos         string              `json:"subtitlePos"`         // posição da legenda: "bottom" | "middle" | "top"
		SubtitleSize        int                 `json:"subtitleSize"`        // tamanho da fonte da legenda (0 = default 20)
		SubtitleColor       string              `json:"subtitleColor"`       // cor do texto (#RRGGBB; vazio = branco)
		SubtitleBorder      int                 `json:"subtitleBorder"`      // espessura do contorno (0 = default 3)
		SubtitleBorderColor string              `json:"subtitleBorderColor"` // cor do contorno (#RRGGBB; vazio = preto)
		SubtitleFont        string              `json:"subtitleFont"`        // fonte: sans|serif|mono|dejavu|noto (vazio = sans)
		SubtitleOpacity     int                 `json:"subtitleOpacity"`     // transparência do texto 0..90 (0 = opaco)
		SubtitleBg          bool                `json:"subtitleBg"`          // caixa (fundo) atrás do texto
		SubtitleBgColor     string              `json:"subtitleBgColor"`     // cor da caixa (#RRGGBB; vazio = preto)
		SubtitleBgOpacity   int                 `json:"subtitleBgOpacity"`   // opacidade da caixa 0..100 (vazio = 60)
		SubtitleAnim        string              `json:"subtitleAnim"`        // 🎞️ legenda animada: pop|karaoke|bounce|vox (vazio = queimada)
		SubtitleAccentColor string              `json:"subtitleAccentColor"` // realce da palavra ativa (#RRGGBB; só no modo animado)
		Grade               string              `json:"grade"`               // Sprint B: color grade (natural|cinema_quente|teal_orange|noir|vintage|dourado|...)
		GradeStrength       int                 `json:"gradeStrength"`       // F2: intensidade do filtro 1..99 (0/100 = look cheio)
		Grain               bool                `json:"grain"`               // Sprint B: film grain/halation sutil
		AmbiencePrompt      string              `json:"ambiencePrompt"`      // F2: som-ambiente sob tudo (vazio = sem)
		EndcardURL          string              `json:"endcardUrl"`          // F2: cartela final ~2s (imagem do nosso storage)
		ColorMatch          bool                `json:"colorMatch"`          // F2: casa a exposição das cenas com a 1ª
		Smooth              bool                `json:"smooth"`              // F2: interpolação de movimento 2× (best-effort)
		TransitionDefault   string              `json:"transitionDefault"`   // 🎬 F1: transição default entre cenas ("" = corte)
		TransitionDur       float64             `json:"transitionDur"`       // duração (s) da transição (0 = 0.5)
		// Flags NEGATIVAS (ausentes = false = comportamento histórico: narração e legenda ligadas).
		// Usadas pelo carrossel-vídeo: o texto já está desenhado no slide, então legenda queimada
		// duplicaria a frase; e o slideshow mudo é o modo que não gasta nada além de CPU.
		Muted       bool `json:"muted"`
		NoSubtitles bool `json:"noSubtitles"`
		// Atraso de FPS (assinatura do documentário explicativo animado): 0 = desligado.
		FPSDelay int `json:"fpsDelay"`
	}
	if !decode(w, r, &in) {
		return
	}
	url, err := s.svc().GenerateStoryVideo(r.Context(), in.Beats, in.VoiceID, in.Lang, media.NarrationOpts{
		Model: in.TTSModel, Format: in.TTSFormat, Style: in.TTSStyle,
		Delay: in.AudioDelay, SubOffset: in.SubtitleOffset,
		Muted: in.Muted, NoSubtitles: in.NoSubtitles,
	}, in.Music, in.MusicPrompt, in.SungNarration, in.Aspect, media.SubtitleStyle{
		Pos: in.SubtitlePos, Size: in.SubtitleSize, Color: in.SubtitleColor, Border: in.SubtitleBorder,
		BorderColor: in.SubtitleBorderColor, Font: in.SubtitleFont, Opacity: in.SubtitleOpacity,
		Bg: in.SubtitleBg, BgColor: in.SubtitleBgColor, BgOpacity: in.SubtitleBgOpacity,
		Anim: in.SubtitleAnim, AccentColor: in.SubtitleAccentColor,
	}, media.FinishOpts{
		Grade: in.Grade, GradeStrength: in.GradeStrength, Grain: in.Grain,
		AmbiencePrompt: in.AmbiencePrompt, EndcardURL: in.EndcardURL,
		ColorMatch: in.ColorMatch, Smooth: in.Smooth, FPSDelay: in.FPSDelay,
	}, in.TransitionDefault, in.TransitionDur)
	if err != nil {
		writeErr(w, err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]string{"url": url})
}

// filmplan — plano de filmagem do FILME CONTÍNUO: N beats {frame_prompt, move_prompt} +
// keyframe final, com contrato de continuidade (um take só). Resposta = FilmPlanResult.
func (s *Server) filmplan(w http.ResponseWriter, r *http.Request) {
	var in struct {
		Brief      string           `json:"brief"`
		Style      string           `json:"style"`
		MasterDesc string           `json:"masterDesc"` // protagonista fixo (produto/persona/imóvel) — opcional
		Persona    string           `json:"persona"`    // 🎥 Diretor escolhido (craft; vazio = cinematógrafo padrão)
		Lang       string           `json:"lang"`
		ClipDur    string           `json:"clipDuration"` // duração de cada trecho ("5"|"10") — informativo pro plano
		Beats      int              `json:"beats"`
		GenLines   content.GenLines `json:"gen_lines"` // line de texto (modelo do seletor; ausente ⇒ default)
	}
	if !decode(w, r, &in) {
		return
	}
	out, err := s.svc().GenerateFilmPlan(r.Context(), in.Brief, in.Style, in.MasterDesc, in.Persona, in.Lang, in.ClipDur, in.Beats, in.GenLines)
	if err != nil {
		writeErr(w, err)
		return
	}
	writeJSON(w, http.StatusOK, out)
}

// filmsection — regenera UMA seção do plano do filme (roteiro|storyboard|narracao|camera|musica)
// mantendo o resto intacto (o plano atual inteiro vai como contexto). Resposta = FilmSectionResult.
func (s *Server) filmsection(w http.ResponseWriter, r *http.Request) {
	var in struct {
		Brief      string             `json:"brief"`
		Style      string             `json:"style"`
		Lang       string             `json:"lang"`
		ClipDur    string             `json:"clipDuration"`
		Section    string             `json:"section"`
		Beats      []content.FilmBeat `json:"beats"`
		FinalFrame string             `json:"finalFramePrompt"`
		GenLines   content.GenLines   `json:"gen_lines"`
	}
	if !decode(w, r, &in) {
		return
	}
	out, err := s.svc().GenerateFilmSection(r.Context(), in.Brief, in.Style, in.Lang, in.ClipDur, in.Section, in.Beats, in.FinalFrame, in.GenLines)
	if err != nil {
		writeErr(w, err)
		return
	}
	writeJSON(w, http.StatusOK, out)
}

// filmclip — UM trecho do filme: i2v com primeiro E último frame (keyframes compartilhados).
// gen_lines.video define o modelo KIE (schema-driven). Resposta {"url": <mp4 durável>}.
func (s *Server) filmclip(w http.ResponseWriter, r *http.Request) {
	var in struct {
		Prompt      string           `json:"prompt"`      // move_prompt do trecho
		ImageURL    string           `json:"imageUrl"`    // keyframe inicial (obrigatório)
		EndImageURL string           `json:"endImageUrl"` // keyframe final (recomendado; vazio = i2v normal)
		Duration    string           `json:"duration"`
		Aspect      string           `json:"aspect"`
		GenLines    content.GenLines `json:"gen_lines"` // .Video: provider kie + model + spec (do catálogo)
	}
	if !decode(w, r, &in) {
		return
	}
	v := in.GenLines.Video
	url, err := s.svc().GenerateFilmClip(r.Context(), in.Prompt, in.ImageURL, in.EndImageURL, in.Duration, in.Aspect, v.Provider, v.Primary, v.Fallback, v.Magnific)
	if err != nil {
		writeErr(w, err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]string{"url": url})
}

// lipsync — 🎬 LIP SYNC de uma cena/fala: talking-head (imagem-retrato + áudio da fala → clipe
// com a boca sincronizada). gen_lines.video define o modelo KIE de lip sync (schema-driven, com
// audio_field no spec). Usado no Estúdio de Animação no lugar do i2v mudo + mux nas cenas com
// diálogo. Resposta {"url": <mp4 durável>}.
func (s *Server) lipsync(w http.ResponseWriter, r *http.Request) {
	var in struct {
		ImageURL string           `json:"imageUrl"`  // retrato/keyframe do personagem que fala (obrigatório)
		AudioURL string           `json:"audioUrl"`  // a fala já em TTS (obrigatório)
		GenLines content.GenLines `json:"gen_lines"` // .Video: provider kie + model de lip sync + spec (audio_field)
	}
	if !decode(w, r, &in) {
		return
	}
	v := in.GenLines.Video
	url, err := s.svc().GenerateLipSyncClip(r.Context(), in.ImageURL, in.AudioURL, v.Primary, v.Provider, v.Magnific)
	if err != nil {
		writeErr(w, err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]string{"url": url})
}

// filmquick — ⚡ FILME RÁPIDO (Sprint D): o filme inteiro numa ÚNICA geração Kling multi_shots
// (cada shot = o move_prompt de um beat; imageUrl = keyframe de abertura). Resposta {"url"}.
func (s *Server) filmquick(w http.ResponseWriter, r *http.Request) {
	var in struct {
		Shots        []string         `json:"shots"`
		ImageURL     string           `json:"imageUrl"`
		TotalSeconds int              `json:"totalSeconds"` // duração TOTAL desejada (3-15) — o engine distribui por corte
		Aspect       string           `json:"aspect"`
		GenLines     content.GenLines `json:"gen_lines"` // .Video: provider kie + model + spec (multi_prompt_field do catálogo)
	}
	if !decode(w, r, &in) {
		return
	}
	v := in.GenLines.Video
	url, err := s.svc().GenerateFilmQuick(r.Context(), in.Shots, in.ImageURL, in.TotalSeconds, in.Aspect, v.Primary)
	if err != nil {
		writeErr(w, err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]string{"url": url})
}

// filmassemble — monta o filme: concat dos clipes na ordem + trilha musical e/ou narração
// contínua com legenda (F2). Resposta {"url": <mp4 durável>}.
func (s *Server) filmassemble(w http.ResponseWriter, r *http.Request) {
	var in struct {
		ClipURLs            []string `json:"clipUrls"`
		Music               bool     `json:"music"`
		MusicPrompt         string   `json:"musicPrompt"`
		Aspect              string   `json:"aspect"`
		Narration           bool     `json:"narration"` // locução contínua por cima do filme montado
		Script              string   `json:"script"`    // roteiro inteiro (voiceovers em sequência)
		Scripts             []string `json:"scripts"`   // locução POR CENA (1 por clipe): o console manda desde 2026-07-26, o struct não tinha o campo e ele era DESCARTADO — a narração virava script corrido no segundo 0. Vazio = usa Script.
		VoiceID             string   `json:"voiceId"`
		TTSModel            string   `json:"ttsModel"`
		TTSFormat           string   `json:"ttsFormat"`
		TTSStyle            string   `json:"ttsStyle"`
		AudioDelay          float64  `json:"audioDelay"`     // atraso (s) da locução no início do filme
		SubtitleOffset      float64  `json:"subtitleOffset"` // deslocamento (s) da legenda vs áudio
		Subtitles           bool     `json:"subtitles"`      // legenda word-level (só com narração)
		SubtitlePos         string   `json:"subtitlePos"`    // estilo COMPLETO da legenda (igual Histórias)
		SubtitleSize        int      `json:"subtitleSize"`
		SubtitleColor       string   `json:"subtitleColor"`
		SubtitleBorder      int      `json:"subtitleBorder"`
		SubtitleBorderColor string   `json:"subtitleBorderColor"`
		SubtitleFont        string   `json:"subtitleFont"`
		SubtitleOpacity     int      `json:"subtitleOpacity"`
		SubtitleBg          bool     `json:"subtitleBg"`
		SubtitleBgColor     string   `json:"subtitleBgColor"`
		SubtitleBgOpacity   int      `json:"subtitleBgOpacity"`
		// 🎞️ Legenda ANIMADA no FILME: o console manda estes 2 campos desde sempre (o mesmo
		// StudioController::subtitleStyleFrom das Histórias/Mídia) e este struct não os tinha —
		// escolher pop/karaoke/bounce no Filme era decorativo: caía na legenda queimada de sempre.
		SubtitleAnim        string   `json:"subtitleAnim"`        // pop|karaoke|bounce|vox (vazio = queimada)
		SubtitleAccentColor string   `json:"subtitleAccentColor"` // realce da palavra ativa (#RRGGBB; só no modo animado)
		Grade               string   `json:"grade"`               // Sprint B: color grade (natural|cinema_quente|teal_orange|noir|vintage)
		Grain               bool     `json:"grain"`               // Sprint B: film grain/halation sutil
		Letterbox           bool     `json:"letterbox"`           // Sprint B: barras cinemascope 2.39:1 (só 16:9)
		EndcardURL          string   `json:"endcardUrl"`          // Sprint B: imagem (logo+CTA) do nosso storage — cartela final
		AmbiencePrompt      string   `json:"ambiencePrompt"`      // Sprint D: SFX/ambiente sob a trilha (vazio = sem efeito)
		TransitionDefault   string   `json:"transitionDefault"`   // 🎬 F1: transição default entre trechos ("" = corte)
		TransitionDur       float64  `json:"transitionDur"`       // duração (s) da transição (0 = 0.5)
		TransitionCuts      []string `json:"transitionCuts"`      // override por corte (len = trechos-1)
		ColorMatch          bool     `json:"colorMatch"`          // S3: casa a exposição entre trechos (anti-drift de cor)
		PadFit              bool     `json:"padFit"`              // lip-sync per-fala: encaixa (pad) em vez de cortar o rosto
		Smooth              bool     `json:"smooth"`              // 🌊 fluidez: interpolação de movimento no vídeo final
		GradeStrength       int      `json:"gradeStrength"`       // F2: intensidade do filtro 1..99 (0/100 = look cheio)
		Vfx                 []string `json:"vfx"`                 // F4: efeito visual por trecho (alinhado aos clipes)
		Sfx                 []string `json:"sfx"`                 // F4: efeito sonoro por trecho (prompt curto)
		OverlayURLs         []string `json:"overlayUrls"`         // F4: overlay de partículas por trecho (nosso S3)
	}
	if !decode(w, r, &in) {
		return
	}
	url, err := s.svc().AssembleFilm(r.Context(), in.ClipURLs, media.FilmMixOpts{
		Music: in.Music, MusicPrompt: in.MusicPrompt, Aspect: in.Aspect,
		Narration: in.Narration, Script: in.Script, Scripts: in.Scripts, VoiceID: in.VoiceID,
		Nar: media.NarrationOpts{
			Model: in.TTSModel, Format: in.TTSFormat, Style: in.TTSStyle,
			Delay: in.AudioDelay, SubOffset: in.SubtitleOffset,
		},
		Subtitles: in.Subtitles,
		Sub: media.SubtitleStyle{
			Pos: in.SubtitlePos, Size: in.SubtitleSize, Color: in.SubtitleColor, Border: in.SubtitleBorder,
			BorderColor: in.SubtitleBorderColor, Font: in.SubtitleFont, Opacity: in.SubtitleOpacity,
			Bg: in.SubtitleBg, BgColor: in.SubtitleBgColor, BgOpacity: in.SubtitleBgOpacity,
			Anim: in.SubtitleAnim, AccentColor: in.SubtitleAccentColor,
		},
		Grade: in.Grade, Grain: in.Grain, Letterbox: in.Letterbox, EndcardURL: in.EndcardURL,
		AmbiencePrompt: in.AmbiencePrompt, ColorMatch: in.ColorMatch, PadFit: in.PadFit, Smooth: in.Smooth,
		Transitions:   media.TransitionOpts{Default: in.TransitionDefault, Dur: in.TransitionDur, Cuts: in.TransitionCuts},
		GradeStrength: in.GradeStrength, Vfx: in.Vfx, Sfx: in.Sfx, OverlayURLs: in.OverlayURLs,
	})
	if err != nil {
		writeErr(w, err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]string{"url": url})
}

// imagefilter — F2: aplica um filtro Instagram (color grade + intensidade) numa FOTO já gerada.
// Determinístico (ffmpeg), sem custo de API. Resposta {"url": <jpg durável>}.
func (s *Server) imagefilter(w http.ResponseWriter, r *http.Request) {
	var in struct {
		ImageURL string `json:"imageUrl"`
		Grade    string `json:"grade"`
		Strength int    `json:"strength"` // 1..99 = blend com o original (0/100 = look cheio)
	}
	if !decode(w, r, &in) {
		return
	}
	url, err := s.svc().FilterImage(r.Context(), in.ImageURL, in.Grade, in.Strength)
	if err != nil {
		writeErr(w, err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]string{"url": url})
}

// lastframe — último frame REAL de um clipe (JPG durável) — re-âncora de keyframe do filme.
func (s *Server) lastframe(w http.ResponseWriter, r *http.Request) {
	var in struct {
		VideoURL string `json:"videoUrl"`
	}
	if !decode(w, r, &in) {
		return
	}
	url, err := s.svc().VideoLastFrame(r.Context(), in.VideoURL)
	if err != nil {
		writeErr(w, err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]string{"url": url})
}

// tts — preview de narração de uma cena (áudio puro). Resposta {"url": <mp3 durável>}.
func (s *Server) tts(w http.ResponseWriter, r *http.Request) {
	var in struct {
		Text    string `json:"text"`
		VoiceID string `json:"voiceId"`
		Lang    string `json:"lang"`
		Model   string `json:"ttsModel"`  // modelo de síntese (catálogo; vazio = default do serviço)
		Format  string `json:"ttsFormat"` // formato/bitrate do MP3 (ex. mp3_44100_192; vazio = default)
		Style   string `json:"ttsStyle"`  // preset de entrega da voz (dramatico|calmo|energetico; vazio = neutro)
		// ⏱️ true = devolve também o alinhamento por palavra ({words:[{word,start,end}], duration})
		// — o mesmo relógio da legenda word-level da montagem, pra sincronização externa.
		Timestamps bool `json:"timestamps"`
	}
	if !decode(w, r, &in) {
		return
	}
	if in.Timestamps {
		url, words, err := s.svc().SynthesizeSpeechWords(r.Context(), in.Text, in.VoiceID, in.Lang, in.Model, in.Format, in.Style)
		if err != nil {
			writeErr(w, err)
			return
		}
		dur := 0.0
		if len(words) > 0 {
			dur = words[len(words)-1].End
		}
		writeJSON(w, http.StatusOK, map[string]any{"url": url, "words": words, "duration": dur})
		return
	}
	url, err := s.svc().SynthesizeSpeech(r.Context(), in.Text, in.VoiceID, in.Lang, in.Model, in.Format, in.Style)
	if err != nil {
		writeErr(w, err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]string{"url": url})
}

// scriptparse — 🎬 Estúdio de Animação, passo 1: roteiro livre (ou ideia) → projeto estruturado
// (elementos com visual_prompt + cenas com diálogo e Ficha de Cena). Texto puro: sem rate limit
// de mídia (mesma classe do /v1/story).
func (s *Server) scriptparse(w http.ResponseWriter, r *http.Request) {
	var in struct {
		Script    string           `json:"script"`
		Style     string           `json:"style"`     // orientação visual dos prompts (ex "3D fofo infantil")
		Lang      string           `json:"lang"`      // idioma da ação/título: "pt-BR"|"en-US" (default pt-BR)
		MaxScenes int              `json:"maxScenes"` // teto do storyboard (clamp 1..20; 0 = 6)
		Narration bool             `json:"narration"` // modos Histórias/Quadrinhos: cenas ganham "narration" (voz única)
		Persona   string           `json:"persona"`   // direção de roteiro (Voz da Marca + roteirista) — mesma semântica do /v1/story
		GenLines  content.GenLines `json:"gen_lines"`
	}
	if !decode(w, r, &in) {
		return
	}
	out, err := s.svc().ParseScript(r.Context(), in.Script, in.Style, in.Lang, in.MaxScenes, in.Narration, in.Persona, in.GenLines)
	if err != nil {
		writeErr(w, err)
		return
	}
	writeJSON(w, http.StatusOK, out)
}

// dialogueaudio — 🎬 diálogo multi-voz de UMA cena: 1 TTS por fala (voz do personagem) + respiro
// entre falas → MP3 durável + duração (dimensiona o clipe i2v da cena).
func (s *Server) dialogueaudio(w http.ResponseWriter, r *http.Request) {
	var in struct {
		Lines     []media.SpeechLine `json:"lines"`
		TTSModel  string             `json:"ttsModel"`
		TTSFormat string             `json:"ttsFormat"`
	}
	if !decode(w, r, &in) {
		return
	}
	url, dur, err := s.svc().GenerateDialogueAudio(r.Context(), in.Lines, in.TTSModel, in.TTSFormat)
	if err != nil {
		writeErr(w, err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"url": url, "duration": dur})
}

// muxaudio — 🎬 casa o áudio do diálogo com o clipe i2v (mudo) da cena. Saída = max(vídeo, áudio).
func (s *Server) muxaudio(w http.ResponseWriter, r *http.Request) {
	var in struct {
		VideoURL string `json:"videoUrl"`
		AudioURL string `json:"audioUrl"`
	}
	if !decode(w, r, &in) {
		return
	}
	if in.VideoURL == "" || in.AudioURL == "" {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": "videoUrl e audioUrl são obrigatórios"})
		return
	}
	url, err := s.svc().MuxSceneAudio(r.Context(), in.VideoURL, in.AudioURL)
	if err != nil {
		writeErr(w, err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]string{"url": url})
}

func (s *Server) veo(w http.ResponseWriter, r *http.Request) {
	var in struct {
		Keyword   string `json:"keyword"`
		Brief     string `json:"brief"`
		Style     string `json:"style"`
		Lang      string `json:"lang"`      // opcional: idioma da fala "pt-BR"|"en-US" (default pt-BR)
		Narration bool   `json:"narration"` // true = Veo MUDO + narração própria (voz do tenant) + legenda
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
		url, err = s.svc().GenerateVeoNarrated(r.Context(), in.Keyword, in.Brief, in.Style, in.Lang, in.VoiceID)
	} else {
		url, err = s.svc().GenerateVeoShort(r.Context(), in.Keyword, in.Brief, in.Style, in.Lang)
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

// writeErr — falha de geração. O erro REAL (que cita providers internos: fal, Veo,
// elevenlabs, MiniMax/M3, Jina etc.) é LOGADO server-side, mas o cliente recebe só uma
// mensagem GENÉRICA — white-label (AUD-013): nunca vazar nome de provedor de IA no público.
//
// O STATUS carrega a semântica que o cliente precisa para decidir se retenta (2026-07-22): até
// então TUDO virava 502, e o console — que trata 5xx como transitório — martelava 3× erros que
// jamais iam passar (modelo incompatível, conta sem saldo). Agora só o que é de fato transitório
// devolve 5xx; o resto vira 4xx e o job desiste na primeira, estornando a cota do cliente.
func writeErr(w http.ResponseWriter, err error) {
	log.Printf("erro de geração: %v", err)
	switch gerr.KindOf(err) {
	case gerr.Config:
		// A mensagem de Config é escrita PARA o cliente (não cita provedor) e diz o que fazer.
		writeJSON(w, http.StatusUnprocessableEntity, map[string]string{"error": err.Error()})
	case gerr.Quota:
		writeJSON(w, http.StatusPaymentRequired, map[string]string{"error": "o provedor de mídia está sem saldo ou cota — recarregue para continuar gerando"})
	default:
		writeJSON(w, http.StatusBadGateway, map[string]string{"error": "a IA está indisponível no momento, tente novamente"})
	}
}
