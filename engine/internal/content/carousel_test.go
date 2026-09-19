package content

import (
	"strings"
	"testing"
)

func TestNormalizeCarouselSlides(t *testing.T) {
	casos := map[int]int{0: 9, -3: 9, 5: 5, 6: 5, 7: 7, 8: 7, 9: 9, 10: 9, 11: 12, 12: 12, 40: 12}
	for in, quer := range casos {
		if got := normalizeCarouselSlides(in); got != quer {
			t.Errorf("normalizeCarouselSlides(%d) = %d, queria %d", in, got, quer)
		}
	}
}

func TestCarouselRolesFechamSempreComAssinatura(t *testing.T) {
	for _, n := range carouselSlideCounts {
		roles := carouselRoles(n)
		if len(roles) != n {
			t.Fatalf("carouselRoles(%d) devolveu %d papéis", n, len(roles))
		}
		if roles[0] != "capa" {
			t.Errorf("carrossel de %d: primeiro slide é %q, queria capa", n, roles[0])
		}
		// Regra invariável: a marca fecha a peça, sempre.
		if roles[n-1] != "assinatura" {
			t.Errorf("carrossel de %d: último slide é %q, queria assinatura", n, roles[n-1])
		}
	}
}

func TestTagForSilenciaCapaEAssinatura(t *testing.T) {
	if got := tagFor("capa", "O PROBLEMA"); got != "" {
		t.Errorf("capa levou tag %q — capa é imagem + headline, sem rótulo", got)
	}
	if got := tagFor("assinatura", "A REGRA FINAL"); got != "" {
		t.Errorf("assinatura levou tag %q — o slide final é a marca, sem rótulo", got)
	}
	if got := tagFor("prova", "os números"); got != "OS NÚMEROS" {
		t.Errorf("tagFor(prova) = %q, queria OS NÚMEROS", got)
	}
}

func TestImageBriefPromptVazioSemSubject(t *testing.T) {
	// Sem subject não há imagem a pedir: devolver um prompt só com "Lighting: ..." geraria
	// uma imagem aleatória cara em vez de falhar barato.
	if got := (ImageBrief{Lighting: "hard side light"}).Prompt(); got != "" {
		t.Errorf("brief sem subject virou prompt %q", got)
	}
	b := ImageBrief{Subject: "a red balloon", Lighting: "golden hour", Avoid: "no text"}
	quer := "Subject: a red balloon. Lighting: golden hour. AVOID: no text"
	if got := b.Prompt(); got != quer {
		t.Errorf("Prompt() = %q, queria %q", got, quer)
	}
}

func TestConferirNarrativaPegaOsDefeitosReais(t *testing.T) {
	// Os três defeitos que a geração em produção entregou (2026-08-02/03), agora medidos.
	plan := CarouselPlan{Slides: []CarouselSlide{
		{Index: 1, Role: "capa", Blocks: []string{"MOBILIDADE PAULISTANA", "Ricos andando de bike em Pinheiros. SP reescreve suas regras."}},
		{Index: 2, Role: "hook", Blocks: []string{"Um bloco só, com vinte e nove palavras dentro dele, que vira um paredão de texto e derruba os dois níveis de leitura do slide inteiro sem dó"}},
		{Index: 3, Role: "assinatura", Blocks: []string{"Salve este post e compartilhe com quem ainda acha que bike é só pra pobre"}},
	}}
	faltas := conferirNarrativa(plan)
	if len(faltas) != 2 {
		t.Fatalf("queria 2 defeitos (slide 2 com 1 bloco, CTA acima do teto), veio %d: %v", len(faltas), faltas)
	}
	if !strings.Contains(faltas[0], "slide 2") || !strings.Contains(faltas[1], "CTA") {
		t.Errorf("defeitos mal descritos: %v", faltas)
	}
}

func TestConferirNarrativaAprovaCopyDentroDaRegua(t *testing.T) {
	plan := CarouselPlan{Slides: []CarouselSlide{
		{Index: 1, Role: "capa", Blocks: []string{"A revolução sobre duas rodas", "A bike que tomou o lugar do carro em SP"}},
		{Index: 2, Role: "prova", Blocks: []string{"São Paulo atingiu 600 km de malha cicloviária em dez anos de obra.", "A infraestrutura finalmente acompanha a demanda."}},
		{Index: 3, Role: "assinatura", Blocks: []string{"Salva este post para usar depois."}},
	}}
	if faltas := conferirNarrativa(plan); len(faltas) != 0 {
		t.Errorf("copy dentro da régua foi reprovada: %v", faltas)
	}
}

func TestCTAPerguntaRetoricaEhDefeito(t *testing.T) {
	// Dentro do teto de palavras e ainda assim sem chamada nenhuma — saiu assim em produção.
	plan := CarouselPlan{Slides: []CarouselSlide{
		{Index: 1, Role: "capa", Blocks: []string{"MOBILIDADE", "A bike que tomou o lugar do carro em SP"}},
		{Index: 2, Role: "assinatura", Blocks: []string{"Sua cidade está pronta para isso?"}},
	}}
	faltas := conferirNarrativa(plan)
	if len(faltas) != 1 || !strings.Contains(faltas[0], "pergunta") {
		t.Fatalf("CTA em forma de pergunta passou batido: %v", faltas)
	}
}
