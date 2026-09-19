"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { RichTextArea } from "@/components/RichTextArea";
import { NET, PLATFORMS, netLimit } from "@/components/NetworkPreview";
import { sfetch } from "@/lib/api";
import { TextModelSelect } from "@/components/TextModelSelect";

// Editor de conteúdo dedicado (abre ao gerar texto pras redes). Foco em ESCREVER:
// editor rico por plataforma + contador/limite + galeria de mídia + salvar. A
// pré-visualização "como fica na rede" mora no passo Aprovar (revisão final).
// Opera sobre o rascunho ativo (mesmo localStorage do wizard).
const KEY = "reachyn_draft";
type Media = { id: string; kind: string; url: string };

export function Editor() {
  const router = useRouter();
  const [draftId, setDraftId] = useState<string | null>(null);
  const [keyword, setKeyword] = useState("");
  // Nenhuma rede pré-marcada — o usuário escolhe entre as CONECTADAS (aba Conexões).
  const [platforms, setPlatforms] = useState<string[]>([]);
  const [activeNet, setActiveNet] = useState<string>(""); // aba de rede aberta
  // Redes conectadas do tenant (Conexões/conector social): só elas ficam habilitadas pra gerar conteúdo.
  const [connected, setConnected] = useState<string[]>([]);
  const [connLoaded, setConnLoaded] = useState(false);
  const [texts, setTexts] = useState<Record<string, string>>({});
  // #3 idioma por rede: 'pt-BR' | 'en-US' por plataforma + padrão da conta (vem de /api/usage).
  const [textLangs, setTextLangs] = useState<Record<string, string>>({});
  const [defaultLang, setDefaultLang] = useState("pt-BR");
  const [media, setMedia] = useState<Media[]>([]);
  const [busy, setBusy] = useState<string | null>(null);
  const [textModel, setTextModel] = useState(""); // seletor de modelo do texto
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
        // #3: recupera o idioma já escolhido por rede (texts_meta[p].lang).
        if (d.draft.texts_meta && typeof d.draft.texts_meta === "object") {
          const langs: Record<string, string> = {};
          for (const [p, m] of Object.entries(d.draft.texts_meta as Record<string, { lang?: string }>)) { if (m?.lang) langs[p] = m.lang; }
          if (Object.keys(langs).length) setTextLangs(langs);
        }
        // Só redes válidas (em NET): descarta legado como "blog", já descontinuado.
        const ps = Object.keys(d.draft.texts || {}).filter((p) => NET[p]);
        if (ps.length) { setPlatforms(ps); setActiveNet(ps[0]); }
      } else localStorage.removeItem(KEY);
      setLoaded(true);
    }).catch(() => setLoaded(true));
  }, []);

  // #3: padrão de idioma da conta (vale como default do seletor por rede até a rede ter o seu).
  useEffect(() => { sfetch("/api/usage").then((r) => r.json()).then((j) => { if (j?.ok && j.content_lang) setDefaultLang(j.content_lang); }).catch(() => {}); }, []);

  // Redes conectadas (aba Conexões/conector social) — só elas ficam selecionáveis; as demais aparecem
  // bloqueadas com atalho pra conectar. Une as contas de TODOS os perfis do tenant.
  useEffect(() => {
    sfetch("/api/connections").then((r) => r.json()).then((d) => {
      if (d?.ok) {
        const fromProfiles = (d.profiles || []).flatMap((p: { accounts?: { platform: string }[] }) => p.accounts || []);
        const all = [...(d.accounts || []), ...fromProfiles].map((a: { platform: string }) => a.platform).filter(Boolean);
        setConnected(Array.from(new Set(all)));
      }
    }).catch(() => {}).finally(() => setConnLoaded(true));
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

  const go = (path: string) => { router.push(path); };
  // #3: idioma efetivo de uma rede = escolha da rede OU padrão da conta.
  const langOf = (p: string) => textLangs[p] ?? defaultLang;

  async function gerarTexto(p: string) {
    if (!draftId) return;
    setBusy("t-" + p); setMsg(null);
    const lang = langOf(p); // #3: gera no idioma marcado pra rede
    const r = await sfetch("/api/studio/text", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ draftId, platform: p, lang, textModel: textModel || undefined }) });
    const d = await r.json(); setBusy(null);
    if (d.ok) { setTexts((t) => ({ ...t, [p]: d.post })); setTextLangs((a) => ({ ...a, [p]: d.lang || lang })); }
    else setMsg("❌ " + (d.error || "geração falhou"));
  }
  // Edição: atualiza local + autosave no servidor (PATCH por plataforma).
  function editar(p: string, text: string) {
    setTexts((t) => ({ ...t, [p]: text }));
    sfetch("/api/studio/text", { method: "PATCH", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ draftId, platform: p, text }) }).catch(() => {});
  }
  /** Marca/desmarca uma rede. Desmarcar só sai de verdade no Salvar (post-networks). */
  function alternarRede(p: string) {
    if (platforms.includes(p)) {
      const rest = platforms.filter((x) => x !== p);
      setPlatforms(rest);
      if (curNet === p) setActiveNet(rest[0] || "");
    } else {
      setPlatforms((a) => [...a, p]);
      setActiveNet(p);
    }
  }
  // Redes que ainda têm legenda gravada mas estão DESMARCADAS — vão sair do post ao salvar.
  const redesRemovidas = Object.keys(texts).filter((p) => NET[p] && !platforms.includes(p) && (texts[p] ?? "").trim() !== "");

  // Salvar explícito: persiste todos os textos das plataformas ativas + feedback.
  async function salvar() {
    if (!draftId) return;
    if (platforms.length === 0) { setMsg("❌ Marque ao menos uma rede."); return; }
    setBusy("save"); setMsg(null);
    try {
      // 1) A MARCAÇÃO é a verdade do post. Desmarcar uma rede não apagava a legenda dela: ela
      //    continuava em draft.texts, voltava a aparecer no Aprovar e o publish (que itera
      //    draft.texts) mandava ao ar assim mesmo. Aqui as desmarcadas saem de fato.
      const r = await sfetch("/api/studio/post-networks", {
        method: "POST", headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ draftId, platforms }),
      });
      const d = await r.json().catch(() => null);
      if (!d?.ok) { setMsg("❌ " + (d?.error || "não consegui salvar as redes do post")); setBusy(null); return; }
      // 2) e as marcadas ficam com o texto atual.
      await Promise.all(platforms.map((p) =>
        sfetch("/api/studio/text", { method: "PATCH", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ draftId, platform: p, text: texts[p] || "" }) })
      ));
      const saiu: string[] = Array.isArray(d.removed) ? d.removed : [];
      setTexts((t) => Object.fromEntries(Object.entries(t).filter(([p]) => !saiu.includes(p))));
      setMsg(saiu.length ? `✅ Rascunho salvo — ${saiu.map((p) => NET[p]?.name ?? p).join(", ")} saiu do post.` : "✅ Rascunho salvo.");
    } catch { setMsg("❌ Não consegui salvar agora."); }
    setBusy(null);
  }
  function novo() { localStorage.removeItem(KEY); go("/"); }

  // ⭐ Salva este rascunho como RECEITA da marca (aba Rápido → "Da sua equipe"). O título vem do tema.
  async function salvarReceita() {
    if (!draftId) return;
    setBusy("receita"); setMsg(null);
    try {
      const r = await sfetch("/api/studio/templates", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ fromDraftId: draftId, title: keyword || "Minha receita", category: "outro" }) });
      const d = await r.json();
      setMsg(d.ok ? "⭐ Salvo como receita da equipe (aba Rápido)." : "❌ " + (d.error || "não deu pra salvar a receita"));
    } catch { setMsg("❌ não deu pra salvar a receita"); }
    setBusy(null);
  }

  const card = { background: "var(--panel)", border: "1px solid var(--line)", borderRadius: 12, padding: 16 } as const;

  if (!loaded) return <p className="sub">Carregando rascunho...</p>;
  if (!draftId) return (
    <>
      <h1 className="h1">Conteúdo / Legendas</h1>
      <div className="empty">Nenhum rascunho ativo. Comece em <Link href="/" style={{ color: "var(--peach)" }}>Pesquisar</Link> — ou use o botão <strong>&quot;Criar sem pesquisa&quot;</strong> lá pra ir direto pro editor.</div>
    </>
  );

  return (
    <>
      <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center" }}>
        <h1 className="h1">Conteúdo / Legendas{keyword ? <span style={{ color: "var(--muted)", fontWeight: 400, fontSize: "1rem" }}> · {keyword}</span> : ""}</h1>
        <p className="sub" style={{ marginBottom: 14 }}>Escreva ou gere a legenda de cada rede — este é o texto que sai na publicação.</p>
        <div style={{ display: "flex", gap: 8 }}>
          <button className="btn ok" style={{ flex: "none", padding: "7px 14px" }} disabled={busy === "save"} onClick={salvar}>{busy === "save" ? "Salvando..." : "💾 Salvar rascunho"}</button>
          <button className="btn edit" style={{ flex: "none", padding: "7px 13px" }} disabled={busy === "receita"} onClick={salvarReceita} title="Salva este rascunho como receita reutilizável da equipe (aba Rápido)">{busy === "receita" ? "..." : "⭐ Receita"}</button>
          <button className="btn edit" style={{ flex: "none", padding: "7px 13px" }} onClick={novo}>+ Novo</button>
        </div>
      </div>

      {/* O resumo/referência foi movido pra a aba Resumo: tirar o textarea grande do topo
          acabou com o salto de scroll que acontecia a cada troca de aba/geração aqui. */}
      <p className="txt" style={{ color: "var(--muted)", fontSize: ".82rem", marginTop: 14 }}>
        📝 O <strong>resumo/referência</strong> (base pra IA gerar o texto e os prompts de mídia) agora fica na aba <a href="/resumo" style={{ color: "var(--peach)" }}>Resumo</a>.
      </p>

      {/* Abas de rede: só as CONECTADAS (aba Conexões) ficam habilitadas. Clicar no corpo
          do chip escreve/edita aquela rede; o × remove do conjunto (desmarca). Redes não
          conectadas aparecem bloqueadas (🔒) e o clique leva pra Conexões. A bolinha ● marca
          as redes que já têm texto. Só o editor da aba ativa aparece. */}
      <div style={{ display: "flex", gap: 6, flexWrap: "wrap", margin: "14px 0 4px" }}>
        {PLATFORMS.map((p) => {
          const isConn = connected.includes(p);
          const on = platforms.includes(p);
          const active = on && p === curNet;
          const hasText = !!(texts[p] && texts[p].trim());
          // Bloqueada só se NÃO conectada E ainda sem conteúdo (redes já com texto continuam
          // acessíveis pra não perder trabalho, mesmo que a conta tenha sido desconectada depois).
          const locked = !isConn && !on;
          if (locked) {
            return (
              <button key={p} title={`Conecte o ${NET[p].name} em Conexões para gerar conteúdo pra ele`}
                onClick={() => go("/conexoes")}
                style={{ padding: "5px 12px", borderRadius: 16, fontSize: ".8rem", cursor: "pointer", fontWeight: 400,
                  border: "1px dashed var(--line)", background: "var(--bg2)", color: "var(--muted)", opacity: 0.6 }}>
                🔒 {NET[p].name}
              </button>
            );
          }
          return (
            <span key={p} style={{ display: "inline-flex", alignItems: "center", gap: 5, paddingLeft: 8, borderRadius: 16, overflow: "hidden",
              border: "1px solid " + (on ? NET[p].color : "var(--line)"),
              background: active ? NET[p].color : on ? NET[p].color + "22" : "var(--bg2)" }}>
              {/* CHECKBOX de verdade (era um × escondido no canto): marcar = a rede entra no post,
                  desmarcar = sai. O corpo do chip continua sendo a aba de edição. */}
              <input type="checkbox" checked={on} onChange={() => alternarRede(p)}
                title={on ? `Tirar o ${NET[p].name} do post` : `Incluir o ${NET[p].name} no post`}
                style={{ accentColor: NET[p].color, width: 14, height: 14, cursor: "pointer" }} />
              <button title={on ? "Editar " + NET[p].name : "Escrever pra " + NET[p].name}
                onClick={() => { if (!on) setPlatforms((a) => [...a, p]); setActiveNet(p); }}
                style={{ padding: "5px 10px 5px 2px", border: 0, background: "transparent", fontSize: ".8rem", cursor: "pointer",
                  fontWeight: active ? 700 : 400, color: active ? "#fff" : on ? "var(--text)" : "var(--muted)" }}>
                {hasText ? "● " : ""}{NET[p].name}
              </button>
            </span>
          );
        })}
      </div>
      {connLoaded && connected.length === 0 && (
        <p className="txt" style={{ color: "#ff9b8a", fontSize: ".82rem", margin: "6px 0 0" }}>
          Nenhuma rede conectada ainda. Vá em <a href="/conexoes" style={{ color: "var(--peach)" }}>Conexões</a> para conectar suas redes (Instagram, TikTok, LinkedIn…) e liberá-las aqui.
        </p>
      )}
      {redesRemovidas.length > 0 && (
        <p className="txt" style={{ color: "#ff9b8a", fontSize: ".8rem", margin: "6px 0 0" }}>
          ⚠️ <strong>{redesRemovidas.map((p) => NET[p]?.name ?? p).join(", ")}</strong> tem legenda escrita e está <strong>desmarcada</strong> — ao salvar, sai do post (a legenda é apagada). Marque de volta se não era isso.
        </p>
      )}

      {/* Editor da rede ATIVA (uma por vez — as abas acima trocam) */}
      <div style={{ display: "flex", flexDirection: "column", gap: 14, marginTop: 10 }}>
        {platforms.length === 0 ? (
          <div className="empty">Selecione ao menos uma rede acima.</div>
        ) : curNet && (
          <div id={"net-" + curNet} style={{ ...card, borderLeft: `3px solid ${NET[curNet].color}` }}>
            <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: 8, gap: 8 }}>
              <strong style={{ color: NET[curNet].color }}>{NET[curNet].name}</strong>
              <div style={{ display: "flex", gap: 8, alignItems: "center", flexWrap: "wrap" }}>
                {/* #3: idioma desta rede (um ou outro) — Gerar/Regerar usa o idioma marcado */}
                <div style={{ display: "flex", border: "1px solid var(--line)", borderRadius: 16, overflow: "hidden" }} title="Idioma do texto desta rede">
                  {([["pt-BR", "PT"], ["en-US", "EN"]] as const).map(([v, lbl]) => {
                    const on = langOf(curNet) === v;
                    return (
                      <button key={v} type="button" onClick={() => setTextLangs((a) => ({ ...a, [curNet]: v }))}
                        style={{ padding: "5px 12px", fontSize: ".74rem", cursor: "pointer", border: 0, background: on ? NET[curNet].color : "transparent", color: on ? "#fff" : "var(--muted)", fontWeight: on ? 700 : 400 }}>{lbl}</button>
                    );
                  })}
                </div>
                <TextModelSelect value={textModel} onChange={setTextModel} />
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
            <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fill,minmax(min(90px, 100%), 1fr))", gap: 6, marginTop: 8 }}>
              {media.map((m) => m.kind === "image"
                ? <img key={m.id} src={m.url} alt="" style={{ width: "100%", borderRadius: 6, display: "block" }} />
                : <video key={m.id} src={m.url} style={{ width: "100%", borderRadius: 6 }} />)}
            </div>
            <a href="/midia" className="txt" style={{ color: "var(--peach)", fontSize: ".8rem", display: "inline-block", marginTop: 8 }}>Gerenciar mídia →</a>
          </div>
        )}

        {/* Aviso: redes adicionadas SEM texto não serão publicadas (o publish só vai pras
            redes com texto). Evita o "só publicou no LinkedIn" sem o usuário perceber. */}
        {(() => {
          const semTexto = platforms.filter((p) => !(texts[p] && texts[p].trim()));
          if (semTexto.length === 0) return null;
          return (
            <p className="txt" style={{ color: "#ff9b8a", fontSize: ".85rem", margin: 0 }}>
              ⚠ Estas redes estão <strong>sem texto</strong> e não serão publicadas:{" "}
              {semTexto.map((p) => NET[p]?.name ?? p).join(", ")}. Abra cada aba e use{" "}
              <strong>“Gerar texto IA”</strong> (ou escreva) para incluí-las.
            </p>
          );
        })()}

        <div style={{ display: "flex", gap: 10, flexWrap: "wrap" }}>
          <button className="btn ok" style={{ flex: "none", padding: "10px 18px" }} onClick={() => go("/midia")}>Ir pra Mídia →</button>
          <button className="btn edit" style={{ flex: "none", padding: "10px 18px" }} onClick={() => go("/aprovar")}>👁 Revisar e aprovar →</button>
        </div>
      </div>

      {msg && <p className="txt" style={{ marginTop: 16, fontSize: "1rem" }}>{msg}</p>}
    </>
  );
}
