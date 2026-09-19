"use client";

// CATÁLOGO DE ELEMENTOS — o carro, a cadeira, a mala, o cão de rua.
//
// POR QUE EXISTE: personagem e cenário já tinham biblioteca com imagem-âncora, e é por isso que
// atravessam o filme sem mudar de cara. Objeto não tinha casa nenhuma — nada garantia que o carro
// da cena 2 fosse o carro da cena 7. Aqui ele ganha a mesma mecânica: ficha + imagem que viaja
// como referência para toda cena que o usa. (Ideia lida no Catalog do Celtx, onde todo ativo de
// produção é indexado e reaproveitado pelas cenas.)

import { useEffect, useState } from "react";
import { sfetch } from "@/lib/api";
import { useToast } from "@/components/ui/Toast";
import { SkeletonCards } from "@/components/ui/Spinner";
import { Plus, Trash2, Sparkles, ImageIcon, X } from "lucide-react";
import { HistoricoDeVersoes } from "@/components/HistoricoDeVersoes";

type Element = {
  id: number; name: string; categoria: string;
  description?: string | null; image_url?: string | null; status?: string | null;
};
type Opcao = { valor: string; rotulo: string };

const inp: React.CSSProperties = {
  width: "100%", background: "var(--bg2)", color: "var(--text)", border: "1px solid var(--line2)",
  borderRadius: 8, padding: "8px 10px", fontSize: ".88rem", fontFamily: "inherit",
};
const lbl: React.CSSProperties = { fontSize: ".72rem", color: "var(--muted)", display: "block", marginBottom: 3 };

export default function ElementosPage() {
  const toast = useToast();
  const [itens, setItens] = useState<Element[]>([]);
  const [cats, setCats] = useState<Opcao[]>([]);
  const [loading, setLoading] = useState(true);
  const [abertoId, setAbertoId] = useState<number | null>(null);
  const [busy, setBusy] = useState<number | "novo" | null>(null);
  const [zoom, setZoom] = useState<Element | null>(null);

  useEffect(() => {
    Promise.allSettled([
      sfetch("/api/elements").then((r) => r.json()),
      sfetch("/api/elements/categorias").then((r) => r.json()),
    ]).then(([ee, cc]) => {
      setItens(ee.status === "fulfilled" && Array.isArray(ee.value) ? ee.value : []);
      setCats(cc.status === "fulfilled" && Array.isArray(cc.value) ? cc.value : []);
    }).finally(() => setLoading(false));
  }, []);

  useEffect(() => {
    if (!zoom) return;
    const onKey = (e: KeyboardEvent) => { if (e.key === "Escape") setZoom(null); };
    window.addEventListener("keydown", onKey);

    return () => window.removeEventListener("keydown", onKey);
  }, [zoom]);

  const upsert = (e: Element) =>
    setItens((prev) => (prev.some((x) => x.id === e.id) ? prev.map((x) => (x.id === e.id ? { ...x, ...e } : x)) : [e, ...prev]));

  async function novo() {
    const nome = prompt("Nome do elemento (ex.: Fusca azul, Cadeira de balanço)");
    if (!nome?.trim()) return;
    setBusy("novo");
    try {
      const r = await sfetch("/api/elements", { method: "POST", body: JSON.stringify({ name: nome.trim() }) });
      const d = await r.json().catch(() => ({}));
      if (!r.ok || !d?.ok) { toast.err(d?.error || "Não deu pra criar."); return; }
      upsert(d.element);
      setAbertoId(d.element.id);
    } catch { toast.err("Erro ao criar."); } finally { setBusy(null); }
  }

  async function salvar(e: Element) {
    setBusy(e.id);
    try {
      const r = await sfetch(`/api/elements/${e.id}`, {
        method: "PATCH",
        body: JSON.stringify({ name: e.name, categoria: e.categoria, description: e.description ?? "" }),
      });
      const d = await r.json().catch(() => ({}));
      if (!r.ok || !d?.ok) { toast.err(d?.error || "Não deu pra salvar."); return; }
      upsert(d.element);
      toast.ok("Elemento salvo.");
    } catch { toast.err("Erro ao salvar."); } finally { setBusy(null); }
  }

  async function excluir(id: number) {
    if (!confirm("Excluir este elemento? As cenas que o usam perdem a âncora dele.")) return;
    try {
      await sfetch(`/api/elements/${id}`, { method: "DELETE" });
      setItens((p) => p.filter((x) => x.id !== id));
    } catch { toast.err("Não deu pra excluir."); }
  }

  // O 📦 Molde: Objeto escreve a descrição densa — mesma persona que a extração de foto usa, então
  // objeto criado à mão e objeto lido de uma foto saem descritos do mesmo jeito.
  async function detalhar(e: Element, onTexto: (t: string) => void) {
    const ideia = (e.description || e.name).trim();
    setBusy(e.id);
    try {
      const r = await sfetch("/api/characters/persona-chat", {
        method: "POST", body: JSON.stringify({ persona: "Molde: Objeto", message: `IDEIA: ${ideia}` }),
      });
      const d = await r.json().catch(() => ({}));
      if (!r.ok || !d?.ok || !d?.text) { toast.err(d?.error || "O molde não respondeu."); return; }
      onTexto(String(d.text).trim());
      toast.ok("Descrição detalhada. Leia e ajuste antes de gerar a imagem.");
    } catch { toast.err("Erro ao falar com a IA."); } finally { setBusy(null); }
  }

  async function gerarImagem(e: Element) {
    setBusy(e.id);
    try {
      const r = await sfetch(`/api/elements/${e.id}/image`, {
        method: "POST", body: JSON.stringify({ description: e.description ?? "" }),
      });
      const d = await r.json().catch(() => ({}));
      if (!r.ok || !d?.ok) { toast.err(d?.error || "Não foi possível gerar."); return; }
      upsert(d.element);
      toast.ok("Gerando a imagem-âncora — ela aparece aqui quando ficar pronta.");
      // Polling curto: a geração é assíncrona (job), como no cenário e na base do personagem.
      const t = setInterval(async () => {
        const rr = await sfetch("/api/elements").then((x) => x.json()).catch(() => null);
        const atual = Array.isArray(rr) ? rr.find((x: Element) => x.id === e.id) : null;
        if (atual && atual.status !== "base") { upsert(atual); clearInterval(t); }
      }, 6000);
      setTimeout(() => clearInterval(t), 420000);
    } catch { toast.err("Erro ao gerar."); } finally { setBusy(null); }
  }

  return (
    <>
      <h1 className="h1">Elementos</h1>
      <p className="sub">
        O catálogo de objetos do filme — veículo, móvel, prop, figurino, animal. Cada um com ficha e
        imagem-âncora: é o que faz o carro da cena 7 ser o mesmo carro da cena 2.
      </p>

      <button type="button" className="btn ok" onClick={novo} disabled={busy === "novo"}
        style={{ flex: "none", display: "inline-flex", alignItems: "center", gap: 7, padding: "10px 18px", marginBottom: 24 }}>
        <Plus size={16} /> {busy === "novo" ? "Criando…" : "Novo elemento"}
      </button>

      {loading ? (
        <SkeletonCards />
      ) : itens.length === 0 ? (
        <div className="empty">Nenhum elemento ainda. Crie o primeiro — um carro, uma cadeira, uma mala.</div>
      ) : (
        <div style={{ display: "flex", flexDirection: "column", gap: 16 }}>
          {itens.map((e) => (
            <Cartao key={e.id} el={e} cats={cats} busy={busy === e.id}
              onZoom={() => setZoom(e)} onSalvar={salvar} onExcluir={excluir}
              onDetalhar={detalhar} onGerar={gerarImagem} />
          ))}
        </div>
      )}

      {zoom?.image_url && (
        /* flex, não grid: em grid a track auto é dimensionada pelo conteúdo, o maxHeight:100%
           da imagem vira referência circular e NÃO clampa — imagem maior que a viewport
           estourava sem scroll (topo e fundo cortados). Em flex o % resolve contra o
           container fixed e clampa. Mesmo fix do lightbox da galeria (344f640). */
        <div onClick={() => setZoom(null)}
          style={{ position: "fixed", inset: 0, zIndex: 60, background: "rgba(0,0,0,.9)", display: "flex", alignItems: "center", justifyContent: "center", padding: 24, cursor: "zoom-out" }}>
          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img src={zoom.image_url} alt={zoom.name} style={{ maxWidth: "100%", maxHeight: "100%", objectFit: "contain", borderRadius: 10 }} />
          <div style={{ position: "absolute", top: 16, left: 20, color: "#fff", fontSize: ".85rem", fontWeight: 700 }}>{zoom.name}</div>
          <button type="button" onClick={() => setZoom(null)} title="Fechar (Esc)"
            style={{ position: "absolute", top: 12, right: 16, background: "none", border: "none", color: "#fff", cursor: "pointer", padding: 6, lineHeight: 0 }}>
            <X size={22} />
          </button>
        </div>
      )}
    </>
  );
}

function Cartao({ el, cats, busy, onZoom, onSalvar, onExcluir, onDetalhar, onGerar }: {
  el: Element; cats: Opcao[]; busy: boolean; onZoom: () => void;
  onSalvar: (e: Element) => void; onExcluir: (id: number) => void;
  onDetalhar: (e: Element, cb: (t: string) => void) => void; onGerar: (e: Element) => void;
}) {
  const [e, setE] = useState<Element>(el);
  // `sujo` = o usuário digitou algo que ainda não foi salvo. Sem esta trava, o polling da
  // geração de imagem (upsert a cada ~6s) reescrevia o card com o valor do servidor e APAGAVA
  // o que estava sendo digitado — os campos não são desabilitados durante a geração.
  // A sincronia segue valendo pro que vem de fora (image_url, status) enquanto nada está sujo.
  const [sujo, setSujo] = useState(false);
  useEffect(() => {
    const servidorJaTem = el.name === e.name && el.categoria === e.categoria && (el.description ?? "") === (e.description ?? "");
    if (sujo && !servidorJaTem) return;          // digitação em andamento: o poll não atropela
    if (sujo) setSujo(false);                    // salvou: o servidor já reflete a tela
    if (el !== e) setE(el);                      // adota o que veio (image_url, status…)
  }, [el, e, sujo]);
  const up = <K extends keyof Element>(k: K, v: Element[K]) => { setSujo(true); setE((p) => ({ ...p, [k]: v })); };
  const gerando = e.status === "base";

  return (
    <div className="card">
      {e.image_url && (
        <img src={e.image_url} alt={e.name} onClick={onZoom} title="Clique pra ver inteira"
          style={{ width: "100%", maxHeight: 220, objectFit: "cover", display: "block", background: "var(--bg2)", cursor: "zoom-in" }} />
      )}
      <div className="body" style={{ display: "flex", flexDirection: "column", gap: 10 }}>
        <div style={{ display: "flex", gap: 8, flexWrap: "wrap" }}>
          <label style={{ flex: 2, minWidth: 180 }}><span style={lbl}>Nome</span>
            <input value={e.name} onChange={(x) => up("name", x.target.value)} style={inp} />
          </label>
          <label style={{ flex: "0 0 190px" }}><span style={lbl}>Categoria</span>
            <select value={e.categoria} onChange={(x) => up("categoria", x.target.value)} style={inp}>
              {cats.map((c) => <option key={c.valor} value={c.valor}>{c.rotulo}</option>)}
            </select>
          </label>
        </div>
        <label><span style={lbl}>Descrição — o que a câmera vê (o 📦 molde detalha)</span>
          <textarea value={e.description ?? ""} onChange={(x) => up("description", x.target.value)} rows={3}
            placeholder="Ex.: Fusca azul-claro de 1972, para-choque cromado amassado no canto direito, pneus gastos"
            style={{ ...inp, resize: "vertical", lineHeight: 1.5 }} />
        </label>
        <div style={{ display: "flex", gap: 8, flexWrap: "wrap" }}>
          <button type="button" className="btn edit" disabled={busy} onClick={() => onDetalhar(e, (t) => up("description", t))}
            title="O 📦 Molde: Objeto engorda a ideia até a descrição densa — escala relacional, materiais, desgaste"
            style={{ flex: "none", padding: "7px 13px", fontSize: ".8rem", display: "inline-flex", alignItems: "center", gap: 5 }}>
            <Sparkles size={13} /> Detalhar
          </button>
          <button type="button" className="btn ok" disabled={busy || gerando || !(e.description || "").trim()}
            onClick={() => onGerar(e)} title="Gera a imagem-âncora do elemento — objeto sozinho, fundo neutro"
            style={{ flex: "none", padding: "7px 13px", fontSize: ".8rem", display: "inline-flex", alignItems: "center", gap: 5 }}>
            <ImageIcon size={13} /> {gerando ? "gerando…" : e.image_url ? "Regerar imagem" : "Gerar imagem"}
          </button>
          <button type="button" className="btn ok" disabled={busy} onClick={() => onSalvar(e)}
            style={{ flex: "none", padding: "7px 13px", fontSize: ".8rem" }}>Salvar</button>
          <button type="button" className="btn no" onClick={() => onExcluir(e.id)}
            style={{ flex: "none", padding: "7px 11px", fontSize: ".8rem", marginLeft: "auto" }}><Trash2 size={13} /></button>
        </div>
        {/* Regerar não apaga mais a anterior: ela fica aqui, a um clique. */}
        <HistoricoDeVersoes tipo="element" id={e.id} campo="image_url"
          onRestaurado={(a) => setE((p) => ({ ...p, ...(a as Partial<Element>) }))} />
      </div>
    </div>
  );
}
