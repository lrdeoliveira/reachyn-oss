// minimax.go — geração de imagem (text-to-image) via API direta da MiniMax (image-01).
// A conta MiniMax (mesma chave do LLM) cobre imagem no plano — é a reserva pré-paga do
// caminho de imagem; o principal é a conta de assinatura via cli-bridge (ver clibridge.go).
package image

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"log"
	"net/http"

	"github.com/redfoxcode/reachyn/engine/internal/provider/bridgealvo"
	"strings"
	"time"
)

type Client struct {
	minimaxKey  string          // MiniMax image-01 (text-to-image)
	magnificKey string          // Magnific (API HTTP) — t2i/i2i + upscale/edição por modelo (ver magnific.go)
	bridgeURL   string          // reachyn-cli-bridge no host da VPS (ver clibridge.go) — vazio = desligado
	bridgeToken string          // X-Bridge-Token do bridge
	bridgeMac   bridgealvo.Alvo // 2º sidecar (Mac): CLIs que só existem lá — ver o pacote
	comfyURL    string          // ComfyUI local no host (ver comfy.go) — vazio = desligado
	http        *http.Client
}

func New(minimaxKey string) *Client {
	return &Client{minimaxKey: minimaxKey, http: &http.Client{Timeout: 200 * time.Second}}
}

// aspectMinimax — mapeia o aspecto interno para o aspect_ratio aceito pela MiniMax.
func aspectMinimax(aspect string) string {
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
		return "3:4" // MiniMax não tem 4:5; 3:4 é o retrato suportado mais próximo
	default:
		return "1:1"
	}
}

// MinimaxImage — text-to-image via MiniMax image-01, com direção de estilo (StyledPrompt).
// Retorna a URL (efêmera) da imagem; o caller persiste no S3. Erro real é propagado
// (mascarado a jusante por white-label).
func (c *Client) MinimaxImage(ctx context.Context, prompt, aspect, style string) (string, error) {
	return c.minimaxGenerate(ctx, prompt, aspect, style, "")
}

// MinimaxImageSubject — i2i por SUBJECT REFERENCE no image-01 (doc oficial image-generation-i2i:
// subject_reference=[{type:"character", image_file:<url>}], 1 ref só). A âncora é o ROSTO/sujeito
// da ref — mais fraca que o i2i ancorado do bridge (que ancora a imagem inteira), mas é o
// caminho de identidade da conta MiniMax pré-paga quando o motor principal falha.
func (c *Client) MinimaxImageSubject(ctx context.Context, prompt, aspect, style, subjectURL string) (string, error) {
	return c.minimaxGenerate(ctx, prompt, aspect, style, subjectURL)
}

// minimaxSensitivePhrases — trechos do identity lock (ModelSheetService.php) escritos como
// negative_prompt que a MODERAÇÃO da MiniMax rejeita (code "new_sensitive") mesmo em
// contexto de negação ("do NOT depict..."). Achado testando localmente via mmx-cli (2026-07-17,
// shot 'head' da Mel): motores com `negative_prompt` dedicada aceitam a mesma frase; a MiniMax não
// tem esse campo — a frase vai dentro do prompt principal e a moderação reage à MENÇÃO, não à
// intenção. Removida só no caminho MiniMax; os demais motores recebem o texto original.
//
// minimaxFillerPhrases — boilerplate GENÉRICO do lock (mesmo texto em todo personagem, não diz
// nada sobre COMO essa imagem específica deve sair) que só faz sentido reforçando consistência
// entre VÁRIAS imagens de um lote — irrelevante numa chamada que gera 1 imagem por vez. Remover
// recupera orçamento de caracteres pro que importa: o trecho de câmera/enquadramento, que é
// ÚNICO por shot e fica no FINAL do prompt (ver smartClamp).
var minimaxSensitivePhrases = []string{
	"Clean professional reference shot: do NOT depict any genitalia, keep the underside smooth and neutral. ",
}

var minimaxFillerPhrases = []string{
	"Identical proportions across all scenes; ",
	"Identical colors across all scenes; ",
	"Identical art style across all scenes; ",
	"No design changes; ",
	"100% visual consistency required; ",
}

func sanitizeForMinimax(prompt string) string {
	for _, p := range minimaxSensitivePhrases {
		prompt = strings.ReplaceAll(prompt, p, "")
	}
	for _, p := range minimaxFillerPhrases {
		prompt = strings.ReplaceAll(prompt, p, "")
	}
	return prompt
}

// shotFrameMarker — início do trecho de ENQUADRAMENTO do shot (ModelSheetService.php: `$shot =
// fn (string $view) => $identity.$photo.'Studio catalog reference shot on a plain seamless
// light-gray studio '...`), igual em TODOS os grupos do model sheet (angles/head/poses/
// accessories). É a parte ÚNICA por shot — diz de que ÂNGULO a câmera vê o personagem e reforça
// o fundo liso — o resto do prompt (identity lock) é repetido em TODOS os shots do personagem.
const shotFrameMarker = "Studio catalog reference shot"

// smartClamp — trunca o prompt pro teto de runas SEM cegar o enquadramento. O clamp ingênuo
// (cortar do fim pro início) sempre cortava o trecho de câmera — que fica DEPOIS do identity
// lock, no final do prompt — deixando a instrução "PERFIL/COSTAS/TOPO" de fora. Achado real
// 2026-07-17: a prancha de ângulos da Mel saiu com as 10 células de frente (nenhuma girava),
// porque a instrução de ângulo nunca chegava no modelo. Se o marcador do enquadramento existe,
// preserva TUDO dele em diante (a cauda) e corta só o identity lock (a cabeça, repetido em todo
// shot do personagem — menos crítico perder um pouco de detalhe de cor/traço do que perder o
// ângulo inteiro). Sem marcador (ex: prompt da imagem-base, sem esse scaffold) cai no corte
// ingênuo do fim, como antes.
// smartClamp retorna o prompt cortado + "smart" (achou o marcador, preservou o enquadramento) ou
// "naive-end"/"naive-tail" (não achou, ou a cauda sozinha estourava — corte simples do fim), pra
// dar pra LOGAR qual caminho rodou de verdade (caso real 2026-07-17: sem esse log não dava pra
// saber se o fix estava realmente encontrando o marcador ou caindo silenciosamente no fallback).
func smartClamp(prompt string, maxRunes int) (string, string) {
	r := []rune(prompt)
	if len(r) <= maxRunes {
		return prompt, "noop"
	}
	if idx := strings.Index(prompt, shotFrameMarker); idx >= 0 {
		tail := []rune(prompt[idx:])
		if len(tail) < maxRunes {
			headBudget := maxRunes - len(tail)
			head := r[:idx]
			if len(head) > headBudget {
				head = head[:headBudget]
				if sp := lastSpace(head); sp > headBudget-60 && sp > 0 {
					head = head[:sp]
				}
			}
			return strings.TrimRight(string(head), ".,;— ") + ". " + string(tail), "smart"
		}
		// a cauda sozinha já estoura o teto (ex: estilo com wrapper enorme) — corta a cauda
		// também, mas SEMPRE do FIM dela pra dentro, nunca do início (perderia o ângulo primeiro).
		cut := tail[:maxRunes]
		if sp := lastSpace(cut); sp > maxRunes-60 && sp > 0 {
			cut = cut[:sp]
		}
		return string(cut), "naive-tail"
	}
	// sem o marcador de enquadramento (prompt sem esse scaffold, ex: base) — corte simples do fim.
	cut := r[:maxRunes]
	if sp := lastSpace(cut); sp > maxRunes-100 && sp > 0 {
		cut = cut[:sp]
	}
	return string(cut), "naive-end"
}

func lastSpace(r []rune) int {
	for i := len(r) - 1; i >= 0; i-- {
		if r[i] == ' ' {
			return i
		}
	}
	return -1
}

func (c *Client) minimaxGenerate(ctx context.Context, prompt, aspect, style, subjectURL string) (string, error) {
	if c.minimaxKey == "" {
		return "", fmt.Errorf("minimax: sem chave")
	}
	prompt = sanitizeForMinimax(prompt)
	// MiniMax image-01 REJEITA prompt >= 1500 chars (code 2013 → geração falha). O limite é do
	// PROMPT FINAL (depois do StyledPrompt colar prefixo+sufixo de estilo) — um clamp fixo na
	// parte variável SEM contar o wrapper estourava de novo (caso real 2026-07-17: clamp de 1300
	// + wrapper "anime" de 496 runas = 1796, sempre 422/2013, o fallback nunca gerava nada).
	// Clampa contra o TETO real: 1500 - wrapper do estilo - margem de segurança.
	maxPrompt := 1500 - WrapLen(style) - 20
	if maxPrompt < 100 {
		maxPrompt = 100 // nunca zera o prompt, mesmo com um wrapper hipotético gigante
	}
	if len([]rune(prompt)) > maxPrompt {
		before := len([]rune(prompt))
		var mode string
		prompt, mode = smartClamp(prompt, maxPrompt)
		tailPreview := prompt
		if len([]rune(tailPreview)) > 120 {
			tp := []rune(tailPreview)
			tailPreview = string(tp[len(tp)-120:])
		}
		log.Printf("minimax img: prompt truncado de %d pra %d runas (wrapper %q=%d runas, modo=%s) — fim do prompt: %q",
			before, len([]rune(prompt)), style, WrapLen(style), mode, tailPreview)
	}
	payload := map[string]any{
		"model":           "image-01",
		"prompt":          StyledPrompt(prompt, style),
		"aspect_ratio":    aspectMinimax(aspect),
		"n":               1,
		"response_format": "url",
	}
	if subjectURL != "" {
		payload["subject_reference"] = []map[string]any{{"type": "character", "image_file": subjectURL}}
	}
	body, _ := json.Marshal(payload)
	req, _ := http.NewRequestWithContext(ctx, http.MethodPost, "https://api.minimax.io/v1/image_generation", bytes.NewReader(body))
	req.Header.Set("Authorization", "Bearer "+c.minimaxKey)
	req.Header.Set("Content-Type", "application/json")
	resp, err := c.http.Do(req)
	if err != nil {
		return "", err
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		b, _ := io.ReadAll(io.LimitReader(resp.Body, 400))
		return "", fmt.Errorf("minimax img http %d: %s", resp.StatusCode, string(b))
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
	// A MiniMax responde HTTP 200 mesmo em erro de negócio (status_code != 0).
	if d.BaseResp.StatusCode != 0 {
		return "", fmt.Errorf("minimax img: %s (code %d)", d.BaseResp.StatusMsg, d.BaseResp.StatusCode)
	}
	if len(d.Data.ImageURLs) == 0 || d.Data.ImageURLs[0] == "" {
		return "", fmt.Errorf("minimax img: sem imagem no resultado")
	}
	return d.Data.ImageURLs[0], nil
}
