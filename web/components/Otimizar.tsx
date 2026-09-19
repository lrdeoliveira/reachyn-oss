"use client";

// 🚀 OTIMIZAR — F4 da Fábrica de Conteúdo: o PACOTE DE PUBLICAÇÃO. Fecha a ponta do funil (o
// trabalho manual que dava pra fazer no ChatGPT): dado o tema/título do vídeo + a rede, a IA devolve
//  • 5 títulos com NOTA de clique (o de nota 9-10 vence),
//  • descrição SEO (palavra-chave na 1ª linha + CTA),
//  • hashtags (+ tags do YouTube),
//  • 2-3 conceitos de thumbnail (sem rosto, texto ≤4 palavras),
//  • e o melhor horário de publicação por rede (guia próprio).
// Tudo com copiar num clique. Gerar a thumbnail de fato reusa a aba Mídia (copiar o conceito).

import { useState } from "react";
import { Rocket, Wand2, Copy, Check, Flame, Clock } from "lucide-react";
import { sfetch } from "@/lib/api";
import { NET, PLATFORMS } from "@/components/NetworkPreview";

type SeoTitle = { text: string; score: number };
type ThumbConcept = { concept: string; text: string; colors: string };
type Pack = { platform: string; titles: SeoTitle[]; description: string; hashtags: string[]; tags: string[]; thumbnails: ThumbConcept[] };

const inp = { background: "var(--bg2)", color: "var(--text)", border: "1px solid var(--line)", borderRadius: 10, padding: "10px 12px", fontSize: ".95rem" } as const;

// Melhor horário de publicação (guia próprio da Fábrica de Conteúdo; horários de Brasília).
const BEST_TIMES: Record<string, string> = {
  youtube: "Vídeo longo: 17h-20h (ter-qui). Shorts: 11h-13h e 19h-21h.",
  tiktok: "11h-13h e 19h-22h. Picos à noite e fins de semana.",
  instagram: "Reels: 11h-13h e 19h-21h. Feed: horário de almoço e início da noite.",
  facebook: "9h-11h e 13h-15h (dias úteis).",
  linkedin: "8h-10h e 12h (ter-qui, horário comercial).",
  twitter: "8h-9h e 18h-19h (horário de deslocamento).",
  threads: "11h-13h e 19h-21h.",
  pinterest: "20h-23h e fins de semana.",
  reddit: "8h-10h (manhã, fuso do público-alvo).",
  bluesky: "9h-11h e 18h-20h.",
  googlebusiness: "Horário comercial: 9h-17h (dias úteis).",
};

function scoreColor(n: number): string {
  if (n >= 9) return "#e24a31";
  if (n >= 7) return "#e8a44f";
  return "#8a8f98";
}

function CopyBtn({ text, label }: { text: string; label?: string }) {
  const [done, setDone] = useState(false);
  return (
    <button className="btn" onClick={async () => {
      try { await navigator.clipboard.writeText(text); setDone(true); setTimeout(() => setDone(false), 1400); } catch { /* clipboard bloqueado */ }
    }}
      title="Copiar" style={{ display: "inline-flex", alignItems: "center", gap: 5, background: "var(--bg1, rgba(255,255,255,.04))", color: "var(--text)", border: "1px solid var(--line)", fontSize: ".78rem", padding: "5px 9px" }}>
      {done ? <Check size={13} color="#7bd18a" /> : <Copy size={13} />} {done ? "copiado" : (label || "copiar")}
    </button>
  );
}

export function Otimizar() {
  const [topic, setTopic] = useState("");
  const [platform, setPlatform] = useState("youtube");
  const [lang, setLang] = useState<"pt-BR" | "en-US">("pt-BR");
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState("");
  const [pack, setPack] = useState<Pack | null>(null);

  async function gerar() {
    const tp = topic.trim();
    if (!tp) { setMsg("Informe o tema ou título do vídeo primeiro."); return; }
    setBusy(true); setMsg(""); setPack(null);
    try {
      const r = await sfetch("/api/studio/optimize", {
        method: "POST", headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ topic: tp, platform, lang }),
      });
      const j = await r.json().catch(() => ({}));
      if (!r.ok || !j?.ok) {
        setMsg(j?.error || (r.status === 402 ? "Limite do plano atingido para geração de texto." : "Não foi possível gerar o pacote agora."));
        return;
      }
      const p: Pack = j.pack;
      if (p?.titles) p.titles.sort((a, b) => (b.score || 0) - (a.score || 0));
      setPack(p || null);
      if (!p?.titles?.length) setMsg("O modelo não retornou títulos — tente refinar o tema.");
    } catch {
      setMsg("Falha de rede ao gerar o pacote.");
    } finally {
      setBusy(false);
    }
  }

  const box = { background: "var(--bg2)", border: "1px solid var(--line)", borderRadius: 12, padding: 16, marginTop: 14 } as const;
  const h3 = { margin: "0 0 10px", fontSize: "1rem", display: "flex", alignItems: "center", gap: 8 } as const;

  return (
    <div style={{ padding: "8px 4px 60px" }}>
      <div style={{ display: "flex", alignItems: "center", gap: 10, marginBottom: 6 }}>
        <Rocket size={22} color="#e8a44f" />
        <h1 style={{ fontSize: "1.35rem", margin: 0 }}>Otimizar</h1>
        <span style={{ fontSize: ".58rem", fontWeight: 700, letterSpacing: ".03em", textTransform: "uppercase", color: "#e8a48f", background: "rgba(226,74,49,.16)", border: "1px solid rgba(226,74,49,.32)", padding: "2px 7px", borderRadius: 999 }}>novo</span>
      </div>
      <p style={{ color: "var(--muted)", marginTop: 0, fontSize: ".95rem" }}>
        A ponta da publicação: transforme o tema do seu vídeo num <b>pacote pronto pra postar</b> —
        títulos com nota de clique, descrição SEO, hashtags/tags, conceitos de thumbnail e o melhor
        horário. Copie e cole em qualquer rede.
      </p>

      <div style={{ background: "var(--bg1, var(--bg2))", border: "1px solid var(--line)", borderRadius: 14, padding: 16, marginTop: 12 }}>
        <label style={{ fontSize: ".85rem", color: "var(--muted)", display: "block", marginBottom: 6 }}>Tema ou título do vídeo</label>
        <textarea value={topic} onChange={(e) => setTopic(e.target.value)} placeholder="Ex: os 5 impérios que caíram mais rápido da história · como economizar 500 reais por mês sem cortar o que ama"
          style={{ ...inp, width: "100%", minHeight: 66, fontFamily: "inherit", resize: "vertical" }} />
        <div style={{ display: "flex", flexWrap: "wrap", gap: 10, marginTop: 12, alignItems: "flex-end" }}>
          <div>
            <label style={{ fontSize: ".78rem", color: "var(--muted)", display: "block", marginBottom: 4 }}>Rede</label>
            <select value={platform} onChange={(e) => setPlatform(e.target.value)} style={{ ...inp, width: "auto" }}>
              {PLATFORMS.map((p) => <option key={p} value={p}>{NET[p]?.name ?? p}</option>)}
            </select>
          </div>
          <div>
            <label style={{ fontSize: ".78rem", color: "var(--muted)", display: "block", marginBottom: 4 }}>Idioma</label>
            <select value={lang} onChange={(e) => setLang(e.target.value as "pt-BR" | "en-US")} style={{ ...inp, width: "auto" }}>
              <option value="pt-BR">Português</option>
              <option value="en-US">English</option>
            </select>
          </div>
          <button className="btn" onClick={gerar} disabled={busy}
            style={{ display: "flex", alignItems: "center", gap: 7, background: "var(--accent, #e24a31)", color: "#fff", border: "none", opacity: busy ? .6 : 1, cursor: busy ? "wait" : "pointer", marginLeft: "auto" }}>
            <Wand2 size={16} /> {busy ? "Gerando pacote…" : "Gerar pacote"}
          </button>
        </div>
      </div>

      {msg && <p style={{ marginTop: 14, color: "var(--muted)" }}>{msg}</p>}

      {pack && (
        <div>
          {/* Títulos */}
          <div style={box}>
            <h3 style={h3}>🏆 Títulos <span style={{ fontSize: ".8rem", color: "var(--muted)", fontWeight: 400 }}>(o de maior nota tende a vencer)</span></h3>
            <div style={{ display: "flex", flexDirection: "column", gap: 8 }}>
              {pack.titles.map((t, i) => (
                <div key={i} style={{ display: "flex", alignItems: "center", gap: 10, padding: "8px 10px", background: "var(--bg1, rgba(255,255,255,.03))", border: "1px solid var(--line)", borderRadius: 10 }}>
                  <span title="nota de clique (1-10)" style={{ display: "flex", alignItems: "center", gap: 3, fontWeight: 700, color: scoreColor(t.score), minWidth: 34 }}><Flame size={13} />{t.score}</span>
                  <span style={{ flex: 1, fontWeight: 500 }}>{t.text}</span>
                  <CopyBtn text={t.text} />
                </div>
              ))}
            </div>
          </div>

          {/* Descrição */}
          {pack.description && (
            <div style={box}>
              <h3 style={h3}>📝 Descrição <span style={{ marginLeft: "auto" }}><CopyBtn text={pack.description} label="copiar tudo" /></span></h3>
              <div style={{ whiteSpace: "pre-wrap", fontSize: ".92rem", color: "var(--text)", lineHeight: 1.55 }}>{pack.description}</div>
            </div>
          )}

          {/* Hashtags + Tags */}
          {(pack.hashtags?.length > 0 || pack.tags?.length > 0) && (
            <div style={box}>
              {pack.hashtags?.length > 0 && (
                <>
                  <h3 style={h3}># Hashtags <span style={{ marginLeft: "auto" }}><CopyBtn text={pack.hashtags.join(" ")} label="copiar tudo" /></span></h3>
                  <div style={{ display: "flex", flexWrap: "wrap", gap: 6, marginBottom: pack.tags?.length ? 14 : 0 }}>
                    {pack.hashtags.map((h, i) => <span key={i} style={{ fontSize: ".82rem", color: "#8ab4f8", background: "rgba(138,180,248,.12)", border: "1px solid var(--line)", padding: "3px 9px", borderRadius: 999 }}>{h}</span>)}
                  </div>
                </>
              )}
              {pack.tags?.length > 0 && (
                <>
                  <h3 style={h3}>🔖 Tags do YouTube <span style={{ marginLeft: "auto" }}><CopyBtn text={pack.tags.join(", ")} label="copiar tudo" /></span></h3>
                  <div style={{ display: "flex", flexWrap: "wrap", gap: 6 }}>
                    {pack.tags.map((tg, i) => <span key={i} style={{ fontSize: ".82rem", color: "var(--muted)", background: "var(--bg1, rgba(255,255,255,.04))", border: "1px solid var(--line)", padding: "3px 9px", borderRadius: 999 }}>{tg}</span>)}
                  </div>
                </>
              )}
            </div>
          )}

          {/* Thumbnails */}
          {pack.thumbnails?.length > 0 && (
            <div style={box}>
              <h3 style={h3}>🖼️ Conceitos de thumbnail <span style={{ fontSize: ".78rem", color: "var(--muted)", fontWeight: 400 }}>(sem rosto · texto ≤4 palavras · gere a imagem na aba Mídia)</span></h3>
              <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fill, minmax(240px, 1fr))", gap: 10 }}>
                {pack.thumbnails.map((tc, i) => (
                  <div key={i} style={{ background: "var(--bg1, rgba(255,255,255,.03))", border: "1px solid var(--line)", borderRadius: 10, padding: 12, display: "flex", flexDirection: "column", gap: 6 }}>
                    <div style={{ fontSize: ".9rem" }}>{tc.concept}</div>
                    {tc.text && <div style={{ fontSize: ".8rem", color: "var(--muted)" }}><b style={{ color: "var(--text)" }}>Texto:</b> “{tc.text}”</div>}
                    {tc.colors && <div style={{ fontSize: ".8rem", color: "var(--muted)" }}><b style={{ color: "var(--text)" }}>Cores:</b> {tc.colors}</div>}
                    <CopyBtn text={`${tc.concept}${tc.text ? `. Texto na imagem: "${tc.text}"` : ""}${tc.colors ? `. Paleta: ${tc.colors}` : ""}. Sem rosto, alto contraste, vertical 9:16.`} label="copiar como prompt" />
                  </div>
                ))}
              </div>
            </div>
          )}

          {/* Melhor horário */}
          <div style={box}>
            <h3 style={h3}><Clock size={16} /> Melhor horário — {NET[pack.platform]?.name ?? pack.platform}</h3>
            <p style={{ margin: 0, fontSize: ".92rem", color: "var(--text)" }}>{BEST_TIMES[pack.platform] ?? "Publique quando seu público está mais ativo — teste 11h-13h e 19h-21h."}</p>
            <p style={{ margin: "6px 0 0", fontSize: ".78rem", color: "var(--muted)" }}>Horários de Brasília — ajuste ao fuso da sua audiência.</p>
          </div>
        </div>
      )}
    </div>
  );
}
