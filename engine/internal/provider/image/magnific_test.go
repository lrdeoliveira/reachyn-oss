package image

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync/atomic"
	"testing"
	"time"
)

// magnificFake — API de mentira. `polls` conta os GET de status; a 1ª resposta do POST é
// IN_PROGRESS e só o N-ésimo poll devolve COMPLETED, que é o caminho real.
func magnificFake(t *testing.T, corpo *map[string]any, polls *atomic.Int32, prontoNo int32, chave *string) *httptest.Server {
	t.Helper()

	return httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if chave != nil {
			*chave = r.Header.Get("x-magnific-api-key")
		}
		w.Header().Set("Content-Type", "application/json")
		if r.Method == http.MethodPost {
			if corpo != nil {
				_ = json.NewDecoder(r.Body).Decode(corpo)
			}
			_, _ = w.Write([]byte(`{"data":{"task_id":"t-1","status":"IN_PROGRESS","generated":[]}}`))

			return
		}
		n := polls.Add(1)
		if n >= prontoNo {
			_, _ = w.Write([]byte(`{"data":{"task_id":"t-1","status":"COMPLETED","generated":["https://cdn/img.jpg"]}}`))

			return
		}
		_, _ = w.Write([]byte(`{"data":{"task_id":"t-1","status":"IN_PROGRESS","generated":[]}}`))
	}))
}

// cliente apontado pro fake: o base é constante, então o teste troca o transporte.
func clienteMagnific(srv *httptest.Server) *Client {
	c := New("").WithMagnific("chave-x")
	c.http = srv.Client()
	c.http.Transport = redireciona{srv.URL}

	return c
}

// redireciona — manda toda chamada pro servidor de teste, preservando path e método.
type redireciona struct{ base string }

func (rd redireciona) RoundTrip(r *http.Request) (*http.Response, error) {
	u := *r.URL
	alvo := strings.TrimPrefix(rd.base, "http://")
	u.Scheme, u.Host = "http", alvo
	r2 := r.Clone(r.Context())
	r2.URL = &u
	r2.Host = alvo

	return http.DefaultTransport.RoundTrip(r2)
}

func TestMagnificImageMontaCorpoPeloSpecEFazPoll(t *testing.T) {
	var corpo map[string]any
	var polls atomic.Int32
	var chave string
	srv := magnificFake(t, &corpo, &polls, 3, &chave)
	defer srv.Close()

	url, err := clienteMagnific(srv).MagnificImage(t.Context(), "mystic",
		"uma raposa", "9:16", "", []string{"https://ref/a.jpg", "https://ref/b.jpg"},
		MagnificSpec{
			AspectField: "aspect_ratio",
			RefsField:   "reference_images",
			Extra:       map[string]any{"num_images": 1},
		})
	if err != nil {
		t.Fatalf("geração falhou: %v", err)
	}
	if url != "https://cdn/img.jpg" {
		t.Errorf("url = %q", url)
	}
	if chave != "chave-x" {
		t.Errorf("header de autenticação = %q — a API exige x-magnific-api-key", chave)
	}
	if corpo["prompt"] == nil || !strings.Contains(corpo["prompt"].(string), "uma raposa") {
		t.Errorf("prompt não chegou: %v", corpo["prompt"])
	}
	if corpo["aspect_ratio"] != "9:16" {
		t.Errorf("aspect_ratio = %v", corpo["aspect_ratio"])
	}
	if corpo["num_images"] == nil {
		t.Error("os params fixos do Extra não foram mesclados")
	}
	refs, _ := corpo["reference_images"].([]any)
	if len(refs) != 2 {
		t.Errorf("refs i2i = %v, queria as 2", corpo["reference_images"])
	}
}

// Campo do spec AUSENTE = campo AUSENTE no corpo. Mandar `aspect_ratio: ""` num endpoint que
// não conhece o param é um 400 — e o catálogo existe justamente pra não hardcodar isso.
func TestMagnificImageNaoInventaCampoForaDoSpec(t *testing.T) {
	var corpo map[string]any
	var polls atomic.Int32
	srv := magnificFake(t, &corpo, &polls, 1, nil)
	defer srv.Close()

	_, err := clienteMagnific(srv).MagnificImage(t.Context(), "z-image-turbo",
		"gato", "16:9", "", []string{"https://ref/a.jpg"}, MagnificSpec{})
	if err != nil {
		t.Fatalf("geração falhou: %v", err)
	}
	for _, campo := range []string{"aspect_ratio", "reference_images", "image", "scale_factor"} {
		if _, tem := corpo[campo]; tem {
			t.Errorf("campo %q foi inventado sem estar no spec", campo)
		}
	}
}

func TestMagnificImageRefsSingleMandaStringNaoLista(t *testing.T) {
	var corpo map[string]any
	var polls atomic.Int32
	srv := magnificFake(t, &corpo, &polls, 1, nil)
	defer srv.Close()

	_, err := clienteMagnific(srv).MagnificImage(t.Context(), "m", "x", "1:1", "",
		[]string{"https://ref/a.jpg", "https://ref/b.jpg"},
		MagnificSpec{RefsField: "image", RefsSingle: true})
	if err != nil {
		t.Fatalf("geração falhou: %v", err)
	}
	if s, ok := corpo["image"].(string); !ok || s != "https://ref/a.jpg" {
		t.Errorf("refs_single tem de mandar UMA string, veio %T %v", corpo["image"], corpo["image"])
	}
}

func TestMagnificEditUsaCampoDaImagemDeOrigem(t *testing.T) {
	var corpo map[string]any
	var polls atomic.Int32
	srv := magnificFake(t, &corpo, &polls, 1, nil)
	defer srv.Close()

	_, err := clienteMagnific(srv).MagnificEdit(t.Context(), "image-upscaler-creative",
		"https://s3/foto.jpg", MagnificSpec{ImageField: "image", Extra: map[string]any{"scale_factor": 2}})
	if err != nil {
		t.Fatalf("edição falhou: %v", err)
	}
	if corpo["image"] != "https://s3/foto.jpg" {
		t.Errorf("imagem de origem = %v", corpo["image"])
	}
	if corpo["scale_factor"] == nil {
		t.Error("params fixos da edição não foram mesclados")
	}
	if _, tem := corpo["prompt"]; tem {
		t.Error("edição não pode mandar prompt")
	}
}

// FAILED tem de virar erro. Sem isto o poll rodaria até o timeout numa tarefa que já morreu:
// o usuário espera 4 minutos por um erro que a API deu no primeiro segundo.
func TestMagnificFalhaViraErroNaHora(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		if r.Method == http.MethodPost {
			_, _ = w.Write([]byte(`{"data":{"task_id":"t-1","status":"CREATED"}}`))

			return
		}
		_, _ = w.Write([]byte(`{"data":{"task_id":"t-1","status":"FAILED"}}`))
	}))
	defer srv.Close()

	inicio := time.Now()
	if _, err := clienteMagnific(srv).MagnificImage(t.Context(), "m", "x", "1:1", "", nil, MagnificSpec{}); err == nil {
		t.Fatal("FAILED devolveu sucesso")
	}
	if time.Since(inicio) > 10*time.Second {
		t.Error("FAILED ficou em poll em vez de falhar na hora")
	}
}

// COMPLETED com `generated` vazio (ou só com string vazia) NÃO é sucesso: persistir "" viraria
// um card de mídia quebrado na galeria, cobrado como geração boa.
func TestMagnificCompletedSemURLViraErro(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		if r.Method == http.MethodPost {
			_, _ = w.Write([]byte(`{"data":{"task_id":"t-1","status":"CREATED"}}`))

			return
		}
		_, _ = w.Write([]byte(`{"data":{"task_id":"t-1","status":"COMPLETED","generated":["  "]}}`))
	}))
	defer srv.Close()

	if _, err := clienteMagnific(srv).MagnificImage(t.Context(), "m", "x", "1:1", "", nil, MagnificSpec{}); err == nil {
		t.Fatal("COMPLETED sem URL devolveu sucesso")
	}
}

// Resposta síncrona (o POST já vem COMPLETED): não pode haver poll nenhum.
func TestMagnificRespostaSincronaNaoFazPoll(t *testing.T) {
	var polls atomic.Int32
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		if r.Method != http.MethodPost {
			polls.Add(1)
		}
		_, _ = w.Write([]byte(`{"data":{"task_id":"t-1","status":"COMPLETED","generated":["https://cdn/j.jpg"]}}`))
	}))
	defer srv.Close()

	url, err := clienteMagnific(srv).MagnificImage(t.Context(), "m", "x", "1:1", "", nil, MagnificSpec{})
	if err != nil {
		t.Fatalf("falhou: %v", err)
	}
	if url != "https://cdn/j.jpg" || polls.Load() != 0 {
		t.Errorf("url=%q polls=%d — resposta síncrona não devia gerar poll", url, polls.Load())
	}
}

// Sem chave, o motor falha claro em vez de sair chamando a API sem autenticação.
func TestMagnificSemChaveFalhaClaro(t *testing.T) {
	c := New("")
	if c.HasMagnific() {
		t.Error("HasMagnific true sem chave")
	}
	if _, err := c.MagnificImage(t.Context(), "m", "x", "1:1", "", nil, MagnificSpec{}); err == nil {
		t.Error("gerou sem chave")
	}
	if _, err := c.MagnificEdit(t.Context(), "m", "https://s3/a.jpg", MagnificSpec{}); err == nil {
		t.Error("editou sem chave")
	}
}

func TestMapAspectMagnific(t *testing.T) {
	m := map[string]string{"9:16": "portrait_16_9", "*": "square_1_1"}
	if got := mapAspectMagnific("9:16", m); got != "portrait_16_9" {
		t.Errorf("traduziu errado: %q", got)
	}
	if got := mapAspectMagnific("21:9", m); got != "square_1_1" {
		t.Errorf("reserva * não aplicada: %q", got)
	}
	if got := mapAspectMagnific("4:5", nil); got != "4:5" {
		t.Errorf("sem mapa devia mandar o aspecto cru: %q", got)
	}
}
