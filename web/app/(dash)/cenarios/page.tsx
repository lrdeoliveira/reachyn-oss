"use client";

// Biblioteca de CENÁRIOS (F3 do fluxo image→cena). Cada cenário ganha o `spec` metodológico
// (função dramática/tríade tempo-espaço/atmosfera/riscos) preenchido pelo 🗺️ Arquiteto de Cenário,
// e a imagem-âncora gerada do spec no mmx local. Espelha o padrão de personagens: lista de cards
// com detalhe (CenarioForm) expansível.

import { useEffect, useState } from "react";
import { sfetch } from "@/lib/api";
import { useToast } from "@/components/ui/Toast";
import { SkeletonCards } from "@/components/ui/Spinner";
import { CenarioForm } from "@/components/CenarioForm";
import { Plus, Trash2, X } from "lucide-react";

type Dict = Record<string, unknown>;
type Scenario = { id: number; name: string; description?: string | null; image_url?: string | null; spec?: Dict | null };

export default function CenariosPage() {
  const toast = useToast();
  const [items, setItems] = useState<Scenario[]>([]);
  const [loading, setLoading] = useState(true);
  const [criando, setCriando] = useState(false);
  const [openId, setOpenId] = useState<number | null>(null);
  // Ampliar a imagem DO CARTÃO. O zoom existia só dentro do "Editar" — com o form fechado, que é
  // como a lista fica, clicar na imagem não fazia nada e não dava pra conferir o cenário sem abrir
  // o formulário inteiro. E o banner é `cover`: corta justamente o enquadramento que se quer julgar.
  const [zoom, setZoom] = useState<Scenario | null>(null);

  useEffect(() => {
    sfetch("/api/scenarios")
      .then((r) => r.json())
      .then((d) => setItems(Array.isArray(d) ? d : (d.data ?? d.items ?? [])))
      .catch(() => setItems([]))
      .finally(() => setLoading(false));
  }, []);

  // Esc fecha a imagem ampliada — a tecla que todo mundo tenta antes de procurar o X.
  useEffect(() => {
    if (!zoom) return;
    const onKey = (e: KeyboardEvent) => { if (e.key === "Escape") setZoom(null); };
    window.addEventListener("keydown", onKey);

    return () => window.removeEventListener("keydown", onKey);
  }, [zoom]);

  const upsert = (s: Scenario) =>
    setItems((prev) => {
      const i = prev.findIndex((x) => x.id === s.id);
      if (i < 0) return [s, ...prev];
      const c = [...prev];
      c[i] = { ...c[i], ...s };
      return c;
    });

  async function novo() {
    setCriando(true);
    try {
      const r = await sfetch("/api/scenarios", { method: "POST", body: JSON.stringify({ name: "Novo cenário" }) });
      const d = await r.json().catch(() => ({}));
      if (!r.ok || !d?.ok) {
        toast.err(d?.error || "Não foi possível criar o cenário.");
        return;
      }
      upsert(d.scenario);
      setOpenId(d.scenario.id);
    } catch {
      toast.err("Erro ao criar o cenário.");
    } finally {
      setCriando(false);
    }
  }

  async function excluir(id: number) {
    if (!confirm("Excluir este cenário?")) return;
    try {
      const r = await sfetch(`/api/scenarios/${id}`, { method: "DELETE" });
      if (!r.ok) throw new Error();
      setItems((prev) => prev.filter((x) => x.id !== id));
      if (openId === id) setOpenId(null);
    } catch {
      toast.err("Não foi possível excluir.");
    }
  }

  return (
    <>
      <h1 className="h1">Cenários</h1>
      <p className="sub">
        Ambientação — função dramática, tempo/espaço, atmosfera e riscos. A imagem sai do spec e
        vira a âncora de lugar das cenas.
      </p>

      <button type="button" className="btn ok" onClick={novo} disabled={criando}
        style={{ flex: "none", display: "inline-flex", alignItems: "center", gap: 7, padding: "10px 18px", marginBottom: 24 }}>
        <Plus size={16} /> {criando ? "Criando…" : "Novo cenário"}
      </button>

      {loading ? (
        <SkeletonCards />
      ) : items.length === 0 ? (
        <div className="empty">Nenhum cenário ainda. Crie o primeiro e deixe o Arquiteto montar a ambientação.</div>
      ) : (
        <div style={{ display: "flex", flexDirection: "column", gap: 16 }}>
          {items.map((c) => {
            // Sem spec ainda (cenário recém-nascido do roteiro/F8), o card mostra a descrição
            // da Escaleta — senão a ficha parece vazia até alguém rodar o Arquiteto.
            const funcao = (c.spec as Dict | null)?.funcao_dramatica || c.description;
            return (
              <div key={c.id} className="card">
                {/* Banner só com o form FECHADO: aberto, quem mostra a imagem é a caixa de lá,
                    ao lado do botão que a gera. As duas juntas repetiam a mesma imagem na tela. */}
                {c.image_url && openId !== c.id && (
                  <img src={c.image_url} alt={c.name} onClick={() => setZoom(c)} title="Clique pra ver inteira"
                    style={{ width: "100%", maxHeight: 260, objectFit: "cover", display: "block", background: "var(--bg2)", cursor: "zoom-in" }} />
                )}
                <div className="body">
                  <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", gap: 10 }}>
                    <div className="title">{c.name}</div>
                    <div style={{ display: "flex", gap: 8 }}>
                      <button type="button" className="btn edit" style={{ flex: "none", padding: "6px 12px", fontSize: ".8rem" }} onClick={() => setOpenId(openId === c.id ? null : c.id)}>
                        {openId === c.id ? "Fechar" : "Editar"}
                      </button>
                      <button type="button" className="btn no" style={{ flex: "none", padding: "6px 12px", fontSize: ".8rem" }} onClick={() => excluir(c.id)}>
                        <Trash2 size={14} />
                      </button>
                    </div>
                  </div>
                  {typeof funcao === "string" && funcao ? <div className="txt" style={{ marginTop: 6 }}>{funcao}</div> : null}
                  {openId === c.id && (
                    <div style={{ marginTop: 14, borderTop: "1px solid var(--line)", paddingTop: 14 }}>
                      <CenarioForm scenario={c} onSaved={(s) => upsert(s as Scenario)} />
                    </div>
                  )}
                </div>
              </div>
            );
          })}
        </div>
      )}

      {/* INTEIRA na tela: o cartão mostra a imagem recortada, e é na luz, nos materiais e no
          enquadramento que se decide se o cenário serve de âncora pras cenas. Clique em qualquer
          lugar (ou Esc) fecha. */}
      {zoom?.image_url && (
        /* flex, não grid: com grid o maxHeight:100% da imagem não clampa (track auto) e
           imagem alta saía cortada sem scroll — mesmo fix dos elementos/galeria. */
        <div onClick={() => setZoom(null)}
          style={{ position: "fixed", inset: 0, zIndex: 60, background: "rgba(0,0,0,.9)", display: "flex", alignItems: "center", justifyContent: "center", padding: 24, cursor: "zoom-out" }}>
          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img src={zoom.image_url} alt={zoom.name} style={{ maxWidth: "100%", maxHeight: "100%", objectFit: "contain", borderRadius: 10 }} />
          <div style={{ position: "absolute", top: 16, left: 20, color: "#fff", fontSize: ".85rem", fontWeight: 700, textShadow: "0 1px 3px rgba(0,0,0,.8)" }}>
            {zoom.name}
          </div>
          <button type="button" onClick={() => setZoom(null)} title="Fechar (Esc)"
            style={{ position: "absolute", top: 12, right: 16, background: "none", border: "none", color: "#fff", cursor: "pointer", padding: 6, lineHeight: 0 }}>
            <X size={22} />
          </button>
        </div>
      )}
    </>
  );
}
