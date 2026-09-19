package content

import (
	"strings"
	"testing"
)

func TestI2IIdentityRuleKeepsHumanAndAnimalIdentity(t *testing.T) {
	for _, want := range []string{
		"Never change a human into an animal",
		"an animal into a human",
		"biological sex",
	} {
		if !strings.Contains(i2iIdentityRule, want) {
			t.Errorf("identity rule must contain %q", want)
		}
	}
}

func TestCompilePromptWithAnchorIdentity(t *testing.T) {
	s := &Service{}
	prompt := "A character walking in a park."
	
	// Case 1: anchorIdentity = false, hasImages = false
	got1 := s.CompilePrompt(prompt, nil, "", "", false, false)
	if got1 != prompt {
		t.Errorf("CompilePrompt(..., false, false) = %q; want %q", got1, prompt)
	}

	// Case 2: anchorIdentity = true, hasImages = false (should not prepend rule since no images)
	got2 := s.CompilePrompt(prompt, nil, "", "", true, false)
	if got2 != prompt {
		t.Errorf("CompilePrompt(..., true, false) = %q; want %q", got2, prompt)
	}

	// Case 3: anchorIdentity = true, hasImages = true
	got3 := s.CompilePrompt(prompt, nil, "", "", true, true)
	expectedPrefix := strings.TrimSpace(i2iIdentityRule) + "\n"
	if !strings.HasPrefix(got3, expectedPrefix) {
		t.Errorf("CompilePrompt(..., true, true) should start with the identity rule prefix. Got: %q", got3)
	}
	if !strings.HasSuffix(got3, prompt) {
		t.Errorf("CompilePrompt(..., true, true) should end with prompt %q. Got: %q", prompt, got3)
	}
}

// A persona é o estilo escolhido no console. Sem ela o prompt tem de sair intacto
// (retrocompat: todo caller que ainda não passa persona não pode mudar de comportamento).
func TestCompilePromptComPersona(t *testing.T) {
	s := &Service{}
	prompt := "A fox on a street."

	if got := s.CompilePrompt(prompt, nil, "", "", false, false); got != prompt {
		t.Errorf("persona vazia deveria deixar o prompt intacto; veio %q", got)
	}

	got := s.CompilePrompt(prompt, nil, "", "Film noir: hard shadows, venetian blinds", false, false)
	if !strings.HasPrefix(got, prompt) {
		t.Errorf("persona não pode reescrever o pedido do usuário; veio %q", got)
	}
	if !strings.Contains(got, "Film noir: hard shadows") {
		t.Errorf("persona não chegou no prompt final; veio %q", got)
	}

	// Teto de 1200: persona é direção de estilo, não roteiro — não pode afogar o pedido.
	longa := strings.Repeat("x", 2000)
	if got := s.CompilePrompt(prompt, nil, "", longa, false, false); len(got) > len(prompt)+1300 {
		t.Errorf("persona longa não foi truncada: %d chars", len(got))
	}
}

// Paleta e persona coexistem: são direções diferentes (cor global x estilo do plano) e
// desligar uma não pode desligar a outra.
func TestCompilePromptPaletaEPersonaJuntas(t *testing.T) {
	s := &Service{}
	got := (&Service{}).CompilePrompt("A car.", nil, "neon_noir", "Handheld documentary feel", false, false)
	_ = s
	if !strings.Contains(got, "Style direction") {
		t.Errorf("persona sumiu quando havia paleta: %q", got)
	}
	if !strings.Contains(got, "color palette") {
		t.Errorf("paleta sumiu quando havia persona: %q", got)
	}
}

// Regressão do caso real 2026-07-17: TODO shot do model sheet inclui a cláusula anatômica do
// identity lock ("Clean professional reference shot: do NOT depict any genitalia...") — " do "
// batia na lista de gatilhos de hasPortuguese, um prompt 100% em inglês entrava no caminho de
// "traduzir" (toEnglishPrompt), a LLM reescrevia/resumia o texto já-inglês e derrubava a
// instrução de câmera de todo shot (motivo real da prancha de ângulos sair inteira de frente,
// não a suposta limitação do subject_reference). "do"/"da" removidos da lista por serem palavras
// curtas demais e colidirem com inglês comum.
func TestHasPortugueseIgnoresEnglishDo(t *testing.T) {
	english := "Clean professional reference shot: do NOT depict any genitalia, keep the underside smooth and neutral."
	if hasPortuguese(english) {
		t.Errorf("hasPortuguese(%q) = true; want false (palavra 'do' em inglês não pode disparar tradução)", english)
	}
}

func TestHasPortugueseStillDetectsRealPortuguese(t *testing.T) {
	cases := []string{
		"uma cachorra salsicha com coleira rosa", // " uma " e " com "
		"personagem que usa óculos",              // " que " + acento
		"referência de personagem",               // acento + " de "
	}
	for _, s := range cases {
		if !hasPortuguese(s) {
			t.Errorf("hasPortuguese(%q) = false; want true (é português de verdade)", s)
		}
	}
}
