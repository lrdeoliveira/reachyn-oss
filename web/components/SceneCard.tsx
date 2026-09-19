"use client";

// Uma CENA da escaleta (F4). Cabeçalho de cena (LOCAL/INT-EXT/TEMPO, do curso), dramaturgia
// (motivação/objetivo/conflito/virada/tamanho/resumo) e ligações personagem × cenário (selects
// da biblioteca do tenant). Salva via PATCH /api/scenes/{id}; subir/descer/excluir sobem pro pai.

import { useEffect, useState } from "react";
import { sfetch } from "@/lib/api";
import { useToast } from "@/components/ui/Toast";
import { ChevronUp, ChevronDown, Trash2, Save } from "lucide-react";
import { PlanosDaCena, type Shot } from "@/components/PlanosDaCena";
import { duracaoFala } from "@/lib/roteiro";

type Scene = {
  id: number; ordem: number; ato?: number | null; scenario_id?: number | null; character_ids?: number[] | null;
  // ELEMENTOS do catálogo que entram na cena — a imagem de cada um vira âncora na produção.
  element_ids?: number[] | null;
  local?: string | null; int_ext?: string | null; tempo?: string | null;
  motivacao?: string | null; objetivo_cena?: string | null; conflito_cena?: string | null;
  virada?: boolean; tamanho?: string | null; resumo?: string | null; narracao?: string | null;
  // Os PLANOS da cena (decupagem) chegam junto do projeto. Cena sem plano segue valendo: vira um
  // quadro só na Montagem, como era antes desta camada existir.
  shots?: Shot[];
};
// `url` = a imagem que vira ÂNCORA na geração (base do personagem, imagem do cenário). A
// escaleta já a carregava; o cartão só não mostrava — daí não dava pra ver, ao montar a cena,
// se aquele personagem/lugar tem visual definido ou é ficha só com nome.
type Ref = { id: number; name: string; url?: string | null; mesh?: string | null; tipo?: "character" | "element" };

const inp: React.CSSProperties = {
  width: "100%", background: "var(--bg2)", color: "var(--text)", border: "1px solid var(--line2)",
  borderRadius: 8, padding: "7px 9px", fontSize: ".85rem", fontFamily: "inherit",
};
const lbl: React.CSSProperties = { fontSize: ".72rem", color: "var(--muted)", display: "block", marginBottom: 3 };

export function SceneCard({ scene, index, scenarios, characters, elements = [], onSaved, onDeleted, onMove }: {
  scene: Scene; index: number; scenarios: Ref[]; characters: Ref[]; elements?: Ref[];
  onSaved: (s: Scene) => void; onDeleted: (id: number) => void; onMove: (id: number, dir: -1 | 1) => void;
}) {
  const toast = useToast();
  const [s, setS] = useState<Scene>(scene);
  const [salvando, setSalvando] = useState(false);
  // O pai põe `key` na div de fora, não neste card: ele NÃO remonta quando a cena muda por
  // fora (reescrita pela IA, reordenação). Como `salvar()` manda TODOS os campos de uma vez
  // — inclusive resumo/narração, que são saída de chamada de IA — um card com estado velho
  // regravaria o texto antigo por cima. Adota o que vem do servidor, exceto se houver edição
  // em andamento nesta tela (aí quem manda é o usuário).
  const [sujo, setSujo] = useState(false);
  useEffect(() => { if (!sujo) setS(scene); }, [scene, sujo]);
  const up = <K extends keyof Scene>(k: K, v: Scene[K]) => { setSujo(true); setS((p) => ({ ...p, [k]: v })); };

  const toggleChar = (cid: number) => {
    const cur = s.character_ids ?? [];
    up("character_ids", cur.includes(cid) ? cur.filter((x) => x !== cid) : [...cur, cid]);
  };
  const toggleEl = (eid: number) => {
    const cur = s.element_ids ?? [];
    up("element_ids", cur.includes(eid) ? cur.filter((x) => x !== eid) : [...cur, eid]);
  };

  async function salvar() {
    setSalvando(true);
    try {
      const body = {
        ato: s.ato ?? 1,
        scenario_id: s.scenario_id ?? null, character_ids: s.character_ids ?? [], element_ids: s.element_ids ?? [],
        local: s.local ?? "", int_ext: s.int_ext || null, tempo: s.tempo ?? "",
        motivacao: s.motivacao ?? "", objetivo_cena: s.objetivo_cena ?? "", conflito_cena: s.conflito_cena ?? "",
        virada: !!s.virada, tamanho: s.tamanho ?? "", resumo: s.resumo ?? "", narracao: s.narracao ?? "",
      };
      const r = await sfetch(`/api/scenes/${s.id}`, { method: "PATCH", body: JSON.stringify(body) });
      const d = await r.json().catch(() => ({}));
      if (!r.ok || !d?.ok) { toast.err(d?.error || "Não deu pra salvar a cena."); return; }
      setSujo(false);   // salvo: volta a aceitar atualização vinda do servidor
      onSaved(d.scene);
      toast.ok("Cena salva.");
    } catch { toast.err("Erro ao salvar a cena."); } finally { setSalvando(false); }
  }

  async function excluir() {
    if (!confirm("Excluir esta cena?")) return;
    try {
      const r = await sfetch(`/api/scenes/${s.id}`, { method: "DELETE" });
      if (!r.ok) throw new Error();
      onDeleted(s.id);
    } catch { toast.err("Não deu pra excluir."); }
  }

  const linha = { display: "flex", gap: 8, flexWrap: "wrap" as const };

  return (
    <div className="card" style={{ padding: 14, gap: 10, display: "flex", flexDirection: "column" }}>
      <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", gap: 8 }}>
        <div style={{ fontWeight: 800, color: "var(--peach)" }}>
          Cena {index + 1}
          {s.virada && <span className="badge" style={{ marginLeft: 8 }}>virada</span>}
        </div>
        <div style={{ display: "flex", gap: 6 }}>
          <button type="button" className="btn edit" style={{ flex: "none", padding: "4px 8px" }} onClick={() => onMove(s.id, -1)} title="Subir"><ChevronUp size={14} /></button>
          <button type="button" className="btn edit" style={{ flex: "none", padding: "4px 8px" }} onClick={() => onMove(s.id, 1)} title="Descer"><ChevronDown size={14} /></button>
          <button type="button" className="btn no" style={{ flex: "none", padding: "4px 8px" }} onClick={excluir}><Trash2 size={14} /></button>
        </div>
      </div>

      {/* Cabeçalho de cena — LOCAL/INT-EXT/TEMPO */}
      <div style={linha}>
        <label style={{ flex: 2, minWidth: 160 }}><span style={lbl}>Local</span><input value={s.local ?? ""} onChange={(e) => up("local", e.target.value)} style={inp} /></label>
        <label style={{ flex: "0 0 90px" }}><span style={lbl}>INT/EXT</span>
          <select value={s.int_ext ?? ""} onChange={(e) => up("int_ext", e.target.value)} style={inp}>
            <option value="">—</option><option value="INT">INT</option><option value="EXT">EXT</option>
          </select>
        </label>
        <label style={{ flex: 1, minWidth: 110 }}><span style={lbl}>Tempo</span><input value={s.tempo ?? ""} onChange={(e) => up("tempo", e.target.value)} placeholder="DIA / NOITE" style={inp} /></label>
      </div>

      {/* Ligações personagem × cenário */}
      <div style={linha}>
        <label style={{ flex: 1, minWidth: 160 }}><span style={lbl}>Cenário</span>
          <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
            {/* Miniatura do LUGAR: é a imagem que vai como âncora pra cena. Sem ela à vista não
                dava pra saber se o cenário escolhido já tem visual definido ou se é só uma ficha
                com nome — e a diferença aparece tarde, na primeira geração. */}
            {(() => {
              const sc = scenarios.find((x) => x.id === s.scenario_id);
              return sc?.url ? (
                // eslint-disable-next-line @next/next/no-img-element
                <img src={sc.url} alt={sc.name} title={`${sc.name} — âncora de lugar`}
                  style={{ width: 34, height: 34, objectFit: "cover", borderRadius: 6, border: "1px solid var(--line2)", flex: "none" }} />
              ) : s.scenario_id ? (
                <span title="Cenário sem imagem — gere a imagem na aba Cenários pra ele virar âncora"
                  style={{ width: 34, height: 34, borderRadius: 6, border: "1px dashed var(--line2)", color: "var(--muted)", fontSize: 9, display: "grid", placeItems: "center", flex: "none", textAlign: "center", lineHeight: 1.1 }}>sem<br />img</span>
              ) : null;
            })()}
            <select value={s.scenario_id ?? ""} onChange={(e) => up("scenario_id", e.target.value ? Number(e.target.value) : null)} style={inp}>
              <option value="">— nenhum —</option>
              {scenarios.map((sc) => <option key={sc.id} value={sc.id}>{sc.name}{sc.url ? "" : " (sem imagem)"}</option>)}
            </select>
          </div>
        </label>
      </div>
      {characters.length > 0 && (
        <div>
          <span style={lbl}>Personagens na cena</span>
          {/* SELECT + chips do ELENCO DESTA CENA — antes eram chips de TODOS os personagens da
              biblioteca, sempre visíveis: com 10 personagens (× N cenas) o cartão virava um
              paredão de botões e achar quem está na cena ficava difícil. Agora só quem entrou
              aparece; incluir é escolher na lista. */}
          <div style={{ display: "flex", gap: 6, flexWrap: "wrap", alignItems: "center" }}>
            {(s.character_ids ?? []).map((cid) => {
              const c = characters.find((x) => x.id === cid);
              return (
                <button type="button" key={cid} className="chip on" onClick={() => toggleChar(cid)}
                  title={c?.url ? "Tirar da cena (tem imagem-base = âncora)" : "Tirar da cena — sem imagem-base, só o lock textual"}
                  style={{ display: "inline-flex", alignItems: "center", gap: 6 }}>
                  {c?.url && (
                    // eslint-disable-next-line @next/next/no-img-element
                    <img src={c.url} alt="" style={{ width: 20, height: 20, objectFit: "cover", borderRadius: 4 }} />
                  )}
                  {c?.name ?? `#${cid}`} ✕
                </button>
              );
            })}
            <select
              value=""
              onChange={(e) => { const v = Number(e.target.value); if (v) toggleChar(v); }}
              style={{ ...inp, width: "auto", minWidth: 150, padding: "6px 10px" }}
            >
              <option value="">+ incluir personagem</option>
              {characters.filter((c) => !(s.character_ids ?? []).includes(c.id)).map((c) => (
                <option key={c.id} value={c.id}>{c.name}</option>
              ))}
            </select>
          </div>
        </div>
      )}
      {elements.length > 0 && (
        <div>
          <span style={lbl}>Elementos na cena (objetos, veículos, animais)</span>
          {/* Mesma mecânica do elenco: o objeto marcado leva a IMAGEM dele como âncora pra
              produção. É o que faz o carro da cena 7 ser o carro da cena 2. */}
          <div style={{ display: "flex", gap: 6, flexWrap: "wrap", alignItems: "center" }}>
            {(s.element_ids ?? []).map((eid) => {
              const el = elements.find((x) => x.id === eid);
              return (
                <button type="button" key={eid} className="chip on" onClick={() => toggleEl(eid)}
                  title={el?.url ? "Tirar da cena (tem imagem = âncora)" : "Tirar da cena — sem imagem, entra só pelo nome"}
                  style={{ display: "inline-flex", alignItems: "center", gap: 6 }}>
                  {el?.url && (
                    // eslint-disable-next-line @next/next/no-img-element
                    <img src={el.url} alt="" style={{ width: 20, height: 20, objectFit: "cover", borderRadius: 4 }} />
                  )}
                  {el?.name ?? `#${eid}`} ✕
                </button>
              );
            })}
            <select value="" onChange={(e) => { const v = Number(e.target.value); if (v) toggleEl(v); }}
              style={{ ...inp, width: "auto", minWidth: 150, padding: "6px 10px" }}>
              <option value="">+ incluir elemento</option>
              {elements.filter((c) => !(s.element_ids ?? []).includes(c.id)).map((c) => (
                <option key={c.id} value={c.id}>{c.name}</option>
              ))}
            </select>
          </div>
        </div>
      )}

      {/* Dramaturgia */}
      <div style={linha}>
        <label style={{ flex: 1, minWidth: 160 }}><span style={lbl}>Objetivo da cena</span><input value={s.objetivo_cena ?? ""} onChange={(e) => up("objetivo_cena", e.target.value)} style={inp} /></label>
        <label style={{ flex: 1, minWidth: 160 }}><span style={lbl}>Conflito da cena</span><input value={s.conflito_cena ?? ""} onChange={(e) => up("conflito_cena", e.target.value)} style={inp} /></label>
      </div>
      <div style={linha}>
        {/* ATO: a história em blocos. É a régua que o 📐 Doutor usa pra julgar se a virada está no
            lugar certo, e vira seção no roteiro exportado. 1 quando ninguém dividiu nada. */}
        <label style={{ flex: "0 0 70px" }}><span style={lbl}>Ato</span>
          <input type="number" min={1} max={12} value={s.ato ?? 1}
            onChange={(e) => up("ato", Math.max(1, Math.min(12, Number(e.target.value) || 1)))} style={inp} />
        </label>
        <label style={{ flex: 2, minWidth: 160 }}><span style={lbl}>Motivação</span><input value={s.motivacao ?? ""} onChange={(e) => up("motivacao", e.target.value)} style={inp} /></label>
        <label style={{ flex: "0 0 120px" }}><span style={lbl}>Tamanho/peso</span><input value={s.tamanho ?? ""} onChange={(e) => up("tamanho", e.target.value)} placeholder="curta / clímax" style={inp} /></label>
      </div>
      <label><span style={lbl}>Resumo — o que a câmera vê</span><textarea value={s.resumo ?? ""} onChange={(e) => up("resumo", e.target.value)} rows={2} style={{ ...inp, resize: "vertical" }} /></label>
      {/* NARRAÇÃO: o que se OUVE. Fica aqui, junto da ação, porque narração é roteiro — escrita e
          revisada com a cena. Sem este campo as cenas chegavam MUDAS na Montagem e o filme
          montado saía sem locução nenhuma, sem dizer por quê. */}
      <label><span style={lbl}>Narração — o que se ouve (vira a locução do filme)
          {/* QUANTO ISSO DURA FALADO: a cena aguenta a fala ou vai esticar? Só aparecia lá na
              Montagem, no nó da cena — depois de o texto estar escrito e o clipe pago. O piloto
              de 2026-07-29 saiu 58% inflado (2,5s de imagem congelada) porque a locução tinha 12s
              e o clipe 5s. É aqui que se escreve a fala, então é aqui que o relógio tem de estar. */}
          {s.narracao?.trim() && (
            <em style={{ marginLeft: 8, fontStyle: "normal", opacity: 0.75 }}>
              ≈ {Math.round(duracaoFala(s.narracao))}s falados
              {duracaoFala(s.narracao) > 10.5 && " ⚠️ não cabe nem num clipe de 10s"}
            </em>
          )}
        </span>
        <textarea value={s.narracao ?? ""} onChange={(e) => up("narracao", e.target.value)} rows={2}
          placeholder="1 a 2 frases. Não repita o que a imagem já mostra." style={{ ...inp, resize: "vertical" }} />
      </label>

      {/* DECUPAGEM: como a câmera cobre esta cena. Fica depois da dramaturgia de propósito — só dá
          pra decidir enquadramento depois de saber o que a cena quer dizer. */}
      {/* `onSaved` junto do estado local: o plano é gravado NA HORA pela própria PlanosDaCena, mas
          quem monta a ponte pra Montagem é a página — e ela lê a cena da lista dela. Sem avisar o
          pai, os planos recém-criados só apareciam no canvas depois de recarregar a tela. */}
      {/* A malha vem do primeiro personagem da cena (ou do primeiro elemento): é o sujeito que a
          câmera enquadra. Sem malha, o botão de âncora nem aparece. */}
      <PlanosDaCena sceneId={s.id} shots={s.shots ?? []}
        meshUrl={
          // Pelo PROXY do console (/api/mesh/...), não pela URL do storage: o loader busca por
          // fetch e o storage não manda cabeçalho de CORS — o arquivo vinha bloqueado.
          (s.character_ids ?? []).find((id) => characters.find((c) => c.id === id)?.mesh)
            ? `character/${(s.character_ids ?? []).find((id) => characters.find((c) => c.id === id)?.mesh)}`
            : (s.element_ids ?? []).find((id) => elements.find((c) => c.id === id)?.mesh)
              ? `element/${(s.element_ids ?? []).find((id) => elements.find((c) => c.id === id)?.mesh)}`
              : null
        }
        onChange={(shots) => { up("shots", shots); onSaved({ ...s, shots }); }} />

      <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", gap: 10 }}>
        <label style={{ display: "inline-flex", alignItems: "center", gap: 6, fontSize: ".82rem", color: "var(--muted)", cursor: "pointer" }}>
          <input type="checkbox" checked={!!s.virada} onChange={(e) => up("virada", e.target.checked)} /> Ponto de virada
        </label>
        <button type="button" className="btn ok" style={{ flex: "none", display: "inline-flex", alignItems: "center", gap: 6, padding: "8px 16px" }} onClick={salvar} disabled={salvando}>
          {salvando ? <span className="spinner" style={{ width: 13, height: 13 }} /> : <Save size={14} />} Salvar
        </button>
      </div>
    </div>
  );
}
