"use client";

import { useCallback, useEffect, useState } from "react";
import { sfetch } from "@/lib/api";

type Provider = { key: string; label: string; group?: string; desc: string; free?: string; url?: string; testable?: boolean; configurable?: string[]; base_url?: string; model?: string };

const card = { background: "var(--panel)", border: "1px solid var(--line)", borderRadius: 12, padding: 16 } as const;
const inp = { background: "var(--bg2)", color: "var(--text)", border: "1px solid var(--line)", borderRadius: 10, padding: "10px 12px", fontSize: ".9rem", width: "100%" } as const;
const grid = { display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(min(280px, 100%), 1fr))", gap: 14, marginTop: 12 } as const;
const badgeOn = { fontSize: ".72rem", color: "#22c55e", border: "1px solid #22c55e", borderRadius: 12, padding: "1px 8px" } as const;
const badgeOff = { fontSize: ".72rem", color: "var(--muted)", border: "1px solid var(--line)", borderRadius: 12, padding: "1px 8px" } as const;

type Saldo = {
  id: string; label: string;
  tipo: "creditos" | "caracteres" | null;
  saldo: number | null; limite?: number | null; usado?: number | null;
  unidade: string | null; reset_em?: string | null; plano?: string | null;
  ok: boolean; erro?: string | null;
};

const num = (n: number) => n.toLocaleString("pt-BR", { maximumFractionDigits: 0 });

/**
 * Saldo REAL das contas nos PROVEDORES — dinheiro/cota na conta deles, NÃO a cota do plano do
 * cliente (isso é a página Plano & Uso). Quando esse número zera, a geração paga para de
 * funcionar e o sintoma chega como "geração falhando" — ver o saldo aqui encurta o diagnóstico.
 *
 * Fica só nesta página porque ela inteira é de operador (o endpoint devolve 403 pro resto):
 * o painel cita provedor pelo nome, o que fere o white-label se vazar pra cliente.
 */
function SaldosSection() {
  const [itens, setItens] = useState<Saldo[]>([]);
  const [estado, setEstado] = useState<"carregando" | "pronto" | "falhou">("carregando");

  const load = useCallback(() => {
    sfetch("/api/admin/provider-balances")
      .then((r) => (r.ok ? r.json() : null))
      .then((d) => {
        if (d?.provedores) { setItens(d.provedores); setEstado("pronto"); }
        else setEstado("falhou");
      })
      .catch(() => setEstado("falhou"));
  }, []);
  useEffect(load, [load]);

  return (
    <section style={{ marginBottom: 26 }}>
      <h2 style={{ fontSize: "1.05rem", margin: "0 0 4px" }}>Saldo nos provedores</h2>
      <p className="txt" style={{ color: "var(--muted)", fontSize: ".84rem", margin: "0 0 4px" }}>
        Crédito real na conta de cada provedor — não é a cota de plano do cliente. Atualiza a cada minuto.
      </p>
      <button className="btn" style={{ flex: "none", padding: "5px 12px", fontSize: ".78rem" }} onClick={load}>Atualizar</button>

      {estado === "carregando" && <p className="txt" style={{ color: "var(--muted)", marginTop: 12 }}>Consultando provedores…</p>}
      {estado === "falhou" && <p className="txt" style={{ color: "#fca5a5", marginTop: 12 }}>Não foi possível carregar os saldos.</p>}

      {estado === "pronto" && (
        <div style={grid}>
          {itens.map((s) => {
            const pct = s.ok && s.limite ? Math.min(100, Math.round(((s.saldo ?? 0) / s.limite) * 100)) : null;
            // Créditos: abaixo de 1000 já merece atenção. Cota: menos de 15% restante.
            const baixo = s.ok && (pct !== null ? pct < 15 : (s.saldo ?? 0) < 1000);
            const cor = !s.ok ? "var(--muted)" : baixo ? "#fca5a5" : "var(--text)";
            return (
              <div key={s.id} style={{ ...card, borderColor: baixo ? "rgba(239,68,68,.45)" : "var(--line)" }}>
                <div style={{ display: "flex", justifyContent: "space-between", alignItems: "baseline", gap: 8 }}>
                  <strong style={{ fontSize: ".95rem" }}>{s.label}</strong>
                  <span style={s.ok ? badgeOn : badgeOff}>{s.ok ? (s.tipo === "caracteres" ? "cota" : "créditos") : "indisponível"}</span>
                </div>

                {s.ok ? (
                  <>
                    <div style={{ fontSize: "1.5rem", fontWeight: 600, color: cor, marginTop: 8 }}>
                      {num(s.saldo ?? 0)} <span style={{ fontSize: ".78rem", fontWeight: 400, color: "var(--muted)" }}>{s.unidade}{s.limite ? " restantes" : ""}</span>
                    </div>
                    {s.limite != null && (
                      <>
                        <div style={{ height: 6, borderRadius: 4, background: "var(--bg2)", marginTop: 10, overflow: "hidden" }}>
                          <div style={{ width: `${pct ?? 0}%`, height: "100%", background: baixo ? "#ef4444" : "#22c55e" }} />
                        </div>
                        <p className="txt" style={{ color: "var(--muted)", fontSize: ".76rem", margin: "6px 0 0" }}>
                          {num(s.usado ?? 0)} de {num(s.limite)} usados ({pct}% livre){s.plano ? ` · plano ${s.plano}` : ""}
                        </p>
                      </>
                    )}
                    {s.reset_em && (
                      <p className="txt" style={{ color: "var(--muted)", fontSize: ".76rem", margin: "4px 0 0" }}>
                        Renova em {new Date(s.reset_em).toLocaleDateString("pt-BR", { day: "2-digit", month: "short", year: "numeric" })}
                      </p>
                    )}
                  </>
                ) : (
                  <p className="txt" style={{ color: "var(--muted)", fontSize: ".8rem", margin: "10px 0 0" }}>{s.erro || "Saldo não disponível."}</p>
                )}
              </div>
            );
          })}
        </div>
      )}
    </section>
  );
}

/** Seção de chaves do operador (Geração e Publicação compartilham a mesma API admin). */
function AdminSection({ base, title, subtitle, guide }: { base: string; title: string; subtitle: string; guide?: { intro: string; steps: string[]; note?: string } }) {
  const [providers, setProviders] = useState<Provider[]>([]);
  const [configured, setConfigured] = useState<Record<string, boolean>>({});
  const [vals, setVals] = useState<Record<string, string>>({});
  const [urls, setUrls] = useState<Record<string, string>>({});
  const [models, setModels] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState<string | null>(null);
  const [msg, setMsg] = useState<string | null>(null);

  const load = useCallback(() => {
    sfetch(base).then(async (r) => {
      if (!r.ok) return;
      const d = await r.json();
      if (d?.ok) {
        const provs: Provider[] = d.providers || [];
        setProviders(provs);
        setConfigured(d.configured || {});
        // Valores atuais de base_url/model (não-secretos). Backend pode devolver em
        // d.config[provider]={base_url,model}, em d.base_urls/d.models, ou direto no provider
        // (p.base_url/p.model). Lemos de forma defensiva, cobrindo os 3 shapes.
        // O console retorna em d.settings[provider]={base_url,model}; cobrimos também
        // config/base_urls/direto-no-provider por robustez.
        const cfg: Record<string, { base_url?: string; model?: string }> = d.settings || d.config || {};
        const baseUrls: Record<string, string> = d.base_urls || {};
        const mdls: Record<string, string> = d.models || {};
        const nextUrls: Record<string, string> = {};
        const nextModels: Record<string, string> = {};
        for (const p of provs) {
          const u = cfg[p.key]?.base_url ?? baseUrls[p.key] ?? p.base_url;
          const m = cfg[p.key]?.model ?? mdls[p.key] ?? p.model;
          if (typeof u === "string") nextUrls[p.key] = u;
          if (typeof m === "string") nextModels[p.key] = m;
        }
        setUrls(nextUrls);
        setModels(nextModels);
      }
    }).catch(() => {});
  }, [base]);
  useEffect(load, [load]);

  async function salvar() {
    const body: Record<string, string> = {};
    for (const p of providers) {
      if ((vals[p.key] || "").trim()) body[p.key] = vals[p.key].trim();
      if (p.configurable?.includes("base_url") && (urls[p.key] || "").trim()) body[`${p.key}_base_url`] = urls[p.key].trim();
      if (p.configurable?.includes("model") && (models[p.key] || "").trim()) body[`${p.key}_model`] = models[p.key].trim();
    }
    if (Object.keys(body).length === 0) { setMsg("Preencha ao menos uma chave (ou Base URL / Modelo)."); return; }
    setBusy("save"); setMsg(null);
    const r = await sfetch(base, { method: "PUT", headers: { "Content-Type": "application/json" }, body: JSON.stringify(body) });
    const d = await r.json(); setBusy(null);
    if (d.ok) { setMsg(d.warning ? "⚠️ " + d.warning : "✅ Chaves salvas."); setVals({}); setConfigured(d.configured || configured); load(); }
    else setMsg("❌ " + (d.error || "falha"));
  }
  async function testar(p: Provider) {
    setBusy("test-" + p.key); setMsg(null);
    const r = await sfetch(`${base}/test`, { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ provider: p.key, key: (vals[p.key] || "").trim() }) });
    const d = await r.json(); setBusy(null);
    setMsg(d.ok ? `✅ ${p.label}: conexão OK (${d.latency_ms ?? "?"}ms)` : `❌ ${p.label}: ${d.error || "falhou"}`);
  }
  async function remover(p: Provider) {
    if (!confirm(`Remover a chave de ${p.label}? Volta a usar a padrão do sistema.`)) return;
    setBusy("del-" + p.key); setMsg(null);
    const r = await sfetch(`${base}/${p.key}`, { method: "DELETE" });
    const d = await r.json(); setBusy(null);
    if (d.ok) { setConfigured((c) => ({ ...c, ...(d.configured || { [p.key]: false }) })); setMsg(`🗑️ Chave de ${p.label} removida.`); }
  }

  return (
    <section style={{ marginTop: 28 }}>
      <h2 style={{ fontSize: "1.05rem", margin: 0 }}>{title}</h2>
      <p className="sub" style={{ marginTop: 4 }}>{subtitle}</p>
      {guide && (
        <div style={{ ...card, marginTop: 10, borderColor: "var(--peach)" }}>
          <strong style={{ fontSize: ".9rem" }}>📘 Como conectar</strong>
          <p className="txt" style={{ color: "var(--muted)", fontSize: ".84rem", margin: "6px 0 8px" }}>{guide.intro}</p>
          <ol style={{ margin: 0, paddingLeft: 18, color: "var(--text)", fontSize: ".84rem", lineHeight: 1.7 }}>
            {guide.steps.map((s, i) => <li key={i}>{s}</li>)}
          </ol>
          {guide.note && <p className="txt" style={{ color: "var(--muted)", fontSize: ".78rem", marginTop: 8 }}>{guide.note}</p>}
        </div>
      )}
      <div style={grid}>
        {providers.map((p) => (
          <div key={p.key} style={card}>
            <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", gap: 8 }}>
              <strong>{p.label}</strong>
              <span style={configured[p.key] ? badgeOn : badgeOff}>{configured[p.key] ? "definida ✓" : "padrão do sistema"}</span>
            </div>
            <p className="txt" style={{ color: "var(--muted)", fontSize: ".82rem", margin: "6px 0 8px" }}>{p.desc}</p>
            <input type="password" autoComplete="off" placeholder={configured[p.key] ? "•••••• (definida — cole pra trocar)" : "cole a chave aqui"} value={vals[p.key] || ""} onChange={(e) => setVals((v) => ({ ...v, [p.key]: e.target.value }))} style={inp} />
            {p.configurable?.includes("base_url") && (
              <div style={{ marginTop: 8 }}>
                <label className="txt" style={{ display: "block", fontSize: ".72rem", color: "var(--muted)", marginBottom: 4 }}>Base URL <span style={{ opacity: .7 }}>(vazio = padrão do sistema)</span></label>
                <input type="text" autoComplete="off" placeholder="padrão do sistema" value={urls[p.key] || ""} onChange={(e) => setUrls((u) => ({ ...u, [p.key]: e.target.value }))} style={inp} />
              </div>
            )}
            {p.configurable?.includes("model") && (
              <div style={{ marginTop: 8 }}>
                <label className="txt" style={{ display: "block", fontSize: ".72rem", color: "var(--muted)", marginBottom: 4 }}>Modelo <span style={{ opacity: .7 }}>(vazio = padrão do sistema)</span></label>
                <input type="text" autoComplete="off" placeholder="padrão do sistema" value={models[p.key] || ""} onChange={(e) => setModels((m) => ({ ...m, [p.key]: e.target.value }))} style={inp} />
              </div>
            )}
            <div style={{ display: "flex", gap: 8, marginTop: 8, flexWrap: "wrap" }}>
              {p.testable && <button className="btn edit" style={{ flex: "none", padding: "6px 12px", fontSize: ".8rem" }} disabled={busy === "test-" + p.key} onClick={() => testar(p)}>{busy === "test-" + p.key ? "Testando…" : "Testar conexão"}</button>}
              {configured[p.key] && <button className="btn edit" style={{ flex: "none", padding: "6px 12px", fontSize: ".8rem" }} disabled={busy === "del-" + p.key} onClick={() => remover(p)}>Remover</button>}
            </div>
          </div>
        ))}
      </div>
      <div style={{ display: "flex", gap: 10, alignItems: "center", flexWrap: "wrap", marginTop: 12 }}>
        <button className="btn ok" style={{ flex: "none", padding: "9px 16px" }} disabled={busy === "save"} onClick={salvar}>{busy === "save" ? "Salvando…" : "Salvar"}</button>
        {msg && <span className="txt" style={{ color: "var(--muted)" }}>{msg}</span>}
      </div>
    </section>
  );
}

// ── Central de Pesquisa ──────────────────────────────────────────────────────
// Cada FUNÇÃO tem uma linha PRINCIPAL e uma de RESERVA (fallback). A chave é por
// PROVEDOR (não por linha): se um provedor é usado em duas linhas, a chave é a mesma.

type SearchProviderKey = "tavily" | "brave" | "jina" | "scrapecreators";
type SearchFnKey = "normal" | "deep" | "scraper";
type LinePair = { primary: string; fallback: string };
type SearchLines = Record<SearchFnKey, LinePair>;

const SEARCH_PROVIDER_META: Record<SearchProviderKey, { label: string; placeholder: string; url?: string }> = {
  tavily: { label: "Tavily", placeholder: "tvly-…", url: "https://tavily.com" },
  brave: { label: "Brave Search", placeholder: "BSA…", url: "https://brave.com/search/api/" },
  jina: { label: "Jina (Leitor & Busca)", placeholder: "jina_…", url: "https://jina.ai" },
  scrapecreators: { label: "ScrapeCreators", placeholder: "cole sua chave", url: "https://scrapecreators.com" },
};

// Provedores VÁLIDOS por função (1º item = recomendado por padrão; o backend confirma em `recommended`).
const SEARCH_FUNCTIONS: { key: SearchFnKey; label: string; desc: string; providers: SearchProviderKey[] }[] = [
  { key: "normal", label: "Busca web", desc: "Busca rápida na internet — alimenta posts e roteiros.", providers: ["tavily", "brave", "jina"] },
  { key: "deep", label: "Pesquisa profunda", desc: "Investiga a fundo e gera um relatório com as fontes.", providers: ["jina", "tavily"] },
  { key: "scraper", label: "Conteúdo social", desc: "Lê perfis de redes sociais (Instagram, TikTok, X, YouTube).", providers: ["scrapecreators"] },
];

// Tabela de recomendação (didática, sem jargão de código).
const SEARCH_RECO: { fn: string; recommended: string; alternatives: string; free: string; links: SearchProviderKey[] }[] = [
  { fn: "Busca web", recommended: "Tavily", alternatives: "Brave, Jina", free: "Tavily 1.000/mês · Brave 2.000/mês", links: ["tavily", "brave", "jina"] },
  { fn: "Pesquisa profunda", recommended: "Jina DeepSearch", alternatives: "Tavily Research", free: "Jina: cota generosa · Tavily: mais completo (~5 min)", links: ["jina", "tavily"] },
  { fn: "Conteúdo social", recommended: "ScrapeCreators", alternatives: "—", free: "Trial disponível", links: ["scrapecreators"] },
];

const emptyLines: SearchLines = {
  normal: { primary: "", fallback: "" },
  deep: { primary: "", fallback: "" },
  scraper: { primary: "", fallback: "" },
};

const lbl = { display: "block", fontSize: ".72rem", color: "var(--muted)", marginBottom: 4 } as const;
const sel = { ...inp, padding: "9px 10px" } as const;

/**
 * Central de Pesquisa (BYOK por conta). Lê tudo de GET /api/studio/search-config:
 * - lines (principal/fallback por função) · keys_configured (chave por provedor) · recommended.
 * Salva: POST search-config {lines} + POST search-keys {chaves preenchidas}.
 * Testa: POST search-test {provider, key}.
 */
function GuideBox({ intro, steps, note }: { intro: string; steps: string[]; note?: string }) {
  return (
    <div style={{ ...card, marginTop: 12, borderColor: "var(--peach)" }}>
      <strong style={{ fontSize: ".95rem" }}>📘 Como conectar</strong>
      <p className="txt" style={{ color: "var(--muted)", fontSize: ".84rem", margin: "6px 0 8px" }}>{intro}</p>
      <ol style={{ margin: 0, paddingLeft: 18, color: "var(--text)", fontSize: ".84rem", lineHeight: 1.7 }}>
        {steps.map((s, i) => <li key={i}>{s}</li>)}
      </ol>
      {note && <p className="txt" style={{ color: "var(--muted)", fontSize: ".78rem", marginTop: 8 }}>{note}</p>}
    </div>
  );
}

function SearchSection() {
  const [lines, setLines] = useState<SearchLines>(emptyLines);
  const [recommended, setRecommended] = useState<Partial<SearchLines>>({});
  const [keysConfigured, setKeysConfigured] = useState<Record<string, boolean>>({});
  const [keys, setKeys] = useState<Record<string, string>>({}); // chaves a salvar (por provedor)
  const [test, setTest] = useState<Record<string, { ok: boolean; ms?: number; err?: string }>>({});
  const [busy, setBusy] = useState<string | null>(null);
  const [msg, setMsg] = useState<string | null>(null);

  const load = useCallback(() => {
    sfetch("/api/studio/search-config").then((r) => r.json()).then((d) => {
      if (!d?.ok) return;
      if (d.lines) {
        setLines({
          normal: { primary: d.lines.normal?.primary || "", fallback: d.lines.normal?.fallback || "" },
          deep: { primary: d.lines.deep?.primary || "", fallback: d.lines.deep?.fallback || "" },
          scraper: { primary: d.lines.scraper?.primary || "", fallback: d.lines.scraper?.fallback || "" },
        });
      }
      if (d.recommended) setRecommended(d.recommended);
      setKeysConfigured(d.keys_configured || {});
    }).catch(() => {});
  }, []);
  useEffect(load, [load]);

  function setLine(fn: SearchFnKey, slot: keyof LinePair, value: string) {
    setLines((l) => ({ ...l, [fn]: { ...l[fn], [slot]: value } }));
  }

  // Provedores realmente em uso nesta função (principal + reserva, sem duplicar e sem vazio).
  function providersInUse(fn: SearchFnKey): SearchProviderKey[] {
    const pr = lines[fn].primary, fb = lines[fn].fallback;
    const out: SearchProviderKey[] = [];
    for (const p of [pr, fb]) if (p && !out.includes(p as SearchProviderKey)) out.push(p as SearchProviderKey);
    return out;
  }

  function recoLabel(fn: SearchFnKey, p: SearchProviderKey): string {
    return recommended[fn]?.primary === p ? `${SEARCH_PROVIDER_META[p].label} (recomendado)` : SEARCH_PROVIDER_META[p].label;
  }

  async function testar(provider: SearchProviderKey) {
    setBusy("test-" + provider); setMsg(null);
    const r = await sfetch("/api/studio/search-test", { method: "POST", body: JSON.stringify({ provider, key: (keys[provider] || "").trim() }) });
    const d = await r.json(); setBusy(null);
    setTest((t) => ({ ...t, [provider]: { ok: !!d.ok, ms: d.latency_ms, err: d.error } }));
  }

  async function salvar() {
    setBusy("save"); setMsg(null);
    // 1) salva as linhas
    const rc = await sfetch("/api/studio/search-config", { method: "POST", body: JSON.stringify({ lines }) });
    const dc = await rc.json();
    if (!dc.ok) { setBusy(null); setMsg("❌ " + (dc.error || "falha ao salvar as linhas")); return; }
    // 2) salva apenas as chaves preenchidas (BYOK por provedor)
    const body: Record<string, string> = {};
    for (const p of Object.keys(SEARCH_PROVIDER_META) as SearchProviderKey[]) {
      if ((keys[p] || "").trim()) body[p] = keys[p].trim();
    }
    if (Object.keys(body).length) {
      const rk = await sfetch("/api/studio/search-keys", { method: "POST", body: JSON.stringify(body) });
      const dk = await rk.json();
      if (!dk.ok) { setBusy(null); setMsg("⚠️ Linhas salvas, mas falhou nas chaves: " + (dk.error || "erro")); load(); return; }
    }
    setBusy(null); setMsg("✅ Tudo salvo."); setKeys({}); load();
  }

  // Renderiza o campo de chave de UM provedor (reaproveitado entre as linhas da função).
  function ProviderKeyField({ p }: { p: SearchProviderKey }) {
    const meta = SEARCH_PROVIDER_META[p];
    const t = test[p];
    return (
      <div style={{ marginTop: 10, padding: 10, background: "var(--bg2)", border: "1px solid var(--line)", borderRadius: 10 }}>
        <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", gap: 8 }}>
          <strong style={{ fontSize: ".85rem" }}>{meta.label}</strong>
          <span style={keysConfigured[p] ? badgeOn : badgeOff}>{keysConfigured[p] ? "🔑 sua chave ✓" : "usando a do Reachyn"}</span>
        </div>
        <div style={{ display: "flex", gap: 8, marginTop: 8, flexWrap: "wrap", alignItems: "center" }}>
          <input
            type="password" autoComplete="off"
            placeholder={keysConfigured[p] ? "•••••• (definida — cole pra trocar)" : `${meta.placeholder} · vazio = chave do Reachyn`}
            value={keys[p] || ""} onChange={(e) => setKeys((v) => ({ ...v, [p]: e.target.value }))}
            style={{ ...inp, flex: "1 1 200px", width: "auto" }}
          />
          <button className="btn edit" style={{ flex: "none", padding: "8px 12px", fontSize: ".8rem" }} disabled={busy === "test-" + p} onClick={() => testar(p)}>
            {busy === "test-" + p ? "Testando…" : "Testar"}
          </button>
          {t && (
            <span className="txt" style={{ fontSize: ".78rem", color: t.ok ? "#22c55e" : "#ef4444" }}>
              {t.ok ? `✓ OK${t.ms != null ? ` (${t.ms}ms)` : ""}` : `✗ ${t.err || "falhou"}`}
            </span>
          )}
        </div>
        {meta.url && <a href={meta.url} target="_blank" rel="noopener" style={{ fontSize: ".74rem", color: "var(--peach)", display: "inline-block", marginTop: 6 }}>obter chave →</a>}
      </div>
    );
  }

  return (
    <section style={{ marginTop: 28 }}>
      <h2 style={{ fontSize: "1.05rem", margin: 0 }}>Pesquisa</h2>
      <p className="sub" style={{ marginTop: 4 }}>
        Conecte os provedores que o Reachyn usa pra pesquisar. Cada função tem uma linha <strong>PRINCIPAL</strong> e uma de <strong>RESERVA</strong> (fallback):
        se a principal falhar, usa a reserva. Campo de chave vazio = usa a chave do Reachyn.
      </p>
      <GuideBox
        intro="Use a sua própria chave de busca (opcional). Vazio = usa a chave do Reachyn."
        steps={[
          "Em cada função (Busca web / Pesquisa profunda / Conteúdo social), escolha o provedor PRINCIPAL e uma RESERVA.",
          "Crie a conta e gere a API Key no provedor: Tavily (tavily.com) · Brave (brave.com/search/api) · ScrapeCreators (scrapecreators.com) · Jina (jina.ai).",
          "Cole a chave no campo do provedor e clique em \"Testar\" (mostra ✓ e a latência).",
          "Clique em Salvar. Se a principal falhar, o Reachyn usa a reserva automaticamente.",
        ]}
        note="Cada provedor tem cota gratuita pra começar. Você não precisa configurar nada pra usar — sem chave, roda com a do Reachyn."
      />

      {/* 💡 Recomendações */}
      <div style={{ ...card, marginTop: 12 }}>
        <strong style={{ fontSize: ".95rem" }}>💡 Recomendações</strong>
        <p className="txt" style={{ color: "var(--muted)", fontSize: ".8rem", margin: "4px 0 10px" }}>Na dúvida, use a coluna “Recomendado”. Tudo tem cota gratuita pra começar.</p>
        <div style={{ overflowX: "auto" }}>
          <table style={{ width: "100%", borderCollapse: "collapse", fontSize: ".82rem" }}>
            <thead>
              <tr style={{ textAlign: "left", color: "var(--muted)" }}>
                <th style={{ padding: "6px 8px", borderBottom: "1px solid var(--line)" }}>Função</th>
                <th style={{ padding: "6px 8px", borderBottom: "1px solid var(--line)" }}>Recomendado</th>
                <th style={{ padding: "6px 8px", borderBottom: "1px solid var(--line)" }}>Alternativas</th>
                <th style={{ padding: "6px 8px", borderBottom: "1px solid var(--line)" }}>Grátis</th>
                <th style={{ padding: "6px 8px", borderBottom: "1px solid var(--line)" }}>Obter chave</th>
              </tr>
            </thead>
            <tbody>
              {SEARCH_RECO.map((r) => (
                <tr key={r.fn}>
                  <td style={{ padding: "6px 8px", borderBottom: "1px solid var(--line)" }}>{r.fn}</td>
                  <td style={{ padding: "6px 8px", borderBottom: "1px solid var(--line)", color: "var(--text)" }}><strong>{r.recommended}</strong></td>
                  <td style={{ padding: "6px 8px", borderBottom: "1px solid var(--line)", color: "var(--muted)" }}>{r.alternatives}</td>
                  <td style={{ padding: "6px 8px", borderBottom: "1px solid var(--line)", color: "var(--muted)" }}>{r.free}</td>
                  <td style={{ padding: "6px 8px", borderBottom: "1px solid var(--line)" }}>
                    {r.links.map((p, i) => (
                      <span key={p}>
                        {i > 0 && <span style={{ color: "var(--muted)" }}> · </span>}
                        {SEARCH_PROVIDER_META[p].url
                          ? <a href={SEARCH_PROVIDER_META[p].url} target="_blank" rel="noopener" style={{ color: "var(--peach)" }}>{SEARCH_PROVIDER_META[p].label}</a>
                          : <span>{SEARCH_PROVIDER_META[p].label}</span>}
                      </span>
                    ))}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      {/* Um card por FUNÇÃO */}
      <div style={{ display: "grid", gap: 14, marginTop: 14 }}>
        {SEARCH_FUNCTIONS.map((fn) => (
          <div key={fn.key} style={card}>
            <strong style={{ fontSize: ".95rem" }}>{fn.label}</strong>
            <p className="txt" style={{ color: "var(--muted)", fontSize: ".82rem", margin: "4px 0 12px" }}>{fn.desc}</p>

            <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(min(220px, 100%), 1fr))", gap: 12 }}>
              {/* Linha principal */}
              <div>
                <label style={lbl} title="Provedor que o Reachyn tenta primeiro.">Principal <span style={{ opacity: .7 }}>(tentado primeiro)</span></label>
                <select value={lines[fn.key].primary} onChange={(e) => setLine(fn.key, "primary", e.target.value)} style={sel}>
                  <option value="">— escolha —</option>
                  {fn.providers.map((p) => <option key={p} value={p}>{recoLabel(fn.key, p)}</option>)}
                </select>
              </div>
              {/* Linha de reserva */}
              <div>
                <label style={lbl} title="Usada só se a principal falhar.">Reserva <span style={{ opacity: .7 }}>(se a principal falhar)</span></label>
                <select value={lines[fn.key].fallback} onChange={(e) => setLine(fn.key, "fallback", e.target.value)} style={sel}>
                  <option value="">— nenhuma —</option>
                  {fn.providers.map((p) => <option key={p} value={p}>{SEARCH_PROVIDER_META[p].label}</option>)}
                </select>
              </div>
            </div>

            {/* Chaves por PROVEDOR usado nesta função (sem duplicar) */}
            {providersInUse(fn.key).length > 0 ? (
              providersInUse(fn.key).map((p) => <ProviderKeyField key={p} p={p} />)
            ) : (
              <p className="txt" style={{ color: "var(--muted)", fontSize: ".78rem", marginTop: 10 }}>Escolha um provedor acima para conectar sua chave (opcional).</p>
            )}
          </div>
        ))}
      </div>

      <div style={{ display: "flex", gap: 10, alignItems: "center", flexWrap: "wrap", marginTop: 14 }}>
        <button className="btn ok" style={{ flex: "none", padding: "9px 16px" }} disabled={busy === "save"} onClick={salvar}>{busy === "save" ? "Salvando…" : "Salvar"}</button>
        {msg && <span className="txt" style={{ color: "var(--muted)" }}>{msg}</span>}
      </div>
    </section>
  );
}

// ── Central de Geração ───────────────────────────────────────────────────────
// Mesmo padrão da Pesquisa: cada FUNÇÃO (texto/imagem/vídeo/voz) tem uma linha
// PRINCIPAL e uma de RESERVA (fallback). A CHAVE é por PROVEDOR (gen-keys); se um
// provedor aparece em duas linhas, a chave é a mesma. A escolha de motor de VÍDEO por
// modelo não mora mais aqui (é no catálogo, gen_models) — aqui fica só o provedor de
// clipe com chave própria.

type GenFnKey = "text" | "image" | "video" | "voice";
type GenLines = Record<GenFnKey, LinePair>;

// Slot de chave (gen-keys) que atende os modelos de VÍDEO no engine. É um IDENTIFICADOR
// DE API (campo de `genkeys.Set` / `GenerationKeys::PROVIDERS`), não um rótulo — o nome
// exibido vem do back-end, em `providers[].label`.
//
// ⚠️ 2026-08-04: era "kie". O agregador saiu em 03/08 e este slot continuou pedindo a chave
// dele nesta tela — campo de credencial para um motor que nenhum caminho chama. A escolha de
// motor de vídeo mudou de lugar (é por MODELO no catálogo); aqui sobra o Hailuo direto.
const VIDEO_KEY_SLOT = "minimax";

// Provedor da CHAVE (gen-keys) usada por cada opção de geração.
// Todos os modelos de vídeo compartilham a MESMA chave.
const GEN_KEY_OF: Record<string, string> = {
  minimax: "minimax",
  ollama: "ollama",
  elevenlabs: "elevenlabs",
};

// Rótulos amigáveis das OPÇÕES de geração (provedor ou modelo) por função.
const GEN_OPTION_LABEL: Record<string, string> = {
  minimax: "a IA",
  ollama: "Ollama",
  elevenlabs: "voz",
};

// Rótulo da opção dentro de uma função (image/minimax precisa de texto próprio).
function genOptionLabel(fn: GenFnKey, opt: string): string {
  if (fn === "image" && opt === "minimax") return "a IA (image-01)";
  return GEN_OPTION_LABEL[opt] || opt;
}

const GEN_FUNCTIONS: { key: GenFnKey; label: string; desc: string }[] = [
  { key: "text", label: "Texto / IA", desc: "Gera roteiros, legendas e textos. Permite Base URL e Modelo próprios." },
  { key: "image", label: "Imagem", desc: "Gera imagens para posts, thumbnails e capas." },
  { key: "video", label: "Vídeo", desc: "Gera vídeos. Todos os modelos rodam por uma única chave de vídeo." },
  { key: "voice", label: "Voz", desc: "Narração e voz sintética para os vídeos." },
];

// Tabela de recomendação (didática).
const GEN_RECO: { fn: string; recommended: string; alternatives: string; obs: string }[] = [
  { fn: "Texto / IA", recommended: "a IA", alternatives: "Ollama", obs: "Base URL + Modelo configuráveis." },
  { fn: "Imagem", recommended: "a IA (image-01)", alternatives: "—", obs: "—" },
  { fn: "Vídeo", recommended: "Hailuo 02", alternatives: "Hailuo 2.3 Fast (econômico), Seedance, Kling", obs: "Todos usam a mesma chave de vídeo. Premium vídeo premium é toggle no Studio." },
  { fn: "Voz", recommended: "voz", alternatives: "—", obs: "—" },
];

const emptyGenLines: GenLines = {
  text: { primary: "", fallback: "" },
  image: { primary: "", fallback: "" },
  video: { primary: "", fallback: "" },
  voice: { primary: "", fallback: "" },
};

/**
 * Central de Geração (operador). Lê:
 * - GET /api/admin/gen-lines → {lines, recommended, providers_by_function}
 * - GET /api/admin/gen-keys  → {providers, configured, settings} (chaves + base_url/model)
 * Salva: POST /api/admin/gen-lines {lines} + PUT /api/admin/gen-keys (chaves + base_url/model).
 * Testa: POST /api/admin/gen-keys/test {provider, key}.
 */
function GenerationSection() {
  const [lines, setLines] = useState<GenLines>(emptyGenLines);
  const [recommended, setRecommended] = useState<Partial<GenLines>>({});
  const [byFunction, setByFunction] = useState<Record<GenFnKey, string[]>>({ text: [], image: [], video: [], voice: [] });
  // chaves (gen-keys), por PROVEDOR de chave (texto, vídeo, voz…)
  const [providers, setProviders] = useState<Provider[]>([]);
  const [configured, setConfigured] = useState<Record<string, boolean>>({});
  const [vals, setVals] = useState<Record<string, string>>({});
  const [urls, setUrls] = useState<Record<string, string>>({});
  const [models, setModels] = useState<Record<string, string>>({});
  const [test, setTest] = useState<Record<string, { ok: boolean; ms?: number; err?: string }>>({});
  const [busy, setBusy] = useState<string | null>(null);
  const [msg, setMsg] = useState<string | null>(null);

  const load = useCallback(() => {
    // 1) linhas + recomendação + provedores por função
    sfetch("/api/admin/gen-lines").then((r) => r.json()).then((d) => {
      if (!d?.ok) return;
      if (d.lines) {
        setLines({
          text: { primary: d.lines.text?.primary || "", fallback: d.lines.text?.fallback || "" },
          image: { primary: d.lines.image?.primary || "", fallback: d.lines.image?.fallback || "" },
          video: { primary: d.lines.video?.primary || "", fallback: d.lines.video?.fallback || "" },
          voice: { primary: d.lines.voice?.primary || "", fallback: d.lines.voice?.fallback || "" },
        });
      }
      if (d.recommended) setRecommended(d.recommended);
      const pbf = d.providers_by_function || {};
      setByFunction({
        text: pbf.text || ["minimax", "ollama"],
        image: pbf.image || ["minimax"],
        video: pbf.video || ["minimax"],
        voice: pbf.voice || ["elevenlabs"],
      });
    }).catch(() => {});
    // 2) chaves (gen-keys) — configured + settings(base_url/model)
    sfetch("/api/admin/gen-keys").then(async (r) => {
      if (!r.ok) return;
      const d = await r.json();
      if (!d?.ok) return;
      const provs: Provider[] = d.providers || [];
      setProviders(provs);
      setConfigured(d.configured || {});
      const cfg: Record<string, { base_url?: string; model?: string }> = d.settings || d.config || {};
      const nextUrls: Record<string, string> = {};
      const nextModels: Record<string, string> = {};
      for (const p of provs) {
        const u = cfg[p.key]?.base_url ?? p.base_url;
        const m = cfg[p.key]?.model ?? p.model;
        if (typeof u === "string") nextUrls[p.key] = u;
        if (typeof m === "string") nextModels[p.key] = m;
      }
      setUrls(nextUrls);
      setModels(nextModels);
    }).catch(() => {});
  }, []);
  useEffect(load, [load]);

  function setLine(fn: GenFnKey, slot: keyof LinePair, value: string) {
    setLines((l) => ({ ...l, [fn]: { ...l[fn], [slot]: value } }));
  }

  function provById(key: string): Provider | undefined {
    return providers.find((p) => p.key === key);
  }
  function isConfigurable(key: string, what: "base_url" | "model"): boolean {
    return !!provById(key)?.configurable?.includes(what);
  }

  // Provedores de CHAVE realmente em uso nesta função (principal + reserva, dedup, sem vazio).
  // Mapeia a OPÇÃO escolhida para o provedor da CHAVE (opção sem mapa = ela própria).
  function keyProvidersInUse(fn: GenFnKey): string[] {
    const out: string[] = [];
    for (const opt of [lines[fn].primary, lines[fn].fallback]) {
      if (!opt) continue;
      const kp = GEN_KEY_OF[opt] || opt;
      if (!out.includes(kp)) out.push(kp);
    }
    return out;
  }

  function recoLabel(fn: GenFnKey, opt: string): string {
    return recommended[fn]?.primary === opt ? `${genOptionLabel(fn, opt)} (recomendado)` : genOptionLabel(fn, opt);
  }

  // Rótulo do provedor da CHAVE (gen-keys); usa o label do backend se houver.
  function keyProvLabel(key: string): string {
    return provById(key)?.label || GEN_OPTION_LABEL[key] || key;
  }

  async function testar(keyProv: string) {
    setBusy("test-" + keyProv); setMsg(null);
    const r = await sfetch("/api/admin/gen-keys/test", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ provider: keyProv, key: (vals[keyProv] || "").trim() }) });
    const d = await r.json(); setBusy(null);
    setTest((t) => ({ ...t, [keyProv]: { ok: !!d.ok, ms: d.latency_ms, err: d.error } }));
  }

  async function remover(keyProv: string) {
    if (!confirm(`Remover a chave de ${keyProvLabel(keyProv)}? Volta a usar a padrão do sistema.`)) return;
    setBusy("del-" + keyProv); setMsg(null);
    const r = await sfetch(`/api/admin/gen-keys/${keyProv}`, { method: "DELETE" });
    const d = await r.json(); setBusy(null);
    if (d.ok) { setConfigured((c) => ({ ...c, ...(d.configured || { [keyProv]: false }) })); setMsg(`🗑️ Chave de ${keyProvLabel(keyProv)} removida.`); }
  }

  async function salvar() {
    setBusy("save"); setMsg(null);
    // 1) salva as linhas (principal/fallback por função)
    const rc = await sfetch("/api/admin/gen-lines", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ lines }) });
    const dc = await rc.json();
    if (!dc.ok) { setBusy(null); setMsg("❌ " + (dc.error || "falha ao salvar as linhas")); return; }
    // 2) salva chaves preenchidas + base_url/model preenchidos (gen-keys, PUT)
    const body: Record<string, string> = {};
    for (const p of providers) {
      if ((vals[p.key] || "").trim()) body[p.key] = vals[p.key].trim();
      if (p.configurable?.includes("base_url") && (urls[p.key] || "").trim()) body[`${p.key}_base_url`] = urls[p.key].trim();
      if (p.configurable?.includes("model") && (models[p.key] || "").trim()) body[`${p.key}_model`] = models[p.key].trim();
    }
    if (Object.keys(body).length) {
      const rk = await sfetch("/api/admin/gen-keys", { method: "PUT", headers: { "Content-Type": "application/json" }, body: JSON.stringify(body) });
      const dk = await rk.json();
      if (!dk.ok) { setBusy(null); setMsg("⚠️ Linhas salvas, mas falhou nas chaves: " + (dk.error || "erro")); load(); return; }
    }
    setBusy(null); setMsg("✅ Tudo salvo."); setVals({}); load();
  }

  // Campo de chave de UM provedor de CHAVE (gen-keys), reaproveitado entre linhas/funções.
  // `chaveDeVideo` = quando esta é a chave compartilhada dos modelos de vídeo (deixa claro na UI).
  function KeyField({ keyProv, chaveDeVideo }: { keyProv: string; chaveDeVideo?: boolean }) {
    const t = test[keyProv];
    const prov = provById(keyProv);
    const testable = prov?.testable !== false; // por padrão testável
    return (
      <div style={{ marginTop: 10, padding: 10, background: "var(--bg2)", border: "1px solid var(--line)", borderRadius: 10 }}>
        <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", gap: 8 }}>
          <strong style={{ fontSize: ".85rem" }}>
            {keyProvLabel(keyProv)}{chaveDeVideo && <span style={{ color: "var(--muted)", fontWeight: 400 }}> · todos os modelos de vídeo rodam por esta chave</span>}
          </strong>
          <span style={configured[keyProv] ? badgeOn : badgeOff}>{configured[keyProv] ? "🔑 sua chave ✓" : "usando a do Reachyn"}</span>
        </div>
        <div style={{ display: "flex", gap: 8, marginTop: 8, flexWrap: "wrap", alignItems: "center" }}>
          <input
            type="password" autoComplete="off"
            placeholder={configured[keyProv] ? "•••••• (definida — cole pra trocar)" : "cole a chave · vazio = chave do Reachyn"}
            value={vals[keyProv] || ""} onChange={(e) => setVals((v) => ({ ...v, [keyProv]: e.target.value }))}
            style={{ ...inp, flex: "1 1 200px", width: "auto" }}
          />
          {testable && (
            <button className="btn edit" style={{ flex: "none", padding: "8px 12px", fontSize: ".8rem" }} disabled={busy === "test-" + keyProv} onClick={() => testar(keyProv)}>
              {busy === "test-" + keyProv ? "Testando…" : "Testar"}
            </button>
          )}
          {configured[keyProv] && (
            <button className="btn edit" style={{ flex: "none", padding: "8px 12px", fontSize: ".8rem" }} disabled={busy === "del-" + keyProv} onClick={() => remover(keyProv)}>Remover</button>
          )}
          {t && (
            <span className="txt" style={{ fontSize: ".78rem", color: t.ok ? "#22c55e" : "#ef4444" }}>
              {t.ok ? `✓ OK${t.ms != null ? ` (${t.ms}ms)` : ""}` : `✗ ${t.err || "falhou"}`}
            </span>
          )}
        </div>
        {/* Base URL + Modelo (só p/ provedores configuráveis, ex.: texto) */}
        {isConfigurable(keyProv, "base_url") && (
          <div style={{ marginTop: 8 }}>
            <label style={lbl}>Base URL <span style={{ opacity: .7 }}>(vazio = padrão do sistema)</span></label>
            <input type="text" autoComplete="off" placeholder="padrão do sistema" value={urls[keyProv] || ""} onChange={(e) => setUrls((u) => ({ ...u, [keyProv]: e.target.value }))} style={inp} />
          </div>
        )}
        {isConfigurable(keyProv, "model") && (
          <div style={{ marginTop: 8 }}>
            <label style={lbl}>Modelo <span style={{ opacity: .7 }}>(vazio = padrão do sistema)</span></label>
            <input type="text" autoComplete="off" placeholder="padrão do sistema" value={models[keyProv] || ""} onChange={(e) => setModels((m) => ({ ...m, [keyProv]: e.target.value }))} style={inp} />
          </div>
        )}
      </div>
    );
  }

  return (
    <section style={{ marginTop: 28 }}>
      <h2 style={{ fontSize: "1.05rem", margin: 0 }}>Geração</h2>
      <p className="sub" style={{ marginTop: 4 }}>
        Escolha o provedor/modelo <strong>principal</strong> e a <strong>reserva</strong> (fallback) de cada função.
        Conecte a sua chave; vazio = chave do Reachyn.
      </p>
      <GuideBox
        intro="Use a sua própria chave de IA (opcional). Vazio = usa a chave do Reachyn."
        steps={[
          "Em cada função (Texto / Imagem / Vídeo / Voz), escolha o provedor/modelo PRINCIPAL e uma RESERVA.",
          "Gere a API Key no site do provedor: a IA (platform.minimax.io) · Google/premium (aistudio.google.com) · voz (elevenlabs.io). Os modelos de vídeo (Hailuo/Seedance/Kling) compartilham uma única chave de vídeo.",
          "No Texto, opcionalmente preencha Base URL + Modelo pra usar qualquer LLM compatível com OpenAI.",
          "Cole a chave, clique em \"Testar\" e Salvar. Se o principal falhar, o Reachyn usa a reserva.",
        ]}
        note="Cada provedor cobra pelo uso (pay-as-you-go). Sem chave, roda com a do Reachyn."
      />

      {/* 💡 Recomendações */}
      <div style={{ ...card, marginTop: 12 }}>
        <strong style={{ fontSize: ".95rem" }}>💡 Recomendações</strong>
        <p className="txt" style={{ color: "var(--muted)", fontSize: ".8rem", margin: "4px 0 10px" }}>Na dúvida, use a coluna “Recomendado”.</p>
        <div style={{ overflowX: "auto" }}>
          <table style={{ width: "100%", borderCollapse: "collapse", fontSize: ".82rem" }}>
            <thead>
              <tr style={{ textAlign: "left", color: "var(--muted)" }}>
                <th style={{ padding: "6px 8px", borderBottom: "1px solid var(--line)" }}>Função</th>
                <th style={{ padding: "6px 8px", borderBottom: "1px solid var(--line)" }}>Recomendado</th>
                <th style={{ padding: "6px 8px", borderBottom: "1px solid var(--line)" }}>Alternativas</th>
                <th style={{ padding: "6px 8px", borderBottom: "1px solid var(--line)" }}>Observação</th>
              </tr>
            </thead>
            <tbody>
              {GEN_RECO.map((r) => (
                <tr key={r.fn}>
                  <td style={{ padding: "6px 8px", borderBottom: "1px solid var(--line)" }}>{r.fn}</td>
                  <td style={{ padding: "6px 8px", borderBottom: "1px solid var(--line)", color: "var(--text)" }}><strong>{r.recommended}</strong></td>
                  <td style={{ padding: "6px 8px", borderBottom: "1px solid var(--line)", color: "var(--muted)" }}>{r.alternatives}</td>
                  <td style={{ padding: "6px 8px", borderBottom: "1px solid var(--line)", color: "var(--muted)" }}>{r.obs}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      {/* Um card por FUNÇÃO */}
      <div style={{ display: "grid", gap: 14, marginTop: 14 }}>
        {GEN_FUNCTIONS.map((fn) => {
          const opts = byFunction[fn.key] || [];
          return (
            <div key={fn.key} style={card}>
              <strong style={{ fontSize: ".95rem" }}>{fn.label}</strong>
              <p className="txt" style={{ color: "var(--muted)", fontSize: ".82rem", margin: "4px 0 12px" }}>{fn.desc}</p>

              <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(min(220px, 100%), 1fr))", gap: 12 }}>
                {/* Linha principal */}
                <div>
                  <label style={lbl} title="Provedor/modelo tentado primeiro.">Principal <span style={{ opacity: .7 }}>(tentado primeiro)</span></label>
                  <select value={lines[fn.key].primary} onChange={(e) => setLine(fn.key, "primary", e.target.value)} style={sel}>
                    <option value="">— escolha —</option>
                    {opts.map((o) => <option key={o} value={o}>{recoLabel(fn.key, o)}</option>)}
                  </select>
                </div>
                {/* Linha de reserva */}
                <div>
                  <label style={lbl} title="Usada só se a principal falhar.">Reserva <span style={{ opacity: .7 }}>(se a principal falhar)</span></label>
                  <select value={lines[fn.key].fallback} onChange={(e) => setLine(fn.key, "fallback", e.target.value)} style={sel}>
                    <option value="">— nenhuma —</option>
                    {opts.map((o) => <option key={o} value={o}>{genOptionLabel(fn.key, o)}</option>)}
                  </select>
                </div>
              </div>

              {/* Chaves por PROVEDOR de chave usado nesta função (sem duplicar) */}
              {keyProvidersInUse(fn.key).length > 0 ? (
                keyProvidersInUse(fn.key).map((kp) => <KeyField key={kp} keyProv={kp} chaveDeVideo={fn.key === "video" && kp === VIDEO_KEY_SLOT} />)
              ) : (
                <p className="txt" style={{ color: "var(--muted)", fontSize: ".78rem", marginTop: 10 }}>Escolha um provedor/modelo acima para conectar sua chave (opcional).</p>
              )}
            </div>
          );
        })}
      </div>

      <div style={{ display: "flex", gap: 10, alignItems: "center", flexWrap: "wrap", marginTop: 14 }}>
        <button className="btn ok" style={{ flex: "none", padding: "9px 16px" }} disabled={busy === "save"} onClick={salvar}>{busy === "save" ? "Salvando…" : "Salvar"}</button>
        {msg && <span className="txt" style={{ color: "var(--muted)" }}>{msg}</span>}
      </div>
    </section>
  );
}

export default function ChavesApiPage() {
  const [restricted, setRestricted] = useState(false);
  const [loaded, setLoaded] = useState(false);
  useEffect(() => {
    sfetch("/api/me").then((r) => r.json()).then((u) => {
      if (!(u?.role === "operator" || u?.role === "admin")) setRestricted(true);
      setLoaded(true);
    }).catch(() => setLoaded(true));
  }, []);

  if (!loaded) return <p className="sub">Carregando…</p>;
  if (restricted) return (<><h1 className="h1">Chaves API</h1><div className="empty">Acesso restrito a operadores.</div></>);

  return (
    <>
      <h1 className="h1">Chaves API</h1>
      <p className="sub">Todas as chaves dos provedores que o Reachyn usa, organizadas por função. Cifradas em repouso; provedor sem chave aqui usa a padrão do sistema.</p>
      <SaldosSection />
      <SearchSection />
      <GenerationSection />
      <AdminSection
        base="/api/admin/publish-keys"
        title="Publicação"
        subtitle="A publicação nas redes (Instagram, Facebook, LinkedIn, YouTube, X, Threads…) é feita pelo conector social — o motor de publicação social do Reachyn. Uma conta conector social do operador cobre todos os clientes; cada cliente conecta as próprias redes por login (OAuth), sem precisar de chave."
        guide={{
          intro: "Para ligar a publicação, conecte a chave da sua conta conector social (o Reachyn cuida do resto):",
          steps: [
            "Crie uma conta no conector social (o painel do conector).",
            "No painel do conector social, gere uma API Key.",
            "Cole a API Key no campo conector social abaixo e clique em \"Testar conexão\".",
            "Pronto: na aba Conexões, cada cliente conecta as próprias redes por OAuth (login), sem ver a chave.",
          ],
          note: "O conector social não tem programa de indicação — use o site oficial (o painel do conector) para criar a conta. A chave fica cifrada e vale para todos os clientes do operador.",
        }}
      />
    </>
  );
}
