// vision.go — LEITURA de imagem (visão): descreve/analisa uma ou mais imagens e devolve TEXTO.
// Não gera nada; é o que alimenta o juiz da malha 3D, a ficha de personagem e o brief visual do
// carrossel.
//
// Roda na conta MiniMax que o cliente de imagem já tem (chatcompletion_v2, modelo VL), no lugar
// do agregador que saiu em 2026-08-03. As imagens vão INLINE como data URL, e não por link: o
// nosso S3 recusa o download de terceiros (403), então mandar a URL devolvia "não consegui ler a
// imagem" com status 200 — um erro que se disfarça de resposta.
//
// White-label (#6): erros não citam o provedor.
package image

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"log"
	"net/http"
	"strings"
)

// defaultVisionModel — modelo de visão da conta MiniMax. Configurável por chamada (o `model`
// vazio cai aqui) pra não exigir rebuild quando a conta ganhar um modelo melhor.
//
// ⚠️ ERA `MiniMax-VL-01` e a conta NÃO TEM esse modelo: a API respondia
// `2013 unknown model 'MiniMax-VL-01'` desde 12e5ece (2026-08-03), quando saímos do agregador e
// passamos a chamar a MiniMax direto. Como todo chamador da visão trata falha como degradação
// (sem brief, cai no default) e a mensagem é white-label ("a IA está indisponível"), a leitura de
// imagem ficou QUEBRADA EM SILÊNCIO por três dias — brief visual do carrossel, ficha de
// personagem e juiz da malha 3D, todos.
//
// Modelos verificados na conta em 2026-08-06 (sondagem direta): MiniMax-Text-01, MiniMax-M2 e
// MiniMax-M2.7 — nenhum "VL". O Text-01 é multimodal: recebe blocos `image_url` e descreve a
// imagem (conferido com uma foto real de produção, não só com um pixel de teste).
const defaultVisionModel = "MiniMax-Text-01"

const minimaxVisionURL = "https://api.minimax.io/v1/text/chatcompletion_v2"

// VisionDescribe — lê UMA imagem (data URL base64) e responde ao `prompt`.
func (c *Client) VisionDescribe(ctx context.Context, model, prompt, imageDataURL string) (string, error) {
	if strings.TrimSpace(imageDataURL) == "" {
		return "", fmt.Errorf("leitura de imagem: sem imagem")
	}

	return c.VisionDescribeMany(ctx, model, prompt, []string{imageDataURL})
}

// VisionDescribeMany — lê VÁRIAS imagens de uma vez (brief visual do carrossel: até 6 refs).
func (c *Client) VisionDescribeMany(ctx context.Context, model, prompt string, imageDataURLs []string) (string, error) {
	if c.minimaxKey == "" {
		return "", fmt.Errorf("leitura de imagem: motor não configurado")
	}
	var imgs []string
	for _, u := range imageDataURLs {
		if s := strings.TrimSpace(u); s != "" {
			imgs = append(imgs, s)
		}
	}
	if len(imgs) == 0 {
		return "", fmt.Errorf("leitura de imagem: sem imagem")
	}

	conteudo := []map[string]any{{"type": "text", "text": prompt}}
	for _, u := range imgs {
		conteudo = append(conteudo, map[string]any{
			"type": "image_url", "image_url": map[string]string{"url": u},
		})
	}
	body, _ := json.Marshal(map[string]any{
		"model":      or(model, defaultVisionModel),
		"messages":   []map[string]any{{"role": "user", "content": conteudo}},
		"max_tokens": 2048,
	})
	req, _ := http.NewRequestWithContext(ctx, http.MethodPost, minimaxVisionURL, bytes.NewReader(body))
	req.Header.Set("Authorization", "Bearer "+c.minimaxKey)
	req.Header.Set("Content-Type", "application/json")
	resp, err := c.http.Do(req)
	if err != nil {
		return "", err
	}
	defer resp.Body.Close()
	raw, _ := io.ReadAll(io.LimitReader(resp.Body, 4<<20))
	if resp.StatusCode >= 400 {
		return "", fmt.Errorf("leitura de imagem: http %d", resp.StatusCode)
	}
	var d struct {
		BaseResp struct {
			StatusCode int    `json:"status_code"`
			StatusMsg  string `json:"status_msg"`
		} `json:"base_resp"`
		Choices []struct {
			Message struct {
				Content string `json:"content"`
			} `json:"message"`
		} `json:"choices"`
	}
	if err := json.Unmarshal(raw, &d); err != nil {
		return "", err
	}
	// Erro de aplicação embutido num HTTP 200 — sem esta checagem, uma recusa de moderação
	// viraria "resposta vazia" e o caller trataria como imagem ilegível.
	if sc := d.BaseResp.StatusCode; sc != 0 && sc != 200 {
		// Server-side only (white-label): a maioria dos chamadores ENGOLE o erro da visão e cai
		// no default — foi assim que um `unknown model` sobreviveu três dias em produção sem
		// deixar rastro. O log com a mensagem CRUA é o que torna a próxima quebra visível na
		// primeira hora; ele nunca chega ao browser.
		log.Printf("visão: recusa do provedor (modelo=%q, code=%d): %s", or(model, defaultVisionModel), sc, d.BaseResp.StatusMsg)

		return "", fmt.Errorf("leitura de imagem: erro %d", sc)
	}
	if len(d.Choices) == 0 {
		return "", fmt.Errorf("leitura de imagem: resposta vazia")
	}
	out := strings.TrimSpace(d.Choices[0].Message.Content)
	if out == "" {
		return "", fmt.Errorf("leitura de imagem: resposta vazia")
	}

	return out, nil
}

// or — override não-vazio, senão o default.
func or(override, def string) string {
	if override != "" {
		return override
	}

	return def
}
