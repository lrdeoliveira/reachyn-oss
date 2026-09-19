// Admin do engine — chaves de geração geridas pelo operador (via console Filament).
// Protegido por X-Admin-Token (== ENGINE_ADMIN_TOKEN, compartilhado com o console).
package api

import (
	"context"
	"crypto/subtle"
	"encoding/json"
	"fmt"
	"log"
	"net/http"
	"time"

	"github.com/redfoxcode/reachyn/engine/internal/genkeys"
	"github.com/redfoxcode/reachyn/engine/internal/provider/image"
	"github.com/redfoxcode/reachyn/engine/internal/provider/llm"
	"github.com/redfoxcode/reachyn/engine/internal/provider/speech"
	"github.com/redfoxcode/reachyn/engine/internal/provider/video"
)

// tokenOK valida o header X-Admin-Token contra s.admin (ENGINE_ADMIN_TOKEN, token
// compartilhado engine⇄console). Comparação constant-time (AUD-012, CWE-208): sem token
// configurado → false (admin/auth desligado, fail-closed).
func (s *Server) tokenOK(r *http.Request) bool {
	if s.admin == "" {
		return false
	}
	return subtle.ConstantTimeCompare([]byte(r.Header.Get("X-Admin-Token")), []byte(s.admin)) == 1
}

// authAdmin valida o token compartilhado. Sem token configurado → 403 (admin desligado).
func (s *Server) authAdmin(w http.ResponseWriter, r *http.Request) bool {
	if !s.tokenOK(r) {
		writeJSON(w, http.StatusForbidden, map[string]string{"error": "forbidden"})
		return false
	}
	return true
}

// PUT /v1/admin/gen-keys — o console empurra o conjunto de chaves de geração; reconstrói o
// serviço com elas sobrepostas ao .env (efeito imediato, sem redeploy).
func (s *Server) setGenKeys(w http.ResponseWriter, r *http.Request) {
	if !s.authAdmin(w, r) {
		return
	}
	var set genkeys.Set
	if !decode(w, r, &set) {
		return
	}
	s.cur.Store(s.build(set))
	// 🐛 FALTAVA: o client de sprites NÃO era reconstruído aqui, então a chave salva no console
	// nunca chegava no motor. `refreshSprite` só era chamado em New(), e com genkeys.Set{} VAZIO
	// — ou seja, lia exclusivamente SPRITERRIFIC_API_KEY do ambiente. Salvar em "Chaves de
	// geração" gravava, cifrava, empurrava... e o /v1/sprite/me seguia respondendo "chave não
	// configurada", sem nenhuma pista de por quê. O próprio comentário do refreshSprite dizia
	// "chamado no boot e a cada push de chaves": a segunda metade simplesmente não era verdade.
	s.refreshSprite(set)
	w.WriteHeader(http.StatusNoContent)
}

// POST /v1/admin/test-key — testa uma chave candidata SEM salvar. {provider, key}.
func (s *Server) testKey(w http.ResponseWriter, r *http.Request) {
	if !s.authAdmin(w, r) {
		return
	}
	var in struct {
		Provider string `json:"provider"`
		Key      string `json:"key"`
	}
	if !decode(w, r, &in) {
		return
	}
	start := time.Now()
	err := testProvider(r.Context(), in.Provider, in.Key)
	res := map[string]any{"ok": err == nil, "latency_ms": time.Since(start).Milliseconds()}
	if err != nil {
		res["error"] = err.Error()
	}
	writeJSON(w, http.StatusOK, res)
}

// testProvider faz uma chamada mínima por provedor pra validar a chave (sem gerar mídia paga).
func testProvider(ctx context.Context, provider, key string) error {
	ctx, cancel := context.WithTimeout(ctx, 30*time.Second)
	defer cancel()
	switch provider {
	case "minimax":
		// LLMConfig vazio → defaults históricos (api.minimax.io + MiniMax-M2.7).
		_, err := llm.New("", key, llm.LLMConfig{}).M3(ctx, "", "ping", 2048)
		return err
	case "ollama":
		// LLMConfig vazio → defaults históricos (ollama.com + gemini-3-flash-preview).
		_, err := llm.New(key, "", llm.LLMConfig{}).OllamaChat(ctx, "", "ping", false)
		return err
	case "elevenlabs":
		return speech.New(key).Ping(ctx)
	case "google":
		return video.New(key).PingGoogle(ctx)
	case "magnific":
		return image.New("").WithMagnific(key).PingMagnific(ctx)
	case "spriterrific":
		// 🐛 `pingSprite` existia desde a integração e NUNCA foi chamado — o switch não tinha o
		// caso, então "Testar" numa chave de sprites caía no default "provedor desconhecido".
		// Função escrita, testada em ninguém: o operador só descobriria que a chave não presta
		// na primeira geração, depois de já ter salvo.
		return pingSprite(ctx, key)
	default:
		return fmt.Errorf("provedor desconhecido: %s", provider)
	}
}

// SyncFromConsole puxa as chaves geridas no console no boot (override do .env). Best-effort:
// o console pode ainda estar subindo, então re-tenta; falha silenciosa mantém o .env.
func (s *Server) SyncFromConsole(consoleURL string) {
	if consoleURL == "" || s.admin == "" {
		return
	}
	client := &http.Client{Timeout: 8 * time.Second}
	for i := 0; i < 12; i++ {
		time.Sleep(5 * time.Second) // dá tempo do console subir/migrar
		req, _ := http.NewRequest(http.MethodGet, consoleURL+"/internal/gen-keys", nil)
		req.Header.Set("X-Admin-Token", s.admin)
		resp, err := client.Do(req)
		if err != nil {
			continue
		}
		if resp.StatusCode == http.StatusOK {
			var set genkeys.Set
			if json.NewDecoder(resp.Body).Decode(&set) == nil {
				s.cur.Store(s.build(set))
				// Mesma omissão do setGenKeys: sem isto, um engine que REINICIA volta com o
				// motor de sprites desligado mesmo com a chave salva no console — o boot-fetch
				// traz a chave e a joga fora. Os dois caminhos que trocam chaves têm de trocar
				// o client de sprites junto, senão ele fica preso no que o .env tinha no boot.
				s.refreshSprite(set)
				log.Printf("reachyn-engine: chaves de geração sincronizadas do console")
			}
			resp.Body.Close()
			return
		}
		resp.Body.Close()
	}
	log.Printf("reachyn-engine: boot-fetch de chaves não respondeu — seguindo com .env")
}
