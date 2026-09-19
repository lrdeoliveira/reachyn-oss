package bridgealvo

import "testing"

var (
	vps = Alvo{URL: "http://host.docker.internal:3921", Token: "tok-vps"}
	mac = Alvo{URL: "https://mac.exemplo", Token: "tok-mac"}
)

// A regra que mais importa: adapter SEM prefixo continua indo pra VPS. Se um dia isto
// inverter, toda geração do produto migra pra uma máquina PESSOAL — que dorme, desliga e
// troca de rede — e o sintoma seria "às vezes falha", o mais caro de diagnosticar.
func TestSemPrefixoVaiProSidecarDaVPS(t *testing.T) {
	for _, ad := range []string{"mmx", "codex", "higgsfield", "higgsfield:gpt_image_2", ""} {
		alvo, limpo := Resolve(ad, vps, mac)
		if alvo.URL != vps.URL || alvo.Token != vps.Token {
			t.Errorf("Resolve(%q) foi pro Mac — devia ir pra VPS", ad)
		}
		if limpo != ad {
			t.Errorf("Resolve(%q) mudou o adapter pra %q", ad, limpo)
		}
	}
}

func TestPrefixoMacVaiProMacESomeDoAdapter(t *testing.T) {
	casos := map[string]string{
		"mac:claude":                 "claude",
		"mac:cursor":                 "cursor",
		"mac:higgsfield:gpt_image_2": "higgsfield:gpt_image_2", // só o PRIMEIRO prefixo é o alvo
		"mac: mmx ":                  "mmx",                    // espaço não muda o destino
	}
	for entrada, esperado := range casos {
		alvo, limpo := Resolve(entrada, vps, mac)
		if alvo.URL != mac.URL || alvo.Token != mac.Token {
			t.Errorf("Resolve(%q) não foi pro Mac", entrada)
		}
		if limpo != esperado {
			t.Errorf("Resolve(%q) = %q, queria %q", entrada, limpo, esperado)
		}
	}
}

// Mac não configurado: o adapter "mac:" NÃO pode escorregar pra VPS. A CLI pedida pode nem
// existir lá (`claude` e `cursor-agent` não estão na VPS), e o resultado seria um erro obscuro
// de "provider desconhecido" no sidecar errado, em vez de "o Mac está fora".
func TestMacDesligadoNaoEscorregaProVPS(t *testing.T) {
	alvo, limpo := Resolve("mac:claude", vps, Alvo{})
	if alvo.Configurado() {
		t.Fatalf("alvo veio configurado: %+v", alvo)
	}
	if alvo.URL == vps.URL {
		t.Error("adapter do Mac caiu na VPS — a CLI pode nem existir lá")
	}
	if limpo != "claude" {
		t.Errorf("adapter limpo = %q", limpo)
	}
}

func TestConfigurado(t *testing.T) {
	if (Alvo{}).Configurado() {
		t.Error("alvo vazio disse estar configurado")
	}
	if !vps.Configurado() {
		t.Error("alvo com URL disse não estar configurado")
	}
}
