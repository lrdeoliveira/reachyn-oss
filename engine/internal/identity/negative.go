// Package identity — regras de identidade de personagem compartilhadas pelos providers de
// geração. Nasceu do incidente da MEL (dachshund FÊMEA saindo MACHO: 2026-07-26 no i2v,
// 2026-07-29 no lock com hedge, 2026-07-31 de novo no Estúdio/SDXL): o prompt POSITIVO já
// afirmava o sexo ("FEMALE dachshund… NO male anatomy") e não bastou — modelo de difusão
// não lê instrução. O CLIP pesa tokens e não entende NEGAÇÃO: "no scrotum, no sheath" no
// positivo entrega os tokens "scrotum, sheath" pro modelo DESENHAR. O canal que o ComfyUI
// dá pra dizer "isto NUNCA" é o conditioning NEGATIVO do KSampler, e é ele que este pacote
// alimenta — o positivo afirma o que o personagem É, o negativo carrega o que ele NÃO É.
package identity

import (
	"regexp"
	"strings"
)

// Detecção por PALAVRA INTEIRA ((?i) + \b): "female" NÃO dispara o marcador de macho e
// "woman"/"human" não disparam "man" — o \b do regexp só existe entre \w e não-\w, então
// substring dentro de outra palavra nunca casa. Pronomes entram de propósito: na prévia
// i2v o lock sai do texto (a identidade vem da imagem) e a ação que sobra costuma dizer só
// "she wags her tail" — sem os pronomes a derivação ficaria cega justamente onde o drift
// mais acontece. O contrato é INGLÊS: todo prompt passa por toEnglishPrompt antes dos
// providers, e os locks já nascem em inglês.
var (
	feminino  = regexp.MustCompile(`(?i)\b(female|woman|women|girl|girls|feminine|she|her|hers|lady|ladies)\b`)
	masculino = regexp.MustCompile(`(?i)\b(male|man|men|boy|boys|masculine|he|him|his|guy|guys|gentleman)\b`)

	// anatomia sexual — completa os marcadores dentro das cláusulas negadas: "no visible
	// genitalia" não tem "male" nem "female", mas é cláusula de sexo do mesmo jeito.
	anatomia = regexp.MustCompile(`(?i)\b(genitalia|genitals|scrotum|sheath|testicles|teats|nipples|udder|anatomy)\b`)

	// clausulaNegada — "no/not/never/without …" até a vírgula/;/./quebra de linha. É o que o
	// lock da MEL carrega ("NO male anatomy, no visible genitalia, no scrotum, no sheath") e
	// o que DESLIGAVA a guarda: o "male" negado fazia o detector ver macho no texto e tratar
	// a cena como mista. Negação não é afirmação — sai da conta antes de detectar.
	clausulaNegada = regexp.MustCompile(`(?i)\b(?:no|not|never|without)\b[^,;.\n]*`)

	// Faxina do texto depois de remover cláusulas: vírgulas em série e vírgula pendurada.
	virgulasEmSerie  = regexp.MustCompile(`\s*,(?:\s*,)+`)
	virgulaPendurada = regexp.MustCompile(`\s*,\s*([;.\n]|$)`)
)

// Os termos suprimidos quando o positivo afirma FÊMEA. "sheath, testicles" não é excesso de
// zelo: foi EXATAMENTE a anatomia que o i2v inventou na MEL (frame t=34s do draft 84) — o
// negativo tem que nomear o que o prior desenha, não a abstração. Lista curta de propósito:
// o CLIP corta em 77 tokens por chunk e o que passa disso dilui.
const negaMacho = "male, man, boy, masculine, male anatomy, male genitalia, sheath, scrotum, testicles"

// E o espelho: positivo afirma MACHO → suprime o desvio pra fêmea.
const negaFemea = "female, woman, girl, feminine, female anatomy, breasts, teats"

// SexNegative — termos a SUPRIMIR no conditioning negativo, derivados do que o prompt
// positivo AFIRMA sobre o sexo do sujeito. Cláusulas negadas saem da conta antes da
// detecção (ver clausulaNegada). Só fêmea afirmada → nega macho; só macho → nega fêmea;
// misto ou nenhum → "" (cena com os dois sexos não pode negar nenhum dos lados, e sem
// sinal não se inventa um). Quem chama apensa o resultado ao negativo-base do workflow.
func SexNegative(positive string) string {
	afirmado := clausulaNegada.ReplaceAllString(positive, "")
	fem, masc := feminino.MatchString(afirmado), masculino.MatchString(afirmado)
	switch {
	case fem && !masc:
		return negaMacho
	case masc && !fem:
		return negaFemea
	default:
		return ""
	}
}

// StripSexNegations — remove do positivo as cláusulas negadas SOBRE SEXO/ANATOMIA ("NO male
// anatomy", "no scrotum", "no sheath") — e SÓ elas: "No redesigns"/"no cuts" ficam, porque
// não entregam token anatômico nenhum. Pra um encoder bag-of-tokens (CLIP/SDXL) a negação
// textual no positivo é um pedido invertido: quem tem que carregar o "nunca" é o
// conditioning negativo (SexNegative). Uso é do provider de IMAGEM; o de vídeo (Wan) fica
// com o positivo intacto — o umt5 é um encoder de linguagem de verdade e entende negação.
func StripSexNegations(prompt string) string {
	out := clausulaNegada.ReplaceAllStringFunc(prompt, func(clausula string) string {
		if masculino.MatchString(clausula) || feminino.MatchString(clausula) || anatomia.MatchString(clausula) {
			return ""
		}
		return clausula
	})
	out = virgulasEmSerie.ReplaceAllString(out, ",")
	out = virgulaPendurada.ReplaceAllString(out, "$1")
	return strings.TrimSpace(out)
}

// O fluxo 3D não menciona sexo NUNCA (SemSexo) — decisão de 2026-07-31, depois de 3 malhas
// macho: em difusão, negar sexo é MENCIONAR sexo, e mencionar atrai. No 3D o texto nem chega
// ao gerador (o conditioning do Hunyuan3D é visual), então a menção só contaminava a ficha.
// Quem garante o personagem certo é o JUIZ de visão comparando imagem com o texto — não a
// palavra no prompt.
var (
	linhaDeSexo  = regexp.MustCompile(`(?im)^.*\b(sex|gender)\b.*$[\r\n]*`)
	palavraSexo  = regexp.MustCompile(`(?i)\b(female|male|fêmea|femea|macho|feminine|masculine)\b`)
	espacoDuplo  = regexp.MustCompile(`[ \t]{2,}`)
	espacoAntes  = regexp.MustCompile(`[ \t]+([,;.])`)
	linhasVazias = regexp.MustCompile(`\n{3,}`)
)

// SemSexo — o texto do personagem SEM nenhuma menção a sexo/anatomia: some a linha inteira
// de "SEX:/GENDER:", somem as cláusulas negadas de anatomia (StripSexNegations) e somem as
// palavras de sexo isoladas ("FEMALE dachshund — a female dog" vira "dachshund — a dog").
// É o texto que alimenta a FICHA DE MALHA e os juízes do fluxo 3D.
func SemSexo(texto string) string {
	out := linhaDeSexo.ReplaceAllString(texto, "")
	out = StripSexNegations(out)
	out = palavraSexo.ReplaceAllString(out, "")
	out = espacoDuplo.ReplaceAllString(out, " ")
	out = espacoAntes.ReplaceAllString(out, "$1")
	out = virgulasEmSerie.ReplaceAllString(out, ",")
	out = linhasVazias.ReplaceAllString(out, "\n\n")
	linhas := strings.Split(out, "\n")
	for i, l := range linhas {
		linhas[i] = strings.TrimSpace(l)
	}
	return strings.TrimSpace(strings.Join(linhas, "\n"))
}
