package content

import "testing"

func TestSpecImageDirective(t *testing.T) {
	if got := specImageDirective(nil); got != "" {
		t.Fatalf("spec nulo deve dar diretiva vazia, veio %q", got)
	}
	if got := specImageDirective(&SceneSpec{}); got != "" {
		t.Fatalf("spec vazio deve dar diretiva vazia, veio %q", got)
	}
	// Key fora do vocabulário é ignorada (retrocompat / robustez a lixo do usuário).
	if got := specImageDirective(&SceneSpec{Shot: "inexistente", Light: "nope"}); got != "" {
		t.Fatalf("keys inválidas devem ser ignoradas, veio %q", got)
	}
	sp := &SceneSpec{Shot: "closeup", Light: "golden", Emotion: "quiet determination"}
	got := specImageDirective(sp)
	for _, want := range []string{"Cinematography:", "Close-up", "golden-hour", "quiet determination"} {
		if !contains(got, want) {
			t.Fatalf("diretiva %q não contém %q", got, want)
		}
	}
}

func TestSpecMoveDirective(t *testing.T) {
	if got := specMoveDirective(nil); got != "" {
		t.Fatalf("spec nulo deve dar movimento vazio, veio %q", got)
	}
	if got := specMoveDirective(&SceneSpec{Movement: "nope"}); got != "" {
		t.Fatalf("movimento inválido deve ser vazio, veio %q", got)
	}
	if got := specMoveDirective(&SceneSpec{Movement: "push_in"}); !contains(got, "push-in") {
		t.Fatalf("push_in deve virar frase de push-in, veio %q", got)
	}
}

func contains(s, sub string) bool {
	return len(sub) == 0 || (len(s) >= len(sub) && indexOf(s, sub) >= 0)
}

func indexOf(s, sub string) int {
	for i := 0; i+len(sub) <= len(s); i++ {
		if s[i:i+len(sub)] == sub {
			return i
		}
	}
	return -1
}
