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

	"github.com/lrdeoliveira/reachyn-oss/engine/internal/config"
	"github.com/lrdeoliveira/reachyn-oss/engine/internal/genkeys"
	"github.com/lrdeoliveira/reachyn-oss/engine/internal/provider/image"
	"github.com/lrdeoliveira/reachyn-oss/engine/internal/provider/llm"
	"github.com/lrdeoliveira/reachyn-oss/engine/internal/provider/speech"
	"github.com/lrdeoliveira/reachyn-oss/engine/internal/provider/video"
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
// Bases/modelos/endpoints vêm do env (config.Load) — white-label: nada de URL no código.
func testProvider(ctx context.Context, provider, key string) error {
	ctx, cancel := context.WithTimeout(ctx, 30*time.Second)
	defer cancel()
	cfg := config.Load()
	// Rótulos opacos (contrato de fio com o console) — casam com as tags JSON de genkeys.Set.
	switch provider {
	case "text":
		// base/modelo de texto primário vêm do env.
		_, err := llm.New("", key, llm.LLMConfig{TextBaseURL: cfg.TextBaseURL, TextModel: cfg.TextModel}).GenText(ctx, "", "ping", 2048)
		return err
	case "text_alt":
		// base/modelo de texto alternativo vêm do env.
		_, err := llm.New(key, "", llm.LLMConfig{TextAltBaseURL: cfg.TextAltBaseURL, TextAltModel: cfg.TextAltModel}).AltChat(ctx, "", "ping", false)
		return err
	case "voice":
		return speech.New(key, speech.Config{BaseURL: cfg.SpeechBaseURL, Model: cfg.SpeechModel, APIKeyHeader: cfg.SpeechAPIKeyHeader}).Ping(ctx)
	case "media":
		return image.New(key, "", image.ImageConfig{GenURL: cfg.ImageGenURL, EditURL: cfg.ImageEditURL}).Ping(ctx)
	case "premium":
		return video.New("", key, video.VideoConfig{PremiumBaseURL: cfg.PremiumVideoBaseURL, PremiumModel: cfg.PremiumVideoModel, PremiumAuthHeader: cfg.PremiumVideoAuthHeader}).PingPremium(ctx)
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
				log.Printf("reachyn-engine: chaves de geração sincronizadas do console")
			}
			resp.Body.Close()
			return
		}
		resp.Body.Close()
	}
	log.Printf("reachyn-engine: boot-fetch de chaves não respondeu — seguindo com .env")
}
