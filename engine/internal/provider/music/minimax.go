// Package music — geração de MÚSICA via MiniMax (api.minimax.io/v1/music_generation), SÍNCRONO
// (1 POST devolve a faixa). Faixa completa (letra+estrutura), instrumental ou COVER de referência.
// model "music-2.6-free" é gratuito (RPM baixo) — bom p/ trilha/tema padrão; a linha paga fica
// premium. output_format=url: a resposta traz um link 24h (não enche o JSON de MB de hex); o caller
// persiste no S3. Auth Bearer, sem GroupId no endpoint global. White-label: erros não citam o provedor.
package music

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"strings"
	"time"
)

const minimaxBase = "https://api.minimax.io"

type Client struct {
	key  string
	base string
	http *http.Client
}

func New(key, baseURL string) *Client {
	base := strings.TrimRight(baseURL, "/")
	if base == "" {
		base = minimaxBase
	}
	return &Client{key: key, base: base, http: &http.Client{Timeout: 180 * time.Second}}
}

// Generate — gera UMA faixa. instrumental=true → sem vocais. Senão: lyrics não-vazio = canta a letra
// dada (com tags [Verse]/[Chorus]/…); lyrics vazio = lyrics_optimizer (a IA escreve a letra do prompt).
// Retorna a URL efêmera (24h) do mp3; o caller persiste no S3.
func (c *Client) Generate(ctx context.Context, model, prompt, lyrics string, instrumental bool) (string, error) {
	if c.key == "" {
		return "", fmt.Errorf("music: sem chave")
	}
	if model == "" {
		model = "music-2.6-free" // grátis por padrão
	}
	body := map[string]any{
		"model":         model,
		"prompt":        prompt,
		"output_format": "url",
		"audio_setting": map[string]any{"sample_rate": 44100, "bitrate": 256000, "format": "mp3"},
	}
	switch {
	case instrumental:
		body["is_instrumental"] = true
	case lyrics != "":
		body["lyrics"] = lyrics
	default:
		body["lyrics_optimizer"] = true
	}
	return c.submit(ctx, body)
}

// Cover — recria uma faixa a partir de um áudio de referência (model music-cover[-free]). prompt =
// estilo/mood alvo; audioURL = a referência (6s–6min, ≤50MB). Letra extraída por ASR se omitida.
func (c *Client) Cover(ctx context.Context, model, prompt, audioURL string) (string, error) {
	if c.key == "" {
		return "", fmt.Errorf("music: sem chave")
	}
	if model == "" {
		model = "music-cover-free"
	}
	return c.submit(ctx, map[string]any{
		"model":         model,
		"prompt":        prompt,
		"audio_url":     audioURL,
		"output_format": "url",
		"audio_setting": map[string]any{"sample_rate": 44100, "bitrate": 256000, "format": "mp3"},
	})
}

// submit — POST /v1/music_generation (síncrono). base_resp.status_code==0 = sucesso; data.audio = URL.
func (c *Client) submit(ctx context.Context, body map[string]any) (string, error) {
	raw, _ := json.Marshal(body)
	req, _ := http.NewRequestWithContext(ctx, http.MethodPost, c.base+"/v1/music_generation", bytes.NewReader(raw))
	req.Header.Set("Authorization", "Bearer "+c.key)
	req.Header.Set("Content-Type", "application/json")
	resp, err := c.http.Do(req)
	if err != nil {
		return "", err
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		b, _ := io.ReadAll(io.LimitReader(resp.Body, 400))
		return "", fmt.Errorf("music http %d: %s", resp.StatusCode, string(b))
	}
	var s struct {
		Data struct {
			Audio  string `json:"audio"`
			Status int    `json:"status"`
		} `json:"data"`
		BaseResp struct {
			StatusCode int    `json:"status_code"`
			StatusMsg  string `json:"status_msg"`
		} `json:"base_resp"`
	}
	if err := json.NewDecoder(resp.Body).Decode(&s); err != nil {
		return "", err
	}
	if s.BaseResp.StatusCode != 0 {
		return "", fmt.Errorf("music: geração falhou (%d: %s)", s.BaseResp.StatusCode, s.BaseResp.StatusMsg)
	}
	if s.Data.Audio == "" {
		return "", fmt.Errorf("music: resposta sem áudio")
	}
	return s.Data.Audio, nil // URL (output_format=url) — caller persiste no S3
}
