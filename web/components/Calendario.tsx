"use client";

// 🗓️ CALENDÁRIO — F5 da Fábrica de Conteúdo: recorrência (o usuário volta todo dia). Dois modos:
//  • Calendário: plano de 30 dias (dia + título + formato + prioridade + dias de descanso).
//  • Série: N episódios com dificuldade crescente + estratégia de teaser entre eles.
// Cada dia/episódio tem "Gerar" → cai no roteiro pré-preenchido do Estúdio (mesmo handoff da aba Ideias).

import { useState } from "react";
import { CalendarDays, Wand2, ArrowRight, Coffee, Flame } from "lucide-react";
import { sfetch } from "@/lib/api";
import { seedStudioAndGo } from "@/lib/formats";

type CalendarDay = { day: number; title: string; format: string; priority: string; rest: boolean };
type Episode = { ep: number; title: string; hook: string; difficulty: string };
type Plan = { mode: string; days?: CalendarDay[]; series?: { name: string; episodes: Episode[]; teaser: string } };

const inp = { background: "var(--bg2)", color: "var(--text)", border: "1px solid var(--line)", borderRadius: 10, padding: "10px 12px", fontSize: ".95rem" } as const;

function prioColor(p: string): { bg: string; fg: string } {
  const s = p.toLowerCase();
  if (s === "alta") return { bg: "rgba(226,74,49,.16)", fg: "#e8a48f" };
  if (s === "baixa") return { bg: "rgba(138,143,152,.16)", fg: "#a7acb5" };
  return { bg: "rgba(234,179,8,.16)", fg: "#e8c766" }; // média
}

export function Calendario() {
  const [niche, setNiche] = useState("");
  const [mode, setMode] = useState<"mes" | "serie">("mes");
  const [count, setCount] = useState(10); // nº de episódios (modo série)
  const [lang, setLang] = useState<"pt-BR" | "en-US">("pt-BR");
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState("");
  const [plan, setPlan] = useState<Plan | null>(null);

  async function gerar() {
    const n = niche.trim();
    if (!n) { setMsg("Descreva o nicho/tema do seu canal primeiro."); return; }
    setBusy(true); setMsg(""); setPlan(null);
    try {
      const r = await sfetch("/api/studio/calendar", {
        method: "POST", headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ niche: n, mode, days: mode === "serie" ? count : 30, lang }),
      });
      const j = await r.json().catch(() => ({}));
      if (!r.ok || !j?.ok) {
        setMsg(j?.error || (r.status === 402 ? "Limite do plano atingido para geração de texto." : "Não foi possível montar o plano agora."));
        return;
      }
      setPlan(j.plan || null);
      if (!j.plan?.days?.length && !j.plan?.series?.episodes?.length) setMsg("O modelo não retornou um plano — tente refinar o nicho.");
    } catch {
      setMsg("Falha de rede ao montar o plano.");
    } finally {
      setBusy(false);
    }
  }

  function usar(title: string, format: string, hook?: string) {
    const script = `${title}${format ? `\n\nFormato viral: ${format}.` : ""}${hook ? `\nGancho: ${hook}` : ""}`;
    seedStudioAndGo({ script, from: "calendario", aspect: "9:16" });
  }

  return (
    <div style={{ padding: "8px 4px 60px" }}>
      <div style={{ display: "flex", alignItems: "center", gap: 10, marginBottom: 6 }}>
        <CalendarDays size={22} color="#8ab4f8" />
        <h1 style={{ fontSize: "1.35rem", margin: 0 }}>Calendário</h1>
        <span style={{ fontSize: ".58rem", fontWeight: 700, letterSpacing: ".03em", textTransform: "uppercase", color: "#e8a48f", background: "rgba(226,74,49,.16)", border: "1px solid rgba(226,74,49,.32)", padding: "2px 7px", borderRadius: 999 }}>novo</span>
      </div>
      <p style={{ color: "var(--muted)", marginTop: 0, fontSize: ".95rem" }}>
        Recorrência sem esforço: um <b>plano de 30 dias</b> pronto (com dias de descanso) ou uma
        <b> série</b> que fideliza. Cada item vira um vídeo num clique.
      </p>

      <div style={{ background: "var(--bg1, var(--bg2))", border: "1px solid var(--line)", borderRadius: 14, padding: 16, marginTop: 12 }}>
        <label style={{ fontSize: ".85rem", color: "var(--muted)", display: "block", marginBottom: 6 }}>Nicho / tema do canal</label>
        <textarea value={niche} onChange={(e) => setNiche(e.target.value)} placeholder="Ex: finanças pessoais pra jovens · curiosidades de ciência · receitas fit"
          style={{ ...inp, width: "100%", minHeight: 60, fontFamily: "inherit", resize: "vertical" }} />
        <div style={{ display: "flex", flexWrap: "wrap", gap: 10, marginTop: 12, alignItems: "flex-end" }}>
          <div>
            <label style={{ fontSize: ".78rem", color: "var(--muted)", display: "block", marginBottom: 4 }}>Tipo</label>
            <select value={mode} onChange={(e) => setMode(e.target.value as "mes" | "serie")} style={{ ...inp, width: "auto" }}>
              <option value="mes">Calendário de 30 dias</option>
              <option value="serie">Série de episódios</option>
            </select>
          </div>
          {mode === "serie" && (
            <div>
              <label style={{ fontSize: ".78rem", color: "var(--muted)", display: "block", marginBottom: 4 }}>Episódios</label>
              <input type="number" min={3} max={20} value={count} onChange={(e) => setCount(Math.max(3, Math.min(20, Number(e.target.value) || 10)))} style={{ ...inp, width: 90 }} />
            </div>
          )}
          <div>
            <label style={{ fontSize: ".78rem", color: "var(--muted)", display: "block", marginBottom: 4 }}>Idioma</label>
            <select value={lang} onChange={(e) => setLang(e.target.value as "pt-BR" | "en-US")} style={{ ...inp, width: "auto" }}>
              <option value="pt-BR">Português</option>
              <option value="en-US">English</option>
            </select>
          </div>
          <button className="btn" onClick={gerar} disabled={busy}
            style={{ display: "flex", alignItems: "center", gap: 7, background: "var(--accent, #e24a31)", color: "#fff", border: "none", opacity: busy ? .6 : 1, cursor: busy ? "wait" : "pointer", marginLeft: "auto" }}>
            <Wand2 size={16} /> {busy ? "Montando…" : "Montar plano"}
          </button>
        </div>
      </div>

      {msg && <p style={{ marginTop: 14, color: "var(--muted)" }}>{msg}</p>}

      {/* Calendário de 30 dias */}
      {plan?.mode === "mes" && plan.days && plan.days.length > 0 && (
        <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fill, minmax(240px, 1fr))", gap: 10, marginTop: 18 }}>
          {plan.days.map((d) => {
            const pc = prioColor(d.priority);
            if (d.rest) return (
              <div key={d.day} style={{ background: "transparent", border: "1px dashed var(--line)", borderRadius: 12, padding: 14, display: "flex", alignItems: "center", gap: 8, color: "var(--muted)" }}>
                <span style={{ fontWeight: 700, opacity: .6 }}>Dia {d.day}</span>
                <Coffee size={15} /> Descanso
              </div>
            );
            return (
              <div key={d.day} style={{ background: "var(--bg2)", border: "1px solid var(--line)", borderRadius: 12, padding: 14, display: "flex", flexDirection: "column", gap: 7 }}>
                <div style={{ display: "flex", alignItems: "center", gap: 6 }}>
                  <span style={{ fontWeight: 700, color: "var(--muted)" }}>Dia {d.day}</span>
                  <span style={{ marginLeft: "auto", fontSize: ".66rem", fontWeight: 600, color: pc.fg, background: pc.bg, padding: "2px 7px", borderRadius: 999 }}>{d.priority}</span>
                </div>
                <div style={{ fontWeight: 600, lineHeight: 1.3 }}>{d.title}</div>
                {d.format && <div style={{ fontSize: ".76rem", color: "var(--muted)" }}>{d.format}</div>}
                <button className="btn" onClick={() => usar(d.title, d.format)}
                  style={{ marginTop: "auto", display: "flex", alignItems: "center", justifyContent: "center", gap: 6, background: "var(--bg1, rgba(255,255,255,.04))", color: "var(--text)", border: "1px solid var(--line)", fontSize: ".8rem" }}>
                  Gerar <ArrowRight size={14} />
                </button>
              </div>
            );
          })}
        </div>
      )}

      {/* Série de episódios */}
      {plan?.mode === "serie" && plan.series && plan.series.episodes.length > 0 && (
        <div style={{ marginTop: 18 }}>
          {plan.series.name && <h2 style={{ fontSize: "1.1rem", margin: "0 0 4px" }}>📺 {plan.series.name}</h2>}
          {plan.series.teaser && <p style={{ color: "var(--muted)", fontSize: ".88rem", marginTop: 0 }}><b style={{ color: "var(--text)" }}>Estratégia de teaser:</b> {plan.series.teaser}</p>}
          <div style={{ display: "flex", flexDirection: "column", gap: 8, marginTop: 10 }}>
            {plan.series.episodes.map((ep) => (
              <div key={ep.ep} style={{ display: "flex", alignItems: "center", gap: 12, padding: "10px 12px", background: "var(--bg2)", border: "1px solid var(--line)", borderRadius: 10 }}>
                <span style={{ fontWeight: 700, color: "#8ab4f8", minWidth: 34 }}>#{ep.ep}</span>
                <div style={{ flex: 1 }}>
                  <div style={{ fontWeight: 600 }}>{ep.title}</div>
                  {ep.hook && <div style={{ fontSize: ".82rem", color: "var(--muted)" }}>{ep.hook}</div>}
                </div>
                {ep.difficulty && <span title="dificuldade" style={{ display: "flex", alignItems: "center", gap: 4, fontSize: ".76rem", color: "var(--muted)" }}><Flame size={12} />{ep.difficulty}</span>}
                <button className="btn" onClick={() => usar(ep.title, "", ep.hook)}
                  style={{ display: "flex", alignItems: "center", gap: 6, background: "var(--bg1, rgba(255,255,255,.04))", color: "var(--text)", border: "1px solid var(--line)", fontSize: ".8rem" }}>
                  Gerar <ArrowRight size={14} />
                </button>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}
