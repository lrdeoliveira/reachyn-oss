// Package scraper — provedor de scraping de perfis sociais: conteúdo real de perfis
// (username/channel) — útil para análise de perfil e curadoria. Busca por TEMA nas redes usa
// filtro site: no provider/search. White-label: a base da API vem de env/config (SCRAPER_BASE).
package scraper

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"os"
	"strings"
	"time"
)

// baseURL — base da API de scraping (env SCRAPER_BASE; sem default revelador).
func baseURL() string { return os.Getenv("SCRAPER_BASE") }

type Item struct {
	URL    string `json:"url"`
	Text   string `json:"text"`
	Source string `json:"source"`
}

type Client struct {
	key  string
	base string
	http *http.Client
}

func New(key, base string) *Client {
	return &Client{key: key, base: base, http: &http.Client{Timeout: 30 * time.Second}}
}

func (c *Client) Enabled() bool { return c.key != "" }

// Ping — valida a chave do scraper com uma chamada leve (sem custo de scrape pesado).
// 200/2xx = ok; 401/403 = chave inválida; demais = erro de provedor. Distingue auth de falha.
// A base vem de env (SCRAPER_BASE) — sem URL hardcoded (white-label).
func Ping(ctx context.Context, key string) error {
	key = strings.TrimSpace(key)
	if key == "" {
		return fmt.Errorf("chave do scraper vazia")
	}
	base := baseURL()
	if base == "" {
		return fmt.Errorf("scraper: base não configurada")
	}
	ctx, cancel := context.WithTimeout(ctx, 20*time.Second)
	defer cancel()
	// Endpoint leve de busca por palavra-chave com count mínimo só pra exercitar a auth.
	q := url.Values{"keyword": {"ping"}, "count": {"1"}}
	req, _ := http.NewRequestWithContext(ctx, http.MethodGet, base+"/v1/tiktok/search/keyword?"+q.Encode(), nil)
	req.Header.Set("x-api-key", key)
	req.Header.Set("Accept", "application/json")
	cl := &http.Client{Timeout: 18 * time.Second}
	resp, err := cl.Do(req)
	if err != nil {
		return fmt.Errorf("scraper indisponível: %w", err)
	}
	defer resp.Body.Close()
	io.Copy(io.Discard, io.LimitReader(resp.Body, 4096))
	switch {
	case resp.StatusCode >= 200 && resp.StatusCode < 300:
		return nil
	case resp.StatusCode == http.StatusUnauthorized || resp.StatusCode == http.StatusForbidden:
		return fmt.Errorf("chave do scraper inválida (HTTP %d)", resp.StatusCode)
	default:
		return fmt.Errorf("scraper respondeu HTTP %d", resp.StatusCode)
	}
}

// get faz uma chamada autenticada e devolve o corpo JSON cru (parse fica a cargo do caller).
func (c *Client) get(ctx context.Context, path string, q url.Values) (map[string]any, error) {
	req, _ := http.NewRequestWithContext(ctx, http.MethodGet, c.base+path+"?"+q.Encode(), nil)
	req.Header.Set("x-api-key", c.key)
	req.Header.Set("Accept", "application/json")
	resp, err := c.http.Do(req)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()
	var out map[string]any
	if err := json.NewDecoder(resp.Body).Decode(&out); err != nil {
		return nil, err
	}
	return out, nil
}

// TikTokKeyword — única busca por TEMA nativa do provedor de scraping (vídeos por palavra-chave).
func (c *Client) TikTokKeyword(ctx context.Context, keyword string, count int) (map[string]any, error) {
	q := url.Values{"keyword": {keyword}, "count": {itoa(count)}}
	return c.get(ctx, "/v1/tiktok/search/keyword", q)
}

// InstagramPosts — posts de um perfil (username).
func (c *Client) InstagramPosts(ctx context.Context, username string, count int) (map[string]any, error) {
	return c.get(ctx, "/v2/instagram/user/posts", url.Values{"username": {username}, "count": {itoa(count)}})
}

// TwitterTweets — tweets de um perfil (username).
func (c *Client) TwitterTweets(ctx context.Context, username string, count int) (map[string]any, error) {
	return c.get(ctx, "/v1/twitter/user-tweets", url.Values{"username": {username}, "count": {itoa(count)}})
}

// YouTubeChannelVideos — vídeos de um canal (channel_id).
func (c *Client) YouTubeChannelVideos(ctx context.Context, channelID string, count int) (map[string]any, error) {
	return c.get(ctx, "/v1/youtube/channel-videos", url.Values{"channel_id": {channelID}, "count": {itoa(count)}})
}

func itoa(n int) string {
	if n <= 0 {
		n = 10
	}
	const d = "0123456789"
	if n < 10 {
		return string(d[n])
	}
	b := []byte{}
	for n > 0 {
		b = append([]byte{d[n%10]}, b...)
		n /= 10
	}
	return string(b)
}
