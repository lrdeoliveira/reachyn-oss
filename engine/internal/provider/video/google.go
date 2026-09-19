// google.go — provedor de vídeo premium (Veo) via API direta do Google Gemini.
// Fluxo de operação longa: POST predictLongRunning → polling do operation até "done"
// → extrai a URI do vídeo. A URI EXIGE o header de auth no GET pra baixar (validade 2 dias),
// então VeoGoogle devolve também o header que o caller repassa ao ffmpeg-service —
// assim a chave nunca aparece em log, erro ou string visível ao usuário (white-label).
package video

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"log"
	"net/http"
	"time"
)

// clipBody — corta o corpo de resposta pra log (evita poluir; não vaza ao usuário).
func clipBody(b []byte) string {
	const n = 300
	if len(b) > n {
		return string(b[:n])
	}
	return string(b)
}

// PingGoogle — valida a chave do Veo (Google) SEM gerar vídeo: um GET no modelo.
// 200 = chave válida e com acesso ao modelo; != 200 → erro (chave/permissão/saldo).
func (c *Client) PingGoogle(ctx context.Context) error {
	if c.googleKey == "" {
		return fmt.Errorf("sem chave")
	}
	req, _ := http.NewRequestWithContext(ctx, http.MethodGet, fmt.Sprintf("%s/models/%s", veoBase, veoModel), nil)
	req.Header.Set(veoAuthHeader, c.googleKey)
	resp, err := c.http.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		b, _ := io.ReadAll(io.LimitReader(resp.Body, 200))
		return fmt.Errorf("veo http %d: %s", resp.StatusCode, string(b))
	}
	return nil
}

const (
	// veoModel — modelo de geração de vídeo premium (rápido).
	veoModel = "veo-3.1-fast-generate-preview"
	// veoBase — base da API de geração (v1beta).
	veoBase = "https://generativelanguage.googleapis.com/v1beta"
	// veoAuthHeader — header de autenticação exigido tanto na chamada quanto no download da URI.
	veoAuthHeader = "x-goog-api-key"
)

// VeoGoogle — gera UM vídeo vertical com áudio integrado via operação longa.
// Retorna a URI do vídeo + o header de auth necessário pra baixá-la. O caller
// (longform) repassa o header ao ffmpeg-service, que persiste a mídia no Scality.
// NUNCA loga nem retorna a chave em mensagens de erro (white-label obrigatório).
func (c *Client) VeoGoogle(ctx context.Context, prompt, aspect string) (string, map[string]string, error) {
	if c.googleKey == "" {
		// Sem chave configurada: não chamamos a API. Erro genérico (mascarado a jusante).
		return "", nil, fmt.Errorf("veo indisponível")
	}
	if aspect == "" {
		aspect = "9:16"
	}

	// 1) Inicia a operação longa de geração.
	// ATENÇÃO ao formato exigido pela API: durationSeconds é NÚMERO (não string) e
	// numberOfVideos NÃO é suportado por este modelo (400 se enviado).
	reqBody := map[string]any{
		"instances": []map[string]any{{"prompt": prompt}},
		"parameters": map[string]any{
			"aspectRatio":     aspect,
			"durationSeconds": 8,
		},
	}
	raw, _ := json.Marshal(reqBody)
	startURL := fmt.Sprintf("%s/models/%s:predictLongRunning", veoBase, veoModel)
	req, _ := http.NewRequestWithContext(ctx, http.MethodPost, startURL, bytes.NewReader(raw))
	req.Header.Set(veoAuthHeader, c.googleKey)
	req.Header.Set("Content-Type", "application/json")
	resp, err := c.http.Do(req)
	if err != nil {
		return "", nil, err
	}
	body, _ := io.ReadAll(resp.Body)
	resp.Body.Close()
	var started struct {
		Name string `json:"name"`
	}
	if derr := json.Unmarshal(body, &started); derr != nil {
		return "", nil, derr
	}
	if started.Name == "" {
		// Loga o corpo REAL server-side (ajuda a depurar mudanças de contrato da API);
		// NÃO vaza ao usuário — o handler mascara via writeErr (white-label).
		log.Printf("veo: operação não iniciada (status %d): %s", resp.StatusCode, clipBody(body))
		return "", nil, fmt.Errorf("veo: operação não iniciada")
	}

	// 2) Polling do operation até "done" (intervalo fixo de 10s, ~11 min de teto).
	opURL := veoBase + "/" + started.Name
	const interval = 10 * time.Second
	const maxPolls = 66 // ~11 min
	for i := 0; i < maxPolls; i++ {
		select {
		case <-ctx.Done():
			return "", nil, ctx.Err()
		case <-time.After(interval):
		}
		greq, _ := http.NewRequestWithContext(ctx, http.MethodGet, opURL, nil)
		greq.Header.Set(veoAuthHeader, c.googleKey)
		gresp, gerr := c.http.Do(greq)
		if gerr != nil {
			continue // transitório: tenta de novo no próximo ciclo
		}
		var op struct {
			Done  bool `json:"done"`
			Error *struct {
				Message string `json:"message"`
			} `json:"error"`
			Response struct {
				GenerateVideoResponse struct {
					GeneratedSamples []struct {
						Video struct {
							URI string `json:"uri"`
						} `json:"video"`
					} `json:"generatedSamples"`
				} `json:"generateVideoResponse"`
			} `json:"response"`
		}
		decErr := json.NewDecoder(gresp.Body).Decode(&op)
		gresp.Body.Close()
		if decErr != nil {
			continue
		}
		if !op.Done {
			continue
		}
		if op.Error != nil {
			// Erro genérico: não vazamos a mensagem do provedor (pode citar a marca).
			return "", nil, fmt.Errorf("veo: geração falhou")
		}
		samples := op.Response.GenerateVideoResponse.GeneratedSamples
		if len(samples) == 0 || samples[0].Video.URI == "" {
			return "", nil, fmt.Errorf("veo: sem vídeo no resultado")
		}
		uri := samples[0].Video.URI
		// O header de auth é necessário pra baixar a URI (validade ~2 dias).
		return uri, map[string]string{veoAuthHeader: c.googleKey}, nil
	}
	return "", nil, fmt.Errorf("veo: timeout após %d polls", maxPolls)
}
