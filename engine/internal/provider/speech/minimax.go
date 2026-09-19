// minimax.go — VOZ (TTS) via MiniMax (api.minimax.io/v1/t2a_v2), SÍNCRONO. Multilíngue (PT via
// language_boost), mais barato que o ElevenLabs e com LEGENDA SRT NATIVA word-level (subtitle_enable)
// — casa com a legenda do Reachyn. Convive com o ElevenLabs (que fica p/ voz premium/clonagem).
// output_format=url → link 24h; o caller persiste no S3. White-label: erros não citam o provedor.
package speech

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

const minimaxVoiceBase = "https://api.minimax.io"

// MinimaxClient — cliente de TTS MiniMax (separado do Client ElevenLabs deste pacote).
type MinimaxClient struct {
	key  string
	base string
	http *http.Client
}

// WithMinimax — pendura a RESERVA de voz no cliente principal (mesmo idioma do video.WithMinimax
// e do music.New). Chave vazia = sem reserva: `Minimax()` devolve nil e o chamador segue sem
// fallback, exatamente como antes.
//
// Este construtor existiu ÓRFÃO de 2026-08-01 até 2026-08-07: o cliente estava escrito, testado
// pelo compilador e nunca instanciado — uma reserva de narração que não existia na prática. Foi
// achado na varredura de "função sem chamador" (auditoria 2026-08-07).
func (c *Client) WithMinimax(key, baseURL string) *Client {
	if strings.TrimSpace(key) != "" {
		c.mmx = NewMinimax(key, baseURL)
	}

	return c
}

// Minimax — a reserva de voz, ou nil quando não configurada.
func (c *Client) Minimax() *MinimaxClient { return c.mmx }

func NewMinimax(key, baseURL string) *MinimaxClient {
	base := strings.TrimRight(baseURL, "/")
	if base == "" {
		base = minimaxVoiceBase
	}
	return &MinimaxClient{key: key, base: base, http: &http.Client{Timeout: 120 * time.Second}}
}

// TTS — sintetiza `text` com a voz `voiceID`. wantSRT=true pede a legenda word-level. Retorna a URL
// do áudio e (se pedida) a URL do arquivo de legenda. model vazio = speech-2.8-hd.
func (c *MinimaxClient) TTS(ctx context.Context, model, text, voiceID, lang string, wantSRT bool) (audioURL, subtitleURL string, err error) {
	if c.key == "" {
		return "", "", fmt.Errorf("voz: sem chave")
	}
	if model == "" {
		model = "speech-2.8-hd"
	}
	if voiceID == "" {
		voiceID = "English_expressive_narrator"
	}
	boost := "auto"
	if strings.HasPrefix(strings.ToLower(lang), "pt") {
		boost = "Portuguese"
	}
	body := map[string]any{
		"model":          model,
		"text":           text,
		"output_format":  "url",
		"language_boost": boost,
		"voice_setting":  map[string]any{"voice_id": voiceID, "speed": 1.0, "vol": 1.0, "pitch": 0},
		"audio_setting":  map[string]any{"sample_rate": 32000, "bitrate": 128000, "format": "mp3", "channel": 1},
	}
	if wantSRT {
		body["subtitle_enable"] = true
		body["subtitle_type"] = "word"
	}
	raw, _ := json.Marshal(body)
	req, _ := http.NewRequestWithContext(ctx, http.MethodPost, c.base+"/v1/t2a_v2", bytes.NewReader(raw))
	req.Header.Set("Authorization", "Bearer "+c.key)
	req.Header.Set("Content-Type", "application/json")
	resp, err := c.http.Do(req)
	if err != nil {
		return "", "", err
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		b, _ := io.ReadAll(io.LimitReader(resp.Body, 400))
		return "", "", fmt.Errorf("voz http %d: %s", resp.StatusCode, string(b))
	}
	var s struct {
		Data struct {
			Audio        string `json:"audio"`
			SubtitleFile string `json:"subtitle_file"`
			Status       int    `json:"status"`
		} `json:"data"`
		BaseResp struct {
			StatusCode int    `json:"status_code"`
			StatusMsg  string `json:"status_msg"`
		} `json:"base_resp"`
	}
	if err := json.NewDecoder(resp.Body).Decode(&s); err != nil {
		return "", "", err
	}
	if s.BaseResp.StatusCode != 0 {
		return "", "", fmt.Errorf("voz: síntese falhou (%d: %s)", s.BaseResp.StatusCode, s.BaseResp.StatusMsg)
	}
	if s.Data.Audio == "" {
		return "", "", fmt.Errorf("voz: resposta sem áudio")
	}
	return s.Data.Audio, s.Data.SubtitleFile, nil
}
