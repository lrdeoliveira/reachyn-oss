package content

import (
	"strings"
	"testing"
)

// ✂️ O ritmo de corte é do PRESET, não global: ligar recorte em tudo mudaria em silêncio toda
// peça que já sai boa hoje (filme, história, carrossel). Só o Vox pediu.
func TestShotSecsSoNoVox(t *testing.T) {
	if got := shotSecs(VoxPreset); got != voxShotSecs {
		t.Fatalf("preset vox: shotSecs = %v, queria %v", got, voxShotSecs)
	}
	for _, p := range []string{"", "realista", "cinema", "desconhecido"} {
		if got := shotSecs(p); got != 0 {
			t.Fatalf("preset %q: shotSecs = %v, queria 0 (plano único, histórico intacto)", p, got)
		}
	}
}

// A faixa útil do explicativo animado: abaixo de ~1,5s vira videoclipe, acima de ~3s já lê como
// slideshow — que é exatamente o defeito que o recorte existe pra corrigir.
func TestVoxShotSecsNaFaixaDoFormato(t *testing.T) {
	if voxShotSecs < 1.5 || voxShotSecs > 3.0 {
		t.Fatalf("voxShotSecs = %v, fora da faixa 1,5..3,0s do formato", voxShotSecs)
	}
}

// O arco narrativo é POSICIONAL, não um conselho em prosa. A peça 381 (2026-08-04) saiu com a
// conclusão no beat 4 e terminou em "mas ela erra mãos" — sem fechamento nenhum. A regra existia,
// só que como recomendação genérica; o modelo seguiu a ordem do texto de entrada em vez dela.
func TestVoxBeatSystemFixaAFuncaoDeCadaPosicao(t *testing.T) {
	sys := voxBeatSystem(6, 5, 12, 65, "português do Brasil", "vertical", "9:16", "", "")
	for _, exigido := range []string{
		"A ORDEM DOS BEATS É OBRIGATÓRIA", // posicional, não sugestão
		"beat 1 — ABERTURA",
		"ÚLTIMO beat — CONCLUSÃO",
		"PROIBIDO terminar num problema", // o defeito exato da peça 381
		"ANTES DE RESPONDER, releia",     // autoverificação
	} {
		if !strings.Contains(sys, exigido) {
			t.Errorf("voxBeatSystem perdeu a trava do arco narrativo: falta %q", exigido)
		}
	}
}
