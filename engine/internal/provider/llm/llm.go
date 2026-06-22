// Package llm — provedores de texto do Reachyn.
// Primário: provedor de texto (saída direta). Fallback: provedor de texto alternativo.
// White-label: bases, modelos e nomes ficam fora do código — vêm de env / config do console.
package llm

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"regexp"
	"strings"
	"time"
)

// Paths fixos (parte estrutural do protocolo, não revelam provedor). A base (host) vem
// SEMPRE de config/env; sem base configurada o client não chama (erro genérico).
const (
	textChatPath    = "/v1/text/chatcompletion_v2"
	textAltChatPath = "/api/chat"
)

// LLMConfig — base_url + model dos provedores de TEXTO. Cada base é uma URL SEM path
// (o llm concatena o path estrutural). Sem base/modelo configurados, o provedor fica
// indisponível (white-label: nada de URL/modelo real hardcoded como default).
type LLMConfig struct {
	TextBaseURL    string // base do provedor de texto primário (sem path)
	TextModel      string // modelo do provedor de texto primário
	TextAltBaseURL string // base do provedor de texto alternativo (sem path)
	TextAltModel   string // modelo do provedor de texto alternativo
}

type Client struct {
	textAltKey string // chave do provedor de texto alternativo (fallback)
	textKey    string // chave do provedor de texto primário
	// base_url + model resolvidos (vêm de env/console; vazio = provedor indisponível).
	textBase     string
	textModel    string
	textAltBase  string
	textAltModel string
	http         *http.Client
}

// New cria o client de texto. base_url + model vêm de env/console (LLMConfig). Campos
// vazios = aquele provedor de texto fica indisponível (sem default revelador).
func New(textAltKey, textKey string, opts LLMConfig) *Client {
	return &Client{
		textAltKey:   textAltKey,
		textKey:      textKey,
		textBase:     opts.TextBaseURL,
		textModel:    opts.TextModel,
		textAltBase:  opts.TextAltBaseURL,
		textAltModel: opts.TextAltModel,
		http:         &http.Client{Timeout: 130 * time.Second},
	}
}

// AltChat — provedor de texto alternativo, saída direta (thinkingBudget 0).
func (c *Client) AltChat(ctx context.Context, system, user string, jsonFormat bool) (string, error) {
	if c.textAltBase == "" {
		return "", fmt.Errorf("texto alt: base não configurada")
	}
	body := map[string]any{
		"model":  c.textAltModel,
		"stream": false,
		"messages": []map[string]string{
			{"role": "system", "content": system},
			{"role": "user", "content": user},
		},
		"options": map[string]any{"thinkingBudget": 0},
	}
	if jsonFormat {
		body["format"] = "json"
	}
	raw, _ := json.Marshal(body)
	req, _ := http.NewRequestWithContext(ctx, http.MethodPost, c.textAltBase+textAltChatPath, bytes.NewReader(raw))
	req.Header.Set("Authorization", "Bearer "+c.textAltKey)
	req.Header.Set("Content-Type", "application/json")
	resp, err := c.http.Do(req)
	if err != nil {
		return "", err
	}
	defer resp.Body.Close()
	var d struct {
		Message struct {
			Content string `json:"content"`
		} `json:"message"`
	}
	if err := json.NewDecoder(resp.Body).Decode(&d); err != nil {
		return "", err
	}
	return strings.TrimSpace(d.Message.Content), nil
}

var thinkRe = regexp.MustCompile(`(?s)<think>.*?</think>`)

// GenText — provedor de texto primário direto. É um reasoning model: o "pensamento" vai
// em reasoning_content (descartado) e a resposta em content. Com max_tokens baixo o
// reasoning consome tudo e o content volta vazio — por isso garantimos um piso.
// 3 retries + strip de <think>/cercas.
func (c *Client) GenText(ctx context.Context, system, user string, maxTokens int) (string, error) {
	if c.textBase == "" {
		return "", fmt.Errorf("texto: base não configurada")
	}
	if maxTokens < 2048 {
		maxTokens = 2048 // espaço pro reasoning + a resposta (senão content vem vazio)
	}
	once := func() (string, error) {
		body, _ := json.Marshal(map[string]any{
			"model": c.textModel,
			"messages": []map[string]string{
				{"role": "system", "content": system},
				{"role": "user", "content": user},
			},
			"max_tokens":  maxTokens,
			"temperature": 0.6,
		})
		// base configurável (env/console) + path estrutural do chat completion.
		req, _ := http.NewRequestWithContext(ctx, http.MethodPost, c.textBase+textChatPath, bytes.NewReader(body))
		req.Header.Set("Authorization", "Bearer "+c.textKey)
		req.Header.Set("Content-Type", "application/json")
		resp, err := c.http.Do(req)
		if err != nil {
			return "", err
		}
		defer resp.Body.Close()
		txt, _ := io.ReadAll(resp.Body)
		if resp.StatusCode >= 400 || len(txt) == 0 {
			return "", fmt.Errorf("gentext HTTP %d (%db)", resp.StatusCode, len(txt))
		}
		var d struct {
			BaseResp struct {
				StatusCode int `json:"status_code"`
			} `json:"base_resp"`
			Choices []struct {
				Message struct {
					Content string `json:"content"`
				} `json:"message"`
			} `json:"choices"`
		}
		if err := json.Unmarshal(txt, &d); err != nil {
			return "", err
		}
		if sc := d.BaseResp.StatusCode; sc != 0 && sc != 200 {
			return "", fmt.Errorf("gentext app err %d", sc)
		}
		if len(d.Choices) == 0 {
			return "", fmt.Errorf("gentext vazio")
		}
		out := d.Choices[0].Message.Content
		out = thinkRe.ReplaceAllString(out, "")
		out = strings.TrimPrefix(strings.TrimSpace(out), "```json")
		out = strings.TrimPrefix(out, "```")
		out = strings.TrimSuffix(strings.TrimSpace(out), "```")
		out = strings.TrimSpace(out)
		if out == "" {
			return "", fmt.Errorf("gentext vazio (só raciocínio)")
		}
		return out, nil
	}
	var last error
	for i := 0; i < 3; i++ {
		if out, err := once(); err == nil {
			return out, nil
		} else {
			last = err
		}
	}
	if last == nil {
		last = fmt.Errorf("gentext falhou")
	}
	return "", last
}
