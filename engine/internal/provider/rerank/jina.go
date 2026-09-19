// Package rerank — Jina reranker v2 multilingual (mesma engine do NEXUS).
// Reordena candidatos por relevância à query; devolve os índices ordenados (top-N).
package rerank

import (
	"bytes"
	"context"
	"encoding/json"
	"net/http"
	"time"
)

const ua = "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130 Safari/537.36"

type Client struct {
	key  string
	http *http.Client
}

func New(key string) *Client {
	return &Client{key: key, http: &http.Client{Timeout: 20 * time.Second}}
}

// Rerank devolve os índices de `docs` ordenados por relevância (até topN).
// Fallback gracioso (ordem original) em qualquer erro — nunca derruba a geração.
func (c *Client) Rerank(ctx context.Context, query string, docs []string, topN int) []int {
	fallback := func() []int {
		n := topN
		if n > len(docs) {
			n = len(docs)
		}
		out := make([]int, n)
		for i := range out {
			out[i] = i
		}
		return out
	}
	if len(docs) <= 1 || c.key == "" {
		return fallback()
	}
	body, _ := json.Marshal(map[string]any{
		"model":     "jina-reranker-v2-base-multilingual",
		"query":     query,
		"documents": docs,
		"top_n":     topN,
	})
	req, _ := http.NewRequestWithContext(ctx, http.MethodPost, "https://api.jina.ai/v1/rerank", bytes.NewReader(body))
	req.Header.Set("Authorization", "Bearer "+c.key)
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("User-Agent", ua) // Cloudflare barra UA não-browser (erro 1010)
	resp, err := c.http.Do(req)
	if err != nil {
		return fallback()
	}
	defer resp.Body.Close()
	var d struct {
		Results []struct {
			Index int `json:"index"`
		} `json:"results"`
	}
	if err := json.NewDecoder(resp.Body).Decode(&d); err != nil || len(d.Results) == 0 {
		return fallback()
	}
	out := make([]int, 0, len(d.Results))
	for _, r := range d.Results {
		if r.Index >= 0 && r.Index < len(docs) {
			out = append(out, r.Index)
		}
	}
	if len(out) == 0 {
		return fallback()
	}
	return out
}

// Score — embasamento (0..1): média dos relevance_scores PONDERADA pela própria
// relevância de cada trecho (trechos mais relevantes pesam mais). Mede o quanto o
// texto, como um todo, está coberto pelas fontes — mais rígido que só o máximo.
// 0 = sem sinal.
func (c *Client) Score(ctx context.Context, query string, docs []string) float64 {
	if len(docs) == 0 || c.key == "" {
		return 0
	}
	body, _ := json.Marshal(map[string]any{
		"model":     "jina-reranker-v2-base-multilingual",
		"query":     query,
		"documents": docs,
		"top_n":     len(docs),
	})
	req, _ := http.NewRequestWithContext(ctx, http.MethodPost, "https://api.jina.ai/v1/rerank", bytes.NewReader(body))
	req.Header.Set("Authorization", "Bearer "+c.key)
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("User-Agent", ua)
	resp, err := c.http.Do(req)
	if err != nil {
		return 0
	}
	defer resp.Body.Close()
	var d struct {
		Results []struct {
			Score float64 `json:"relevance_score"`
		} `json:"results"`
	}
	if err := json.NewDecoder(resp.Body).Decode(&d); err != nil {
		return 0
	}
	// média ponderada: peso de cada trecho = sua própria relevância → Σ(s²)/Σ(s).
	// Fica entre a média simples e o máximo: não pune trechos irrelevantes, mas
	// exige que os trechos relevantes cubram bem o texto.
	var sum, wsum float64
	for _, r := range d.Results {
		sum += r.Score * r.Score
		wsum += r.Score
	}
	if wsum == 0 {
		return 0
	}
	return sum / wsum
}

// BestIndex — índice do doc mais relevante à query em UMA chamada (Jina já devolve
// os resultados ordenados por relevância desc, então results[0].index é o melhor).
// Devolve -1 quando não há sinal (sem docs, sem chave ou erro). Usado para escolher,
// entre vários prompts candidatos, o que melhor representa o resumo da pesquisa —
// ancorando a geração de mídia no conteúdo real em vez do 1º palpite do modelo.
func (c *Client) BestIndex(ctx context.Context, query string, docs []string) int {
	if len(docs) == 0 || c.key == "" {
		return -1
	}
	body, _ := json.Marshal(map[string]any{
		"model":     "jina-reranker-v2-base-multilingual",
		"query":     query,
		"documents": docs,
		"top_n":     len(docs),
	})
	req, _ := http.NewRequestWithContext(ctx, http.MethodPost, "https://api.jina.ai/v1/rerank", bytes.NewReader(body))
	req.Header.Set("Authorization", "Bearer "+c.key)
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("User-Agent", ua)
	resp, err := c.http.Do(req)
	if err != nil {
		return -1
	}
	defer resp.Body.Close()
	var d struct {
		Results []struct {
			Index int `json:"index"`
		} `json:"results"`
	}
	if err := json.NewDecoder(resp.Body).Decode(&d); err != nil || len(d.Results) == 0 {
		return -1
	}
	return d.Results[0].Index
}
