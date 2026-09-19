package video

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"log"
	"math/rand/v2"
	"mime/multipart"
	"net/http"
	"net/url"
	"strings"
	"time"

	"github.com/redfoxcode/reachyn/engine/internal/gerr"
	"github.com/redfoxcode/reachyn/engine/internal/identity"
)

// ComfyUI como motor de PRÉVIA DE MOVIMENTO (Fase 2 do docs/ESTUDIO-3D.md).
//
// PRA QUE EXISTE: ver se a cena se move como você quer ANTES de pagar o clipe de verdade. No
// piloto "O Sinal na Colina" (2026-07-29) o clipe do clímax voltou com um robô branco que não
// existia e uma segunda cabeça de dachshund: 25cr pra refazer, mais 50cr de cenas mortas. Um
// rascunho local que mostrasse isso antes teria pago por si.
//
// MODELO: Wan 2.2 TI2V 5B, escolhido por medição e não por catálogo (bench de 2026-07-29 na
// mesma GPU, mesmo quadro e mesmo prompt). O LTX-Video 2B é 11× mais rápido e INÚTIL: devolvia
// a imagem de entrada quase parada — 2 frames únicos de 97 — e, quando se forçava movimento,
// destruía a identidade.
//
// ⚠️ A RÉGUA DAQUELE BENCH NÃO BASTA. Ele mediu frames ÚNICOS (`ffmpeg -vf mpdecimate`), que
// respondem "mudou?" e não "a mudança faz sentido?". Uma cena que DERRETE marca 100% nessa
// métrica — todo quadro difere do anterior porque está mudando de forma. Foi o que aconteceu na
// primeira prévia que rodou ponta a ponta (2026-07-30): 121 de 121 frames únicos, e a antena do
// plano virou um mastro liso no meio do clipe. Rascunho só serve se a cena chegar inteira ao
// fim; por isso os parâmetros abaixo (960×544, cfg 3.5) foram medidos OLHANDO os quadros.
//
// CUSTO REAL: o servidor é o ComfyUI do operador (Colab/host), então não há crédito de API —
// mas há TEMPO. Medido no Colab L4: ~380 s para os 3 s do rascunho em 960×544. É rascunho, não
// entrega: sai pequeno, mudo e em webm.
//
// O grafo abaixo é o mesmo de scripts/comfy/previa_bench.py — se mudar um, mude o outro, senão
// a bancada deixa de medir o que o produto roda.

// previaCkpt — nomes dos arquivos como o ComfyUI os enxerga (models/…, ligados ao Drive pelo
// colab_bootstrap.py). Trocar de modelo aqui é trocar de motor: mantenha o trio coerente.
const (
	previaUNet = "wan2.2_ti2v_5B_fp16.safetensors"
	previaVAE  = "wan2.2_vae.safetensors"
	previaCLIP = "umt5_xxl_fp8_e4m3fn_scaled.safetensors"
)

// Receita do Wan 2.2. steps/cfg NÃO são comparáveis entre arquiteturas — estes são os que
// funcionaram no bench. O `shift` no sampling não é enfeite: sem ele o Wan 2.2 sai borrado.
//
// cfg 3.5, e não os 5.0 do bench: com 5.0 a cena DERRETIA ao longo do clipe. Medido em
// 2026-07-30 na mesma imagem (o mastro de antena do piloto): aos 3 s as hastes da yagi tinham
// sumido, o farol mudado de forma e a cor virado sépia. Com 3.5 (e a resolução abaixo) a antena
// chega inteira ao fim. Ver §6.2 do docs/ESTUDIO-3D.md.
const (
	previaSteps = 20
	previaCFG   = 3.5
	previaShift = 8.0
	previaFPS   = 24
	// A prévia gera 3 s SEMPRE, e a interface diz isso no botão — não é truncar escondido.
	// Motivo: o custo cresce com quadro × pixel, e o que se confere num rascunho ("a cena se
	// mexe como eu quero?") aparece nos primeiros segundos. 3 s a 960×544 levam ~6 min na L4;
	// os 5 s pedidos passariam de 10 min por rascunho, o que ninguém espera antes de pagar.
	previaSegundos = 3
)

// Negativo-BASE da prévia — previaGraph apensa na frente dele os termos de
// identity.SexNegative derivados do prompt (personagem fêmea → nega anatomia de macho, e
// vice-versa). É o mesmo remédio do motor de imagem, e aqui ele importa AINDA MAIS: no i2v
// o prior completa os ângulos que a âncora não mostra — foi exatamente onde a MEL (fêmea)
// ganhou anatomia de macho (draft 84, frame t=34s). O prompt da prévia chega com a ficha
// IDENTITY LOCK recolada (ou ao menos os pronomes da ação), então a derivação tem sinal.
const previaNegative = "blurry, distorted, extra animals, extra characters, morphing, watermark, text"

// comfyVideoHTTP — teto alto de propósito, e maior que o da imagem: 280 s medidos para 5 s de
// vídeo, mais a fila SERIAL do ComfyUI (a prévia pode esperar uma geração de imagem terminar).
var comfyVideoHTTP = &http.Client{Timeout: 1800 * time.Second}

// WithComfy habilita a prévia de movimento LOCAL como provider "comfy" no roteamento de vídeo.
// baseURL vazio = desligado (o motor some do caminho e a escolha vira erro claro). Fluent.
func (c *Client) WithComfy(baseURL string) *Client {
	c.comfyURL = strings.TrimRight(strings.TrimSpace(baseURL), "/")

	return c
}

// previaSize — dimensões do rascunho por formato.
//
// 960×544, e não os 768×448 do bench: abaixo disso o Wan 2.2 (treinado em 720p) perde a cena.
// Medido em 2026-07-30 com a mesma imagem e o mesmo prompt — em 768×448 a antena do plano ia
// derretendo até virar um mastro liso; em 960×544 ela chega inteira ao fim do clipe. O bench de
// 2026-07-29 não pegou isso porque media frames ÚNICOS (`mpdecimate`), e uma cena que derrete
// marca 100% nessa régua: todo quadro é diferente do anterior justamente porque está mudando de
// forma. Frames únicos respondem "mudou?", não "a mudança faz sentido?".
//
// Continua abaixo do nativo de propósito (tempo cresce com a área) — é o piso que ainda segura
// a identidade, não a melhor qualidade possível.
func previaSize(aspect string) (w, h int) {
	switch aspect {
	case "9:16":
		return 544, 960
	case "1:1":
		return 736, 736 // múltiplo de 32 e acima do piso de área; 704² já ficava abaixo
	default: // 16:9 e qualquer coisa que chegue torta
		return 960, 544
	}
}

// previaFrames — quantos quadros o rascunho tem. FIXO em 3 s (o Wan trabalha em 4n+1 → 73).
//
// A duração pedida na tela vale pro CLIPE PAGO, não pro rascunho: aqui ela é ignorada de
// propósito, e a interface anuncia "3 s" no próprio botão pra ninguém achar que viu a cena
// inteira. Cap escondido seria bug mudo; cap anunciado é decisão de produto.
func previaFrames() int {
	return previaSegundos*previaFPS + 1
}

// previaGraph — o grafo do ComfyUI em API format. Espelha previa_bench.py (modelo 'wan22').
func previaGraph(prompt, aspect, imageName string, seed int64) map[string]any {
	w, h := previaSize(aspect)
	frames := previaFrames()
	negativo := previaNegative
	if extra := identity.SexNegative(prompt); extra != "" {
		negativo = extra + ", " + previaNegative
	}

	return map[string]any{
		"clip": map[string]any{"class_type": "CLIPLoader", "inputs": map[string]any{
			"clip_name": previaCLIP, "type": "wan", "device": "default"}},
		"pos": map[string]any{"class_type": "CLIPTextEncode", "inputs": map[string]any{
			"text": prompt, "clip": []any{"clip", 0}}},
		"neg": map[string]any{"class_type": "CLIPTextEncode", "inputs": map[string]any{
			"text": negativo, "clip": []any{"clip", 0}}},
		"img": map[string]any{"class_type": "LoadImage", "inputs": map[string]any{
			"image": imageName}},
		"unet": map[string]any{"class_type": "UNETLoader", "inputs": map[string]any{
			"unet_name": previaUNet, "weight_dtype": "default"}},
		"vae": map[string]any{"class_type": "VAELoader", "inputs": map[string]any{
			"vae_name": previaVAE}},
		"ms": map[string]any{"class_type": "ModelSamplingSD3", "inputs": map[string]any{
			"model": []any{"unet", 0}, "shift": previaShift}},
		"lat": map[string]any{"class_type": "Wan22ImageToVideoLatent", "inputs": map[string]any{
			"vae": []any{"vae", 0}, "width": w, "height": h, "length": frames,
			"batch_size": 1, "start_image": []any{"img", 0}}},
		"ks": map[string]any{"class_type": "KSampler", "inputs": map[string]any{
			"seed": seed, "steps": previaSteps, "cfg": previaCFG, "sampler_name": "euler",
			"scheduler": "normal", "denoise": 1.0, "model": []any{"ms", 0},
			"positive": []any{"pos", 0}, "negative": []any{"neg", 0},
			"latent_image": []any{"lat", 0}}},
		"dec": map[string]any{"class_type": "VAEDecode", "inputs": map[string]any{
			"samples": []any{"ks", 0}, "vae": []any{"vae", 0}}},
		"out": map[string]any{"class_type": "SaveWEBM", "inputs": map[string]any{
			"images": []any{"dec", 0}, "filename_prefix": "previa", "codec": "vp9",
			"fps": float64(previaFPS), "crf": 32.0}},
	}
}

// ComfyPrevia — gera a prévia de movimento e devolve os BYTES (o caller persiste). i2v puro: o
// ComfyUI não busca URL, então a imagem-base é baixada do nosso acervo e subida pro input/ dele.
//
// Sem fallback cross-provider, igual ao motor local de imagem: a prévia é escolha EXPLÍCITA do
// operador. Se o servidor estiver fora, o erro diz isso — trocar por um clipe pago em silêncio
// seria cobrar por algo que o usuário pediu de graça.
func (c *Client) ComfyPrevia(ctx context.Context, prompt, aspect, duration, imageURL string, seed int64) ([]byte, string, error) {
	if c.comfyURL == "" {
		return nil, "", gerr.Configf("prévia de movimento: motor local não configurado (COMFY_URL)")
	}
	if strings.TrimSpace(imageURL) == "" {
		// Wan22ImageToVideoLatent precisa do quadro inicial. Sem imagem não há o que animar —
		// e isso é erro de CONFIG (contrato), não falha de rede que valha retry.
		return nil, "", gerr.Configf("prévia de movimento: escolha a imagem que vai virar movimento")
	}
	name, err := c.comfyUploadImage(ctx, imageURL, fmt.Sprintf("previa_%d.png", rand.Int64N(1<<50)))
	if err != nil {
		return nil, "", fmt.Errorf("prévia: imagem base: %w", err)
	}
	if seed <= 0 {
		// Seed novo a cada chamada: o ComfyUI faz cache por grafo — com seed fixo, pedir a mesma
		// prévia de novo devolveria o arquivo anterior sem executar nada.
		seed = rand.Int64N(1 << 62)
	}

	body, _ := json.Marshal(map[string]any{"prompt": previaGraph(prompt, aspect, name, seed)})
	req, _ := http.NewRequestWithContext(ctx, http.MethodPost, c.comfyURL+"/prompt", bytes.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := comfyVideoHTTP.Do(req)
	if err != nil {
		return nil, "", fmt.Errorf("prévia: %w", err)
	}
	defer resp.Body.Close()
	var queued struct {
		PromptID string `json:"prompt_id"`
	}
	raw, _ := io.ReadAll(io.LimitReader(resp.Body, 1<<20))
	if jerr := json.Unmarshal(raw, &queued); jerr != nil || queued.PromptID == "" {
		// O ComfyUI recusa o grafo inteiro quando falta um modelo, e a mensagem dele diz QUAL —
		// vale mais que um "http 400" (foi o que achou o umt5 faltando no bench).
		return nil, "", fmt.Errorf("prévia: %w", naoEhComfy(resp.StatusCode, raw))
	}
	log.Printf("prévia de movimento: enfileirada (%s) — %s, %d quadros", queued.PromptID, aspect, previaFrames())

	filename, subfolder, err := c.comfyWaitMedia(ctx, queued.PromptID)
	if err != nil {
		return nil, "", err
	}

	return c.comfyFetch(ctx, filename, subfolder)
}

// naoEhComfy — traduz uma resposta que NÃO veio do ComfyUI. Quando o servidor está atrás de um
// túnel (Colab/ngrok) e o túnel cai, o proxy responde no lugar dele: uma PÁGINA HTML de erro com
// status 404. O erro que chegava ao operador era "http 404", que aponta pro lugar errado — parece
// endpoint trocado no nosso código quando, na verdade, o motor sumiu do ar (2026-07-30, com o
// Colab desconectado no meio de uma prévia). Aqui a diferença fica explícita.
func naoEhComfy(status int, corpo []byte) error {
	if t := strings.TrimSpace(string(corpo)); strings.HasPrefix(t, "<") {
		return fmt.Errorf("o endereço do motor local respondeu uma página web (http %d), não a API — servidor fora do ar ou túnel expirado; confira o COMFY_URL", status)
	}

	return fmt.Errorf("http %d: %s", status, clipBody(corpo))
}

// comfyUploadImage — baixa a imagem do acervo e sobe pro input/ do ComfyUI (LoadImage só lê
// arquivo que já está no servidor). Nome ÚNICO por chamada: o upload acontece ANTES da fila
// serial, e nome fixo faria duas prévias concorrentes trocarem a base uma da outra.
func (c *Client) comfyUploadImage(ctx context.Context, imageURL, filename string) (string, error) {
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, imageURL, nil)
	if err != nil {
		return "", err
	}
	resp, err := comfyVideoHTTP.Do(req)
	if err != nil {
		return "", err
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return "", fmt.Errorf("download da imagem base: http %d", resp.StatusCode)
	}
	data, err := io.ReadAll(io.LimitReader(resp.Body, 30<<20))
	if err != nil {
		return "", err
	}

	var buf bytes.Buffer
	mw := multipart.NewWriter(&buf)
	part, _ := mw.CreateFormFile("image", filename)
	_, _ = part.Write(data)
	_ = mw.WriteField("overwrite", "true")
	_ = mw.Close()

	up, _ := http.NewRequestWithContext(ctx, http.MethodPost, c.comfyURL+"/upload/image", &buf)
	up.Header.Set("Content-Type", mw.FormDataContentType())
	ur, err := comfyVideoHTTP.Do(up)
	if err != nil {
		return "", err
	}
	defer ur.Body.Close()
	var out struct {
		Name      string `json:"name"`
		Subfolder string `json:"subfolder"`
	}
	corpo, _ := io.ReadAll(io.LimitReader(ur.Body, 1<<20))
	if derr := json.Unmarshal(corpo, &out); derr != nil || out.Name == "" {
		return "", fmt.Errorf("upload da imagem base: %w", naoEhComfy(ur.StatusCode, corpo))
	}
	if out.Subfolder != "" {
		return out.Subfolder + "/" + out.Name, nil
	}

	return out.Name, nil
}

// comfyWaitMedia — espera o job e devolve o arquivo de saída. Olha `images` E `videos` no
// outputs: o nó de saída de vídeo publica num ou noutro conforme a versão do ComfyUI, e olhar
// só um dos dois faz a espera estourar o tempo com o arquivo pronto do outro lado.
func (c *Client) comfyWaitMedia(ctx context.Context, promptID string) (filename, subfolder string, err error) {
	deadline := time.Now().Add(1800 * time.Second)
	for {
		select {
		case <-ctx.Done():
			return "", "", ctx.Err()
		case <-time.After(5 * time.Second):
		}
		if time.Now().After(deadline) {
			return "", "", fmt.Errorf("prévia de movimento: excedeu 30 min (servidor ocupado ou GPU sem fôlego)")
		}
		req, _ := http.NewRequestWithContext(ctx, http.MethodGet, c.comfyURL+"/history/"+url.PathEscape(promptID), nil)
		resp, herr := comfyVideoHTTP.Do(req)
		if herr != nil {
			log.Printf("prévia: poll falhou (%v) — tentando de novo", herr)

			continue
		}
		type arquivo struct {
			Filename  string `json:"filename"`
			Subfolder string `json:"subfolder"`
			Type      string `json:"type"`
		}
		var hist map[string]struct {
			Status struct {
				StatusStr string  `json:"status_str"`
				Messages  [][]any `json:"messages"`
			} `json:"status"`
			Outputs map[string]struct {
				Images []arquivo `json:"images"`
				Videos []arquivo `json:"videos"`
			} `json:"outputs"`
		}
		derr := json.NewDecoder(io.LimitReader(resp.Body, 8<<20)).Decode(&hist)
		resp.Body.Close()
		if derr != nil {
			continue
		}
		entry, ok := hist[promptID]
		if !ok {
			continue // ainda na fila ou executando
		}
		if entry.Status.StatusStr == "error" {
			msg := "erro de execução"
			for _, m := range entry.Status.Messages {
				if len(m) >= 2 && fmt.Sprint(m[0]) == "execution_error" {
					if b, jerr := json.Marshal(m[1]); jerr == nil {
						msg = clipBody(b)
					}
				}
			}

			return "", "", fmt.Errorf("prévia de movimento: %s", msg)
		}
		for _, out := range entry.Outputs {
			for _, a := range append(append([]arquivo{}, out.Videos...), out.Images...) {
				if a.Type == "output" {
					return a.Filename, a.Subfolder, nil
				}
			}
		}
	}
}

// comfyFetch — baixa os bytes do arquivo gerado (GET /view) e devolve a extensão real.
func (c *Client) comfyFetch(ctx context.Context, filename, subfolder string) ([]byte, string, error) {
	q := url.Values{"filename": {filename}, "subfolder": {subfolder}, "type": {"output"}}
	req, _ := http.NewRequestWithContext(ctx, http.MethodGet, c.comfyURL+"/view?"+q.Encode(), nil)
	resp, err := comfyVideoHTTP.Do(req)
	if err != nil {
		return nil, "", fmt.Errorf("prévia view: %w", err)
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return nil, "", fmt.Errorf("prévia view: http %d", resp.StatusCode)
	}
	// 120 MB: 10 s a 24 fps em vp9 cabe MUITO abaixo disso, mas o crf é ajustável e um rascunho
	// truncado no meio é pior que um download grande.
	data, err := io.ReadAll(io.LimitReader(resp.Body, 120<<20))
	if err != nil || len(data) == 0 {
		return nil, "", fmt.Errorf("prévia view: resposta vazia")
	}
	ext := "webm"
	if i := strings.LastIndex(filename, "."); i >= 0 && i < len(filename)-1 {
		ext = strings.ToLower(filename[i+1:])
	}

	return data, ext, nil
}
