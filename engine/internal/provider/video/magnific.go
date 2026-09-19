// magnific.go — geração de VÍDEO via API HTTP do Magnific (api.magnific.com). Mesmo contrato
// do lado da imagem (ver provider/image/magnific.go): o modelo vive no PATH, POST devolve
// task_id e o GET de status devolve as URLs em `generated`.
//
//	POST /v1/ai/{modelo}          → {"data":{"task_id":"...","status":"CREATED"}}
//	GET  /v1/ai/{modelo}/{taskID} → {"data":{"status":"COMPLETED","generated":["https://..."]}}
//
// Cobre três papéis que estavam presos ao agregador que saiu:
//   - t2v / i2v (kling, hailuo, wan, seedance)
//   - LIP SYNC — /v1/ai/omni-human-1-5 é o MESMO OmniHuman 1.5 que servia o vid-lipsync.
//     Era o único buraco sem sucessor no Higgsfield quando o agregador saiu.
//
// White-label (#6): erros não citam o provedor.
package video

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

const magnificBase = "https://api.magnific.com/v1/ai"

// MagnificVideoSpec — COMO montar o corpo de um modelo de vídeo do Magnific. Vem do CATÁLOGO
// (gen_models.capabilities.magnific), não daqui: cada endpoint tem params próprios, e um
// modelo novo tem de ser uma linha no catálogo, não um rebuild do engine.
type MagnificVideoSpec struct {
	PromptField   string         `json:"prompt_field"`   // campo do prompt; "" = "prompt"
	AspectField   string         `json:"aspect_field"`   // campo do aspecto; "" = não envia
	DurationField string         `json:"duration_field"` // campo da duração; "" = não envia
	RefsField     string         `json:"refs_field"`     // campo do keyframe/refs (i2v); "" = sem refs
	RefsSingle    bool           `json:"refs_single"`    // aceita UMA imagem só (envia string, não lista)
	ImageField    string         `json:"image_field"`    // lip sync: campo do retrato; "" = "image"
	AudioField    string         `json:"audio_field"`    // lip sync: campo do áudio; "" = "audio"
	Extra         map[string]any `json:"extra"`          // params fixos obrigatórios do modelo
	// AspectMap — tradução do aspecto interno pro enum do endpoint (reserva "*").
	AspectMap map[string]string `json:"aspect_map"`
}

// WithMagnific — liga a API do Magnific no cliente de vídeo. Chave vazia = desligado: o
// modelo falha com erro claro, sem trocar de motor em silêncio.
//
// Cria um http.Client PRÓPRIO porque o do pacote tem Timeout de 30s — suficiente pros
// submits antigos, curto pra um POST de vídeo. Fica num CAMPO, e não numa variável local
// dentro de cada chamada, por dois motivos: o transporte passa a ser configurável (e
// testável), e uma conexão reaproveitada entre polls não paga TLS de novo a cada 4s.
func (c *Client) WithMagnific(key string) *Client {
	c.magnificKey = key
	if c.magnificHTTP == nil {
		c.magnificHTTP = &http.Client{Timeout: 120 * time.Second}
	}
	return c
}

// HasMagnific — a linha do Magnific está configurada?
func (c *Client) HasMagnific() bool { return c.magnificKey != "" }

// MagnificVideo — 1 clipe (i2v quando há keyframe, senão t2v). `model` = último segmento do
// path (ex.: "kling-v2-6-pro"). Devolve a URL efêmera; quem chama persiste.
func (c *Client) MagnificVideo(ctx context.Context, model, prompt, aspect, duration string, imageURLs []string, spec MagnificVideoSpec) (string, error) {
	if c.magnificKey == "" {
		return "", fmt.Errorf("vídeo: motor não configurado")
	}
	if model == "" {
		return "", fmt.Errorf("vídeo: sem model")
	}
	campo := spec.PromptField
	if campo == "" {
		campo = "prompt"
	}
	body := map[string]any{campo: prompt}
	if spec.AspectField != "" {
		body[spec.AspectField] = mapAspectMagnificVideo(aspect, spec.AspectMap)
	}
	if spec.DurationField != "" && strings.TrimSpace(duration) != "" {
		body[spec.DurationField] = duration
	}
	for k, v := range spec.Extra {
		body[k] = v
	}
	if len(imageURLs) > 0 && spec.RefsField != "" {
		if spec.RefsSingle {
			body[spec.RefsField] = imageURLs[0]
		} else {
			body[spec.RefsField] = imageURLs
		}
	}

	return c.magnificRun(ctx, model, body)
}

// MagnificLipSync — clipe com a BOCA sincronizada com a fala: retrato + áudio → vídeo.
// É o sucessor direto do lipsync que rodava pelo agregador (mesmo modelo OmniHuman 1.5).
func (c *Client) MagnificLipSync(ctx context.Context, model, imageURL, audioURL string, spec MagnificVideoSpec) (string, error) {
	if c.magnificKey == "" {
		return "", fmt.Errorf("vídeo: motor não configurado")
	}
	if model == "" {
		return "", fmt.Errorf("vídeo: sem model")
	}
	if strings.TrimSpace(imageURL) == "" || strings.TrimSpace(audioURL) == "" {
		return "", fmt.Errorf("vídeo: fala sincronizada exige retrato e áudio")
	}
	campoImg, campoAud := spec.ImageField, spec.AudioField
	if campoImg == "" {
		campoImg = "image"
	}
	if campoAud == "" {
		campoAud = "audio"
	}
	body := map[string]any{campoImg: imageURL, campoAud: audioURL}
	for k, v := range spec.Extra {
		body[k] = v
	}

	return c.magnificRun(ctx, model, body)
}

// magnificRun — submete e espera. O POST pode já voltar COMPLETED (modelo rápido).
//
// O budget de poll é MAIOR que o da imagem porque vídeo demora: 150×4s ≈ 600s. Quem chama
// é sempre um job async — o caminho síncrono morreria no corte de ~100s do Cloudflare com
// 524, num vídeo que na verdade foi gerado e pago.
func (c *Client) magnificRun(ctx context.Context, model string, body map[string]any) (string, error) {
	taskID, url, err := c.magnificSubmit(ctx, model, body)
	if err != nil {
		return "", err
	}
	if url != "" {
		return url, nil
	}

	return c.magnificPoll(ctx, model, taskID, 150, 4*time.Second)
}

func (c *Client) magnificSubmit(ctx context.Context, model string, body map[string]any) (string, string, error) {
	raw, _ := json.Marshal(body)
	req, _ := http.NewRequestWithContext(ctx, http.MethodPost, magnificBase+"/"+model, bytes.NewReader(raw))
	req.Header.Set("x-magnific-api-key", c.magnificKey)
	req.Header.Set("Content-Type", "application/json")
	resp, err := c.magnificHTTP.Do(req)
	if err != nil {
		return "", "", err
	}
	defer resp.Body.Close()
	if resp.StatusCode >= 400 {
		b, _ := io.ReadAll(io.LimitReader(resp.Body, 400))
		return "", "", fmt.Errorf("vídeo: envio http %d: %s", resp.StatusCode, string(b))
	}
	var d magnificVideoResp
	if err := json.NewDecoder(resp.Body).Decode(&d); err != nil {
		return "", "", err
	}
	if u := d.primeiraURL(); u != "" {
		return d.Data.TaskID, u, nil
	}
	if d.Data.TaskID == "" {
		return "", "", fmt.Errorf("vídeo: envio sem identificador de tarefa")
	}

	return d.Data.TaskID, "", nil
}

func (c *Client) magnificPoll(ctx context.Context, model, taskID string, maxPolls int, interval time.Duration) (string, error) {
	url := magnificBase + "/" + model + "/" + taskID
	for i := 0; i < maxPolls; i++ {
		req, _ := http.NewRequestWithContext(ctx, http.MethodGet, url, nil)
		req.Header.Set("x-magnific-api-key", c.magnificKey)
		if resp, err := c.magnificHTTP.Do(req); err == nil {
			var d magnificVideoResp
			dErr := json.NewDecoder(resp.Body).Decode(&d)
			resp.Body.Close()
			if dErr == nil {
				switch strings.ToUpper(d.Data.Status) {
				case "COMPLETED":
					if u := d.primeiraURL(); u != "" {
						return u, nil
					}

					return "", fmt.Errorf("vídeo: concluído sem resultado")
				case "FAILED", "ERROR":
					return "", fmt.Errorf("vídeo: geração falhou")
				}
			}
		}
		select {
		case <-ctx.Done():
			return "", ctx.Err()
		case <-time.After(interval):
		}
	}

	return "", fmt.Errorf("vídeo: tempo esgotado após %d verificações", maxPolls)
}

type magnificVideoResp struct {
	Data struct {
		TaskID    string   `json:"task_id"`
		Status    string   `json:"status"`
		Generated []string `json:"generated"`
	} `json:"data"`
}

// primeiraURL — 1ª saída não-vazia, ou "". Ver a nota gêmea em image/magnific.go: item vazio
// não conta como resultado, senão persistiríamos uma URL quebrada como se fosse mídia boa.
func (r magnificVideoResp) primeiraURL() string {
	for _, u := range r.Data.Generated {
		if s := strings.TrimSpace(u); s != "" {
			return s
		}
	}

	return ""
}

func mapAspectMagnificVideo(aspect string, m map[string]string) string {
	if len(m) > 0 {
		if v, ok := m[aspect]; ok {
			return v
		}
		if v, ok := m["*"]; ok {
			return v
		}
	}

	return aspect
}
