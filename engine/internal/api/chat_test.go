package api

import (
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/redfoxcode/reachyn/engine/internal/content"
	"github.com/redfoxcode/reachyn/engine/internal/genkeys"
)

// O bug que este arquivo trava: POST /v1/chat NÃO estava no mux. O console chama a rota em 5
// lugares (Escaleta, Doutor de Roteiro, chat de persona/Arquiteto, Molde de personagem, Decupagem
// de planos) e em produção todos batiam em 404 — enquanto as rotas vizinhas devolviam 403 pra
// token errado, prova de que a rota simplesmente não existia.
//
// Os testes cobrem só o que dá pra provar SEM chamar a IA (rede): registro da rota, o guarda de
// token e a validação de payload. A geração em si é do content.Service, testado no pacote dele.
func testServer(t *testing.T) *Server {
	t.Helper()
	// Serviço sem nenhum provedor: basta pros caminhos que não chegam a gerar texto.
	return New(func(genkeys.Set) *content.Service {
		return content.New(nil, nil, nil, nil, nil, nil, nil, nil, nil, nil, nil)
	}, "tok-de-teste")
}

func post(t *testing.T, s *Server, body, token string) *httptest.ResponseRecorder {
	t.Helper()
	r := httptest.NewRequest(http.MethodPost, "/v1/chat", strings.NewReader(body))
	if token != "" {
		r.Header.Set("X-Admin-Token", token)
	}
	w := httptest.NewRecorder()
	s.Routes().ServeHTTP(w, r)
	return w
}

// A regressão principal: a rota tem que EXISTIR. Sem token ela responde 403 (como as vizinhas),
// nunca 404 — 404 aqui significa que o handler saiu do mux de novo.
func TestChatRotaRegistrada(t *testing.T) {
	if got := post(t, testServer(t), `{}`, "").Code; got != http.StatusForbidden {
		t.Fatalf("POST /v1/chat sem token = %d, queria 403 (404 = rota fora do mux)", got)
	}
}

// system/message ausentes = erro do CHAMADOR → 400. Se caísse no serviço viraria 502
// "a IA está indisponível" e o console retentaria 3× um payload que nunca ia passar.
func TestChatCamposObrigatorios(t *testing.T) {
	casos := map[string]string{
		"vazio":       `{}`,
		"sem message": `{"system":"persona"}`,
		"sem system":  `{"message":"oi"}`,
		"só espaço":   `{"system":"  ","message":"oi"}`,
	}
	s := testServer(t)
	for nome, body := range casos {
		if got := post(t, s, body, "tok-de-teste").Code; got != http.StatusBadRequest {
			t.Errorf("%s: %d, queria 400", nome, got)
		}
	}
}

func TestChatJSONInvalido(t *testing.T) {
	if got := post(t, testServer(t), `{nao é json`, "tok-de-teste").Code; got != http.StatusBadRequest {
		t.Fatalf("corpo inválido = %d, queria 400", got)
	}
}

// O contrato de saída depende de ExtractJSON pra desencapar o que o modelo devolve sujo
// (cerca ```json, frase de abertura, raciocínio vazado da linha de reserva) quando json:true.
func TestExtractJSONDesencapaRespostaSuja(t *testing.T) {
	sujo := "Aqui está o JSON:\n```json\n{\"cenas\":[{\"n\":1}]}\n```\n"
	if got := content.ExtractJSON(sujo); got != `{"cenas":[{"n":1}]}` {
		t.Fatalf("ExtractJSON = %q", got)
	}
}
