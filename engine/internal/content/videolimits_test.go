package content

import (
	"strings"
	"testing"
)

// Estes testes trancam as duas travas de geração de vídeo (2026-08-03):
//
//   - videoHardLimits — o que o motor comprovadamente NÃO sabe fazer (semáforo, aéreo sobre
//     trânsito, ultrapassagem, match cut, número legível em painel) + o viés de quadro fechado.
//   - sceneGeographyRule — a posição de cada corpo travada entre planos.
//
// O que se testa é PRESENÇA NO PROMPT FINAL, não a existência da constante. A lição de 2026-08-02
// nesta base foi função escrita e nunca chamada, falhando em silêncio: uma constante linda que não
// chega ao `sys` não muda um frame sequer. Por isso os builders de prompt (filmPlanSystem,
// animScriptSystem) foram extraídos em funções puras — para que dê pra afirmar isso aqui.
//
// A segunda metade é o teste de NÃO-VAZAMENTO, e é o que mais importa: regra de vídeo em inglês
// caindo num prompt de imagem PT-BR polui a geração e a geografia de cena não tem sentido em clipe
// de plano único.

// trecho curto e estável de cada bloco — evita comparar parágrafos inteiros no erro do teste.
const (
	marcaLimites   = "ENGINE HARD LIMITS"
	marcaGeografia = "FIXED SCENE GEOGRAPHY"
	marcaFisica    = "ACTOR PHYSICS"
)

func TestVideoHardLimitsCobreAsCincoFalhasDuras(t *testing.T) {
	// As cinco falhas são as de categoria "não tem conserto" do catálogo — se alguém enxugar a
	// constante e derrubar uma, o cliente volta a pagar pela geração perdida.
	for _, want := range []string{
		"traffic light",
		"aerial",
		"overtaking",
		"match cut",
		"legible number",
		"FRAMING BIAS", // a regra de ouro: quanto mais fechado o quadro, menos porcaria
	} {
		if !strings.Contains(videoHardLimits, want) {
			t.Errorf("videoHardLimits perdeu a trava %q", want)
		}
	}
}

func TestSceneGeographyRuleCobreAsSeisTravas(t *testing.T) {
	for _, want := range []string{
		"locked position", // posição travada com nome
		"no lane change",  // trajetória negada
		"remains seated",  // quem não sai de onde está
		"LIGHT DIRECTION", // luz como constante da cena
		"BLOCKING SHOT",   // plano de blocagem primeiro
		"LANDING",         // objeto que sai de quadro tem destino escrito
	} {
		if !strings.Contains(sceneGeographyRule, want) {
			t.Errorf("sceneGeographyRule perdeu a trava %q", want)
		}
	}
}

func TestActorPhysicsRuleTrancaOCorpoEACamera(t *testing.T) {
	// O dicionário de substituição é o núcleo: o modelo RENDERIZA DEMAIS o verbo emocional, e a
	// cura é escrever o corpo em vez do sentimento. Se alguém enxugar isso, volta o choro de novela.
	for _, want := range []string{
		"WEIGHT MARK",              // onde a massa assentou
		"MICRO-ACTION",             // o movimento involuntário
		"HELD BEAT",                // a pausa com intenção
		"WRITE THE BODY",           // a regra que resume as trocas
		"her eyes glass over",      // a substituição de "tears stream down her face"
		"MORE THAN ONE element",    // vento em um elemento só lê como ventilador de set
		"epic sweeping drone shot", // o que NUNCA pedir de câmera
	} {
		if !strings.Contains(actorPhysicsRule, want) {
			t.Errorf("actorPhysicsRule perdeu %q", want)
		}
	}
	// Cada verbo emocional citado tem de vir SEMPRE precedido de "instead of" — ele aparece como o
	// que NÃO escrever, nunca solto. Solto, a regra ensinaria justamente o defeito que veio curar.
	for _, verbo := range []string{"tears stream down her face", "he rages", "she smiles widely"} {
		if !strings.Contains(actorPhysicsRule, "instead of '"+verbo+"'") {
			t.Errorf("o verbo emocional %q precisa vir como \"instead of\", nunca solto", verbo)
		}
	}
}

func TestAspectHintNaoEscreveARazaoNumerica(t *testing.T) {
	// A proporção já vai como PARÂMETRO na chamada do provider. Escrita no corpo do prompt é
	// redundância que, no pior caso, o modelo desenha como texto DENTRO da imagem.
	for _, a := range []string{"16:9", "1:1", "4:5", "9:16"} {
		h := aspectHint(a)
		if h == "" {
			t.Fatalf("aspectHint(%q) veio vazio — a orientação de composição é o motivo da função existir", a)
		}
		if strings.Contains(h, a) {
			t.Errorf("aspectHint(%q) escreve a razão no texto: %q", a, h)
		}
	}
	// Formato desconhecido continua sem dica (não inventa enquadramento).
	if aspectHint("3:2") != "" {
		t.Error("formato fora do catálogo deve devolver vazio")
	}
}

func TestIdentityRuleDeclaraOsDoisLadosDoEscopo(t *testing.T) {
	// Escopo sem a metade NEGATIVA é meia instrução: a referência visualmente mais forte domina as
	// outras. Medido em produção (30 ocorrências nos 17 prompts do comercial, 2026-08-03).
	for _, want := range []string{
		"GOVERNS face, design and wardrobe ONLY",
		"do not take framing, palette or lighting from it",
		"GOVERNS layout, camera position and light ONLY",
		"do not take character design, wardrobe or colours from it",
	} {
		if !strings.Contains(i2iIdentityRule, want) {
			t.Errorf("i2iIdentityRule sem a metade negativa do escopo: %q", want)
		}
	}
}

// ── PRESENÇA — as regras chegam ao prompt final de cada gerador de vídeo ──────────────────────

func TestVideoPersonaCarregaOsLimitesDoMotor(t *testing.T) {
	// videoPersona alimenta o clipe único e o preset Vox (ambos passam por generateSingleClip).
	if !strings.Contains(videoPersona, marcaLimites) {
		t.Error("videoPersona sem videoHardLimits — o clipe curto voltaria a pedir o que o motor quebra")
	}
	if !strings.Contains(videoPersona, marcaFisica) {
		t.Error("videoPersona sem actorPhysicsRule — sem peso e contenção o clipe lê como CGI")
	}
}

func TestFilmPlanSystemCarregaAsDuasTravas(t *testing.T) {
	sys := filmPlanSystem("You are a director.", "Write in English.", "", "5", 4)

	for _, want := range []string{marcaLimites, marcaGeografia, marcaFisica, "Apply these craft rules"} {
		if !strings.Contains(sys, want) {
			t.Errorf("filmPlanSystem não entrega %q ao modelo", want)
		}
	}
	// O contrato antigo continua de pé — as travas somam, não substituem.
	if !strings.Contains(sys, "THE 180-DEGREE RULE") {
		t.Error("filmPlanSystem perdeu a regra dos 180°: aquela garante o EIXO, a geografia garante a POSIÇÃO")
	}
}

func TestAnimScriptSystemCarregaAsDuasTravas(t *testing.T) {
	// A animação é multi-cena e o estilo é livre (o usuário pode pedir fotorrealista), então leva
	// os dois blocos.
	sys := animScriptSystem(6, "português do Brasil", "3D animated cartoon", "", false)

	for _, want := range []string{marcaLimites, marcaGeografia, marcaFisica} {
		if !strings.Contains(sys, want) {
			t.Errorf("animScriptSystem não entrega %q ao modelo", want)
		}
	}
	// E não some quando o modo narração/persona entra — os appends vêm depois no mesmo sys.
	comNarracao := animScriptSystem(6, "português do Brasil", "cinematic photoreal", "Diretor de cinema", true)
	if !strings.Contains(comNarracao, marcaLimites) || !strings.Contains(comNarracao, marcaGeografia) {
		t.Error("as travas somem quando narração/persona são ligadas")
	}
}

// ── NÃO-VAZAMENTO — onde as regras NÃO podem entrar ───────────────────────────────────────────

func TestGeografiaNaoEntraEmClipeDePlanoUnico(t *testing.T) {
	// Clipe único não tem "entre planos": geografia fixa ali é só prompt gasto diluindo o essencial.
	if strings.Contains(videoPersona, marcaGeografia) {
		t.Error("sceneGeographyRule vazou pro clipe de plano único (videoPersona)")
	}
}

func TestLimitesDeVideoNaoVazamParaPromptDeImagem(t *testing.T) {
	// O caminho de imagem é outro (budoImageRules, em PT-BR). Bloco de vídeo em inglês caindo lá
	// muda a descrição da cena sem que ninguém peça.
	imagem := map[string]string{
		"budoImageRules":      budoImageRules,
		"realismAnchor":       realismAnchor,
		"i2iIdentityRule":     i2iIdentityRule,
		"carouselImageSystem": carouselImageSystem(5, "vibrante", CarouselBrand{}),
		"voxBeatSystem":       voxBeatSystem(6, 5, 12, 65, "português do Brasil", "vertical", "9:16", "", ""),
	}
	for nome, s := range imagem {
		if strings.Contains(s, marcaLimites) {
			t.Errorf("%s: videoHardLimits vazou para um prompt de IMAGEM", nome)
		}
		if strings.Contains(s, marcaGeografia) {
			t.Errorf("%s: sceneGeographyRule vazou para um prompt de IMAGEM", nome)
		}
		if strings.Contains(s, marcaFisica) {
			t.Errorf("%s: actorPhysicsRule vazou para um prompt de IMAGEM (é regra de MOVIMENTO)", nome)
		}
	}
}

// TestBeat8sCompativelComVoxFactory — o beat de 8s existe e cai EXATAMENTE no mesmo teto de
// caracteres do Vox Factory (a ferramenta do Google Labs Flow que a casa usa): 90 caracteres.
//
// Antes de 2026-08-30 `validDuration` só conhecia "6" e "10" e devolvia "6" em silêncio para
// qualquer outro valor. Um roteiro escrito no Vox Factory (8s / 90 caracteres) virava clipe de 6s,
// cuja janela falada é 5,4s = 65 caracteres — a narração saía CORTADA no meio da frase. Silêncio
// perfeito: nenhum erro, nenhum log, só a fala truncada no vídeo entregue.
//
// Os dois lados calculam do MESMO jeito (12,2 c/s menos 0,6s de respiro), então o teste trava a
// interoperabilidade, não um número escrito à mão.
func TestBeat8sCompativelComVoxFactory(t *testing.T) {
	if got := validDuration("8"); got != "8" {
		t.Fatalf("validDuration(8) = %q, queria 8 — sem isso o beat do Vox Factory cai em 6s e a fala trunca", got)
	}

	secs, words, maxChars := beatScriptSize("8")
	// (8 - 0,6) × 12,2 = 90,28 → 90: o mesmo MAX_CHARS_PER_BEAT do Vox Factory.
	if maxChars != 90 {
		t.Fatalf("maxChars(8s) = %d, queria 90 (o teto do Vox Factory)", maxChars)
	}
	if secs != 7 {
		t.Fatalf("secs(8s) = %d, queria 7 (7,4s de fala arredondado)", secs)
	}
	if words != 17 {
		t.Fatalf("words(8s) = %d, queria 17", words)
	}

	// A escala continua monótona: 6 < 8 < 10 em teto de fala.
	_, _, c6 := beatScriptSize("6")
	_, _, c10 := beatScriptSize("10")
	if !(c6 < maxChars && maxChars < c10) {
		t.Fatalf("teto fora de ordem: 6s=%d, 8s=%d, 10s=%d", c6, maxChars, c10)
	}

	// Duração inválida continua caindo no default do formato.
	if got := validDuration("7"); got != "6" {
		t.Fatalf("validDuration(7) = %q, queria o default 6", got)
	}
	// E o teto de cenas respeita os 5 min com o clipe de 8s.
	if got := maxScenesFor("8"); got != maxVideoSeconds/8 {
		t.Fatalf("maxScenesFor(8) = %d, queria %d", got, maxVideoSeconds/8)
	}
}
