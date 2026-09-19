package image

import (
	"context"
	"regexp"
	"strings"
	"testing"
)

// A allowlist é a fronteira de segurança (baseline #7): nome de workflow fora dela tem
// que ser recusado ANTES de qualquer chamada de rede.
func TestComfyGraphAllowlist(t *testing.T) {
	if _, err := comfyGraph("../../etc/passwd", "p", "", "1:1", 1, "", ""); err == nil {
		t.Fatal("workflow fora da allowlist deveria ser recusado")
	}
	if _, err := comfyGraph("sdxl-refine", "p", "", "1:1", 1, "", ""); err == nil {
		t.Fatal("sdxl-refine sem ref deveria falhar — refino refina ALGO")
	}
}

func TestComfyGraphT2I(t *testing.T) {
	g, err := comfyGraph("sdxl-t2i", "uma raposa", "", "16:9", 42, "", "")
	if err != nil {
		t.Fatal(err)
	}
	latent := g["5"].(map[string]any)["inputs"].(map[string]any)
	if latent["width"] != 1344 || latent["height"] != 768 {
		t.Fatalf("16:9 deveria usar o bucket nativo 1344x768 do SDXL, veio %vx%v", latent["width"], latent["height"])
	}
	sampler := g["3"].(map[string]any)["inputs"].(map[string]any)
	if sampler["denoise"] != 1.0 {
		t.Fatalf("t2i parte do ruído puro (denoise 1.0), veio %v", sampler["denoise"])
	}
}

func TestComfyGraphRefine(t *testing.T) {
	g, err := comfyGraph("sdxl-refine", "polir", "", "1:1", 42, "ref.png", "")
	if err != nil {
		t.Fatal(err)
	}
	if g["10"].(map[string]any)["inputs"].(map[string]any)["image"] != "ref.png" {
		t.Fatal("refine tem que carregar a ref enviada")
	}
	sampler := g["3"].(map[string]any)["inputs"].(map[string]any)
	if sampler["denoise"] != 0.35 {
		t.Fatalf("refine preserva identidade com denoise baixo (0.35), veio %v", sampler["denoise"])
	}
	if _, temVazio := g["5"]; temVazio {
		t.Fatal("refine parte da ref codificada, não de EmptyLatentImage")
	}
}

// A pose (2.1) transforma a âncora em contorno que a geração OBEDECE — o grafo tem que
// rotear positive/negative pelo ControlNet, senão o modelo ignora a âncora em silêncio.
func TestComfyGraphPose(t *testing.T) {
	if _, err := comfyGraph("sdxl-pose", "p", "", "16:9", 1, "", ""); err == nil {
		t.Fatal("pose sem âncora deveria falhar")
	}
	g, err := comfyGraph("sdxl-pose", "herói na rua", "", "16:9", 42, "ancora.png", "")
	if err != nil {
		t.Fatal(err)
	}
	apply := g["15"].(map[string]any)["inputs"].(map[string]any)
	if apply["strength"] != 0.9 {
		t.Fatalf("força do controle deveria ser 0.9 (0.85 soltou a composição no bench), veio %v", apply["strength"])
	}
	sampler := g["3"].(map[string]any)["inputs"].(map[string]any)
	pos, ok := sampler["positive"].([]any)
	if !ok || pos[0] != "15" {
		t.Fatal("o positive do sampler tem que vir do ControlNetApplyAdvanced — senão a âncora é ignorada em silêncio")
	}
}

// O inpaint (2.2) redesenha SÓ a área da máscara — o latente tem que passar pelo
// SetLatentNoiseMask, senão o denoise 0.6 borraria a imagem INTEIRA.
func TestComfyGraphInpaint(t *testing.T) {
	if _, err := comfyGraph("sdxl-inpaint", "p", "", "1:1", 1, "img.png", ""); err == nil {
		t.Fatal("inpaint sem máscara deveria falhar — sem ela viraria um i2i borrado")
	}
	g, err := comfyGraph("sdxl-inpaint", "casaco amarelo", "", "1:1", 42, "img.png", "mask.png")
	if err != nil {
		t.Fatal(err)
	}
	sampler := g["3"].(map[string]any)["inputs"].(map[string]any)
	lat, ok := sampler["latent_image"].([]any)
	if !ok || lat[0] != "24" {
		t.Fatal("o latente tem que vir do SetLatentNoiseMask — senão a máscara é ignorada e a imagem inteira muda")
	}
	if sampler["denoise"] != 0.6 {
		t.Fatalf("denoise do inpaint é 0.6 (validado no bench: troca o conteúdo, herda luz e grão), veio %v", sampler["denoise"])
	}
}

// A corrente de LoRAs (COMFY_LORA) entra ENTRE o checkpoint e os consumidores: cada elo
// encadeia no anterior e sampler + text encodes leem do ÚLTIMO. Rewire dos dois lados é o
// ponto do teste — LoRA só no model (sem o clip) perde os trigger words em silêncio.
func TestComfyGraphLora(t *testing.T) {
	t.Setenv("COMFY_LORA", "pixel-art-xl-v1.1.safetensors:0.9, add-detail-xl.safetensors")
	g, err := comfyGraph("sdxl-t2i", "raposa", "", "1:1", 42, "", "")
	if err != nil {
		t.Fatal(err)
	}
	l1 := g["41"].(map[string]any)["inputs"].(map[string]any)
	if l1["lora_name"] != "pixel-art-xl-v1.1.safetensors" || l1["strength_model"] != 0.9 || l1["strength_clip"] != 0.9 {
		t.Fatalf("elo 1 deveria ser pixel-art a 0.9 em model E clip, veio %v", l1)
	}
	if l1["model"].([]any)[0] != "4" {
		t.Fatal("o primeiro elo tem que ler do checkpoint")
	}
	l2 := g["42"].(map[string]any)["inputs"].(map[string]any)
	if l2["lora_name"] != "add-detail-xl.safetensors" || l2["strength_model"] != 1.0 {
		t.Fatalf("força omitida deveria valer 1.0, veio %v", l2)
	}
	if l2["model"].([]any)[0] != "41" {
		t.Fatal("o segundo elo tem que encadear no primeiro, não no checkpoint")
	}
	sampler := g["3"].(map[string]any)["inputs"].(map[string]any)
	if sampler["model"].([]any)[0] != "42" {
		t.Fatal("o sampler tem que ler o model do último elo da corrente")
	}
	for _, enc := range []string{"6", "7"} {
		clip := g[enc].(map[string]any)["inputs"].(map[string]any)["clip"].([]any)
		if clip[0] != "42" {
			t.Fatalf("o CLIPTextEncode %s tem que ler o clip do último elo — sem isso os trigger words do LoRA não fazem efeito", enc)
		}
	}
}

// Com LoRA ligado, a pose continua roteando o conditioning pelo ControlNet — a corrente
// muda de onde vem o CLIP, nunca quem manda na composição.
func TestComfyGraphLoraPose(t *testing.T) {
	t.Setenv("COMFY_LORA", "pixel-art-xl-v1.1.safetensors:0.9")
	g, err := comfyGraph("sdxl-pose", "herói", "", "16:9", 42, "ancora.png", "")
	if err != nil {
		t.Fatal(err)
	}
	sampler := g["3"].(map[string]any)["inputs"].(map[string]any)
	if sampler["positive"].([]any)[0] != "15" {
		t.Fatal("com LoRA, o positive do sampler tem que continuar vindo do ControlNetApplyAdvanced")
	}
	if sampler["model"].([]any)[0] != "41" {
		t.Fatal("com LoRA, o model do sampler tem que vir da corrente")
	}
}

// COMFY_LORA vazio = grafo IDÊNTICO ao de antes (nenhum nó extra, wiring original) — a
// corrente desligada não pode deixar rastro.
func TestComfyGraphSemLora(t *testing.T) {
	t.Setenv("COMFY_LORA", "")
	g, err := comfyGraph("sdxl-t2i", "raposa", "", "1:1", 42, "", "")
	if err != nil {
		t.Fatal(err)
	}
	if _, tem := g["41"]; tem {
		t.Fatal("sem COMFY_LORA não pode existir LoraLoader no grafo")
	}
	sampler := g["3"].(map[string]any)["inputs"].(map[string]any)
	if sampler["model"].([]any)[0] != "4" {
		t.Fatal("sem LoRA o sampler lê o model direto do checkpoint")
	}
}

// O upscale-4x é pré-processador PURO (modelo ESRGAN, sem checkpoint/sampler/prompt) — e
// LoRA NÃO entra nele: corrente ligada não pode vazar pra um grafo que nem tem sampler.
func TestComfyGraphUpscale(t *testing.T) {
	if _, err := comfyGraph("upscale-4x", "p", "", "1:1", 1, "", ""); err == nil {
		t.Fatal("upscale sem referência deveria falhar — amplia-se ALGO")
	}
	t.Setenv("COMFY_LORA", "pixel-art-xl-v1.1.safetensors:0.9")
	g, err := comfyGraph("upscale-4x", "", "", "1:1", 1, "textura.png", "")
	if err != nil {
		t.Fatal(err)
	}
	if g["11"].(map[string]any)["inputs"].(map[string]any)["model_name"] != "4x-UltraSharp.pt" {
		t.Fatal("upscale tem que usar o 4x-UltraSharp instalado em models/upscale_models")
	}
	for _, id := range []string{"3", "4", "41"} {
		if _, tem := g[id]; tem {
			t.Fatalf("upscale é pré-processador puro — nó %s (sampler/checkpoint/LoRA) não pode existir no grafo", id)
		}
	}
}

// A guarda de sexo no conditioning NEGATIVO é o que impede a MEL (fêmea) de sair macho:
// o positivo afirmar "FEMALE" não basta (CLIP pesa tokens, não obedece instrução). A guarda
// tem que vir ANTES do negativo-base (os primeiros tokens do chunk pesam mais) e disparar
// MESMO com o lock real, que traz "male" dentro de cláusulas negadas ("NO male anatomy").
// Do outro lado, o POSITIVO tem que PERDER essas cláusulas: "no scrotum" no positivo é o
// CLIP recebendo o token "scrotum" pra desenhar — foi a rodada E2E de 2026-07-31 que
// mostrou os dois furos de uma vez.
func TestComfyGraphGuardaDeSexo(t *testing.T) {
	lock := "FEMALE dachshund — a female dog: feminine slender build, NO male anatomy, no visible genitalia, no scrotum, no sheath"
	g, err := comfyGraph("sdxl-t2i", lock, "", "1:1", 42, "", "")
	if err != nil {
		t.Fatal(err)
	}
	neg := g["7"].(map[string]any)["inputs"].(map[string]any)["text"].(string)
	if !strings.HasPrefix(neg, "male") || !strings.Contains(neg, "sheath") {
		t.Fatalf("prompt fêmea deveria abrir o negativo com a guarda de macho, veio: %q", neg)
	}
	if !strings.HasSuffix(neg, comfyNegative) {
		t.Fatalf("o negativo-base do bench tem que continuar presente, veio: %q", neg)
	}
	pos := g["6"].(map[string]any)["inputs"].(map[string]any)["text"].(string)
	// Palavra INTEIRA de propósito: Contains acharia o "male" dentro de "FEMALE" e acusaria
	// o positivo correto.
	for _, termo := range []string{"male", "scrotum", "sheath", "genitalia"} {
		if regexp.MustCompile(`(?i)\b` + termo + `\b`).MatchString(pos) {
			t.Fatalf("o positivo não pode entregar o token %q pro CLIP, veio: %q", termo, pos)
		}
	}
	if !strings.Contains(pos, "FEMALE dachshund") {
		t.Fatalf("as afirmações do lock têm que sobreviver no positivo, veio: %q", pos)
	}
}

// O negativo do CALLER (ex.: a ficha de malha suprimindo "close-up, portrait" pro full body
// não virar busto) entra ENTRE a guarda de sexo e o base — a guarda continua abrindo o chunk.
func TestComfyGraphNegativoDoCaller(t *testing.T) {
	g, err := comfyGraph("sdxl-t2i", "FEMALE dachshund dog", "close-up, portrait", "4:3", 42, "", "")
	if err != nil {
		t.Fatal(err)
	}
	neg := g["7"].(map[string]any)["inputs"].(map[string]any)["text"].(string)
	if !strings.HasPrefix(neg, "male") {
		t.Fatalf("a guarda de sexo tem que continuar abrindo o negativo, veio: %q", neg)
	}
	if !strings.Contains(neg, "close-up, portrait") {
		t.Fatalf("o negativo do caller deveria estar presente, veio: %q", neg)
	}
	if !strings.HasSuffix(neg, comfyNegative) {
		t.Fatalf("o negativo-base tem que fechar a lista, veio: %q", neg)
	}
}

// Sem sinal de sexo no prompt, o negativo fica EXATAMENTE o do bench — a guarda não pode
// inventar supressão (cena sem personagem, ou com os dois sexos, não nega nenhum lado).
func TestComfyGraphNegativoNeutro(t *testing.T) {
	g, err := comfyGraph("sdxl-t2i", "a lighthouse on a rocky hill at dusk", "", "1:1", 42, "", "")
	if err != nil {
		t.Fatal(err)
	}
	if neg := g["7"].(map[string]any)["inputs"].(map[string]any)["text"]; neg != comfyNegative {
		t.Fatalf("sem sinal de sexo o negativo deveria ser só o base, veio: %q", neg)
	}
}

// O Z-Image tem grafo PRÓPRIO: UNET+Qwen3(lumina2)+VAE dele, AuraFlow shift 3.0, cfg 1.0
// (distilled). E a corrente de LoRA — que é SDXL — NÃO pode vazar pra ele nem o checkpoint
// SDXL aparecer no grafo: LoRA de outra arquitetura degrada em silêncio.
func TestComfyGraphZImage(t *testing.T) {
	t.Setenv("COMFY_LORA", "pixel-art-xl-v1.1.safetensors:0.9")
	g, err := comfyGraph("zimage-t2i", "a copper fox figurine", "", "4:3", 42, "", "")
	if err != nil {
		t.Fatal(err)
	}
	for _, id := range []string{"4", "41", "5", "6", "7"} {
		if _, tem := g[id]; tem {
			t.Fatalf("nó SDXL/LoRA %s não pode existir no grafo do Z-Image", id)
		}
	}
	if g["c"].(map[string]any)["inputs"].(map[string]any)["type"] != "lumina2" {
		t.Fatal("o text encoder do Z-Image carrega como lumina2 (validado ao vivo 2026-07-31)")
	}
	ks := g["3"].(map[string]any)["inputs"].(map[string]any)
	if ks["cfg"] != 1.0 || ks["steps"] != 9 {
		t.Fatalf("Z-Image turbo é cfg 1.0 / 9 passos, veio cfg=%v steps=%v", ks["cfg"], ks["steps"])
	}
	if ks["model"].([]any)[0] != "ms" {
		t.Fatal("o sampler tem que consumir o ModelSamplingAuraFlow — sem shift o Z degrada")
	}
	if g["pos"].(map[string]any)["inputs"].(map[string]any)["prompt"] != "a copper fox figurine" {
		t.Fatal("o prompt tem que chegar no TextEncodeZImageOmni positivo")
	}
}

// Desligado (COMFY_URL vazio) = erro claro na hora, sem tentar rede — é o contrato que
// permite o modelo existir no catálogo inativo sem risco (mesmo padrão do cli-bridge).
func TestComfyImageDesligado(t *testing.T) {
	c := New("")
	_, _, err := c.ComfyImage(context.Background(), "sdxl-t2i", "p", "", "1:1", "", nil, "", 0)
	if err == nil || !strings.Contains(err.Error(), "COMFY_URL") {
		t.Fatalf("sem COMFY_URL deveria falhar citando a env, veio: %v", err)
	}
}
