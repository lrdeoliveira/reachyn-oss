// reachyn engine — geração de conteúdo + jobs de mídia + providers.
// F2: research/texto/imagem via providers (llm/rerank/image). F3+: jobs longos.
package main

import (
	"log"
	"net/http"
	"time"

	"github.com/lrdeoliveira/reachyn-oss/engine/internal/api"
	"github.com/lrdeoliveira/reachyn-oss/engine/internal/config"
	"github.com/lrdeoliveira/reachyn-oss/engine/internal/content"
	"github.com/lrdeoliveira/reachyn-oss/engine/internal/genkeys"
	"github.com/lrdeoliveira/reachyn-oss/engine/internal/media"
	"github.com/lrdeoliveira/reachyn-oss/engine/internal/provider/image"
	"github.com/lrdeoliveira/reachyn-oss/engine/internal/provider/llm"
	"github.com/lrdeoliveira/reachyn-oss/engine/internal/provider/rerank"
	"github.com/lrdeoliveira/reachyn-oss/engine/internal/provider/scraper"
	"github.com/lrdeoliveira/reachyn-oss/engine/internal/provider/search"
	"github.com/lrdeoliveira/reachyn-oss/engine/internal/provider/speech"
	"github.com/lrdeoliveira/reachyn-oss/engine/internal/provider/video"
)

func main() {
	cfg := config.Load()

	// build reconstrói o serviço com as chaves de geração geridas no console sobrepostas
	// ao .env. As de pesquisa (busca/leitor/scraper) seguem do .env (BYOK por tenant
	// vai por request). Providers são clients HTTP baratos — reconstruir é instantâneo.
	build := func(k genkeys.Set) *content.Service {
		return content.New(
			search.New(cfg.SearchPrimary, cfg.SearchAlt, cfg.Rerank),
			scraper.New(cfg.Scraper, cfg.ScraperBase),
			llm.New(genkeys.Or(k.TextAlt, cfg.TextAlt), genkeys.Or(k.Text, cfg.Text), llm.LLMConfig{
				// base_url + model do LLM de texto vêm das chaves geridas no console; vazio → env (cfg).
				TextBaseURL:    genkeys.Or(k.TextBaseURL, cfg.TextBaseURL),
				TextModel:      genkeys.Or(k.TextModel, cfg.TextModel),
				TextAltBaseURL: genkeys.Or(k.TextAltBaseURL, cfg.TextAltBaseURL),
				TextAltModel:   genkeys.Or(k.TextAltModel, cfg.TextAltModel),
			}),
			rerank.New(cfg.Rerank, cfg.RerankBaseURL, cfg.RerankModel),
			image.New(genkeys.Or(k.Media, cfg.Media), genkeys.Or(k.Text, cfg.Text), image.ImageConfig{
				GenURL:       cfg.ImageGenURL,
				EditURL:      cfg.ImageEditURL,
				PrimaryBase:  cfg.ImageBaseURL,
				PrimaryModel: cfg.ImageModel,
			}),
			video.New(genkeys.Or(k.Media, cfg.Media), genkeys.Or(k.PremiumVideo, cfg.PremiumVideo), video.VideoConfig{
				AT2V: cfg.VideoAT2V, AI2V: cfg.VideoAI2V,
				BT2V: cfg.VideoBT2V, BI2V: cfg.VideoBI2V,
				CT2V: cfg.VideoCT2V, CI2V: cfg.VideoCI2V,
				PremiumBaseURL:    cfg.PremiumVideoBaseURL,
				PremiumModel:      cfg.PremiumVideoModel,
				PremiumAuthHeader: cfg.PremiumVideoAuthHeader,
			}),
			speech.New(genkeys.Or(k.Speech, cfg.Speech), speech.Config{
				BaseURL:      cfg.SpeechBaseURL,
				Model:        cfg.SpeechModel,
				APIKeyHeader: cfg.SpeechAPIKeyHeader,
			}),
			media.New(cfg.FFmpegURL, cfg.FFmpegToken),
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
