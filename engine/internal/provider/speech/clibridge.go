// clibridge.go — NARRAÇÃO (TTS) via reachyn-cli-bridge (2026-08-02), o mesmo sidecar no host da
// VPS que já serve imagem e vídeo (ver provider/image/clibridge.go e provider/video/clibridge.go).
// Roda numa conta de ASSINATURA já paga: cada narração aqui não consome crédito pré-pago de API.
//
// Contrato do bridge (tools/cli-bridge/media.go, POST /v1/generate-audio):
//
//	req  {provider, prompt, voice_id, voice_type, variant, timeout_sec}
//	resp {ok, b64, ext, mime, provider, elapsed_ms} | {error}
//
// `provider` = adapter do bridge (job_type FIXO por trás, allowlist por construção): hoje só
// "higgsfield-tts". `prompt` é o TEXTO falado — não há aspect nem ref aqui.
//
// Devolve os BYTES, não uma URL: o áudio nasce no host (IP privado) e o safe_fetch do
// ffmpeg-service bloqueia IP privado de propósito (anti-SSRF), então quem chama precisa
// persistir via media.PersistBytes — mesmo contrato do CliVideo/CliImage.
package speech

import (
	"bytes"
	"context"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"io"
	"net/http"

	"github.com/redfoxcode/reachyn/engine/internal/provider/bridgealvo"
	"time"
)

// WithCliBridge — liga o adapter do CLI Bridge no cliente de voz (mesmo padrão de image/video).
// URL vazia = bridge desligado: os modelos cli-bridge falham com erro claro, sem fallback
// silencioso pra outro motor (trocar a voz que o cliente escolheu em silêncio é pior que falhar).
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

// CliSpeech — sintetiza `text` com a voz `voiceID` pelo motor `variant`, via CLI no host.
// model = adapter do bridge; voiceID vazio = voz default do adapter; variant vazio = motor
// default do adapter. Retorna (bytes, ext).
//
// Sem retry aqui, igual ao vídeo: a chamada roda numa conta de assinatura e retentar sozinho
// gastaria a cota duas vezes por um erro que pode ser de contrato (texto acima do limite de
// caracteres do motor, voice_id fora do formato) — coisas que nenhuma retentativa conserta.
func (c *Client) CliSpeech(ctx context.Context, model, text, voiceID, variant string) ([]byte, string, error) {
	alvo, model := bridgealvo.Resolve(model,
		bridgealvo.Alvo{URL: c.bridgeURL, Token: c.bridgeToken}, c.bridgeMac)
	if !alvo.Configurado() {
		return nil, "", fmt.Errorf("cli-bridge: não configurado (CLI_BRIDGE_URL)")
	}
	payload := map[string]any{"provider": model, "prompt": text}
	if voiceID != "" {
		payload["voice_id"] = voiceID
		payload["voice_type"] = "preset" // catálogo de vozes do provedor; "element" é voz clonada
	}
	if variant != "" {
		payload["variant"] = variant
	}
	body, _ := json.Marshal(payload)
	req, _ := http.NewRequestWithContext(ctx, http.MethodPost, alvo.URL+"/v1/generate-audio", bytes.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("X-Bridge-Token", alvo.Token)
	// Acima do timeout do adapter de áudio do bridge (280s): quem corta é o bridge, que devolve
	// o motivo; cortar aqui daria um erro de rede opaco sobre uma geração que talvez tenha saído.
	httpc := &http.Client{Timeout: 300 * time.Second}
	resp, err := httpc.Do(req)
	if err != nil {
		return nil, "", fmt.Errorf("cli-bridge voz: %w", err)
	}
	defer resp.Body.Close()
	var out struct {
		OK    bool   `json:"ok"`
		B64   string `json:"b64"`
		Ext   string `json:"ext"`
		Error string `json:"error"`
	}
	// Teto de leitura: narração é MP3 de minutos (~1MB/min), 20MB já é muito mais que o real —
	// e o /persist-bytes do ffmpeg-service recusa acima de 25MB de qualquer forma.
	if err := json.NewDecoder(io.LimitReader(resp.Body, 20<<20)).Decode(&out); err != nil {
		return nil, "", fmt.Errorf("cli-bridge voz: resposta inválida (http %d)", resp.StatusCode)
	}
	if !out.OK || out.B64 == "" {
		return nil, "", fmt.Errorf("cli-bridge voz http %d: %s", resp.StatusCode, clip(out.Error, 200))
	}
	data, err := base64.StdEncoding.DecodeString(out.B64)
	if err != nil || len(data) == 0 {
		return nil, "", fmt.Errorf("cli-bridge voz: base64 inválido")
	}
	if out.Ext == "" {
		out.Ext = "mp3"
	}
	return data, out.Ext, nil
}
