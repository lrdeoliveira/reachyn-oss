// gerr — erros de geração CLASSIFICADOS pela ação que cabe a quem chamou, não pela camada que
// falhou. Nasceu do incidente 2026-07-22 (Estúdio de Animação, projeto 19): /v1/filmclip devolvia
// 502 genérico para TUDO — inclusive para "modelo de vídeo que este fluxo não sabe usar" e para
// "provedor sem saldo". O console trata 5xx como transitório, então retentou 3× cada cena contra um
// erro que jamais ia passar; 6 cenas morreram e a UI não soube dizer por quê.
//
// As três classes, pela ação que cada uma pede:
//
//	Transient — blip de rede / 5xx do provedor: retentar ADIANTA. É o default de quem não se declara.
//	Config    — contrato errado (modelo que o fluxo não suporta, parâmetro inválido): retentar NUNCA
//	            adianta; quem chamou precisa mudar a chamada. Vira 4xx na borda.
//	Quota     — saldo/cota do provedor estourada: retentar não adianta agora. É decisão humana
//	            (recarregar) ou trocar de modelo. Vira 402 na borda.
//
// White-label (guideline 6): as mensagens daqui vão pro cliente, então NUNCA citam o provedor —
// "o provedor de mídia", nunca "KIE"/"MiniMax". O nome real fica no log interno.
package gerr

import (
	"errors"
	"fmt"
	"strings"
)

type Kind int

const (
	Transient Kind = iota
	Config
	Quota
)

// Error — erro de geração com a classe embutida. Preserva o erro original em Unwrap para o log
// interno (que PODE citar o provedor) sem vazá-lo na mensagem pública.
type Error struct {
	kind Kind
	msg  string
	err  error
}

func (e *Error) Error() string {
	if e.err != nil {
		return e.msg + ": " + e.err.Error()
	}
	return e.msg
}

func (e *Error) Unwrap() error { return e.err }
func (e *Error) Kind() Kind    { return e.kind }

// Configf — erro PERMANENTE de contrato. Retry não ajuda.
func Configf(format string, a ...any) error {
	return &Error{kind: Config, msg: fmt.Sprintf(format, a...)}
}

// Quotaf — saldo/cota do provedor. Retry não ajuda agora.
func Quotaf(format string, a ...any) error {
	return &Error{kind: Quota, msg: fmt.Sprintf(format, a...)}
}

// WrapQuota — marca um erro de provedor já formado como Quota, preservando o texto original
// (com o nome do provedor) para o log. Usado pelos clients logo após detectar saldo/cota.
func WrapQuota(err error) error {
	if err == nil {
		return nil
	}
	return &Error{kind: Quota, msg: "sem saldo ou cota no provedor de mídia", err: err}
}

// KindOf — classe de um erro em qualquer profundidade da cadeia. Desconhecido = Transient, que é o
// comportamento histórico (retentar) para tudo que ninguém classificou.
func KindOf(err error) Kind {
	var e *Error
	if errors.As(err, &e) {
		return e.kind
	}
	return Transient
}

// quotaTerms — o vocabulário que os provedores usam para dizer "acabou o dinheiro". Casos reais
// coletados em prod (2026-07-18 e 2026-07-22):
//
//	Agregador de vídeo: "Your balance is insufficient. Please top up to continue."
//	KIE:                "Credits insufficient"
//	MiniMax: "Token Plan usage limit reached: Upgrade your Token Plan or purchase Credits..."
var quotaTerms = []string{
	"insufficient",
	"usage limit reached",
	"quota exceeded",
	"out of credit",
	"top up",
	"no credit",
}

// IsQuotaBody — a resposta do provedor é falta de saldo/cota? O TEXTO decide, não o status: os três
// provedores usam status diferentes para a mesma coisa (403, KIE 402, MiniMax 200+code). Um
// 403 sem vocabulário de saldo é outra coisa (chave inválida) e NÃO entra aqui.
func IsQuotaBody(status int, body string) bool {
	b := strings.ToLower(body)
	for _, t := range quotaTerms {
		if strings.Contains(b, t) {
			return true
		}
	}
	return status == 402
}
