// Rotas /v1/sprite/* — sprites de jogo via motor hospedado (provider/sprite).
// O engine é um proxy autenticado + persistência: repassa o JSON do serviço como veio
// (o contrato é do provedor; o web lê o shape original via console) e, no persist,
// baixa os artefatos úteis pro NOSSO storage (Scality via ffmpeg-service) — a mídia
// durável nunca fica só em URL remota de terceiro.
package api

import (
	"context"
	"encoding/json"
	"io"
	"net/http"
	"os"
	"regexp"
	"strconv"
	"strings"

	"github.com/redfoxcode/reachyn/engine/internal/genkeys"
	"github.com/redfoxcode/reachyn/engine/internal/media"
	"github.com/redfoxcode/reachyn/engine/internal/provider/sprite"
)

// spriteJobID — ids do serviço são slugs curtos; qualquer outra coisa é lixo/injeção
// (o id entra em path de URL remota — allowlist, nunca concatenar cru).
var spriteJobID = regexp.MustCompile(`^[A-Za-z0-9_-]{1,64}$`)

// spriteEnqueueFields — allowlist do payload de enqueue (defesa em profundidade: o console
// também filtra). Campo fora da lista é DESCARTADO, não erro — payload novo do serviço não
// quebra a integração, só não passa até alguém revisar o contrato.
var spriteEnqueueFields = map[string]bool{
	"type": true, "characterName": true, "sourcePrompt": true, "sourceImageUrl": true,
	"referenceJobId": true, "editPrompt": true, "direction": true, "gameView": true,
	"actions": true, "actionBaselines": true, "candidatePromptPreset": true,
	"pixelSnapAnchor": true, "pixelSnap": true, "seed": true, "actionContext": true,
	"chroma": true, "kColors": true, "imageModelAlias": true, "videoModelAlias": true,
}

// refreshSprite reconstrói o client com a chave atual (genkeys do console > .env).
// Chamado no boot e a cada push de chaves (mesmo swap atômico do content.Service).
func (s *Server) refreshSprite(set genkeys.Set) {
	key := genkeys.Or(set.Spriterrific, os.Getenv("SPRITERRIFIC_API_KEY"))
	s.spriteCl.Store(sprite.New(key, os.Getenv("SPRITERRIFIC_API_BASE")))
}

// spriteMediaClient — client do ffmpeg-service pra persistir artefatos (mesmos envs do main).
func spriteMediaClient() *media.Client {
	base := os.Getenv("MEDIA_FFMPEG_URL")
	if base == "" {
		base = "http://ffmpeg-service:7788"
	}
	return media.New(base, os.Getenv("FFMPEG_SERVICE_TOKEN"))
}

// spriteReady devolve o client habilitado ou responde 422 (chave não configurada).
func (s *Server) spriteReady(w http.ResponseWriter) *sprite.Client {
	c := s.spriteCl.Load()
	if c == nil || !c.Enabled() {
		writeJSON(w, http.StatusUnprocessableEntity, map[string]string{
			"error": "Chave do motor de sprites não configurada — salve em Chaves de geração.",
		})
		return nil
	}
	return c
}

// relaySprite repassa status + corpo cru do serviço. Corpo não-JSON (ex.: HTML de erro de
// borda) vira 502 limpo em vez de vazar pro browser.
func relaySprite(w http.ResponseWriter, status int, body []byte, err error) {
	if err != nil {
		writeJSON(w, http.StatusBadGateway, map[string]string{"error": "motor de sprites indisponível: " + err.Error()})
		return
	}
	if !json.Valid(body) {
		writeJSON(w, http.StatusBadGateway, map[string]string{"error": "resposta inválida do motor de sprites"})
		return
	}
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	w.Write(body)
}

// GET /v1/sprite/me — saldo de créditos do serviço (o web mostra antes de gastar).
func (s *Server) spriteMe(w http.ResponseWriter, r *http.Request) {
	c := s.spriteReady(w)
	if c == nil {
		return
	}
	st, body, err := c.Me(r.Context())
	relaySprite(w, st, body, err)
}

// POST /v1/sprite/jobs — enfileira um job (character | variante | action) no serviço.
func (s *Server) spriteEnqueue(w http.ResponseWriter, r *http.Request) {
	c := s.spriteReady(w)
	if c == nil {
		return
	}
	raw, err := io.ReadAll(http.MaxBytesReader(w, r.Body, maxBodyBytes))
	if err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": "corpo inválido"})
		return
	}
	var in map[string]any
	if json.Unmarshal(raw, &in) != nil {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": "JSON inválido"})
		return
	}
	out := map[string]any{}
	for k, v := range in {
		if spriteEnqueueFields[k] {
			out[k] = v
		}
	}
	payload, err := json.Marshal(out)
	if err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": "payload inválido"})
		return
	}
	st, respBody, err2 := c.Enqueue(r.Context(), payload)
	relaySprite(w, st, respBody, err2)
}

// GET /v1/sprite/jobs — lista os jobs da chave (mais novos primeiro).
func (s *Server) spriteList(w http.ResponseWriter, r *http.Request) {
	c := s.spriteReady(w)
	if c == nil {
		return
	}
	limit, _ := strconv.Atoi(r.URL.Query().Get("limit"))
	st, body, err := c.Jobs(r.Context(), limit)
	relaySprite(w, st, body, err)
}

// GET /v1/sprite/jobs/{jobId} — um job: status, steps, artifacts (envelope {"job": ...}).
func (s *Server) spriteJob(w http.ResponseWriter, r *http.Request) {
	c := s.spriteReady(w)
	if c == nil {
		return
	}
	id := r.PathValue("jobId")
	if !spriteJobID.MatchString(id) {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": "id inválido"})
		return
	}
	st, body, err := c.Job(r.Context(), id)
	relaySprite(w, st, body, err)
}

// POST /v1/sprite/jobs/{jobId}/persist — baixa os artefatos úteis do job (âncora,
// spritesheet, preview, manifest) pro Scality e devolve {files: nome→URL nossa}.
// Persist é gracioso: artefato que falhar mantém a URL remota (melhor mostrar do que sumir).
func (s *Server) spritePersist(w http.ResponseWriter, r *http.Request) {
	c := s.spriteReady(w)
	if c == nil {
		return
	}
	id := r.PathValue("jobId")
	if !spriteJobID.MatchString(id) {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": "id inválido"})
		return
	}
	status, body, err := c.Job(r.Context(), id)
	if err != nil || status != http.StatusOK {
		relaySprite(w, status, body, err)
		return
	}
	var env struct {
		Job struct {
			Status    string `json:"status"`
			Artifacts []struct {
				Name string `json:"name"`
				URL  string `json:"url"`
			} `json:"artifacts"`
		} `json:"job"`
	}
	if json.Unmarshal(body, &env) != nil {
		writeJSON(w, http.StatusBadGateway, map[string]string{"error": "resposta inválida do motor de sprites"})
		return
	}
	if env.Job.Status != "completed" && env.Job.Status != "partial" {
		writeJSON(w, http.StatusConflict, map[string]string{"error": "job ainda não terminou", "status": env.Job.Status})
		return
	}
	files := map[string]string{}
	for _, a := range env.Job.Artifacts {
		ext, ok := spriteArtifactExt(a.Name)
		if !ok || a.URL == "" {
			continue // extras volumosos (raw-video, contact, run-index) ficam no serviço
		}
		files[a.Name] = s.spriteMedia.PersistWithHeaders(r.Context(), a.URL, "sprite", ext,
			// R2 público recusa User-Agent de biblioteca (403); um UA de browser passa.
			map[string]string{"User-Agent": "Mozilla/5.0 (Macintosh) AppleWebKit/537.36"})
	}
	writeJSON(w, http.StatusOK, map[string]any{"ok": true, "status": env.Job.Status, "files": files})
}

// spriteArtifactExt — quais artefatos valem persistir e com qual extensão.
// Nomes do serviço: anchors/anchor-<dir>, anchors/candidate, <ação>/spritesheet,
// <ação>/preview (GIF), <ação>/manifest (JSON de frames).
func spriteArtifactExt(name string) (string, bool) {
	switch {
	case strings.HasPrefix(name, "anchors/"):
		return "png", true
	case strings.HasSuffix(name, "/spritesheet"):
		return "png", true
	case strings.HasSuffix(name, "/preview"):
		return "gif", true
	case strings.HasSuffix(name, "/manifest"):
		return "json", true
	}
	return "", false
}

// pingSprite — test-key do admin (chave candidata, sem salvar).
func pingSprite(ctx context.Context, key string) error {
	return sprite.New(key, os.Getenv("SPRITERRIFIC_API_BASE")).Ping(ctx)
}

// ——— Motor LOCAL de sprites (pipeline próprio: KIE gera, ffmpeg-service normaliza) ———
// A âncora e o clipe i2v saem das rotas de geração EXISTENTES (/v1/image, /v1/video);
// aqui entram só os passos novos — extração de frames e normalização. Sem chave externa.
// Plano completo: docs/PLANO-SPRITE-LOCAL.md.

// POST /v1/sprite/frames — clipe i2v (do NOSSO storage) → frames por token + contact
// sheet numerada pro frame-pick. Passthrough do ffmpeg-service /sprite-frames.
func (s *Server) spriteFrames(w http.ResponseWriter, r *http.Request) {
	var in struct {
		VideoURL string `json:"video_url"`
		Fps      int    `json:"fps"`
	}
	if !decode(w, r, &in) {
		return
	}
	if in.VideoURL == "" {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": "video_url obrigatório"})
		return
	}
	body := map[string]any{"video_url": in.VideoURL}
	if in.Fps > 0 { // zero = omite (o serviço aplica o default 12, não o clamp mínimo)
		body["fps"] = in.Fps
	}
	s.relayFfmpeg(w, r, "/sprite-frames", body)
}

// POST /v1/sprite/normalize — poses escolhidas → spritesheet + preview GIF + manifest.
// Duas fontes (passthrough do ffmpeg-service): token+frames (vídeo) OU sheet_url (prancha
// 5×2 por image-gen, fatiada pelo grid nominal).
func (s *Server) spriteNormalize(w http.ResponseWriter, r *http.Request) {
	var in struct {
		Token     string `json:"token"`
		Frames    []int  `json:"frames"`
		SheetURL  string `json:"sheet_url"`
		Cols      int    `json:"cols"`
		Rows      int    `json:"rows"`
		Chroma    string `json:"chroma"`
		Tolerance int    `json:"tolerance"`
		Cell      int    `json:"cell"`
		Fps       int    `json:"fps"`
	}
	if !decode(w, r, &in) {
		return
	}
	if in.SheetURL == "" && (in.Token == "" || len(in.Frames) == 0) {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": "token+frames OU sheet_url obrigatórios"})
		return
	}
	body := map[string]any{}
	if in.SheetURL != "" {
		body["sheet_url"] = in.SheetURL
		if in.Cols > 0 {
			body["cols"] = in.Cols
		}
		if in.Rows > 0 {
			body["rows"] = in.Rows
		}
		if len(in.Frames) > 0 {
			body["frames"] = in.Frames
		}
	} else {
		body["token"] = in.Token
		body["frames"] = in.Frames
	}
	if in.Chroma != "" {
		body["chroma"] = in.Chroma
	}
	if in.Tolerance > 0 {
		body["tolerance"] = in.Tolerance
	}
	if in.Cell > 0 {
		body["cell"] = in.Cell
	}
	if in.Fps > 0 {
		body["fps"] = in.Fps
	}
	s.relayFfmpeg(w, r, "/sprite-normalize", body)
}

// relayFfmpeg repassa status + JSON do ffmpeg-service (o contrato é dele).
func (s *Server) relayFfmpeg(w http.ResponseWriter, r *http.Request, path string, body map[string]any) {
	status, out, err := s.spriteMedia.PostJSON(r.Context(), path, body)
	if err != nil {
		writeJSON(w, http.StatusBadGateway, map[string]string{"error": "ffmpeg-service indisponível: " + err.Error()})
		return
	}
	writeJSON(w, status, out)
}
