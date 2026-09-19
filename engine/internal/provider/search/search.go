// Package search — busca web multi-provedor (Tavily + Brave + Jina) e busca por rede (site:).
// Usado pelo Research: a fonte "web" agrega os três; fontes sociais usam filtro site: via Brave/Jina.
package search

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strings"
	"sync"
	"time"
)

// errUnknownProvider — erro padronizado de provedor inválido/sem chave.
func errUnknownProvider(p string) error { return fmt.Errorf("provedor de pesquisa inválido: %s", p) }

type Result struct {
	Title   string `json:"title"`
	URL     string `json:"url"`
	Content string `json:"content"`
	Source  string `json:"source"` // web | instagram | twitter | youtube
}

type Client struct {
	tavily, brave, jina string
	http                *http.Client
}

func New(tavily, brave, jina string) *Client {
	return &Client{tavily: tavily, brave: brave, jina: jina, http: &http.Client{Timeout: 20 * time.Second}}
}

// With devolve um Client com as chaves do tenant sobrepostas (BYOK).
// Chave vazia mantém a global do sistema (.env) — fallback gracioso.
func (c *Client) With(over map[string]string) *Client {
	pick := func(k, def string) string {
		if v, ok := over[k]; ok && strings.TrimSpace(v) != "" {
			return v
		}
		return def
	}
	return &Client{
		tavily: pick("tavily", c.tavily),
		brave:  pick("brave", c.brave),
		jina:   pick("jina", c.jina),
		http:   c.http,
	}
}

func clip(s string, n int) string {
	s = strings.TrimSpace(s)
	if len(s) > n {
		return s[:n]
	}
	return s
}

// Read — Jina Reader (r.jina.ai): devolve o CORPO da página em markdown limpo.
// Usado para aprofundar as melhores fontes (snippets de busca são rasos). "" em erro.
func (c *Client) Read(ctx context.Context, pageURL string) string {
	if c.jina == "" || pageURL == "" {
		return ""
	}
	req, _ := http.NewRequestWithContext(ctx, http.MethodGet, "https://r.jina.ai/"+pageURL, nil)
	req.Header.Set("Authorization", "Bearer "+c.jina)
	req.Header.Set("X-Return-Format", "markdown")
	req.Header.Set("X-Timeout", "20") // o Reader aborta a página em 20s
	cl := &http.Client{Timeout: 28 * time.Second}
	resp, err := cl.Do(req)
	if err != nil {
		return ""
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return ""
	}
	b, _ := io.ReadAll(io.LimitReader(resp.Body, 60000))
	return strings.TrimSpace(string(b))
}

// hasKey — o provedor tem chave configurada (tenant ou global)?
func (c *Client) hasKey(provider string) bool {
	switch provider {
	case "tavily":
		return c.tavily != ""
	case "brave":
		return c.brave != ""
	case "jina":
		return c.jina != ""
	}
	return false
}

// searchByProvider — executa a busca NORMAL em UM provedor (sem dedup/source).
// "" (nenhum resultado) se o provedor for desconhecido ou estiver sem chave.
func (c *Client) searchByProvider(ctx context.Context, provider, query string) []Result {
	switch provider {
	case "tavily":
		if c.tavily == "" {
			return nil
		}
		return c.tavilySearch(ctx, query)
	case "brave":
		if c.brave == "" {
			return nil
		}
		return c.braveSearch(ctx, query)
	case "jina":
		if c.jina == "" {
			return nil
		}
		return c.jinaSearch(ctx, query)
	}
	return nil
}

// SearchLine — busca NORMAL respeitando a "line" (principal/reserva): tenta o `primary`;
// se vier vazio (sem resultado) ou o provedor não tiver chave, cai pro `fallback`.
// `fallback` vazio = sem reserva. Deduplica por URL e devolve até n. (Substitui o antigo
// "paralelo junta tudo" por principal→reserva, conforme pedido.)
func (c *Client) SearchLine(ctx context.Context, query, primary, fallback string, n int) []Result {
	if primary == "" {
		primary = "tavily" // default histórico
	}
	rs := c.searchByProvider(ctx, primary, query)
	if len(rs) == 0 && fallback != "" && fallback != primary {
		rs = c.searchByProvider(ctx, fallback, query)
	}
	return dedup(rs, "web", n)
}

// Web — agrega Tavily + Brave + Jina em paralelo, deduplica por URL e devolve até n.
func (c *Client) Web(ctx context.Context, query string, n int) []Result {
	var wg sync.WaitGroup
	var mu sync.Mutex
	all := []Result{}
	add := func(rs []Result) { mu.Lock(); all = append(all, rs...); mu.Unlock() }

	if c.tavily != "" {
		wg.Add(1)
		go func() { defer wg.Done(); add(c.tavilySearch(ctx, query)) }()
	}
	if c.brave != "" {
		wg.Add(1)
		go func() { defer wg.Done(); add(c.braveSearch(ctx, query)) }()
	}
	if c.jina != "" {
		wg.Add(1)
		go func() { defer wg.Done(); add(c.jinaSearch(ctx, query)) }()
	}
	wg.Wait()

	return dedup(all, "web", n)
}

// Site — busca o tema restrita a uma rede (site:dominio) via Brave, com fallback no Jina.
func (c *Client) Site(ctx context.Context, query, domain, source string, n int) []Result {
	q := "site:" + domain + " " + query
	var rs []Result
	if c.brave != "" {
		rs = c.braveSearch(ctx, q)
	}
	if len(rs) == 0 && c.jina != "" {
		rs = c.jinaSearch(ctx, q)
	}
	return dedup(rs, source, n)
}

func dedup(rs []Result, source string, n int) []Result {
	seen := map[string]bool{}
	out := make([]Result, 0, len(rs))
	for _, r := range rs {
		key := strings.TrimRight(strings.SplitN(r.URL, "?", 2)[0], "/")
		if key == "" || seen[key] {
			continue
		}
		seen[key] = true
		r.Source = source
		out = append(out, r)
		if len(out) >= n {
			break
		}
	}
	return out
}

func (c *Client) tavilySearch(ctx context.Context, q string) []Result {
	body, _ := json.Marshal(map[string]any{"query": q, "max_results": 10, "search_depth": "advanced", "include_answer": false})
	req, _ := http.NewRequestWithContext(ctx, http.MethodPost, "https://api.tavily.com/search", strings.NewReader(string(body)))
	req.Header.Set("Authorization", "Bearer "+c.tavily)
	req.Header.Set("Content-Type", "application/json")
	resp, err := c.http.Do(req)
	if err != nil {
		return nil
	}
	defer resp.Body.Close()
	var tv struct {
		Results []struct {
			Title, URL, Content string
		} `json:"results"`
	}
	if json.NewDecoder(resp.Body).Decode(&tv) != nil {
		return nil
	}
	out := make([]Result, 0, len(tv.Results))
	for _, r := range tv.Results {
		out = append(out, Result{Title: r.Title, URL: r.URL, Content: clip(r.Content, 1200)})
	}
	return out
}

func (c *Client) braveSearch(ctx context.Context, q string) []Result {
	u := "https://api.search.brave.com/res/v1/web/search?count=10&q=" + url.QueryEscape(q)
	req, _ := http.NewRequestWithContext(ctx, http.MethodGet, u, nil)
	req.Header.Set("X-Subscription-Token", c.brave)
	req.Header.Set("Accept", "application/json")
	resp, err := c.http.Do(req)
	if err != nil {
		return nil
	}
	defer resp.Body.Close()
	var br struct {
		Web struct {
			Results []struct {
				Title, URL, Description string
			} `json:"results"`
		} `json:"web"`
	}
	if json.NewDecoder(resp.Body).Decode(&br) != nil {
		return nil
	}
	out := make([]Result, 0, len(br.Web.Results))
	for _, r := range br.Web.Results {
		out = append(out, Result{Title: r.Title, URL: r.URL, Content: clip(r.Description, 1200)})
	}
	return out
}

// Deep — resultado da pesquisa profunda (DeepSearch): resposta sintetizada + fontes citadas.
type Deep struct {
	Summary string   `json:"summary"`
	Results []Result `json:"results"`
}

// DeepSearch — Jina DeepSearch (agêntico: busca + lê + raciocina + itera). Lento (~min).
func (c *Client) DeepSearch(ctx context.Context, query string) (Deep, error) {
	body, _ := json.Marshal(map[string]any{
		"model": "jina-deepsearch-v1",
		"messages": []map[string]string{
			{"role": "system", "content": "Responda SEMPRE em português do Brasil (PT-BR): use 'você', ortografia e vocabulário brasileiros; evite construções de português europeu ('a fazer', 'de facto', 'utilizadores'). Pode pesquisar fontes em qualquer idioma, mas o texto final deve ser PT-BR."},
			{"role": "user", "content": query},
		},
		"stream":           false,
		"reasoning_effort": "low",
	})
	req, _ := http.NewRequestWithContext(ctx, http.MethodPost, "https://deepsearch.jina.ai/v1/chat/completions", strings.NewReader(string(body)))
	req.Header.Set("Authorization", "Bearer "+c.jina)
	req.Header.Set("Content-Type", "application/json")
	cl := &http.Client{Timeout: 240 * time.Second} // DeepSearch é lento
	resp, err := cl.Do(req)
	if err != nil {
		return Deep{}, err
	}
	defer resp.Body.Close()
	var dr struct {
		VisitedURLs []string `json:"visitedURLs"`
		ReadURLs    []string `json:"readURLs"`
		Choices     []struct {
			Message struct {
				Content     string `json:"content"`
				Annotations []struct {
					URLCitation struct {
						URL        string `json:"url"`
						Title      string `json:"title"`
						ExactQuote string `json:"exactQuote"`
					} `json:"url_citation"`
				} `json:"annotations"`
			} `json:"message"`
		} `json:"choices"`
	}
	if err := json.NewDecoder(resp.Body).Decode(&dr); err != nil {
		return Deep{}, err
	}

	out := Deep{}
	if len(dr.Choices) > 0 {
		out.Summary = dr.Choices[0].Message.Content
		for _, a := range dr.Choices[0].Message.Annotations {
			if a.URLCitation.URL != "" {
				out.Results = append(out.Results, Result{Title: a.URLCitation.Title, URL: a.URLCitation.URL, Content: clip(a.URLCitation.ExactQuote, 600), Source: "deepsearch"})
			}
		}
	}
	urls := dr.ReadURLs
	if len(urls) == 0 {
		urls = dr.VisitedURLs
	}
	for _, u := range urls {
		out.Results = append(out.Results, Result{Title: u, URL: u, Source: "deepsearch"})
	}
	out.Results = dedup(out.Results, "deepsearch", 12)
	return out, nil
}

// DeepSearchLine — pesquisa PROFUNDA respeitando a "line" deep (principal/reserva).
//   - provider "jina"   → Jina DeepSearch (jina-deepsearch-v1) — comportamento atual.
//   - provider "tavily" → API /research da Tavily (assíncrona com polling, ~5min).
//
// Tenta o `primary`; se falhar (erro ou resumo vazio) e houver `fallback` distinto, cai nele.
func (c *Client) DeepSearchLine(ctx context.Context, query, primary, fallback string) (Deep, error) {
	if primary == "" {
		primary = "jina" // default histórico
	}
	d, err := c.deepByProvider(ctx, primary, query)
	if (err != nil || strings.TrimSpace(d.Summary) == "") && fallback != "" && fallback != primary {
		if d2, err2 := c.deepByProvider(ctx, fallback, query); err2 == nil && strings.TrimSpace(d2.Summary) != "" {
			return d2, nil
		}
	}
	return d, err
}

// deepByProvider — roteia a pesquisa profunda pro provedor escolhido.
func (c *Client) deepByProvider(ctx context.Context, provider, query string) (Deep, error) {
	switch provider {
	case "tavily":
		return c.tavilyResearch(ctx, query)
	case "jina":
		return c.DeepSearch(ctx, query)
	}
	return Deep{}, errUnknownProvider(provider)
}

// tavilyResearch — pesquisa profunda via API /research da Tavily (assíncrona).
// POST /research {input} → {request_id, status:"pending"}; faz polling em
// GET /research/{id} até status=="completed". A resposta final traz `content` (resumo)
// e `sources` ([{url,title}]). Auth Bearer da chave Tavily. Orçamento total ~330s.
func (c *Client) tavilyResearch(ctx context.Context, query string) (Deep, error) {
	if c.tavily == "" {
		return Deep{}, errUnknownProvider("tavily (sem chave)")
	}
	ctx, cancel := context.WithTimeout(ctx, 330*time.Second)
	defer cancel()
	cl := &http.Client{Timeout: 60 * time.Second}

	// 1) dispara a pesquisa (assíncrona).
	body, _ := json.Marshal(map[string]any{"input": query})
	req, _ := http.NewRequestWithContext(ctx, http.MethodPost, "https://api.tavily.com/research", strings.NewReader(string(body)))
	req.Header.Set("Authorization", "Bearer "+c.tavily)
	req.Header.Set("Content-Type", "application/json")
	resp, err := cl.Do(req)
	if err != nil {
		return Deep{}, err
	}
	var start struct {
		RequestID string `json:"request_id"`
		Status    string `json:"status"`
	}
	dec := json.NewDecoder(resp.Body).Decode(&start)
	resp.Body.Close()
	if dec != nil {
		return Deep{}, dec
	}
	if start.RequestID == "" {
		return Deep{}, errUnknownProvider("tavily research: sem request_id")
	}

	// 2) faz polling até "completed" (ou estourar o orçamento de tempo).
	ticker := time.NewTicker(5 * time.Second)
	defer ticker.Stop()
	for {
		pr, perr := http.NewRequestWithContext(ctx, http.MethodGet, "https://api.tavily.com/research/"+start.RequestID, nil)
		if perr != nil {
			return Deep{}, perr
		}
		pr.Header.Set("Authorization", "Bearer "+c.tavily)
		pres, err := cl.Do(pr)
		if err != nil {
			return Deep{}, err
		}
		var poll struct {
			Status  string `json:"status"`
			Content string `json:"content"`
			Sources []struct {
				URL   string `json:"url"`
				Title string `json:"title"`
			} `json:"sources"`
		}
		perr = json.NewDecoder(pres.Body).Decode(&poll)
		pres.Body.Close()
		if perr != nil {
			return Deep{}, perr
		}
		if poll.Status == "completed" {
			out := Deep{Summary: poll.Content}
			for _, sc := range poll.Sources {
				if sc.URL != "" {
					out.Results = append(out.Results, Result{Title: sc.Title, URL: sc.URL, Source: "deepsearch"})
				}
			}
			out.Results = dedup(out.Results, "deepsearch", 12)
			return out, nil
		}
		if poll.Status == "failed" || poll.Status == "error" {
			return Deep{}, errUnknownProvider("tavily research: status " + poll.Status)
		}
		// pending/in_progress → aguarda o próximo tick ou o cancelamento do ctx.
		select {
		case <-ctx.Done():
			return Deep{}, ctx.Err()
		case <-ticker.C:
		}
	}
}

func (c *Client) jinaSearch(ctx context.Context, q string) []Result {
	req, _ := http.NewRequestWithContext(ctx, http.MethodGet, "https://s.jina.ai/?q="+url.QueryEscape(q), nil)
	req.Header.Set("Authorization", "Bearer "+c.jina)
	req.Header.Set("Accept", "application/json")
	req.Header.Set("X-Respond-With", "no-content") // só metadados (rápido)
	resp, err := c.http.Do(req)
	if err != nil {
		return nil
	}
	defer resp.Body.Close()
	var jr struct {
		Data []struct {
			Title, URL, Description, Content string
		} `json:"data"`
	}
	if json.NewDecoder(resp.Body).Decode(&jr) != nil {
		return nil
	}
	out := make([]Result, 0, len(jr.Data))
	for _, r := range jr.Data {
		body := r.Description
		if body == "" {
			body = r.Content
		}
		out = append(out, Result{Title: r.Title, URL: r.URL, Content: clip(body, 1200)})
	}
	return out
}

// Ping — valida a chave de um provedor de PESQUISA sem custo alto (1 chamada leve).
// Distingue chave inválida (401/403) de chave válida (200). Devolve erro claro.
// O scrapecreators NÃO é tratado aqui (vive no package scraper) — use scraper.Ping.
func Ping(ctx context.Context, provider, key string) error {
	key = strings.TrimSpace(key)
	if key == "" {
		return fmt.Errorf("chave vazia para %s", provider)
	}
	ctx, cancel := context.WithTimeout(ctx, 20*time.Second)
	defer cancel()
	cl := &http.Client{Timeout: 18 * time.Second}

	switch provider {
	case "tavily":
		// POST /search com query curta: 200 = chave ok; 401/403/432 = chave inválida.
		body, _ := json.Marshal(map[string]any{"query": "ping", "max_results": 1})
		req, _ := http.NewRequestWithContext(ctx, http.MethodPost, "https://api.tavily.com/search", strings.NewReader(string(body)))
		req.Header.Set("Authorization", "Bearer "+key)
		req.Header.Set("Content-Type", "application/json")
		return doPing(cl, req, "tavily")
	case "brave":
		// GET search com q=ping: 200 = ok; 401/403/422 = chave inválida.
		req, _ := http.NewRequestWithContext(ctx, http.MethodGet, "https://api.search.brave.com/res/v1/web/search?count=1&q=ping", nil)
		req.Header.Set("X-Subscription-Token", key)
		req.Header.Set("Accept", "application/json")
		return doPing(cl, req, "brave")
	case "jina":
		// GET s.jina.ai simples: 200 = ok; 401/402 = chave inválida/sem crédito.
		req, _ := http.NewRequestWithContext(ctx, http.MethodGet, "https://s.jina.ai/?q=ping", nil)
		req.Header.Set("Authorization", "Bearer "+key)
		req.Header.Set("Accept", "application/json")
		req.Header.Set("X-Respond-With", "no-content") // só metadados (rápido/barato)
		return doPing(cl, req, "jina")
	}
	return errUnknownProvider(provider)
}

// doPing — executa a requisição de teste e traduz o status num erro claro.
// 2xx = ok (nil). 401/403/etc = chave inválida. Demais = erro genérico de provedor.
func doPing(cl *http.Client, req *http.Request, provider string) error {
	resp, err := cl.Do(req)
	if err != nil {
		return fmt.Errorf("%s indisponível: %w", provider, err)
	}
	defer resp.Body.Close()
	io.Copy(io.Discard, io.LimitReader(resp.Body, 4096)) // drena p/ reaproveitar a conexão
	switch {
	case resp.StatusCode >= 200 && resp.StatusCode < 300:
		return nil
	case resp.StatusCode == http.StatusUnauthorized || resp.StatusCode == http.StatusForbidden ||
		resp.StatusCode == http.StatusPaymentRequired || resp.StatusCode == 432:
		return fmt.Errorf("chave de %s inválida ou sem permissão (HTTP %d)", provider, resp.StatusCode)
	default:
		return fmt.Errorf("%s respondeu HTTP %d", provider, resp.StatusCode)
	}
}
