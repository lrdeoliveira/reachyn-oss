// Package speech — transcrição de voz com tempos por palavra (base do clipper / highlights
// sincronizados). White-label: base, modelo de transcrição e nome do header de auth vêm de
// env/config — nada de URL ou nome de provedor no código.
package speech

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"mime/multipart"
	"net/http"
	"strings"
	"time"
)

// Config — base/model/header do provedor de voz (vêm de env/config).
type Config struct {
	BaseURL      string // base da API de voz (sem path)
	Model        string // modelo de transcrição
	APIKeyHeader string // nome do header de auth (ex.: configurável; sem revelar o provedor)
}

type Client struct {
	key       string
	base      string
	model     string
	keyHeader string
	http      *http.Client
}

func New(key string, cfg Config) *Client {
	h := cfg.APIKeyHeader
	if h == "" {
		h = "Authorization" // header genérico se não configurado (evita revelar provedor)
	}
	return &Client{key: key, base: cfg.BaseURL, model: cfg.Model, keyHeader: h, http: &http.Client{Timeout: 300 * time.Second}}
}

// Ping valida a chave sem custo (GET /v1/user). 200 = ok. Um 401 por FALTA DE ESCOPO
// (missing_permissions) também significa chave VÁLIDA — ela autenticou, só não tem aquele
// escopo (as chaves operacionais do Reachyn não precisam de user_read). Só 401 de chave
// inválida (detected_unusual_activity / invalid_api_key) é erro de verdade.
func (c *Client) Ping(ctx context.Context) error {
	if c.key == "" {
		return fmt.Errorf("chave vazia")
	}
	if c.base == "" {
		return fmt.Errorf("voz: base não configurada")
	}
	req, _ := http.NewRequestWithContext(ctx, http.MethodGet, c.base+"/v1/user", nil)
	req.Header.Set(c.keyHeader, c.key)
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
	return fmt.Errorf("voz %d: %s", resp.StatusCode, string(b))
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
		return nil, fmt.Errorf("voz: chave não configurada")
	}
	if c.base == "" {
		return nil, fmt.Errorf("voz: base não configurada")
	}
	req, _ := http.NewRequestWithContext(ctx, http.MethodGet, c.base+"/v1/voices", nil)
	req.Header.Set(c.keyHeader, c.key)
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

// Transcribe — transcrição a partir de uma URL de mídia, com timestamps por palavra.
func (c *Client) Transcribe(ctx context.Context, mediaURL string) (Transcript, error) {
	if c.key == "" {
		return Transcript{}, fmt.Errorf("voz: chave não configurada")
	}
	if c.base == "" || c.model == "" {
		return Transcript{}, fmt.Errorf("voz: base/modelo não configurados")
	}
	var buf bytes.Buffer
	mw := multipart.NewWriter(&buf)
	_ = mw.WriteField("model_id", c.model)
	_ = mw.WriteField("source_url", mediaURL)
	_ = mw.WriteField("timestamps_granularity", "word")
	mw.Close()

	req, _ := http.NewRequestWithContext(ctx, http.MethodPost, c.base+"/v1/speech-to-text", &buf)
	req.Header.Set(c.keyHeader, c.key)
	req.Header.Set("Content-Type", mw.FormDataContentType())
	resp, err := c.http.Do(req)
	if err != nil {
		return Transcript{}, err
	}
	defer resp.Body.Close()
	raw, _ := io.ReadAll(resp.Body)
	if resp.StatusCode >= 400 {
		return Transcript{}, fmt.Errorf("transcrição HTTP %d: %s", resp.StatusCode, clip(string(raw), 200))
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
