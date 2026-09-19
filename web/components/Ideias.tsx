"use client";

// 💡 IDEIAS — F1 + F2 da Fábrica de Conteúdo: o TOPO DO FUNIL faceless. Mata o "não sei o que postar".
//  • Gerador de Ideias (F1): nicho → banco de ideias de vídeo viral (título + gatilho + formato +
//    dificuldade + nota). Cada ideia tem "Gerar esse vídeo" → cai no roteiro pré-preenchido.
//  • Formatos Virais (F2): a biblioteca dos formatos consagrados como templates 1-clique.
// Ambos entregam ao Estúdio via seedStudioAndGo (sessionStorage → /estudio).

import { useState } from "react";
import { Lightbulb, Sparkles, Wand2, LayoutGrid, ArrowRight, Flame } from "lucide-react";
import { sfetch } from "@/lib/api";
import { VIRAL_FORMATS, seedStudioAndGo, type Dificuldade } from "@/lib/formats";

type Idea = {
  title: string;
  trigger: string;
  format: string;
  difficulty: string;
  score: number;
  why: string;
};

const inp = { background: "var(--bg2)", color: "var(--text)", border: "1px solid var(--line)", borderRadius: 10, padding: "10px 12px", fontSize: ".95rem" } as const;

const MODES: Array<[string, string, string]> = [
  ["", "Equilibrado", "banco variado, do fácil ao difícil — pra começar hoje"],
  ["dor", "Por dor da audiência", "cada ideia resolve uma dor real do seu público (ouro de CTR)"],
  ["sazonal", "Sazonal (por mês)", "datas comemorativas, estações e eventos do mês escolhido"],
  ["desbloqueio", "Destravar", "formatos e ângulos novos que você provavelmente não tentou"],
  ["maluca", "Ideia maluca", "ousadas, surpreendentes, quebra-padrão (mas viáveis)"],
];

function difClass(d: string): { bg: string; fg: string } {
  const s = d.toLowerCase();
  if (s.startsWith("fác") || s.startsWith("fac")) return { bg: "rgba(52,168,83,.16)", fg: "#7bd18a" };
  if (s.startsWith("dif")) return { bg: "rgba(226,74,49,.16)", fg: "#e8a48f" };
  return { bg: "rgba(234,179,8,.16)", fg: "#e8c766" }; // médio
}

function scoreColor(n: number): string {
  if (n >= 9) return "#e24a31";
  if (n >= 7) return "#e8a44f";
  return "#8a8f98";
}

export function Ideias() {
  const [tab, setTab] = useState<"gerar" | "formatos">("gerar");

  // Gerador (F1)
  const [niche, setNiche] = useState("");
  const [mode, setMode] = useState("");
  const [month, setMonth] = useState("");
  const [lang, setLang] = useState<"pt-BR" | "en-US">("pt-BR");
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState("");
  const [ideas, setIdeas] = useState<Idea[]>([]);

  async function gerar() {
    const n = niche.trim();
    if (!n) { setMsg("Descreva o nicho/tema do seu canal primeiro."); return; }
    setBusy(true); setMsg(""); setIdeas([]);
    try {
      const r = await sfetch("/api/studio/ideas", {
        method: "POST", headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ niche: n, mode, month: mode === "sazonal" ? month : undefined, lang }),
      });
      const j = await r.json().catch(() => ({}));
      if (!r.ok || !j?.ok) {
        setMsg(j?.error || (r.status === 402 ? "Limite do plano atingido para geração de texto." : "Não foi possível gerar ideias agora."));
        return;
      }
      const list: Idea[] = Array.isArray(j.ideas) ? j.ideas : [];
      // Melhores primeiro (o modelo dá a nota crítica; a ordenação ajuda o usuário a decidir).
      list.sort((a, b) => (b.score || 0) - (a.score || 0));
      setIdeas(list);
      if (list.length === 0) setMsg("O modelo não retornou ideias — tente refinar o nicho.");
    } catch {
      setMsg("Falha de rede ao gerar ideias.");
    } finally {
      setBusy(false);
    }
  }

  function usarIdeia(it: Idea) {
    // Semente rica: título + formato + gatilho dão contexto pro Estúdio estruturar as cenas.
    const script = `${it.title}\n\nFormato viral: ${it.format || "livre"}. Gatilho: ${it.trigger || "curiosidade"}.` + (it.why ? `\nPor que funciona: ${it.why}` : "");
    seedStudioAndGo({ script, from: "ideia", aspect: "9:16" });
  }

  function usarFormato(nome: string, descricao: string, estrutura: string) {
    const script = `Vídeo no formato "${nome}" (${descricao}) sobre [SEU TEMA — troque aqui].\n\nEstrutura: ${estrutura}`;
    seedStudioAndGo({ script, from: "formato", aspect: "9:16" });
  }

  return (
    <div style={{ padding: "8px 4px 60px" }}>
      <div style={{ display: "flex", alignItems: "center", gap: 10, marginBottom: 6 }}>
        <Lightbulb size={22} color="#e8a44f" />
        <h1 style={{ fontSize: "1.35rem", margin: 0 }}>Ideias</h1>
        <span style={{ fontSize: ".58rem", fontWeight: 700, letterSpacing: ".03em", textTransform: "uppercase", color: "#e8a48f", background: "rgba(226,74,49,.16)", border: "1px solid rgba(226,74,49,.32)", padding: "2px 7px", borderRadius: 999 }}>novo</span>
      </div>
      <p style={{ color: "var(--muted)", marginTop: 0, fontSize: ".95rem" }}>
        O topo do funil: saia do <em>&quot;não sei o que postar&quot;</em>. Gere um banco de ideias virais pro seu nicho
        ou pegue um formato consagrado — e caia direto no roteiro, num clique.
      </p>

      {/* Abas */}
      <div style={{ display: "flex", gap: 8, margin: "18px 0 16px" }}>
        <button className="btn" onClick={() => setTab("gerar")}
          style={{ display: "flex", alignItems: "center", gap: 7, background: tab === "gerar" ? "var(--accent, #e24a31)" : "var(--bg2)", color: tab === "gerar" ? "#fff" : "var(--text)", border: "1px solid var(--line)" }}>
          <Sparkles size={15} /> Gerador de ideias
        </button>
        <button className="btn" onClick={() => setTab("formatos")}
          style={{ display: "flex", alignItems: "center", gap: 7, background: tab === "formatos" ? "var(--accent, #e24a31)" : "var(--bg2)", color: tab === "formatos" ? "#fff" : "var(--text)", border: "1px solid var(--line)" }}>
          <LayoutGrid size={15} /> Formatos virais <span style={{ opacity: .7 }}>({VIRAL_FORMATS.length})</span>
        </button>
      </div>

      {tab === "gerar" && (
        <div>
          <div style={{ background: "var(--bg1, var(--bg2))", border: "1px solid var(--line)", borderRadius: 14, padding: 16 }}>
            <label style={{ fontSize: ".85rem", color: "var(--muted)", display: "block", marginBottom: 6 }}>Qual é o seu nicho / tema do canal?</label>
            <textarea value={niche} onChange={(e) => setNiche(e.target.value)} placeholder="Ex: curiosidades de história antiga · finanças pessoais pra jovens · receitas fit rápidas · terror urbano brasileiro"
              style={{ ...inp, width: "100%", minHeight: 74, fontFamily: "inherit", resize: "vertical" }} />

            <div style={{ display: "flex", flexWrap: "wrap", gap: 10, marginTop: 12, alignItems: "flex-end" }}>
              <div>
                <label style={{ fontSize: ".78rem", color: "var(--muted)", display: "block", marginBottom: 4 }}>Ângulo</label>
                <select value={mode} onChange={(e) => setMode(e.target.value)} style={{ ...inp, width: "auto" }}>
                  {MODES.map(([v, label]) => <option key={v} value={v}>{label}</option>)}
                </select>
              </div>
              {mode === "sazonal" && (
                <div>
                  <label style={{ fontSize: ".78rem", color: "var(--muted)", display: "block", marginBottom: 4 }}>Mês / época</label>
                  <input value={month} onChange={(e) => setMonth(e.target.value)} placeholder="dezembro / Natal" style={{ ...inp, width: 160 }} />
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
                <Wand2 size={16} /> {busy ? "Gerando ideias…" : "Gerar ideias"}
              </button>
            </div>
            <p style={{ fontSize: ".78rem", color: "var(--muted)", margin: "10px 0 0" }}>{MODES.find(([v]) => v === mode)?.[2]}</p>
          </div>

          {msg && <p style={{ marginTop: 14, color: "var(--muted)" }}>{msg}</p>}

          {ideas.length > 0 && (
            <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fill, minmax(300px, 1fr))", gap: 12, marginTop: 18 }}>
              {ideas.map((it, i) => {
                const dc = difClass(it.difficulty);
                return (
                  <div key={i} style={{ background: "var(--bg2)", border: "1px solid var(--line)", borderRadius: 12, padding: 14, display: "flex", flexDirection: "column", gap: 8 }}>
                    <div style={{ display: "flex", alignItems: "center", gap: 6, flexWrap: "wrap" }}>
                      {it.format && <span style={{ fontSize: ".68rem", fontWeight: 600, color: "var(--muted)", background: "var(--bg1, rgba(255,255,255,.04))", border: "1px solid var(--line)", padding: "2px 8px", borderRadius: 999 }}>{it.format}</span>}
                      <span style={{ fontSize: ".68rem", fontWeight: 600, color: dc.fg, background: dc.bg, padding: "2px 8px", borderRadius: 999 }}>{it.difficulty}</span>
                      <span title="potencial viral (1-10)" style={{ marginLeft: "auto", display: "flex", alignItems: "center", gap: 3, fontWeight: 700, color: scoreColor(it.score) }}>
                        <Flame size={13} /> {it.score}
                      </span>
                    </div>
                    <div style={{ fontWeight: 600, fontSize: "1rem", lineHeight: 1.3 }}>{it.title}</div>
                    {it.trigger && <div style={{ fontSize: ".82rem", color: "var(--muted)" }}><b style={{ color: "var(--text)" }}>Gatilho:</b> {it.trigger}</div>}
                    {it.why && <div style={{ fontSize: ".82rem", color: "var(--muted)" }}>{it.why}</div>}
                    <button className="btn" onClick={() => usarIdeia(it)}
                      style={{ marginTop: "auto", display: "flex", alignItems: "center", justifyContent: "center", gap: 6, background: "var(--accent, #e24a31)", color: "#fff", border: "none" }}>
                      Gerar esse vídeo <ArrowRight size={15} />
                    </button>
                  </div>
                );
              })}
            </div>
          )}
        </div>
      )}

      {tab === "formatos" && (
        <div>
          <p style={{ color: "var(--muted)", fontSize: ".9rem", marginTop: 0 }}>
            {VIRAL_FORMATS.length} formatos consagrados de vídeo viral faceless. Cada um traz a estrutura
            (gancho → clímax → CTA), o gatilho e a duração ideal. Clique em <b>Usar este formato</b> pra
            cair no Estúdio com o roteiro-semente pronto — é só trocar pelo seu tema.
          </p>
          <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fill, minmax(320px, 1fr))", gap: 12, marginTop: 16 }}>
            {VIRAL_FORMATS.map((f) => {
              const dc = difClass(f.dificuldade as Dificuldade);
              return (
                <div key={f.nome} style={{ background: "var(--bg2)", border: "1px solid var(--line)", borderRadius: 12, padding: 14, display: "flex", flexDirection: "column", gap: 7 }}>
                  <div style={{ display: "flex", alignItems: "center", gap: 6 }}>
                    <div style={{ fontWeight: 600, fontSize: ".98rem", flex: 1, lineHeight: 1.25 }}>{f.nome}</div>
                    <span style={{ fontSize: ".66rem", fontWeight: 600, color: dc.fg, background: dc.bg, padding: "2px 7px", borderRadius: 999, whiteSpace: "nowrap" }}>{f.dificuldade}</span>
                  </div>
                  <div style={{ fontSize: ".82rem", color: "var(--muted)" }}>{f.descricao}</div>
                  <div style={{ fontSize: ".78rem", color: "var(--muted)" }}><b style={{ color: "var(--text)" }}>Estrutura:</b> {f.estrutura}</div>
                  <div style={{ display: "flex", gap: 12, fontSize: ".76rem", color: "var(--muted)", flexWrap: "wrap" }}>
                    <span><b style={{ color: "var(--text)" }}>Gatilho:</b> {f.gatilho}</span>
                    <span><b style={{ color: "var(--text)" }}>Duração:</b> {f.duracao}</span>
                  </div>
                  <button className="btn" onClick={() => usarFormato(f.nome, f.descricao, f.estrutura)}
                    style={{ marginTop: "auto", display: "flex", alignItems: "center", justifyContent: "center", gap: 6, background: "var(--bg1, rgba(255,255,255,.04))", color: "var(--text)", border: "1px solid var(--line)" }}>
                    Usar este formato <ArrowRight size={15} />
                  </button>
                </div>
              );
            })}
          </div>
        </div>
      )}
    </div>
  );
}
