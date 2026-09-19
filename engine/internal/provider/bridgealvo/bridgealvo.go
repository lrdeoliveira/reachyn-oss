// Package bridgealvo — escolhe QUAL cli-bridge atende um adapter.
//
// Existem dois sidecars com o MESMO conjunto de CLIs (mmx, higgsfield, codex, claude,
// cursor-agent, agy). A diferença é a COTA: cada máquina tem a sua conta de assinatura.
//
// ⚠️ CORREÇÃO (2026-08-03, no deploy): a primeira versão deste comentário dizia que o Mac
// existia porque `claude` e `cursor-agent` não estavam na VPS. ERRADO — elas estão, em
// /root/.local/bin, que o PATH do login SSH não inclui (o do systemd inclui). O que a
// verificação de fato mostrou foi outra coisa, e mais útil: no mesmo deploy o `codex` da
// VPS estava com a COTA ESTOURADA ("try again at Aug 11th"), enquanto o do Mac respondia.
//
// É esse o papel do 2º sidecar: cota separada quando a conta de uma máquina seca. Não é
// acesso a CLI exclusiva.
//
// CONVENÇÃO: o prefixo "mac:" no nome do adapter manda a chamada pro sidecar do Mac.
// "mac:claude" → bridge do Mac, adapter "claude". Sem prefixo → VPS.
//
// Por que um PREFIXO no adapter e não um campo novo no catálogo: o nome do adapter já viaja
// inteiro por todas as camadas (catálogo → console → payload → engine → bridge). Um campo
// novo teria de ser propagado em cada uma delas, e qualquer camada que esquecesse mandaria a
// chamada pro sidecar errado em silêncio. O prefixo viaja de graça com o valor.
//
// ⚠️ O Mac é uma máquina PESSOAL: desligado, dormindo ou sem rede, ele some. Por isso nada
// que seja caminho padrão do produto deve depender dele — o alvo Mac é válvula de escape de
// cota, acionada por escolha explícita. Sem URL configurada, Resolve devolve alvo vazio e
// quem chama falha com erro claro, em vez de escorregar pro sidecar errado.
package bridgealvo

import "strings"

// PrefixoMac — marca de adapter que roda no sidecar do Mac.
const PrefixoMac = "mac:"

// Alvo — endereço + token de um sidecar.
type Alvo struct {
	URL   string
	Token string
}

// Configurado — o alvo tem endereço?
func (a Alvo) Configurado() bool { return a.URL != "" }

// Resolve — decide o sidecar e devolve o nome LIMPO do adapter (sem o prefixo).
//
// `vps` e `mac` são os dois alvos configurados no boot. Adapter sem prefixo vai pra VPS.
func Resolve(adapter string, vps, mac Alvo) (Alvo, string) {
	a := strings.TrimSpace(adapter)
	if rest, achou := strings.CutPrefix(a, PrefixoMac); achou {
		return mac, strings.TrimSpace(rest)
	}

	return vps, a
}
