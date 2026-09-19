"use client";

// PLANOS de uma cena (decupagem) — a camada entre a cena e a imagem.
//
// POR QUE EXISTE: a unidade era `cena = 1 quadro = 1 clipe`, e no cinema ninguém filma uma cena
// inteira num enquadramento só — por isso o resultado parecia "clipes bonitos em sequência" em vez
// de cena coberta. Aqui a cena vira N planos, cada um com o próprio enquadramento, ângulo, altura
// de câmera e movimento, e cada plano vira um nó na Montagem.
//
// O BLOCKING É DADO, não adjetivo: os quatro campos são listas FECHADAS (vêm do servidor, de
// `App\Support\Plano`) porque "plano baixo e dramático" não se repete e "low angle, camera at knee
// height" sim. Mesma lição da régua de escala do cenário.
//
// Cena SEM plano continua valendo: vira um plano só na Montagem, como era antes.

import { useEffect, useState } from "react";

/** Rótulos dos AJUSTES rápidos do quadro. Espelha as chaves de ShotController::AJUSTES — a FRASE
 *  que vai ao modelo vive no servidor (é ela que precisa ser concreta); aqui é só o nome que a
 *  pessoa lê. Chave nova lá, rótulo novo aqui. */
const AJUSTES_UI: [string, string][] = [
  ["escuro", "🌑 mais escuro"],
  ["claro", "☀️ mais claro"],
  ["dramatico", "🎭 mais dramático"],
  ["aberto", "↔️ plano mais aberto"],
  ["fechado", "🔍 plano mais fechado"],
  ["chuva", "🌧️ chuva"],
  ["neblina", "🌫️ neblina"],
  ["quente", "🔥 grade quente"],
  ["frio", "❄️ grade fria"],
  ["mao", "🎥 câmera na mão"],
];
import { sfetch } from "@/lib/api";
import { useToast } from "@/components/ui/Toast";
import { ChevronUp, ChevronDown, Trash2, Plus, Clapperboard, Box } from "lucide-react";
import { renderMalha } from "@/lib/renderMalha";

export type Shot = {
  id: number; ordem: number; funcao?: string | null;
  enquadramento?: string | null; angulo?: string | null; altura?: string | null; movimento?: string | null;
  acao?: string | null; duracao?: number | null;
  // Render da malha NESTE ângulo — vira a primeira âncora do plano na produção.
  ancora_url?: string | null;
  // QUADRO gerado obedecendo a âncora (motor local de pose — Fase 2.1 do docs/ESTUDIO-3D.md).
  quadro_url?: string | null;
  quadro_status?: string | null; // 'gerando' | 'erro' | null
};
type Opcao = { valor: string; rotulo: string };
type Vocabulario = Record<string, Opcao[]>;

const CAMPOS: { chave: keyof Shot & string; titulo: string }[] = [
  // FUNÇÃO abre a lista: é o que o plano FAZ no corte (master, cutaway, reação), decidido antes
  // de como ele é composto. Veio da leitura do Celtx, cujo tipo de plano mistura as duas coisas.
  { chave: "funcao", titulo: "Função" },
  { chave: "enquadramento", titulo: "Enquadramento" },
  { chave: "angulo", titulo: "Ângulo" },
  { chave: "altura", titulo: "Altura da câmera" },
  { chave: "movimento", titulo: "Movimento" },
];

// O vocabulário é o mesmo pra todas as cenas da tela: buscar uma vez por montagem do módulo evita
// uma requisição por cartão aberto (uma escaleta de 12 cenas fazia 12 chamadas iguais).
let cacheVocab: Vocabulario | null = null;

const sel: React.CSSProperties = {
  background: "var(--bg2)", color: "var(--text)", border: "1px solid var(--line2)",
  borderRadius: 8, padding: "5px 7px", fontSize: ".74rem", fontFamily: "inherit", maxWidth: 150,
};

export function PlanosDaCena({ sceneId, shots, meshUrl, aspect = "16:9", onChange }: {
  sceneId: number; shots: Shot[];
  /** `tipo/id` do dono da malha (ex.: "character/16") — o console serve o arquivo. */
  meshUrl?: string | null;
  aspect?: string;
  onChange: (shots: Shot[]) => void;
}) {
  const toast = useToast();
  const [vocab, setVocab] = useState<Vocabulario | null>(cacheVocab);
  const [busy, setBusy] = useState(false);
  // ESTÚDIO LOCAL: só o "quadro" depende dele (motor img-local-pose, que nasce inativo no
  // catálogo e só existe onde há ComfyUI de pé) — sem gate ele aparecia sempre e devolvia 422
  // "o motor local de pose não está ativo neste ambiente" em 100% dos cliques em produção.
  // A "âncora" NÃO entra aqui: ela é renderizada no navegador (WebGL) e só faz upload do PNG,
  // então funciona em qualquer ambiente. Mesmo sinal dos botões de render da aba /3d.
  const [estudioLocal, setEstudioLocal] = useState(false);
  useEffect(() => {
    sfetch("/api/mesh-health")
      .then((r) => r.json())
      .then((d) => setEstudioLocal(!!d?.ok))
      .catch(() => setEstudioLocal(false));
  }, []);
  const semEstudio = "Precisa do seu estúdio local ligado — é ele que renderiza a malha e desenha o quadro.";
  const [decupando, setDecupando] = useState(false);

  useEffect(() => {
    if (cacheVocab) return;
    sfetch("/api/shots/vocabulario").then((r) => r.json()).then((j) => { cacheVocab = j; setVocab(j); }).catch(() => {});
  }, []);

  // Salva o campo direto (sem botão): o plano é um punhado de selects, e exigir "salvar" num
  // cartão que já tem o salvar da CENA confundiria qual botão grava o quê.
  async function patch(shot: Shot, campo: string, valor: string | number) {
    const antes = shots;
    onChange(shots.map((s) => (s.id === shot.id ? { ...s, [campo]: valor } : s)));
    try {
      const r = await sfetch(`/api/shots/${shot.id}`, { method: "PATCH", body: JSON.stringify({ [campo]: valor }) });
      if (!r.ok) throw new Error();
    } catch {
      onChange(antes);   // volta ao que estava: melhor do que a tela mentir que salvou
      toast.err("Não deu pra salvar o plano.");
    }
  }

  async function novo() {
    setBusy(true);
    try {
      const r = await sfetch("/api/shots", { method: "POST", body: JSON.stringify({ scene_id: sceneId, duracao: 5 }) });
      const d = await r.json().catch(() => ({}));
      if (!r.ok || !d?.ok) { toast.err(d?.error || "Não deu pra criar o plano."); return; }
      onChange([...shots, d.shot]);
    } catch { toast.err("Erro ao criar o plano."); } finally { setBusy(false); }
  }

  async function excluir(id: number) {
    const antes = shots;
    onChange(shots.filter((s) => s.id !== id));
    try {
      const r = await sfetch(`/api/shots/${id}`, { method: "DELETE" });
      if (!r.ok) throw new Error();
    } catch { onChange(antes); toast.err("Não deu pra excluir o plano."); }
  }

  async function mover(id: number, dir: -1 | 1) {
    const lista = [...shots];
    const i = lista.findIndex((x) => x.id === id);
    const j = i + dir;
    if (i < 0 || j < 0 || j >= lista.length) return;
    [lista[i], lista[j]] = [lista[j], lista[i]];
    onChange(lista);
    await sfetch("/api/shots/reorder", { method: "POST", body: JSON.stringify({ ids: lista.map((x) => x.id) }) }).catch(() => {});
  }

  async function decupar() {
    setDecupando(true);
    try {
      const r = await sfetch(`/api/scenes/${sceneId}/decupar`, { method: "POST", body: JSON.stringify({ planos: 4 }) });
      const d = await r.json().catch(() => ({}));
      if (!r.ok || !d?.ok) { toast.err(d?.error || "Não foi possível decupar."); return; }
      onChange(d.shots ?? []);
      toast.ok(`${d.criados} plano(s) — revise antes de produzir.`);
    } catch { toast.err("Erro ao falar com a IA."); } finally { setDecupando(false); }
  }

  // ÂNCORA DE ÂNGULO: a decupagem já diz de onde a câmera olha; com a malha em mãos isso deixa de
  // ser torcida e vira geometria — renderiza o plano pedido e usa o render como referência.
  // Roda no navegador (WebGL): sem GPU dedicada, sem fila, segundos.
  async function ancorar(shot: Shot) {
    if (!meshUrl) return;
    setBusy(true);
    try {
      // A malha vem pelo console (autenticada, mesma origem): buscar direto no storage esbarra em
      // CORS. Os BYTES vão pro renderer — URL `blob:` esbarraria no connect-src da CSP.
      const rm = await sfetch(`/api/mesh/${meshUrl}`);
      if (!rm.ok) { toast.err("Não foi possível ler a malha."); return; }
      const { blob } = await renderMalha(await rm.arrayBuffer(), shot, aspect);
      const fd = new FormData();
      fd.append("file", new File([blob], `plano-${shot.id}.png`, { type: "image/png" }));
      const r = await sfetch(`/api/shots/${shot.id}/ancora`, { method: "POST", body: fd });
      const d = await r.json().catch(() => ({}));
      if (!r.ok || !d?.ok) { toast.err(d?.error || "Não deu pra salvar a âncora."); return; }
      onChange(shots.map((x) => (x.id === shot.id ? d.shot : x)));
      toast.ok("Âncora do ângulo gerada a partir da malha.");
    } catch {
      toast.err("Não foi possível renderizar a malha.");
    } finally { setBusy(false); }
  }

  // QUADRO DO PLANO: a âncora vira contorno e o motor local desenha a cena POR CIMA da
  // composição (~104s medidos) — por isso async + polling do shot individual, sem recarregar
  // a escaleta inteira.
  async function gerarQuadro(shot: Shot, ajuste?: string) {
    try {
      const r = await sfetch(`/api/shots/${shot.id}/quadro`, {
        method: "POST",
        ...(ajuste ? { body: JSON.stringify({ ajuste }) } : {}),
      });
      const d = await r.json().catch(() => ({}));
      if (!r.ok || !d?.ok) { toast.err(d?.error || "Não deu pra gerar o quadro."); return; }
      onChange(shots.map((x) => (x.id === shot.id ? d.shot : x)));
      toast.ok("Gerando o quadro deste plano — leva uns 2 minutos.");
    } catch { toast.err("Não deu pra gerar o quadro agora."); }
  }

  // ⬆️ UPSCALE SELETIVO: sobe a resolução DESTE quadro e amarra o resultado ao plano. O quadro
  // nasce pequeno (é um painel numa folha) e precisa de detalhe pra virar âncora de vídeo.
  const [subindo, setSubindo] = useState<number | null>(null);
  async function upscaleQuadro(shot: Shot) {
    setSubindo(shot.id);
    try {
      const r = await sfetch(`/api/shots/${shot.id}/upscale`, { method: "POST" });
      const d = await r.json().catch(() => ({}));
      if (!r.ok || !d?.ok) { toast.err(d?.error || "Não deu pra melhorar o quadro."); return; }
      onChange(shots.map((x) => (x.id === shot.id ? d.shot : x)));
      toast.ok("Quadro em alta — agora serve de âncora pro clipe.");
    } catch { toast.err("Não deu pra melhorar o quadro agora."); } finally { setSubindo(null); }
  }

  const gerandoIds = shots.filter((s) => s.quadro_status === "gerando").map((s) => s.id).join(",");
  useEffect(() => {
    if (!gerandoIds) return;
    const t = setInterval(async () => {
      for (const id of gerandoIds.split(",")) {
        try {
          const r = await sfetch(`/api/shots/${id}`);
          const d = await r.json().catch(() => ({}));
          if (d?.ok && d.shot && d.shot.quadro_status !== "gerando") {
            onChange(shots.map((x) => (x.id === d.shot.id ? d.shot : x)));
            if (d.shot.quadro_url && d.shot.quadro_status === null) toast.ok("Quadro do plano pronto.");
            else if (d.shot.quadro_status === "erro") toast.err("O quadro deste plano falhou — crédito estornado.");
          }
        } catch { /* tenta de novo no próximo tick */ }
      }
    }, 8000);
    return () => clearInterval(t);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [gerandoIds]);

  const totalSeg = shots.reduce((t, s) => t + (Number(s.duracao) || 0), 0);

  return (
    <div style={{ borderTop: "1px solid var(--line)", marginTop: 12, paddingTop: 10 }}>
      <div style={{ display: "flex", alignItems: "center", gap: 8, flexWrap: "wrap", marginBottom: shots.length ? 8 : 0 }}>
        <span style={{ fontSize: ".74rem", color: "var(--muted)" }}>
          🎬 Planos {shots.length > 0 && <>· {shots.length} · {totalSeg}s</>}
        </span>
        <button type="button" className="btn edit" onClick={novo} disabled={busy}
          title="Um plano a mais nesta cena — você escolhe o enquadramento e o ângulo"
          style={{ flex: "none", padding: "4px 9px", fontSize: ".72rem", display: "inline-flex", alignItems: "center", gap: 4 }}>
          <Plus size={12} /> Plano
        </button>
        <button type="button" className="btn edit" onClick={decupar} disabled={decupando}
          title="A IA cobre a cena em planos (abre, aproxima, fecha no que importa) — acrescenta aos que já existem"
          style={{ flex: "none", padding: "4px 9px", fontSize: ".72rem", display: "inline-flex", alignItems: "center", gap: 4 }}>
          <Clapperboard size={12} /> {decupando ? "decupando…" : "Decupar com IA"}
        </button>
        {shots.length === 0 && (
          <span style={{ fontSize: ".72rem", color: "var(--muted)", opacity: 0.8 }}>
            sem planos, a cena inteira vira um quadro só
          </span>
        )}
      </div>

      <div style={{ display: "flex", flexDirection: "column", gap: 7 }}>
        {shots.map((s, i) => (
          <div key={s.id} style={{ background: "var(--bg2)", border: "1px solid var(--line2)", borderRadius: 8, padding: "7px 9px" }}>
            <div style={{ display: "flex", alignItems: "center", gap: 6, flexWrap: "wrap" }}>
              <strong style={{ fontSize: ".72rem", color: "var(--peach)", minWidth: 26 }}>{i + 1}</strong>
              {CAMPOS.map(({ chave, titulo }) => (
                <select key={chave} title={titulo} value={(s[chave] as string) ?? ""}
                  onChange={(e) => void patch(s, chave, e.target.value)} style={sel}>
                  <option value="">{titulo.toLowerCase()}…</option>
                  {(vocab?.[chave] ?? []).map((o) => <option key={o.valor} value={o.valor}>{o.rotulo}</option>)}
                </select>
              ))}
              <label style={{ display: "inline-flex", alignItems: "center", gap: 3, fontSize: ".72rem", color: "var(--muted)" }}>
                <input type="number" min={1} max={30} value={s.duracao ?? 5}
                  onChange={(e) => void patch(s, "duracao", Math.max(1, Math.min(30, Number(e.target.value) || 5)))}
                  style={{ ...sel, width: 46, maxWidth: 46 }} />s
              </label>
              {/* Âncora SEM gate de estúdio: é renderizada NO NAVEGADOR (WebGL, ver renderMalha)
                  e só sobe o PNG — não passa pelo blender-bridge, então funciona em produção. */}
              {meshUrl && (
                <button type="button" className="btn edit" onClick={() => void ancorar(s)} disabled={busy}
                  title="Renderiza a malha exatamente neste ângulo e usa como âncora do plano"
                  style={{ ...mini, display: "inline-flex", alignItems: "center", gap: 3 }}>
                  <Box size={11} /> âncora
                </button>
              )}
              {s.ancora_url && (
                // eslint-disable-next-line @next/next/no-img-element
                <img src={s.ancora_url} alt="âncora do ângulo" title="Âncora renderizada da malha"
                  style={{ width: 30, height: 30, objectFit: "cover", borderRadius: 5, border: "1px solid var(--line2)" }} />
              )}
              {s.ancora_url && (
                <button type="button" className="btn edit" onClick={() => void gerarQuadro(s)}
                  disabled={s.quadro_status === "gerando" || !estudioLocal}
                  title={estudioLocal ? "O motor local desenha a cena POR CIMA da composição da âncora — o ângulo deixa de ser pedido por texto" : semEstudio}
                  style={{ ...mini, display: "inline-flex", alignItems: "center", gap: 3 }}>
                  🎨 {s.quadro_status === "gerando" ? "quadro…" : "quadro"}
                </button>
              )}
              {s.quadro_url && (
                <a href={s.quadro_url} target="_blank" rel="noreferrer" title="Quadro gerado obedecendo a âncora — clique pra ver inteiro">
                  {/* eslint-disable-next-line @next/next/no-img-element */}
                  <img src={s.quadro_url} alt="quadro do plano"
                    style={{ width: 30, height: 30, objectFit: "cover", borderRadius: 5, border: "1px solid var(--peach)", display: "block" }} />
                </a>
              )}
              {/* REFINO DESTE painel: um ajuste de cada vez, sem refazer a folha inteira. É a
                  vantagem de a folha ser composta de imagens separadas — corrigir um quadro custa
                  um quadro. O vocabulário é fechado (vem de /api/shots/ajustes) porque adjetivo
                  solto não muda difusor: "mais dramático" não faz nada, "uma fonte dura, sombra
                  profunda, alto contraste" faz. */}
              {s.ancora_url && (
                <select defaultValue="" disabled={s.quadro_status === "gerando" || !estudioLocal}
                  onChange={(e) => { const v = e.target.value; e.currentTarget.value = ""; if (v) void gerarQuadro(s, v); }}
                  title={estudioLocal ? "Refaz SÓ este quadro com um ajuste" : semEstudio}
                  style={{ ...mini, padding: "2px 4px" }}>
                  <option value="">✨ ajustar…</option>
                  {AJUSTES_UI.map(([k, rot]) => <option key={k} value={k}>{rot}</option>)}
                </select>
              )}
              {s.quadro_url && (
                <button type="button" className="btn edit" onClick={() => void upscaleQuadro(s)}
                  disabled={subindo === s.id}
                  title="Sobe a resolução deste quadro e o amarra ao plano — é ele que vira a âncora do clipe"
                  style={{ ...mini, display: "inline-flex", alignItems: "center", gap: 3 }}>
                  ⬆️ {subindo === s.id ? "subindo…" : "alta"}
                </button>
              )}
              <span style={{ marginLeft: "auto", display: "inline-flex", gap: 3 }}>
                <button type="button" className="btn edit" onClick={() => void mover(s.id, -1)} title="Subir" style={mini}><ChevronUp size={12} /></button>
                <button type="button" className="btn edit" onClick={() => void mover(s.id, 1)} title="Descer" style={mini}><ChevronDown size={12} /></button>
                <button type="button" className="btn no" onClick={() => void excluir(s.id)} title="Excluir plano" style={mini}><Trash2 size={12} /></button>
              </span>
            </div>
            <input value={s.acao ?? ""} onChange={(e) => onChange(shots.map((x) => (x.id === s.id ? { ...x, acao: e.target.value } : x)))}
              onBlur={(e) => void patch(s, "acao", e.target.value)}
              placeholder="o que se vê NESTE plano — uma frase concreta"
              style={{ width: "100%", marginTop: 6, background: "var(--panel)", color: "var(--text)", border: "1px solid var(--line2)", borderRadius: 7, padding: "5px 8px", fontSize: ".78rem", fontFamily: "inherit" }} />
          </div>
        ))}
      </div>
    </div>
  );
}

const mini: React.CSSProperties = { flex: "none", padding: "3px 6px", fontSize: ".7rem", lineHeight: 1 };
