package content

import (
	"strings"
	"testing"
)

// 🐛 REGRESSÃO que estes testes trancam: `splitIdentityBlock` foi escrita em 667d6ad — o commit
// "fix do IDENTITY LOCK (Mel saiu macho)" — e NUNCA foi chamada por ninguém. Nasceu morta, então
// a trava textual jamais esteve ativa: a ficha de identidade ia inteira pro LLM que elabora o
// prompt do clipe, e elaborar uma ficha é RESUMI-LA. "pink fabric collar with small circular gold
// metal tag engraved 'Mel'" volta como "wearing a collar", e o traço que segurava a identidade
// some antes de chegar ao modelo de vídeo. Falha silenciosa: nada erra, só sai diferente.
//
// Achado em 2026-08-02 por varredura de funções Go sem chamador.

// A ficha real que o console monta (App\Support\IdentityLock::bloco) — o formato importa: é o
// contrato entre os dois lados, e é ele que o marcador tem de reconhecer.
const fichaExemplo = identityBlockMarker + " MEL: golden retriever fêmea, pink fabric collar with small circular gold metal tag engraved 'Mel' | fur: cream-gold, slightly wavy ears"

func TestSplitIdentityBlockSeparaAcaoDaFicha(t *testing.T) {
	acao, ficha := splitIdentityBlock("Mel corre pelo parque ao amanhecer. " + fichaExemplo)

	if acao != "Mel corre pelo parque ao amanhecer" {
		t.Errorf("ação = %q; a pontuação final deve sair junto com a separação", acao)
	}
	if ficha != fichaExemplo {
		t.Errorf("ficha = %q; ela precisa sair VERBATIM — é o ponto inteiro da função", ficha)
	}
}

func TestSplitIdentityBlockSemMarcadorNaoMudaNada(t *testing.T) {
	p := "Mel corre pelo parque ao amanhecer"
	acao, ficha := splitIdentityBlock(p)

	// Retrocompat: prompt sem ficha tem de seguir exatamente o caminho de antes.
	if acao != p || ficha != "" {
		t.Errorf("sem marcador esperava (%q, \"\"), veio (%q, %q)", p, acao, ficha)
	}
}

func TestSplitIdentityBlockSoFichaViraTema(t *testing.T) {
	// Prompt que é SÓ ficha: não há ação pra elaborar. Devolver ação vazia faria o redator
	// receber "Theme: " e INVENTAR uma cena do nada — o mesmo modo de falha que a diretiva
	// anti-alucinação do i2v já combate em generateSingleClip.
	acao, ficha := splitIdentityBlock(fichaExemplo)

	if acao != fichaExemplo || ficha != "" {
		t.Errorf("ficha sozinha deve virar o tema; veio (%q, %q)", acao, ficha)
	}
}

func TestFichaSobreviveInteiraAoRecolar(t *testing.T) {
	// O que o chamador faz depois do split: manda só a ação pro LLM e recola a ficha. Este
	// teste prova o INVARIANTE que importa — o detalhe fino chega ao modelo intacto, mesmo
	// quando o "LLM" devolve algo completamente diferente da ação original.
	acao, ficha := splitIdentityBlock("Mel corre pelo parque. " + fichaExemplo)

	elaborado := "A golden retriever sprints across a sunlit park, cinematic tracking shot"
	final := elaborado
	if ficha != "" {
		final += "\n\n" + ficha
	}

	for _, detalhe := range []string{
		"small circular gold metal tag engraved 'Mel'",
		"cream-gold, slightly wavy ears",
		"fêmea",
	} {
		if !strings.Contains(final, detalhe) {
			t.Errorf("o detalhe %q não sobreviveu — é exatamente ele que o resumo do LLM apagava", detalhe)
		}
	}
	if strings.Contains(acao, identityBlockMarker) {
		t.Error("a ação não pode levar o marcador junto: seria mandar a ficha pro redator de novo")
	}
}
