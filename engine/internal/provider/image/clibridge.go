// clibridge.go — geração de imagem via reachyn-cli-bridge (sidecar systemd no HOST da
// VPS que executa CLIs de imagem — P0: mmx/MiniMax com a conta OAuth de assinatura,
// que a API key do engine não cobre). O bridge devolve a imagem em BASE64 (sem URL:
// o safe_fetch do ffmpeg-service bloqueia IP privado por anti-SSRF); o caller persiste
// os bytes no Scality via media.PersistBytes. Plano: docs/PLANO-CLI-IMAGE-BRIDGE.md.
package image

import (
	"bytes"
	"context"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"io"
	"log"
	"net/http"

	"github.com/redfoxcode/reachyn/engine/internal/provider/bridgealvo"
	"time"
)

// WithCliBridge — liga o adapter do CLI Bridge (mesmo padrão do llm.WithCliBridge /
// video.WithMinimax). URL vazia = bridge desligado (modelos cli-* falham com erro claro).
func (c *Client) WithCliBridge(baseURL, token string) *Client {
	c.bridgeURL = baseURL
	c.bridgeToken = token
	return c
}

// WithCliBridgeMac — 2º sidecar, no Mac (adapter prefixado "mac:"). Ver bridgealvo.
func (c *Client) WithCliBridgeMac(baseURL, token string) *Client {
	c.bridgeMac = bridgealvo.Alvo{URL: baseURL, Token: token}
	return c
}

// CliImage — gera via CLI no host. cliProvider = adapter no bridge: "mmx" (t2i + subject-ref)
// ou "cursor" (t2i apenas — o bridge recusa ref_urls). "agy" só refina, não gera.
// Retorna os BYTES da imagem + ext real (sniff de magic bytes no bridge — a CLI mente na
// extensão). Sem fallback cross-provider aqui: o cliente escolheu esse motor explicitamente.
func (c *Client) CliImage(ctx context.Context, cliProvider, prompt, aspect, style string, refURLs []string) ([]byte, string, error) {
	alvo, cliProvider := bridgealvo.Resolve(cliProvider,
		bridgealvo.Alvo{URL: c.bridgeURL, Token: c.bridgeToken}, c.bridgeMac)
	if !alvo.Configurado() {
		return nil, "", fmt.Errorf("cli-bridge: não configurado (CLI_BRIDGE_URL)")
	}
	// Sinais de qualidade anexados a TODA imagem do mmx. Sem eles o motor entrega liso: a
	// descrição do assunto sozinha não pede nitidez, lente, luz nem textura, e o resultado sai
	// genérico — validado no uso real (o mesmo pedido COM esses sinais entrega bem).
	//
	// Versão COMPACTA de propósito. A persona "Base: Qualidade Visual" da biblioteca tem ~700
	// caracteres e o mmx tem teto REAL de 1500 (MiniMax image-01 por baixo): colar ela inteira
	// consumiria metade do orçamento e o smartClamp cortaria justamente a descrição do assunto —
	// pioraria em vez de melhorar. Aqui ficam 2 a 4 sinais de CAMADAS DIFERENTES (nitidez, lente,
	// luz, textura), que é exatamente a receita que a persona prescreve.
	const qualityBoost = " Ultra-detailed, tack-sharp focus, shallow depth of field, motivated cinematic lighting, subtle 35mm film grain."
	if cliProvider == "mmx" {
		// mmx = MiniMax image-01 por baixo → mesmos limites de moderação e de tamanho de
		// prompt da API direta (ver minimax.go): tira as frases sensíveis/filler e clampa
		// pro teto real (1500 - wrapper do estilo - os sinais de qualidade).
		prompt = sanitizeForMinimax(prompt)
		// O boost entra no ORÇAMENTO: se não descontasse aqui, o prompt final estouraria o teto
		// e quem seria cortado é o fim da string — o boost — anulando a mudança em silêncio.
		maxPrompt := 1500 - WrapLen(style) - len([]rune(qualityBoost)) - 20
		if maxPrompt < 100 {
			maxPrompt = 100
		}
		if len([]rune(prompt)) > maxPrompt {
			var mode string
			prompt, mode = smartClamp(prompt, maxPrompt)
			log.Printf("cli-bridge img: prompt clampado pra %d runas (modo=%s)", len([]rune(prompt)), mode)
		}
	}
	finalPrompt := StyledPrompt(prompt, style)
	if cliProvider == "mmx" {
		finalPrompt += qualityBoost
	}
	payload := map[string]any{
		"provider": cliProvider,
		"prompt":   finalPrompt,
		"aspect":   aspect,
	}
	if len(refURLs) > 0 {
		payload["ref_urls"] = refURLs
	}
	body, _ := json.Marshal(payload)
	req, _ := http.NewRequestWithContext(ctx, http.MethodPost, alvo.URL+"/v1/generate", bytes.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("X-Bridge-Token", alvo.Token)
	// Acima do maior timeout do bridge (cursor = 280s): quem tem que cortar é o bridge,
	// que devolve erro explicando o motivo. Cortar aqui daria um erro de rede opaco.
	httpc := &http.Client{Timeout: 300 * time.Second}
	resp, err := httpc.Do(req)
	if err != nil {
		return nil, "", fmt.Errorf("cli-bridge: %w", err)
	}
	defer resp.Body.Close()
	var out struct {
		OK    bool   `json:"ok"`
		B64   string `json:"b64"`
		Ext   string `json:"ext"`
		Error string `json:"error"`
	}
	if err := json.NewDecoder(io.LimitReader(resp.Body, 40<<20)).Decode(&out); err != nil {
		return nil, "", fmt.Errorf("cli-bridge: resposta inválida (http %d)", resp.StatusCode)
	}
	if !out.OK || out.B64 == "" {
		return nil, "", fmt.Errorf("cli-bridge http %d: %s", resp.StatusCode, clipStr(out.Error, 200))
	}
	data, err := base64.StdEncoding.DecodeString(out.B64)
	if err != nil || len(data) == 0 {
		return nil, "", fmt.Errorf("cli-bridge: base64 inválido")
	}
	if out.Ext == "" {
		out.Ext = "jpg"
	}
	return data, out.Ext, nil
}

// CliPrompt — manda o pedido cru + a persona pra uma CLI do host REESCREVER como prompt
// denso em vocabulário visual (bridge POST /v1/prompt). cliProvider: mmx | cursor | agy.
//
// Diferente do CliImage, aqui a falha NÃO é fatal: o caller cai de volta no prompt
// original (refinar é melhoria, não requisito — não faz sentido perder a geração inteira
// porque o refinador engasgou). Por isso devolve erro pro log, mas o caller ignora.
func (c *Client) CliPrompt(ctx context.Context, cliProvider, persona, prompt, kind string) (string, error) {
	alvo, cliProvider := bridgealvo.Resolve(cliProvider,
		bridgealvo.Alvo{URL: c.bridgeURL, Token: c.bridgeToken}, c.bridgeMac)
	if !alvo.Configurado() {
		return "", fmt.Errorf("cli-bridge: não configurado (CLI_BRIDGE_URL)")
	}
	body, _ := json.Marshal(map[string]any{
		"provider": cliProvider,
		"persona":  persona,
		"prompt":   prompt,
		"kind":     kind,
	})
	req, _ := http.NewRequestWithContext(ctx, http.MethodPost, alvo.URL+"/v1/prompt", bytes.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("X-Bridge-Token", alvo.Token)
	// Teto menor que o da imagem: refinar prompt é texto e leva ~5-15s; se passar disso,
	// não vale segurar a geração esperando.
	httpc := &http.Client{Timeout: 150 * time.Second}
	resp, err := httpc.Do(req)
	if err != nil {
		return "", fmt.Errorf("cli-bridge prompt: %w", err)
	}
	defer resp.Body.Close()
	var out struct {
		OK     bool   `json:"ok"`
		Prompt string `json:"prompt"`
		Error  string `json:"error"`
	}
	if err := json.NewDecoder(io.LimitReader(resp.Body, 1<<20)).Decode(&out); err != nil {
		return "", fmt.Errorf("cli-bridge prompt: resposta inválida (http %d)", resp.StatusCode)
	}
	if !out.OK || out.Prompt == "" {
		return "", fmt.Errorf("cli-bridge prompt http %d: %s", resp.StatusCode, clipStr(out.Error, 200))
	}
	return out.Prompt, nil
}

func clipStr(s string, n int) string {
	if len(s) > n {
		return s[:n]
	}
	return s
}
