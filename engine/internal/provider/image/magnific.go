// magnific.go — geração e edição de imagem via API HTTP do Magnific (api.magnific.com).
// Entra no lugar do agregador que saiu (decisão Luciano 2026-08-03), ao lado do Higgsfield
// (assinatura, via CLI Bridge) e do mmx.
//
// Contrato da API, e ele é o motivo do desenho deste arquivo:
//
//	POST /v1/ai/{modelo}          → {"data":{"task_id":"...","status":"CREATED","generated":[]}}
//	GET  /v1/ai/{modelo}/{taskID} → {"data":{"status":"COMPLETED","generated":["https://..."]}}
//	header x-magnific-api-key
//
// O MODELO VIVE NO PATH, não num campo do corpo — cada modelo é um endpoint próprio
// (/v1/ai/mystic, /v1/ai/flux-2-pro, /v1/ai/image-upscaler-creative). E cada endpoint tem o
// seu próprio conjunto de params. Por isso o mesmo padrão schema-driven que o catálogo já
// usava: o formato do input vem do CATÁLOGO (gen_models.capabilities.magnific), não daqui.
// Modelo novo = uma linha no catálogo, sem rebuild do engine.
//
// White-label (#6): erros não citam o provedor.
package image

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

// MagnificSpec — COMO montar o corpo de um modelo Magnific específico. Mesmo papel do
// spec do agregador anterior: a API não padroniza os params entre endpoints (mystic usa
// `aspect_ratio`, o upscaler usa `image` + `scale_factor`, o flux aceita `reference_images`).
type MagnificSpec struct {
	PromptField string         `json:"prompt_field"` // campo do prompt; "" = "prompt"
	AspectField string         `json:"aspect_field"` // campo do aspecto; "" = não envia
	RefsField   string         `json:"refs_field"`   // campo das refs i2i; "" = sem refs
	RefsSingle  bool           `json:"refs_single"`  // o modelo aceita UMA ref só (envia string, não lista)
	ImageField  string         `json:"image_field"`  // campo da imagem de ORIGEM (edição/upscale); "" = "image"
	Extra       map[string]any `json:"extra"`        // params fixos obrigatórios do modelo
	// AspectMap — tradução do aspecto INTERNO ("9:16") pro enum do modelo, quando ele não
	// aceita "W:H" cru. Chave "*" = reserva. Vazio = envia o aspecto interno.
	AspectMap map[string]string `json:"aspect_map"`
}

// WithMagnific — liga a API do Magnific no cliente de imagem. Chave vazia = desligado: os
// modelos magnific falham com erro claro, sem cair noutro motor em silêncio (trocar o modelo
// que o cliente ESCOLHEU e ainda cobrar por ele é o pior desfecho).
func (c *Client) WithMagnific(key string) *Client {
	c.magnificKey = key
	return c
}

// HasMagnific — a linha do Magnific está configurada?
func (c *Client) HasMagnific() bool { return c.magnificKey != "" }

// MagnificImage — gera imagem: POST no endpoint do modelo + poll. `model` = último segmento
// do path (ex.: "mystic", "flux-2-pro"). imageURLs não-vazio + RefsField preenchido = i2i.
// Devolve a URL efêmera do provedor; quem chama persiste no S3.
func (c *Client) MagnificImage(ctx context.Context, model, prompt, aspect, style string, imageURLs []string, spec MagnificSpec) (string, error) {
	if c.magnificKey == "" {
		return "", fmt.Errorf("imagem: motor não configurado")
	}
	if model == "" {
		return "", fmt.Errorf("imagem: sem model")
	}
	campo := spec.PromptField
	if campo == "" {
		campo = "prompt"
	}
	body := map[string]any{campo: StyledPrompt(prompt, style)}
	if spec.AspectField != "" {
		body[spec.AspectField] = mapAspectMagnific(aspect, spec.AspectMap)
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

// MagnificEdit — pós-processa UMA imagem (upscale, remover fundo, relight): a imagem de
// origem entra no campo que ESTE endpoint espera. Sem prompt nem aspecto.
func (c *Client) MagnificEdit(ctx context.Context, model, imageURL string, spec MagnificSpec) (string, error) {
	if c.magnificKey == "" {
		return "", fmt.Errorf("imagem: motor não configurado")
	}
	if model == "" {
		return "", fmt.Errorf("imagem: sem model")
	}
	if strings.TrimSpace(imageURL) == "" {
		return "", fmt.Errorf("imagem: sem imagem de origem")
	}
	campo := spec.ImageField
	if campo == "" {
		campo = "image"
	}
	body := map[string]any{campo: imageURL}
	for k, v := range spec.Extra {
		body[k] = v
	}

	return c.magnificRun(ctx, model, body)
}

// PingMagnific — valida a chave SEM gerar mídia paga: um GET de status num task_id que não
// existe. Auth ruim devolve 401/403; qualquer outra resposta significa que a chave autentica
// (a tarefa é que não existe). Usado no botão "testar chave" do admin — mesma régua do
// ping de chave dos outros motores, e o motivo é o mesmo: testar chave não pode custar
// uma geração.
func (c *Client) PingMagnific(ctx context.Context) error {
	if c.magnificKey == "" {
		return fmt.Errorf("magnific: sem chave")
	}
	req, _ := http.NewRequestWithContext(ctx, http.MethodGet, magnificBase+"/mystic/ping", nil)
	req.Header.Set("x-magnific-api-key", c.magnificKey)
	resp, err := c.http.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	if resp.StatusCode == http.StatusUnauthorized || resp.StatusCode == http.StatusForbidden {
		return fmt.Errorf("magnific: chave inválida (http %d)", resp.StatusCode)
	}

	return nil
}

// magnificRun — submete e espera. O POST às vezes já volta COMPLETED (modelo rápido, tipo o
// z-image); nesse caso não há poll nenhum e a URL sai na hora.
func (c *Client) magnificRun(ctx context.Context, model string, body map[string]any) (string, error) {
	taskID, url, err := c.magnificSubmit(ctx, model, body)
	if err != nil {
		return "", err
	}
	if url != "" {
		return url, nil
	}
	// Budget ABAIXO do timeout (360s) dos jobs async, deixando margem pro persist —
	// mesma conta do poll do agregador anterior: job cortado depois de gerado é crédito
	// gasto e mídia perdida.
	return c.magnificPoll(ctx, model, taskID, 90, 3*time.Second) // 90×3s ≈ 270s
}

// magnificSubmit — POST /v1/ai/{model}. Devolve (taskID, urlPronta): a URL vem preenchida
// quando o modelo terminou de forma síncrona.
func (c *Client) magnificSubmit(ctx context.Context, model string, body map[string]any) (string, string, error) {
	raw, _ := json.Marshal(body)
	req, _ := http.NewRequestWithContext(ctx, http.MethodPost, magnificBase+"/"+model, bytes.NewReader(raw))
	req.Header.Set("x-magnific-api-key", c.magnificKey)
	req.Header.Set("Content-Type", "application/json")
	resp, err := c.http.Do(req)
	if err != nil {
		return "", "", err
	}
	defer resp.Body.Close()
	if resp.StatusCode >= 400 {
		b, _ := io.ReadAll(io.LimitReader(resp.Body, 400))
		return "", "", fmt.Errorf("imagem: envio http %d: %s", resp.StatusCode, string(b))
	}
	var d magnificResp
	if err := json.NewDecoder(resp.Body).Decode(&d); err != nil {
		return "", "", err
	}
	if u := d.primeiraURL(); u != "" {
		return d.Data.TaskID, u, nil
	}
	if d.Data.TaskID == "" {
		return "", "", fmt.Errorf("imagem: envio sem identificador de tarefa")
	}

	return d.Data.TaskID, "", nil
}

// magnificPoll — GET /v1/ai/{model}/{taskID} até o estado final. O 1º poll é IMEDIATO
// (sem espera morta); a espera fica ENTRE os polls.
func (c *Client) magnificPoll(ctx context.Context, model, taskID string, maxPolls int, interval time.Duration) (string, error) {
	url := magnificBase + "/" + model + "/" + taskID
	for i := 0; i < maxPolls; i++ {
		req, _ := http.NewRequestWithContext(ctx, http.MethodGet, url, nil)
		req.Header.Set("x-magnific-api-key", c.magnificKey)
		if resp, err := c.http.Do(req); err == nil {
			var d magnificResp
			dErr := json.NewDecoder(resp.Body).Decode(&d)
			resp.Body.Close()
			if dErr == nil {
				switch strings.ToUpper(d.Data.Status) {
				case "COMPLETED":
					if u := d.primeiraURL(); u != "" {
						return u, nil
					}

					return "", fmt.Errorf("imagem: concluída sem resultado")
				case "FAILED", "ERROR":
					return "", fmt.Errorf("imagem: geração falhou")
				}
			}
		}
		select {
		case <-ctx.Done():
			return "", ctx.Err()
		case <-time.After(interval):
		}
	}

	return "", fmt.Errorf("imagem: tempo esgotado após %d verificações", maxPolls)
}

// magnificResp — envelope comum do POST e do GET de status.
type magnificResp struct {
	Data struct {
		TaskID    string   `json:"task_id"`
		Status    string   `json:"status"` // CREATED | IN_PROGRESS | COMPLETED | FAILED
		Generated []string `json:"generated"`
	} `json:"data"`
}

// primeiraURL — 1ª saída não-vazia, ou "". Item vazio dentro do array não conta como
// resultado: devolver "" pra cima vira "concluída sem resultado", que é um erro honesto,
// em vez de uma URL quebrada persistida como se fosse mídia boa.
func (r magnificResp) primeiraURL() string {
	for _, u := range r.Data.Generated {
		if s := strings.TrimSpace(u); s != "" {
			return s
		}
	}

	return ""
}

// mapAspectMagnific — valor do campo de aspecto conforme o spec. Com AspectMap, traduz o
// aspecto interno pro enum do endpoint (reserva "*"); sem mapa, envia o aspecto cru.
func mapAspectMagnific(aspect string, m map[string]string) string {
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
