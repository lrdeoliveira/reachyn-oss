package content

import (
	"strings"
	"testing"

	"github.com/redfoxcode/reachyn/engine/internal/media"
)

// 🎬 O SEGUNDO PLANO É ARTE NOVA, E ELE ENTRA LOGO DEPOIS DO SEU CLIPE.
//
// O defeito que isto previne: com dois segmentos por beat, um achatamento errado intercala as
// stills fora de lugar e a peça narra a 2ª metade da frase 3 sobre a arte da frase 5 — falha
// silenciosa idêntica à da ordem embaralhada (nenhum erro, duração certa, só aparece
// transcrevendo).
func TestSegundoPlanoEntraLogoDepoisDoClipeDoMesmoBeat(t *testing.T) {
	slot := [][2]media.ShortBeat{
		{{ClipURL: "c0.mp4", Script: "primeira metade zero"}, {ImageURL: "i0.png", Script: "segunda metade zero"}},
		{{ClipURL: "c1.mp4", Script: "primeira metade um"}, {ImageURL: "i1.png", Script: "segunda metade um"}},
	}

	out := compactaNaOrdem(slot)

	quer := []string{"c0.mp4", "", "c1.mp4", ""}
	querImg := []string{"", "i0.png", "", "i1.png"}
	if len(out) != 4 {
		t.Fatalf("queria 4 segmentos (2 cenas × 2 planos), veio %d", len(out))
	}
	for i := range out {
		if out[i].ClipURL != quer[i] || out[i].ImageURL != querImg[i] {
			t.Fatalf("posição %d: clip=%q img=%q — queria clip=%q img=%q",
				i, out[i].ClipURL, out[i].ImageURL, quer[i], querImg[i])
		}
	}
}

// Still ÓRFÃ não entra: se o clipe da cena falhou, a imagem que narra a 2ª metade da frase viraria
// uma cena com meia frase e sem o plano que ela complementa.
func TestStillOrfaNaoEntraNaPeca(t *testing.T) {
	slot := [][2]media.ShortBeat{
		{{ClipURL: "c0.mp4"}, {ImageURL: "i0.png"}},
		{{}, {ImageURL: "i1.png"}}, // clipe falhou, still sobreviveu
		{{ClipURL: "c2.mp4"}, {}},  // clipe ok, 2ª imagem falhou → cena de um plano só
	}

	out := compactaNaOrdem(slot)

	if len(out) != 3 {
		t.Fatalf("queria 3 segmentos, veio %d", len(out))
	}
	for _, sb := range out {
		if sb.ImageURL == "i1.png" {
			t.Fatal("still órfã entrou na peça: a cena narra meia frase sem o clipe dela")
		}
	}
	if out[2].ClipURL != "c2.mp4" {
		t.Fatalf("cena sem 2ª imagem deveria seguir como plano único, veio %q", out[2].ClipURL)
	}
}

// ✂️ Com o segundo plano ligado, o recorte do MESMO clipe sai: o corte já virou troca de arte, e
// recortar de novo daria dois cortes dentro de ~2s (pisca-pisca, não montagem).
func TestShotSecsSaiQuandoTemSegundoPlano(t *testing.T) {
	if got := shotSecsDoBeat(VoxPreset, true); got != 0 {
		t.Fatalf("com segundo plano: shot_secs = %v, queria 0", got)
	}
	if got := shotSecsDoBeat(VoxPreset, false); got != voxShotSecs {
		t.Fatalf("sem segundo plano: shot_secs = %v, queria %v (o ritmo tem de vir de algum lugar)", got, voxShotSecs)
	}
	if got := shotSecsDoBeat("", true); got != 0 {
		t.Fatalf("fora do Vox: shot_secs = %v, queria 0", got)
	}
}

// ✂️ O trim de cabeça é EXCLUSIVO do Vox: todo o resto (filme, história, carrossel) continua
// entregando o clipe do primeiro quadro, byte a byte como antes.
func TestHeadTrimSoNoVox(t *testing.T) {
	if got := headTrim(VoxPreset); got != voxHeadTrim {
		t.Fatalf("vox: head_trim = %v, queria %v", got, voxHeadTrim)
	}
	for _, p := range []string{"", "filme", "historia"} {
		if got := headTrim(p); got != 0 {
			t.Fatalf("preset %q: head_trim = %v, queria 0 — o resto do produto não muda", p, got)
		}
	}
	if voxHeadTrim <= 0 || voxHeadTrim > 0.6 {
		t.Fatalf("voxHeadTrim = %v: acima de ~0,6s começa a comer conteúdo, não só a parada inicial", voxHeadTrim)
	}
}

func TestMetadeDoScript(t *testing.T) {
	casos := []struct{ nome, in, a, b string }{
		{
			nome: "corta na vírgula perto do meio",
			in:   "Em minutos, milhares se reuniram nos postos de fronteira",
			a:    "Em minutos,",
			b:    "milhares se reuniram nos postos de fronteira",
		},
		{
			nome: "sem pontuação cai no meio",
			in:   "os guardas não receberam nenhuma ordem clara naquela noite",
			a:    "os guardas não receberam",
			b:    "nenhuma ordem clara naquela noite",
		},
	}
	for _, c := range casos {
		t.Run(c.nome, func(t *testing.T) {
			a, b := metadeDoScript(c.in)
			if a != c.a || b != c.b {
				t.Fatalf("partiu em %q | %q, queria %q | %q", a, b, c.a, c.b)
			}
			// INVARIANTE: partir não pode perder nem inventar palavra — a narração é o produto.
			if strings.Join(strings.Fields(a+" "+b), " ") != strings.Join(strings.Fields(c.in), " ") {
				t.Fatal("as duas metades não remontam a frase original")
			}
		})
	}
}

// Frase curta demais NÃO é partida: duas imagens para quatro palavras é pisca-pisca. O chamador
// lê o "" e mantém o beat num segmento só.
func TestFraseCurtaNaoEPartida(t *testing.T) {
	for _, s := range []string{"", "o muro caiu", "ninguém planejou aquilo"} {
		if a, b := metadeDoScript(s); a != "" || b != "" {
			t.Fatalf("%q foi partida em %q | %q — queria manter inteira", s, a, b)
		}
	}
}

// 🛑 Recusa por conteúdo NÃO é instabilidade: repetir o mesmo prompt bloqueado gasta as tentativas
// pra receber a mesma recusa e a cena some em silêncio. Confundir com falta de crédito é pior
// ainda — manda o cliente recarregar a conta por um problema que não é de saldo.
func TestErroDePoliticaDistingueDosOutrosErros(t *testing.T) {
	bloqueios := []string{
		"content policy violation",
		"request was blocked by safety filters",
		"geração bloqueada: conteúdo sensível",
		"prompt flagged by moderation",
	}
	for _, m := range bloqueios {
		if !erroDePolitica(errString(m)) {
			t.Fatalf("%q deveria ser reconhecido como recusa por conteúdo", m)
		}
	}
	outros := []string{
		"sem créditos na conta do provedor",
		"not_enough_credits",
		"connection reset by peer",
		"502 bad gateway",
		"context deadline exceeded",
	}
	for _, m := range outros {
		if erroDePolitica(errString(m)) {
			t.Fatalf("%q NÃO é recusa por conteúdo — reescrever o prompt não resolve isso", m)
		}
	}
	if erroDePolitica(nil) {
		t.Fatal("erro nulo não é recusa")
	}
	// E as duas classes não se confundem entre si.
	if erroDePolitica(errString("sem créditos")) || !erroDeCredito(errString("sem créditos")) {
		t.Fatal("falta de crédito classificada como política")
	}
}

type errString string

func (e errString) Error() string { return string(e) }

// 🔊 O ganho do SFX do Vox é DISCRETO por contrato: o default do serviço (0.9) é ganho de efeito
// dramático, e a doutrina do formato é "só uma coisa alta por vez" — a coisa alta é a voz.
func TestSfxGainDiscretoESoComPrompt(t *testing.T) {
	if got := sfxGain(""); got != 0 {
		t.Fatalf("sem prompt: gain = %v, queria 0 (cena sem SFX não manda gain)", got)
	}
	g := sfxGain("paper sliding")
	if g <= 0 || g >= 0.9 {
		t.Fatalf("gain = %v: precisa existir e ficar ABAIXO do default dramático (0.9)", g)
	}
}

// 🎞️ A cadência do formato: 12 quadros/s efetivos (a referência renderiza gráficos a 12fps).
// Fora da faixa 12..24 o efeito vira defeito (menos) ou some (mais).
func TestVoxFPSDelayNaCadenciaDaReferencia(t *testing.T) {
	if voxFPSDelay != 12 {
		t.Fatalf("voxFPSDelay = %d, queria 12 (cadência medida da referência)", voxFPSDelay)
	}
}

// O prompt de segmentação do Vox PEDE os campos que o pipeline consome. Prompt e struct andam
// juntos: campo pedido que o JSON não declara é campo que o LLM não devolve — e o recurso
// inteiro (2ª arte, SFX) morre em silêncio na origem.
func TestVoxBeatSystemPedeOsCamposNovos(t *testing.T) {
	sys := voxBeatSystem(6, 5, 13, 65, "português", "VERTICAL (9:16)", "vertical PORTRAIT 9:16", "", "")
	for _, campo := range []string{`"image_prompt_b"`, `"sfx"`} {
		if !strings.Contains(sys, campo) {
			t.Fatalf("voxBeatSystem não pede %s no JSON de saída", campo)
		}
	}
}
