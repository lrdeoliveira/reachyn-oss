// Package video — geração de vídeo. Clipes via sidecar de CLIs no host (ver clibridge.go),
// Magnific (API schema-driven, ver magnific.go), MiniMax Hailuo direto (conta pré-paga, ver
// minimax.go) e ComfyUI local (prévia de movimento, ver comfy.go); Veo premium via operação
// longa do Google Gemini (ver google.go).
// Timeouts longos e explícitos (jobs de vídeo levam minutos) — lição River JobTimeout.
package video

import (
	"context"
	"fmt"
	"net/http"

	"github.com/redfoxcode/reachyn/engine/internal/provider/bridgealvo"
	"time"
)

type Client struct {
	googleKey    string              // chave do provedor de vídeo premium (Veo) — ver google.go
	magnificKey  string              // chave do Magnific (API HTTP: clipe + fala sincronizada — ver magnific.go)
	magnificHTTP *http.Client        // client do Magnific: timeout maior que o do pacote (ver WithMagnific)
	mmx          *MinimaxVideoClient // MiniMax Hailuo DIRETO (conta pré-paga) — habilitado via WithMinimax
	comfyURL     string              // ComfyUI do operador: prévia de movimento LOCAL (ver comfy.go)
	bridgeURL    string              // reachyn-cli-bridge — vídeo via sidecar de CLIs (ver clibridge.go)
	bridgeToken  string
	bridgeMac    bridgealvo.Alvo // 2º sidecar (Mac): CLIs que só existem lá — ver o pacote
	http         *http.Client
}

func New(googleKey string) *Client {
	return &Client{googleKey: googleKey, http: &http.Client{Timeout: 30 * time.Second}}
}

// WithMinimax habilita o clipe via MiniMax Hailuo DIRETO (api.minimax.io) como provider "minimax"
// no roteamento — usa a conta MiniMax PRÉ-PAGA em vez de pagar o agregador por geração. Fluent.
func (c *Client) WithMinimax(key, baseURL string) *Client {
	c.mmx = NewMinimaxVideo(key, baseURL)

	return c
}

// HailuoDirect — clipe via MiniMax Hailuo DIRETO (conta pré-paga), provider "minimax". t2v se
// imageURL=="" ; i2v (first_frame) se preenchido. Hailuo ignora aspect (o i2v segue a imagem);
// duration "6"|"10". Sem MiniMax configurado → erro (o caller decide como seguir).
func (c *Client) HailuoDirect(ctx context.Context, model, prompt, duration, imageURL string) (string, error) {
	if c.mmx == nil {
		return "", fmt.Errorf("vídeo: MiniMax direto indisponível")
	}

	return c.mmx.HailuoVideo(ctx, model, prompt, duration, "768P", imageURL, "")
}
