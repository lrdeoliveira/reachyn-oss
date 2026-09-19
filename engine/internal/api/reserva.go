package api

import (
	"net/http"

	"github.com/redfoxcode/reachyn/engine/internal/content"
)

// CabecalhoReserva — nome do cabeçalho que diz ao console QUEM atendeu a geração de texto.
// Presente e "1" ⇒ a linha primária não serviu e a reserva entregou. Ausente ⇒ primária OK.
//
// Existe porque o console debita o crédito ANTES de gerar, pelo preço do modelo pedido, e a queda
// pra reserva volta como HTTP 200 — indistinguível de sucesso. Sem este cabeçalho o cliente paga
// premium por uma entrega de reserva (caso real 2026-08-03). Ver content/reserva.go.
const CabecalhoReserva = "X-Reachyn-Reserva"

// respostaMarcada — ResponseWriter que carimba o cabeçalho no momento do WriteHeader, que é
// quando o handler já terminou de gerar e o marcador já tem a resposta final. Carimbar antes
// (no middleware) seria cedo demais: a geração ainda nem começou.
type respostaMarcada struct {
	http.ResponseWriter
	marcado  func() bool
	escreveu bool
}

func (w *respostaMarcada) WriteHeader(code int) {
	if !w.escreveu {
		w.escreveu = true
		if w.marcado() {
			w.Header().Set(CabecalhoReserva, "1")
		}
	}
	w.ResponseWriter.WriteHeader(code)
}

// Write cobre o handler que escreve corpo sem chamar WriteHeader (Go implica 200): sem isto o
// cabeçalho não sairia justamente no caminho de sucesso, que é o que precisa ser cobrado certo.
func (w *respostaMarcada) Write(b []byte) (int, error) {
	if !w.escreveu {
		w.WriteHeader(http.StatusOK)
	}

	return w.ResponseWriter.Write(b)
}

// comMarcadorDeReserva instala o marcador no context da requisição e carimba a resposta.
// Aplicado no router inteiro: é barato (um atomic.Bool por requisição) e assim nenhum endpoint
// de texto novo nasce sem a sinalização — o defeito original foi exatamente um caminho de
// cobrança que ninguém lembrou de ligar.
func comMarcadorDeReserva(h http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		ctx, marcador := content.ComMarcadorDeReserva(r.Context())
		h.ServeHTTP(&respostaMarcada{ResponseWriter: w, marcado: marcador.Load}, r.WithContext(ctx))
	})
}
