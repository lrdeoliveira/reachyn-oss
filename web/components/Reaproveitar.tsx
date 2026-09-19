"use client";

// ♻️ REAPROVEITAR — F6 da Fábrica de Conteúdo: TRANSFORMAÇÃO DE FORMATO ("1 vira 10"). Cole um
// conteúdo longo (roteiro, transcrição ou tema detalhado) e a IA multiplica em uma semana de posts:
// 5 Shorts (com o trecho a cortar + gancho), 1 carrossel, 1 thread (X) e 1 pin. "Posta todo dia
// sem produzir todo dia." Tudo com copiar num clique; a mídia já gerada pode ser reusada.

import { useState } from "react";
import { Recycle, Wand2, Copy, Check, Scissors, LayoutList, MessageSquare, Pin } from "lucide-react";
import { sfetch } from "@/lib/api";

type ShortCut = { title: string; timestamp: string; hook: string };
type Pack = {
  shorts: ShortCut[];
  carousel: { title: string; slides: string[] };
  thread: string[];
  pin: string;
};

const inp = { background: "var(--bg2)", color: "var(--text)", border: "1px solid var(--line)", borderRadius: 10, padding: "10px 12px", fontSize: ".95rem" } as const;

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

export function Reaproveitar() {
  const [source, setSource] = useState("");
  const [lang, setLang] = useState<"pt-BR" | "en-US">("pt-BR");
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState("");
  const [pack, setPack] = useState<Pack | null>(null);

  async function gerar() {
    const src = source.trim();
    if (src.length < 30) { setMsg("Cole o conteúdo longo (roteiro, transcrição ou tema detalhado) — pelo menos algumas frases."); return; }
    setBusy(true); setMsg(""); setPack(null);
    try {
      const r = await sfetch("/api/studio/repurpose", {
        method: "POST", headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ source: src, lang }),
      });
      const j = await r.json().catch(() => ({}));
      if (!r.ok || !j?.ok) {
        setMsg(j?.error || (r.status === 402 ? "Limite do plano atingido para geração de texto." : "Não foi possível reaproveitar agora."));
        return;
      }
      setPack(j.pack || null);
      if (!j.pack?.shorts?.length && !j.pack?.carousel?.slides?.length) setMsg("O modelo não achou material aproveitável — tente colar mais conteúdo.");
    } catch {
      setMsg("Falha de rede ao reaproveitar.");
    } finally {
      setBusy(false);
    }
  }

  const box = { background: "var(--bg2)", border: "1px solid var(--line)", borderRadius: 12, padding: 16, marginTop: 14 } as const;
  const h3 = { margin: "0 0 10px", fontSize: "1rem", display: "flex", alignItems: "center", gap: 8 } as const;

  return (
    <div style={{ padding: "8px 4px 60px" }}>
      <div style={{ display: "flex", alignItems: "center", gap: 10, marginBottom: 6 }}>
        <Recycle size={22} color="#7bd18a" />
        <h1 style={{ fontSize: "1.35rem", margin: 0 }}>Reaproveitar</h1>
        <span style={{ fontSize: ".58rem", fontWeight: 700, letterSpacing: ".03em", textTransform: "uppercase", color: "#e8a48f", background: "rgba(226,74,49,.16)", border: "1px solid rgba(226,74,49,.32)", padding: "2px 7px", borderRadius: 999 }}>novo</span>
      </div>
      <p style={{ color: "var(--muted)", marginTop: 0, fontSize: ".95rem" }}>
        <b>Um vira dez.</b> Cole um conteúdo longo (roteiro, transcrição ou tema detalhado) e receba
        uma semana de posts: 5 Shorts com o trecho a cortar, 1 carrossel, 1 thread e 1 pin.
        <em> Poste todo dia sem produzir todo dia.</em>
      </p>

      <div style={{ background: "var(--bg1, var(--bg2))", border: "1px solid var(--line)", borderRadius: 14, padding: 16, marginTop: 12 }}>
        <label style={{ fontSize: ".85rem", color: "var(--muted)", display: "block", marginBottom: 6 }}>Conteúdo longo</label>
        <textarea value={source} onChange={(e) => setSource(e.target.value)} placeholder="Cole aqui o roteiro do seu vídeo longo, a transcrição de uma live/podcast, ou um tema desenvolvido em vários parágrafos…"
          style={{ ...inp, width: "100%", minHeight: 160, fontFamily: "inherit", resize: "vertical" }} />
        <div style={{ display: "flex", flexWrap: "wrap", gap: 10, marginTop: 12, alignItems: "flex-end" }}>
          <div>
            <label style={{ fontSize: ".78rem", color: "var(--muted)", display: "block", marginBottom: 4 }}>Idioma</label>
            <select value={lang} onChange={(e) => setLang(e.target.value as "pt-BR" | "en-US")} style={{ ...inp, width: "auto" }}>
              <option value="pt-BR">Português</option>
              <option value="en-US">English</option>
            </select>
          </div>
          <button className="btn" onClick={gerar} disabled={busy}
            style={{ display: "flex", alignItems: "center", gap: 7, background: "var(--accent, #e24a31)", color: "#fff", border: "none", opacity: busy ? .6 : 1, cursor: busy ? "wait" : "pointer", marginLeft: "auto" }}>
            <Wand2 size={16} /> {busy ? "Reaproveitando…" : "Reaproveitar"}
          </button>
        </div>
      </div>

      {msg && <p style={{ marginTop: 14, color: "var(--muted)" }}>{msg}</p>}

      {pack && (
        <div>
          {/* Shorts */}
          {pack.shorts?.length > 0 && (
            <div style={box}>
              <h3 style={h3}><Scissors size={16} /> 5 Shorts <span style={{ fontSize: ".8rem", color: "var(--muted)", fontWeight: 400 }}>(o trecho a cortar + o gancho de 3s)</span></h3>
              <div style={{ display: "flex", flexDirection: "column", gap: 8 }}>
                {pack.shorts.map((sc, i) => (
                  <div key={i} style={{ padding: "10px 12px", background: "var(--bg1, rgba(255,255,255,.03))", border: "1px solid var(--line)", borderRadius: 10 }}>
                    <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
                      <span style={{ fontWeight: 600, flex: 1 }}>{sc.title}</span>
                      {sc.timestamp && <span style={{ fontSize: ".76rem", color: "var(--muted)", fontFamily: "var(--mono, monospace)", background: "var(--bg2)", border: "1px solid var(--line)", padding: "2px 7px", borderRadius: 6, whiteSpace: "nowrap" }}>{sc.timestamp}</span>}
                    </div>
                    {sc.hook && <div style={{ fontSize: ".85rem", color: "var(--muted)", marginTop: 5 }}><b style={{ color: "var(--text)" }}>Gancho:</b> {sc.hook}</div>}
                    <div style={{ marginTop: 7 }}><CopyBtn text={`${sc.title}${sc.hook ? `\n\n${sc.hook}` : ""}${sc.timestamp ? `\n\n(trecho: ${sc.timestamp})` : ""}`} /></div>
                  </div>
                ))}
              </div>
            </div>
          )}

          {/* Carrossel */}
          {pack.carousel?.slides?.length > 0 && (
            <div style={box}>
              <h3 style={h3}><LayoutList size={16} /> Carrossel {pack.carousel.title ? `— ${pack.carousel.title}` : ""} <span style={{ marginLeft: "auto" }}><CopyBtn text={[pack.carousel.title, ...pack.carousel.slides].filter(Boolean).join("\n\n")} label="copiar tudo" /></span></h3>
              <ol style={{ margin: 0, paddingLeft: 22, display: "flex", flexDirection: "column", gap: 6 }}>
                {pack.carousel.slides.map((sl, i) => <li key={i} style={{ fontSize: ".92rem", lineHeight: 1.5 }}>{sl}</li>)}
              </ol>
            </div>
          )}

          {/* Thread */}
          {pack.thread?.length > 0 && (
            <div style={box}>
              <h3 style={h3}><MessageSquare size={16} /> Thread <span style={{ marginLeft: "auto" }}><CopyBtn text={pack.thread.map((tw, i) => `${i + 1}/ ${tw}`).join("\n\n")} label="copiar tudo" /></span></h3>
              <div style={{ display: "flex", flexDirection: "column", gap: 8 }}>
                {pack.thread.map((tw, i) => (
                  <div key={i} style={{ display: "flex", gap: 10, padding: "8px 10px", background: "var(--bg1, rgba(255,255,255,.03))", border: "1px solid var(--line)", borderRadius: 10 }}>
                    <span style={{ color: "var(--muted)", fontWeight: 700, minWidth: 24 }}>{i + 1}/</span>
                    <span style={{ flex: 1, fontSize: ".9rem", lineHeight: 1.5 }}>{tw}</span>
                  </div>
                ))}
              </div>
            </div>
          )}

          {/* Pin */}
          {pack.pin && (
            <div style={box}>
              <h3 style={h3}><Pin size={16} /> Pin (post fixado) <span style={{ marginLeft: "auto" }}><CopyBtn text={pack.pin} /></span></h3>
              <div style={{ whiteSpace: "pre-wrap", fontSize: ".92rem", lineHeight: 1.55 }}>{pack.pin}</div>
            </div>
          )}
        </div>
      )}
    </div>
  );
}
