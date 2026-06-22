// image_primary.go — geração de imagem (text-to-image) via provedor de imagem primário.
// Usado como provedor PRIMÁRIO do t2i; o gerador de imagem alternativo fica como fallback.
// White-label: base e modelo vêm de env/config — nada de URL ou nome de modelo no código.
package image

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
)

// aspectPrimary — mapeia o aspecto interno para o aspect_ratio aceito pelo provedor primário.
func aspectPrimary(aspect string) string {
	switch aspect {
	case "9:16":
		return "9:16"
	case "16:9":
		return "16:9"
	case "3:4":
		return "3:4"
	case "4:3":
		return "4:3"
	case "4:5":
		return "3:4" // provedor não tem 4:5; 3:4 é o retrato suportado mais próximo
	default:
		return "1:1"
	}
}

// ImagePrimary — text-to-image via provedor de imagem primário. Retorna a URL (efêmera)
// da imagem; o caller persiste no S3. Erro real é propagado (mascarado a jusante por white-label).
func (c *Client) ImagePrimary(ctx context.Context, prompt, aspect string) (string, error) {
	if c.primaryKey == "" {
		return "", fmt.Errorf("imagem: sem chave")
	}
	if c.primaryBase == "" || c.primaryModel == "" {
		return "", fmt.Errorf("imagem: base/modelo não configurados")
	}
	body, _ := json.Marshal(map[string]any{
		"model":           c.primaryModel,
		"prompt":          prompt,
		"aspect_ratio":    aspectPrimary(aspect),
		"n":               1,
		"response_format": "url",
	})
	req, _ := http.NewRequestWithContext(ctx, http.MethodPost, c.primaryBase+"/v1/image_generation", bytes.NewReader(body))
	req.Header.Set("Authorization", "Bearer "+c.primaryKey)
	req.Header.Set("Content-Type", "application/json")
	resp, err := c.http.Do(req)
	if err != nil {
		return "", err
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		b, _ := io.ReadAll(io.LimitReader(resp.Body, 400))
		return "", fmt.Errorf("img primária http %d: %s", resp.StatusCode, string(b))
	}
	var d struct {
		Data struct {
			ImageURLs []string `json:"image_urls"`
		} `json:"data"`
		BaseResp struct {
			StatusCode int    `json:"status_code"`
			StatusMsg  string `json:"status_msg"`
		} `json:"base_resp"`
	}
	if err := json.NewDecoder(resp.Body).Decode(&d); err != nil {
		return "", err
	}
	// O provedor responde HTTP 200 mesmo em erro de negócio (status_code != 0).
	if d.BaseResp.StatusCode != 0 {
		return "", fmt.Errorf("img primária: %s (code %d)", d.BaseResp.StatusMsg, d.BaseResp.StatusCode)
	}
	if len(d.Data.ImageURLs) == 0 || d.Data.ImageURLs[0] == "" {
		return "", fmt.Errorf("img primária: sem imagem no resultado")
	}
	return d.Data.ImageURLs[0], nil
}
