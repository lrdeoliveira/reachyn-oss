// Package image — geração de imagem com presets de estilo
// (biblioteca Padrão de Excelência: 95-premium / 54-pixar / 62-anime / 63-comic).
// White-label: endpoints, base e modelo vêm de env/config — nada de URL/nome no código.
package image

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"math/rand"
	"net/http"
	"time"
)

// ImageConfig — endpoints/base/modelo do provedor de imagem (vêm de env/config).
type ImageConfig struct {
	GenURL       string // endpoint de geração (text-to-image, provedor alternativo)
	EditURL      string // endpoint de edição (image-to-image)
	PrimaryBase  string // base do provedor de imagem primário
	PrimaryModel string // modelo do provedor de imagem primário
}

type Client struct {
	genKey       string // chave do gerador de imagem (t2i alternativo + edição i2i)
	primaryKey   string // chave do provedor de imagem primário (t2i)
	genURL       string // endpoint de geração (t2i alternativo)
	editURL      string // endpoint de edição (i2i)
	primaryBase  string // base do provedor de imagem primário
	primaryModel string // modelo do provedor de imagem primário
	http         *http.Client
}

func New(genKey, primaryKey string, cfg ImageConfig) *Client {
	return &Client{
		genKey:       genKey,
		primaryKey:   primaryKey,
		genURL:       cfg.GenURL,
		editURL:      cfg.EditURL,
		primaryBase:  cfg.PrimaryBase,
		primaryModel: cfg.PrimaryModel,
		http:         &http.Client{Timeout: 200 * time.Second}, // cobre geração (~95s) e edição (~180s)
	}
}

// Ping valida a chave do gerador sem gerar mídia: POST com corpo vazio → 422 (auth ok, falta
// prompt) vs 401/403 (chave inválida). A mesma chave cobre imagem e vídeo de fila.
func (c *Client) Ping(ctx context.Context) error {
	if c.genKey == "" {
		return fmt.Errorf("chave vazia")
	}
	if c.genURL == "" {
		return fmt.Errorf("endpoint de imagem não configurado")
	}
	req, _ := http.NewRequestWithContext(ctx, http.MethodPost, c.genURL, bytes.NewReader([]byte("{}")))
	req.Header.Set("Authorization", "Key "+c.genKey)
	req.Header.Set("Content-Type", "application/json")
	resp, err := c.http.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	if resp.StatusCode == http.StatusUnauthorized || resp.StatusCode == http.StatusForbidden {
		return fmt.Errorf("imagem: chave inválida (%d)", resp.StatusCode)
	}
	return nil
}

// Styles — sufixo de direcionamento colado no prompt do assunto. Default "realista" = master 95.
var Styles = map[string]string{
	"realista":    ", cinematic premium commercial look, dramatic soft key light with rim light, professional color grading, rich textures, realistic materials and reflections, sharp focus on subject, shallow depth of field with creamy bokeh, ultra-high detail, 8K quality. Avoid: distorted anatomy, extra limbs, warped or fake text, letters, watermark, logo, visible AI artifacts, plastic look, blurry or noisy areas, banding, oversaturation.",
	"3d":          ", 3D animated blockbuster look with Pixar-like charm, high-quality CGI, appealing character design, expressive emotion, physically-based rendering, ray-traced reflections, global illumination, cinematic key/fill/rim lighting, clean gradients, 4K-8K clarity. Avoid: text, logos, watermarks, uncanny faces, broken anatomy, extra fingers or limbs, low-res textures, banding, cheap plastic look, AI artifacts.",
	"anime":       ", anime illustration, crisp confident outlines with varied line weight, soft cel shading, expressive eyes and clean anatomy, layered detailed background, cinematic framing, harmonized vibrant palette, high resolution, stable facial features. Avoid: text overlays, logos, watermarks, low-res, blurry lines, muddy shading, distorted anatomy, extra fingers or limbs, uncanny faces, AI artifacts.",
	"comic":       ", modern American comic-book illustration (not photoreal), bold clean inks, confident dynamic anatomy, strong silhouette readability, dynamic perspective and cinematic panel framing, rich color separation, detailed costumes, atmospheric effects. Avoid: text, logos, watermarks, distorted anatomy, extra fingers or limbs, muddy colors, low-res textures, banding, visible AI artifacts.",
	"aquarela":    ", delicate watercolor textures, soft pigment bleeds, visible paper grain, organic color blending, light and airy, hand-painted feel. Avoid: text, logos, watermarks, harsh digital edges, photorealism, distorted anatomy, extra fingers, AI artifacts.",
	"cyberpunk":   ", neon magenta and cyan lighting, rain-slick reflections, holographic interfaces, dense futuristic city mood, cinematic high contrast, ultra-detailed. Avoid: text, logos, watermarks, distorted anatomy, extra limbs, muddy colors, banding, AI artifacts.",
	"minimalista": ", simple geometric shapes, limited harmonious palette, flat vector look, generous negative space, modern and clean. Avoid: clutter, text, logos, watermarks, photorealism, noisy gradients, distorted shapes, AI artifacts.",
	"vintage":     ", analog film grain, faded warm tones, retro color grading, soft vignette, nostalgic 70s/80s mood. Avoid: text, logos, watermarks, modern elements, distorted anatomy, extra fingers, AI artifacts.",
	"produto":     ", crisp studio softbox lighting, seamless clean background, sharp focus, premium commercial look, subtle reflections, ultra-high detail, 8K. Avoid: text, logos, watermarks, clutter, distorted shapes, oversaturation, AI artifacts.",
	"pintura":     ", expressive brush strokes, rich layered color, dramatic lighting, concept-art quality, detailed and atmospheric. Avoid: text, logos, watermarks, photorealism, flat lighting, distorted anatomy, extra fingers, AI artifacts.",
}

// StylePrefix — vai no INÍCIO do prompt (peso máximo no difusor). Sem isso, temas
// com forte viés visual (ex: carro futurista) sobrepõem o estilo e tudo vira realista.
var StylePrefix = map[string]string{
	"realista":    "Photorealistic cinematic photograph of ",
	"3d":          "3D animated movie still in Pixar/Disney CGI style, stylized non-photorealistic characters: ",
	"anime":       "2D anime illustration in Japanese manga/anime art style (flat cel shading, not photorealistic, not 3D): ",
	"comic":       "2D American comic-book illustration, bold inked comic art (not photorealistic, not 3D): ",
	"aquarela":    "Watercolor painting illustration, soft washes and bleeds (not photorealistic): ",
	"cyberpunk":   "Cyberpunk neon-lit scene, futuristic high-tech atmosphere: ",
	"minimalista": "Minimalist clean flat-design illustration, lots of negative space (not photorealistic): ",
	"vintage":     "Vintage retro aesthetic, aged analog film look, nostalgic tones: ",
	"produto":     "Professional product photography, studio lighting, clean seamless background: ",
	"pintura":     "Digital painting, painterly brushwork, concept-art style (not photorealistic): ",
}

// size — image_size do gerador de imagem. Aceita preset (string) OU dimensões custom
// ({width,height}). 4:5 não tem preset nomeado → enviamos dimensões exatas (1080×1350).
func size(aspect string) any {
	switch aspect {
	case "9:16":
		return "portrait_16_9"
	case "16:9":
		return "landscape_16_9"
	case "4:5":
		return map[string]int{"width": 1080, "height": 1350}
	default: // 1:1 e qualquer outro
		return "square_hd"
	}
}

// StyledPrompt — monta o prompt com o estilo: prefixo (início, peso máx) + prompt + sufixo de qualidade.
// Reusado por imagem e vídeo para direcionar o estilo visual.
func StyledPrompt(prompt, style string) string {
	suffix, ok := Styles[style]
	if !ok {
		suffix = Styles["realista"]
		style = "realista"
	}
	return StylePrefix[style] + prompt + suffix
}

// ImageGenerate gera 1 imagem (gerador alternativo) e devolve a URL. aspect: 1:1|16:9|9:16;
// style: chave de Styles.
func (c *Client) ImageGenerate(ctx context.Context, prompt, aspect, style string) (string, error) {
	if c.genURL == "" {
		return "", fmt.Errorf("imagem: endpoint de geração não configurado")
	}
	body, _ := json.Marshal(map[string]any{
		"prompt":                StyledPrompt(prompt, style),
		"image_size":            size(aspect),
		"num_inference_steps":   28,
		"num_images":            1,
		"seed":                  rand.Int63n(1_000_000_000), // varia a cada geração (sem isso, repete a mesma imagem)
		"enable_safety_checker": true,
	})
	req, _ := http.NewRequestWithContext(ctx, http.MethodPost, c.genURL, bytes.NewReader(body))
	req.Header.Set("Authorization", "Key "+c.genKey)
	req.Header.Set("Content-Type", "application/json")
	resp, err := c.http.Do(req)
	if err != nil {
		return "", err
	}
	defer resp.Body.Close()
	// Checa o status: sem isso, um erro (ex.: 403 "Exhausted balance") era decodificado num
	// struct vazio e devolvido como url "" — falha silenciosa que aparecia como "indefinido"
	// na UI. Agora propaga o erro real (mascarado a jusante).
	if resp.StatusCode != http.StatusOK {
		b, _ := io.ReadAll(io.LimitReader(resp.Body, 400))
		return "", fmt.Errorf("imagem http %d: %s", resp.StatusCode, string(b))
	}
	var d struct {
		Images []struct {
			URL string `json:"url"`
		} `json:"images"`
	}
	if err := json.NewDecoder(resp.Body).Decode(&d); err != nil {
		return "", err
	}
	if len(d.Images) == 0 || d.Images[0].URL == "" {
		return "", fmt.Errorf("imagem: sem imagem no resultado")
	}
	return d.Images[0].URL, nil
}

// ImageEdit — edita a foto do cliente (templates virais: action figure / funko / etc.).
func (c *Client) ImageEdit(ctx context.Context, prompt string, imageURLs []string) (string, error) {
	if c.editURL == "" {
		return "", fmt.Errorf("imagem: endpoint de edição não configurado")
	}
	body, _ := json.Marshal(map[string]any{"prompt": prompt, "image_urls": imageURLs})
	req, _ := http.NewRequestWithContext(ctx, http.MethodPost, c.editURL, bytes.NewReader(body))
	req.Header.Set("Authorization", "Key "+c.genKey)
	req.Header.Set("Content-Type", "application/json")
	resp, err := c.http.Do(req)
	if err != nil {
		return "", err
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		b, _ := io.ReadAll(io.LimitReader(resp.Body, 400))
		return "", fmt.Errorf("imagem http %d: %s", resp.StatusCode, string(b))
	}
	var d struct {
		Images []struct {
			URL string `json:"url"`
		} `json:"images"`
	}
	if err := json.NewDecoder(resp.Body).Decode(&d); err != nil {
		return "", err
	}
	if len(d.Images) == 0 || d.Images[0].URL == "" {
		return "", fmt.Errorf("imagem: sem imagem no resultado")
	}
	return d.Images[0].URL, nil
}
