package identity

import (
	"regexp"
	"strings"
	"testing"
)

// contémPalavra — asserção por PALAVRA INTEIRA: Contains(out, "male") acharia o "male"
// dentro de "FEMALE" e acusaria o próprio texto correto (aconteceu na 1ª versão do teste).
func contémPalavra(s, palavra string) bool {
	return regexp.MustCompile(`(?i)\b` + palavra + `\b`).MatchString(s)
}

// A primeira linha REAL do lock da MEL (personagem 14) — com as cláusulas negadas que
// desligavam a guarda na primeira versão do detector: o "male" de "NO male anatomy" fazia
// o texto parecer misto e a proteção sumia exatamente no personagem que a motivou.
const lockMel = "FEMALE dachshund — a female dog: feminine slender build, smooth underbelly, NO male anatomy, no visible genitalia, no scrotum, no sheath"

// O caso que motivou o pacote: lock da MEL afirma fêmea → o negativo tem que nomear a
// anatomia de macho que o prior desenhava (sheath/testicles do frame t=34s, draft 84) —
// MESMO com o "male" aparecendo em cláusula negada no texto.
func TestSexNegativeFemea(t *testing.T) {
	neg := SexNegative(lockMel)
	for _, termo := range []string{"male", "sheath", "testicles"} {
		if !strings.Contains(neg, termo) {
			t.Fatalf("positivo fêmea deveria negar %q, veio: %q", termo, neg)
		}
	}
	if strings.Contains(neg, "female") {
		t.Fatalf("negar macho não pode suprimir a própria fêmea, veio: %q", neg)
	}
}

// O espelho: personagem macho → suprime o desvio pra fêmea.
func TestSexNegativeMacho(t *testing.T) {
	neg := SexNegative("NORDY: male red fox, he wears a leather satchel")
	if !strings.Contains(neg, "female") || !strings.Contains(neg, "woman") {
		t.Fatalf("positivo macho deveria negar fêmea, veio: %q", neg)
	}
}

// Só os pronomes bastam: na prévia i2v o lock sai do texto e a ação que sobra diz
// "she wags her tail" — é o único sinal disponível e tem que ser suficiente.
func TestSexNegativePronome(t *testing.T) {
	if neg := SexNegative("the dachshund runs along the pier, she wags her tail"); !strings.Contains(neg, "male") {
		t.Fatalf("pronome fêmea deveria bastar pra negar macho, veio: %q", neg)
	}
}

// Cena com os DOIS sexos AFIRMADOS não pode negar nenhum dos lados — negar "male"
// apagaria o personagem macho legítimo da cena.
func TestSexNegativeMisto(t *testing.T) {
	if neg := SexNegative("the female dog greets a man at the door"); neg != "" {
		t.Fatalf("sinal misto deveria devolver vazio, veio: %q", neg)
	}
}

// Sem sinal não se inventa um.
func TestSexNegativeSemSinal(t *testing.T) {
	if neg := SexNegative("a lighthouse on a rocky hill at dusk, fog rolling in"); neg != "" {
		t.Fatalf("sem sinal de sexo deveria devolver vazio, veio: %q", neg)
	}
}

// A fronteira de palavra é o que impede o bug clássico: "female" contém "male" e
// "woman"/"human" contêm "man" — substring dentro de palavra NÃO pode disparar o
// marcador oposto, senão todo prompt fêmea viraria "misto" e a proteção sumiria.
func TestSexNegativeFronteiraDePalavra(t *testing.T) {
	if neg := SexNegative("a female human woman in the theatre"); !strings.Contains(neg, "male anatomy") {
		t.Fatalf("female/woman/human não podem disparar o marcador de macho, veio: %q", neg)
	}
	// E o inverso: "she"/"her" dentro de outras palavras ("shed", "there", "hero").
	if neg := SexNegative("a male fox near the shed, there is a hero statue"); !strings.Contains(neg, "female") {
		t.Fatalf("shed/there/hero não podem disparar o marcador de fêmea, veio: %q", neg)
	}
}

// A negação textual no positivo é um pedido invertido pro CLIP: "no scrotum" entrega o
// token "scrotum" pro modelo desenhar. As cláusulas negadas de sexo/anatomia SAEM do
// positivo — e a linha continua legível (sem vírgulas em série nem pendurada).
func TestStripSexNegationsMel(t *testing.T) {
	out := StripSexNegations(lockMel)
	for _, termo := range []string{"male", "genitalia", "scrotum", "sheath"} {
		if contémPalavra(out, termo) {
			t.Fatalf("o positivo não pode entregar o token %q pro CLIP, veio: %q", termo, out)
		}
	}
	if !strings.Contains(out, "FEMALE dachshund") || !strings.Contains(out, "smooth underbelly") {
		t.Fatalf("as afirmações têm que sobreviver à limpeza, veio: %q", out)
	}
	if strings.Contains(out, ", ,") || strings.HasSuffix(out, ",") {
		t.Fatalf("a limpeza deixou vírgula sobrando: %q", out)
	}
}

// O fluxo 3D não menciona sexo nunca: linha de SEX: some inteira, palavras de sexo somem,
// e o que sobra continua legível — espécie, cores e acessórios intactos.
func TestSemSexo(t *testing.T) {
	in := lockMel + "\nSignature colors: mahogany-red fur (#8B3A1A)\nSEX: MEL is FEMALE — keep this sex in EVERY frame, from every camera angle"
	out := SemSexo(in)
	for _, termo := range []string{"female", "male", "sex", "sheath", "scrotum", "genitalia"} {
		if contémPalavra(out, termo) {
			t.Fatalf("o texto 3D não pode mencionar %q, veio: %q", termo, out)
		}
	}
	for _, termo := range []string{"dachshund", "mahogany-red", "slender build"} {
		if !strings.Contains(out, termo) {
			t.Fatalf("espécie/cores/forma têm que sobreviver, veio: %q", out)
		}
	}
	if strings.Contains(out, "  ") || strings.Contains(out, " ,") {
		t.Fatalf("a limpeza deixou espaçamento sobrando: %q", out)
	}
}

// Só as negações de SEXO/ANATOMIA saem — "No redesigns"/"no cuts" são instrução de
// consistência sem token anatômico e ficam onde estão.
func TestStripSexNegationsPreservaOutrasNegacoes(t *testing.T) {
	in := "FEMALE dachshund, no sheath\nNo redesigns\n100% visual consistency required"
	out := StripSexNegations(in)
	if !strings.Contains(out, "No redesigns") {
		t.Fatalf("negação sem anatomia deveria ficar, veio: %q", out)
	}
	if contémPalavra(out, "sheath") {
		t.Fatalf("a cláusula anatômica deveria sair, veio: %q", out)
	}
}
