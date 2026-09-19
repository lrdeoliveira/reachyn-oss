package api

import (
	"net/http"
	"net/http/httptest"
	"testing"

	"github.com/redfoxcode/reachyn/engine/internal/content"
)

// O cabeçalho só pode aparecer quando a reserva REALMENTE atendeu: um falso positivo estorna
// crédito que foi bem cobrado, e um falso negativo é o bug original (cobrar premium pela reserva).
func TestMarcadorDeReserva(t *testing.T) {
	casos := []struct {
		nome    string
		handler http.HandlerFunc
		quer    string
	}{
		{
			nome: "primaria atendeu: sem cabecalho",
			handler: func(w http.ResponseWriter, r *http.Request) {
				w.WriteHeader(http.StatusOK)
			},
			quer: "",
		},
		{
			nome: "reserva atendeu: cabecalho 1",
			handler: func(w http.ResponseWriter, r *http.Request) {
				content.MarcaReservaParaTeste(r.Context())
				w.WriteHeader(http.StatusOK)
			},
			quer: "1",
		},
		{
			// Caminho mais comum em prod: writeJSON escreve corpo sem WriteHeader explícito.
			// Era exatamente aqui que o cabeçalho poderia sumir no sucesso.
			nome: "reserva atendeu e handler so escreve corpo",
			handler: func(w http.ResponseWriter, r *http.Request) {
				content.MarcaReservaParaTeste(r.Context())
				_, _ = w.Write([]byte(`{"ok":true}`))
			},
			quer: "1",
		},
	}

	for _, c := range casos {
		t.Run(c.nome, func(t *testing.T) {
			rec := httptest.NewRecorder()
			comMarcadorDeReserva(c.handler).ServeHTTP(rec, httptest.NewRequest(http.MethodPost, "/v1/text", nil))
			if got := rec.Header().Get(CabecalhoReserva); got != c.quer {
				t.Fatalf("%s = %q, queria %q", CabecalhoReserva, got, c.quer)
			}
		})
	}
}
