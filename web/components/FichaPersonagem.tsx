"use client";

// Ficha metodológica do personagem (F1 do fluxo image→cena). O form estruturado do `bible`
// (desejo/conflito/antagonista/físico+voz/psicológico/arquétipo/arco) da metodologia do curso.
// O botão "🧬 Arquiteto" roda a persona editável do console (aba Prompts) pelo SERVIDOR
// (/api/characters/persona-chat → engine /v1/chat) pra pré-preencher a ficha a partir de uma
// premissa; "🎨 Gerar base" faz o mesmo caminho pra virar prompt e gera a imagem pelo engine.
// Ambos chamavam rotas Next locais (binário no host, MMX_LOCAL=1) e morriam em "motor local
// desativado" com o web em container. Salva via PATCH /api/characters/{id}. Auto-contido: o
// Personagens.tsx só monta <FichaPersonagem char=… />.

import { useEffect, useState } from "react";
import { sfetch, Engine } from "@/lib/api";
import { useToast } from "@/components/ui/Toast";
import { Sparkles, Save, ImageIcon } from "lucide-react";

// O `bible` é um JSON livre que carrega DOIS conjuntos: a ficha metodológica (desejo/conflito/
// arco…, deste form) e a bíblia VISUAL do model sheet (palette/traits/accessories/expressions,
// gerada em outro fluxo). Por isso tratamos como objeto genérico e sempre MESCLAMOS — nunca
// sobrescrevemos — pra não apagar os campos visuais.
type Dict = Record<string, unknown>;
type Char = { id: number; name: string; style?: string | null; archetype?: string | null; logline?: string | null; bible?: Dict | null };

const ARQUETIPOS: [string, string][] = [
  ["", "— arquétipo —"],
  ["heroi", "🦸 Herói"],
  ["mentor", "🧙 Mentor"],
  ["guardiao_limiar", "🛡️ Guardião do limiar"],
  ["arauto", "📯 Arauto"],
  ["camaleao", "🦎 Camaleão"],
  ["sombra", "🌑 Sombra (vilão)"],
  ["picaro", "🃏 Pícaro"],
];

/** Lê a ficha JSON que a persona devolve. Tolerante de propósito: modelo de texto às vezes
 *  embrulha em ```json ou solta um parágrafo antes — o que importa é o objeto no meio. */
function parseFicha(raw: string): Dict | null {
  const semCerca = raw.match(/```(?:json)?\s*([\s\S]*?)```/)?.[1] ?? raw;
  const texto = semCerca.trim();
  try {
    return JSON.parse(texto) as Dict;
  } catch {
    const i = texto.indexOf("{");
    const f = texto.lastIndexOf("}");
    if (i >= 0 && f > i) {
      try {
        return JSON.parse(texto.slice(i, f + 1)) as Dict;
      } catch { /* devolve null abaixo */ }
    }
  }
  return null;
}

const inputStyle: React.CSSProperties = {
  width: "100%", background: "var(--bg2)", color: "var(--text)", border: "1px solid var(--line2)",
  borderRadius: 8, padding: "8px 10px", fontSize: ".88rem", fontFamily: "inherit",
};

export function FichaPersonagem({ char, onSaved }: { char: Char; onSaved?: (c: unknown) => void }) {
  const toast = useToast();
  const [archetype, setArchetype] = useState(char.archetype ?? "");
  const [logline, setLogline] = useState(char.logline ?? "");
  const [bible, setBible] = useState<Dict>((char.bible ?? {}) as Dict);
  const [premissa, setPremissa] = useState("");
  // A ficha vive dentro de um <details> NATIVO — colapsar/expandir não desmonta o React, e o
  // pai renderiza sem `key`. Sem isto o estado ficava preso no primeiro valor: bastava abrir os
  // Detalhes e depois clicar "🧬 Extrair da foto" (que escreve bible/lock por fora) pra que um
  // "Salvar ficha" regravasse o bible VELHO — apagando a extração recém-paga. Pior no fluxo F8:
  // personagem nascido do roteiro abre com bible {} e o save mandava {} (= apagar).
  // Só preenche quando a tela está vazia: nunca atropela edição em andamento.
  useEffect(() => {
    const vindo = (char.bible ?? {}) as Dict;
    setBible((atual) => (Object.keys(atual).length === 0 && Object.keys(vindo).length > 0 ? vindo : atual));
    setArchetype((a) => a || (char.archetype ?? ""));
    setLogline((l) => l || (char.logline ?? ""));
  }, [char.id, char.bible, char.archetype, char.logline]);
  const [gerando, setGerando] = useState(false);
  const [salvando, setSalvando] = useState(false);
  const [gerandoBase, setGerandoBase] = useState(false);

  // lê/escreve um caminho aninhado do bible imutavelmente.
  const get = (path: string[]): string => {
    let o: unknown = bible;
    for (const k of path) {
      if (o == null || typeof o !== "object") return "";
      o = (o as Record<string, unknown>)[k];
    }
    return typeof o === "string" ? o : "";
  };
  const set = (path: string[]) => (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) => {
    const value = e.target.value;
    setBible((prev) => {
      const next: Record<string, unknown> = structuredClone(prev ?? {});
      let o = next;
      for (let i = 0; i < path.length - 1; i++) {
        if (typeof o[path[i]] !== "object" || o[path[i]] == null) o[path[i]] = {};
        o = o[path[i]] as Record<string, unknown>;
      }
      o[path[path.length - 1]] = value;
      return next;
    });
  };

  async function arquiteto() {
    if (!premissa.trim()) {
      toast.err("Escreva uma premissa pro personagem.");
      return;
    }
    setGerando(true);
    try {
      // Vai pelo CONSOLE (persona da aba Prompts resolvida no servidor → engine /v1/chat). Antes
      // chamava a rota Next local, que roda o binário do motor no host: com o web em container
      // isso respondia "motor local desativado" e o botão não tinha caminho nenhum.
      const res = await sfetch("/api/characters/persona-chat", {
        method: "POST",
        body: JSON.stringify({ persona: "Arquiteto de Personagem", message: `PREMISSA: ${premissa.trim()}`, json: true }),
      });
      const j = await res.json().catch(() => ({}));
      if (!res.ok || !j?.ok) {
        toast.err(j?.error || "O Arquiteto não conseguiu montar a ficha.");
        return;
      }
      const f = (parseFicha(String(j.text ?? "")) ?? {}) as { archetype?: string; logline?: string; bible?: Dict };
      if (!f.archetype && !f.logline && !f.bible) {
        toast.err("O Arquiteto respondeu fora do formato de ficha. Tente de novo.");
        return;
      }
      if (f.archetype) setArchetype(String(f.archetype));
      if (f.logline) setLogline(String(f.logline));
      // MESCLA (não sobrescreve) — preserva a bíblia visual (palette/traits) já no bible.
      if (f.bible && typeof f.bible === "object") setBible((prev) => ({ ...prev, ...f.bible }));
      toast.ok("Ficha preenchida pelo Arquiteto — revise e salve.");
    } catch {
      toast.err("Erro de rede ao falar com o Arquiteto.");
    } finally {
      setGerando(false);
    }
  }

  async function salvar() {
    // Ficha vazia na tela + bible salvo no servidor = tela stale: salvar apagaria uma extração
    // de visão computacional (cara). O backend também recusa {} — cinto e suspensório.
    if (Object.keys(bible).length === 0 && Object.keys((char.bible ?? {}) as Dict).length > 0) {
      toast.err("A ficha está vazia nesta tela — recarregue a página antes de salvar (o texto salvo continua no servidor).");
      return;
    }
    setSalvando(true);
    try {
      const r = await sfetch(`/api/characters/${char.id}`, {
        method: "PATCH",
        body: JSON.stringify({ archetype: archetype || null, logline: logline || null, bible }),
      });
      const d = await r.json().catch(() => ({}));
      if (!r.ok) {
        toast.err(d?.error || "Não foi possível salvar a ficha.");
        return;
      }
      toast.ok("Ficha salva.");
      onSaved?.(d.character);
    } catch {
      toast.err("Erro ao salvar a ficha.");
    } finally {
      setSalvando(false);
    }
  }

  // F2: gera a BASE do personagem a PARTIR da ficha atual (persona 🎨 Ficha→Prompt no mmx local),
  // e aponta o personagem pra ela (base_url). Usa o estado atual do form (não precisa ter salvado).
  async function gerarBase() {
    setGerandoBase(true);
    try {
      // Dois passos, ambos no servidor (antes era a rota Next local — "motor local desativado"
      // em container): a persona 🎨 Ficha→Prompt vira PROMPT, o prompt vira IMAGEM pelo engine.
      const ficha = { name: char.name, style: char.style ?? "realista", archetype, logline, ...bible };
      const pr = await sfetch("/api/characters/persona-chat", {
        method: "POST",
        body: JSON.stringify({ persona: "Ficha → Prompt", message: `FICHA: ${JSON.stringify(ficha)}` }),
      });
      const pj = await pr.json().catch(() => ({}));
      if (!pr.ok || !pj?.ok || !String(pj.text ?? "").trim()) {
        toast.err(pj?.error || "Não foi possível montar o prompt da base.");
        return;
      }
      const j = await Engine.image({ prompt: String(pj.text).trim(), aspect: "3:4", style: char.style ?? "realista" });
      if (!j?.url) {
        toast.err("Não foi possível gerar a base.");
        return;
      }
      const sr = await sfetch(`/api/characters/${char.id}`, { method: "PATCH", body: JSON.stringify({ base_url: j.url }) });
      const sd = await sr.json().catch(() => ({}));
      if (!sr.ok) {
        toast.err("Base gerada, mas não deu pra salvar no personagem.");
        return;
      }
      toast.ok("Base gerada pela ficha!");
      onSaved?.(sd.character);
    } catch {
      toast.err("Erro ao gerar a base.");
    } finally {
      setGerandoBase(false);
    }
  }

  // helpers de render
  const Campo = ({ label, path }: { label: string; path: string[] }) => (
    <label style={{ display: "block", flex: 1, minWidth: 140 }}>
      <span style={{ fontSize: ".74rem", color: "var(--muted)", display: "block", marginBottom: 3 }}>{label}</span>
      <input value={get(path)} onChange={set(path)} style={inputStyle} />
    </label>
  );
  const Area = ({ label, path, rows = 2 }: { label: string; path: string[]; rows?: number }) => (
    <label style={{ display: "block" }}>
      <span style={{ fontSize: ".74rem", color: "var(--muted)", display: "block", marginBottom: 3 }}>{label}</span>
      <textarea value={get(path)} onChange={set(path)} rows={rows} style={{ ...inputStyle, resize: "vertical" }} />
    </label>
  );
  const Secao = ({ titulo, children }: { titulo: string; children: React.ReactNode }) => (
    <div style={{ borderTop: "1px solid var(--line)", paddingTop: 12, marginTop: 12 }}>
      <div style={{ fontWeight: 700, fontSize: ".82rem", color: "var(--peach)", marginBottom: 8 }}>{titulo}</div>
      <div style={{ display: "flex", flexDirection: "column", gap: 10 }}>{children}</div>
    </div>
  );
  const linha = { display: "flex", gap: 10, flexWrap: "wrap" as const };

  return (
    <div>
      {/* Arquiteto: premissa → ficha */}
      <div style={{ background: "var(--bg2)", border: "1px solid var(--line2)", borderRadius: 10, padding: 12 }}>
        <div style={{ fontSize: ".82rem", color: "var(--muted)", marginBottom: 8 }}>
          Descreva a premissa e deixe o <b style={{ color: "var(--peach)" }}>🧬 Arquiteto</b> montar a ficha (você revisa depois).
        </div>
        <textarea
          value={premissa}
          onChange={(e) => setPremissa(e.target.value)}
          disabled={gerando}
          rows={2}
          placeholder="Ex.: uma caçadora de recompensas que perdeu a filha e busca redenção num mundo pós-apocalíptico"
          style={{ ...inputStyle, resize: "vertical", marginBottom: 8 }}
        />
        <button type="button" className="btn ok" onClick={arquiteto} disabled={gerando}
          style={{ flex: "0 0 auto", display: "inline-flex", alignItems: "center", gap: 7, padding: "9px 16px" }}>
          {gerando ? <span className="spinner" style={{ width: 14, height: 14 }} /> : <Sparkles size={15} />}
          {gerando ? "Montando…" : "Arquiteto preenche"}
        </button>
      </div>

      <Secao titulo="Identidade">
        <label style={{ display: "block" }}>
          <span style={{ fontSize: ".74rem", color: "var(--muted)", display: "block", marginBottom: 3 }}>Logline (quem quer o quê, contra o quê)</span>
          <textarea value={logline} onChange={(e) => setLogline(e.target.value)} rows={2} style={{ ...inputStyle, resize: "vertical" }} />
        </label>
        <label style={{ display: "block", maxWidth: 260 }}>
          <span style={{ fontSize: ".74rem", color: "var(--muted)", display: "block", marginBottom: 3 }}>Arquétipo</span>
          <select value={archetype} onChange={(e) => setArchetype(e.target.value)} style={inputStyle}>
            {ARQUETIPOS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
          </select>
        </label>
      </Secao>

      <Secao titulo="Desejo (McKee)">
        <div style={linha}><Campo label="Objetivo (concreto)" path={["desejo", "objetivo"]} /><Campo label="Subjetivo (interno)" path={["desejo", "subjetivo"]} /></div>
      </Secao>

      <Secao titulo="Conflito">
        <div style={linha}><Campo label="Tipo (interno/externo)" path={["conflito", "tipo"]} /><Campo label="Natureza (psico/social/…)" path={["conflito", "natureza"]} /></div>
        <Area label="Descrição do conflito" path={["conflito", "descricao"]} />
      </Secao>

      <Secao titulo="Antagonista (o oposto que quer o mesmo)">
        <Area label="Quem/o que se opõe e por quê" path={["antagonista"]} />
      </Secao>

      <Secao titulo="Físico">
        <div style={linha}><Campo label="Apelido" path={["fisico", "apelido"]} /><Campo label="Idade" path={["fisico", "idade"]} /><Campo label="Sexo" path={["fisico", "sexo"]} /></div>
        <Area label="Aparência (cabelo, porte, marcas)" path={["fisico", "aparencia"]} />
        <div style={linha}><Campo label="Voz — timbre" path={["fisico", "voz", "timbre"]} /><Campo label="Voz — ritmo" path={["fisico", "voz", "ritmo"]} /></div>
        <div style={linha}><Campo label="Voz — tique de fala" path={["fisico", "voz", "tique"]} /><Campo label="Vocabulário / jargão" path={["fisico", "voz", "vocabulario"]} /></div>
      </Secao>

      <Secao titulo="Psicológico">
        <Area label="Personalidade" path={["psicologico", "personalidade"]} />
        <Area label="Passado / histórico" path={["psicologico", "passado"]} />
        <div style={linha}><Campo label="Valores" path={["psicologico", "valores"]} /></div>
        <Area label="Reflexo no físico (personalidade → figurino/trejeitos)" path={["psicologico", "reflexo_fisico"]} />
      </Secao>

      <Secao titulo="Arco (perguntas de desenvolvimento)">
        <div style={linha}><Campo label="Quer a longo prazo" path={["arco", "quer_longo"]} /><Campo label="Quer agora" path={["arco", "quer_agora"]} /></div>
        <div style={linha}><Campo label="O que atrapalha" path={["arco", "obstaculo"]} /><Campo label="Como tenta superar" path={["arco", "como_supera"]} /></div>
        <div style={linha}><Campo label="Nova situação" path={["arco", "nova_situacao"]} /><Campo label="Como muda os objetivos" path={["arco", "muda_objetivo"]} /></div>
        <div style={linha}><Campo label="Do que se afasta/aproxima" path={["arco", "afasta_aproxima"]} /><Campo label="Como enfrenta depois" path={["arco", "enfrenta_depois"]} /></div>
      </Secao>

      <div style={{ display: "flex", justifyContent: "flex-end", gap: 10, marginTop: 16, flexWrap: "wrap" }}>
        <button type="button" className="btn edit" onClick={gerarBase} disabled={gerandoBase || salvando}
          style={{ flex: "0 0 auto", display: "inline-flex", alignItems: "center", gap: 7, padding: "10px 18px" }}>
          {gerandoBase ? <span className="spinner" style={{ width: 14, height: 14 }} /> : <ImageIcon size={15} />}
          {gerandoBase ? "Gerando base…" : "Gerar base pela ficha"}
        </button>
        <button type="button" className="btn ok" onClick={salvar} disabled={salvando || gerandoBase}
          style={{ flex: "0 0 auto", display: "inline-flex", alignItems: "center", gap: 7, padding: "10px 20px" }}>
          {salvando ? <span className="spinner" style={{ width: 14, height: 14 }} /> : <Save size={15} />}
          {salvando ? "Salvando…" : "Salvar ficha"}
        </button>
      </div>
    </div>
  );
}
