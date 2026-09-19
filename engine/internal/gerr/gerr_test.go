package gerr

import (
	"errors"
	"fmt"
	"testing"
)

// As respostas REAIS que os provedores devolveram nos incidentes — a classificação tem que
// reconhecer as três, senão o console volta a retentar 3× contra conta sem saldo.
func TestIsQuotaBody_CasosReaisDeProd(t *testing.T) {
	casos := []struct {
		nome   string
		status int
		body   string
		quer   bool
	}{
		{"403 saldo (2026-07-22)", 403, `{"message":"Your balance is insufficient. Please top up to continue.","code":"FORBIDDEN"}`, true},
		{"kie 402 créditos (2026-07-18)", 402, `{"msg":"Credits insufficient"}`, true},
		{"minimax 2056 cota (2026-07-22)", 2056, "Token Plan usage limit reached: Upgrade your Token Plan or purchase Credits for more usage.", true},
		{"402 sem texto conhecido", 402, "payment required", true},

		// O que NÃO pode virar Quota: são outras falhas, e retentar/recarregar não é a resposta.
		{"403 de chave inválida", 403, `{"message":"Invalid API key","code":"FORBIDDEN"}`, false},
		{"500 do provedor", 500, "internal server error", false},
		{"422 de parâmetro", 422, `{"msg":"invalid duration"}`, false},
		{"corpo vazio", 500, "", false},
	}
	for _, c := range casos {
		t.Run(c.nome, func(t *testing.T) {
			if got := IsQuotaBody(c.status, c.body); got != c.quer {
				t.Fatalf("IsQuotaBody(%d, %q) = %v, quer %v", c.status, c.body, got, c.quer)
			}
		})
	}
}

// Desconhecido = Transient: quem não se declara mantém o comportamento histórico (retentar).
func TestKindOf_DefaultTransient(t *testing.T) {
	if got := KindOf(errors.New("blip de rede")); got != Transient {
		t.Fatalf("erro comum = %v, quer Transient", got)
	}
	if got := KindOf(nil); got != Transient {
		t.Fatalf("nil = %v, quer Transient", got)
	}
}

func TestKindOf_ClassificaEAtravessaWrap(t *testing.T) {
	if got := KindOf(Configf("modelo não suportado")); got != Config {
		t.Fatalf("Configf = %v, quer Config", got)
	}
	if got := KindOf(Quotaf("sem saldo")); got != Quota {
		t.Fatalf("Quotaf = %v, quer Quota", got)
	}
	// A classe precisa sobreviver ao %w de quem repassa o erro — é assim que ela chega na borda.
	embrulhado := fmt.Errorf("clipe da cena 3: %w", Quotaf("sem saldo"))
	if got := KindOf(embrulhado); got != Quota {
		t.Fatalf("Quota embrulhada = %v, quer Quota", got)
	}
}

// WrapQuota classifica sem perder o texto original (que cita o provedor e vai só pro log).
func TestWrapQuota_PreservaOriginalParaOLog(t *testing.T) {
	orig := errors.New(`submit http 403: {"message":"Your balance is insufficient."}`)
	w := WrapQuota(orig)

	if KindOf(w) != Quota {
		t.Fatalf("WrapQuota não classificou como Quota")
	}
	if !errors.Is(w, orig) {
		t.Fatalf("WrapQuota perdeu o erro original (Unwrap quebrado)")
	}
	if WrapQuota(nil) != nil {
		t.Fatalf("WrapQuota(nil) devia continuar nil")
	}
}
