package media

import (
	"context"
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"testing"
)

// Legenda ANIMADA no FILME (auditoria 2026-08-01): o console mandava `subtitleAnim`/
// `subtitleAccentColor` no /v1/filmassemble (mesmo subtitleStyleFrom das Histórias/Mídia) e o
// engine nem lia os campos — escolher pop/karaoke/bounce no Filme não fazia nada. Este teste
// trava as DUAS pontas: o preset chega ao ffmpeg-service quando escolhido, e o body fica
// EXATAMENTE como era (sem as chaves novas) quando não vem preset.
func capturaConcat(t *testing.T, o FilmMixOpts) map[string]any {
	t.Helper()
	var got map[string]any
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		raw, _ := io.ReadAll(r.Body)
		if err := json.Unmarshal(raw, &got); err != nil {
			t.Errorf("body inválido: %v", err)
		}
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(`{"video_url":"http://x/f.mp4"}`))
	}))
	defer srv.Close()
	if _, err := New(srv.URL, "tok").ConcatClips(context.Background(), []string{"c1.mp4"}, o); err != nil {
		t.Fatalf("ConcatClips: %v", err)
	}
	return got
}

func TestConcatClipsLegendaAnimada(t *testing.T) {
	body := capturaConcat(t, FilmMixOpts{
		Subtitles: true,
		Sub:       SubtitleStyle{Anim: "karaoke", AccentColor: "#FF00AA"},
	})
	if body["subtitle_anim"] != "karaoke" {
		t.Errorf("subtitle_anim = %#v, queria karaoke", body["subtitle_anim"])
	}
	if body["subtitle_accent_color"] != "#FF00AA" {
		t.Errorf("subtitle_accent_color = %#v, queria #FF00AA", body["subtitle_accent_color"])
	}
}

// Sem preset animado o payload não ganha campo nenhum — o Filme de hoje sai byte a byte igual.
func TestConcatClipsSemAnimNaoMudaOBody(t *testing.T) {
	body := capturaConcat(t, FilmMixOpts{Subtitles: true, Sub: SubtitleStyle{Pos: "top"}})
	for _, k := range []string{"subtitle_anim", "subtitle_accent_color"} {
		if _, ok := body[k]; ok {
			t.Errorf("%s não deveria estar no body sem preset animado", k)
		}
	}
	if body["subtitle_pos"] != "top" {
		t.Errorf("subtitle_pos = %#v, queria top (estilo de sempre intacto)", body["subtitle_pos"])
	}
}
