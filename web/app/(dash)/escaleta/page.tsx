"use client";

// ESCALETA (F4 do fluxo image→cena): o mini-GDD. Um PROJETO (história) tem uma lista ORDENADA de
// cenas ligando personagem × cenário. Cada cena traz o cabeçalho (LOCAL/INT-EXT/TEMPO) e a
// dramaturgia (objetivo/conflito/virada) do curso. Reordenar sobe/desce e persiste em /scenes/reorder.

import { useEffect, useState } from "react";
import { sfetch } from "@/lib/api";
import { useToast } from "@/components/ui/Toast";
import { SkeletonCards } from "@/components/ui/Spinner";
import { SceneCard } from "@/components/SceneCard";
import { Plus, Trash2, FileDown, Clapperboard, Sparkles, Map as MapIcon, Stethoscope, X, Users } from "lucide-react";
import { daEscaleta, salvarAgora, carregar, type PlanoEscaleta } from "@/lib/roteiro";
import { SeletorModeloImagem } from "@/components/SeletorModeloImagem";
import { styleOptions } from "@/lib/imageStyles";

// A imagem entra junto: é a âncora de identidade que a cena leva pro canvas (base do
// personagem, imagem do cenário). Sem ela a produção redesenha tudo a cada cena.
type Ref = { id: number; name: string; url?: string | null; mesh?: string | null };
type Scene = {
  id: number; ordem: number; ato?: number | null; scenario_id?: number | null; character_ids?: number[] | null;
  element_ids?: number[] | null;
  local?: string | null; int_ext?: string | null; tempo?: string | null;
  motivacao?: string | null; objetivo_cena?: string | null; conflito_cena?: string | null;
  virada?: boolean; tamanho?: string | null; resumo?: string | null; narracao?: string | null;
  // Decupagem: cada plano vira um nó na Montagem. Cena sem plano vira um nó só, como antes.
  shots?: PlanoEscaleta[];
};
// image_model/image_style = o PADRÃO VISUAL da história: escolhido UMA vez aqui e herdado por
// todo personagem, cenário e elemento que ela criar. Sem isso cada aba escolhia o seu e o mesmo
// filme saía com três estéticas.
type Project = { id: number; name: string; argumento?: string | null; image_model?: string | null; image_style?: string | null; scenes?: Scene[] };
type ModeloImagem = { slug: string; display_name: string; cost_credits: number | null; subtype?: string };
// Diagnóstico do 📐 Doutor de Roteiro: ele APONTA, não corrige — nada aqui altera cena nenhuma.
// `cena` é o NÚMERO na escaleta (1..N); null = problema da história inteira (estrutura, arco).
type Nota = { cena: number | null; nivel: string; problema: string; sugestao?: string };
type Diagnostico = { veredito: string; notas: Nota[] };

const NIVEL: Record<string, { rotulo: string; cor: string }> = {
  grave: { rotulo: "grave", cor: "var(--red, #e0575b)" },
  atencao: { rotulo: "atenção", cor: "var(--peach, #e8a55c)" },
  ok: { rotulo: "menor", cor: "var(--muted)" },
};

const refs = (v: unknown): Ref[] =>
  (Array.isArray(v) ? v : []).map((x: { id: number; name: string; base_url?: string | null; image_url?: string | null; mesh_url?: string | null }) =>
    ({ id: x.id, name: x.name, url: x.base_url ?? x.image_url ?? null, mesh: x.mesh_url ?? null }));

export default function EscaletaPage() {
  const toast = useToast();
  const [projects, setProjects] = useState<Project[]>([]);
  const [scenarios, setScenarios] = useState<Ref[]>([]);
  const [characters, setCharacters] = useState<Ref[]>([]);
  const [elements, setElements] = useState<Ref[]>([]);
  const [loading, setLoading] = useState(true);
  const [selId, setSelId] = useState<number | null>(null);
  const [busy, setBusy] = useState(false);
  // TEXTO DO ROTEIRO (o `argumento` do projeto): é daqui que a IA escreve a escaleta, e é o
  // documento que o filme inteiro herda. Existia no banco desde sempre e nunca teve campo na
  // tela — a história só vivia partida em cenas, sem lugar pra ideia inteira.
  const [argumento, setArgumento] = useState("");
  const [planejando, setPlanejando] = useState(false);
  const [nCenas, setNCenas] = useState(8);
  // Diagnóstico do Doutor: vive só na tela (não persiste). É uma leitura do roteiro NAQUELE
  // momento — guardar levaria a mostrar crítica de uma versão que já foi corrigida.
  const [revisando, setRevisando] = useState(false);
  const [diag, setDiag] = useState<Diagnostico | null>(null);
  // PADRÃO VISUAL da história — modelo e técnica com que TUDO dela é gerado. Vive no projeto
  // (não na tela): reabrir o Roteiro amanhã tem de encontrar a mesma escolha, senão o personagem
  // criado hoje e o cenário criado amanhã saem em estéticas diferentes.
  const [modelos, setModelos] = useState<ModeloImagem[]>([]);
  const [modelo, setModelo] = useState("");
  const [estilo, setEstilo] = useState("realista");
  const [criandoElenco, setCriandoElenco] = useState(false);

  useEffect(() => {
    sfetch("/api/gen-models?kind=image").then((r) => r.json()).then((j) => {
      const list: ModeloImagem[] = (Array.isArray(j) ? j : j?.data ?? []).filter((m: ModeloImagem) => m.subtype === "text_to_image");
      setModelos(list);
      // Default = o TOPO (img-ultra), como na aba Personagens: a âncora de identidade se gera uma
      // vez por asset — qualidade vale mais que centavo aqui.
      const best = list.find((m) => m.slug === "img-ultra") ?? list[0];
      if (best) setModelo((cur) => cur || best.slug);
    }).catch(() => {});
  }, []);

  useEffect(() => {
    Promise.allSettled([
      sfetch("/api/projects").then((r) => r.json()),
      sfetch("/api/scenarios").then((r) => r.json()),
      sfetch("/api/characters").then((r) => r.json()),
      sfetch("/api/elements").then((r) => r.json()),
    ])
      .then(([pp, ss, cc, el]) => {
        const proj: Project[] = pp.status === "fulfilled" && Array.isArray(pp.value) ? pp.value : [];
        setProjects(proj);
        setSelId((cur) => cur ?? (proj[0]?.id ?? null));
        setScenarios(ss.status === "fulfilled" ? refs(ss.value) : []);
        setCharacters(cc.status === "fulfilled" ? refs(cc.value) : []);
        setElements(el.status === "fulfilled" ? refs(el.value) : []);
      })
      .finally(() => setLoading(false));
  }, []);

  const sel = projects.find((p) => p.id === selId) ?? null;
  // Troca de história → recarrega o texto do roteiro daquela (sem isso o campo mostrava o texto
  // da anterior e um salvar acidental sobrescreveria a história errada).
  // O diagnóstico sai junto: crítica da história A pendurada na história B é pior que nenhuma.
  useEffect(() => {
    setArgumento(sel?.argumento ?? "");
    setDiag(null);
    // Padrão visual segue a história: sem isto, trocar de projeto mantinha na tela a escolha do
    // anterior e o próximo "Criar elenco" gerava tudo no estilo do filme errado.
    setEstilo(sel?.image_style || "realista");
    setModelo((cur) => sel?.image_model || cur);
  }, [sel?.id]); // eslint-disable-line react-hooks/exhaustive-deps

  // Grava a escolha no projeto (PATCH leve — não recarrega as cenas).
  async function salvarPadraoVisual(patch: { image_model?: string; image_style?: string }) {
    if (!sel) return;
    setProjects((prev) => prev.map((p) => (p.id === sel.id ? { ...p, ...patch } : p)));
    await sfetch(`/api/projects/${sel.id}`, { method: "PATCH", body: JSON.stringify(patch) }).catch(() => {});
  }

  const patchScenes = (fn: (scenes: Scene[]) => Scene[]) =>
    setProjects((prev) => prev.map((p) => (p.id === selId ? { ...p, scenes: fn(p.scenes ?? []) } : p)));

  async function novoProjeto() {
    setBusy(true);
    try {
      const r = await sfetch("/api/projects", { method: "POST", body: JSON.stringify({ name: "Nova história" }) });
      const d = await r.json().catch(() => ({}));
      if (!r.ok || !d?.ok) { toast.err("Não deu pra criar."); return; }
      setProjects((prev) => [d.project, ...prev]);
      setSelId(d.project.id);
    } catch { toast.err("Erro ao criar."); } finally { setBusy(false); }
  }

  async function renomear(name: string) {
    if (!sel) return;
    setProjects((prev) => prev.map((p) => (p.id === sel.id ? { ...p, name } : p)));
    await sfetch(`/api/projects/${sel.id}`, { method: "PATCH", body: JSON.stringify({ name }) }).catch(() => {});
  }

  async function excluirProjeto() {
    if (!sel || !confirm(`Excluir a história "${sel.name}" e todas as cenas?`)) return;
    try {
      await sfetch(`/api/projects/${sel.id}`, { method: "DELETE" });
      setProjects((prev) => {
        const rest = prev.filter((p) => p.id !== sel.id);
        setSelId(rest[0]?.id ?? null);
        return rest;
      });
    } catch { toast.err("Não deu pra excluir."); }
  }

  async function salvarArgumento() {
    if (!sel) return;
    // Só grava se MUDOU de verdade: blur de campo intocado, com a lista de projetos velha em
    // memória (campo vazio), fazia PATCH de "" por cima da premissa salva no servidor — era
    // assim que a premissa "sumia" (2026-07-29). Apagar de propósito continua funcionando:
    // quem tinha texto e limpou passa no dirty-check.
    if (argumento.trim() === (sel.argumento ?? "").trim()) return;
    setProjects((prev) => prev.map((p) => (p.id === sel.id ? { ...p, argumento } : p)));
    await sfetch(`/api/projects/${sel.id}`, { method: "PATCH", body: JSON.stringify({ argumento }) }).catch(() => {});
    toast.ok("Prompt do filme salvo.");
  }

  // A IA lê o texto do roteiro e escreve as cenas (cabeçalho + dramaturgia + quem entra em cada
  // uma). ACRESCENTA ao que existe — não apaga cena nenhuma, então repetir é seguro.
  async function planejarComIA() {
    if (!sel) return;
    const brief = argumento.trim();
    if (!brief) { toast.err("Escreva o prompt do filme antes — é dele que a IA tira as cenas."); return; }
    setPlanejando(true);
    try {
      const r = await sfetch(`/api/projects/${sel.id}/plan`, {
        method: "POST", body: JSON.stringify({ brief, cenas: nCenas, image_model: modelo, image_style: estilo }),
      });
      const d = await r.json().catch(() => ({}));
      if (!r.ok || !d?.ok) { toast.err(d?.error || "Não foi possível planejar."); return; }
      setProjects((prev) => prev.map((p) => (p.id === sel.id ? d.project : p)));
      const fichas = [
        d.novos_personagens ? `${d.novos_personagens} personagem(ns)` : "",
        d.novos_cenarios ? `${d.novos_cenarios} cenário(s)` : "",
        d.novos_elementos ? `${d.novos_elementos} elemento(s)` : "",
      ].filter(Boolean).join(", ");
      toast.ok(`${d.criadas} cena(s) escritas pela IA.${fichas ? ` Criei ${fichas} — revise o roteiro e clique em "Criar personagens, cenários e elementos".` : ""}`);
      // A biblioteca acabou de crescer: sem recarregar, os cartões de cena mostrariam o vínculo
      // com uma ficha que a tela ainda não conhece (e o seletor abriria vazio).
      recarregarFichas();
    } catch {
      toast.err("Erro ao falar com a IA.");
    } finally {
      setPlanejando(false);
    }
  }

  // Recarrega as três bibliotecas (personagem/cenário/elemento) — usado depois de qualquer
  // etapa que cria ou dá rosto a ficha, pra tela não ficar mostrando o acervo de antes.
  async function recarregarFichas() {
    const [ss, cc, el] = await Promise.allSettled([
      sfetch("/api/scenarios").then((r) => r.json()),
      sfetch("/api/characters").then((r) => r.json()),
      sfetch("/api/elements").then((r) => r.json()),
    ]);
    if (ss.status === "fulfilled") setScenarios(refs(ss.value));
    if (cc.status === "fulfilled") setCharacters(refs(cc.value));
    if (el.status === "fulfilled") setElements(refs(el.value));
  }

  // 🎭 BOTÃO ÚNICO — dá rosto de uma vez a tudo que a história cita e ainda não tem imagem:
  // personagens, cenários e elementos, no MESMO modelo e na MESMA técnica. Era a etapa manual
  // entre o roteiro e a montagem: três abas, item por item, cada uma com a sua escolha de motor.
  // Assíncrono: dispara os jobs e as abas acompanham no polling de sempre.
  async function criarElenco() {
    if (!sel) return;
    const faltando = [
      ...characters.filter((c) => !c.url),
      ...scenarios.filter((c) => !c.url),
      ...elements.filter((c) => !c.url),
    ].length;
    if (!confirm(`Gerar a imagem de personagens, cenários e elementos desta história que ainda não têm (até ${faltando} no acervo), no modelo e na técnica escolhidos aqui? Cada imagem consome da sua cota.`)) return;
    setCriandoElenco(true);
    try {
      const r = await sfetch(`/api/projects/${sel.id}/cast`, {
        method: "POST", body: JSON.stringify({ image_model: modelo, image_style: estilo }),
      });
      const d = await r.json().catch(() => ({}));
      if (!r.ok || !d?.ok) { toast.err(d?.error || "Não foi possível criar o elenco."); return; }
      const feito = [
        d.personagens ? `${d.personagens} personagem(ns)` : "",
        d.cenarios ? `${d.cenarios} cenário(s)` : "",
        d.elementos ? `${d.elementos} elemento(s)` : "",
      ].filter(Boolean).join(", ");
      if (!feito) {
        toast.ok(d.prontos ? "Tudo desta história já tem imagem." : "Nada a gerar — as fichas estão sem descrição.");
      } else {
        toast.ok(`Gerando ${feito}. Acompanhe nas abas Personagens, Cenários e Elementos.${d.cota ? " Parei no limite do plano — o resto fica pra depois." : ""}`);
      }
      recarregarFichas();
    } catch {
      toast.err("Erro ao criar o elenco.");
    } finally {
      setCriandoElenco(false);
    }
  }

  // 📐 DOUTOR DE ROTEIRO: lê a escaleta SALVA e devolve o diagnóstico. Não muda cena nenhuma —
  // aponta o defeito (cena sem conflito, virada faltando, buraco de continuidade) e quem corrige
  // é o autor. Vem antes de produzir: descobrir que a cena 4 não tem conflito depois de gerar o
  // clipe é caro.
  async function revisarComDoutor() {
    if (!sel) return;
    setRevisando(true);
    try {
      const r = await sfetch(`/api/projects/${sel.id}/review`, { method: "POST", body: JSON.stringify({}) });
      const d = await r.json().catch(() => ({}));
      if (!r.ok || !d?.ok) { toast.err(d?.error || "Não foi possível revisar."); return; }
      setDiag({ veredito: String(d.veredito ?? ""), notas: Array.isArray(d.notas) ? d.notas : [] });
      toast.ok(d.notas?.length ? `${d.notas.length} apontamento(s).` : "Nenhum defeito apontado.");
    } catch {
      toast.err("Erro ao falar com o Doutor de Roteiro.");
    } finally {
      setRevisando(false);
    }
  }

  // Criar CENÁRIO aqui: a cena precisa de um lugar, e mandar o usuário pra outra aba no meio da
  // escrita quebra o raciocínio (e volta com o formulário perdido). Cria só a ficha — a imagem
  // sai depois, na aba Cenários, como a base do personagem.
  async function novoCenario() {
    const nome = prompt("Nome do cenário (ex.: Feira da praça, Beco da chuva)");
    if (!nome?.trim()) return;
    try {
      const r = await sfetch("/api/scenarios", { method: "POST", body: JSON.stringify({ name: nome.trim() }) });
      const d = await r.json().catch(() => ({}));
      if (!r.ok || !d?.ok) { toast.err(d?.error || "Não deu pra criar o cenário."); return; }
      setScenarios((prev) => [...prev, { id: d.scenario.id, name: d.scenario.name, url: null }]);
      toast.ok(`Cenário "${d.scenario.name}" criado — já dá pra usar nas cenas (gere a imagem na aba Cenários).`);
    } catch { toast.err("Erro ao criar o cenário."); }
  }

  async function novaCena() {
    if (!sel) return;
    try {
      const r = await sfetch("/api/scenes", { method: "POST", body: JSON.stringify({ project_id: sel.id }) });
      const d = await r.json().catch(() => ({}));
      if (!r.ok || !d?.ok) { toast.err("Não deu pra criar a cena."); return; }
      patchScenes((sc) => [...sc, d.scene]);
    } catch { toast.err("Erro ao criar a cena."); }
  }

  // F5: baixa a "Bíblia do Projeto" (Markdown) — personagens + cenários + escaleta desta história.
  // PONTE Escaleta → Roteiro: a estrutura vira produção sem redigitar nada. Cada cena vira um
  // nó já com o prompt montado (cabeçalho + resumo + intenção) e as âncoras de personagem e
  // cenário. Confirma antes de sobrescrever um grafo que já tenha trabalho.
  function produzirNoRoteiro() {
    if (!sel?.scenes?.length) {
      toast.err("Adicione ao menos uma cena antes de produzir.");
      return;
    }
    const atual = carregar();
    if (atual.nodes.length && !confirm(`O canvas já tem ${atual.nodes.length} cena(s). Substituir pelo roteiro "${sel.name}"?`)) return;
    salvarAgora(daEscaleta(sel.name, sel.scenes, scenarios, characters, elements));
    window.location.href = "/roteiro";
  }

  // Baixa um arquivo gerado pela API (a resposta já vem com o nome no Content-Disposition, mas o
  // fetch autenticado devolve blob — o nome tem que ser remontado aqui).
  async function baixar(rota: string, ext: string, prefixo = "") {
    if (!sel) return;
    try {
      const r = await sfetch(rota);
      if (!r.ok) { toast.err("Não foi possível gerar o arquivo."); return; }
      const blob = await r.blob();
      const url = URL.createObjectURL(blob);
      const a = document.createElement("a");
      a.href = url;
      a.download = `${prefixo}${sel.name.toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, "") || "projeto"}.${ext}`;
      document.body.appendChild(a);
      a.click();
      a.remove();
      URL.revokeObjectURL(url);
    } catch { toast.err("Erro ao baixar."); }
  }

  const baixarBiblia = () => baixar(`/api/projects/${sel?.id}/bible`, "md", "biblia-");
  // O roteiro no formato que o resto do mundo lê: Fountain (texto puro, abre no Highland, Beat,
  // Fade In) e FDX (o XML do Final Draft, que WriterDuet, Celtx e Arc Studio importam). Sem isto a
  // escaleta é um beco sem saída — nenhum produtor ou montador abre a Bíblia em Markdown.
  const baixarFountain = () => baixar(`/api/projects/${sel?.id}/screenplay?format=fountain`, "fountain");
  const baixarFdx = () => baixar(`/api/projects/${sel?.id}/screenplay?format=fdx`, "fdx");

  // 🎞️ FOLHA DE STORYBOARD — o documento que se confere ANTES de montar. Não gera imagem nem
  // gasta crédito: os painéis são os quadros que já existem e os rótulos saem da decupagem. Abre
  // em aba nova porque é peça pra OLHAR (e imprimir), não mais um card na tela.
  const [compondo, setCompondo] = useState(false);
  async function abrirStoryboard() {
    if (!sel || compondo) return;
    setCompondo(true);
    try {
      const j = await sfetch(`/api/projects/${sel.id}/storyboard`, { method: "POST" }).then((r) => r.json());
      if (!j?.ok || !j?.url) {
        toast.err(j?.error || "não foi possível compor a folha");
        return;
      }
      // Plano sem quadro vira célula vazia na folha — avisar quantos são evita que a lacuna
      // passe por engano, que é justamente o que a folha existe pra impedir.
      if (j.sem_quadro > 0) toast.ok(`Folha pronta — ${j.sem_quadro} de ${j.panels} planos ainda sem quadro`);
      window.open(j.url, "_blank", "noopener");
    } catch {
      toast.err("não foi possível compor a folha");
    } finally {
      setCompondo(false);
    }
  }

  async function mover(id: number, dir: -1 | 1) {
    if (!sel) return;
    const list = [...(sel.scenes ?? [])];
    const i = list.findIndex((x) => x.id === id);
    const j = i + dir;
    if (i < 0 || j < 0 || j >= list.length) return;
    [list[i], list[j]] = [list[j], list[i]];
    patchScenes(() => list);
    await sfetch("/api/scenes/reorder", { method: "POST", body: JSON.stringify({ ids: list.map((x) => x.id) }) }).catch(() => {});
  }

  return (
    <>
      <h1 className="h1">Roteiro</h1>
      <p className="sub">Escreva a história e quebre em cenas — cada uma com lugar, hora, quem entra e o que está em jogo. É daqui que a Montagem produz.</p>

      {loading ? (
        <SkeletonCards />
      ) : (
        <>
          {/* seletor de história */}
          <div style={{ display: "flex", gap: 8, alignItems: "center", flexWrap: "wrap", marginBottom: 24 }}>
            {projects.length > 0 && (
              <select value={selId ?? ""} onChange={(e) => setSelId(Number(e.target.value))}
                style={{ background: "var(--bg2)", color: "var(--text)", border: "1px solid var(--line2)", borderRadius: 10, padding: "9px 12px", fontSize: ".9rem", fontFamily: "inherit", minWidth: 200 }}>
                {projects.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
              </select>
            )}
            <button type="button" className="btn ok" onClick={novoProjeto} disabled={busy} style={{ flex: "none", display: "inline-flex", alignItems: "center", gap: 6, padding: "9px 16px" }}>
              <Plus size={15} /> Nova história
            </button>
            {sel && <button type="button" className="btn edit" onClick={novoCenario} style={{ flex: "none", display: "inline-flex", alignItems: "center", gap: 6, padding: "9px 14px" }}><MapIcon size={14} /> Novo cenário</button>}
            {sel && <button type="button" className="btn edit" onClick={baixarBiblia} style={{ flex: "none", display: "inline-flex", alignItems: "center", gap: 6, padding: "9px 14px" }}><FileDown size={14} /> Baixar Bíblia</button>}
            {/* Exportar o ROTEIRO (não a Bíblia): é o que abre na ferramenta de quem vai produzir. */}
            {(sel?.scenes?.length ?? 0) > 0 && (
              <>
                <button type="button" className="btn edit" onClick={abrirStoryboard} disabled={compondo}
                  title="Folha de storyboard — os quadros já gerados em grade, com plano, tempo e ação. Não gasta crédito: é composição, não geração."
                  style={{ flex: "none", display: "inline-flex", alignItems: "center", gap: 6, padding: "9px 14px" }}>
                  <Clapperboard size={14} /> {compondo ? "Compondo…" : "Storyboard"}
                </button>
                <button type="button" className="btn edit" onClick={baixarFountain}
                  title="Roteiro em Fountain — texto puro, abre no Highland, Beat, Fade In e importa em qualquer editor moderno"
                  style={{ flex: "none", display: "inline-flex", alignItems: "center", gap: 6, padding: "9px 14px" }}>
                  <FileDown size={14} /> Fountain
                </button>
                <button type="button" className="btn edit" onClick={baixarFdx}
                  title="Roteiro em .fdx — o formato do Final Draft, que WriterDuet, Celtx e Arc Studio também importam"
                  style={{ flex: "none", display: "inline-flex", alignItems: "center", gap: 6, padding: "9px 14px" }}>
                  <FileDown size={14} /> Final Draft
                </button>
              </>
            )}
            {sel && <button type="button" className="btn no" onClick={excluirProjeto} style={{ flex: "none", display: "inline-flex", alignItems: "center", gap: 6, padding: "9px 12px" }}><Trash2 size={14} /> Excluir história</button>}
          </div>

          {!sel ? (
            <div className="empty">Nenhuma história ainda. Crie a primeira e monte a escaleta cena a cena.</div>
          ) : (
            <>
              <label style={{ display: "block", marginBottom: 14 }}>
                <span style={{ fontSize: ".74rem", color: "var(--muted)", display: "block", marginBottom: 3 }}>Nome da história</span>
                <input value={sel.name} onChange={(e) => renomear(e.target.value)}
                  style={{ width: "100%", maxWidth: 480, background: "var(--bg2)", color: "var(--text)", border: "1px solid var(--line2)", borderRadius: 8, padding: "9px 11px", fontSize: ".95rem", fontWeight: 700, fontFamily: "inherit" }} />
              </label>

              {/* PROMPT DO FILME — a porta de entrada do fluxo (pedido do Luciano 2026-07-28):
                  daqui a IA escreve as cenas E cria as fichas de personagem/cenário que a
                  história pedir e ainda não existirem na biblioteca. Aceita da ideia curta ao
                  roteiro por extenso. */}
              <div style={{ marginBottom: 18 }}>
                <span style={{ fontSize: ".74rem", color: "var(--muted)", display: "block", marginBottom: 3 }}>
                  Prompt do filme — da ideia curta à história por extenso (a IA escreve as cenas e cria as fichas de quem/onde que faltarem)
                </span>
                <textarea
                  value={argumento}
                  onChange={(e) => setArgumento(e.target.value)}
                  onBlur={salvarArgumento}
                  rows={6}
                  placeholder={"Escreva ou cole a história aqui.\n\nEx.: Mel, uma dachshund cor de mel, se perde do dono numa feira. Ao atravessar a cidade atrás do cheiro dele, enfrenta a chuva, um cão de rua territorial e a própria teimosia — até reencontrá-lo na porta de casa, ao entardecer."}
                  style={{ width: "100%", background: "var(--bg2)", color: "var(--text)", border: "1px solid var(--line2)", borderRadius: 10, padding: "11px 13px", fontSize: ".92rem", fontFamily: "inherit", lineHeight: 1.55, resize: "vertical" }}
                />
                <div style={{ display: "flex", gap: 8, alignItems: "center", flexWrap: "wrap", marginTop: 8 }}>
                  <button type="button" className="btn ok" onClick={planejarComIA} disabled={planejando || !argumento.trim()}
                    title="A IA quebra o texto em cenas com local, hora, objetivo, conflito e quem entra em cada uma"
                    style={{ flex: "none", display: "inline-flex", alignItems: "center", gap: 6, padding: "9px 15px" }}>
                    <Sparkles size={15} /> {planejando ? "Escrevendo as cenas…" : "Escrever cenas com IA"}
                  </button>
                  <label style={{ color: "var(--muted)", fontSize: ".8rem", display: "inline-flex", alignItems: "center", gap: 6 }}>
                    em
                    <input type="number" min={2} max={24} value={nCenas} onChange={(e) => setNCenas(Math.max(2, Math.min(24, Number(e.target.value) || 8)))}
                      style={{ width: 58, background: "var(--bg2)", color: "var(--text)", border: "1px solid var(--line2)", borderRadius: 8, padding: "6px 8px", fontFamily: "inherit" }} />
                    cenas
                  </label>
                  {(sel.scenes?.length ?? 0) > 0 && (
                    <button type="button" className="btn edit" onClick={revisarComDoutor} disabled={revisando}
                      title="O Doutor de Roteiro lê as cenas e aponta o que está frouxo — cena sem conflito, virada faltando, buraco de continuidade. Não altera nada."
                      style={{ flex: "none", display: "inline-flex", alignItems: "center", gap: 6, padding: "9px 15px" }}>
                      <Stethoscope size={15} /> {revisando ? "Lendo o roteiro…" : "Revisar com o Doutor"}
                    </button>
                  )}
                  <span style={{ color: "var(--muted)", fontSize: ".78rem" }}>
                    as cenas novas entram no fim — não apaga o que já existe
                  </span>
                </div>
              </div>

              {/* DIAGNÓSTICO — leitura do roteiro, não edição. Cada nota diz a cena; clicar rola
                  até ela. Fica acima das cenas porque é o que se lê ANTES de mexer nelas. */}
              {diag && (
                <div style={{ background: "var(--bg2)", border: "1px solid var(--line2)", borderRadius: 12, padding: "14px 16px", marginBottom: 18 }}>
                  <div style={{ display: "flex", alignItems: "center", gap: 8, marginBottom: diag.veredito || diag.notas.length ? 10 : 0 }}>
                    <Stethoscope size={15} style={{ color: "var(--peach)" }} />
                    <b style={{ fontSize: ".9rem" }}>Doutor de Roteiro</b>
                    <span style={{ color: "var(--muted)", fontSize: ".76rem" }}>aponta; quem corrige é você</span>
                    <button type="button" onClick={() => setDiag(null)} title="Fechar o diagnóstico"
                      style={{ marginLeft: "auto", background: "none", border: "none", color: "var(--muted)", cursor: "pointer", padding: 2, lineHeight: 0 }}>
                      <X size={15} />
                    </button>
                  </div>
                  {diag.veredito && <p style={{ margin: "0 0 12px", fontSize: ".9rem", lineHeight: 1.55 }}>{diag.veredito}</p>}
                  {diag.notas.length === 0 ? (
                    <p style={{ margin: 0, color: "var(--muted)", fontSize: ".85rem" }}>Nenhum defeito apontado nesta leitura.</p>
                  ) : (
                    <div style={{ display: "flex", flexDirection: "column", gap: 9 }}>
                      {diag.notas.map((n, i) => {
                        const nv = NIVEL[n.nivel] ?? NIVEL.atencao;
                        return (
                          <div key={i} style={{ display: "flex", gap: 10, alignItems: "baseline", borderLeft: `2px solid ${nv.cor}`, paddingLeft: 10 }}>
                            <span style={{ color: nv.cor, fontSize: ".68rem", textTransform: "uppercase", letterSpacing: ".04em", fontWeight: 700, flex: "0 0 auto", minWidth: 52 }}>
                              {nv.rotulo}
                            </span>
                            <div style={{ fontSize: ".86rem", lineHeight: 1.5 }}>
                              {n.cena ? (
                                <a href={`#cena-${n.cena}`} style={{ color: "var(--peach)", fontWeight: 700, textDecoration: "none" }}>Cena {n.cena}</a>
                              ) : (
                                <b style={{ color: "var(--muted)" }}>História</b>
                              )}
                              {" — "}{n.problema}
                              {n.sugestao && <span style={{ color: "var(--muted)" }}> → {n.sugestao}</span>}
                            </div>
                          </div>
                        );
                      })}
                    </div>
                  )}
                </div>
              )}

              {/* ── PRÓXIMOS PASSOS: a ordem do filme na tela, na sequência em que se percorre.
                  Antes o "Produzir na Montagem" ficava na barra do topo, ao lado de exportar e
                  excluir — parecia mais um botão de arquivo, e não a etapa final. E não havia
                  etapa nenhuma entre revisar o roteiro e montar: dar rosto às fichas era abrir três
                  abas e clicar item por item. Agora as três etapas ficam juntas e em ordem. ── */}
              {(sel.scenes?.length ?? 0) > 0 && (
                <div style={{ background: "var(--bg2)", border: "1px solid var(--line2)", borderRadius: 12, padding: "14px 16px", marginBottom: 20 }}>
                  <div style={{ display: "flex", alignItems: "baseline", gap: 8, marginBottom: 10 }}>
                    <b style={{ fontSize: ".9rem" }}>Depois de revisar o roteiro</b>
                    <span style={{ color: "var(--muted)", fontSize: ".76rem" }}>dê rosto ao que a história pede e leve pra produção</span>
                  </div>

                  {/* PADRÃO VISUAL — a escolha que vale pra história INTEIRA. Fica aqui, e não em
                      cada aba, porque personagem, cenário e elemento do mesmo filme têm de sair do
                      mesmo motor e da mesma técnica pra parecerem o mesmo mundo. */}
                  <div style={{ display: "flex", gap: 12, alignItems: "flex-end", flexWrap: "wrap", marginBottom: 12 }}>
                    <SeletorModeloImagem
                      modelos={modelos}
                      valor={modelo}
                      onChange={(slug) => { setModelo(slug); salvarPadraoVisual({ image_model: slug }); }}
                      rotulo="Modelo de imagem da história"
                      estilo={{ background: "var(--bg)", color: "var(--text)", border: "1px solid var(--line2)", borderRadius: 8, padding: "8px 10px", fontSize: ".85rem", fontFamily: "inherit", minWidth: 240 }}
                    />
                    <div style={{ display: "flex", flexDirection: "column", gap: 4 }}>
                      <label className="txt" style={{ color: "var(--muted)", fontSize: ".78rem" }}>Técnica (estilo)</label>
                      <select
                        value={estilo}
                        onChange={(e) => { setEstilo(e.target.value); salvarPadraoVisual({ image_style: e.target.value }); }}
                        title="A técnica com que TUDO desta história é desenhado — personagens, cenários e elementos"
                        style={{ background: "var(--bg)", color: "var(--text)", border: "1px solid var(--line2)", borderRadius: 8, padding: "8px 10px", fontSize: ".85rem", fontFamily: "inherit", minWidth: 220 }}>
                        {styleOptions(estilo).map(([v, rot]) => <option key={v} value={v}>{rot}</option>)}
                      </select>
                    </div>
                  </div>

                  <div style={{ display: "flex", gap: 8, alignItems: "center", flexWrap: "wrap" }}>
                    <button type="button" className="btn ok" onClick={criarElenco} disabled={criandoElenco}
                      title="Gera de uma vez a imagem de todo personagem, cenário e elemento desta história que ainda não tem — no modelo e na técnica acima"
                      style={{ flex: "none", display: "inline-flex", alignItems: "center", gap: 6, padding: "10px 16px" }}>
                      <Users size={15} /> {criandoElenco ? "Enfileirando…" : "Criar personagens, cenários e elementos"}
                    </button>
                    <button type="button" className="btn edit" onClick={produzirNoRoteiro}
                      title="Leva as cenas para a Montagem, já com personagem, cenário e elementos como âncora"
                      style={{ flex: "none", display: "inline-flex", alignItems: "center", gap: 6, padding: "10px 16px" }}>
                      <Clapperboard size={15} /> Produzir na Montagem
                    </button>
                    <span style={{ color: "var(--muted)", fontSize: ".78rem" }}>
                      só gera o que ainda não tem imagem — o que já está pronto não é refeito
                    </span>
                  </div>
                </div>
              )}

              <div style={{ display: "flex", flexDirection: "column", gap: 14 }}>
                {(sel.scenes ?? []).map((sc, idx, todas) => (
                  // A âncora usa a POSIÇÃO (1..N), não o id: é o número que o Doutor devolve e o
                  // mesmo que aparece no cartão — reordenar não deixa o link apontando pra cena errada.
                  <div key={sc.id} id={`cena-${idx + 1}`} style={{ scrollMarginTop: 16 }}>
                    {/* Faixa de ATO quando o bloco muda: a escaleta era uma lista plana e não dava
                        pra ver onde um ato termina — que é justamente onde a virada devia estar. */}
                    {(sc.ato ?? 1) !== (idx === 0 ? 0 : (todas[idx - 1].ato ?? 1)) && (
                      <div style={{ display: "flex", alignItems: "center", gap: 10, margin: idx === 0 ? "0 0 10px" : "18px 0 10px" }}>
                        <strong style={{ fontSize: ".76rem", letterSpacing: ".08em", color: "var(--peach)" }}>ATO {sc.ato ?? 1}</strong>
                        <span style={{ flex: 1, height: 1, background: "var(--line2)" }} />
                      </div>
                    )}
                    <SceneCard scene={sc} index={idx} scenarios={scenarios} characters={characters} elements={elements}
                      onSaved={(s) => patchScenes((list) => list.map((x) => (x.id === s.id ? s : x)))}
                      onDeleted={(id) => patchScenes((list) => list.filter((x) => x.id !== id))}
                      onMove={mover} />
                  </div>
                ))}
              </div>

              <button type="button" className="btn edit" onClick={novaCena} style={{ flex: "none", display: "inline-flex", alignItems: "center", gap: 6, padding: "10px 18px", marginTop: 16 }}>
                <Plus size={15} /> Adicionar cena
              </button>
            </>
          )}
        </>
      )}
    </>
  );
}
