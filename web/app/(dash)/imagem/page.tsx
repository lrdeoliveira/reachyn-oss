"use client";

// Tela do MOTOR DE IMAGEM local (§4/§5 do plano). Um tema → o mmx expande no prompt premium de
// 7 camadas e gera em alta resolução, tudo no host (sem bridge, sem KIE, sem gastar crédito do
// Reachyn). É a semente do fluxo imagem→cena controlado: por ora, t2i direto.

import { useEffect, useState } from "react";
import { useToast } from "@/components/ui/Toast";
import { IMAGE_STYLES } from "@/lib/imageStyles";
import { Console, Engine, sfetch, type GenModelInfo } from "@/lib/api";
import { Sparkles, Download, Copy, RotateCcw } from "lucide-react";
import { ComfyStatus, useComfy } from "@/components/ComfyStatus";
import { EscolherImagem } from "@/components/EscolherImagem";
import { SeletorModeloImagem } from "@/components/SeletorModeloImagem";
import { ajudaMotor, marcaMotor, nomeModelo, rotuloMotor } from "@/lib/motor";

// O sentinela LOCAL ("__mmx__") FOI REMOVIDO (2026-08-01). Ele roteava pra `fetch("/api/image")`,
// uma rota Next que NUNCA existiu neste repo — só `app/api/compose` existe, e o git confirma que
// /api/image jamais foi criada. Pior: era o caminho PADRÃO (resolverAuto devolvia LOCAL sempre que
// não havia personagem com base), e o 404 saía mascarado como "Motor local desligado" porque o
// código só tratava 403. Desde 2026-07-20 o mmx é o modelo de catálogo `img-cli-mmx` (DEFAULT_T2I
// no console), que roda pelo cli-bridge via engine — ou seja, já era opção normal do seletor.
//
// Motor AUTO: você escolhe a INTENÇÃO, o sistema escolhe o caminho. Com personagem (precisa de
// referência) → mais barato que aceita ref; sem personagem → o mais barato da lista.
const AUTO = "__auto__";

// Proporções oferecidas (subconjunto da allowlist da rota /api/image).
const ASPECTS: [string, string][] = [
  ["1:1", "Quadrado 1:1"],
  ["16:9", "Paisagem 16:9"],
  ["9:16", "Retrato 9:16"],
  ["3:4", "Retrato 3:4"],
  ["4:3", "Paisagem 4:3"],
];

type Result = { url: string; name: string; aspect: string; style?: string; prompt: string };

// Campo select reutilizado (estilo + proporção): mesmo visual do resto dos forms.
const selectStyle: React.CSSProperties = {
  background: "var(--bg2)",
  color: "var(--text)",
  border: "1px solid var(--line2)",
  borderRadius: 10,
  padding: "10px 12px",
  fontSize: ".9rem",
  fontFamily: "inherit",
};

// Persona de imagem = direção de arte escrita por você na aba Prompts (kind=image).
type Persona = { id: number; title: string; content: string };

export default function ImagemPage() {
  const [theme, setTheme] = useState("");
  const [aspect, setAspect] = useState("1:1");
  const [style, setStyle] = useState("realista");
  const [model, setModel] = useState(AUTO);
  const [models, setModels] = useState<GenModelInfo[]>([]);
  const comfy = useComfy();
  const [busy, setBusy] = useState(false);
  // Direção de arte: as personas de imagem da aba Prompts. O engine aceita `persona` desde
  // sempre; faltava a tela oferecer e o console mandar, então elas não afetavam nada.
  const [personas, setPersonas] = useState<Persona[]>([]);
  const [personaId, setPersonaId] = useState("");
  // Cor separada da direção: no BUDO a paleta é módulo cross-cutting, então dá pra combinar
  // "Estilo: Produto" com "Cor: Teal & Orange" em vez de escolher um OU outro.
  const [cores, setCores] = useState<Persona[]>([]);
  const [corId, setCorId] = useState("");
  const [result, setResult] = useState<Result | null>(null);
  // PERSONAGEM da biblioteca: a base vira âncora (i2i) e o `lock` entra no prompt no servidor
  // (charIds → IDENTITY LOCK). Sem isto, gerar uma peça avulsa "da Mel" dependia de descrever a
  // Mel de novo no texto — e sair parecida por sorte.
  const [chars, setChars] = useState<{ id: number; name: string; base_url?: string | null }[]>([]);
  const [charId, setCharId] = useState("");
  const toast = useToast();

  // ✏️ EDIÇÃO (i2i) — o card de baixo. Esta aba nasceu text→image; corrigir uma imagem que já
  // existe obrigava a ir ao Studio. Estado próprio de propósito: o motor de EDIÇÃO é outro (tem de
  // aceitar referência) e o prompt aqui é uma ORDEM ("tire o poste"), não a descrição de uma cena.
  const [edBase, setEdBase] = useState("");
  const [edEscolher, setEdEscolher] = useState(false);
  const [edPrompt, setEdPrompt] = useState("");
  const [edModel, setEdModel] = useState("");
  const [edBusy, setEdBusy] = useState(false);
  const [edResult, setEdResult] = useState<string>("");

  // Modelos de imagem do catálogo (via engine). Mostra só os t2i que rodam local via engine/KIE:
  // exclui `img-cli-*` (mmx/cursor do cli-bridge — sidecar de PROD, não existe no dev; o mmx local
  // já é a opção "Local") e os i2i (esta aba é text→image). Degrada: sem catálogo, fica só o mmx.
  useEffect(() => {
    sfetch("/api/characters")
      .then((r) => r.json())
      .then((j) => setChars((Array.isArray(j) ? j : j?.data ?? []).map((c: { id: number; name: string; base_url?: string | null }) => ({ id: c.id, name: c.name, base_url: c.base_url }))))
      .catch(() => {});
    Console.imageModels()
      .then((r) =>
        setModels(
          (r.data ?? [])
            // `img-cli-*` NÃO é mais excluído: eram justamente o mmx e o cursor, que ficavam de
            // fora porque o mmx tinha a opção "Local" própria — a que apontava pra rota fantasma.
            // Sem ela, esconder os cli-bridge tirava do seletor os únicos motores de assinatura.
            .filter((m) => m.subtype === "text_to_image")
            // do mais barato pro mais caro (o custo em créditos aparece na opção)
            .sort((a, b) => (a.cost_credits ?? 0) - (b.cost_credits ?? 0)),
        ),
      )
      .catch(() => {});
    // Personas de imagem da aba Prompts (a direção de arte que VOCÊ escreveu).
    sfetch("/api/prompts?kind=image")
      .then((r) => r.json())
      .then((d) => {
        const todas: Persona[] = Array.isArray(d) ? d : [];
        setCores(todas.filter((x) => x.title.startsWith("🎨 Cor:")));
        setPersonas(todas.filter((x) => !x.title.startsWith("🎨 Cor:")));
      })
      .catch(() => {});
  }, []);

  // Resolução do AUTO — a regra numa função só, usada pra gerar E pro badge contar o plano:
  // personagem com base → precisa de referência → catálogo mais barato com refs_max>=1;
  // sem personagem → mmx local (grátis). Lista já vem ordenada por custo.
  function resolverAuto(): string {
    const p = chars.find((c) => String(c.id) === charId);
    // Com personagem só serve modelo que aceita referência; sem ele, o mais barato (a lista já
    // vem ordenada por custo, então models[0] é o mais barato — hoje o próprio mmx).
    if (p?.base_url) return models.find((m) => (m.refs_max ?? 0) >= 1)?.slug ?? models[0]?.slug ?? "";
    return models[0]?.slug ?? "";
  }

  // Modelo do catálogo: gera pelo engine (nuvem/ComfyUI) e devolve a URL do S3.
  async function gerarCatalogo(t: string, slug: string): Promise<Result> {
    const p = chars.find((c) => String(c.id) === charId);
    const d = await Engine.image({
      prompt: t, aspect, style, model: slug,
      // Identidade: base como âncora visual + id pro servidor injetar o lock textual.
      imageUrls: p?.base_url ? [p.base_url] : undefined,
      charIds: p ? [p.id] : undefined,
      // Manda o TEXTO da persona (mesmo contrato do Filme e da aba Vídeo), não o id.
      persona: [
        personas.find((x) => String(x.id) === personaId)?.content,
        cores.find((x) => String(x.id) === corId)?.content,
      ].filter(Boolean).join("\n\n") || undefined,
    });
    return { ...d, prompt: t, aspect, style } as Result;
  }

  async function gerar() {
    const t = theme.trim();
    if (!t) {
      toast.err("Escreva um tema pra imagem.");
      return;
    }
    setBusy(true);
    setResult(null);
    try {
      const alvo = model === AUTO ? resolverAuto() : model;
      if (!alvo) throw new Error("Nenhum motor de imagem disponível no catálogo.");
      setResult(await gerarCatalogo(t, alvo));
      toast.ok("Imagem pronta!");
    } catch (e) {
      toast.err(e instanceof Error ? e.message : "Erro de rede ao falar com o motor.");
    } finally {
      setBusy(false);
    }
  }

  // Modelos que ACEITAM referência — só eles sabem editar. `refs_max >= 1` é o mesmo critério que
  // o Auto já usa pra escolher motor quando há personagem.
  const modelosEdicao = models.filter((m) => (m.refs_max ?? 0) >= 1);

  async function editar() {
    if (!edBase) return toast.err("Escolha ou suba a imagem que você quer editar.");
    const ordem = edPrompt.trim();
    if (!ordem) return toast.err("Diga o que mudar na imagem.");
    const alvo = edModel || modelosEdicao[0]?.slug;
    if (!alvo) return toast.err("Nenhum motor de edição disponível no catálogo.");
    setEdBusy(true);
    setEdResult("");
    try {
      // Mesmo contrato do i2i do Studio: a imagem vai como referência e o prompt é a ORDEM de
      // mudança. `anchorIdentity` fica FORA de propósito — em edição o usuário quer justamente
      // alterar o sujeito, e travar a identidade brigaria com o pedido.
      const d = await Engine.image({ prompt: ordem, aspect, style, model: alvo, imageUrls: [edBase] });
      const url = (d as { url?: string })?.url ?? "";
      if (!url) throw new Error("O motor não devolveu imagem.");
      setEdResult(url);
      // A peça editada já nasce na galeria — sem isso o resultado morria na tela e o usuário
      // tinha de baixar e subir de novo pra usá-la em qualquer outro lugar.
      toast.ok("Imagem editada — já está na galeria.");
    } catch (e) {
      toast.err(e instanceof Error ? e.message : "Erro ao editar a imagem.");
    } finally {
      setEdBusy(false);
    }
  }

  function copiarPrompt() {
    if (!result) return;
    navigator.clipboard?.writeText(result.prompt).then(
      () => toast.ok("Prompt copiado."),
      () => toast.err("Não deu pra copiar."),
    );
  }

  return (
    <>
      <h1 className="h1">Imagem</h1>
      <p className="sub">
        Um tema vira uma imagem em alta resolução. No <b>Auto</b>, o sistema escolhe o motor pelo
        pedido: sem personagem gera grátis no seu Mac; com personagem usa o catálogo (referência).
        Ou mande no motor que quiser.
      </p>

      <div className="card" style={{ marginBottom: 24 }}>
        <div className="body" style={{ gap: 14 }}>
          <textarea
            value={theme}
            onChange={(e) => setTheme(e.target.value)}
            onKeyDown={(e) => {
              // Cmd/Ctrl+Enter gera — atalho comum de "enviar" em campos multilinha.
              if ((e.metaKey || e.ctrlKey) && e.key === "Enter") gerar();
            }}
            disabled={busy}
            rows={4}
            placeholder="Ex.: raposa vermelha em chiaroscuro, pelo nítido, fundo escuro de estúdio"
            style={{
              width: "100%",
              resize: "vertical",
              background: "var(--bg2)",
              color: "var(--text)",
              border: "1px solid var(--line2)",
              borderRadius: 10,
              padding: "12px 14px",
              fontSize: ".95rem",
              fontFamily: "inherit",
              lineHeight: 1.55,
            }}
          />
          <div style={{ display: "flex", gap: 10, alignItems: "flex-end", flexWrap: "wrap" }}>
            <label style={{ display: "flex", flexDirection: "column", gap: 5 }}>
              <span style={{ color: "var(--muted)", fontSize: ".78rem" }}>Modelo</span>
              <select
                value={model}
                onChange={(e) => setModel(e.target.value)}
                disabled={busy}
                title="Auto: sem personagem gera no mmx grátis; com personagem usa o catálogo (referência). Ou escolha o motor na mão."
                style={selectStyle}
              >
                <option value={AUTO}>✨ Auto (decide pelo pedido)</option>
                {/* O mmx agora vem do catálogo como qualquer outro (img-cli-mmx) — a origem
                    "💻 Mac" sai do próprio modelo, via marcaMotor. Não há mais opção fixa. */}
                {models.map((m) => (
                  <option key={m.slug} value={m.slug}>
                    {/* Operador lê a ORIGEM na frente ("☁️ nuvem · …", "✨ assinatura · …",
                        "🎛️ ComfyUI · …", "💻 Mac · …") — é o que separa de qual conta sai a peça
                        quando três motores cobram de bolsos diferentes. Cliente lê só o ícone. */}
                    {marcaMotor(m)} · {nomeModelo(m)}{m.cost_credits != null ? ` · ${m.cost_credits} cr` : ""}
                  </option>
                ))}
              </select>
            </label>
            <label style={{ display: "flex", flexDirection: "column", gap: 5 }}>
              <span style={{ color: "var(--muted)", fontSize: ".78rem" }}>Estilo</span>
              <select
                value={style}
                onChange={(e) => setStyle(e.target.value)}
                disabled={busy}
                title="Persona de imagem: direciona o meio e a qualidade (foto, 3D/Pixar, anime, pintura…)"
                style={selectStyle}
              >
                {IMAGE_STYLES.map(([v, label]) => (
                  <option key={v} value={v}>{label}</option>
                ))}
              </select>
            </label>
            {chars.length > 0 && (
              <label style={{ display: "flex", flexDirection: "column", gap: 5 }}>
                <span style={{ color: "var(--muted)", fontSize: ".78rem" }}>Personagem</span>
                <select
                  value={charId}
                  onChange={(e) => setCharId(e.target.value)}
                  // O gate agora é a CAPACIDADE do modelo, não um sentinela: ancorar num
                  // personagem exige aceitar referência (refs_max >= 1). No Auto não trava —
                  // resolverAuto já escolhe um modelo com ref quando há personagem.
                  disabled={busy || (model !== AUTO && (models.find((m) => m.slug === model)?.refs_max ?? 0) < 1)}
                  title={model !== AUTO && (models.find((m) => m.slug === model)?.refs_max ?? 0) < 1
                    ? "Este motor gera só a partir do texto — escolha Auto ou um modelo que aceite referência pra ancorar num personagem"
                    : "Usa a imagem-base como referência e injeta o character lock no prompt (no Auto, escolher um personagem roteia pro modelo com referência)"}
                  style={selectStyle}
                >
                  <option value="">Nenhum</option>
                  {chars.map((c) => (
                    <option key={c.id} value={String(c.id)}>{c.name}{c.base_url ? "" : " (sem base)"}</option>
                  ))}
                </select>
              </label>
            )}
            {personas.length > 0 && (
              <label style={{ display: "flex", flexDirection: "column", gap: 5 }}>
                <span style={{ color: "var(--muted)", fontSize: ".78rem" }}>Direção</span>
                <select
                  value={personaId}
                  onChange={(e) => setPersonaId(e.target.value)}
                  disabled={busy}
                  title="Personas que você escreveu na aba Prompts — direção de arte aplicada na geração"
                  style={selectStyle}
                >
                  <option value="">Sem direção</option>
                  {personas.map((p) => (
                    <option key={p.id} value={String(p.id)}>{p.title}</option>
                  ))}
                </select>
              </label>
            )}
            {cores.length > 0 && (
              <label style={{ display: "flex", flexDirection: "column", gap: 5 }}>
                <span style={{ color: "var(--muted)", fontSize: ".78rem" }}>Cor</span>
                <select
                  value={corId}
                  onChange={(e) => setCorId(e.target.value)}
                  disabled={busy}
                  title="Paleta cinematográfica — combina com a direção escolhida"
                  style={selectStyle}
                >
                  <option value="">Sem paleta</option>
                  {cores.map((c) => (
                    <option key={c.id} value={String(c.id)}>{c.title.replace("🎨 Cor: ", "")}</option>
                  ))}
                </select>
              </label>
            )}
            <label style={{ display: "flex", flexDirection: "column", gap: 5 }}>
              <span style={{ color: "var(--muted)", fontSize: ".78rem" }}>Proporção</span>
              <select
                value={aspect}
                onChange={(e) => setAspect(e.target.value)}
                disabled={busy}
                style={selectStyle}
              >
                {ASPECTS.map(([v, label]) => (
                  <option key={v} value={v}>{label}</option>
                ))}
              </select>
            </label>
            <button
              type="button"
              className="btn ok"
              onClick={gerar}
              disabled={busy}
              style={{ flex: "0 0 auto", display: "flex", alignItems: "center", gap: 8, padding: "11px 20px" }}
            >
              {busy ? <span className="spinner" style={{ width: 15, height: 15 }} /> : <Sparkles size={16} />}
              {busy ? "Gerando…" : "Gerar imagem"}
            </button>
            <span style={{ color: "var(--muted)", fontSize: ".82rem" }}>⌘/Ctrl + Enter</span>
            {/* Custo do motor que VAI rodar. No Auto o badge conta o plano ("Auto → …") pra
                decisão automática nunca ser surpresa — transparência é parte da facilidade. */}
            {(() => {
              const alvo = model === AUTO ? resolverAuto() : model;
              const cur = models.find((m) => m.slug === alvo);
              // Onde roda vem ANTES do nome: é o que decide se aquilo custa crédito, depende do
              // seu servidor de pé ou usa a assinatura desta máquina.
              // Sem texto fixo: o rótulo sai do próprio modelo. O antigo dizia "sem crédito" pro
              // mmx, o que hoje seria mentira — img-cli-mmx custa crédito no catálogo.
              const rotulo = cur
                ? `${rotuloMotor(cur)} · ${nomeModelo(cur)} · ${cur.cost_credits ?? "—"} créditos`
                : "—";
              return (
                <span className="badge" style={{ marginLeft: "auto" }} title={cur ? ajudaMotor(cur) : undefined}>
                  {model === AUTO ? `Auto → ${rotulo}` : rotulo}
                </span>
              );
            })()}
          </div>
          {/* 🟢 Estúdio Local: a bolinha conta se o ComfyUI está de pé ANTES de gastar o clique. */}
          <div style={{ display: "flex", justifyContent: "flex-end" }}>
            <ComfyStatus info={comfy} />
          </div>
          {busy && (
            <div style={{ color: "var(--muted)", fontSize: ".86rem" }}>
              Expandindo o prompt e gerando em alta resolução — costuma levar de 15 a 40 segundos.
            </div>
          )}
        </div>
      </div>

      {result && (
        <div className="card">
          <img
            src={result.url}
            alt={theme || "imagem gerada"}
            style={{ width: "100%", height: "auto", display: "block", background: "var(--bg2)" }}
          />
          <div className="body">
            <details>
              <summary style={{ cursor: "pointer", fontWeight: 700, color: "var(--peach)" }}>
                Prompt premium usado
              </summary>
              <p className="txt" style={{ marginTop: 10, whiteSpace: "pre-wrap" }}>{result.prompt}</p>
            </details>
          </div>
          <div className="acts">
            <a className="btn ok" href={result.url} download={result.name} style={{ display: "flex", alignItems: "center", justifyContent: "center", gap: 7, textDecoration: "none" }}>
              <Download size={15} /> Baixar
            </a>
            <button type="button" className="btn edit" onClick={copiarPrompt} style={{ display: "flex", alignItems: "center", justifyContent: "center", gap: 7 }}>
              <Copy size={15} /> Copiar prompt
            </button>
            <button type="button" className="btn no" onClick={() => setResult(null)} style={{ display: "flex", alignItems: "center", justifyContent: "center", gap: 7 }}>
              <RotateCcw size={15} /> Nova
            </button>
          </div>
        </div>
      )}

      {/* ✏️ EDITAR — o caminho inverso do card de cima: em vez de escrever uma cena, você traz uma
          imagem pronta e diz o que mudar. Existe porque corrigir uma peça (tirar um objeto, trocar
          a cor, ajustar o fundo) obrigava a ir ao Studio ou a regerar do zero — e regerar do zero
          devolve outra imagem, não a mesma corrigida. */}
      <div className="card" style={{ marginTop: 24 }}>
        <div className="body" style={{ gap: 14 }}>
          <div>
            <strong>✏️ Editar uma imagem</strong>
            <p className="sub" style={{ margin: "4px 0 0" }}>
              Suba do computador ou pegue da galeria, diga o que mudar e o resultado já entra na galeria.
            </p>
          </div>

          <div style={{ display: "flex", gap: 12, flexWrap: "wrap", alignItems: "flex-start" }}>
            {edBase ? (
              // eslint-disable-next-line @next/next/no-img-element
              <img src={edBase} alt="imagem a editar" style={{ width: 96, height: 96, objectFit: "cover", borderRadius: 10, border: "1px solid var(--line)" }} />
            ) : null}
            <div style={{ display: "flex", flexDirection: "column", gap: 6 }}>
              <button type="button" className="btn edit" style={{ flex: "none", padding: "9px 14px" }} onClick={() => setEdEscolher(true)}>
                {edBase ? "Trocar imagem" : "🖼️ Escolher imagem"}
              </button>
              {edBase && (
                <button type="button" className="btn" style={{ flex: "none", padding: "7px 12px" }} onClick={() => { setEdBase(""); setEdResult(""); }}>
                  Limpar
                </button>
              )}
            </div>
            <SeletorModeloImagem modelos={modelosEdicao} valor={edModel} onChange={setEdModel}
              rotulo="Motor da edição"
              estilo={{ background: "var(--bg2)", color: "var(--text)", border: "1px solid var(--line)", borderRadius: 8, padding: "9px 12px", fontSize: ".82rem" }} />
          </div>

          <textarea
            value={edPrompt}
            onChange={(e) => setEdPrompt(e.target.value)}
            rows={2}
            placeholder="O que mudar: tire o poste do fundo · deixe o céu no fim de tarde · troque a camisa por uma azul"
            style={{ width: "100%", resize: "vertical", padding: 10, borderRadius: 8, border: "1px solid var(--line)", background: "transparent", color: "var(--fg)", fontSize: ".9rem", boxSizing: "border-box" }}
          />

          <div style={{ display: "flex", gap: 10, alignItems: "center", flexWrap: "wrap" }}>
            <button type="button" className="btn ok" disabled={edBusy || !edBase} onClick={editar}
              style={{ flex: "none", padding: "9px 16px", display: "inline-flex", alignItems: "center", gap: 7 }}>
              <Sparkles size={15} /> {edBusy ? "Editando…" : "Editar imagem"}
            </button>
            {modelosEdicao.length === 0 && (
              <span className="txt" style={{ color: "var(--muted)", fontSize: ".78rem" }}>
                Nenhum motor do catálogo aceita referência agora — a edição precisa de um.
              </span>
            )}
          </div>

          {edResult && (
            <div style={{ display: "flex", gap: 12, flexWrap: "wrap", alignItems: "flex-start" }}>
              {/* eslint-disable-next-line @next/next/no-img-element */}
              <img src={edResult} alt="imagem editada" style={{ maxWidth: 320, borderRadius: 10, border: "1px solid var(--line)" }} />
              <div style={{ display: "flex", flexDirection: "column", gap: 8 }}>
                <a className="btn ok" href={edResult} download style={{ display: "inline-flex", alignItems: "center", gap: 7, textDecoration: "none", padding: "9px 14px" }}>
                  <Download size={15} /> Baixar
                </a>
                {/* Editar em cima do resultado: é assim que correção vira PROCESSO — um ajuste de
                    cada vez, olhando o anterior, em vez de reescrever a ordem inteira. */}
                <button type="button" className="btn edit" style={{ padding: "9px 14px" }}
                  onClick={() => { setEdBase(edResult); setEdResult(""); setEdPrompt(""); }}>
                  <RotateCcw size={15} /> Editar este resultado
                </button>
              </div>
            </div>
          )}

          <EscolherImagem aberto={edEscolher} onFechar={() => setEdEscolher(false)}
            titulo="Imagem para editar" onEscolher={(u) => { setEdBase(u); setEdEscolher(false); setEdResult(""); }} />
        </div>
      </div>
    </>
  );
}
