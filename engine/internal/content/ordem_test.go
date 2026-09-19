package content

import (
	"fmt"
	"math/rand"
	"sync"
	"testing"

	"github.com/redfoxcode/reachyn/engine/internal/media"
)

// 🎬 A ORDEM DA PEÇA É A ORDEM DO ROTEIRO — nunca a ordem de chegada dos clipes.
//
// O defeito (peças 381 e 382, 2026-08-04): as cenas eram acrescentadas com `append` de dentro da
// goroutine de cada uma, então a montagem saía na ordem em que o PROVEDOR terminava — e o tempo
// varia de 3 a 6 minutos por cena. O roteiro saía perfeito do segmentador e chegava embaralhado
// na tela: conclusão no meio, gancho no fim, frases abrindo com "ela" sem antecedente.
//
// Falha silenciosa: nenhum erro, nenhuma cena perdida, duração certa. Só aparece TRANSCREVENDO a
// narração — olhando os quadros a peça parece certa. Por isso vira teste.
func TestMontagemSegueAOrdemDosBeatsNaoADeChegada(t *testing.T) {
	const n = 12
	slot := make([][2]media.ShortBeat, n)
	var wg sync.WaitGroup
	// Preenche em ordem EMBARALHADA de propósito — é o que a concorrência faz na vida real.
	ordem := rand.Perm(n)
	for _, i := range ordem {
		wg.Add(1)
		go func(i int) {
			defer wg.Done()
			slot[i][0] = media.ShortBeat{ClipURL: fmt.Sprintf("clipe-%02d.mp4", i), Script: fmt.Sprintf("frase %d", i)}
		}(i)
	}
	wg.Wait()

	out := compactaNaOrdem(slot)
	if len(out) != n {
		t.Fatalf("perdeu cena: %d de %d", len(out), n)
	}
	for i, sb := range out {
		if want := fmt.Sprintf("clipe-%02d.mp4", i); sb.ClipURL != want {
			t.Fatalf("posição %d: %q — a peça saiu fora da ordem do roteiro (queria %q)", i, sb.ClipURL, want)
		}
	}
}

// Cena que falhou some, mas NÃO empurra as outras pra posição errada: o resto continua na ordem
// do roteiro. É a diferença entre uma peça mais curta e uma peça sem sentido.
func TestCenaQueFalhouNaoDesalinhaOResto(t *testing.T) {
	slot := [][2]media.ShortBeat{
		{{ClipURL: "a.mp4"}, {}}, {{}, {}}, {{ClipURL: "c.mp4"}, {}}, {{}, {}}, {{ClipURL: "e.mp4"}, {}},
	}

	out := compactaNaOrdem(slot)

	if len(out) != 3 {
		t.Fatalf("queria 3 cenas boas, veio %d", len(out))
	}
	for i, want := range []string{"a.mp4", "c.mp4", "e.mp4"} {
		if out[i].ClipURL != want {
			t.Fatalf("posição %d: %q, queria %q", i, out[i].ClipURL, want)
		}
	}
}

func TestTodasAsCenasFalharam(t *testing.T) {
	if out := compactaNaOrdem(make([][2]media.ShortBeat, 4)); len(out) != 0 {
		t.Fatalf("queria vazio, veio %d", len(out))
	}
}
