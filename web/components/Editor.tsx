"use client";

import { useEffect, useState } from "react";
import { RichTextArea } from "@/components/RichTextArea";
import { NET, PLATFORMS, netLimit } from "@/components/NetworkPreview";
import { sfetch } from "@/lib/api";

// Editor de conteúdo dedicado (abre ao gerar texto pras redes). Foco em ESCREVER:
// editor rico por plataforma + contador/limite + galeria de mídia + salvar. A
// pré-visualização "como fica na rede" mora no passo Aprovar (revisão final).
// Opera sobre o rascunho ativo (mesmo localStorage do wizard).
const KEY = "reachyn_draft";
type Media = { id: string; kind: string; url: string };

export function Editor() {
  const [draftId, setDraftId] = useState<string | null>(null);
  const [keyword, setKeyword] = useState("");
  const [platforms, setPlatforms] = useState<string[]>(["linkedin", "instagram"]);
  const [activeNet, setActiveNet] = useState<string>("linkedin"); // aba de rede aberta
  const [texts, setTexts] = useState<Record<string, string>>({});
  const [media, setMedia] = useState<Media[]>([]);
  const [busy, setBusy] = useState<string | null>(null);
  const [msg, setMsg] = useState<string | null>(null);
  const [loaded, setLoaded] = useState(false);

  useEffect(() => {
    const id = typeof window !== "undefined" ? localStorage.getItem(KEY) : null;
    if (!id) { setLoaded(true); return; }
    sfetch(`/api/studio/draft?id=${id}`).then((r) => r.json()).then((d) => {
      if (d.ok) {
        setDraftId(d.draft.id); setKeyword(d.draft.keyword || "");
        setTexts(d.draft.texts || {});
        setMedia(d.draft.media || []);
        const ps = Object.keys(d.draft.texts || {});
        if (ps.length) { setPlatforms(ps); setActiveNet(ps[0]); }
      } else localStorage.removeItem(KEY);
      setLoaded(true);
    }).catch(() => setLoaded(true));
  }, []);

  // Abre a aba de uma rede vinda do "✏️ Editar" da etapa Aprovar (?net=linkedin):
  // ativa a aba dela (adicionando ao conjunto se ainda não estiver).
  useEffect(() => {
    if (!loaded) return;
    const net = new URLSearchParams(window.location.search).get("net");
    if (net && NET[net]) { setActiveNet(net); setPlatforms((a) => (a.includes(net) ? a : [...a, net])); }
  }, [loaded]);

  // Aba ativa efetiva: se a selecionada saiu do conjunto, cai na primeira (derivado, sem effect).
  const curNet = platforms.includes(activeNet) ? activeNet : (platforms[0] || "");

  const go = (path: string) => { window.location.href = path; };

  async function gerarTexto(p: string) {
    if (!draftId) return;
    setBusy("t-" + p); setMsg(null);
    const r = await sfetch("/api/studio/text", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ draftId, platform: p }) });
    const d = await r.json(); setBusy(null);
    if (d.ok) setTexts((t) => ({ ...t, [p]: d.post }));
    else setMsg("❌ " + (d.error || "geração falhou"));
  }
  // Edição: atualiza local + autosave no servidor (PATCH por plataforma).
  function editar(p: string, text: string) {
    setTexts((t) => ({ ...t, [p]: text }));
    sfetch("/api/studio/text", { method: "PATCH", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ draftId, platform: p, text }) }).catch(() => {});
  }
  // Salvar explícito: persiste todos os textos das plataformas ativas + feedback.
  async function salvar() {
    if (!draftId) return;
    setBusy("save"); setMsg(null);
    try {
      await Promise.all(platforms.map((p) =>
        sfetch("/api/studio/text", { method: "PATCH", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ draftId, platform: p, text: texts[p] || "" }) })
      ));
      setMsg("✅ Rascunho salvo.");
    } catch { setMsg("❌ Não consegui salvar agora."); }
    setBusy(null);
  }
  function novo() { localStorage.removeItem(KEY); go("/"); }

  const card = { background: "var(--panel)", border: "1px solid var(--line)", borderRadius: 12, padding: 16 } as const;

  if (!loaded) return <p className="sub">Carregando rascunho...</p>;
  if (!draftId) return (
    <>
      <h1 className="h1">Conteúdo</h1>
      <div className="empty">Nenhum rascunho ativo. Comece em <a href="/" style={{ color: "var(--peach)" }}>Pesquisar</a> — ou use o botão <strong>&quot;Criar sem pesquisa&quot;</strong> lá pra ir direto pro editor.</div>
    </>
  );

  return (
    <>
      <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center" }}>
        <h1 className="h1">Conteúdo{keyword ? <span style={{ color: "var(--muted)", fontWeight: 400, fontSize: "1rem" }}> · {keyword}</span> : ""}</h1>
        <div style={{ display: "flex", gap: 8 }}>
          <button className="btn ok" style={{ flex: "none", padding: "7px 14px" }} disabled={busy === "save"} onClick={salvar}>{busy === "save" ? "Salvando..." : "💾 Salvar rascunho"}</button>
          <button className="btn edit" style={{ flex: "none", padding: "7px 13px" }} onClick={novo}>+ Novo</button>
        </div>
      </div>

      {/* O resumo/referência foi movido pra a aba Resumo: tirar o textarea grande do topo
          acabou com o salto de scroll que acontecia a cada troca de aba/geração aqui. */}
      <p className="txt" style={{ color: "var(--muted)", fontSize: ".82rem", marginTop: 14 }}>
        📝 O <strong>resumo/referência</strong> (base pra IA gerar o texto e os prompts de mídia) agora fica na aba <a href="/resumo" style={{ color: "var(--peach)" }}>Resumo</a>.
      </p>

      {/* Abas de rede: clicar navega (ou adiciona ao conjunto, se ainda não estiver).
          A bolinha ● marca as redes que já têm texto. Só o editor da aba ativa aparece,
          pra a página não ficar extensa. */}
      <div style={{ display: "flex", gap: 6, flexWrap: "wrap", margin: "14px 0 4px" }}>
        {PLATFORMS.map((p) => {
          const on = platforms.includes(p);
          const active = on && p === curNet;
          const hasText = !!(texts[p] && texts[p].trim());
          return (
            <button key={p} title={on ? "Editar " + NET[p].name : "Adicionar " + NET[p].name}
              onClick={() => { if (on) setActiveNet(p); else { setPlatforms((a) => [...a, p]); setActiveNet(p); } }}
              style={{ padding: "5px 12px", borderRadius: 16, fontSize: ".8rem", cursor: "pointer", fontWeight: active ? 700 : 400,
                border: "1px solid " + (on ? NET[p].color : "var(--line)"),
                background: active ? NET[p].color : on ? NET[p].color + "22" : "var(--bg2)",
                color: active ? "#fff" : on ? "var(--text)" : "var(--muted)" }}>
              {hasText ? "● " : ""}{NET[p].name}
            </button>
          );
        })}
      </div>

      {/* Editor da rede ATIVA (uma por vez — as abas acima trocam) */}
      <div style={{ display: "flex", flexDirection: "column", gap: 14, marginTop: 10 }}>
        {platforms.length === 0 ? (
          <div className="empty">Selecione ao menos uma rede acima.</div>
        ) : curNet && (
          <div id={"net-" + curNet} style={{ ...card, borderLeft: `3px solid ${NET[curNet].color}` }}>
            <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: 8, gap: 8 }}>
              <strong style={{ color: NET[curNet].color }}>{NET[curNet].name}</strong>
              <div style={{ display: "flex", gap: 8 }}>
                <button className="btn edit" style={{ flex: "none", padding: "6px 12px" }} disabled={busy === "t-" + curNet} onClick={() => gerarTexto(curNet)}>{busy === "t-" + curNet ? "Gerando..." : texts[curNet] ? "Regerar IA" : "Gerar texto IA"}</button>
                <button className="btn no" style={{ flex: "none", padding: "6px 11px" }} title={"Remover " + NET[curNet].name + " do conjunto"} onClick={() => { const rest = platforms.filter((x) => x !== curNet); setPlatforms(rest); setActiveNet(rest[0] || ""); }}>✕</button>
              </div>
            </div>
            <RichTextArea value={texts[curNet] || ""} onChange={(v) => editar(curNet, v)} placeholder="Gere com IA ou escreva aqui..." limit={netLimit(curNet)} />
          </div>
        )}

        {/* Mídia de referência (a pré-visualização "como fica na rede" mora no Aprovar) */}
        {media.length > 0 && (
          <div style={card}>
            <strong style={{ fontSize: ".9rem" }}>Mídia ({media.length})</strong>
            <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fill,minmax(90px,1fr))", gap: 6, marginTop: 8 }}>
              {media.map((m) => m.kind === "image"
                ? <img key={m.id} src={m.url} alt="" style={{ width: "100%", borderRadius: 6, display: "block" }} />
                : <video key={m.id} src={m.url} style={{ width: "100%", borderRadius: 6 }} />)}
            </div>
            <a href="/midia" className="txt" style={{ color: "var(--peach)", fontSize: ".8rem", display: "inline-block", marginTop: 8 }}>Gerenciar mídia →</a>
          </div>
        )}

        <div style={{ display: "flex", gap: 10, flexWrap: "wrap" }}>
          <button className="btn ok" style={{ flex: "none", padding: "10px 18px" }} onClick={() => go("/midia")}>Ir pra Mídia →</button>
          <button className="btn edit" style={{ flex: "none", padding: "10px 18px" }} onClick={() => go("/aprovar")}>👁 Revisar e aprovar →</button>
        </div>
      </div>

      {msg && <p className="txt" style={{ marginTop: 16, fontSize: "1rem" }}>{msg}</p>}
    </>
  );
}
