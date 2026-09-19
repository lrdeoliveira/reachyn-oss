package video

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

// redirecionaV — manda toda chamada pro servidor de teste (o base da API é constante).
type redirecionaV struct{ base string }

func (rd redirecionaV) RoundTrip(r *http.Request) (*http.Response, error) {
	u := *r.URL
	alvo := strings.TrimPrefix(rd.base, "http://")
	u.Scheme, u.Host = "http", alvo
	r2 := r.Clone(r.Context())
	r2.URL = &u
	r2.Host = alvo

	return http.DefaultTransport.RoundTrip(r2)
}

// fakeMagnificV — devolve COMPLETED já no POST (o caminho de poll é o mesmo código testado
// no lado da imagem; aqui o que importa é a MONTAGEM do corpo).
func fakeMagnificV(t *testing.T, corpo *map[string]any, caminho *string) *httptest.Server {
	t.Helper()

	return httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if caminho != nil {
			*caminho = r.URL.Path
		}
		if corpo != nil {
			_ = json.NewDecoder(r.Body).Decode(corpo)
		}
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(`{"data":{"task_id":"t-1","status":"COMPLETED","generated":["https://cdn/v.mp4"]}}`))
	}))
}

func clienteMagnificV(srv *httptest.Server) *Client {
	c := New("").WithMagnific("chave-x")
	c.magnificHTTP.Transport = redirecionaV{srv.URL}

	return c
}

// O MODELO VIVE NO PATH — é a diferença central pro agregador anterior, onde ele ia no corpo.
// Errar isso manda toda geração pro endpoint errado.
func TestMagnificVideoModeloVaiNoPath(t *testing.T) {
	var corpo map[string]any
	var caminho string
	srv := fakeMagnificV(t, &corpo, &caminho)
	defer srv.Close()

	_, err := clienteMagnificV(srv).MagnificVideo(t.Context(), "kling-v2-6-pro",
		"a raposa corre", "9:16", "5", []string{"https://s3/frame.jpg"},
		MagnificVideoSpec{
			AspectField: "aspect_ratio", DurationField: "duration",
			RefsField: "image", RefsSingle: true,
		})
	if err != nil {
		t.Fatalf("clipe falhou: %v", err)
	}
	if caminho != "/v1/ai/kling-v2-6-pro" {
		t.Errorf("path = %q, queria /v1/ai/kling-v2-6-pro", caminho)
	}
	if corpo["duration"] != "5" {
		t.Errorf("duração não propagada: %v — cena de 5s viraria o default do modelo em silêncio", corpo["duration"])
	}
	if corpo["image"] != "https://s3/frame.jpg" {
		t.Errorf("keyframe i2v não chegou: %v", corpo["image"])
	}
}

// Lip sync: retrato E áudio nos campos que o endpoint espera. É o papel que ficou sem
// sucessor no Higgsfield quando o agregador saiu, então o contrato precisa estar travado.
func TestMagnificLipSyncMandaRetratoEAudio(t *testing.T) {
	var corpo map[string]any
	var caminho string
	srv := fakeMagnificV(t, &corpo, &caminho)
	defer srv.Close()

	url, err := clienteMagnificV(srv).MagnificLipSync(t.Context(), "omni-human-1-5",
		"https://s3/retrato.jpg", "https://s3/fala.mp3",
		MagnificVideoSpec{ImageField: "image_url", AudioField: "audio_url",
			Extra: map[string]any{"output_resolution": "720"}})
	if err != nil {
		t.Fatalf("fala sincronizada falhou: %v", err)
	}
	if url != "https://cdn/v.mp4" {
		t.Errorf("url = %q", url)
	}
	if caminho != "/v1/ai/omni-human-1-5" {
		t.Errorf("path = %q", caminho)
	}
	if corpo["image_url"] != "https://s3/retrato.jpg" || corpo["audio_url"] != "https://s3/fala.mp3" {
		t.Errorf("retrato/áudio nos campos errados: %v", corpo)
	}
	if corpo["output_resolution"] == nil {
		t.Error("params fixos não foram mesclados")
	}
}

// Faltando retrato OU áudio, falha ANTES de chamar a API: um lip sync sem áudio gera um
// clipe mudo que parece certo e cobra igual.
func TestMagnificLipSyncExigeOsDois(t *testing.T) {
	srv := fakeMagnificV(t, nil, nil)
	defer srv.Close()
	c := clienteMagnificV(srv)

	if _, err := c.MagnificLipSync(t.Context(), "m", "https://s3/r.jpg", "", MagnificVideoSpec{}); err == nil {
		t.Error("aceitou fala sincronizada sem áudio")
	}
	if _, err := c.MagnificLipSync(t.Context(), "m", "", "https://s3/a.mp3", MagnificVideoSpec{}); err == nil {
		t.Error("aceitou fala sincronizada sem retrato")
	}
}

func TestMagnificVideoSemChaveFalhaClaro(t *testing.T) {
	c := New("")
	if c.HasMagnific() {
		t.Error("HasMagnific true sem chave")
	}
	if _, err := c.MagnificVideo(t.Context(), "m", "x", "9:16", "5", nil, MagnificVideoSpec{}); err == nil {
		t.Error("gerou sem chave")
	}
}

// Duração vazia não vira campo vazio no corpo: "" num enum de duração é 400.
func TestMagnificVideoDuracaoVaziaNaoVaiNoCorpo(t *testing.T) {
	var corpo map[string]any
	srv := fakeMagnificV(t, &corpo, nil)
	defer srv.Close()

	if _, err := clienteMagnificV(srv).MagnificVideo(t.Context(), "m", "x", "9:16", "  ", nil,
		MagnificVideoSpec{DurationField: "duration"}); err != nil {
		t.Fatalf("falhou: %v", err)
	}
	if _, tem := corpo["duration"]; tem {
		t.Errorf("duração vazia foi enviada: %v", corpo["duration"])
	}
}
