package content

import (
	"strings"
	"testing"
)

// 📏 A RÉGUA. O bug de produção era narração cortada no meio: o roteiro do beat não cabia no
// clipe. Estes testes travam a régua medida (12,2 caracteres/s) e o teto que sai dela — sem eles
// o número volta a virar palpite espalhado pelo código.

func TestBeatScriptSizeSaiDaRegua(t *testing.T) {
	casos := []struct {
		duration           string
		fala               float64
		secs, words, chars int
	}{
		{"6", 5.4, 5, 12, 65},
		{"10", 9.4, 9, 22, 114},
		{"", 5.4, 5, 12, 65},    // vazio = default "6"
		{"999", 5.4, 5, 12, 65}, // inválido = default "6"
	}
	for _, c := range casos {
		secs, words, chars := beatScriptSize(c.duration)
		if secs != c.secs || words != c.words || chars != c.chars {
			t.Fatalf("beatScriptSize(%q) = (%d,%d,%d), queria (%d,%d,%d)",
				c.duration, secs, words, chars, c.secs, c.words, c.chars)
		}
		// O teto TEM de ser a régua aplicada à janela falada — não um número escolhido à mão.
		if got := int(c.fala * charsPerSec); got != chars {
			t.Fatalf("duração %q: teto %d divergiu da régua (%.1f s × %.1f c/s = %d)",
				c.duration, chars, c.fala, charsPerSec, got)
		}
	}
}

func TestReguaEhAMedida(t *testing.T) {
	// Medida no piloto do FoxAssets. Mudar aqui muda o produto inteiro — que é o ponto de haver
	// UM lugar canônico.
	if charsPerSec != 12.2 {
		t.Fatalf("charsPerSec = %v, queria 12.2 (régua MEDIDA de locução PT-BR)", charsPerSec)
	}
	// Sanidade cruzada: ~5,1 caracteres por palavra em PT-BR.
	if r := charsPerSec / wordsPerSec; r < 4.5 || r > 6.0 {
		t.Fatalf("charsPerSec/wordsPerSec = %.2f — fora da faixa de tamanho de palavra em PT-BR", r)
	}
	// Contra-prova do enunciado do bug: num clipe de 8s cabem ~97 caracteres brutos.
	oito := 8.0
	if brutos := int(oito * charsPerSec); brutos < 95 || brutos > 99 {
		t.Fatalf("8s × régua = %d caracteres, queria ~97", brutos)
	}
}

func TestEncurtaScriptNuncaEstouraNemCortaPalavra(t *testing.T) {
	casos := []struct {
		nome, in, quer string
		max            int
	}{
		{"cabe: intacto", "A frase curta.", "A frase curta.", 65},
		{"corta em fim de frase", "O custo despencou. E as prateleiras esvaziaram no mês seguinte.", "O custo despencou.", 40},
		{"corta em cláusula, vira ponto", "O custo despencou em março, e as prateleiras esvaziaram depois", "O custo despencou em março.", 40},
		{"corta em palavra inteira", "O custo despencou muito rapidamente naquele trimestre inteiro", "O custo despencou muito.", 24},
		{"teto zero = sem teto", "qualquer coisa aqui", "qualquer coisa aqui", 0},
	}
	for _, c := range casos {
		got := encurtaScript(c.in, c.max)
		if got != c.quer {
			t.Fatalf("%s: encurtaScript(%q, %d) = %q, queria %q", c.nome, c.in, c.max, got, c.quer)
		}
		if c.max > 0 && len([]rune(got)) > c.max {
			t.Fatalf("%s: saída com %d runas estourou o teto %d", c.nome, len([]rune(got)), c.max)
		}
		// NUNCA corta palavra no meio: a última palavra da saída tem de existir na entrada.
		if f := strings.Fields(strings.Trim(got, ".")); len(f) > 0 && !strings.Contains(c.in, f[len(f)-1]) {
			t.Fatalf("%s: última palavra %q não existe na entrada — cortou no meio", c.nome, f[len(f)-1])
		}
	}
}

func TestClampBeatScriptsCobreTodoOLote(t *testing.T) {
	_, _, max := beatScriptSize("6")
	longo := "As prateleiras do supermercado esvaziaram em menos de três dias, e o preço do arroz dobrou na semana seguinte."
	beats := clampBeatScripts([]Beat{{Script: longo}, {Script: "curta."}, {Script: longo}}, max)
	for i, b := range beats {
		if n := len([]rune(b.Script)); n > max {
			t.Fatalf("beat %d saiu com %d caracteres (teto %d): %q", i, n, max, b.Script)
		}
	}
	if beats[1].Script != "curta." {
		t.Fatalf("beat que já cabia foi mexido: %q", beats[1].Script)
	}
}

func TestPromptVoxDizOTetoEmCaracteres(t *testing.T) {
	_, words, max := beatScriptSize("6")
	sys := voxBeatSystem(6, 5, words, max, "português do Brasil", "VERTICAL (9:16)", "vertical PORTRAIT 9:16", "", "")
	for _, quer := range []string{"65 CARACTERES", "12.2 caracteres por segundo", "CORTADA NO MEIO"} {
		if !strings.Contains(sys, quer) {
			t.Fatalf("prompt Vox não diz %q — o roteirista não sabe o teto", quer)
		}
	}
}
