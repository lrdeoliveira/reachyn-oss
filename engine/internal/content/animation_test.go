package content

import "testing"

// Testes PUROS do Estúdio de Animação (padrão da casa: só lógica determinística, sem LLM/HTTP).

func TestClampAnimScenes(t *testing.T) {
	cases := map[int]int{0: animDefaultScenes, -3: animDefaultScenes, 1: 1, 6: 6, 20: 20, 50: animMaxScenes}
	for in, want := range cases {
		if got := clampAnimScenes(in); got != want {
			t.Errorf("clampAnimScenes(%d) = %d, quero %d", in, got, want)
		}
	}
}

func TestNormalizeParsed(t *testing.T) {
	out := ScriptParseResult{
		Title: "  O Último Chocolate  ",
		Characters: []AnimElement{
			{Name: "Oliver", Kind: "child", VisualPrompt: "a cute 3D boy", VoiceHint: "menino ~8 anos"},
			{Name: "Sofia", VisualPrompt: "a cute 3D girl", VoiceHint: "menina ~6 anos"},
			{Name: "", VisualPrompt: "sem nome — descartar"},
			{Name: "Fantasma", VisualPrompt: ""}, // sem prompt — descartar
		},
		Locations: []AnimElement{{Name: "Cozinha", VisualPrompt: "a cozy kitchen"}},
		Props:     []AnimElement{{Name: "Chocolate", VisualPrompt: "a chocolate bar"}},
		Scenes: []AnimScene{
			{
				Title: "A disputa", Action: "Oliver pega o chocolate.",
				ImagePrompt: "two kids reaching for a chocolate bar",
				Characters:  []string{"Oliver", "sofia", "Figurante Inventado"},
				Location:    "Cozinha",
				Dialogue: []AnimDialogueLine{
					{Character: "Oliver", Line: "É meu!"},
					{Character: "Sofia", Line: ""}, // fala vazia — descartar
				},
				Spec: &SceneSpec{Shot: "closeup", Movement: "push_in", Light: "soft"},
			},
			{Title: "Vazia", Action: "", ImagePrompt: ""}, // cena vazia — descartar
			{Title: "Extra", Action: "Cena 3", ImagePrompt: "x"},
			{Title: "Estouro", Action: "Cena 4", ImagePrompt: "y"},
		},
	}
	normalizeParsed(&out, 2)

	if out.Title != "O Último Chocolate" {
		t.Errorf("title = %q", out.Title)
	}
	if len(out.Characters) != 2 {
		t.Fatalf("characters = %d, quero 2 (descarta sem nome/sem prompt)", len(out.Characters))
	}
	if out.Characters[0].VoiceHint != "menino ~8 anos" {
		t.Errorf("voice_hint perdido: %q", out.Characters[0].VoiceHint)
	}
	if len(out.Scenes) != 2 {
		t.Fatalf("scenes = %d, quero 2 (descarta vazia + clamp maxScenes)", len(out.Scenes))
	}
	sc := out.Scenes[0]
	// Elenco restrito aos personagens extraídos (case-insensitive; figurante inventado cai fora).
	if len(sc.Characters) != 2 || sc.Characters[0] != "Oliver" || sc.Characters[1] != "sofia" {
		t.Errorf("elenco da cena = %v", sc.Characters)
	}
	if len(sc.Dialogue) != 1 || sc.Dialogue[0].Line != "É meu!" {
		t.Errorf("diálogo = %v", sc.Dialogue)
	}
	// video_prompt derivado do spec.movement (determinístico, só câmera).
	if sc.VideoPrompt == "" || sc.VideoPrompt != moveDirectives["push_in"] {
		t.Errorf("video_prompt = %q, quero a diretiva de push_in", sc.VideoPrompt)
	}
	if sc.Location != "Cozinha" {
		t.Errorf("location = %q", sc.Location)
	}
}

func TestNormalizeParsedLocationDesconhecida(t *testing.T) {
	out := ScriptParseResult{
		Characters: []AnimElement{{Name: "A", VisualPrompt: "x"}},
		Scenes:     []AnimScene{{Action: "cena", ImagePrompt: "p", Location: "Lugar Inventado"}},
	}
	normalizeParsed(&out, 5)
	if out.Scenes[0].Location != "" {
		t.Errorf("location desconhecida devia zerar, veio %q", out.Scenes[0].Location)
	}
}
