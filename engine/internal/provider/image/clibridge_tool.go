// clibridge_tool.go — FERRAMENTAS de imagem no bridge: modelos que transformam uma peça que já
// existe em vez de gerar do zero (reiluminar, expandir enquadramento, tirar fundo, upscale).
//
// POR QUE NÃO CABE NO CliImage: aquele caminho é de GERAÇÃO — monta prompt com estilo, aplica
// os sinais de qualidade do mmx, e não tem por onde passar os parâmetros próprios de cada
// ferramenta. Uma reiluminação não tem prompt nenhum: a instrução dela é `light_source=fdl`,
// `brightness=60`, `light_quality=soft` — params que o modelo publica no schema e que o bridge
// valida um a um antes de montar o argv (higgsmodels.go). Forçar isso dentro do CliImage
// significaria carregar um mapa de extras por todo o caminho de geração, que não os usa.
package image

import (
	"bytes"
	"context"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"time"

	"github.com/redfoxcode/reachyn/engine/internal/provider/bridgealvo"
)

// CliTool — roda uma ferramenta do catálogo dinâmico sobre uma imagem de origem.
//
// `jobType` é o job_type puro ("nano_banana_2_relight"); o prefixo "higgsfield:" é acrescentado
// aqui para que o chamador não precise conhecer o contrato do bridge. `params` são os campos
// próprios da ferramenta: o bridge descarta em silêncio o que o modelo não publica ou não
// aceita, então valor inventado não vira crédito gasto num erro do outro lado.
//
// `srcURL` PRECISA apontar para o storage próprio (s3.example.com): o bridge tem allowlist
// de host anti-SSRF e recusa qualquer outro domínio. Devolve os BYTES + a extensão real (o
// bridge farejou os magic bytes), no mesmo contrato do CliImage — o caller persiste com
// media.PersistBytes.
func (c *Client) CliTool(ctx context.Context, jobType, srcURL string, params map[string]string) ([]byte, string, error) {
	alvo, _ := bridgealvo.Resolve("higgsfield",
		bridgealvo.Alvo{URL: c.bridgeURL, Token: c.bridgeToken}, c.bridgeMac)
	if !alvo.Configurado() {
		return nil, "", fmt.Errorf("cli-bridge: não configurado (CLI_BRIDGE_URL)")
	}
	if srcURL == "" {
		return nil, "", fmt.Errorf("cli-bridge: ferramenta sem imagem de origem")
	}
	payload := map[string]any{
		"provider": "higgsfield:" + jobType,
		"ref_urls": []string{srcURL},
	}
	if len(params) > 0 {
		payload["extra"] = params
	}
	body, _ := json.Marshal(payload)
	req, _ := http.NewRequestWithContext(ctx, http.MethodPost, alvo.URL+"/v1/generate", bytes.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("X-Bridge-Token", alvo.Token)
	// Mesma folga do CliImage: acima do teto do bridge (280s), pra que o erro venha explicado
	// por quem sabe o motivo em vez de virar timeout seco de rede.
	resp, err := (&http.Client{Timeout: 300 * time.Second}).Do(req)
	if err != nil {
		return nil, "", fmt.Errorf("cli-bridge tool: %w", err)
	}
	defer resp.Body.Close()
	var out struct {
		OK    bool   `json:"ok"`
		B64   string `json:"b64"`
		Ext   string `json:"ext"`
		Error string `json:"error"`
	}
	// 40 MB: uma reiluminação em alta resolução volta com ~29 MB de PNG (medido), e o base64
	// infla isso em ~4/3 — um limite menor cortaria o corpo no meio e o erro apareceria como
	// "resposta inválida", escondendo que a ferramenta funcionou.
	if err := json.NewDecoder(io.LimitReader(resp.Body, 60<<20)).Decode(&out); err != nil {
		return nil, "", fmt.Errorf("cli-bridge tool: resposta inválida (http %d)", resp.StatusCode)
	}
	if !out.OK || out.B64 == "" {
		return nil, "", fmt.Errorf("cli-bridge tool http %d: %s", resp.StatusCode, clipStr(out.Error, 200))
	}
	data, err := base64.StdEncoding.DecodeString(out.B64)
	if err != nil || len(data) == 0 {
		return nil, "", fmt.Errorf("cli-bridge tool: base64 inválido")
	}
	if out.Ext == "" {
		out.Ext = "png"
	}

	return data, out.Ext, nil
}
