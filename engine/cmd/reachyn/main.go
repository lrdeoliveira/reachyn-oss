// reachyn engine — geração de conteúdo + jobs de mídia + providers.
// F2: research/texto/imagem via providers (llm/rerank/image). F3+: jobs longos.
package main

import (
	"log"
	"net/http"
	"time"

	"github.com/redfoxcode/reachyn/engine/internal/api"
	"github.com/redfoxcode/reachyn/engine/internal/config"
	"github.com/redfoxcode/reachyn/engine/internal/content"
	"github.com/redfoxcode/reachyn/engine/internal/genkeys"
	"github.com/redfoxcode/reachyn/engine/internal/media"
	"github.com/redfoxcode/reachyn/engine/internal/provider/image"
	"github.com/redfoxcode/reachyn/engine/internal/provider/llm"
	"github.com/redfoxcode/reachyn/engine/internal/provider/mesh"
	"github.com/redfoxcode/reachyn/engine/internal/provider/motion"
	"github.com/redfoxcode/reachyn/engine/internal/provider/music"
	"github.com/redfoxcode/reachyn/engine/internal/provider/rerank"
	"github.com/redfoxcode/reachyn/engine/internal/provider/scraper"
	"github.com/redfoxcode/reachyn/engine/internal/provider/search"
	"github.com/redfoxcode/reachyn/engine/internal/provider/speech"
	"github.com/redfoxcode/reachyn/engine/internal/provider/video"
)

func main() {
	cfg := config.Load()

	// build reconstrói o serviço com as chaves de geração geridas no console sobrepostas
	// ao .env. As de pesquisa (tavily/brave/jina/scrapecreators) seguem do .env (BYOK por tenant
	// vai por request). Providers são clients HTTP baratos — reconstruir é instantâneo.
	build := func(k genkeys.Set) *content.Service {
		return content.New(
			search.New(cfg.Tavily, cfg.Brave, cfg.Jina),
			scraper.New(cfg.ScrapeCreators),
			llm.New(genkeys.Or(k.Ollama, cfg.Ollama), genkeys.Or(k.Minimax, cfg.Minimax), llm.LLMConfig{
				// base_url + model do LLM de texto vêm das chaves geridas no console (vazio → default no llm).
				MinimaxBaseURL: k.MinimaxBaseURL,
				MinimaxModel:   k.MinimaxModel,
				OllamaBaseURL:  k.OllamaBaseURL,
				OllamaModel:    k.OllamaModel,
			}).
				// Texto pela CLI do host (conta de ASSINATURA, sem crédito por chamada) — é a
				// linha PRIMÁRIA desde 2026-08-03, à frente do agregador. Ordem efetiva:
				// cli-bridge → MiniMax M2.7. Bridge desligado (URL vazia) = cai pro MiniMax.
				WithCliBridge(cfg.CliBridgeURL, cfg.CliBridgeToken, cfg.CliTextCLI, cfg.CliTextFastCLI).
				WithCliBridgeMac(cfg.CliBridgeMacURL, cfg.CliBridgeMacToken),
			rerank.New(cfg.Jina),
			image.New(genkeys.Or(k.Minimax, cfg.Minimax)).
				WithMagnific(genkeys.Or(k.Magnific, cfg.Magnific)).           // Magnific (API HTTP): t2i/i2i + upscale/edição
				WithCliBridge(cfg.CliBridgeURL, cfg.CliBridgeToken).          // CLIs no host da VPS — ver tools/cli-bridge
				WithCliBridgeMac(cfg.CliBridgeMacURL, cfg.CliBridgeMacToken). // 2º sidecar no Mac (adapter "mac:")
				WithComfy(cfg.ComfyURL),                                      // ComfyUI local (Mac do Luciano) — vazio na VPS = desligado, erro claro em runtime
			video.New(genkeys.Or(k.Google, cfg.Google)).
				WithMinimax(genkeys.Or(k.Minimax, cfg.Minimax), k.MinimaxBaseURL). // Hailuo direto (conta pré-paga) p/ GIF/vídeo
				WithComfy(cfg.ComfyURL).                                           // prévia de movimento LOCAL (Wan 2.2) — mesmo servidor da imagem
				WithMagnific(genkeys.Or(k.Magnific, cfg.Magnific)).                // Magnific: clipe + fala sincronizada (OmniHuman)
				WithCliBridge(cfg.CliBridgeURL, cfg.CliBridgeToken).               // vídeo via CLI no host (mesmo sidecar da imagem)
				WithCliBridgeMac(cfg.CliBridgeMacURL, cfg.CliBridgeMacToken),
			speech.New(genkeys.Or(k.Elevenlabs, cfg.Elevenlabs)).
				WithCliBridge(cfg.CliBridgeURL, cfg.CliBridgeToken). // narração via CLI no host (mesmo sidecar de imagem/vídeo)
				WithCliBridgeMac(cfg.CliBridgeMacURL, cfg.CliBridgeMacToken).
				WithMinimax(genkeys.Or(k.Minimax, cfg.Minimax), k.MinimaxBaseURL), // 🔁 reserva de voz do PREVIEW (ver SynthesizeSpeech)
			music.New(genkeys.Or(k.Minimax, cfg.Minimax), k.MinimaxBaseURL),
			media.New(cfg.FFmpegURL, cfg.FFmpegToken),
			motion.New(cfg.RunningHub), // motion transfer via RunningHub (workflow curado; chave via env)
			mesh.New(cfg.ComfyURL),     // 🧊 malha 3D (Hunyuan3D nativo) no MESMO ComfyUI — vazio na VPS = desligado
		)
	}
	apiSrv := api.New(build, cfg.AdminToken)
	// Boot-fetch: puxa as chaves geridas no console (override do .env) assim que o console subir.
	go apiSrv.SyncFromConsole(cfg.ConsoleURL)

	addr := ":" + cfg.Port
	// Timeouts explícitos (AUD-011, CWE-400): sem eles um cliente lento/malicioso prende
	// conexões e cabeçalhos abertos indefinidamente (Slowloris). ReadHeaderTimeout e
	// ReadTimeout cortam a leitura da requisição; IdleTimeout recicla keep-alive ocioso.
	// WriteTimeout fica DESABILITADO (0): a geração de mídia é longa (ffmpeg até ~600s,
	// speech 300s, deepsearch 240s) e um teto de escrita cortaria a resposta no meio.
	srv := &http.Server{
		Addr:              addr,
		Handler:           apiSrv.Routes(),
		ReadHeaderTimeout: 10 * time.Second,
		ReadTimeout:       60 * time.Second,
		WriteTimeout:      0, // desabilitado de propósito — gerações longas (ver acima)
		IdleTimeout:       120 * time.Second,
		MaxHeaderBytes:    1 << 20, // 1MB de cabeçalhos
	}
	log.Printf("reachyn-engine ouvindo em %s", addr)
	if err := srv.ListenAndServe(); err != nil {
		log.Fatal(err)
	}
}
