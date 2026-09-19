// Package speech — transcrição via ElevenLabs Scribe (tempos por palavra).
// Porta fiel do transcribeVideo do lib/studio.ts. Base do clipper (highlights sincronizados).
package speech

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"mime/multipart"
	"net/http"

	"github.com/redfoxcode/reachyn/engine/internal/provider/bridgealvo"
	"strings"
	"time"
)

type Client struct {
	key  string
	http *http.Client
	// CLI Bridge (sidecar no host da VPS) — narração por assinatura paga. Ver clibridge.go.
	bridgeURL   string
	bridgeToken string
	bridgeMac   bridgealvo.Alvo // 2º sidecar (Mac): CLIs que só existem lá — ver o pacote
	// 🔁 RESERVA de voz (MiniMax). nil = não configurada. Ver WithMinimax em minimax.go.
	mmx *MinimaxClient
}

func New(key string) *Client {
	return &Client{key: key, http: &http.Client{Timeout: 300 * time.Second}}
}

// Ping valida a chave sem custo (GET /v1/user). 200 = ok. Um 401 por FALTA DE ESCOPO
// (missing_permissions) também significa chave VÁLIDA — ela autenticou, só não tem aquele
// escopo (as chaves operacionais do Reachyn não precisam de user_read). Só 401 de chave
// inválida (detected_unusual_activity / invalid_api_key) é erro de verdade.
func (c *Client) Ping(ctx context.Context) error {
	if c.key == "" {
		return fmt.Errorf("chave vazia")
	}
	req, _ := http.NewRequestWithContext(ctx, http.MethodGet, "https://api.elevenlabs.io/v1/user", nil)
	req.Header.Set("xi-api-key", c.key)
	resp, err := c.http.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	if resp.StatusCode == http.StatusOK {
		return nil
	}
	b, _ := io.ReadAll(io.LimitReader(resp.Body, 400))
	if resp.StatusCode == http.StatusUnauthorized && strings.Contains(string(b), "missing_permissions") {
		return nil // chave válida, apenas sem o escopo user_read
	}
	return fmt.Errorf("elevenlabs %d: %s", resp.StatusCode, string(b))
}

// Voice — uma voz disponível na conta do provedor (para o seletor de narração).
type Voice struct {
	ID       string `json:"id"`
	Name     string `json:"name"`
	Gender   string `json:"gender"`
	Accent   string `json:"accent"`
	Language string `json:"language"`
	Category string `json:"category"` // premade | cloned | professional | generated
}

// ListVoices — lista as vozes da conta (GET /v1/voices). Prioriza as PT-BR (idioma/sotaque
// português ou brasileiro nos labels); se nenhuma casar, devolve todas (fallback) — nunca vazio.
func (c *Client) ListVoices(ctx context.Context) ([]Voice, error) {
	if c.key == "" {
		return nil, fmt.Errorf("ELEVENLABS_API_KEY não configurada")
	}
	req, _ := http.NewRequestWithContext(ctx, http.MethodGet, "https://api.elevenlabs.io/v1/voices", nil)
	req.Header.Set("xi-api-key", c.key)
	resp, err := c.http.Do(req)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()
	raw, _ := io.ReadAll(resp.Body)
	if resp.StatusCode >= 400 {
		return nil, fmt.Errorf("voices HTTP %d: %s", resp.StatusCode, clip(string(raw), 200))
	}
	var d struct {
		Voices []struct {
			VoiceID  string            `json:"voice_id"`
			Name     string            `json:"name"`
			Category string            `json:"category"`
			Labels   map[string]string `json:"labels"`
		} `json:"voices"`
	}
	if err := json.Unmarshal(raw, &d); err != nil {
		return nil, err
	}
	var all, ptbr []Voice
	for _, v := range d.Voices {
		vc := Voice{ID: v.VoiceID, Name: v.Name, Category: v.Category,
			Gender: v.Labels["gender"], Accent: v.Labels["accent"], Language: v.Labels["language"]}
		all = append(all, vc)
		lang := strings.ToLower(vc.Language)
		acc := strings.ToLower(vc.Accent)
		if strings.Contains(lang, "pt") || strings.Contains(lang, "portug") ||
			strings.Contains(acc, "brazil") || strings.Contains(acc, "portug") {
			ptbr = append(ptbr, vc)
		}
	}
	if len(ptbr) > 0 {
		return ptbr, nil
	}
	return all, nil
}

type WordTS struct {
	Text  string  `json:"text"`
	Start float64 `json:"start"`
	End   float64 `json:"end"`
}

type Transcript struct {
	Text  string   `json:"text"`
	Words []WordTS `json:"words"`
}

// Transcribe — Scribe v2 a partir de uma URL de mídia, com timestamps por palavra.
func (c *Client) Transcribe(ctx context.Context, mediaURL string) (Transcript, error) {
	if c.key == "" {
		return Transcript{}, fmt.Errorf("ELEVENLABS_API_KEY não configurada")
	}
	var buf bytes.Buffer
	mw := multipart.NewWriter(&buf)
	_ = mw.WriteField("model_id", "scribe_v2")
	_ = mw.WriteField("source_url", mediaURL)
	_ = mw.WriteField("timestamps_granularity", "word")
	mw.Close()

	req, _ := http.NewRequestWithContext(ctx, http.MethodPost, "https://api.elevenlabs.io/v1/speech-to-text", &buf)
	req.Header.Set("xi-api-key", c.key)
	req.Header.Set("Content-Type", mw.FormDataContentType())
	resp, err := c.http.Do(req)
	if err != nil {
		return Transcript{}, err
	}
	defer resp.Body.Close()
	raw, _ := io.ReadAll(resp.Body)
	if resp.StatusCode >= 400 {
		return Transcript{}, fmt.Errorf("Scribe HTTP %d: %s", resp.StatusCode, clip(string(raw), 200))
	}
	var d struct {
		Text  string `json:"text"`
		Words []struct {
			Text  string  `json:"text"`
			Start float64 `json:"start"`
			End   float64 `json:"end"`
			Type  string  `json:"type"`
		} `json:"words"`
	}
	if err := json.Unmarshal(raw, &d); err != nil {
		return Transcript{}, err
	}
	out := Transcript{Text: d.Text}
	for _, w := range d.Words {
		if w.Type == "word" {
			out.Words = append(out.Words, WordTS{Text: w.Text, Start: w.Start, End: w.End})
		}
	}
	return out, nil
}

func clip(s string, n int) string {
	if len(s) > n {
		return s[:n]
	}
	return s
}
