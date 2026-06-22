// Package genkeys — overrides em runtime das chaves dos provedores de GERAÇÃO.
// As chaves vivem no console (Postgres cifrado); o engine recebe via PUT /v1/admin/gen-keys
// e busca no boot (SyncFromConsole). Campo vazio = mantém o default do .env.
// Provedores de PESQUISA NÃO entram aqui — são BYOK por tenant.
//
// White-label: os nomes Go e as tags JSON são genéricos/opacos — nenhum nome de provedor
// no código nem no contrato de fio. O console envia exclusivamente as tags opacas.
package genkeys

import "encoding/json"

// Set — chaves dos provedores de geração geridas pelo operador (admin).
type Set struct {
	Text         string // LLM (texto) + imagem (provedor primário)
	TextAlt      string // LLM fallback (texto alternativo)
	Media        string // mídia: vídeo (fila) + edição de imagem (i2i)
	PremiumVideo string // vídeo premium
	Speech       string // voz: transcrição, dublagem, narração

	// Overrides de base_url + model do LLM de TEXTO. Vazio = default do env/llm.
	TextBaseURL    string // base do provedor de texto primário (o llm concatena o path)
	TextModel      string // modelo do provedor de texto primário
	TextAltBaseURL string // base do provedor de texto alternativo
	TextAltModel   string // modelo do provedor de texto alternativo
}

// wire — representação de fio (contrato com o console). Só tags opacas.
type wire struct {
	Text         *string `json:"text"`
	TextAlt      *string `json:"text_alt"`
	Media        *string `json:"media"`
	PremiumVideo *string `json:"premium"`
	Speech       *string `json:"voice"`

	TextBaseURL    *string `json:"text_base_url"`
	TextModel      *string `json:"text_model"`
	TextAltBaseURL *string `json:"text_alt_base_url"`
	TextAltModel   *string `json:"text_alt_model"`
}

// UnmarshalJSON — decodifica as tags opacas do contrato de fio com o console.
func (s *Set) UnmarshalJSON(b []byte) error {
	var w wire
	if err := json.Unmarshal(b, &w); err != nil {
		return err
	}
	pick := func(p *string) string {
		if p != nil {
			return *p
		}
		return ""
	}
	s.Text = pick(w.Text)
	s.TextAlt = pick(w.TextAlt)
	s.Media = pick(w.Media)
	s.PremiumVideo = pick(w.PremiumVideo)
	s.Speech = pick(w.Speech)
	s.TextBaseURL = pick(w.TextBaseURL)
	s.TextModel = pick(w.TextModel)
	s.TextAltBaseURL = pick(w.TextAltBaseURL)
	s.TextAltModel = pick(w.TextAltModel)
	return nil
}

// Or devolve o override se não-vazio, senão o default (env).
func Or(override, def string) string {
	if override != "" {
		return override
	}
	return def
}
