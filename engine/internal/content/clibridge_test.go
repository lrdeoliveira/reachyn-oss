package content

import (
	"encoding/base64"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync/atomic"
	"testing"

	"github.com/redfoxcode/reachyn/engine/internal/gerr"
	"github.com/redfoxcode/reachyn/engine/internal/media"
	"github.com/redfoxcode/reachyn/engine/internal/provider/llm"
	"github.com/redfoxcode/reachyn/engine/internal/provider/speech"
	"github.com/redfoxcode/reachyn/engine/internal/provider/video"
)

// Roteamento do CLI Bridge (sidecar no host) pra VÍDEO e ÁUDIO. O que estes testes travam é o
// buraco de 2026-08-02: o cliente CliVideo existia e NINGUÉM o chamava — escolher o motor no
// seletor caía no erro de config "modelo não suportado neste fluxo".

// bridgeFake — bridge de mentira: guarda o corpo recebido e devolve a resposta {ok,b64,ext}.
func bridgeFake(t *testing.T, path string, got *map[string]any, calls *atomic.Int32, fail bool) *httptest.Server {
	t.Helper()
	return httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != path {
			t.Errorf("bridge: caminho %s, queria %s", r.URL.Path, path)
		}
		if calls != nil {
			calls.Add(1)
		}
		var in map[string]any
		_ = json.NewDecoder(r.Body).Decode(&in)
		if got != nil {
			*got = in
		}
		w.Header().Set("Content-Type", "application/json")
		if fail {
			w.WriteHeader(http.StatusBadGateway)
			_, _ = w.Write([]byte(`{"error":"geração falhou"}`))
			return
		}
		_, _ = w.Write([]byte(`{"ok":true,"b64":"` +
			base64.StdEncoding.EncodeToString([]byte("bytes-de-midia")) + `","ext":"mp4"}`))
	}))
}

// persistFake — ffmpeg-service de mentira: confere que os BYTES chegaram no /persist-bytes e
// devolve a URL durável.
func persistFake(t *testing.T, got *map[string]any) *httptest.Server {
	t.Helper()
	return httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/persist-bytes" {
			t.Errorf("ffmpeg-service: caminho %s, queria /persist-bytes", r.URL.Path)
		}
		var in map[string]any
		_ = json.NewDecoder(r.Body).Decode(&in)
		if got != nil {
			*got = in
		}
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(`{"url":"https://s3.exemplo/midia.mp4","persisted":true}`))
	}))
}

// O ramo novo: provider "cli-bridge" gera pelo sidecar, PROPAGA duração/resolução (antes o bridge
// caía no default de 5s/720p e a cena de 10s virava 5s em silêncio) e persiste os bytes — que é
// a única forma de virar URL durável, já que o safe_fetch não alcança o host.
func TestClipCliBridgePropagaDuracaoResolucaoEPersiste(t *testing.T) {
	var pedido, persistido map[string]any
	br := bridgeFake(t, "/v1/generate-video", &pedido, nil, false)
	defer br.Close()
	ff := persistFake(t, &persistido)
	defer ff.Close()

	s := New(nil, nil, nil, nil, nil,
		video.New("").WithCliBridge(br.URL, "tok"),
		nil, nil, media.New(ff.URL, "tok"), nil, nil)

	url, err := s.clipModelOrdered(t.Context(), "cli-bridge", "higgsfield-cinema", "",
		[]string{"https://exemplo/keyframe.jpg"}, "uma raposa na colina", "10", "9:16", video.MagnificVideoSpec{})
	if err != nil {
		t.Fatalf("cli-bridge deveria gerar, veio erro: %v", err)
	}
	if url != "https://s3.exemplo/midia.mp4" {
		t.Fatalf("url persistida = %q", url)
	}
	if pedido["provider"] != "higgsfield-cinema" {
		t.Errorf("provider enviado ao bridge = %v (o `model` é o adapter)", pedido["provider"])
	}
	if d, _ := pedido["duration_sec"].(float64); d != 10 {
		t.Errorf("duration_sec = %v, queria 10 (a duração da cena tem que viajar)", pedido["duration_sec"])
	}
	if pedido["resolution"] != cliClipResolution {
		t.Errorf("resolution = %v, queria %s", pedido["resolution"], cliClipResolution)
	}
	if refs, _ := pedido["ref_urls"].([]any); len(refs) != 1 || refs[0] != "https://exemplo/keyframe.jpg" {
		t.Errorf("ref_urls = %v, queria o keyframe (i2v ancorado)", pedido["ref_urls"])
	}
	if persistido["kind"] != "clip" || persistido["b64"] == "" {
		t.Errorf("persist-bytes recebeu %v — kind/b64 errados", persistido)
	}
}

// FORA do retry: a chamada custa assinatura e leva minutos. Retentar 3× gastaria 3 gerações e
// ~9 min por um erro que provavelmente não é oscilação. UMA chamada, erro claro, console estorna.
func TestClipCliBridgeNaoRetenta(t *testing.T) {
	var calls atomic.Int32
	br := bridgeFake(t, "/v1/generate-video", nil, &calls, true)
	defer br.Close()

	s := New(nil, nil, nil, nil, nil,
		video.New("").WithCliBridge(br.URL, "tok"), nil, nil, media.New("http://127.0.0.1:9", ""), nil, nil)

	_, err := s.clipModelOrdered(t.Context(), "cli-bridge", "higgsfield-cinema", "",
		[]string{"https://exemplo/k.jpg"}, "p", "6", "9:16", video.MagnificVideoSpec{})
	if err == nil {
		t.Fatal("falha do bridge deveria subir como erro")
	}
	if n := calls.Load(); n != 1 {
		t.Fatalf("bridge chamado %d× — tem que ser 1 (geração cara não se retenta sozinha)", n)
	}
}

// Sem CLI_BRIDGE_URL o motor está desligado: erro claro na hora, sem rede e sem cair pra outro
// provedor em silêncio (o cliente ESCOLHEU este motor).
func TestClipCliBridgeDesligado(t *testing.T) {
	s := New(nil, nil, nil, nil, nil, video.New(""), nil, nil, media.New("", ""), nil, nil)
	_, err := s.clipModelOrdered(t.Context(), "cli-bridge", "higgsfield-cinema", "",
		nil, "p", "6", "9:16", video.MagnificVideoSpec{})
	if err == nil || !strings.Contains(err.Error(), "CLI_BRIDGE_URL") {
		t.Fatalf("bridge desligado deveria falhar citando a env, veio: %v", err)
	}
}

// SEM REGRESSÃO: provider fora da lista continua caindo no erro de CONFIG explícito (era o que
// mascarava modelo incompatível com um 403 de conta morta antes de 2026-07-22).
func TestClipProviderDesconhecidoContinuaConfig(t *testing.T) {
	s := New(nil, nil, nil, nil, nil, video.New(""), nil, nil, media.New("", ""), nil, nil)
	_, err := s.clipModelOrdered(t.Context(), "provedor-que-nao-existe", "m", "",
		nil, "p", "6", "9:16", video.MagnificVideoSpec{})
	if gerr.KindOf(err) != gerr.Config {
		t.Fatalf("provider desconhecido deveria ser erro de config, veio: %v", err)
	}
}

// ÁUDIO: o slug do catálogo roteia a narração pro bridge e os bytes viram URL durável.
func TestSynthesizeSpeechPeloCliBridge(t *testing.T) {
	var pedido, persistido map[string]any
	br := bridgeFake(t, "/v1/generate-audio", &pedido, nil, false)
	defer br.Close()
	ff := persistFake(t, &persistido)
	defer ff.Close()

	s := New(nil, nil, nil, nil, nil, nil,
		speech.New("").WithCliBridge(br.URL, "tok"), nil, media.New(ff.URL, "tok"), nil, nil)

	url, err := s.SynthesizeSpeech(t.Context(), "boa noite", "", "pt", "audio-higgsfield-tts", "", "")
	if err != nil {
		t.Fatalf("narração pelo bridge falhou: %v", err)
	}
	if url == "" {
		t.Fatal("narração deveria devolver a URL persistida")
	}
	if pedido["provider"] != "higgsfield-tts" || pedido["prompt"] != "boa noite" {
		t.Errorf("payload de áudio errado: %v", pedido)
	}
	if _, tem := pedido["aspect"]; tem {
		t.Error("áudio não tem aspect — o campo não deveria existir no payload")
	}
	if persistido["kind"] != "tts" {
		t.Errorf("persist-bytes kind = %v, queria tts", persistido["kind"])
	}
}

// A voz default do ElevenLabs não existe no catálogo do bridge: mandá-la faria o bridge recusar
// (não tem forma de UUID). Ela vira "" = voz default do adapter.
func TestSynthesizeSpeechCliBridgeNaoMandaVozDoOutroMotor(t *testing.T) {
	var pedido map[string]any
	br := bridgeFake(t, "/v1/generate-audio", &pedido, nil, false)
	defer br.Close()
	ff := persistFake(t, nil)
	defer ff.Close()

	s := New(nil, nil, nil, nil, nil, nil,
		speech.New("").WithCliBridge(br.URL, "tok"), nil, media.New(ff.URL, "tok"), nil, nil)

	if _, err := s.SynthesizeSpeech(t.Context(), "oi", defaultVoice, "pt", "higgsfield-tts", "", ""); err != nil {
		t.Fatalf("narração falhou: %v", err)
	}
	if v, tem := pedido["voice_id"]; tem {
		t.Errorf("voice_id = %v — a voz de outro motor não pode viajar pro bridge", v)
	}
}

// Modelo fora da allowlist NÃO vira erro: segue pelo caminho de sempre (ffmpeg-service).
func TestCliSpeechAdapterSoAceitaOQueExisteNoBridge(t *testing.T) {
	for _, m := range []string{"", "eleven_v3", "audio-premium", "higgsfield", "cli-bridge"} {
		if got := cliSpeechAdapter(m); got != "" {
			t.Errorf("cliSpeechAdapter(%q) = %q, queria vazio (cai no caminho antigo)", m, got)
		}
	}
	for _, m := range []string{"audio-higgsfield-tts", " higgsfield-tts "} {
		if got := cliSpeechAdapter(m); got != "higgsfield-tts" {
			t.Errorf("cliSpeechAdapter(%q) = %q, queria higgsfield-tts", m, got)
		}
	}
}

// ─── Linha de TEXTO pelo bridge (2026-08-03) ─────────────────────────────────────────
//
// A regressão que estes testes travam: o console manda gen_lines.text.model com o ID do
// modelo do CATÁLOGO ("claude-fable-5"), não com o nome do adapter do bridge. Repassado
// cru, o bridge devolveria "provider desconhecido", todo texto cairia na reserva MiniMax
// e o único sintoma seria "a IA piorou" — sem erro em log nem em teste.

func TestCliTextAdapterIgnoraModeloDeCatalogo(t *testing.T) {
	// IDs que o console de fato manda hoje: nenhum é adapter do bridge.
	for _, m := range []string{"", "claude-fable-5", "claude-haiku-4-5", "gemini-3-flash",
		"gpt-5-2", "MiniMax-M2.7", "cli-bridge", "higgsfield"} {
		if got := cliTextAdapter(m); got != "" {
			t.Errorf("cliTextAdapter(%q) = %q, queria vazio (usa o adapter principal)", m, got)
		}
	}
}

func TestCliTextAdapterAceitaAdapterExplicito(t *testing.T) {
	for _, m := range []string{"codex", "mmx", "cursor", "agy"} {
		if got := cliTextAdapter(m); got != m {
			t.Errorf("cliTextAdapter(%q) = %q, queria %q", m, got, m)
		}
	}
}

// FIO LIGADO: textPrime (a linha default de TODO texto — roteiro, plano, resumo) tem de
// sair pelo bridge. Sem este teste, WithCliBridge/CliText poderiam existir compilando e
// nunca serem chamados — foi exatamente assim que o CliVideo passou meses morto.
func TestTextPrimeSaiPeloBridge(t *testing.T) {
	var pedido map[string]any
	var chamadas atomic.Int32
	br := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/v1/text" {
			t.Errorf("bridge: caminho %s, queria /v1/text", r.URL.Path)
		}
		chamadas.Add(1)
		_ = json.NewDecoder(r.Body).Decode(&pedido)
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(`{"ok":true,"text":"CENA 1\n\nCENA 2"}`))
	}))
	defer br.Close()

	s := New(nil, nil, llm.New("", "", llm.LLMConfig{}).WithCliBridge(br.URL, "tok", "", ""),
		nil, nil, nil, nil, nil, nil, nil, nil)

	out, err := s.textPrime(t.Context(), "Você é roteirista.", "escreva 2 cenas", 2048)
	if err != nil {
		t.Fatalf("textPrime falhou: %v", err)
	}
	if chamadas.Load() != 1 {
		t.Fatalf("bridge chamado %d vez(es) — a linha de texto não está ligada nele", chamadas.Load())
	}
	if out != "CENA 1\n\nCENA 2" {
		t.Errorf("resposta veio mutilada: %q", out)
	}
	if pedido["provider"] != "claude" {
		t.Errorf("provider = %v, queria o adapter principal (claude)", pedido["provider"])
	}
	if pedido["system"] != "Você é roteirista." {
		t.Errorf("system não chegou ao bridge: %v", pedido["system"])
	}
}

// Bridge DESLIGADO (URL vazia) não pode derrubar a geração: cai na reserva, como era com a
// chave do agregador ausente.
func TestTextPrimeSemBridgeCaiNaReserva(t *testing.T) {
	mm := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(`{"choices":[{"message":{"content":"reserva ok"}}]}`))
	}))
	defer mm.Close()

	s := New(nil, nil, llm.New("", "k", llm.LLMConfig{MinimaxBaseURL: mm.URL}),
		nil, nil, nil, nil, nil, nil, nil, nil)

	out, err := s.textPrime(t.Context(), "sys", "user", 2048)
	if err != nil {
		t.Fatalf("sem bridge a geração morreu em vez de cair na reserva: %v", err)
	}
	if out != "reserva ok" {
		t.Errorf("reserva devolveu %q", out)
	}
}
