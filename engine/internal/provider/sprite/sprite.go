// Package sprite — client do motor hospedado de sprites de jogo (Spriterrific HTTP API).
// Personagem por prompt → âncora direcional + spritesheets de animação (walk, idle, attack…),
// jobs assíncronos com polling. A chave é do OPERADOR (genkeys "spriterrific" > env), os
// créditos são debitados lá; passos que falham são estornados pelo próprio serviço.
// Contrato: skill spriterrific-api v1.3.2 (github.com/chongdashu/spriterrific-skills).
package sprite

import (
	"bytes"
	"context"
	"fmt"
	"io"
	"net/http"
	"strings"
	"time"
)

// DefaultBase — deployment hospedado padrão; override via SPRITERRIFIC_API_BASE.
const DefaultBase = "https://courteous-mouse-611.convex.site"

// skillVersion vai em toda request (X-Spriterrific-Skill-Version): o serviço devolve um
// campo "notice" quando o contrato desta integração ficou velho — repassar, não engolir.
const skillVersion = "1.3.2"

// maxRespBytes — teto de resposta lida (as respostas legítimas são JSON pequenos; artefatos
// binários são baixados pelo ffmpeg-service, não por aqui).
const maxRespBytes = 4 << 20 // 4MB

type Client struct {
	base string
	key  string
	hc   *http.Client
}

// New — key vazia = desabilitado (Enabled() false). base vazia = hospedado padrão.
func New(key, base string) *Client {
	if base == "" {
		base = DefaultBase
	}
	return &Client{
		base: strings.TrimRight(base, "/"),
		key:  key,
		// Enqueue/poll são leves; 60s cobre folgado a latência do serviço (a geração em si
		// é assíncrona — quem espera minutos é o polling do web, não esta chamada).
		hc: &http.Client{Timeout: 60 * time.Second},
	}
}

func (c *Client) Enabled() bool { return c != nil && c.key != "" }

// do — request autenticada; devolve status + corpo cru (o engine repassa o JSON do serviço
// sem re-modelar: o contrato é do provedor e o web lê o shape original).
func (c *Client) do(ctx context.Context, method, path string, body []byte) (int, []byte, error) {
	var rd io.Reader
	if body != nil {
		rd = bytes.NewReader(body)
	}
	req, err := http.NewRequestWithContext(ctx, method, c.base+path, rd)
	if err != nil {
		return 0, nil, err
	}
	req.Header.Set("Authorization", "Bearer "+c.key)
	req.Header.Set("X-Spriterrific-Skill-Version", skillVersion)
	if body != nil {
		req.Header.Set("Content-Type", "application/json")
	}
	resp, err := c.hc.Do(req)
	if err != nil {
		return 0, nil, err
	}
	defer resp.Body.Close()
	data, err := io.ReadAll(io.LimitReader(resp.Body, maxRespBytes))
	if err != nil {
		return resp.StatusCode, nil, err
	}
	return resp.StatusCode, data, nil
}

// Me — GET /api/v1/me: saldo de créditos (planCredits/topupCredits/total) + notice.
func (c *Client) Me(ctx context.Context) (int, []byte, error) {
	return c.do(ctx, http.MethodGet, "/api/v1/me", nil)
}

// Enqueue — POST /api/v1/jobs (payload já validado/allowlistado pelo caller).
// 201 → {jobId, credits}; 400 validação, 401 chave, 402 sem crédito.
func (c *Client) Enqueue(ctx context.Context, payload []byte) (int, []byte, error) {
	return c.do(ctx, http.MethodPost, "/api/v1/jobs", payload)
}

// Jobs — GET /api/v1/jobs?limit=N (mais novos primeiro, do dono da chave).
func (c *Client) Jobs(ctx context.Context, limit int) (int, []byte, error) {
	if limit < 1 || limit > 100 {
		limit = 25
	}
	return c.do(ctx, http.MethodGet, fmt.Sprintf("/api/v1/jobs?limit=%d", limit), nil)
}

// Job — GET /api/v1/jobs/{id}. Envelope {"job": {...}} — status/steps/artifacts vivem DENTRO
// de "job" (ler o topo faz o poller rodar pra sempre).
func (c *Client) Job(ctx context.Context, id string) (int, []byte, error) {
	return c.do(ctx, http.MethodGet, "/api/v1/jobs/"+id, nil)
}

// Ping — valida a chave sem gastar crédito (GET /me). Usado pelo test-key do admin.
func (c *Client) Ping(ctx context.Context) error {
	if !c.Enabled() {
		return fmt.Errorf("chave não configurada")
	}
	status, body, err := c.Me(ctx)
	if err != nil {
		return err
	}
	if status != http.StatusOK {
		return fmt.Errorf("status %d: %s", status, clip(string(body), 160))
	}
	return nil
}

func clip(s string, n int) string {
	if len(s) > n {
		return s[:n]
	}
	return s
}
