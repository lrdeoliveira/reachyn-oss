"use client";

// Form do CENÁRIO metodológico (F3): edita o `spec` (função dramática/tríade tempo-espaço/
// atmosfera/riscos/jogabilidade) do curso. Botão "🗺️ Arquiteto" chama /api/character-bible (rota
// pelo SERVIDOR (/api/characters/persona-chat → engine) com a persona "🗺️ Arquiteto de Cenário", e "Gerar imagem" monta
// um tema do spec e usa /api/scenarios/{id}/image (console → engine), com modelo/qualidade/formato
// escolhidos na tela, como na geração de personagem. Salva via PATCH /api/scenarios/{id}.

import { useEffect, useState } from "react";
import { HistoricoDeVersoes } from "@/components/HistoricoDeVersoes";
import { sfetch, Console, type GenModelInfo } from "@/lib/api";
import { useToast } from "@/components/ui/Toast";
import { Sparkles, ImageIcon, Save } from "lucide-react";

type Dict = Record<string, unknown>;
type Scenario = { id: number; name: string; description?: string | null; spec?: Dict | null; image_model?: string | null; image_url?: string | null };

const inputStyle: React.CSSProperties = {
  width: "100%", background: "var(--bg2)", color: "var(--text)", border: "1px solid var(--line2)",
  borderRadius: 8, padding: "8px 10px", fontSize: ".88rem", fontFamily: "inherit",
};

/** Lê a ficha JSON da persona. Tolerante: o modelo às vezes embrulha em ```json ou solta um
 *  parágrafo antes — o que importa é o objeto no meio. */
function parseFicha(raw: string): Dict | null {
  const semCerca = raw.match(/```(?:json)?\s*([\s\S]*?)```/)?.[1] ?? raw;
  const texto = semCerca.trim();
  try {
    return JSON.parse(texto) as Dict;
  } catch {
    const i = texto.indexOf("{");
    const f = texto.lastIndexOf("}");
    if (i >= 0 && f > i) {
      try { return JSON.parse(texto.slice(i, f + 1)) as Dict; } catch { /* null abaixo */ }
    }
  }
  return null;
}

export function CenarioForm({ scenario, onSaved }: { scenario: Scenario; onSaved?: (s: unknown) => void }) {
  const toast = useToast();
  const [name, setName] = useState(scenario.name ?? "");
  const [spec, setSpec] = useState<Dict>((scenario.spec ?? {}) as Dict);
  // Cenário nascido do PROMPT DO FILME (F8) chega com `description` escrita pela Escaleta e o
  // spec vazio — sem isto o form parecia VAZIO e a descrição ficava invisível. Ela vira a
  // premissa pré-preenchida do Arquiteto: um clique em "Gerar" e o spec sai do texto do roteiro.
  const [premissa, setPremissa] = useState(scenario.description ?? "");
  const [gerando, setGerando] = useState(false);
  const [salvando, setSalvando] = useState(false);
  const [gerandoImg, setGerandoImg] = useState(false);
  // O estado inicial do useState não reage a props novas: com o card remontando (polling da
  // imagem, upsert da lista) o form ficava preso no primeiro valor — e quando a listagem ainda
  // não trazia o `spec`, a ambientação sumia da tela. Sincroniza quando MUDA DE CENÁRIO ou
  // quando o spec salvo chega depois; nunca por cima do que já existe na tela (não atropela
  // edição em andamento nem o resultado recém-gerado do Arquiteto, que ainda não foi salvo).
  useEffect(() => {
    const vindo = (scenario.spec ?? {}) as Dict;
    setSpec((atual) => (Object.keys(atual).length === 0 && Object.keys(vindo).length > 0 ? vindo : atual));
  }, [scenario.id, scenario.spec]);
  // MESMAS opções da geração de personagem (modelo · qualidade · formato). O cenário gerava com
  // o motor cravado no código e sempre em 16:9 — sem escolha de qualidade e sem como pedir um
  // ambiente vertical pro filme 9:16, que é o formato primário do produto.
  const [imgModels, setImgModels] = useState<GenModelInfo[]>([]);
  const [imgModel, setImgModel] = useState(scenario.image_model ?? "");
  const [quality, setQuality] = useState("");
  const [aspect, setAspect] = useState("16:9");
  // A imagem do cenário VIVE aqui: antes só aparecia no card da lista, e como a geração é
  // assíncrona (job no worker) ela nem aparecia sozinha — era preciso recarregar a página pra
  // descobrir se tinha dado certo. Agora o form mostra o estado (gerando / imagem / vazio) e
  // pergunta ao servidor até chegar.
  const [imagem, setImagem] = useState<string | null>(scenario.image_url ?? null);
  const [aguardando, setAguardando] = useState(false);
  const [zoom, setZoom] = useState(false);

  useEffect(() => {
    Console.imageModels()
      // utilitários (mapa PBR/refino/conserto) ficam fora: rodam por botão próprio, não geram cenário
      .then((r) => setImgModels((r.data ?? []).filter((m) => !m.utility).sort((a, b) => (a.cost_credits ?? 0) - (b.cost_credits ?? 0))))
      .catch(() => {});
  }, []);
  // Esc fecha a ampliação, igual à da lista — mesma tecla nos dois lugares.
  useEffect(() => {
    if (!zoom) return;
    const onKey = (e: KeyboardEvent) => { if (e.key === "Escape") setZoom(false); };
    window.addEventListener("keydown", onKey);

    return () => window.removeEventListener("keydown", onKey);
  }, [zoom]);
  // Trocar de modelo zera a qualidade: os tiers são por modelo (o "2K" de um não é o do outro).
  const qualities = imgModels.find((m) => m.slug === imgModel)?.qualities ?? [];

  const get = (path: string[]): string => {
    let o: unknown = spec;
    for (const k of path) {
      if (o == null || typeof o !== "object") return "";
      o = (o as Dict)[k];
    }
    return typeof o === "string" ? o : Array.isArray(o) ? o.join(", ") : "";
  };
  const set = (path: string[], asList = false) => (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) => {
    const raw = e.target.value;
    const value: unknown = asList ? raw.split(",").map((s) => s.trim()).filter(Boolean) : raw;
    setSpec((prev) => {
      const next: Dict = structuredClone(prev ?? {});
      let o = next;
      for (let i = 0; i < path.length - 1; i++) {
        if (typeof o[path[i]] !== "object" || o[path[i]] == null) o[path[i]] = {};
        o = o[path[i]] as Dict;
      }
      o[path[path.length - 1]] = value;
      return next;
    });
  };

  async function arquiteto() {
    if (!premissa.trim()) {
      toast.err("Escreva a premissa do cenário.");
      return;
    }
    setGerando(true);
    try {
      // Pelo SERVIDOR (persona da aba Prompts → engine /v1/chat). Antes ia pela rota Next que
      // executa o binário no host: com o web em container isso era 403 "motor local desativado".
      const res = await sfetch("/api/characters/persona-chat", {
        method: "POST",
        body: JSON.stringify({ persona: "Arquiteto de Cenário", message: `PREMISSA: ${premissa.trim()}`, json: true }),
      });
      const j = await res.json().catch(() => ({}));
      if (!res.ok || !j?.ok) {
        toast.err(j?.error || "O Arquiteto de Cenário falhou.");
        return;
      }
      const f = (parseFicha(String(j.text ?? "")) ?? {}) as { name?: string; spec?: Dict };
      if (!f.name && !f.spec) {
        toast.err("O Arquiteto respondeu fora do formato. Tente de novo.");
        return;
      }
      if (f.name) setName(String(f.name));
      if (f.spec && typeof f.spec === "object") setSpec(f.spec);
      toast.ok("Ambientação preenchida — revise e salve.");
    } catch {
      toast.err("Erro de rede ao falar com o Arquiteto.");
    } finally {
      setGerando(false);
    }
  }

  async function salvar() {
    // Não manda ambientação VAZIA: um save de form que nunca carregou o spec (tela stale)
    // apagaria o trabalho do Arquiteto no servidor. O backend também recusa {} — cinto e
    // suspensório, porque o dado aqui é caro (uma chamada de IA longa).
    if (Object.keys(spec).length === 0) {
      toast.err("A ambientação está vazia nesta tela — recarregue a página antes de salvar (o texto salvo continua no servidor).");
      return;
    }
    setSalvando(true);
    try {
      const r = await sfetch(`/api/scenarios/${scenario.id}`, { method: "PATCH", body: JSON.stringify({ name: name.trim() || "Cenário", spec }) });
      const d = await r.json().catch(() => ({}));
      if (!r.ok || !d?.ok) {
        toast.err(d?.error || "Não foi possível salvar o cenário.");
        return;
      }
      toast.ok("Cenário salvo.");
      onSaved?.(d.scenario);
    } catch {
      toast.err("Erro ao salvar.");
    } finally {
      setSalvando(false);
    }
  }

  // Gera a imagem-âncora do cenário a partir do spec (tema → /api/image no mmx local) e salva.
  async function gerarImagem() {
    setGerandoImg(true);
    try {
      const a = (spec.atmosfera ?? {}) as Dict;
      const te = (spec.tempo_espaco ?? {}) as Dict;
      const tema = [
        name,
        te.espaco, te.tempo,
        a.mood, a.luz, a.clima, a.paleta,
        spec.funcao_dramatica,
      ].filter(Boolean).join(", ");
      if (!tema.trim()) {
        toast.err("Preencha a ambientação antes de gerar a imagem.");
        return;
      }
      // Rota OFICIAL do console: ela mesma monta o establishing shot (lugar vazio de gente —
      // figura no quadro contaminaria o i2i das cenas), cobra a cota e salva a URL no cenário.
      // O caminho antigo era a rota Next do motor local, que em container responde 403.
      const res = await sfetch(`/api/scenarios/${scenario.id}/image`, {
        method: "POST",
        body: JSON.stringify({ description: tema, model: imgModel || undefined, quality: quality || undefined, aspect }),
      });
      const j = await res.json().catch(() => ({}));
      if (!res.ok || !j?.ok) {
        toast.err(j?.error || "Não foi possível gerar a imagem do cenário.");
        return;
      }
      toast.ok("Gerando a imagem do cenário… ela aparece aqui quando ficar pronta.");
      if (j.scenario) onSaved?.(j.scenario);
      // O job roda no worker: pergunta ao servidor até a URL mudar (ou desistir em ~3min, que é
      // bem mais que os ~40s de uma geração normal).
      setAguardando(true);
      const antes = imagem ?? "";
      for (let i = 0; i < 45; i++) {
        await new Promise((r) => setTimeout(r, 4000));
        const sr = await sfetch(`/api/scenarios`).catch(() => null);
        const lista = sr ? await sr.json().catch(() => []) : [];
        const atual = (Array.isArray(lista) ? lista : []).find((x: { id: number }) => x.id === scenario.id);
        if (atual?.image_url && atual.image_url !== antes) {
          setImagem(atual.image_url);
          onSaved?.(atual);
          toast.ok("Imagem do cenário pronta!");
          break;
        }
        if (atual && !atual.status && i > 2) break; // job terminou sem produzir — não fica girando à toa
      }
      setAguardando(false);
    } catch {
      toast.err("Erro ao gerar a imagem.");
    } finally {
      setGerandoImg(false);
    }
  }

  const Campo = ({ label, path, list = false }: { label: string; path: string[]; list?: boolean }) => (
    <label style={{ display: "block", flex: 1, minWidth: 140 }}>
      <span style={{ fontSize: ".74rem", color: "var(--muted)", display: "block", marginBottom: 3 }}>{label}</span>
      <input value={get(path)} onChange={set(path, list)} style={inputStyle} />
    </label>
  );
  const linha = { display: "flex", gap: 10, flexWrap: "wrap" as const };

  return (
    <div style={{ display: "flex", flexDirection: "column", gap: 12 }}>
      <label style={{ display: "block" }}>
        <span style={{ fontSize: ".74rem", color: "var(--muted)", display: "block", marginBottom: 3 }}>Nome do cenário</span>
        <input value={name} onChange={(e) => setName(e.target.value)} style={inputStyle} />
      </label>
      <div style={{ background: "var(--bg2)", border: "1px solid var(--line2)", borderRadius: 10, padding: 12 }}>
        <div style={{ fontSize: ".82rem", color: "var(--muted)", marginBottom: 8 }}>
          Descreva a premissa e deixe o <b style={{ color: "var(--peach)" }}>🗺️ Arquiteto de Cenário</b> montar a ambientação.
        </div>
        <textarea value={premissa} onChange={(e) => setPremissa(e.target.value)} disabled={gerando} rows={2}
          placeholder="Ex.: o posto de gasolina em ruínas onde a caçadora reencontra o vilão ao crepúsculo"
          style={{ ...inputStyle, resize: "vertical", marginBottom: 8 }} />
        <button type="button" className="btn ok" onClick={arquiteto} disabled={gerando}
          style={{ flex: "0 0 auto", display: "inline-flex", alignItems: "center", gap: 7, padding: "9px 16px" }}>
          {gerando ? <span className="spinner" style={{ width: 14, height: 14 }} /> : <Sparkles size={15} />}
          {gerando ? "Montando…" : "Arquiteto preenche"}
        </button>
      </div>

      <textarea value={get(["funcao_dramatica"])} onChange={set(["funcao_dramatica"])} rows={2}
        placeholder="Função dramática — o que este espaço provoca na trama"
        style={{ ...inputStyle, resize: "vertical" }} />
      <div style={linha}><Campo label="Tempo (época/hora)" path={["tempo_espaco", "tempo"]} /><Campo label="Espaço (lugar)" path={["tempo_espaco", "espaco"]} /></div>
      <div style={linha}><Campo label="Mood" path={["atmosfera", "mood"]} /><Campo label="Luz" path={["atmosfera", "luz"]} /></div>
      <div style={linha}><Campo label="Clima" path={["atmosfera", "clima"]} /><Campo label="Paleta" path={["atmosfera", "paleta"]} /></div>
      <Campo label="Riscos (separe por vírgula)" path={["riscos"]} list />
      <textarea value={get(["jogabilidade"])} onChange={set(["jogabilidade"])} rows={2}
        placeholder="Jogabilidade — como o cenário afeta escolhas/caminhos"
        style={{ ...inputStyle, resize: "vertical" }} />

      {/* CAIXA DA IMAGEM — o resultado tem que estar na mesma tela onde se pede. Sem ela, gerar
          era um ato de fé: o job roda no worker e a única prova ficava no card da lista, depois
          de recarregar a página. */}
      {/* PREVIEW: teto de altura + largura automática. Cada tentativa anterior errava de um
          jeito diferente porque o formato do cenário agora é escolhido na hora de gerar (não é
          mais sempre 16:9): `cover` cortava o enquadramento, `contain` em caixa larga deixava
          tarjas, e altura automática esticava um vertical até empurrar os controles pra fora da
          tela. Aqui a imagem manda na proporção e a caixa só limita o quanto ela ocupa. */}
      <div style={{ position: "relative", width: "100%", minHeight: imagem ? 0 : 140, padding: imagem ? 8 : 0, borderRadius: 10, border: "1px solid var(--line2)", background: "var(--bg2)", display: "grid", placeItems: "center", overflow: "hidden" }}>
        {imagem ? (
          // eslint-disable-next-line @next/next/no-img-element
          // `contain`: o cover cortava topo e base do 16:9 — justo o enquadramento que se quer
          // julgar. Aqui a imagem aparece INTEIRA, com fundo preto no que sobra.
          <img src={imagem} alt={name} onClick={() => setZoom(true)} title="Clique pra ampliar"
            style={{ maxHeight: 320, maxWidth: "100%", width: "auto", height: "auto", display: "block", borderRadius: 6, cursor: "zoom-in" }} />
        ) : (
          <span className="txt" style={{ color: "var(--muted)", fontSize: ".82rem", padding: 24, textAlign: "center" }}>
            {gerandoImg || aguardando ? "⏳ gerando o ambiente…" : "Sem imagem ainda — preencha a ambientação e clique em Gerar imagem."}
          </span>
        )}
        {(gerandoImg || aguardando) && imagem && (
          <div style={{ position: "absolute", inset: 0, display: "grid", placeItems: "center", background: "rgba(0,0,0,.5)", color: "#fff", fontSize: ".82rem" }}>
            ⏳ gerando uma nova…
          </div>
        )}
      </div>

      {/* Regerar não apaga mais a versão anterior — ela fica aqui, a um clique. */}
      <HistoricoDeVersoes tipo="scenario" id={scenario.id} campo="image_url"
        onRestaurado={(a) => { const u = (a as { image_url?: string }).image_url; if (u) setImagem(u); }} />

      {/* Tela cheia: 16:9 num cartão de lista não deixa julgar luz e materiais, que é exatamente
          o que faz este cenário servir (ou não) de âncora pras cenas. */}
      {zoom && imagem && (
        /* flex, não grid: com grid o maxHeight:100% da imagem não clampa (track auto) e
           imagem alta saía cortada sem scroll — mesmo fix dos elementos/galeria. */
        <div onClick={() => setZoom(false)}
          style={{ position: "fixed", inset: 0, zIndex: 60, background: "rgba(0,0,0,.9)", display: "flex", alignItems: "center", justifyContent: "center", padding: 24, cursor: "zoom-out" }}>
          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img src={imagem} alt={name} style={{ maxWidth: "100%", maxHeight: "100%", objectFit: "contain", borderRadius: 10 }} />
        </div>
      )}

      <div style={{ display: "flex", alignItems: "flex-end", justifyContent: "flex-end", gap: 10, flexWrap: "wrap" }}>
        {imgModels.length > 0 && (
          <label style={{ display: "flex", flexDirection: "column", gap: 4 }}>
            <span style={{ fontSize: ".72rem", color: "var(--muted)" }}>Modelo</span>
            <select value={imgModel} onChange={(e) => { setImgModel(e.target.value); setQuality(""); }} disabled={gerandoImg}
              style={{ ...inputStyle, width: "auto", minWidth: 170 }}>
              <option value="">automático</option>
              {imgModels.map((m) => (
                <option key={m.slug} value={m.slug}>{m.real_name ?? m.display_name}{m.cost_credits != null ? ` · ${m.cost_credits} cr` : ""}</option>
              ))}
            </select>
          </label>
        )}
        {qualities.length > 0 && (
          <label style={{ display: "flex", flexDirection: "column", gap: 4 }}>
            <span style={{ fontSize: ".72rem", color: "var(--muted)" }}>Qualidade</span>
            <select value={quality} onChange={(e) => setQuality(e.target.value)} disabled={gerandoImg}
              style={{ ...inputStyle, width: "auto", minWidth: 120 }}>
              <option value="">padrão</option>
              {qualities.map((q) => (
                <option key={q.key} value={q.key}>{q.label}{q.p != null ? ` · ${q.p} cr` : ""}</option>
              ))}
            </select>
          </label>
        )}
        <label style={{ display: "flex", flexDirection: "column", gap: 4 }}>
          <span style={{ fontSize: ".72rem", color: "var(--muted)" }}>Formato</span>
          <select value={aspect} onChange={(e) => setAspect(e.target.value)} disabled={gerandoImg}
            style={{ ...inputStyle, width: "auto", minWidth: 90 }}>
            {["16:9", "9:16", "1:1", "4:3", "3:4", "21:9"].map((a) => <option key={a} value={a}>{a}</option>)}
          </select>
        </label>
        <button type="button" className="btn edit" onClick={gerarImagem} disabled={gerandoImg || salvando}
          style={{ flex: "0 0 auto", display: "inline-flex", alignItems: "center", gap: 7, padding: "10px 18px" }}>
          {gerandoImg ? <span className="spinner" style={{ width: 14, height: 14 }} /> : <ImageIcon size={15} />}
          {gerandoImg ? "Gerando…" : "Gerar imagem"}
        </button>
        <button type="button" className="btn ok" onClick={salvar} disabled={salvando || gerandoImg}
          style={{ flex: "0 0 auto", display: "inline-flex", alignItems: "center", gap: 7, padding: "10px 20px" }}>
          {salvando ? <span className="spinner" style={{ width: 14, height: 14 }} /> : <Save size={15} />}
          {salvando ? "Salvando…" : "Salvar"}
        </button>
      </div>
    </div>
  );
}
