// runninghub.go — MOTION TRANSFER via RunningHub (OpenAPI v2). Roda um workflow ComfyUI CURADO
// (ex. SCAIL SDPose Uni3C: transfere o gesto/câmera de um vídeo-guia pra um personagem, incl.
// NÃO-humano) por HTTP: submit /openapi/v2/run/workflow/{id} (Bearer) → poll /openapi/v2/query até
// SUCCESS, devolve a URL do vídeo. SCHEMA-DRIVEN: o CONSOLE monta o nodeInfoList (quais nós recebem
// o keyframe e o vídeo-guia) e passa aqui — o engine é um cliente genérico do provedor, sem
// hardcode de workflow (mesma filosofia schema-driven do resto). White-label: erros não citam o
// provedor. Recursos aceitam URL pública direto (passamos a URL do nosso S3, sem upload).
package motion

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

const rhBase = "https://www.runninghub.ai/openapi/v2"

// NodeInfo — um mapeamento de parâmetro do workflow (o console preenche a partir do catálogo +
// das URLs do keyframe/vídeo). fieldValue aceita URL pública (nosso S3).
type NodeInfo struct {
	NodeId     string `json:"nodeId"`
	FieldName  string `json:"fieldName"`
	FieldValue any    `json:"fieldValue"`
}

// Client — cliente do RunningHub OpenAPI v2. apiKey via env (nunca hardcoded).
type Client struct {
	apiKey string
	http   *http.Client
}

func New(apiKey string) *Client {
	return &Client{apiKey: strings.TrimSpace(apiKey), http: &http.Client{Timeout: 60 * time.Second}}
}

func (c *Client) Enabled() bool { return c.apiKey != "" }

func (c *Client) post(ctx context.Context, path string, body any) (map[string]any, error) {
	buf, _ := json.Marshal(body)
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, rhBase+path, bytes.NewReader(buf))
	if err != nil {
		return nil, err
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Authorization", "Bearer "+c.apiKey)
	res, err := c.http.Do(req)
	if err != nil {
		return nil, err
	}
	defer res.Body.Close()
	raw, _ := io.ReadAll(io.LimitReader(res.Body, 8<<20))
	var out map[string]any
	if err := json.Unmarshal(raw, &out); err != nil {
		return nil, fmt.Errorf("motion: resposta inválida (%d)", res.StatusCode)
	}
	return out, nil
}

// Submit — dispara o workflow. Devolve o taskId. instanceType: "default" (24G) | "plus" (48G).
func (c *Client) Submit(ctx context.Context, workflowID string, nodes []NodeInfo, instanceType string) (string, error) {
	if c.apiKey == "" {
		return "", fmt.Errorf("motion: sem credencial")
	}
	if strings.TrimSpace(workflowID) == "" {
		return "", fmt.Errorf("motion: sem workflow")
	}
	if instanceType == "" {
		instanceType = "default"
	}
	out, err := c.post(ctx, "/run/workflow/"+workflowID, map[string]any{
		"addMetadata":  true,
		"nodeInfoList": nodes,
		"instanceType": instanceType,
	})
	if err != nil {
		return "", err
	}
	if ec, _ := out["errorCode"].(string); ec != "" {
		return "", fmt.Errorf("motion: falha ao submeter (%s)", ec)
	}
	taskID, _ := out["taskId"].(string)
	if taskID == "" {
		return "", fmt.Errorf("motion: sem taskId")
	}
	return taskID, nil
}

// Poll — acompanha o taskId até terminar. Devolve a URL do primeiro resultado de vídeo (mp4).
// Vídeo é LENTO (minutos) + fila: budget de poll grande, respeita ctx.Done().
func (c *Client) Poll(ctx context.Context, taskID string, maxPolls int, interval time.Duration) (string, error) {
	if maxPolls <= 0 {
		maxPolls = 90
	}
	if interval <= 0 {
		interval = 5 * time.Second
	}
	for i := 0; i < maxPolls; i++ {
		if i > 0 {
			select {
			case <-ctx.Done():
				return "", ctx.Err()
			case <-time.After(interval):
			}
		}
		out, err := c.post(ctx, "/query", map[string]any{"taskId": taskID})
		if err != nil {
			continue // erro transitório de rede — tenta de novo
		}
		switch status, _ := out["status"].(string); status {
		case "SUCCESS":
			return pickVideoURL(out["results"])
		case "FAILED":
			msg, _ := out["errorMessage"].(string)
			if msg == "" {
				msg = "geração falhou"
			}
			return "", fmt.Errorf("motion: %s", msg)
		}
		// QUEUED / RUNNING → continua
	}
	return "", fmt.Errorf("motion: tempo esgotado")
}

// pickVideoURL — do array de results, prefere o primeiro mp4/webm/mov; senão o primeiro com url.
func pickVideoURL(results any) (string, error) {
	arr, ok := results.([]any)
	if !ok || len(arr) == 0 {
		return "", fmt.Errorf("motion: sem resultado")
	}
	var fallback string
	for _, r := range arr {
		m, ok := r.(map[string]any)
		if !ok {
			continue
		}
		url, _ := m["url"].(string)
		if url == "" {
			continue
		}
		if fallback == "" {
			fallback = url
		}
		ext, _ := m["outputType"].(string)
		switch strings.ToLower(ext) {
		case "mp4", "webm", "mov":
			return url, nil
		}
	}
	if fallback == "" {
		return "", fmt.Errorf("motion: sem url no resultado")
	}
	return fallback, nil
}
