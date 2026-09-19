package content

import "testing"

// TestClampStoryScenes — o nº de cenas da história é normalizado para [storyMinScenes,
// storyMaxScenes] (hoje [3,50]); 0/negativo cai no default 8. Garante o guard-rail
// (anti denial-of-wallet) e o "sem limite fixo de 10".
func TestClampStoryScenes(t *testing.T) {
	cases := []struct {
		in   int
		want int
	}{
		{0, storyDefaultScenes},              // ausente → default
		{-5, storyDefaultScenes},             // negativo → default
		{1, storyMinScenes},                  // abaixo do mínimo → 3
		{2, storyMinScenes},                  // abaixo do mínimo → 3
		{storyMinScenes, storyMinScenes},     // mínimo exato
		{8, 8},                               // dentro da faixa
		{20, 20},                             // dentro da faixa (max subiu de 20 pra 50)
		{storyMaxScenes, storyMaxScenes},     // máximo exato
		{storyMaxScenes + 1, storyMaxScenes}, // acima do máximo → clampa
		{999, storyMaxScenes},                // muito acima → clampa
	}
	for _, c := range cases {
		if got := clampStoryScenes(c.in); got != c.want {
			t.Errorf("clampStoryScenes(%d) = %d; quero %d", c.in, got, c.want)
		}
	}
}
