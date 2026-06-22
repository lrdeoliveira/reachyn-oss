// Package search — busca web multi-provedor (primário + alternativo + leitor) e busca por
// rede (site:). A fonte "web" agrega os provedores; fontes sociais usam filtro site:.
// White-label: as bases de API, o modelo de deepsearch e o nome do header de auth do provedor
// alternativo vêm de env/config — nenhuma URL ou nome de provedor no código.
package search

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"os"
	"strings"
	"sync"
	"time"
)

// Endpoints/headers do search — resolvidos de env (sem default revelador). Centralizados
// aqui para que tanto os métodos do Client quanto o Ping package-level os reusem.
func searchPrimaryBase() string { return os.Getenv("SEARCH_PRIMARY_BASE") } // provedor de busca primário
func searchAltBase() string     { return os.Getenv("SEARCH_ALT_BASE") }     // provedor de busca alternativo
func searchAltAuthHeader() string { // header de auth do alternativo
	if h := os.Getenv("SEARCH_ALT_AUTH_HEADER"); h != "" {
		return h
	}
	return "Authorization"
}
func readerBase() string      { return os.Getenv("READER_BASE") }      // leitor de página (URL → markdown)
func searchReadBase() string  { return os.Getenv("SEARCH_READ_BASE") } // busca do leitor (busca → resultados)
func deepSearchBase() string  { return os.Getenv("DEEPSEARCH_BASE") }  // provedor de deepsearch
func deepSearchModel() string { return os.Getenv("DEEPSEARCH_MODEL") } // modelo de deepsearch

// errUnknownProvider — erro padronizado de provedor inválido/sem chave.
func errUnknownProvider(p string) error { return fmt.Errorf("provedor de pesquisa inválido: %s", p) }

type Result struct {
	Title   string `json:"title"`
	URL     string `json:"url"`
	Content string `json:"content"`
	Source  string `json:"source"` // web | instagram | twitter | youtube
}

type Client struct {
	primaryKey, altKey, readerKey string
	http                          *http.Client
}

func New(primaryKey, altKey, readerKey string) *Client {
	return &Client{primaryKey: primaryKey, altKey: altKey, readerKey: readerKey, http: &http.Client{Timeout: 20 * time.Second}}
}

// normProvider — normaliza o slug do provedor de busca para o slot canônico opaco
// (search-primary/search-alt/reader). Desconhecido → "".
func normProvider(p string) string {
	switch p {
	case "search-primary":
		return "search-primary"
	case "search-alt":
		return "search-alt"
	case "reader":
		return "reader"
	}
	return ""
}

// With devolve um Client com as chaves do tenant sobrepostas (BYOK).
// Chave vazia mantém a global do sistema (.env) — fallback gracioso. Aceita os slugs do
// tenant tanto opacos quanto legados.
func (c *Client) With(over map[string]string) *Client {
	pick := func(slot string, def string) string {
		for k, v := range over {
			if normProvider(k) == slot && strings.TrimSpace(v) != "" {
				return v
			}
		}
		return def
	}
	return &Client{
		primaryKey: pick("search-primary", c.primaryKey),
		altKey:     pick("search-alt", c.altKey),
		readerKey:  pick("reader", c.readerKey),
		http:       c.http,
	}
}

func clip(s string, n int) string {
	s = strings.TrimSpace(s)
	if len(s) > n {
		return s[:n]
	}
	return s
}

// Read — leitor de página: devolve o CORPO da página em markdown limpo.
// Usado para aprofundar as melhores fontes (snippets de busca são rasos). "" em erro.
func (c *Client) Read(ctx context.Context, pageURL string) string {
	if c.readerKey == "" || pageURL == "" || readerBase() == "" {
		return ""
	}
	req, _ := http.NewRequestWithContext(ctx, http.MethodGet, readerBase()+"/"+pageURL, nil)
	req.Header.Set("Authorization", "Bearer "+c.readerKey)
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

// hasKey — o slot de busca tem chave configurada (tenant ou global)?
func (c *Client) hasKey(provider string) bool {
	switch normProvider(provider) {
	case "search-primary":
		return c.primaryKey != ""
	case "search-alt":
		return c.altKey != ""
	case "reader":
		return c.readerKey != ""
	}
	return false
}

// searchByProvider — executa a busca NORMAL em UM slot (sem dedup/source).
// "" (nenhum resultado) se o slot for desconhecido ou estiver sem chave.
func (c *Client) searchByProvider(ctx context.Context, provider, query string) []Result {
	switch normProvider(provider) {
	case "search-primary":
		if c.primaryKey == "" {
			return nil
		}
		return c.primaryKeySearch(ctx, query)
	case "search-alt":
		if c.altKey == "" {
			return nil
		}
		return c.altKeySearch(ctx, query)
	case "reader":
		if c.readerKey == "" {
			return nil
		}
		return c.readerKeySearch(ctx, query)
	}
	return nil
}

// SearchLine — busca NORMAL respeitando a "line" (principal/reserva): tenta o `primary`;
// se vier vazio (sem resultado) ou o slot não tiver chave, cai pro `fallback`.
// `fallback` vazio = sem reserva. Deduplica por URL e devolve até n.
func (c *Client) SearchLine(ctx context.Context, query, primary, fallback string, n int) []Result {
	if primary == "" {
		primary = "search-primary" // default histórico
	}
	rs := c.searchByProvider(ctx, primary, query)
	if len(rs) == 0 && fallback != "" && fallback != primary {
		rs = c.searchByProvider(ctx, fallback, query)
	}
	return dedup(rs, "web", n)
}

// Web — agrega os slots (primário + alternativo + leitor) em paralelo, deduplica por URL.
func (c *Client) Web(ctx context.Context, query string, n int) []Result {
	var wg sync.WaitGroup
	var mu sync.Mutex
	all := []Result{}
	add := func(rs []Result) { mu.Lock(); all = append(all, rs...); mu.Unlock() }

	if c.primaryKey != "" {
		wg.Add(1)
		go func() { defer wg.Done(); add(c.primaryKeySearch(ctx, query)) }()
	}
	if c.altKey != "" {
		wg.Add(1)
		go func() { defer wg.Done(); add(c.altKeySearch(ctx, query)) }()
	}
	if c.readerKey != "" {
		wg.Add(1)
		go func() { defer wg.Done(); add(c.readerKeySearch(ctx, query)) }()
	}
	wg.Wait()

	return dedup(all, "web", n)
}

// Site — busca o tema restrita a uma rede (site:dominio) via slot alternativo, fallback no leitor.
func (c *Client) Site(ctx context.Context, query, domain, source string, n int) []Result {
	q := "site:" + domain + " " + query
	var rs []Result
	if c.altKey != "" {
		rs = c.altKeySearch(ctx, q)
	}
	if len(rs) == 0 && c.readerKey != "" {
		rs = c.readerKeySearch(ctx, q)
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

func (c *Client) primaryKeySearch(ctx context.Context, q string) []Result {
	if searchPrimaryBase() == "" {
		return nil
	}
	body, _ := json.Marshal(map[string]any{"query": q, "max_results": 10, "search_depth": "advanced", "include_answer": false})
	req, _ := http.NewRequestWithContext(ctx, http.MethodPost, searchPrimaryBase()+"/search", strings.NewReader(string(body)))
	req.Header.Set("Authorization", "Bearer "+c.primaryKey)
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

func (c *Client) altKeySearch(ctx context.Context, q string) []Result {
	if searchAltBase() == "" {
		return nil
	}
	u := searchAltBase() + "/res/v1/web/search?count=10&q=" + url.QueryEscape(q)
	req, _ := http.NewRequestWithContext(ctx, http.MethodGet, u, nil)
	req.Header.Set(searchAltAuthHeader(), c.altKey)
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

// DeepSearch — pesquisa profunda agêntica (busca + lê + raciocina + itera). Lento (~min).
func (c *Client) DeepSearch(ctx context.Context, query string) (Deep, error) {
	if deepSearchBase() == "" {
		return Deep{}, fmt.Errorf("deepsearch: base não configurada")
	}
	body, _ := json.Marshal(map[string]any{
		"model": deepSearchModel(),
		"messages": []map[string]string{
			{"role": "system", "content": "Responda SEMPRE em português do Brasil (PT-BR): use 'você', ortografia e vocabulário brasileiros; evite construções de português europeu ('a fazer', 'de facto', 'utilizadores'). Pode pesquisar fontes em qualquer idioma, mas o texto final deve ser PT-BR."},
			{"role": "user", "content": query},
		},
		"stream":           false,
		"reasoning_effort": "low",
	})
	req, _ := http.NewRequestWithContext(ctx, http.MethodPost, deepSearchBase()+"/v1/chat/completions", strings.NewReader(string(body)))
	req.Header.Set("Authorization", "Bearer "+c.readerKey)
	req.Header.Set("Content-Type", "application/json")
	cl := &http.Client{Timeout: 240 * time.Second} // deepsearch é lento
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
//   - slot "reader"         → deepsearch agêntico — comportamento atual.
//   - slot "search-primary" → API /research do provedor primário (assíncrona, ~5min).
//
// Aceita também os rótulos legados do console (retrocompat). Tenta o `primary`; se falhar
// (erro ou resumo vazio) e houver `fallback` distinto, cai nele.
func (c *Client) DeepSearchLine(ctx context.Context, query, primary, fallback string) (Deep, error) {
	if primary == "" {
		primary = "reader" // default histórico
	}
	d, err := c.deepByProvider(ctx, primary, query)
	if (err != nil || strings.TrimSpace(d.Summary) == "") && fallback != "" && fallback != primary {
		if d2, err2 := c.deepByProvider(ctx, fallback, query); err2 == nil && strings.TrimSpace(d2.Summary) != "" {
			return d2, nil
		}
	}
	return d, err
}

// deepByProvider — roteia a pesquisa profunda pro slot escolhido (aceita rótulos legados).
func (c *Client) deepByProvider(ctx context.Context, provider, query string) (Deep, error) {
	switch normProvider(provider) {
	case "search-primary":
		return c.primaryKeyResearch(ctx, query)
	case "reader":
		return c.DeepSearch(ctx, query)
	}
	return Deep{}, errUnknownProvider(provider)
}

// primaryKeyResearch — pesquisa profunda via API /research do provedor primário (assíncrona).
// POST /research {input} → {request_id, status:"pending"}; faz polling em
// GET /research/{id} até status=="completed". A resposta final traz `content` (resumo)
// e `sources` ([{url,title}]). Auth Bearer da chave. Orçamento total ~330s.
func (c *Client) primaryKeyResearch(ctx context.Context, query string) (Deep, error) {
	if c.primaryKey == "" {
		return Deep{}, errUnknownProvider("primário (sem chave)")
	}
	if searchPrimaryBase() == "" {
		return Deep{}, errUnknownProvider("primário (sem base)")
	}
	ctx, cancel := context.WithTimeout(ctx, 330*time.Second)
	defer cancel()
	cl := &http.Client{Timeout: 60 * time.Second}

	// 1) dispara a pesquisa (assíncrona).
	body, _ := json.Marshal(map[string]any{"input": query})
	req, _ := http.NewRequestWithContext(ctx, http.MethodPost, searchPrimaryBase()+"/research", strings.NewReader(string(body)))
	req.Header.Set("Authorization", "Bearer "+c.primaryKey)
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
		return Deep{}, errUnknownProvider("research: sem request_id")
	}

	// 2) faz polling até "completed" (ou estourar o orçamento de tempo).
	ticker := time.NewTicker(5 * time.Second)
	defer ticker.Stop()
	for {
		pr, perr := http.NewRequestWithContext(ctx, http.MethodGet, searchPrimaryBase()+"/research/"+start.RequestID, nil)
		if perr != nil {
			return Deep{}, perr
		}
		pr.Header.Set("Authorization", "Bearer "+c.primaryKey)
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
			return Deep{}, errUnknownProvider("research: status " + poll.Status)
		}
		// pending/in_progress → aguarda o próximo tick ou o cancelamento do ctx.
		select {
		case <-ctx.Done():
			return Deep{}, ctx.Err()
		case <-ticker.C:
		}
	}
}

func (c *Client) readerKeySearch(ctx context.Context, q string) []Result {
	if searchReadBase() == "" {
		return nil
	}
	req, _ := http.NewRequestWithContext(ctx, http.MethodGet, searchReadBase()+"/?q="+url.QueryEscape(q), nil)
	req.Header.Set("Authorization", "Bearer "+c.readerKey)
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

// Ping — valida a chave de um slot de PESQUISA sem custo alto (1 chamada leve).
// Distingue chave inválida (401/403) de chave válida (200). Devolve erro claro.
// Aceita rótulos opacos e legados. O scraper NÃO é tratado aqui — use scraper.Ping.
func Ping(ctx context.Context, provider, key string) error {
	key = strings.TrimSpace(key)
	if key == "" {
		return fmt.Errorf("chave vazia para %s", provider)
	}
	ctx, cancel := context.WithTimeout(ctx, 20*time.Second)
	defer cancel()
	cl := &http.Client{Timeout: 18 * time.Second}

	switch normProvider(provider) {
	case "search-primary":
		// POST /search com query curta: 200 = chave ok; 401/403/432 = chave inválida.
		if searchPrimaryBase() == "" {
			return fmt.Errorf("provedor de busca primário sem base configurada")
		}
		body, _ := json.Marshal(map[string]any{"query": "ping", "max_results": 1})
		req, _ := http.NewRequestWithContext(ctx, http.MethodPost, searchPrimaryBase()+"/search", strings.NewReader(string(body)))
		req.Header.Set("Authorization", "Bearer "+key)
		req.Header.Set("Content-Type", "application/json")
		return doPing(cl, req, "primário")
	case "search-alt":
		// GET search com q=ping: 200 = ok; 401/403/422 = chave inválida.
		if searchAltBase() == "" {
			return fmt.Errorf("provedor de busca alternativo sem base configurada")
		}
		req, _ := http.NewRequestWithContext(ctx, http.MethodGet, searchAltBase()+"/res/v1/web/search?count=1&q=ping", nil)
		req.Header.Set(searchAltAuthHeader(), key)
		req.Header.Set("Accept", "application/json")
		return doPing(cl, req, "alternativo")
	case "reader":
		// GET leitor simples: 200 = ok; 401/402 = chave inválida/sem crédito.
		if searchReadBase() == "" {
			return fmt.Errorf("leitor de busca sem base configurada")
		}
		req, _ := http.NewRequestWithContext(ctx, http.MethodGet, searchReadBase()+"/?q=ping", nil)
		req.Header.Set("Authorization", "Bearer "+key)
		req.Header.Set("Accept", "application/json")
		req.Header.Set("X-Respond-With", "no-content") // só metadados (rápido/barato)
		return doPing(cl, req, "leitor")
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
