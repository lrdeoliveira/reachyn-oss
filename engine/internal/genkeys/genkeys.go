// Package genkeys — overrides em runtime das chaves dos provedores de GERAÇÃO.
// As chaves vivem no console (Postgres cifrado); o engine recebe via PUT /v1/admin/gen-keys
// e busca no boot (SyncFromConsole). Campo vazio = mantém o default do .env.
// Provedores de PESQUISA (tavily/brave/jina/scrapecreators) NÃO entram aqui — são BYOK por tenant.
package genkeys

// Set — chaves dos provedores de geração geridas pelo operador (admin).
type Set struct {
	Minimax    string `json:"minimax"`    // LLM (texto) + imagem (image-01)
	Ollama     string `json:"ollama"`     // LLM fallback
	Google     string `json:"google"`     // vídeo premium (Veo)
	Elevenlabs string `json:"elevenlabs"` // voz: transcrição, dublagem, narração
	Magnific   string `json:"magnific"`   // Magnific (API HTTP): imagem, upscale/edição, clipe e fala sincronizada

	// Overrides de base_url + model do LLM de TEXTO (estilo Nexusyn). Vazio = default do llm.
	// Retrocompat: Set antigo (sem estes campos) decodifica como "" → comportamento idêntico.
	MinimaxBaseURL string `json:"minimax_base_url"` // base do MiniMax, ex.: https://api.minimax.io (o llm concatena /v1/text/chatcompletion_v2)
	MinimaxModel   string `json:"minimax_model"`    // model do MiniMax, ex.: MiniMax-M2.7
	OllamaBaseURL  string `json:"ollama_base_url"`  // base do Ollama, ex.: https://ollama.com (o llm concatena /api/chat)
	OllamaModel    string `json:"ollama_model"`     // model do Ollama, ex.: gemini-3-flash-preview

	Spriterrific string `json:"spriterrific"` // sprites de jogo (aba Sprites): personagem → spritesheet
}

// Or devolve o override se não-vazio, senão o default (env).
func Or(override, def string) string {
	if override != "" {
		return override
	}
	return def
}
