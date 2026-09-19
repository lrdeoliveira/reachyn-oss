"use client";

// 🕹️ Aba Sprites — personagem por prompt → spritesheet de jogo animado (motor hospedado).
// Fluxo: descrever o personagem + escolher as animações → job assíncrono na nuvem →
// polling ~15s até terminar → prévia (GIF) + salvar no acervo (o engine baixa os artefatos
// pro nosso storage e a spritesheet entra na Galeria). Em cima de um job pronto dá pra
// pedir uma ANIMAÇÃO nova (barata, reusa a âncora) ou uma VARIAÇÃO (mesmo personagem,
// outra roupa/direção — a identidade vem do job de referência, nunca de re-descrever).
// Custos (créditos do serviço): personagem = 90 + 100/animação · animação avulsa = 100 ·
// variação = 60 + 100/animação. Passo que falha é estornado pelo próprio serviço.

import { useCallback, useEffect, useRef, useState } from "react";
import { useToast } from "@/components/ui/Toast";
import { Sprite, ApiError, type SpriteJob, type SpriteMe } from "@/lib/api";
import { Gamepad2, RefreshCcw, Save, Plus, Shirt, ExternalLink, ChevronDown, ChevronUp } from "lucide-react";
import { MotorLocal } from "./MotorLocal";
import { AssetStudio } from "./AssetStudio";

const RUN_PAGE = "https://app.spriterrific.com/jobs/"; // página viva do job (frame picker etc.)

// Ações padrão do serviço — o conjunto de qualidade assegurada. Custom = animação avulsa
// com baseline (o preset estrutural vem da ação padrão mais próxima).
const CORE_ACTIONS = ["walk", "run", "jump", "hurt", "attack", "death", "idle", "crouch"];
const EXTRA_ACTIONS = [
  "talk", "interact", "pick_up", "use", "examine", "give", "shrug", "walk_forward",
  "walk_backward", "block_high", "block_low", "knockdown", "get_up", "light_attack", "heavy_attack",
];
const BASELINES = ["attack", "light_attack", "heavy_attack", "hurt", "interact", "use", "idle", "jump", "walk"];

const GAME_VIEWS: [string, string][] = [
  ["platformer", "Plataforma (padrão)"],
  ["adventure", "Aventura"],
  ["point-and-click", "Point-and-click"],
  ["top-down", "Visão de cima"],
  ["rts-oblique", "RTS oblíquo"],
  ["isometric", "Isométrico"],
  ["generic", "Genérico"],
];

const DIRECTIONS: [string, string][] = [
  ["w", "Oeste (padrão)"],
  ["e", "Leste"],
  ["s", "Sul"],
  ["n", "Norte"],
  ["sw", "Sudoeste"],
  ["se", "Sudeste"],
  ["nw", "Noroeste"],
  ["ne", "Nordeste"],
];

// Estilo de saída: mixels (padrão hospedado, textura rica sem grid real) ou lobit
// (low-fi num pixel grid de verdade — melhor pra criatura/monstro/blocudo).
const PRESETS: [string, string][] = [
  ["mixels", "Alta fidelidade (padrão)"],
  ["lobit", "Pixel retrô (lobit)"],
];

const TERMINAL = new Set(["completed", "partial", "failed", "canceled"]);

const selectStyle: React.CSSProperties = {
  background: "var(--bg2)",
  color: "var(--text)",
  border: "1px solid var(--line2)",
  borderRadius: 10,
  padding: "10px 12px",
  fontSize: ".9rem",
  fontFamily: "inherit",
};
const inputStyle: React.CSSProperties = { ...selectStyle, padding: "10px 12px" };
const fieldLabel: React.CSSProperties = { display: "flex", flexDirection: "column", gap: 5 };
const capText: React.CSSProperties = { color: "var(--muted)", fontSize: ".78rem" };

// Nome do personagem no serviço é um slug (vira pasta/artefato).
function slugify(s: string): string {
  return s
    .toLowerCase()
    .normalize("NFD")
    .replace(/[̀-ͯ]/g, "")
    .replace(/[^a-z0-9_-]+/g, "-")
    .replace(/^-+|-+$/g, "")
    .slice(0, 40);
}

function statusBadge(status: string): { label: string; color: string } {
  switch (status) {
    case "completed": return { label: "pronto", color: "var(--green, #7dc98f)" };
    case "partial": return { label: "parcial", color: "var(--peach)" };
    case "failed": return { label: "falhou", color: "var(--red)" };
    case "canceled": return { label: "cancelado", color: "var(--muted)" };
    default: return { label: status || "na fila", color: "var(--muted)" };
  }
}

export default function SpritePage() {
  const toast = useToast();

  // Motor: LOCAL (pipeline próprio, padrão — "usar a IA daqui") × NUVEM (serviço hospedado,
  // exige chave em Chaves de geração) × ASSETS (tileset/background/textura/GUI/ícones/props —
  // as demais categorias de asset de jogo, catálogo em lib/gameAssets.ts).
  const [motor, setMotor] = useState<"local" | "nuvem" | "assets">("local");

  // ——— saldo + aviso de contrato (notice do serviço) ———
  const [me, setMe] = useState<SpriteMe | null>(null);
  const [keyMissing, setKeyMissing] = useState(false);

  // ——— form de personagem novo ———
  const [nome, setNome] = useState("");
  const [prompt, setPrompt] = useState("");
  const [gameView, setGameView] = useState("platformer");
  const [direction, setDirection] = useState("w");
  const [preset, setPreset] = useState("mixels");
  const [acoes, setAcoes] = useState<string[]>(["walk", "idle"]);
  const [maisAcoes, setMaisAcoes] = useState(false);
  const [verde, setVerde] = useState(false); // personagem verde → chroma magenta (senão o matte come o personagem)
  const [contexto, setContexto] = useState("");
  const [busy, setBusy] = useState(false);

  // ——— jobs ———
  const [jobs, setJobs] = useState<SpriteJob[]>([]);
  const [aberto, setAberto] = useState<string | null>(null); // job expandido
  const [detalhe, setDetalhe] = useState<Record<string, SpriteJob>>({});
  const [persistido, setPersistido] = useState<Record<string, Record<string, string>>>({});
  const [salvando, setSalvando] = useState<string | null>(null);

  // ——— forms rápidos em cima de um job pronto ———
  const [novaAcao, setNovaAcao] = useState(""); // ação padrão OU "custom"
  const [customNome, setCustomNome] = useState("");
  const [customBase, setCustomBase] = useState("attack");
  const [acaoContexto, setAcaoContexto] = useState("");
  const [varNome, setVarNome] = useState("");
  const [varEdit, setVarEdit] = useState("");
  const [varDir, setVarDir] = useState("");
  const [varAcoes, setVarAcoes] = useState<string[]>([]);
  const [quickForm, setQuickForm] = useState<"" | "acao" | "variacao">("");

  const saldo = me?.total ?? ((me?.planCredits ?? 0) + (me?.topupCredits ?? 0) || null);
  const custoNovo = 90 + acoes.length * 100;

  const carregarMe = useCallback(() => {
    Sprite.me()
      .then((m) => {
        setMe(m);
        setKeyMissing(false);
        if (m.notice) toast.ok(m.notice); // atualização de contrato do serviço — repassar, não engolir
      })
      .catch((e) => {
        if (e instanceof ApiError && e.status === 422) setKeyMissing(true);
      });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const carregarJobs = useCallback(() => {
    Sprite.jobs(25)
      .then((r) => setJobs(Array.isArray(r.jobs) ? r.jobs : []))
      .catch(() => {});
  }, []);

  const carregarDetalhe = useCallback((id: string) => {
    Sprite.job(id)
      .then((r) => {
        if (r.job) setDetalhe((prev) => ({ ...prev, [id]: r.job! }));
      })
      .catch(() => {});
  }, []);

  useEffect(() => {
    if (motor !== "nuvem") return; // modo local não fala com o serviço hospedado
    carregarMe();
    carregarJobs();
  }, [motor, carregarMe, carregarJobs]);

  // Polling: jobs levam MINUTOS (uma geração por âncora e por ação) — ritmo de 15s, sem
  // tight-loop, e só com a aba visível. O detalhe expandido acompanha junto.
  const abertoRef = useRef(aberto);
  const detalheRef = useRef(detalhe);
  useEffect(() => {
    abertoRef.current = aberto;
    detalheRef.current = detalhe;
  }, [aberto, detalhe]);
  useEffect(() => {
    if (motor !== "nuvem") return;
    const t = setInterval(() => {
      if (document.hidden) return;
      carregarJobs();
      const id = abertoRef.current;
      if (id) {
        const d = detalheRef.current[id];
        if (!d || !TERMINAL.has(d.status)) carregarDetalhe(id);
      }
    }, 15000);
    return () => clearInterval(t);
  }, [motor, carregarJobs, carregarDetalhe]);

  function toggleAcao(a: string) {
    setAcoes((prev) => (prev.includes(a) ? prev.filter((x) => x !== a) : [...prev, a]));
  }

  async function gerar() {
    const name = slugify(nome);
    const p = prompt.trim();
    if (!name || !p) {
      toast.err("Dê um nome e descreva o personagem.");
      return;
    }
    setBusy(true);
    try {
      const payload: Record<string, unknown> = {
        type: "character",
        characterName: name,
        sourcePrompt: p,
        gameView,
        direction,
        actions: acoes,
      };
      if (preset === "lobit") {
        payload.candidatePromptPreset = "lobit-v1";
        payload.pixelSnapAnchor = true;
        payload.pixelSnap = true;
        payload.kColors = 64;
      }
      if (verde) payload.chroma = "#FF00FF";
      if (contexto.trim()) payload.actionContext = contexto.trim().slice(0, 130);
      const r = await Sprite.create(payload);
      if (r.notice) toast.ok(r.notice);
      toast.ok(`Na fila! ${r.credits ?? custoNovo} créditos debitados (passo que falhar é estornado).`);
      setPrompt("");
      carregarJobs();
      carregarMe();
      if (r.jobId) setAberto(r.jobId);
    } catch (e) {
      toast.err(e instanceof ApiError ? e.message : "Erro de rede ao enfileirar o sprite.");
    } finally {
      setBusy(false);
    }
  }

  // Animação avulsa em cima de um job pronto (reusa a âncora — 1 geração, não o plano todo).
  async function gerarAcao(job: SpriteJob) {
    const custom = novaAcao === "custom";
    const acao = custom ? slugify(customNome) : novaAcao;
    if (!acao) {
      toast.err(custom ? "Dê um nome pra ação personalizada." : "Escolha a animação.");
      return;
    }
    setBusy(true);
    try {
      const payload: Record<string, unknown> = {
        type: "action",
        characterName: job.characterName ?? slugify(nome) ?? "sprite",
        referenceJobId: job.id,
        actions: [acao],
      };
      if (custom) payload.actionBaselines = { [acao]: customBase };
      if (acaoContexto.trim()) payload.actionContext = acaoContexto.trim().slice(0, 130);
      const r = await Sprite.create(payload);
      toast.ok(`Animação "${acao}" na fila (${r.credits ?? 100} créditos).`);
      setQuickForm("");
      setNovaAcao("");
      setCustomNome("");
      setAcaoContexto("");
      carregarJobs();
      carregarMe();
      if (r.jobId) setAberto(r.jobId);
    } catch (e) {
      toast.err(e instanceof ApiError ? e.message : "Erro ao enfileirar a animação.");
    } finally {
      setBusy(false);
    }
  }

  // Variação: MESMO personagem, outra roupa (editPrompt = só o delta) ou outra direção.
  // Nunca re-descrever do zero — texto não pinna identidade; a referência sim.
  async function gerarVariacao(job: SpriteJob) {
    const name = slugify(varNome);
    if (!name) {
      toast.err("Dê um nome pra variação (ex.: mel-jaqueta).");
      return;
    }
    if (!varEdit.trim() && !varDir) {
      toast.err("Descreva a mudança OU escolha uma direção nova.");
      return;
    }
    setBusy(true);
    try {
      const payload: Record<string, unknown> = {
        type: "character",
        characterName: name,
        referenceJobId: job.id,
        actions: varAcoes,
      };
      if (varEdit.trim()) payload.editPrompt = varEdit.trim().slice(0, 1000);
      if (varDir) payload.direction = varDir;
      const r = await Sprite.create(payload);
      toast.ok(`Variação "${name}" na fila (${r.credits ?? 60 + varAcoes.length * 100} créditos).`);
      setQuickForm("");
      setVarNome("");
      setVarEdit("");
      setVarDir("");
      setVarAcoes([]);
      carregarJobs();
      carregarMe();
      if (r.jobId) setAberto(r.jobId);
    } catch (e) {
      toast.err(e instanceof ApiError ? e.message : "Erro ao enfileirar a variação.");
    } finally {
      setBusy(false);
    }
  }

  // Salvar no acervo: o engine baixa âncora/spritesheet/preview/manifest pro nosso storage
  // e o console anexa as peças visuais na Galeria (idempotente por job).
  async function salvar(job: SpriteJob) {
    setSalvando(job.id);
    try {
      const r = await Sprite.persist(job.id, job.characterName);
      setPersistido((prev) => ({ ...prev, [job.id]: r.files ?? {} }));
      toast.ok("Salvo no acervo — as peças estão na Galeria.");
    } catch (e) {
      toast.err(e instanceof ApiError ? e.message : "Erro ao salvar os artefatos.");
    } finally {
      setSalvando(null);
    }
  }

  function abrirJob(id: string) {
    if (aberto === id) {
      setAberto(null);
      return;
    }
    setAberto(id);
    setQuickForm("");
    carregarDetalhe(id);
  }

  // Artefatos de um job com as URLs locais (persistidas) por cima das remotas.
  function artefatos(job: SpriteJob): { name: string; url: string }[] {
    const local = persistido[job.id] ?? {};
    return (job.artifacts ?? []).map((a) => ({ name: a.name, url: local[a.name] ?? a.url }));
  }

  return (
    <>
      <h1 className="h1">Sprites</h1>
      <p className="sub">
        Personagens animados (spritesheet com prévia), tilesets, backgrounds com parallax,
        texturas seamless, GUI, ícones e props — assets prontos pra engine de jogo
        (Phaser, Unity, Godot, Love2D).
      </p>

      {/* Seletor de motor: local (nossa IA — imagem + i2v + normalização) × nuvem (hospedado). */}
      <div style={{ display: "flex", gap: 8, marginBottom: 20 }}>
        {([["local", "🦊 Personagem (nossa IA)"], ["nuvem", "☁️ Personagem (nuvem)"], ["assets", "🎮 Assets de jogo"]] as const).map(([v, l]) => (
          <button
            key={v}
            type="button"
            onClick={() => setMotor(v)}
            style={{
              padding: "8px 16px",
              borderRadius: 999,
              fontSize: ".86rem",
              fontWeight: 600,
              cursor: "pointer",
              border: `1px solid ${motor === v ? "var(--red)" : "var(--line2)"}`,
              background: motor === v ? "rgba(226,74,49,.16)" : "var(--bg2)",
              color: motor === v ? "var(--text)" : "var(--muted)",
            }}
          >
            {l}
          </button>
        ))}
      </div>

      {motor === "local" && <MotorLocal />}

      {motor === "assets" && <AssetStudio />}

      {motor === "nuvem" && (
        <>
      {keyMissing && (
        <div className="card" style={{ marginBottom: 24 }}>
          <div className="body">
            <span style={{ color: "var(--peach)" }}>
              Chave do motor de sprites não configurada — salve em{" "}
              <a href="/chaves-api" style={{ color: "var(--text)" }}>Chaves de geração</a> (grupo Sprites).
            </span>
          </div>
        </div>
      )}

      {/* ——— PERSONAGEM NOVO ——— */}
      <div className="card" style={{ marginBottom: 24 }}>
        <div className="body" style={{ gap: 14 }}>
          <div style={{ display: "flex", gap: 10, flexWrap: "wrap" }}>
            <label style={{ ...fieldLabel, flex: "1 1 200px" }}>
              <span style={capText}>Nome do personagem (vira o slug dos arquivos)</span>
              <input
                value={nome}
                onChange={(e) => setNome(e.target.value)}
                disabled={busy}
                placeholder="ex.: raposa-capuz"
                style={inputStyle}
              />
            </label>
          </div>

          <textarea
            value={prompt}
            onChange={(e) => setPrompt(e.target.value)}
            onKeyDown={(e) => {
              if ((e.metaKey || e.ctrlKey) && e.key === "Enter") gerar();
            }}
            disabled={busy}
            rows={3}
            placeholder="Ex.: uma raposa vermelha atarracada de capuz amarelo, botas de couro, estilo aventura"
            style={{ width: "100%", resize: "vertical", ...inputStyle, lineHeight: 1.55, fontSize: ".95rem" }}
          />

          {/* Animações — o preço é por animação, então a seleção é o orçamento. */}
          <div style={{ display: "flex", flexDirection: "column", gap: 8 }}>
            <span style={{ ...capText, color: "var(--text)", fontWeight: 600 }}>Animações (100 créditos cada)</span>
            <div style={{ display: "flex", gap: 8, flexWrap: "wrap" }}>
              {[...CORE_ACTIONS, ...(maisAcoes ? EXTRA_ACTIONS : [])].map((a) => (
                <button
                  key={a}
                  type="button"
                  onClick={() => toggleAcao(a)}
                  disabled={busy}
                  style={{
                    padding: "6px 12px",
                    borderRadius: 999,
                    fontSize: ".82rem",
                    cursor: "pointer",
                    border: `1px solid ${acoes.includes(a) ? "var(--red)" : "var(--line2)"}`,
                    background: acoes.includes(a) ? "rgba(226,74,49,.16)" : "var(--bg2)",
                    color: acoes.includes(a) ? "var(--text)" : "var(--muted)",
                  }}
                >
                  {a}
                </button>
              ))}
              <button
                type="button"
                onClick={() => setMaisAcoes((v) => !v)}
                disabled={busy}
                style={{ padding: "6px 12px", borderRadius: 999, fontSize: ".82rem", cursor: "pointer", border: "1px dashed var(--line2)", background: "transparent", color: "var(--muted)" }}
              >
                {maisAcoes ? "menos ações" : "mais ações…"}
              </button>
            </div>
          </div>

          <div style={{ display: "flex", gap: 10, alignItems: "flex-end", flexWrap: "wrap" }}>
            <label style={fieldLabel}>
              <span style={capText}>Tipo de jogo</span>
              <select
                value={gameView}
                onChange={(e) => {
                  setGameView(e.target.value);
                  // Aventura enxerga o personagem de 3/4 — sudoeste é a direção que casa.
                  if (e.target.value === "adventure" && direction === "w") setDirection("sw");
                }}
                disabled={busy}
                style={selectStyle}
              >
                {GAME_VIEWS.map(([v, l]) => (
                  <option key={v} value={v}>{l}</option>
                ))}
              </select>
            </label>
            <label style={fieldLabel}>
              <span style={capText}>Direção</span>
              <select value={direction} onChange={(e) => setDirection(e.target.value)} disabled={busy} style={selectStyle}>
                {DIRECTIONS.map(([v, l]) => (
                  <option key={v} value={v}>{l}</option>
                ))}
              </select>
            </label>
            <label style={fieldLabel}>
              <span style={capText}>Estilo</span>
              <select value={preset} onChange={(e) => setPreset(e.target.value)} disabled={busy} style={selectStyle}
                title="Alta fidelidade: textura rica (padrão). Pixel retrô: silhueta compacta num grid de pixel real — melhor pra criaturas e personagens blocudos.">
                {PRESETS.map(([v, l]) => (
                  <option key={v} value={v}>{l}</option>
                ))}
              </select>
            </label>
            <label style={{ ...fieldLabel, flex: "1 1 220px" }}>
              <span style={capText}>Contexto de movimento (opcional, até 130)</span>
              <input
                value={contexto}
                onChange={(e) => setContexto(e.target.value.slice(0, 130))}
                disabled={busy}
                placeholder="ex.: caminhada lenta e relaxada, tronco ereto"
                style={inputStyle}
              />
            </label>
            <label
              style={{ ...fieldLabel, alignSelf: "center", flexDirection: "row", alignItems: "center", gap: 7 }}
              title="Personagem verde/teal/lima: troca o fundo de recorte pra magenta — senão o recorte come o personagem."
            >
              <input type="checkbox" checked={verde} onChange={(e) => setVerde(e.target.checked)} disabled={busy} />
              <span style={{ fontSize: ".86rem" }}>🟢 Personagem verde</span>
            </label>
            <button
              type="button"
              className="btn ok"
              onClick={gerar}
              disabled={busy || keyMissing}
              style={{ flex: "0 0 auto", display: "flex", alignItems: "center", gap: 8, padding: "11px 20px" }}
            >
              {busy ? <span className="spinner" style={{ width: 15, height: 15 }} /> : <Gamepad2 size={16} />}
              {busy ? "Enfileirando…" : "Gerar sprite"}
            </button>
            <span className="badge" style={{ marginLeft: "auto" }}>
              custo: {custoNovo} créditos{saldo != null ? ` · saldo: ${saldo}` : ""}
            </span>
          </div>

          <span style={capText}>
            O job roda na nuvem e leva alguns minutos (uma geração por âncora e por animação).
            A lista abaixo acompanha sozinha; passo que falhar é estornado.
          </span>
        </div>
      </div>

      {/* ——— JOBS ——— */}
      <div style={{ display: "flex", alignItems: "center", gap: 10, marginBottom: 10 }}>
        <h2 style={{ fontSize: "1.05rem", margin: 0 }}>Gerações</h2>
        <button type="button" className="btn" onClick={() => { carregarJobs(); carregarMe(); }} style={{ display: "flex", alignItems: "center", gap: 6, padding: "6px 12px", fontSize: ".82rem" }}>
          <RefreshCcw size={13} /> Atualizar
        </button>
      </div>

      {jobs.length === 0 && (
        <p style={capText}>Nenhuma geração ainda — descreva um personagem aí em cima. 🦊</p>
      )}

      {jobs.map((j) => {
        const d = detalhe[j.id] ?? j;
        const badge = statusBadge(d.status);
        const done = TERMINAL.has(d.status);
        const expandido = aberto === j.id;
        const arts = expandido ? artefatos(d) : [];
        const previews = arts.filter((a) => a.name.endsWith("/preview"));
        const sheets = arts.filter((a) => a.name.endsWith("/spritesheet"));
        const anchors = arts.filter((a) => a.name.startsWith("anchors/"));
        const avisos = (d.steps ?? []).flatMap((s) => s.warnings ?? []);
        const falhas = (d.steps ?? []).filter((s) => s.error).map((s) => `${s.id ?? "passo"}: ${s.error}`);
        return (
          <div key={j.id} className="card" style={{ marginBottom: 12 }}>
            <div
              className="body"
              onClick={() => abrirJob(j.id)}
              style={{ display: "flex", alignItems: "center", gap: 12, cursor: "pointer", flexWrap: "wrap" }}
            >
              <strong style={{ fontSize: ".95rem" }}>{d.characterName ?? j.id.slice(-8)}</strong>
              {d.type === "action" && <span style={capText}>animação{d.actions?.length ? `: ${d.actions.join(", ")}` : ""}</span>}
              <span style={{ fontSize: ".78rem", fontWeight: 700, color: badge.color }}>
                {!done && <span className="spinner" style={{ width: 11, height: 11, marginRight: 6, display: "inline-block" }} />}
                {badge.label}
              </span>
              {!done && d.progress?.total ? (
                <span style={capText}>{(d.progress.index ?? 0) + 1}/{d.progress.total} · {d.progress.step ?? ""}</span>
              ) : null}
              {typeof d.creditsDebited === "number" && (
                <span style={capText}>
                  {d.creditsDebited - (d.creditsRefunded ?? 0)} créditos{(d.creditsRefunded ?? 0) > 0 ? ` (${d.creditsRefunded} estornados)` : ""}
                </span>
              )}
              <span style={{ marginLeft: "auto", color: "var(--muted)" }}>
                {expandido ? <ChevronUp size={16} /> : <ChevronDown size={16} />}
              </span>
            </div>

            {expandido && (
              <div className="body" style={{ borderTop: "1px solid var(--line2)", gap: 12 }}>
                {falhas.length > 0 && (
                  <div style={{ color: "var(--red)", fontSize: ".82rem" }}>
                    {falhas.map((f) => <div key={f}>✗ {f} (estornado — dá pra re-tentar como animação avulsa)</div>)}
                  </div>
                )}
                {avisos.length > 0 && (
                  <div style={{ color: "var(--peach)", fontSize: ".82rem" }}>
                    {avisos.map((a2) => <div key={a2}>⚠ {a2}</div>)}
                  </div>
                )}

                {previews.length + anchors.length > 0 && (
                  <div style={{ display: "flex", gap: 12, flexWrap: "wrap" }}>
                    {anchors.filter((a) => !a.name.includes("candidate")).map((a) => (
                      <figure key={a.name} style={{ margin: 0, textAlign: "center" }}>
                        {/* eslint-disable-next-line @next/next/no-img-element */}
                        <img src={a.url} alt={a.name} style={{ height: 130, imageRendering: "pixelated", borderRadius: 10, border: "1px solid var(--line2)", background: "var(--bg2)" }} />
                        <figcaption style={{ ...capText, fontSize: ".72rem" }}>âncora</figcaption>
                      </figure>
                    ))}
                    {previews.map((a) => (
                      <figure key={a.name} style={{ margin: 0, textAlign: "center" }}>
                        {/* eslint-disable-next-line @next/next/no-img-element */}
                        <img src={a.url} alt={a.name} style={{ height: 130, imageRendering: "pixelated", borderRadius: 10, border: "1px solid var(--line2)", background: "var(--bg2)" }} />
                        <figcaption style={{ ...capText, fontSize: ".72rem" }}>{a.name.replace("/preview", "")}</figcaption>
                      </figure>
                    ))}
                  </div>
                )}

                {sheets.length > 0 && (
                  <div style={{ display: "flex", gap: 10, flexWrap: "wrap" }}>
                    {sheets.map((a) => (
                      <a key={a.name} className="btn" href={a.url} target="_blank" rel="noreferrer" style={{ fontSize: ".8rem", textDecoration: "none" }}>
                        spritesheet {a.name.replace("/spritesheet", "")}
                      </a>
                    ))}
                  </div>
                )}

                <div style={{ display: "flex", gap: 8, flexWrap: "wrap" }}>
                  {done && (d.status === "completed" || d.status === "partial") && (
                    <>
                      <button type="button" className="btn ok" onClick={() => salvar(d)} disabled={salvando === j.id} style={{ display: "flex", alignItems: "center", gap: 6, fontSize: ".82rem" }}>
                        {salvando === j.id ? <span className="spinner" style={{ width: 12, height: 12 }} /> : <Save size={14} />}
                        Salvar no acervo
                      </button>
                      {d.type !== "action" && (
                        <>
                          <button type="button" className="btn" onClick={() => setQuickForm(quickForm === "acao" ? "" : "acao")} style={{ display: "flex", alignItems: "center", gap: 6, fontSize: ".82rem" }}>
                            <Plus size={14} /> Nova animação (100)
                          </button>
                          <button type="button" className="btn" onClick={() => setQuickForm(quickForm === "variacao" ? "" : "variacao")} style={{ display: "flex", alignItems: "center", gap: 6, fontSize: ".82rem" }}>
                            <Shirt size={14} /> Variação (60+)
                          </button>
                        </>
                      )}
                    </>
                  )}
                  <a className="btn" href={RUN_PAGE + j.id} target="_blank" rel="noreferrer" style={{ display: "flex", alignItems: "center", gap: 6, fontSize: ".82rem", textDecoration: "none" }}
                    title="Página viva do job: progresso passo a passo e o seletor de quadros (re-picks grátis)">
                    <ExternalLink size={14} /> Acompanhar / ajustar quadros
                  </a>
                </div>

                {/* Animação avulsa: reusa a âncora deste job — 1 geração, e o slot padrão fica livre. */}
                {quickForm === "acao" && (
                  <div style={{ display: "flex", gap: 10, alignItems: "flex-end", flexWrap: "wrap", borderTop: "1px dashed var(--line2)", paddingTop: 10 }}>
                    <label style={fieldLabel}>
                      <span style={capText}>Animação</span>
                      <select value={novaAcao} onChange={(e) => setNovaAcao(e.target.value)} style={selectStyle}>
                        <option value="">Escolher…</option>
                        {[...CORE_ACTIONS, ...EXTRA_ACTIONS].map((a) => (
                          <option key={a} value={a}>{a}</option>
                        ))}
                        <option value="custom">✨ personalizada…</option>
                      </select>
                    </label>
                    {novaAcao === "custom" && (
                      <>
                        <label style={fieldLabel}>
                          <span style={capText}>Nome (slug)</span>
                          <input value={customNome} onChange={(e) => setCustomNome(e.target.value)} placeholder="ex.: sliding-tackle" style={inputStyle} />
                        </label>
                        <label style={fieldLabel}>
                          <span style={capText}>Base (família de movimento)</span>
                          <select value={customBase} onChange={(e) => setCustomBase(e.target.value)} style={selectStyle}>
                            {BASELINES.map((b) => (
                              <option key={b} value={b}>{b}</option>
                            ))}
                          </select>
                        </label>
                      </>
                    )}
                    <label style={{ ...fieldLabel, flex: "1 1 200px" }}>
                      <span style={capText}>Contexto (opcional, até 130)</span>
                      <input value={acaoContexto} onChange={(e) => setAcaoContexto(e.target.value.slice(0, 130))} placeholder="ex.: deslize rasteiro agressivo, perna estendida" style={inputStyle} />
                    </label>
                    <button type="button" className="btn ok" onClick={() => gerarAcao(d)} disabled={busy} style={{ fontSize: ".82rem" }}>
                      Enfileirar
                    </button>
                  </div>
                )}

                {/* Variação: identidade vem da REFERÊNCIA (nunca re-descrever) — só o delta muda. */}
                {quickForm === "variacao" && (
                  <div style={{ display: "flex", flexDirection: "column", gap: 10, borderTop: "1px dashed var(--line2)", paddingTop: 10 }}>
                    <div style={{ display: "flex", gap: 10, flexWrap: "wrap" }}>
                      <label style={{ ...fieldLabel, flex: "1 1 180px" }}>
                        <span style={capText}>Nome da variação</span>
                        <input value={varNome} onChange={(e) => setVarNome(e.target.value)} placeholder="ex.: raposa-capuz-jaqueta" style={inputStyle} />
                      </label>
                      <label style={fieldLabel}>
                        <span style={capText}>Nova direção (opcional)</span>
                        <select value={varDir} onChange={(e) => setVarDir(e.target.value)} style={selectStyle}>
                          <option value="">Manter a atual</option>
                          {DIRECTIONS.map(([v, l]) => (
                            <option key={v} value={v}>{l}</option>
                          ))}
                        </select>
                      </label>
                    </div>
                    <textarea
                      value={varEdit}
                      onChange={(e) => setVarEdit(e.target.value)}
                      rows={2}
                      placeholder='Só a MUDANÇA: "troque o capuz por uma jaqueta jeans e adicione tênis branco". O que não for citado é preservado. Vazio + direção nova = mesmo visual virado.'
                      style={{ width: "100%", resize: "vertical", ...inputStyle, lineHeight: 1.5 }}
                    />
                    <div style={{ display: "flex", gap: 8, flexWrap: "wrap", alignItems: "center" }}>
                      <span style={capText}>Animações da variação:</span>
                      {CORE_ACTIONS.map((a) => (
                        <button
                          key={a}
                          type="button"
                          onClick={() => setVarAcoes((prev) => (prev.includes(a) ? prev.filter((x) => x !== a) : [...prev, a]))}
                          style={{
                            padding: "4px 10px", borderRadius: 999, fontSize: ".78rem", cursor: "pointer",
                            border: `1px solid ${varAcoes.includes(a) ? "var(--red)" : "var(--line2)"}`,
                            background: varAcoes.includes(a) ? "rgba(226,74,49,.16)" : "var(--bg2)",
                            color: varAcoes.includes(a) ? "var(--text)" : "var(--muted)",
                          }}
                        >
                          {a}
                        </button>
                      ))}
                      <button type="button" className="btn ok" onClick={() => gerarVariacao(d)} disabled={busy} style={{ fontSize: ".82rem", marginLeft: "auto" }}>
                        Enfileirar ({60 + varAcoes.length * 100})
                      </button>
                    </div>
                  </div>
                )}
              </div>
            )}
          </div>
        );
      })}
        </>
      )}
    </>
  );
}
