package content

import (
	"context"
	"sync/atomic"
)

// QUEM ATENDEU A GERAÇÃO DE TEXTO — e por que isso precisa sair do engine.
//
// A linha de texto é principal→reserva (ver genTextOrdered): quando a primária falha, vem vazia
// ou vaza CJK, a reserva atende e a requisição termina em **HTTP 200**. Do lado do console isso
// era indistinguível de um sucesso da primária — e o console debita ANTES de gerar, pelo preço do
// modelo PEDIDO. Resultado real em produção (2026-08-03, linha Claude do agregador fora): o
// cliente pagava 20 créditos por "Topo" e recebia o texto da reserva, cujo par de catálogo custa 1.
// Cobrar premium por uma entrega de reserva é o defeito; o log em stdout não conserta, porque
// quem cobra é outro processo.
//
// A correção é o engine DIZER quem atendeu. O caminho é um contador no context em vez de um campo
// em cada struct de resposta porque são ~15 endpoints de texto com formatos diferentes
// (/v1/text, /v1/chat, /v1/summarize, /v1/story…): um marcador no context atravessa todos sem
// mexer em nenhum contrato de JSON, e a camada HTTP traduz num cabeçalho de resposta.
//
// atomic.Bool, e não um bool simples, porque uma requisição pode disparar várias gerações de
// texto em paralelo (ex.: post por rede) — todas escrevendo no mesmo marcador.

type chaveReserva struct{}

// ComMarcadorDeReserva devolve um context que carrega o marcador, mais o próprio marcador pra
// camada HTTP ler DEPOIS que o handler terminou. Chamar uma vez por requisição.
func ComMarcadorDeReserva(ctx context.Context) (context.Context, *atomic.Bool) {
	var m atomic.Bool

	return context.WithValue(ctx, chaveReserva{}, &m), &m
}

// marcaReserva anota que a reserva atendeu. No-op quando o context não tem marcador (chamadas
// internas, testes, jobs de fila) — de propósito: nenhum caminho de geração pode quebrar por
// causa da contabilidade.
func marcaReserva(ctx context.Context) {
	if m, ok := ctx.Value(chaveReserva{}).(*atomic.Bool); ok && m != nil {
		m.Store(true)
	}
}

// MarcaReservaParaTeste expõe marcaReserva para o teste do middleware no pacote api, que precisa
// simular a queda sem subir um provedor de LLM. Não usar em código de produção — quem marca é o
// genTextOrdered, no ponto em que a queda de fato acontece.
func MarcaReservaParaTeste(ctx context.Context) { marcaReserva(ctx) }
