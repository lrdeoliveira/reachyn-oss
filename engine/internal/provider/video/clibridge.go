// clibridge.go — geração de VÍDEO via reachyn-cli-bridge (2026-08-01, mesmo sidecar que já
// serve imagem — ver provider/image/clibridge.go). model = adapter do bridge (job_type FIXO
// da Higgsfield por trás): higgsfield-seedance | higgsfield-kling | higgsfield-veo |
// higgsfield-hailuo. Retorna os BYTES do vídeo (o safe_fetch do ffmpeg-service bloqueia IP
// privado por anti-SSRF, então o caller persiste via media.PersistBytes — mesmo contrato do
// motor "comfy" em clipModelOrdered).
package video

import (
	"bytes"
	"context"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"strings"

	"github.com/redfoxcode/reachyn/engine/internal/provider/bridgealvo"
	"time"
)

// WithCliBridge — liga o adapter do CLI Bridge pro cliente de vídeo (mesmo padrão do
// image.Client.WithCliBridge). URL vazia = bridge desligado (modelos cli-bridge falham
// com erro claro, sem fallback silencioso).
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

// CliVideo — gera 1 clipe via CLI no host (POST /v1/generate-video). refURL vazio = t2v puro;
// preenchido = i2v (quadro inicial). Sem retry aqui de propósito — o caller (clipModelOrdered)
// trata "cli-bridge" fora do wrapper de retry, igual ao "comfy": uma chamada já leva minutos
// (medido 2m58s no cinematic_studio_3_0, 480p/5s), e retentar 3× encostaria no timeout do job
// gastando crédito de assinatura a cada tentativa.
//
// durationSec e resolution são PROPAGADOS (2026-08-02): antes o payload os omitia e o bridge
// caía no default do adapter (5s/720p) — a cena de 10s do Estúdio virava um clipe de 5s sem
// ninguém ser avisado, o modo de falha mais caro (parece que funcionou). Valor 0/"" continua
// significando "usa o default do adapter"; quem valida a faixa aceita pelo MODELO é o bridge
// (min/max do `model get`), então mandar um número torto degrada pro default em vez de explodir.
func (c *Client) CliVideo(ctx context.Context, model, prompt, aspect string, refURL string, durationSec int, resolution string) ([]byte, string, error) {
	alvo, model := bridgealvo.Resolve(model,
		bridgealvo.Alvo{URL: c.bridgeURL, Token: c.bridgeToken}, c.bridgeMac)
	if !alvo.Configurado() {
		return nil, "", fmt.Errorf("cli-bridge: não configurado (CLI_BRIDGE_URL)")
	}
	payload := map[string]any{"provider": model, "prompt": prompt, "aspect": aspect}
	if refURL != "" {
		payload["ref_urls"] = []string{refURL}
	}
	if durationSec > 0 {
		payload["duration_sec"] = durationSec
	}
	if resolution != "" {
		payload["resolution"] = resolution
	}
	body, _ := json.Marshal(payload)
	req, _ := http.NewRequestWithContext(ctx, http.MethodPost, alvo.URL+"/v1/generate-video", bytes.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("X-Bridge-Token", alvo.Token)
	// Acima do maior timeout do bridge (6min por adapter de vídeo): quem corta é o bridge,
	// que devolve erro explicando o motivo — cortar aqui daria um erro de rede opaco.
	httpc := &http.Client{Timeout: 400 * time.Second}
	resp, err := httpc.Do(req)
	if err != nil {
		return nil, "", fmt.Errorf("cli-bridge vídeo: %w", err)
	}
	defer resp.Body.Close()
	var out struct {
		OK    bool   `json:"ok"`
		B64   string `json:"b64"`
		Ext   string `json:"ext"`
		Error string `json:"error"`
	}
	if err := json.NewDecoder(io.LimitReader(resp.Body, 60<<20)).Decode(&out); err != nil {
		return nil, "", fmt.Errorf("cli-bridge vídeo: resposta inválida (http %d)", resp.StatusCode)
	}
	if !out.OK || out.B64 == "" {
		return nil, "", fmt.Errorf("cli-bridge vídeo http %d: %s", resp.StatusCode, clipStrVideo(out.Error, 200))
	}
	data, err := base64.StdEncoding.DecodeString(out.B64)
	if err != nil || len(data) == 0 {
		return nil, "", fmt.Errorf("cli-bridge vídeo: base64 inválido")
	}
	if out.Ext == "" {
		out.Ext = "mp4"
	}
	return data, out.Ext, nil
}

func clipStrVideo(s string, n int) string {
	if len(s) > n {
		return s[:n]
	}
	return s
}

// CreditosDoBridge — quanto crédito resta na conta do provedor, pelo /health do sidecar.
//
// Existe pro PRÉ-VOO: uma peça multi-cena gasta 1 imagem + 1 clipe POR cena, e descobrir que a
// conta zerou na 4ª cena significa ter pago as três primeiras por uma peça que sai pela metade.
// Foi o que aconteceu em 2026-08-04 (3 de 6 cenas). Perguntar antes custa uma chamada HTTP.
//
// (0, false) = não deu pra saber (bridge desligado, health mudo, provedor sem esse dado). Nesse
// caso quem chama SEGUE em frente: bloquear geração por causa de um health que não respondeu
// seria trocar um problema raro por um pior.
func (c *Client) CreditosDoBridge(ctx context.Context) (float64, bool) {
	// O saldo é da conta do SIDECAR DA VPS (é ela que paga as gerações do produto); o alvo do Mac
	// é outra conta e não entra nesta conversa.
	alvo := bridgealvo.Alvo{URL: c.bridgeURL, Token: c.bridgeToken}
	if !alvo.Configurado() {
		return 0, false
	}
	cctx, cancel := context.WithTimeout(ctx, 8*time.Second)
	defer cancel()
	req, err := http.NewRequestWithContext(cctx, http.MethodGet, strings.TrimRight(alvo.URL, "/")+"/health", nil)
	if err != nil {
		return 0, false
	}
	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		return 0, false
	}
	defer resp.Body.Close()
	var out struct {
		Providers map[string]struct {
			Credits *float64 `json:"credits"`
		} `json:"providers"`
	}
	if json.NewDecoder(io.LimitReader(resp.Body, 1<<20)).Decode(&out) != nil {
		return 0, false
	}
	for _, p := range out.Providers {
		if p.Credits != nil {
			return *p.Credits, true
		}
	}

	return 0, false
}
