// Package config carrega a configuração do engine a partir do ambiente.
// Todos os segredos vêm do .env do mono (fonte única) — nada hardcoded.
package config

import "os"

type Config struct {
	Port              string
	Tavily            string
	Brave             string
	ScrapeCreators    string
	Jina              string
	Ollama            string
	Minimax           string
	Elevenlabs        string
	Google            string // chave do provedor de vídeo premium (Veo) — env GOOGLE_API_KEY / GEMINI_API_KEY
	Magnific          string // chave do Magnific (imagem/upscale/vídeo) — env MAGNIFIC_API_KEY
	RunningHub        string // chave da RunningHub (motion transfer via workflow ComfyUI curado) — env RUNNINGHUB_API_KEY
	FFmpegURL         string // stack de mídia, por NOME de serviço (nunca IP fixo)
	FFmpegToken       string // X-Service-Token p/ o ffmpeg-service (AUD-008)
	CliBridgeURL      string // reachyn-cli-bridge no HOST da VPS (host.docker.internal) — vazio = desligado
	CliBridgeToken    string // X-Bridge-Token do cli-bridge
	CliBridgeMacURL   string // 2º sidecar, no Mac (adapter prefixado "mac:") — vazio = desligado
	CliBridgeMacToken string // X-Bridge-Token do sidecar do Mac
	CliTextCLI        string // adapter da linha de TEXTO no bridge — env CLI_TEXT_CLI (default codex)
	CliTextFastCLI    string // adapter da linha RÁPIDA de texto — env CLI_TEXT_CLI_FAST (default mmx)
	ComfyURL          string // ComfyUI local no HOST (host.docker.internal:8188) — vazio = desligado (topologia VPS sem GPU)
	AdminToken        string // protege /v1/admin/* (compartilhado com o console)
	ConsoleURL        string // console interno (boot-fetch das chaves de geração)
}

func Load() Config {
	return Config{
		Port:              envOr("PORT", "8080"),
		Tavily:            os.Getenv("TAVILY_API_KEY"),
		Brave:             os.Getenv("BRAVE_API_KEY"),
		ScrapeCreators:    os.Getenv("SCRAPECREATORS_API_KEY"),
		Jina:              os.Getenv("JINA_API_KEY"),
		Ollama:            os.Getenv("OLLAMA_API_KEY"),
		Minimax:           os.Getenv("MINIMAX_API_KEY"),
		Elevenlabs:        os.Getenv("ELEVENLABS_API_KEY"),
		Google:            envOr("GOOGLE_API_KEY", os.Getenv("GEMINI_API_KEY")), // vídeo premium (Veo); fallback p/ GEMINI_API_KEY
		Magnific:          os.Getenv("MAGNIFIC_API_KEY"),
		RunningHub:        os.Getenv("RUNNINGHUB_API_KEY"),
		FFmpegURL:         envOr("MEDIA_FFMPEG_URL", "http://ffmpeg-service:7788"),
		FFmpegToken:       os.Getenv("FFMPEG_SERVICE_TOKEN"),
		CliBridgeURL:      os.Getenv("CLI_BRIDGE_URL"),
		CliBridgeToken:    os.Getenv("CLI_BRIDGE_TOKEN"),
		CliBridgeMacURL:   os.Getenv("CLI_BRIDGE_MAC_URL"),
		CliBridgeMacToken: os.Getenv("CLI_BRIDGE_MAC_TOKEN"),
		CliTextCLI:        envOr("CLI_TEXT_CLI", "codex"),
		CliTextFastCLI:    envOr("CLI_TEXT_CLI_FAST", "mmx"),
		ComfyURL:          os.Getenv("COMFY_URL"),
		AdminToken:        os.Getenv("ENGINE_ADMIN_TOKEN"),
		ConsoleURL:        envOr("CONSOLE_INTERNAL_URL", "http://console:8000"),
	}
}

func envOr(k, def string) string {
	if v := os.Getenv(k); v != "" {
		return v
	}
	return def
}
