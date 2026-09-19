// Package llm — provedores de texto do Reachyn.
// Primário: CLI de assinatura no host, via cli-bridge (ver CliText). Reserva: MiniMax M2.7.
// O agregador de LLM saiu em 2026-08-03 (ordem do Luciano); o Ollama já estava desativado.
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

	"github.com/redfoxcode/reachyn/engine/internal/provider/bridgealvo"
)

// Defaults históricos — preservam EXATAMENTE o comportamento anterior quando o
// operador não configura base_url/model (campos vazios no LLMConfig).
const (
	// defaultMinimaxBase é a BASE do MiniMax (sem path). O endpoint final é
	// {base}/v1/text/chatcompletion_v2 — o operador configura só a base, ex.:
	// "https://api.minimax.io"; o llm concatena o path /v1/text/chatcompletion_v2.
	defaultMinimaxBase  = "https://api.minimax.io"
	minimaxChatPath     = "/v1/text/chatcompletion_v2"
	defaultMinimaxModel = "MiniMax-M2.7"

	// defaultOllamaBase é a BASE do Ollama (sem path). O endpoint final é
	// {base}/api/chat — o operador configura só a base, ex.: "https://ollama.com".
	defaultOllamaBase  = "https://ollama.com"
	ollamaChatPath     = "/api/chat"
	defaultOllamaModel = "gemini-3-flash-preview"
)

// LLMConfig — overrides de base_url + model dos provedores de TEXTO (estilo Nexusyn).
// Cada campo vazio cai no default histórico (retrocompat total). As bases são URLs SEM
// path (ex.: "https://api.minimax.io"); o llm concatena o path específico de cada provedor.
type LLMConfig struct {
	MinimaxBaseURL string // base do MiniMax (sem path), ex.: https://api.minimax.io
	MinimaxModel   string // model do MiniMax, ex.: MiniMax-M2.7
	OllamaBaseURL  string // base do Ollama (sem path), ex.: https://ollama.com
	OllamaModel    string // model do Ollama, ex.: gemini-3-flash-preview
}

type Client struct {
	ollamaKey  string
	minimaxKey string
	// CLI Bridge (sidecar no host): a linha de texto que roda em CONTA DE ASSINATURA, sem
	// agregador e sem crédito por chamada. Ver CliText.
	bridgeURL     string
	bridgeToken   string
	bridgeMac     bridgealvo.Alvo // 2º sidecar (Mac): CLIs que só existem lá — ver o pacote
	bridgeCLI     string          // adapter principal (mmx | codex)
	bridgeFastCLI string          // adapter da linha rápida (tarefa mecânica, alto volume)
	// base_url + model resolvidos (já com fallback pro default aplicado no New).
	minimaxBase  string
	minimaxModel string
	ollamaBase   string
	ollamaModel  string
	http         *http.Client
}

// New cria o client de texto. base_url + model são configuráveis via LLMConfig; cada
// campo vazio mantém o default histórico (MiniMax api.minimax.io + MiniMax-M2.7;
// Ollama ollama.com + gemini-3-flash-preview), preservando o comportamento anterior.
func New(ollamaKey, minimaxKey string, opts LLMConfig) *Client {
	return &Client{
		ollamaKey:    ollamaKey,
		minimaxKey:   minimaxKey,
		minimaxBase:  or(opts.MinimaxBaseURL, defaultMinimaxBase),
		minimaxModel: or(opts.MinimaxModel, defaultMinimaxModel),
		ollamaBase:   or(opts.OllamaBaseURL, defaultOllamaBase),
		ollamaModel:  or(opts.OllamaModel, defaultOllamaModel),
		// Backstop MAIOR que o maior deadline de ctx das chamadas (filmplan/story usam ctx ~230s):
		// o limite REAL de cada request vem do context.WithTimeout de quem chama. Um Timeout fixo
		// de 130s cortava gerações longas legítimas (plano com muitos beats no reasoning model) e,
		// com o retry dentro do ctx de 200s, produzia "context deadline exceeded" em ~200s exatos.
		http: &http.Client{Timeout: 320 * time.Second},
	}
}

// or devolve o override se não-vazio, senão o default.
func or(override, def string) string {
	if override != "" {
		return override
	}
	return def
}

// WithCliBridge — liga a linha de texto pelo CLI Bridge (sidecar no host), que passa a ser
// a PRIMÁRIA no lugar do agregador (decisão Luciano 2026-08-03).
//
// cli vazio = "claude" (agente de assinatura no host, régua alta pra roteiro).
// fastCLI vazio = "mmx" (CLI de mídia, resposta em segundos) — a linha rápida existe porque
// tradução de prompt roda em TODA geração de imagem e não precisa de agente.
// URL vazia = bridge desligado: HasCliText() vira false e o texto cai no MiniMax.
// WithCliBridgeMac — liga o SEGUNDO sidecar, no Mac. Adapter prefixado "mac:" vai pra cá.
// Vazio = desligado: um adapter "mac:" falha com erro claro em vez de escorregar pra VPS,
// onde a CLI pedida pode nem existir.
func (c *Client) WithCliBridgeMac(baseURL, token string) *Client {
	c.bridgeMac = bridgealvo.Alvo{URL: strings.TrimRight(baseURL, "/"), Token: token}
	return c
}

func (c *Client) WithCliBridge(baseURL, token, cli, fastCLI string) *Client {
	c.bridgeURL = strings.TrimRight(baseURL, "/")
	c.bridgeToken = token
	c.bridgeCLI = or(cli, "claude")
	c.bridgeFastCLI = or(fastCLI, "mmx")
	return c
}

// HasCliText — a linha de texto por CLI está configurada?
func (c *Client) HasCliText() bool { return c.bridgeURL != "" || c.bridgeMac.Configurado() }

// CliText — texto pela CLI do host (bridge POST /v1/text). É a PRIMÁRIA da geração de texto.
// `cli` vazio = adapter principal configurado. Sem fallback interno: quem chama decide se
// cai pro MiniMax (ver textGen no content) — trocar de motor em silêncio aqui esconderia
// um bridge morto atrás de uma régua menor por semanas.
func (c *Client) CliText(ctx context.Context, cli, system, user string, maxTokens int) (string, error) {
	alvo, adapter := bridgealvo.Resolve(or(cli, c.bridgeCLI),
		bridgealvo.Alvo{URL: c.bridgeURL, Token: c.bridgeToken}, c.bridgeMac)
	if !alvo.Configurado() {
		return "", fmt.Errorf("texto: linha primária indisponível")
	}
	_ = maxTokens // a CLI não expõe teto de tokens; o limite real é o timeout do ctx
	body, _ := json.Marshal(map[string]any{
		"provider": adapter,
		"system":   system,
		"user":     user,
	})
	req, _ := http.NewRequestWithContext(ctx, http.MethodPost, alvo.URL+"/v1/text", bytes.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("X-Bridge-Token", alvo.Token)
	resp, err := c.http.Do(req)
	if err != nil {
		return "", fmt.Errorf("texto: primária indisponível: %w", err)
	}
	defer resp.Body.Close()
	var d struct {
		OK    bool   `json:"ok"`
		Text  string `json:"text"`
		Error string `json:"error"`
	}
	if err := json.NewDecoder(io.LimitReader(resp.Body, 8<<20)).Decode(&d); err != nil {
		return "", fmt.Errorf("texto: primária resposta inválida (http %d)", resp.StatusCode)
	}
	if !d.OK || strings.TrimSpace(d.Text) == "" {
		return "", fmt.Errorf("texto: primária http %d: %s", resp.StatusCode, d.Error)
	}
	// Mesmo strip de <think> das outras linhas: adapter de agente às vezes vaza raciocínio.
	out := strings.TrimSpace(thinkRe.ReplaceAllString(d.Text, ""))
	if out == "" {
		return "", fmt.Errorf("texto: primária sem conteúdo")
	}
	return out, nil
}

// CliFast — linha RÁPIDA pela CLI (tarefa mecânica de alto volume: tradução de prompt).
func (c *Client) CliFast(ctx context.Context, system, user string, maxTokens int) (string, error) {
	return c.CliText(ctx, c.bridgeFastCLI, system, user, maxTokens)
}

// OllamaChat — modelo primário gemini-3-flash-preview, saída direta (thinkingBudget 0).
func (c *Client) OllamaChat(ctx context.Context, system, user string, jsonFormat bool) (string, error) {
	body := map[string]any{
		"model":  c.ollamaModel,
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
	req, _ := http.NewRequestWithContext(ctx, http.MethodPost, c.ollamaBase+ollamaChatPath, bytes.NewReader(raw))
	req.Header.Set("Authorization", "Bearer "+c.ollamaKey)
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

// M3 — MiniMax no modelo default do client (c.minimaxModel). Atalho de M3WithModel("").
func (c *Client) M3(ctx context.Context, system, user string, maxTokens int) (string, error) {
	return c.M3WithModel(ctx, "", system, user, maxTokens)
}

// M3WithModel — igual ao M3, mas com o modelo MiniMax EXPLÍCITO (vazio = c.minimaxModel).
// Permite uma função usar um modelo diferente do default GLOBAL sem trocá-lo — ex.: a história
// usa MiniMax-M3 (mais robusto p/ o JSON longo de N cenas) enquanto o texto das redes segue no
// default. É um reasoning model: o "pensamento" vai em reasoning_content (descartado) e a
// resposta em content; com max_tokens baixo o reasoning consome tudo e o content volta vazio —
// por isso garantimos um piso. 3 retries + strip de <think>/cercas.
func (c *Client) M3WithModel(ctx context.Context, model, system, user string, maxTokens int) (string, error) {
	if model == "" {
		model = c.minimaxModel
	}
	if maxTokens < 2048 {
		maxTokens = 2048 // espaço pro reasoning + a resposta (senão content vem vazio)
	}
	once := func() (string, error) {
		body, _ := json.Marshal(map[string]any{
			"model": model,
			"messages": []map[string]string{
				{"role": "system", "content": system},
				{"role": "user", "content": user},
			},
			"max_tokens":  maxTokens,
			"temperature": 0.6,
		})
		// base configurável (ex.: https://api.minimax.io) + path fixo do chat completion.
		req, _ := http.NewRequestWithContext(ctx, http.MethodPost, c.minimaxBase+minimaxChatPath, bytes.NewReader(body))
		req.Header.Set("Authorization", "Bearer "+c.minimaxKey)
		req.Header.Set("Content-Type", "application/json")
		resp, err := c.http.Do(req)
		if err != nil {
			return "", err
		}
		defer resp.Body.Close()
		txt, _ := io.ReadAll(resp.Body)
		if resp.StatusCode >= 400 || len(txt) == 0 {
			return "", fmt.Errorf("M3 HTTP %d (%db)", resp.StatusCode, len(txt))
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
			return "", fmt.Errorf("M3 app err %d", sc)
		}
		if len(d.Choices) == 0 {
			return "", fmt.Errorf("M3 vazio")
		}
		c := d.Choices[0].Message.Content
		c = thinkRe.ReplaceAllString(c, "")
		c = strings.TrimPrefix(strings.TrimSpace(c), "```json")
		c = strings.TrimPrefix(c, "```")
		c = strings.TrimSuffix(strings.TrimSpace(c), "```")
		c = strings.TrimSpace(c)
		if c == "" {
			return "", fmt.Errorf("M3 vazio (só raciocínio)")
		}
		return c, nil
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
		last = fmt.Errorf("M3 falhou")
	}
	return "", last
}
