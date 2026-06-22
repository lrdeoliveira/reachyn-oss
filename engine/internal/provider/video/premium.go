// google.go — provedor de vídeo premium via API de operação longa.
// Fluxo: POST predictLongRunning → polling do operation até "done" → extrai a URI do vídeo.
// A URI EXIGE o header de auth no GET pra baixar (validade ~2 dias), então PremiumVideo
// devolve também o header que o caller repassa ao ffmpeg-service — assim a chave nunca aparece
// em log, erro ou string visível ao usuário (white-label).
// White-label: base, modelo e nome do header de auth vêm de env/config — nada no código.
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

// PingPremium — valida a chave do vídeo premium SEM gerar vídeo: um GET no modelo.
// 200 = chave válida e com acesso ao modelo; != 200 → erro (chave/permissão/saldo).
func (c *Client) PingPremium(ctx context.Context) error {
	if c.premiumKey == "" {
		return fmt.Errorf("sem chave")
	}
	if c.premiumBase == "" || c.premiumModel == "" || c.premiumAuthHeader == "" {
		return fmt.Errorf("vídeo premium não configurado")
	}
	req, _ := http.NewRequestWithContext(ctx, http.MethodGet, fmt.Sprintf("%s/models/%s", c.premiumBase, c.premiumModel), nil)
	req.Header.Set(c.premiumAuthHeader, c.premiumKey)
	resp, err := c.http.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		b, _ := io.ReadAll(io.LimitReader(resp.Body, 200))
		return fmt.Errorf("vídeo premium http %d: %s", resp.StatusCode, string(b))
	}
	return nil
}

// PremiumVideo — gera UM vídeo vertical com áudio integrado via operação longa.
// Retorna a URI do vídeo + o header de auth necessário pra baixá-la. O caller
// (longform) repassa o header ao ffmpeg-service, que persiste a mídia no Scality.
// NUNCA loga nem retorna a chave em mensagens de erro (white-label obrigatório).
func (c *Client) PremiumVideo(ctx context.Context, prompt, aspect string) (string, map[string]string, error) {
	if c.premiumKey == "" || c.premiumBase == "" || c.premiumModel == "" || c.premiumAuthHeader == "" {
		// Sem provedor configurado: não chamamos a API. Erro genérico (mascarado a jusante).
		return "", nil, fmt.Errorf("vídeo premium indisponível")
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
	startURL := fmt.Sprintf("%s/models/%s:predictLongRunning", c.premiumBase, c.premiumModel)
	req, _ := http.NewRequestWithContext(ctx, http.MethodPost, startURL, bytes.NewReader(raw))
	req.Header.Set(c.premiumAuthHeader, c.premiumKey)
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
		log.Printf("vídeo premium: operação não iniciada (status %d): %s", resp.StatusCode, clipBody(body))
		return "", nil, fmt.Errorf("vídeo premium: operação não iniciada")
	}

	// 2) Polling do operation até "done" (intervalo fixo de 10s, ~11 min de teto).
	opURL := c.premiumBase + "/" + started.Name
	const interval = 10 * time.Second
	const maxPolls = 66 // ~11 min
	for i := 0; i < maxPolls; i++ {
		select {
		case <-ctx.Done():
			return "", nil, ctx.Err()
		case <-time.After(interval):
		}
		greq, _ := http.NewRequestWithContext(ctx, http.MethodGet, opURL, nil)
		greq.Header.Set(c.premiumAuthHeader, c.premiumKey)
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
			return "", nil, fmt.Errorf("vídeo premium: geração falhou")
		}
		samples := op.Response.GenerateVideoResponse.GeneratedSamples
		if len(samples) == 0 || samples[0].Video.URI == "" {
			return "", nil, fmt.Errorf("vídeo premium: sem vídeo no resultado")
		}
		uri := samples[0].Video.URI
		// O header de auth é necessário pra baixar a URI (validade ~2 dias).
		return uri, map[string]string{c.premiumAuthHeader: c.premiumKey}, nil
	}
	return "", nil, fmt.Errorf("vídeo premium: timeout após %d polls", maxPolls)
}
