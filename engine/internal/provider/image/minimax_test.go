package image

import (
	"strings"
	"testing"
)

// Caso real 2026-07-17: a prancha de ângulos da Mel saiu com as 10 células de frente — o clamp
// ingênuo (cortar do fim) sempre derrubava a instrução de câmera, que fica DEPOIS do identity
// lock no prompt do model sheet. smartClamp preserva o trecho a partir de shotFrameMarker.
func TestSmartClampPreservesShotFraming(t *testing.T) {
	head := strings.Repeat("Locked identity trait filler text. ", 40) // >1000 runas de boilerplate
	tail := shotFrameMarker + " on a plain seamless light-gray studio background: " +
		"Full-body LEFT-SIDE profile (90°): perfect side view, nose pointing at the LEFT edge."
	prompt := head + tail

	out, mode := smartClamp(prompt, 400)

	if mode != "smart" {
		t.Fatalf("smartClamp não usou o modo esperado: got %q, want \"smart\"", mode)
	}
	if !strings.Contains(out, "LEFT-SIDE profile (90°)") {
		t.Fatalf("smartClamp derrubou a instrução de ângulo — out: %q", out)
	}
	if !strings.Contains(out, shotFrameMarker) {
		t.Fatalf("smartClamp derrubou o marcador de enquadramento — out: %q", out)
	}
	if len([]rune(out)) > 400 {
		t.Fatalf("smartClamp não respeitou o teto: %d runas", len([]rune(out)))
	}
}

// Regressão do caso real: content.go's CompilePrompt PREPENDE i2iIdentityRule (~900 runas) antes
// do prompt do ModelSheetService quando anchorIdentity&&hasImages — TODO shot do model sheet passa
// por aqui. O marcador precisa sobreviver com esse prefixo extra na frente também.
func TestSmartClampFindsMarkerWithIdentityRulePrefix(t *testing.T) {
	i2iIdentityRule := "IDENTITY LOCK — reproduce the subject(s) from the reference image(s) with their EXACT identity: " +
		strings.Repeat("same face and features, ", 30) // simula o tamanho real (~900 runas)
	body := "The EXACT SAME character as in the reference image named \"Mel\" — " +
		strings.Repeat("Locked identity trait filler text. ", 30) +
		shotFrameMarker + " on a plain seamless light-gray studio background: " +
		"Full-body LEFT-SIDE profile (90°): perfect side view, nose pointing at the LEFT edge."
	prompt := i2iIdentityRule + "\n" + body

	out, mode := smartClamp(prompt, 980)

	if mode != "smart" {
		t.Fatalf("smartClamp não achou o marcador com o prefixo do identity rule — modo=%q", mode)
	}
	if !strings.Contains(out, "LEFT-SIDE profile (90°)") {
		t.Fatalf("smartClamp derrubou a instrução de ângulo com o prefixo do identity rule — out: %q", out)
	}
}

// Sem o marcador (ex: prompt da imagem-base, sem o scaffold do model sheet) cai no corte simples
// do fim — não pode quebrar nem sair maior que o teto.
func TestSmartClampFallsBackWithoutMarker(t *testing.T) {
	prompt := strings.Repeat("word ", 500) // 2500 runas, sem shotFrameMarker
	out, mode := smartClamp(prompt, 400)
	if mode != "naive-end" {
		t.Fatalf("smartClamp não usou o modo esperado: got %q, want \"naive-end\"", mode)
	}
	if len([]rune(out)) > 400 {
		t.Fatalf("smartClamp não respeitou o teto sem marcador: %d runas", len([]rune(out)))
	}
}

// Prompt já dentro do teto não deve ser tocado.
func TestSmartClampNoopWhenShort(t *testing.T) {
	prompt := "short prompt"
	out, mode := smartClamp(prompt, 400)
	if out != prompt || mode != "noop" {
		t.Fatalf("smartClamp mexeu num prompt que já cabia: out=%q mode=%q", out, mode)
	}
}

// Cauda (a partir do marcador) maior que o próprio teto: corta a cauda do FIM pra dentro, nunca
// do início — o ângulo pedido é o primeiro trecho depois do marcador, não pode sumir aqui também.
func TestSmartClampTailLongerThanBudget(t *testing.T) {
	tail := shotFrameMarker + ": Full-body LEFT-SIDE profile (90°), " + strings.Repeat("more detail ", 100)
	out, mode := smartClamp(tail, 80)
	if mode != "naive-tail" {
		t.Fatalf("smartClamp não usou o modo esperado: got %q, want \"naive-tail\"", mode)
	}
	if !strings.Contains(out, "LEFT-SIDE profile") {
		t.Fatalf("smartClamp perdeu o início da cauda mesmo ela estourando o teto: %q", out)
	}
	if len([]rune(out)) > 80 {
		t.Fatalf("smartClamp não respeitou o teto: %d runas", len([]rune(out)))
	}
}

func TestSanitizeForMinimaxStripsSensitiveAndFiller(t *testing.T) {
	in := "Locked identity: Female dachshund. Identical proportions across all scenes; " +
		"100% visual consistency required; Clean professional reference shot: do NOT depict any genitalia, " +
		"keep the underside smooth and neutral. Studio catalog reference shot: front view."
	out := sanitizeForMinimax(in)
	if strings.Contains(out, "genitalia") {
		t.Fatalf("sanitizeForMinimax deixou a frase sensível passar: %q", out)
	}
	if strings.Contains(out, "Identical proportions across all scenes") {
		t.Fatalf("sanitizeForMinimax deixou o boilerplate genérico passar: %q", out)
	}
	if !strings.Contains(out, "Studio catalog reference shot: front view") {
		t.Fatalf("sanitizeForMinimax removeu conteúdo que devia ficar: %q", out)
	}
}
