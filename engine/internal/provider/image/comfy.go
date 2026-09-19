// comfy.go — geração de imagem via ComfyUI LOCAL (host da máquina, Metal/MPS no Mac M5).
// Plano: docs/ESTUDIO-3D.md. O ganho é CONTROLE, não preço: refino que preserva
// identidade, inpaint com máscara e (futuro, com controle leve) pose exata via âncora de
// ângulo — coisas que os provedores por API não deixam pedir.
//
// Topologia igual ao cli-bridge: o engine roda em container e o ComfyUI no host, alcançado
// por COMFY_URL (host.docker.internal). O resultado volta em BYTES e o caller persiste via
// media.PersistBytes — o safe_fetch bloqueia IP privado de propósito (anti-SSRF), então URL
// interna não serve. Fila: o ComfyUI processa UM job por vez por natureza (a serialização
// que a §7 do plano exige) — aqui só se espera a vez chegar, por isso o teto de 600s.
//
// A allowlist de workflows vive AQUI (comfyGraph), nunca em caminho vindo do cliente
// (baseline #7). Números do KSampler (25 steps, cfg 7, euler) são os medidos na Fase 0
// (2026-07-27): SDXL 1024² quente ≈ 73-84s nesta máquina com o produto de pé.
package image

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
	"os"
	"strconv"
	"strings"
	"time"

	"github.com/redfoxcode/reachyn/engine/internal/identity"
)

// WithComfy — liga o motor local. URL vazia = desligado (modelos comfy falham com erro claro,
// mesmo padrão do WithCliBridge — o catálogo nasce inativo e o operador só ativa onde o
// ComfyUI existe de verdade).
func (c *Client) WithComfy(baseURL string) *Client {
	c.comfyURL = strings.TrimRight(baseURL, "/")
	return c
}

// negativo-BASE do SDXL — o mesmo validado no bench da Fase 0. Não é o negativo inteiro:
// comfyGraph apensa na frente dele os termos de identity.SexNegative derivados do prompt
// (personagem fêmea → nega anatomia de macho, e vice-versa). O positivo afirmar "FEMALE"
// não basta — CLIP pesa tokens, não obedece instrução, e foi assim que a MEL saiu macho
// no Estúdio (2026-07-31); o canal do ComfyUI pra "isto NUNCA" é o conditioning negativo.
const comfyNegative = "blurry, lowres, watermark, text, deformed"

// comfySize — resoluções NATIVAS do SDXL por aspecto (fora delas o modelo degrada:
// treino foi nesses buckets). Default = quadrado.
func comfySize(aspect string) (w, h int) {
	switch aspect {
	case "16:9":
		return 1344, 768
	case "9:16":
		return 768, 1344
	case "4:3":
		return 1152, 896
	case "3:4":
		return 896, 1152
	default:
		return 1024, 1024
	}
}

// comfyGraph — monta o grafo do workflow pedido. É a ALLOWLIST: nome fora daqui = erro.
//
//	sdxl-t2i    → texto → imagem (EmptyLatent no aspecto pedido).
//	sdxl-refine → i2i de baixa denoise sobre a ref (refino/polimento SEM trocar identidade —
//	              Fase 2.3 do plano na sua forma mais simples). A ref define o enquadramento;
//	              só se normaliza o total de pixels pro budget nativo do SDXL (~1 MP), senão
//	              uma ref 2048² estoura tempo e memória da máquina.
//	sdxl-pose   → Fase 2.1: a ref é a ÂNCORA DE ÂNGULO (render da malha no enquadramento do
//	              plano) e vira mapa de contorno (Canny) que o ControlNet impõe à geração — o
//	              modelo desenha POR CIMA da composição em vez de adivinhar o ângulo pelo
//	              texto. Modelo de controle: o canny SMALL (~320 MB) — o união de 2,3 GB
//	              thrasha nesta máquina (Fase 0: quente 1529s vs ~104s do small, fidelidade
//	              de composição validada no olho nos dois). Força 0.9: no bench a 0.85 um dos
//	              seeds soltou a composição; contorno de âncora é pra ser obedecido.
//	sdxl-inpaint→ Fase 2.2: redesenha SÓ a área pintada da máscara (SetLatentNoiseMask) — o
//	              resto da imagem nem entra no ruído. Máscara = imagem P&B (pintado=muda,
//	              preto=fica), canal red, como no bench da Fase 0 (~79s quente). denoise 0.6:
//	              alto o bastante pra trocar o conteúdo da área, baixo o bastante pra herdar
//	              luz e grão da vizinhança — costura invisível é o produto.
//
// comfyCkpt — QUAL IA roda os workflows locais. Configurável por env COMFY_CKPT (nome do
// arquivo em models/checkpoints do ComfyUI) — trocar de modelo vira configuração, sem
// rebuild. ⚠️ Deve ser um finetune da FAMÍLIA SDXL (CheckpointLoaderSimple): os grafos
// (steps/cfg) e os ControlNets instalados são SDXL; um ckpt de outra arquitetura quebra
// o sdxl-pose em silêncio. Default: SDXL base 1.0 (o validado na Fase 0).
func comfyCkpt() string {
	if v := strings.TrimSpace(os.Getenv("COMFY_CKPT")); v != "" {
		return v
	}
	return "sd_xl_base_1.0.safetensors"
}

// Z-IMAGE TURBO (2026-07-31) — o modelo BOM do Estúdio Local: 6B (Tongyi), bf16 cabe
// folgado na L4 de 24GB do Colab, 9 passos em ~20s e aderência de prompt em OUTRA LIGA do
// SDXL ("está horrível" foi o veredito do operador sobre o SDXL). O trio de arquivos já
// vive no Drive do Colab. Distilled: cfg 1.0 — o conditioning negativo é INERTE (mantido
// no grafo por uniformidade). Trocar arquivo = env, mesmo espírito do COMFY_CKPT.
func zimageArquivo(env, padrao string) string {
	if v := strings.TrimSpace(os.Getenv(env)); v != "" {
		return v
	}
	return padrao
}

// comfyLora — um elo da corrente de LoRAs aplicada por cima do checkpoint.
type comfyLora struct {
	name     string
	strength float64
}

// comfyLoras — corrente OPCIONAL de LoRAs por env COMFY_LORA, mesmo espírito do COMFY_CKPT
// (trocar estilo vira configuração, sem rebuild). Formato: "arquivo.safetensors:força" com
// vírgula pra encadear ("pixel-art-xl-v1.1.safetensors:0.9,add-detail-xl.safetensors:0.5");
// força omitida = 1.0. Vazio (o default) = corrente desligada e o grafo fica IDÊNTICO ao de
// antes. ⚠️ Só LoRA da família SDXL (mesma regra do ckpt): o LoraLoader aceita qualquer
// arquivo em models/loras e um LoRA SD1.5 degrada a saída em silêncio. A força vale pra
// model E clip juntos — é o botão que importa (0.9 vs 0.85 muda tanto quanto o modelo).
func comfyLoras() []comfyLora {
	raw := strings.TrimSpace(os.Getenv("COMFY_LORA"))
	if raw == "" {
		return nil
	}
	var out []comfyLora
	for _, part := range strings.Split(raw, ",") {
		part = strings.TrimSpace(part)
		if part == "" {
			continue
		}
		name, strength := part, 1.0
		if i := strings.LastIndex(part, ":"); i > 0 {
			if f, err := strconv.ParseFloat(strings.TrimSpace(part[i+1:]), 64); err == nil {
				name, strength = strings.TrimSpace(part[:i]), f
			}
		}
		out = append(out, comfyLora{name: name, strength: strength})
	}
	return out
}

// ComfyHealth — estado do Estúdio Local pra UI (GET /v1/comfy/health): ComfyUI de pé? qual
// checkpoint e quais LoRAs respondem pelos img-local-* e quais estão instalados (pra trocar
// via COMFY_CKPT/COMFY_LORA)? loras vem "nome:força" — a força faz parte da configuração
// tanto quanto o arquivo. Standalone (lê COMFY_URL direto do env, como o build do client
// faz): health check não pode depender do ciclo de vida do content.Service nem pendurar
// atrás do teto de 600s da geração — timeout curto próprio.
func ComfyHealth(ctx context.Context) (ok bool, ckpt string, disponiveis []string, loras []string, lorasDisponiveis []string, ckptInstalado bool, remoto bool) {
	ckpt = comfyCkpt()
	for _, l := range comfyLoras() {
		loras = append(loras, fmt.Sprintf("%s:%g", l.name, l.strength))
	}
	base := strings.TrimRight(strings.TrimSpace(os.Getenv("COMFY_URL")), "/")
	if base == "" {
		return false, ckpt, nil, loras, nil, false, false
	}
	// REMOTO = ComfyUI fora desta máquina (Colab por túnel, servidor de GPU). Muda o que a UI
	// pode prometer: "sem internet" e "no seu Mac" deixam de ser verdade, e a latência da rede
	// entra em cima do tempo de geração.
	remoto = !strings.Contains(base, "host.docker.internal") &&
		!strings.Contains(base, "localhost") && !strings.Contains(base, "127.0.0.1")

	// 3s bastava pro localhost. Num túnel, o handshake TLS mais o salto até o Colab estouram
	// isso à toa e a luz apagava com o servidor de pé — pior que não ter luz, porque manda o
	// usuário caçar um problema que não existe.
	espera := 3 * time.Second
	if remoto {
		espera = 12 * time.Second
	}
	hctx, cancel := context.WithTimeout(ctx, espera)
	defer cancel()
	req, _ := http.NewRequestWithContext(hctx, http.MethodGet, base+"/system_stats", nil)
	res, err := http.DefaultClient.Do(req)
	if err != nil {
		return false, ckpt, nil, loras, nil, false, remoto
	}
	io.Copy(io.Discard, res.Body)
	res.Body.Close()
	if res.StatusCode != http.StatusOK {
		return false, ckpt, nil, loras, nil, false, remoto
	}
	// Instalados (object_info dos loaders) — best-effort: falhou, a luz acende sem a lista.
	disponiveis = comfyChoices(hctx, base, "CheckpointLoaderSimple", "ckpt_name")
	lorasDisponiveis = comfyChoices(hctx, base, "LoraLoader", "lora_name")

	// O CHECKPOINT CONFIGURADO EXISTE LÁ? Sem isto a luz acendia verde com o servidor de pé e a
	// pasta de modelos VAZIA — o que acontece em todo Colab novo, onde os modelos vêm do Drive e
	// ainda não foram montados. O usuário via "pronto", mandava gerar e só então descobria.
	// Só afirma quando a lista foi lida: lista vazia por falha de leitura não vira acusação.
	for _, d := range disponiveis {
		if d == ckpt {
			ckptInstalado = true

			break
		}
	}

	return true, ckpt, disponiveis, loras, lorasDisponiveis, ckptInstalado, remoto
}

// comfyChoices — opções de um campo enum de um nó via /object_info (a lista de arquivos que
// o ComfyUI enxerga na pasta de modelos). Best-effort: qualquer falha = lista vazia.
func comfyChoices(ctx context.Context, base, node, field string) []string {
	req, _ := http.NewRequestWithContext(ctx, http.MethodGet, base+"/object_info/"+node, nil)
	res, err := http.DefaultClient.Do(req)
	if err != nil {
		return nil
	}
	defer res.Body.Close()
	var oi map[string]struct {
		Input struct {
			Required map[string][]any `json:"required"`
		} `json:"input"`
	}
	if json.NewDecoder(io.LimitReader(res.Body, 1<<20)).Decode(&oi) != nil {
		return nil
	}
	arr, found := oi[node].Input.Required[field]
	if !found || len(arr) == 0 {
		return nil
	}
	names, isList := arr[0].([]any)
	if !isList {
		return nil
	}
	var out []string
	for _, n := range names {
		if s, isStr := n.(string); isStr {
			out = append(out, s)
		}
	}
	return out
}

func comfyGraph(workflow, prompt, negative, aspect string, seed int64, refName, maskName string) (map[string]any, error) {
	w, h := comfySize(aspect)
	// Negativo = guarda de sexo derivada do positivo + negativo do CALLER + base. A guarda vem
	// PRIMEIRO: o CLIP corta em 77 tokens por chunk e os primeiros pesam mais — "male, sheath,
	// testicles" na frente é o que impede a MEL (fêmea) de sair macho; "blurry" pode diluir, a
	// guarda não. O negativo do caller entra no meio (ex.: a ficha de malha suprime "close-up,
	// portrait" pra o full body não virar busto).
	negativo := comfyNegative
	if extra := strings.TrimSpace(negative); extra != "" {
		negativo = extra + ", " + negativo
	}
	if guarda := identity.SexNegative(prompt); guarda != "" {
		negativo = guarda + ", " + negativo
	}
	// E o POSITIVO perde as cláusulas negadas de sexo/anatomia ("NO male anatomy, no
	// scrotum, no sheath" do lock da MEL): CLIP não entende negação — no positivo essas
	// cláusulas entregam os tokens "male…scrotum…sheath" pro modelo DESENHAR, o contrário
	// exato do pedido. O "nunca" delas já vive no negativo acima.
	prompt = identity.StripSexNegations(prompt)
	g := map[string]any{
		"4": map[string]any{"class_type": "CheckpointLoaderSimple", "inputs": map[string]any{"ckpt_name": comfyCkpt()}},
		"6": map[string]any{"class_type": "CLIPTextEncode", "inputs": map[string]any{"clip": []any{"4", 1}, "text": prompt}},
		"7": map[string]any{"class_type": "CLIPTextEncode", "inputs": map[string]any{"clip": []any{"4", 1}, "text": negativo}},
		"8": map[string]any{"class_type": "VAEDecode", "inputs": map[string]any{"samples": []any{"3", 0}, "vae": []any{"4", 2}}},
		"9": map[string]any{"class_type": "SaveImage", "inputs": map[string]any{"images": []any{"8", 0}, "filename_prefix": "foxassets_comfy"}},
	}
	sampler := map[string]any{
		"model": []any{"4", 0}, "positive": []any{"6", 0}, "negative": []any{"7", 0},
		"seed": seed, "steps": 25, "cfg": 7.0, "sampler_name": "euler", "scheduler": "normal",
	}
	switch workflow {
	case "zimage-t2i":
		// Grafo PRÓPRIO do Z-Image (nada de checkpoint SDXL nem corrente de LoRA — LoRA
		// SDXL não fala com esta arquitetura): UNET + Qwen3-4B (CLIPLoader type lumina2,
		// validado ao vivo em 2026-07-31) + VAE próprio + AuraFlow shift 3.0. Retorna
		// direto, mesmo padrão dos pré-processadores, pra corrente de LoRA não vazar.
		return map[string]any{
			"u": map[string]any{"class_type": "UNETLoader", "inputs": map[string]any{
				"unet_name": zimageArquivo("COMFY_ZIMAGE_UNET", "z_image_turbo_bf16.safetensors"), "weight_dtype": "default"}},
			"c": map[string]any{"class_type": "CLIPLoader", "inputs": map[string]any{
				"clip_name": zimageArquivo("COMFY_ZIMAGE_CLIP", "qwen_3_4b.safetensors"), "type": "lumina2", "device": "default"}},
			"v": map[string]any{"class_type": "VAELoader", "inputs": map[string]any{
				"vae_name": zimageArquivo("COMFY_ZIMAGE_VAE", "z_image_ae.safetensors")}},
			"pos": map[string]any{"class_type": "TextEncodeZImageOmni", "inputs": map[string]any{
				"clip": []any{"c", 0}, "prompt": prompt, "auto_resize_images": true}},
			"neg": map[string]any{"class_type": "TextEncodeZImageOmni", "inputs": map[string]any{
				"clip": []any{"c", 0}, "prompt": negativo, "auto_resize_images": true}},
			"ms": map[string]any{"class_type": "ModelSamplingAuraFlow", "inputs": map[string]any{
				"model": []any{"u", 0}, "shift": 3.0}},
			"lat": map[string]any{"class_type": "EmptySD3LatentImage", "inputs": map[string]any{
				"width": w, "height": h, "batch_size": 1}},
			"3": map[string]any{"class_type": "KSampler", "inputs": map[string]any{
				"model": []any{"ms", 0}, "positive": []any{"pos", 0}, "negative": []any{"neg", 0},
				"seed": seed, "steps": 9, "cfg": 1.0, "sampler_name": "euler", "scheduler": "simple",
				"denoise": 1.0, "latent_image": []any{"lat", 0}}},
			"8": map[string]any{"class_type": "VAEDecode", "inputs": map[string]any{"samples": []any{"3", 0}, "vae": []any{"v", 0}}},
			"9": map[string]any{"class_type": "SaveImage", "inputs": map[string]any{"images": []any{"8", 0}, "filename_prefix": "foxassets_comfy"}},
		}, nil
	case "sdxl-t2i":
		g["5"] = map[string]any{"class_type": "EmptyLatentImage", "inputs": map[string]any{"width": w, "height": h, "batch_size": 1}}
		sampler["latent_image"] = []any{"5", 0}
		sampler["denoise"] = 1.0
	case "sdxl-refine":
		if refName == "" {
			return nil, fmt.Errorf("comfy: %s exige uma imagem de referência", workflow)
		}
		g["10"] = map[string]any{"class_type": "LoadImage", "inputs": map[string]any{"image": refName}}
		// resolution_steps=8: além de obrigatório no ComfyUI 0.28+, arredonda w/h pra múltiplos
		// de 8 — exigência do VAE (latente = pixels/8; dimensão quebrada = erro no encode).
		g["11"] = map[string]any{"class_type": "ImageScaleToTotalPixels", "inputs": map[string]any{"image": []any{"10", 0}, "upscale_method": "lanczos", "megapixels": 1.05, "resolution_steps": 8}}
		g["12"] = map[string]any{"class_type": "VAEEncode", "inputs": map[string]any{"pixels": []any{"11", 0}, "vae": []any{"4", 2}}}
		sampler["latent_image"] = []any{"12", 0}
		// denoise baixo É o produto: mantém composição e identidade, redesenha só o acabamento.
		sampler["denoise"] = 0.35
	case "sdxl-pose":
		if refName == "" {
			return nil, fmt.Errorf("comfy: %s exige a âncora de ângulo como referência", workflow)
		}
		g["10"] = map[string]any{"class_type": "LoadImage", "inputs": map[string]any{"image": refName}}
		g["13"] = map[string]any{"class_type": "Canny", "inputs": map[string]any{"image": []any{"10", 0}, "low_threshold": 0.2, "high_threshold": 0.6}}
		g["14"] = map[string]any{"class_type": "ControlNetLoader", "inputs": map[string]any{"control_net_name": "controlnet-canny-sdxl-small-fp16.safetensors"}}
		g["15"] = map[string]any{"class_type": "ControlNetApplyAdvanced", "inputs": map[string]any{
			"positive": []any{"6", 0}, "negative": []any{"7", 0}, "control_net": []any{"14", 0},
			"image": []any{"13", 0}, "strength": 0.9, "start_percent": 0.0, "end_percent": 1.0,
		}}
		g["5"] = map[string]any{"class_type": "EmptyLatentImage", "inputs": map[string]any{"width": w, "height": h, "batch_size": 1}}
		sampler["positive"] = []any{"15", 0}
		sampler["negative"] = []any{"15", 1}
		sampler["latent_image"] = []any{"5", 0}
		sampler["denoise"] = 1.0
	case "normal-map":
		if refName == "" {
			return nil, fmt.Errorf("comfy: %s exige a textura diffuse como referência", workflow)
		}
		// Pré-processador PURO (PBR do pipeline Unity): o BAE (comfyui_controlnet_aux) ESTIMA a
		// normal da diffuse — sem checkpoint, sampler ou prompt. Grafo próprio, retorna aqui.
		// resolution segue o aspect (lado MENOR do comfySize — o preprocessor preserva proporção);
		// todos os valores da tabela são múltiplos de 64, exigência do nó.
		return map[string]any{
			"10": map[string]any{"class_type": "LoadImage", "inputs": map[string]any{"image": refName}},
			"11": map[string]any{"class_type": "BAE-NormalMapPreprocessor", "inputs": map[string]any{"image": []any{"10", 0}, "resolution": min(w, h)}},
			"9":  map[string]any{"class_type": "SaveImage", "inputs": map[string]any{"images": []any{"11", 0}, "filename_prefix": "foxassets_comfy"}},
		}, nil
	case "depth-map":
		if refName == "" {
			return nil, fmt.Errorf("comfy: %s exige a textura diffuse como referência", workflow)
		}
		// Depth por modelo dedicado (DepthAnythingV2 vits ~50MB, 3s quente) — vira AO por bake
		// no cliente (crate_build_pbr.py). Pré-processador puro, mesmo padrão do normal-map.
		return map[string]any{
			"10": map[string]any{"class_type": "LoadImage", "inputs": map[string]any{"image": refName}},
			"11": map[string]any{"class_type": "DepthAnythingV2Preprocessor", "inputs": map[string]any{"image": []any{"10", 0}, "ckpt_name": "depth_anything_v2_vits.pth", "resolution": min(w, h)}},
			"9":  map[string]any{"class_type": "SaveImage", "inputs": map[string]any{"images": []any{"11", 0}, "filename_prefix": "foxassets_comfy"}},
		}, nil
	case "material-map":
		if refName == "" {
			return nil, fmt.Errorf("comfy: %s exige a textura diffuse como referência", workflow)
		}
		// Roughness por modelo dedicado (Marigold IID Appearance via custom node FoxMarigoldMaterial,
		// scripts/comfy/foxassets_material.py — 5s quente no gate). Saída 0 = roughness.
		return map[string]any{
			"10": map[string]any{"class_type": "LoadImage", "inputs": map[string]any{"image": refName}},
			"11": map[string]any{"class_type": "FoxMarigoldMaterial", "inputs": map[string]any{"image": []any{"10", 0}, "steps": 4}},
			"9":  map[string]any{"class_type": "SaveImage", "inputs": map[string]any{"images": []any{"11", 0}, "filename_prefix": "foxassets_comfy"}},
		}, nil
	case "upscale-4x":
		if refName == "" {
			return nil, fmt.Errorf("comfy: %s exige a imagem a ampliar como referência", workflow)
		}
		// Upscale 4× por modelo (4x-UltraSharp, família ESRGAN — models/upscale_models):
		// pré-processador puro, mesmo padrão do normal-map — sem checkpoint/sampler/prompt,
		// zero crédito de nuvem. É a ponta "textura 4K pro Unity" do PBR (1024² → 4096²).
		// Pixel art NÃO passa aqui: sprite pixelado amplia por nearest-neighbor (nativo) —
		// modelo de upscale derreteria o pixel duro.
		return map[string]any{
			"10": map[string]any{"class_type": "LoadImage", "inputs": map[string]any{"image": refName}},
			"11": map[string]any{"class_type": "UpscaleModelLoader", "inputs": map[string]any{"model_name": "4x-UltraSharp.pt"}},
			"12": map[string]any{"class_type": "ImageUpscaleWithModel", "inputs": map[string]any{"upscale_model": []any{"11", 0}, "image": []any{"10", 0}}},
			"9":  map[string]any{"class_type": "SaveImage", "inputs": map[string]any{"images": []any{"12", 0}, "filename_prefix": "foxassets_comfy"}},
		}, nil
	case "sdxl-inpaint":
		if refName == "" || maskName == "" {
			return nil, fmt.Errorf("comfy: %s exige a imagem e a máscara", workflow)
		}
		g["20"] = map[string]any{"class_type": "LoadImage", "inputs": map[string]any{"image": refName}}
		g["21"] = map[string]any{"class_type": "LoadImage", "inputs": map[string]any{"image": maskName}}
		g["22"] = map[string]any{"class_type": "ImageToMask", "inputs": map[string]any{"image": []any{"21", 0}, "channel": "red"}}
		g["23"] = map[string]any{"class_type": "VAEEncode", "inputs": map[string]any{"pixels": []any{"20", 0}, "vae": []any{"4", 2}}}
		// A máscara é redimensionada pro latente pelo próprio SetLatentNoiseMask — a imagem
		// entra no tamanho ORIGINAL de propósito: conserto não deve mudar a resolução do quadro.
		g["24"] = map[string]any{"class_type": "SetLatentNoiseMask", "inputs": map[string]any{"samples": []any{"23", 0}, "mask": []any{"22", 0}}}
		sampler["latent_image"] = []any{"24", 0}
		sampler["denoise"] = 0.6
	default:
		return nil, fmt.Errorf("comfy: workflow desconhecido %q (allowlist: zimage-t2i, sdxl-t2i, sdxl-refine, sdxl-pose, sdxl-inpaint, normal-map, depth-map, material-map, upscale-4x)", workflow)
	}
	// Corrente de LoRAs (COMFY_LORA) — entra ENTRE o checkpoint e os consumidores: cada
	// LoraLoader recebe model+clip do elo anterior e os dois text encodes + o sampler passam
	// a ler do ÚLTIMO elo. Rewire dos DOIS lados de propósito: LoRA só no model (sem o clip)
	// ignora os trigger words do treino e o estilo vem pela metade — bug silencioso clássico.
	// Fica depois do switch porque os pré-processadores puros (normal/depth/material) já
	// retornaram: LoRA é coisa de geração, não de estimativa.
	if loras := comfyLoras(); len(loras) > 0 {
		model, clip := []any{"4", 0}, []any{"4", 1}
		id := ""
		for i, l := range loras {
			id = fmt.Sprintf("4%d", i+1) // 41, 42… — faixa livre no grafo
			g[id] = map[string]any{"class_type": "LoraLoader", "inputs": map[string]any{
				"model": model, "clip": clip, "lora_name": l.name,
				"strength_model": l.strength, "strength_clip": l.strength,
			}}
			model, clip = []any{id, 0}, []any{id, 1}
		}
		g["6"].(map[string]any)["inputs"].(map[string]any)["clip"] = clip
		g["7"].(map[string]any)["inputs"].(map[string]any)["clip"] = clip
		sampler["model"] = model
	}
	g["3"] = map[string]any{"class_type": "KSampler", "inputs": sampler}
	return g, nil
}

// ComfyImage — gera via ComfyUI local. workflow = provider_model_id do catálogo (allowlist
// acima). Sem fallback cross-provider: motor escolhido é escolha explícita; falha vira erro
// claro e o console estorna, igual ao cli-bridge.
//
// seed > 0 = variação CONTROLADA (Fase 2.4): mesmo seed + mesmo workflow repetem a
// composição; muda-se o prompt e a pose fica. seed <= 0 = novo a cada chamada (padrão) —
// repetir o pedido gera OUTRA imagem e fura o cache do ComfyUI, que devolveria a idêntica
// sem executar nada. maskURL só é usado pelo sdxl-inpaint (área a redesenhar).
func (c *Client) ComfyImage(ctx context.Context, workflow, prompt, negative, aspect, style string, refURLs []string, maskURL string, seed int64) ([]byte, string, error) {
	if c.comfyURL == "" {
		return nil, "", fmt.Errorf("comfy: não configurado (COMFY_URL)")
	}
	finalPrompt := StyledPrompt(prompt, style)

	refName := ""
	if len(refURLs) > 0 {
		name, err := c.comfyUpload(ctx, refURLs[0], fmt.Sprintf("ref_%d.png", rand.Int64N(1<<50)))
		if err != nil {
			return nil, "", fmt.Errorf("comfy: ref: %w", err)
		}
		refName = name
	}
	maskName := ""
	if maskURL != "" {
		name, err := c.comfyUpload(ctx, maskURL, fmt.Sprintf("mask_%d.png", rand.Int64N(1<<50)))
		if err != nil {
			return nil, "", fmt.Errorf("comfy: máscara: %w", err)
		}
		maskName = name
	}

	if seed <= 0 {
		seed = rand.Int64N(1 << 62)
	}
	graph, err := comfyGraph(workflow, finalPrompt, negative, aspect, seed, refName, maskName)
	if err != nil {
		return nil, "", err
	}

	body, _ := json.Marshal(map[string]any{"prompt": graph})
	req, _ := http.NewRequestWithContext(ctx, http.MethodPost, c.comfyURL+"/prompt", bytes.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := comfyHTTP.Do(req)
	if err != nil {
		return nil, "", fmt.Errorf("comfy: %w", err)
	}
	defer resp.Body.Close()
	var queued struct {
		PromptID string `json:"prompt_id"`
		Error    any    `json:"error"`
	}
	raw, _ := io.ReadAll(io.LimitReader(resp.Body, 1<<20))
	if err := json.Unmarshal(raw, &queued); err != nil || queued.PromptID == "" {
		return nil, "", fmt.Errorf("comfy http %d: %s", resp.StatusCode, clipStr(string(raw), 300))
	}

	filename, subfolder, err := c.comfyWait(ctx, queued.PromptID)
	if err != nil {
		return nil, "", err
	}
	return c.comfyView(ctx, filename, subfolder)
}

// comfyHTTP — teto alto de propósito: a fila do ComfyUI é serial (§7), então uma geração
// pode esperar a da frente terminar antes de rodar os ~80s dela.
var comfyHTTP = &http.Client{Timeout: 600 * time.Second}

// comfyUpload — baixa a ref (URL pública do acervo) e sobe pro input/ do ComfyUI.
// O ComfyUI não busca URL: LoadImage só lê arquivo que já está no servidor.
// filename ÚNICO por chamada: o upload acontece ANTES da fila serial — nome fixo faria duas
// gerações concorrentes trocarem as refs uma da outra (e ref/máscara se sobrescreverem).
func (c *Client) comfyUpload(ctx context.Context, refURL, filename string) (string, error) {
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, refURL, nil)
	if err != nil {
		return "", err
	}
	resp, err := comfyHTTP.Do(req)
	if err != nil {
		return "", err
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return "", fmt.Errorf("download da ref: http %d", resp.StatusCode)
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
	ur, err := comfyHTTP.Do(up)
	if err != nil {
		return "", err
	}
	defer ur.Body.Close()
	var out struct {
		Name      string `json:"name"`
		Subfolder string `json:"subfolder"`
	}
	if err := json.NewDecoder(io.LimitReader(ur.Body, 1<<20)).Decode(&out); err != nil || out.Name == "" {
		return "", fmt.Errorf("upload da ref: http %d", ur.StatusCode)
	}
	if out.Subfolder != "" {
		return out.Subfolder + "/" + out.Name, nil
	}
	return out.Name, nil
}

// comfyWait — espera o job terminar via /history. Erro de execução vem com a mensagem real
// do nó que falhou (modelo faltando, OOM…), não um timeout opaco.
func (c *Client) comfyWait(ctx context.Context, promptID string) (filename, subfolder string, err error) {
	deadline := time.Now().Add(600 * time.Second)
	for {
		select {
		case <-ctx.Done():
			return "", "", ctx.Err()
		case <-time.After(2 * time.Second):
		}
		if time.Now().After(deadline) {
			return "", "", fmt.Errorf("comfy: excedeu 600s (fila local ocupada ou máquina sem fôlego)")
		}
		req, _ := http.NewRequestWithContext(ctx, http.MethodGet, c.comfyURL+"/history/"+url.PathEscape(promptID), nil)
		resp, herr := comfyHTTP.Do(req)
		if herr != nil {
			log.Printf("comfy: poll falhou (%v) — tentando de novo", herr)
			continue
		}
		var hist map[string]struct {
			Status struct {
				StatusStr string  `json:"status_str"`
				Messages  [][]any `json:"messages"`
			} `json:"status"`
			Outputs map[string]struct {
				Images []struct {
					Filename  string `json:"filename"`
					Subfolder string `json:"subfolder"`
					Type      string `json:"type"`
				} `json:"images"`
			} `json:"outputs"`
		}
		derr := json.NewDecoder(io.LimitReader(resp.Body, 8<<20)).Decode(&hist)
		resp.Body.Close()
		if derr != nil {
			continue
		}
		entry, ok := hist[promptID]
		if !ok {
			continue // ainda na fila/executando
		}
		if entry.Status.StatusStr == "error" {
			msg := "erro de execução"
			for _, m := range entry.Status.Messages {
				if len(m) >= 2 && fmt.Sprint(m[0]) == "execution_error" {
					if b, jerr := json.Marshal(m[1]); jerr == nil {
						msg = clipStr(string(b), 400)
					}
				}
			}
			return "", "", fmt.Errorf("comfy: %s", msg)
		}
		for _, out := range entry.Outputs {
			for _, img := range out.Images {
				if img.Type == "output" {
					return img.Filename, img.Subfolder, nil
				}
			}
		}
	}
}

// comfyView — baixa os bytes da imagem gerada (GET /view).
func (c *Client) comfyView(ctx context.Context, filename, subfolder string) ([]byte, string, error) {
	q := url.Values{"filename": {filename}, "subfolder": {subfolder}, "type": {"output"}}
	req, _ := http.NewRequestWithContext(ctx, http.MethodGet, c.comfyURL+"/view?"+q.Encode(), nil)
	resp, err := comfyHTTP.Do(req)
	if err != nil {
		return nil, "", fmt.Errorf("comfy view: %w", err)
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return nil, "", fmt.Errorf("comfy view: http %d", resp.StatusCode)
	}
	// 80 MB: o upscale-4x devolve PNG 4096² — textura ruidosa comprime mal e passa dos 40 MB
	// que bastavam pro 1024².
	data, err := io.ReadAll(io.LimitReader(resp.Body, 80<<20))
	if err != nil || len(data) == 0 {
		return nil, "", fmt.Errorf("comfy view: resposta vazia")
	}
	ext := "png"
	if i := strings.LastIndex(filename, "."); i >= 0 && i < len(filename)-1 {
		ext = strings.ToLower(filename[i+1:])
	}
	return data, ext, nil
}
