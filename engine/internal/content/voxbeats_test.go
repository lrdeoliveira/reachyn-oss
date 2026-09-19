package content

import (
	"errors"

	"github.com/redfoxcode/reachyn/engine/internal/gerr"
	"strings"
	"testing"
)

// ✅ ROTEIRO APROVADO É INTOCÁVEL. O cliente lê o roteiro, aprova, e a peça TEM de sair sobre
// exatamente aquilo. Re-segmentar na hora de gerar entregaria uma peça diferente da aprovada — e
// ele só descobriria assistindo, com a peça já paga.
func TestBeatsAprovadosPulamASegmentacao(t *testing.T) {
	aprovados := []Beat{
		{Caption: "um", Script: "primeira frase", ImagePrompt: "coisa um"},
		{Caption: "dois", Script: "segunda frase", ImagePrompt: "coisa dois"},
	}
	opt := VideoOptions{Prompt: "tema qualquer", Scenes: 2, Beats: aprovados}

	// A normalização não pode mexer nos beats aprovados (é onde clamps e defaults agem).
	got := opt.normalize().Beats
	if len(got) != len(aprovados) {
		t.Fatalf("normalize mexeu na quantidade: %d, queria %d", len(got), len(aprovados))
	}
	for i, b := range got {
		if b.Script != aprovados[i].Script || b.ImagePrompt != aprovados[i].ImagePrompt {
			t.Fatalf("beat %d foi alterado: %+v", i, b)
		}
	}
}

// 🎞️ O prompt de movimento do Vox tinha se tornado o OPOSTO do formato: proibia movimento, e a
// peça saía parada ("muito simples", queixa do cliente). O método de origem é colagem ANIMADA —
// papel que desliza, entra, assenta. O que continua proibido é a CÂMERA se mexer e o elemento
// mudar de identidade (modelo i2v trata verbo de ação como licença pra redesenhar a cena).
func TestVoxMotionPromptPedeMovimentoDoPapelNaoDaCamera(t *testing.T) {
	p := voxMotionPrompt("")
	for _, exigido := range []string{"ANIMATE", "slide", "IDENTITY IS PRESERVED", "only the paper moves"} {
		if !strings.Contains(p, exigido) {
			t.Errorf("voxMotionPrompt perdeu %q — o formato volta a sair parado", exigido)
		}
	}
	// A câmera travada é o que separa colagem de papel de "vídeo de IA".
	for _, camera := range []string{"no pan", "no tilt", "no zoom", "no shake"} {
		if !strings.Contains(p, camera) {
			t.Errorf("voxMotionPrompt soltou a CÂMERA (%q ausente) — câmera à deriva denuncia IA", camera)
		}
	}
	// A regressão que motivou tudo: a versão antiga mandava NADA se mexer.
	if strings.Contains(p, "The ONLY motion is a very subtle") {
		t.Error("voxMotionPrompt voltou a proibir movimento (versão antiga)")
	}
}

// 💳 "Acabou o crédito" NÃO é "serviço instável". A mensagem genérica manda o cliente repetir uma
// geração que não pode dar certo e esconde a única coisa acionável: recarregar a conta. Caso real
// 2026-08-04 — a conta zerou no meio de uma peça de 6 cenas e ela saiu pela metade, sem motivo.
func TestErroDeCreditoNaoSeConfundeComInstabilidade(t *testing.T) {
	credito := []string{
		"cli-bridge vídeo http 402: sem créditos no provedor de IA",
		`Error: {"error_type":"not_enough_credits","plan_type":"plus"}`,
		"insufficient credits",
	}
	for _, m := range credito {
		if !erroDeCredito(errors.New(m)) {
			t.Errorf("não reconheceu falta de crédito em %q", m)
		}
	}
	outros := []string{
		"cli-bridge vídeo http 502: geração falhou",
		"bridge ocupado",
		"context deadline exceeded",
		"Model does not accept --start-image",
	}
	for _, m := range outros {
		if erroDeCredito(errors.New(m)) {
			t.Errorf("confundiu %q com falta de crédito — o cliente leria a mensagem errada", m)
		}
	}
}

// 💳 O TIPO DO ERRO É QUE CARREGA A INFORMAÇÃO. gerr.Quota vira HTTP 402 no writeErr, com a
// mensagem certa pro cliente; qualquer outro erro cai no default (502 "a IA está indisponível,
// tente novamente"). E 5xx é transitório por definição, então o job ainda retentava 3× contra uma
// conta vazia. Usar fmt.Errorf aqui jogava fora as duas coisas — foi o que aconteceu em
// 2026-08-04, e só apareceu medindo as tentativas em produção.
func TestFaltaDeCreditoEhErroDeQuota(t *testing.T) {
	err := gerr.Quotaf("sem créditos no provedor de IA (saldo %.1f)", 4.1)
	if gerr.KindOf(err) != gerr.Quota {
		t.Fatalf("KindOf = %v, queria Quota — sem isso o cliente lê 'tente novamente' e o job retenta", gerr.KindOf(err))
	}
	// E continua sendo reconhecível como erro de crédito pelo caminho de texto (defesa em camada).
	if !erroDeCredito(err) {
		t.Error("erroDeCredito não reconheceu o próprio erro de quota")
	}
}
