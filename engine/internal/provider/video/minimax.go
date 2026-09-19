// minimax.go — VÍDEO Hailuo direto via MiniMax (api.minimax.io), ASSÍNCRONO em 3 passos: submit
// (/v1/video_generation → task_id) → poll (/v1/query/video_generation → status+file_id) → retrieve
// (/v1/files/retrieve → download_url). Diferencial vs o agregador: S2V-01 (consistência de personagem,
// casa com o CHARACTER LOCK das Histórias) e Hailuo direto (sem markup do agregador). Tipo separado
// (não mexe nos demais motores deste pacote). White-label: erros não citam o provedor.
package video

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"strconv"
	"strings"
	"time"

	"github.com/redfoxcode/reachyn/engine/internal/gerr"
)

const minimaxVideoBase = "https://api.minimax.io"

type MinimaxVideoClient struct {
	key  string
	base string
	http *http.Client
}

func NewMinimaxVideo(key, baseURL string) *MinimaxVideoClient {
	base := strings.TrimRight(baseURL, "/")
	if base == "" {
		base = minimaxVideoBase
	}
	return &MinimaxVideoClient{key: key, base: base, http: &http.Client{Timeout: 30 * time.Second}}
}

// HailuoVideo — gera vídeo (async). firstFrame != "" = i2v. subjectImage != "" = S2V-01 (consistência
// de personagem; ignora first_frame e duration/resolution, que o S2V fixa). Retorna a download_url.
func (c *MinimaxVideoClient) HailuoVideo(ctx context.Context, model, prompt, duration, resolution, firstFrame, subjectImage string) (string, error) {
	if c.key == "" {
		return "", fmt.Errorf("vídeo: sem chave")
	}
	if model == "" {
		model = "MiniMax-Hailuo-2.3"
	}
	body := map[string]any{"model": model, "prompt": prompt, "prompt_optimizer": true}
	if subjectImage != "" {
		body["model"] = "S2V-01"
		body["subject_reference"] = []map[string]any{{"type": "character", "image": []string{subjectImage}}}
	} else {
		if duration == "" {
			duration = "6"
		}
		if d, err := strconv.Atoi(duration); err == nil {
			// A API só aceita 6 ou 10 (doc oficial); o Filme trabalha com trechos de 5s → clampa
			// pro degrau válido mais próximo em vez de deixar o provedor rejeitar o task.
			if d <= 6 {
				d = 6
			} else {
				d = 10
			}
			body["duration"] = d
		}
		if resolution == "" {
			resolution = "768P" // 1080P não suporta 10s
		}
		body["resolution"] = resolution
		if firstFrame != "" {
			body["first_frame_image"] = firstFrame // i2v
		}
	}
	taskID, err := c.submit(ctx, body)
	if err != nil {
		return "", err
	}
	fileID, err := c.poll(ctx, taskID, 110, 6*time.Second) // vídeo é lento (~11 min)
	if err != nil {
		return "", err
	}
	return c.retrieve(ctx, fileID)
}

// submit — POST /v1/video_generation → task_id.
func (c *MinimaxVideoClient) submit(ctx context.Context, body map[string]any) (string, error) {
	raw, _ := json.Marshal(body)
	req, _ := http.NewRequestWithContext(ctx, http.MethodPost, c.base+"/v1/video_generation", bytes.NewReader(raw))
	req.Header.Set("Authorization", "Bearer "+c.key)
	req.Header.Set("Content-Type", "application/json")
	resp, err := c.http.Do(req)
	if err != nil {
		return "", err
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		b, _ := io.ReadAll(io.LimitReader(resp.Body, 400))
		err := fmt.Errorf("vídeo createTask http %d: %s", resp.StatusCode, string(b))
		if gerr.IsQuotaBody(resp.StatusCode, string(b)) {
			return "", gerr.WrapQuota(err)
		}
		return "", err
	}
	var s struct {
		TaskID   string `json:"task_id"`
		BaseResp struct {
			StatusCode int    `json:"status_code"`
			StatusMsg  string `json:"status_msg"`
		} `json:"base_resp"`
	}
	if err := json.NewDecoder(resp.Body).Decode(&s); err != nil {
		return "", err
	}
	if s.BaseResp.StatusCode != 0 || s.TaskID == "" {
		err := fmt.Errorf("vídeo: createTask falhou (%d: %s)", s.BaseResp.StatusCode, s.BaseResp.StatusMsg)
		// A recusa por cota vem com HTTP 200 e o motivo só no base_resp (2056: "Token Plan usage
		// limit reached") — foi o que derrubou a RESERVA em 2026-07-22 e travou o projeto 19.
		if gerr.IsQuotaBody(s.BaseResp.StatusCode, s.BaseResp.StatusMsg) {
			return "", gerr.WrapQuota(err)
		}
		return "", err
	}
	return s.TaskID, nil
}

// poll — GET /v1/query/video_generation?task_id= até status final. Success → file_id; Fail → erro.
func (c *MinimaxVideoClient) poll(ctx context.Context, taskID string, maxPolls int, interval time.Duration) (string, error) {
	url := c.base + "/v1/query/video_generation?task_id=" + taskID
	for i := 0; i < maxPolls; i++ {
		req, _ := http.NewRequestWithContext(ctx, http.MethodGet, url, nil)
		req.Header.Set("Authorization", "Bearer "+c.key)
		if resp, err := c.http.Do(req); err == nil {
			var st struct {
				Status string `json:"status"` // Preparing|Queueing|Processing|Success|Fail
				FileID string `json:"file_id"`
			}
			dErr := json.NewDecoder(resp.Body).Decode(&st)
			resp.Body.Close()
			if dErr == nil {
				switch st.Status {
				case "Success":
					if st.FileID == "" {
						return "", fmt.Errorf("vídeo: success sem file_id")
					}
					return st.FileID, nil
				case "Fail":
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
	return "", fmt.Errorf("vídeo: timeout após %d polls", maxPolls)
}

// retrieve — GET /v1/files/retrieve?file_id= → download_url do mp4.
func (c *MinimaxVideoClient) retrieve(ctx context.Context, fileID string) (string, error) {
	req, _ := http.NewRequestWithContext(ctx, http.MethodGet, c.base+"/v1/files/retrieve?file_id="+fileID, nil)
	req.Header.Set("Authorization", "Bearer "+c.key)
	resp, err := c.http.Do(req)
	if err != nil {
		return "", err
	}
	defer resp.Body.Close()
	var s struct {
		File struct {
			DownloadURL string `json:"download_url"`
		} `json:"file"`
	}
	if err := json.NewDecoder(resp.Body).Decode(&s); err != nil {
		return "", err
	}
	if s.File.DownloadURL == "" {
		return "", fmt.Errorf("vídeo: retrieve sem download_url")
	}
	return s.File.DownloadURL, nil
}
