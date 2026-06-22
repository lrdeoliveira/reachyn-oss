// Package video — geração de vídeo. Modelo de fila (submit + poll, este arquivo);
// vídeo premium via operação longa (ver google.go).
// White-label: os endpoints de vídeo vêm de env/config; o código só conhece slots OPACOS
// (video-a / video-b / video-c). Timeouts longos e explícitos (jobs de vídeo levam minutos).
package video

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"time"
)

// VideoConfig — endpoints t2v/i2v por slot opaco + config do vídeo premium (ver google.go).
// Tudo vem de env/config; sem default revelador.
type VideoConfig struct {
	AT2V, AI2V string
	BT2V, BI2V string
	CT2V, CI2V string

	PremiumBaseURL    string // base da API de vídeo premium
	PremiumModel      string // modelo de vídeo premium
	PremiumAuthHeader string // nome do header de auth do vídeo premium
}

type Client struct {
	key               string // chave do provedor de fila (vídeo)
	premiumKey        string // chave do provedor de vídeo premium — ver google.go
	premiumBase       string // base da API de vídeo premium
	premiumModel      string // modelo de vídeo premium
	premiumAuthHeader string // nome do header de auth do vídeo premium
	endpoints         map[string]struct{ T2V, I2V string }
	http              *http.Client
}

func New(key, premiumKey string, cfg VideoConfig) *Client {
	endpoints := map[string]struct{ T2V, I2V string }{
		"video-a": {T2V: cfg.AT2V, I2V: cfg.AI2V},
		"video-b": {T2V: cfg.BT2V, I2V: cfg.BI2V},
		"video-c": {T2V: cfg.CT2V, I2V: cfg.CI2V},
	}
	return &Client{
		key:               key,
		premiumKey:        premiumKey,
		premiumBase:       cfg.PremiumBaseURL,
		premiumModel:      cfg.PremiumModel,
		premiumAuthHeader: cfg.PremiumAuthHeader,
		endpoints:         endpoints,
		http:              &http.Client{Timeout: 30 * time.Second},
	}
}

type submitResp struct {
	StatusURL   string `json:"status_url"`
	ResponseURL string `json:"response_url"`
}

func (c *Client) submit(ctx context.Context, endpoint string, body map[string]any) (submitResp, error) {
	if endpoint == "" {
		return submitResp{}, fmt.Errorf("vídeo: endpoint não configurado")
	}
	raw, _ := json.Marshal(body)
	req, _ := http.NewRequestWithContext(ctx, http.MethodPost, endpoint, bytes.NewReader(raw))
	req.Header.Set("Authorization", "Key "+c.key)
	req.Header.Set("Content-Type", "application/json")
	resp, err := c.http.Do(req)
	if err != nil {
		return submitResp{}, err
	}
	defer resp.Body.Close()
	var s submitResp
	if err := json.NewDecoder(resp.Body).Decode(&s); err != nil {
		return submitResp{}, err
	}
	if s.StatusURL == "" {
		return submitResp{}, fmt.Errorf("vídeo: sem status_url")
	}
	return s, nil
}

// poll espera o job da fila terminar (intervalo fixo) e devolve a URL do vídeo.
func (c *Client) poll(ctx context.Context, s submitResp, maxPolls int, interval time.Duration) (string, error) {
	get := func(url string, out any) error {
		req, _ := http.NewRequestWithContext(ctx, http.MethodGet, url, nil)
		req.Header.Set("Authorization", "Key "+c.key)
		resp, err := c.http.Do(req)
		if err != nil {
			return err
		}
		defer resp.Body.Close()
		return json.NewDecoder(resp.Body).Decode(out)
	}
	for i := 0; i < maxPolls; i++ {
		select {
		case <-ctx.Done():
			return "", ctx.Err()
		case <-time.After(interval):
		}
		var st struct {
			Status string `json:"status"`
		}
		if err := get(s.StatusURL, &st); err != nil {
			continue
		}
		switch st.Status {
		case "COMPLETED":
			var out struct {
				Video struct {
					URL string `json:"url"`
				} `json:"video"`
			}
			if err := get(s.ResponseURL, &out); err != nil {
				return "", err
			}
			return out.Video.URL, nil
		case "FAILED":
			return "", fmt.Errorf("vídeo: job FAILED")
		}
	}
	return "", fmt.Errorf("vídeo: timeout após %d polls", maxPolls)
}

// clipDuration — os modelos de vídeo aceitam clipes de 5 ou 10 segundos. O front envia
// "5"/"10" (ou "6" legado): "10" → 10, qualquer outro → 5. Retorna número (o body espera int).
func clipDuration(duration string) int {
	if duration == "10" {
		return 10
	}
	return 5
}

// validVideoAspect — formato aceito pelos modelos de vídeo: só "9:16" (vertical) ou "16:9"
// (horizontal); qualquer outro → "9:16" (vertical, padrão do produto). 1:1/4:5 não são
// suportados em vídeo (escolha de escopo: vídeo é vertical ou horizontal).
func validVideoAspect(aspect string) string {
	if aspect == "16:9" {
		return "16:9"
	}
	return "9:16"
}

// ── slots de vídeo (PRINCIPAL/FALLBACK vêm da gen_lines.video) ──
//
// O slot é escolhido em runtime pela gen_lines.video (primary/fallback). Slot
// desconhecido cai no defaultVideoModel (video-a), preservando o comportamento histórico.

// defaultVideoModel — slot usado quando o `model` vem vazio/desconhecido.
const defaultVideoModel = "video-a"

// resolveVideoModel — normaliza o slot opaco (video-a/b/c); vazio/desconhecido → default.
func (c *Client) resolveVideoModel(model string) string {
	if _, ok := c.endpoints[model]; ok {
		return model
	}
	return defaultVideoModel
}

// videoBody — monta o corpo do submit por SLOT. aspect_ratio/resolution variam por modelo:
// video-a usa aspect_ratio + resolution "720p"; video-b usa aspect_ratio; video-c só aceita
// duration (vertical nativo). prompt/image_url e duration são comuns. imageURL vazio =
// text-to-video; preenchido = image-to-video.
func videoBody(model, prompt, imageURL, duration, aspect string) map[string]any {
	body := map[string]any{"prompt": prompt, "duration": clipDuration(duration)}
	if imageURL != "" {
		body["image_url"] = imageURL
	}
	ar := validVideoAspect(aspect)
	switch model {
	case "video-a":
		body["aspect_ratio"] = ar
		body["resolution"] = "720p"
	case "video-b":
		body["aspect_ratio"] = ar
	case "video-c":
		// vertical nativo — só aceita duration (sem aspect_ratio/resolution).
	}
	return body
}

// ClipText2Video — texto → vídeo, com o SLOT escolhido em runtime (video-a/b/c). Os modelos
// geram vídeo de alta qualidade SEM áudio nativo (a narração vem do provedor de voz, então o
// áudio do clipe seria descartado de qualquer forma). duration 5/10.
func (c *Client) ClipText2Video(ctx context.Context, model, prompt, duration, aspect string) (string, error) {
	m := c.resolveVideoModel(model)
	s, err := c.submit(ctx, c.endpoints[m].T2V, videoBody(m, prompt, "", duration, aspect))
	if err != nil {
		return "", err
	}
	return c.poll(ctx, s, 110, 6*time.Second) // ~11 min (vídeo é lento; teto ampliado)
}

// ClipImage2Video — imagem → vídeo (base do short-form sincronizado), com o SLOT escolhido
// em runtime (video-a/b/c). duration 5/10.
func (c *Client) ClipImage2Video(ctx context.Context, model, imageURL, prompt, duration, aspect string) (string, error) {
	m := c.resolveVideoModel(model)
	s, err := c.submit(ctx, c.endpoints[m].I2V, videoBody(m, prompt, imageURL, duration, aspect))
	if err != nil {
		return "", err
	}
	return c.poll(ctx, s, 110, 6*time.Second) // ~11 min (vídeo é lento; teto ampliado)
}

// VideoPrimaryText2Video — wrapper retrocompatível: text-to-video no slot default (video-a).
// Mantido para chamadores que não passam modelo. Prefira ClipText2Video com o slot da gen_lines.
func (c *Client) VideoPrimaryText2Video(ctx context.Context, prompt, duration string) (string, error) {
	return c.ClipText2Video(ctx, defaultVideoModel, prompt, duration, "9:16")
}

// VideoPrimaryImage2Video — wrapper retrocompatível: image-to-video no slot default (video-a).
// Mantido para chamadores que não passam modelo. Prefira ClipImage2Video com o slot da gen_lines.
func (c *Client) VideoPrimaryImage2Video(ctx context.Context, imageURL, prompt, duration string) (string, error) {
	return c.ClipImage2Video(ctx, defaultVideoModel, imageURL, prompt, duration, "9:16")
}
