// Package media — cliente da stack de mídia da Reachyn (ffmpeg-service + Scality).
// Resolve por NOME DE SERVIÇO (MEDIA_FFMPEG_URL), nunca IP fixo (lição do IP stale).
// Porta de persist/shortform/clip/ingest/thumbnail do lib/studio.ts.
package media

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"strings"
	"time"

	"github.com/lrdeoliveira/reachyn-oss/engine/internal/provider/speech"
)

type Client struct {
	base  string
	token string // X-Service-Token exigido pelo ffmpeg-service (AUD-008)
	http  *http.Client
}

func New(baseURL, token string) *Client {
	return &Client{base: strings.TrimRight(baseURL, "/"), token: token, http: &http.Client{Timeout: 600 * time.Second}}
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

// ShortBeat — um clipe + legenda + fala, pronto pra montagem sincronizada.
type ShortBeat struct {
	ClipURL string `json:"clip_url"`
	Caption string `json:"caption"`
	Script  string `json:"script"`
}

// Shortform — monta os beats num short vertical (ffmpeg-service /shortform) com etapas
// CONDICIONAIS: cada flag em false PULA a etapa no ffmpeg-service (economiza recurso).
//   - narration: gera narração TTS por cena (e dita a duração do segmento);
//   - subtitles: queima legenda (word-level se há narração; timing estimado se não);
//   - music: gera trilha de fundo mixada sob o vídeo.
//   - lang: idioma da narração/legenda (repassado; informativo no body).
//
// O ffmpeg-service usa default true em todas as flags quando ausentes — então um body sem
// flags reproduz EXATAMENTE o comportamento histórico (narração+legenda+música).
func (c *Client) Shortform(ctx context.Context, beats []ShortBeat, voiceID string, narration, subtitles, music bool, lang, aspect string) (string, error) {
	var out struct {
		VideoURL string `json:"video_url"`
	}
	body := map[string]any{
		"beats":     beats,
		"voice_id":  voiceID,
		"narration": narration,
		"subtitles": subtitles,
		"music":     music,
		"lang":      lang,
		"aspect":    aspect, // "9:16" (default) ou "16:9" — dimensões da montagem no ffmpeg-service
	}
	if err := c.post(ctx, "/shortform", body, &out); err != nil {
		return "", err
	}
	if out.VideoURL == "" {
		return "", fmt.Errorf("montagem falhou (sem video_url)")
	}
	return out.VideoURL, nil
}

// StripAudio — remove o áudio de um vídeo (ffmpeg-service /strip-audio) → vídeo
// MUDO no Scality. Usado pelo "vídeo premium + narração própria" antes de sobrepor a voz.
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
// (ex.: a URI do vídeo premium exige o header de auth pra baixar). Quando há headers, eles vão
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

func clip(s string, n int) string {
	if len(s) > n {
		return s[:n]
	}
	return s
}
