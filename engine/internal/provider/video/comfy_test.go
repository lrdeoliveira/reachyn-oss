package video

import (
	"strings"
	"testing"
)

// A prévia é i2v puro: sem quadro inicial o Wan22ImageToVideoLatent não tem o que animar. O erro
// precisa ser de CONFIG (contrato), não de rede — senão o caller retenta 3× uma coisa que nunca
// vai dar certo, ocupando a GPU do operador à toa.
func TestComfyPreviaSemImagemNaoChamaRede(t *testing.T) {
	c := New("").WithComfy("http://127.0.0.1:9")
	_, _, err := c.ComfyPrevia(t.Context(), "qualquer movimento", "16:9", "5", "", 0)
	if err == nil {
		t.Fatal("sem imagem base deveria falhar de cara")
	}
	if !strings.Contains(err.Error(), "imagem") {
		t.Fatalf("o erro deveria dizer que falta a imagem, veio: %v", err)
	}
}

// Motor desligado (COMFY_URL vazio) = erro claro na hora, sem tentar rede. Mesmo contrato do
// provider de imagem — é o que faz a escolha explícita falhar explicando, em vez de pendurar.
func TestComfyPreviaDesligado(t *testing.T) {
	c := New("")
	_, _, err := c.ComfyPrevia(t.Context(), "movimento", "16:9", "5", "https://exemplo/x.jpg", 0)
	if err == nil || !strings.Contains(err.Error(), "COMFY_URL") {
		t.Fatalf("sem COMFY_URL deveria falhar citando a env, veio: %v", err)
	}
}

// O rascunho tem 3 s FIXOS (73 quadros = 3×24+1, o 4n+1 que o Wan pede). A duração da tela vale
// pro clipe pago; aqui é ignorada de propósito e ANUNCIADA no botão. O que este teste protege é
// o número bater com o que a interface promete — se um dia virar 5 s aqui e continuar "3 s" lá,
// o usuário espera o dobro do tempo sem entender por quê.
func TestPreviaFrames(t *testing.T) {
	if got := previaFrames(); got != 73 {
		t.Errorf("quadros do rascunho = %d, queria 73 (3 s a 24 fps)", got)
	}
}

// A resolução não pode cair abaixo do piso medido: em 768×448 o Wan 2.2 DERRETE a cena (a antena
// do plano virou mastro liso em 3 s, 2026-07-30). 960×544 foi o menor tamanho que segurou a
// identidade até o fim do clipe. Baixar isto "pra ficar mais rápido" volta a quebrar o rascunho —
// e a falha é silenciosa: o vídeo sai, só não é mais a sua cena.
func TestPreviaSizeNaoCaiAbaixoDoPisoMedido(t *testing.T) {
	for _, aspect := range []string{"16:9", "9:16", "1:1", "torto"} {
		w, h := previaSize(aspect)
		if w*h < 960*544 {
			t.Errorf("%s: %dx%d tem menos pixels que o piso medido (960×544) — a cena derrete", aspect, w, h)
		}
	}
}

// O formato pedido tem que chegar no grafo: vertical vira retrato de verdade. Um aspect ignorado
// aqui devolveria a prévia deitada e o operador julgaria o enquadramento errado.
func TestPreviaSizeSegueOFormato(t *testing.T) {
	if w, h := previaSize("9:16"); w >= h {
		t.Errorf("9:16 deveria ser retrato, veio %dx%d", w, h)
	}
	if w, h := previaSize("16:9"); w <= h {
		t.Errorf("16:9 deveria ser paisagem, veio %dx%d", w, h)
	}
	if w, h := previaSize("1:1"); w != h {
		t.Errorf("1:1 deveria ser quadrado, veio %dx%d", w, h)
	}
	// Formato desconhecido não pode virar 0×0 (o ComfyUI recusaria o grafo inteiro).
	if w, h := previaSize("banana"); w <= 0 || h <= 0 {
		t.Errorf("formato desconhecido deveria cair no padrão, veio %dx%d", w, h)
	}
}

// A guarda de sexo no negativo da prévia — o mesmo remédio do motor de imagem, e aqui onde
// o drift NASCEU: o i2v completa os ângulos que a âncora não mostra, e foi assim que a MEL
// (fêmea) ganhou anatomia de macho (draft 84, t=34s). Basta o pronome da ação ("she") pra
// guarda entrar, porque na prévia o lock pode ter saído do texto.
func TestPreviaGraphGuardaDeSexo(t *testing.T) {
	g := previaGraph("the dachshund trots along the pier, she wags her tail", "16:9", "base.png", 42)
	neg := g["neg"].(map[string]any)["inputs"].(map[string]any)["text"].(string)
	if !strings.HasPrefix(neg, "male") || !strings.Contains(neg, "sheath") {
		t.Fatalf("prompt fêmea deveria abrir o negativo com a guarda de macho, veio: %q", neg)
	}
	if !strings.HasSuffix(neg, previaNegative) {
		t.Fatalf("o negativo-base da prévia tem que continuar presente, veio: %q", neg)
	}
}

// O grafo precisa sair no formato de API do ComfyUI e ligado nos nós certos. Este teste é o que
// separa "compila" de "o servidor aceita": um fio trocado só apareceria depois de 5 min de GPU.
func TestPreviaGraphLigacoes(t *testing.T) {
	g := previaGraph("a raposa respira", "16:9", "base_123.png", 42)

	for _, no := range []string{"clip", "pos", "neg", "img", "unet", "vae", "ms", "lat", "ks", "dec", "out"} {
		if _, ok := g[no]; !ok {
			t.Fatalf("faltou o nó %q no grafo", no)
		}
	}

	nó := func(nome string) map[string]any {
		n, ok := g[nome].(map[string]any)
		if !ok {
			t.Fatalf("nó %q malformado", nome)
		}

		return n
	}
	inputs := func(nome string) map[string]any {
		in, ok := nó(nome)["inputs"].(map[string]any)
		if !ok {
			t.Fatalf("inputs de %q malformado", nome)
		}

		return in
	}

	// A imagem que subimos é a que o LoadImage lê — se este fio quebrar, a prévia anima outra coisa.
	if got := inputs("img")["image"]; got != "base_123.png" {
		t.Errorf("LoadImage deveria usar a imagem enviada, veio %v", got)
	}
	// O prompt do usuário precisa chegar no condicionamento POSITIVO (e não no negativo).
	if got := inputs("pos")["text"]; got != "a raposa respira" {
		t.Errorf("prompt não chegou no positivo, veio %v", got)
	}
	if got := inputs("neg")["text"]; got != previaNegative {
		t.Errorf("negativo trocado, veio %v", got)
	}
	// O sampler tem que rodar sobre o modelo COM shift (ModelSamplingSD3): ligado direto no
	// UNETLoader o Wan 2.2 sai borrado — foi o achado do bench.
	if got, ok := inputs("ks")["model"].([]any); !ok || len(got) != 2 || got[0] != "ms" {
		t.Errorf("KSampler deveria consumir o ModelSamplingSD3 (shift), veio %v", inputs("ks")["model"])
	}
	if got := inputs("ms")["shift"]; got != previaShift {
		t.Errorf("shift do sampling perdido, veio %v", got)
	}
	// O latente carrega o quadro inicial — é o que faz disto i2v e não t2v.
	if got, ok := inputs("lat")["start_image"].([]any); !ok || len(got) != 2 || got[0] != "img" {
		t.Errorf("o latente deveria partir da imagem, veio %v", inputs("lat")["start_image"])
	}
	if got := inputs("lat")["length"]; got != 73 {
		t.Errorf("quadros no latente = %v, queria 73", got)
	}
	// Seed explícito: o ComfyUI faz cache por grafo, e seed repetido devolveria o arquivo anterior
	// sem executar nada — a "nova" prévia seria a antiga.
	if got := inputs("ks")["seed"]; got != int64(42) {
		t.Errorf("seed não chegou no KSampler, veio %v", got)
	}
	// A saída precisa vir do VAEDecode; ligada no latente cru sairia ruído.
	if got, ok := inputs("out")["images"].([]any); !ok || len(got) != 2 || got[0] != "dec" {
		t.Errorf("a saída deveria vir do VAEDecode, veio %v", inputs("out")["images"])
	}
}

// Resposta de PÁGINA WEB (túnel caído/expirado) não pode virar "http 404": isso aponta pro nosso
// código quando o problema é o motor fora do ar. Aconteceu em 2026-07-30, com o Colab desconectado
// no meio de uma prévia — o log dizia 404 e parecia endpoint trocado.
func TestNaoEhComfyDistingueProxyDeApi(t *testing.T) {
	err := naoEhComfy(404, []byte("<!DOCTYPE html><html>ERR_NGROK_3200</html>"))
	if err == nil || !strings.Contains(err.Error(), "fora do ar") {
		t.Fatalf("HTML de proxy deveria virar erro de servidor fora do ar, veio: %v", err)
	}
	if !strings.Contains(err.Error(), "COMFY_URL") {
		t.Errorf("o erro deveria dizer onde olhar (COMFY_URL), veio: %v", err)
	}
	// Erro de verdade do ComfyUI (JSON) segue passando o corpo adiante, que é onde vem o motivo real.
	err = naoEhComfy(400, []byte(`{"error":{"message":"model not found"}}`))
	if err == nil || !strings.Contains(err.Error(), "model not found") {
		t.Fatalf("erro real do ComfyUI deveria chegar inteiro, veio: %v", err)
	}
}
