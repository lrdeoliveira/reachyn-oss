package content

import "testing"

// Campos que o console MANDAVA e o engine DESCARTAVA em silêncio (auditoria 2026-08-01):
// `imageUrls` (multi-referência do lote de cenas do Roteiro) e `gradeStrength` do /v1/video.
// Estes testes travam as duas garantias: as refs extras chegam ao provider E o pedido antigo
// (só `imageUrl`) continua produzindo exatamente a mesma lista de uma âncora.

func eq(t *testing.T, got, want []string) {
	t.Helper()
	if len(got) != len(want) {
		t.Fatalf("refs = %v, queria %v", got, want)
	}
	for i := range want {
		if got[i] != want[i] {
			t.Fatalf("refs = %v, queria %v", got, want)
		}
	}
}

// RETROCOMPATIBILIDADE: sem `imageUrls`, o resultado é o de sempre — nenhuma âncora (t2v) ou
// exatamente a única de `imageUrl`.
func TestVideoOptionsRefsRetrocompativel(t *testing.T) {
	if got := (VideoOptions{}).refs(); len(got) != 0 {
		t.Fatalf("sem imagem = %v, queria vazio (t2v)", got)
	}
	eq(t, VideoOptions{ImageURL: "https://s3/a.jpg"}.refs(), []string{"https://s3/a.jpg"})
}

// A 1ª âncora continua sendo `imageUrl` (contrato histórico) e as extras entram DEPOIS, na ordem.
func TestVideoOptionsRefsMultiReferencia(t *testing.T) {
	o := VideoOptions{ImageURL: "a", ImageURLs: []string{"b", "c"}}
	eq(t, o.refs(), []string{"a", "b", "c"})
}

// Só `imageUrls` (sem singular) também vale — o console pode mandar a lista sozinha.
func TestVideoOptionsRefsSoLista(t *testing.T) {
	eq(t, VideoOptions{ImageURLs: []string{"a", "b"}}.refs(), []string{"a", "b"})
}

// Repetida (o console manda `imageUrl` = imageUrls[0]) não vira ref duplicada — duplicata é
// referência cobrada duas vezes e confunde o modelo. Vazia/espaço é descartada.
func TestVideoOptionsRefsDedupEIgnoraVazias(t *testing.T) {
	o := VideoOptions{ImageURL: "a", ImageURLs: []string{"a", "", "   ", "b", "b"}}
	eq(t, o.refs(), []string{"a", "b"})
}

func TestRefsHead(t *testing.T) {
	if got := refsHead(nil); got != "" {
		t.Fatalf("refsHead(nil) = %q, queria vazio", got)
	}
	if got := refsHead([]string{"a", "b"}); got != "a" {
		t.Fatalf("refsHead = %q, queria a", got)
	}
}
