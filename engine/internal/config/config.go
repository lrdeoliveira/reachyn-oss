// Package config carrega a configuração do engine a partir do ambiente.
// Todos os segredos vêm do .env do mono (fonte única) — nada hardcoded.
//
// WHITE-LABEL: nenhuma URL real nem nome de provedor/modelo de IA vive no código.
// As bases de API, os modelos e os nomes de header de auth chegam por env (placeholders
// genéricos quando ausentes). Os valores reais ficam SÓ no .env (fora do repo).
package config

import "os"

type Config struct {
	Port           string
	SearchPrimary  string // chave do provedor de busca primário
	SearchAlt      string // chave do provedor de busca alternativo
	Scraper        string // chave do scraper de perfis sociais
	Rerank         string // chave do leitor/rerank (URL → markdown + rerank)
	TextAlt        string // chave do LLM de texto alternativo (fallback)
	Text           string // chave do LLM de texto primário + imagem primária
	Speech         string // chave do provedor de voz
	Media          string // chave do provedor de mídia (vídeo fila + edição de imagem)
	PremiumVideo   string // chave do provedor de vídeo premium
	FFmpegURL      string // stack de mídia, por NOME de serviço (nunca IP fixo)
	FFmpegToken    string // X-Service-Token p/ o ffmpeg-service (AUD-008)
	AdminToken     string // protege /v1/admin/* (compartilhado com o console)
	ConsoleURL     string // console interno (boot-fetch das chaves de geração)

	// ── Bases de API + modelos + headers (white-label: vêm do .env, sem default revelador) ──

	// LLM de texto (primário + alternativo). Base SEM path; o llm concatena o path.
	TextBaseURL    string // base do provedor de texto primário
	TextModel      string // modelo do provedor de texto primário
	TextAltBaseURL string // base do provedor de texto alternativo (fallback)
	TextAltModel   string // modelo do provedor de texto alternativo

	// Imagem
	ImageBaseURL string // base do provedor de imagem primário (text-to-image)
	ImageModel   string // modelo de imagem primário
	ImageGenURL  string // endpoint completo de geração de imagem (text-to-image, fallback)
	ImageEditURL string // endpoint completo de edição de imagem (image-to-image)

	// Vídeo (fila) — endpoints completos por slot opaco (video-a/b/c), t2v + i2v.
	VideoAT2V string
	VideoAI2V string
	VideoBT2V string
	VideoBI2V string
	VideoCT2V string
	VideoCI2V string

	// Vídeo premium (operação longa)
	PremiumVideoBaseURL    string // base da API de vídeo premium
	PremiumVideoModel      string // modelo de vídeo premium
	PremiumVideoAuthHeader string // nome do header de auth do vídeo premium

	// Voz (transcrição / vozes)
	SpeechBaseURL      string // base da API de voz
	SpeechModel        string // modelo de transcrição
	SpeechAPIKeyHeader string // nome do header de auth da voz

	// Rerank
	RerankBaseURL string // base da API de rerank
	RerankModel   string // modelo de rerank

	// Busca / leitura / deepsearch
	SearchPrimaryBase   string // base do provedor de busca primário
	SearchAltBase       string // base do provedor de busca alternativo
	SearchAltAuthHeader string // nome do header de auth do provedor de busca alternativo
	ReaderBase          string // base do leitor de páginas (URL → markdown)
	SearchReadBase      string // base de busca do leitor (busca → resultados)
	DeepSearchBase      string // base do provedor de deepsearch
	DeepSearchModel     string // modelo de deepsearch

	// Scraper de perfis sociais
	ScraperBase string // base da API de scraping de perfis
}

func Load() Config {
	return Config{
		Port:           envOr("PORT", "8080"),
		SearchPrimary:  os.Getenv("SEARCH_PRIMARY_API_KEY"),
		SearchAlt:      os.Getenv("SEARCH_ALT_API_KEY"),
		Scraper:        os.Getenv("SCRAPER_API_KEY"),
		Rerank:         os.Getenv("RERANK_API_KEY"),
		TextAlt:        os.Getenv("TEXT_ALT_API_KEY"),
		Text:           os.Getenv("TEXT_API_KEY"),
		Speech:         os.Getenv("SPEECH_API_KEY"),
		Media:          os.Getenv("MEDIA_API_KEY"),
		PremiumVideo:   os.Getenv("PREMIUM_VIDEO_API_KEY"),
		FFmpegURL:      envOr("MEDIA_FFMPEG_URL", "http://ffmpeg-service:7788"),
		FFmpegToken:    os.Getenv("FFMPEG_SERVICE_TOKEN"),
		AdminToken:     os.Getenv("ENGINE_ADMIN_TOKEN"),
		ConsoleURL:     envOr("CONSOLE_INTERNAL_URL", "http://console:8000"),

		TextBaseURL:    os.Getenv("TEXT_BASE_URL"),
		TextModel:      os.Getenv("TEXT_MODEL"),
		TextAltBaseURL: os.Getenv("TEXT_ALT_BASE_URL"),
		TextAltModel:   os.Getenv("TEXT_ALT_MODEL"),

		ImageBaseURL: os.Getenv("IMAGE_BASE_URL"),
		ImageModel:   os.Getenv("IMAGE_MODEL"),
		ImageGenURL:  os.Getenv("IMAGE_GEN_URL"),
		ImageEditURL: os.Getenv("IMAGE_EDIT_URL"),

		VideoAT2V: os.Getenv("VIDEO_A_T2V"),
		VideoAI2V: os.Getenv("VIDEO_A_I2V"),
		VideoBT2V: os.Getenv("VIDEO_B_T2V"),
		VideoBI2V: os.Getenv("VIDEO_B_I2V"),
		VideoCT2V: os.Getenv("VIDEO_C_T2V"),
		VideoCI2V: os.Getenv("VIDEO_C_I2V"),

		PremiumVideoBaseURL:    os.Getenv("PREMIUM_VIDEO_BASE_URL"),
		PremiumVideoModel:      os.Getenv("PREMIUM_VIDEO_MODEL"),
		PremiumVideoAuthHeader: os.Getenv("PREMIUM_VIDEO_AUTH_HEADER"),

		SpeechBaseURL:      os.Getenv("SPEECH_BASE_URL"),
		SpeechModel:        os.Getenv("SPEECH_MODEL"),
		SpeechAPIKeyHeader: os.Getenv("SPEECH_API_KEY_HEADER"),

		RerankBaseURL: os.Getenv("RERANK_BASE_URL"),
		RerankModel:   os.Getenv("RERANK_MODEL"),

		SearchPrimaryBase:   os.Getenv("SEARCH_PRIMARY_BASE"),
		SearchAltBase:       os.Getenv("SEARCH_ALT_BASE"),
		SearchAltAuthHeader: os.Getenv("SEARCH_ALT_AUTH_HEADER"),
		ReaderBase:          os.Getenv("READER_BASE"),
		SearchReadBase:      os.Getenv("SEARCH_READ_BASE"),
		DeepSearchBase:      os.Getenv("DEEPSEARCH_BASE"),
		DeepSearchModel:     os.Getenv("DEEPSEARCH_MODEL"),

		ScraperBase: os.Getenv("SCRAPER_BASE"),
	}
}

func envOr(k, def string) string {
	if v := os.Getenv(k); v != "" {
		return v
	}
	return def
}
