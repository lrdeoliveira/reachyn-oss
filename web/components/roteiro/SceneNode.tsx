"use client";

// FoxAssets — nó CENA do canvas de roteiro. Cartão editável (título/prompt/narração/estilo/
// aspecto/duração) que gera mídia ali mesmo: 🖼️ imagem (mmx-bridge), 🎙️ narração (/studio/tts),
// 🎬 clipe (Engine.video, usando a imagem como base i2v se houver). Os callbacks vêm por contexto
// (RoteiroCtx) pra não guardar função no data do nó (que é serializado no localStorage).
// Inputs levam `nodrag` pra digitar sem arrastar o nó.
import { memo, createContext, useContext, useLayoutEffect, useRef } from "react";
import { Handle, Position, type NodeProps } from "@xyflow/react";
import { Image as ImageIcon, Mic, Clapperboard, Trash2, Loader2, Download, Maximize2, ImageOff, VideoOff, VolumeX, Copy, MapPinPlus } from "lucide-react";
import type { SceneData } from "@/lib/roteiro";
import { ASPECTS, DURATIONS, avisoFala } from "@/lib/roteiro";
import { styleOptions } from "@/lib/imageStyles";
import { CAM_MOVE_KEYS, MOVE_OPTIONS } from "@/lib/shots";
import { cartaoBadge, cartaoBox, cartaoBtn, cartaoField, cartaoIconBtn, CARTAO_MIDIA_H } from "@/components/roteiro/cartaoCena";

/** Movimentos oferecidos pela CÂMERA PROGRAMADA: o cruzamento de MOVE_OPTIONS (rótulos em PT) com
 *  CAM_MOVE_KEYS (o que o /camclip reproduz de verdade via zoompan). Cruzar em vez de digitar uma
 *  lista nova é de propósito — nome inventado vira 422 no servidor, ou pior, key órfã ignorada em
 *  silêncio. Os que faltam (orbit, guindaste, câmera na mão, travelling) pedem paralaxe/3D e só
 *  saem no i2v pago. */
const CAM_MOVES: [string, string][] = MOVE_OPTIONS
  .filter(([k]) => CAM_MOVE_KEYS.includes(k))
  .map(([k, label]) => [k, label]);

/** Textarea que se ajusta ao conteúdo. O cartão nascia com `rows={2}` fixo e um prompt de cena —
 *  que costuma ter 3 a 6 linhas — ficava escondido atrás de uma barra de rolagem de 2 linhas:
 *  pra ler o próprio roteiro era preciso arrastar o canto de cada caixa, cena por cena. Cresce
 *  até um teto e só então rola, senão uma narração longa empurraria os botões pra fora da tela.
 *  useLayoutEffect (não useEffect): mede e ajusta ANTES da pintura, sem piscar na altura errada. */
function AutoTextarea({ value, onChange, placeholder, style, maxHeight = 220 }: {
  value: string;
  onChange: (v: string) => void;
  placeholder?: string;
  style?: React.CSSProperties;
  maxHeight?: number;
}) {
  const ref = useRef<HTMLTextAreaElement>(null);
  useLayoutEffect(() => {
    const el = ref.current;
    if (!el) return;
    el.style.height = "auto";                       // zera antes de medir, senão só cresce
    const h = Math.min(el.scrollHeight, maxHeight);
    el.style.height = `${h}px`;
    el.style.overflowY = el.scrollHeight > maxHeight ? "auto" : "hidden";
  }, [value, maxHeight]);

  return (
    <textarea
      ref={ref}
      className="nodrag"
      rows={1}
      value={value}
      placeholder={placeholder}
      onChange={(e) => onChange(e.target.value)}
      style={{ ...style, resize: "vertical", overflowY: "hidden" }}
    />
  );
}

/** Personagem da biblioteca, como o cartão precisa dele: id (vira `charIds` → IDENTITY LOCK no
 *  servidor) e as âncoras visuais (vira `refUrls`): a base (retrato) E o model sheet (turnaround)
 *  — só a base não segura espécie/sexo/anatomia em ângulo que ela não mostra. */
export type ElencoItem = { id: number; name: string; base_url?: string | null; sheet_url?: string | null };

/** Cenário da biblioteca (F5 — "Elementos"), como o cartão precisa dele: id (vira `scenarioId`)
 *  e a imagem-âncora (vira `refUrls`). Espelha `ElencoItem` do lado dos LUGARES. */
export type CenarioItem = { id: number; name: string; image_url?: string | null };

export type RoteiroActions = {
  update: (id: string, patch: Partial<SceneData>) => void;
  genImage: (id: string) => void;
  genNarracao: (id: string) => void;
  genClip: (id: string) => void;
  remover: (id: string) => void;
  // Duplicar cena: pedido junto do resto ("mais opções no card pra melhorar o fluxo") — variar
  // um plano parecido (outro ângulo do mesmo instante) sem reescrever prompt/narração/âncoras
  // do zero a cada vez.
  duplicar?: (id: string) => void;
  // Biblioteca de personagens + como pôr/tirar um da cena. Até 2026-07-25 só a Escaleta sabia
  // dizer "quem está nesta cena": uma cena criada no canvas (ou vinda de "Criar filme") não
  // tinha como ancorar em ninguém, e a geração saía com um personagem qualquer — foi o que
  // aconteceu ao pedir a Mel e receber uma mão numa parede de tijolos.
  elenco?: ElencoItem[];
  addChar?: (id: string, charId: number) => void;
  delChar?: (id: string, charId: number) => void;
  // Mesma mecânica do elenco, do lado dos LUGARES: biblioteca de cenários (F5) + anexar/tirar um
  // da cena. Sem isso o Roteiro só sabia gerar um fundo do zero a cada cena — nunca reaproveitava
  // um lugar já usado, e o cenário gerado ali nunca virava registro na aba Cenários.
  cenarios?: CenarioItem[];
  addScenario?: (id: string, scenarioId: number) => void;
  delScenario?: (id: string) => void;
  // Salva o quadro atual da cena (d.imageUrl) como um novo Cenário reutilizável na biblioteca.
  salvarCenario?: (id: string) => void;
  // Abre a mídia em tela cheia. O cartão tem 300px: no canvas dá pra ver que existe imagem, não
  // pra julgar se ela presta — e julgar cedo é o que evita renderizar um lote inteiro errado.
  ampliar?: (url: string, kind: "image" | "video") => void;
};
export const RoteiroCtx = createContext<RoteiroActions | null>(null);

// Cores = tokens do tema FoxAssets (app/globals.css): --bg/--panel/--line/--red/--peach/--green…
// A CASCA do cartão (moldura, campo, botão, selo, altura da mídia) mora em ./cartaoCena e é
// compartilhada com o storyboard da aba /video — as duas telas são a mesma ferramenta e têm de
// parecer a mesma. Aqui ficam só os ajustes deste nó do canvas.
const box: React.CSSProperties = {
  ...cartaoBox,
  // 300 → 380 (2026-07-25): o prompt de uma cena tem 4 a 6 linhas e, com a caixa crescendo pra
  // mostrar o texto todo, o cartão ficava alto e estreito — uma coluna de texto espremida. Mais
  // largo, cabe mais palavra por linha e o cartão encurta.
  width: 380,
};
const field = cartaoField;
const iconBtn = cartaoIconBtn;
const btn = cartaoBtn;
const badge = cartaoBadge;

const STATUS_LABEL: Record<string, string> = {
  idle: "rascunho", img: "gerando imagem…", tts: "narrando…", clip: "gerando clipe…",
  done: "pronto", error: "erro",
};

function SceneNodeBase({ id, data, selected }: NodeProps) {
  const a = useContext(RoteiroCtx);
  const d = data as SceneData;
  const busy = d.status === "img" || d.status === "tts" || d.status === "clip";
  const set = (patch: Partial<SceneData>) => a?.update(id, patch);

  return (
    <div style={{ ...box, borderColor: selected ? "var(--red)" : "var(--line)" }}>
      <Handle type="target" position={Position.Left} style={{ background: "var(--muted)", width: 9, height: 9 }} />
      <Handle type="source" position={Position.Right} style={{ background: "var(--red)", width: 9, height: 9 }} />

      {/* header */}
      <div style={{ display: "flex", alignItems: "center", gap: 6, padding: "8px 10px", borderBottom: "1px solid var(--line)" }}>
        <input
          className="nodrag" value={d.titulo} placeholder="Título da cena"
          onChange={(e) => set({ titulo: e.target.value })}
          style={{ ...field, border: "none", background: "transparent", fontWeight: 600, padding: 0 }}
        />
        <span style={badge(d.status)}>{STATUS_LABEL[d.status ?? "idle"] ?? d.status}</span>
        {a?.duplicar && (
          <button className="nodrag" title="Duplicar cena (copia prompt, narração, âncoras e cenário)" onClick={() => a.duplicar?.(id)}
            style={{ background: "none", border: "none", color: "var(--muted)", cursor: "pointer", padding: 2 }}>
            <Copy size={14} />
          </button>
        )}
        <button className="nodrag" title="Remover cena" onClick={() => a?.remover(id)}
          style={{ background: "none", border: "none", color: "var(--muted)", cursor: "pointer", padding: 2 }}>
          <Trash2 size={14} />
        </button>
      </div>

      {/* QUEM ESTÁ NA CENA + âncoras. Ficam à vista porque são elas que explicam por que o
          personagem e o lugar continuam os mesmos de um plano pro outro — e agora dá pra
          escolher aqui, não só herdando da Escaleta. */}
      <div className="nodrag" style={{ display: "flex", alignItems: "center", gap: 5, padding: "5px 10px", borderBottom: "1px solid var(--line)", flexWrap: "wrap" }}>
        {(d.refUrls ?? []).slice(0, 3).map((u, i) => (
          // eslint-disable-next-line @next/next/no-img-element
          <img key={u} src={u} alt={d.refNomes?.[i] ?? "âncora"} title={d.refNomes?.[i] ?? "âncora"}
            style={{ width: 22, height: 22, objectFit: "cover", borderRadius: 5, border: "1px solid var(--line2)" }} />
        ))}
        {(d.charIds ?? []).map((cid) => {
          const p = a?.elenco?.find((e) => e.id === cid);
          // A biblioteca pode não ter chegado ainda (a lista vem do servidor). Sem nome, "…" diz
          // "carregando"; "#14" parecia dado corrompido — foi o que o Luciano viu como quebrado.
          const rotulo = p?.name ?? (a?.elenco?.length ? `#${cid}` : "…");
          return (
            <span key={cid} title={p?.base_url ? "âncora de identidade ativa" : "sem imagem-base — só o lock textual"}
              style={{ display: "inline-flex", alignItems: "center", gap: 3, fontSize: 10, padding: "1px 6px", borderRadius: 999, background: "var(--panel2)", color: p?.base_url ? "var(--green, #3fb950)" : "var(--muted)" }}>
              {rotulo}
              <button onClick={() => a?.delChar?.(id, cid)} title="Tirar da cena"
                style={{ background: "none", border: "none", color: "inherit", cursor: "pointer", padding: 0, fontSize: 11, lineHeight: 1 }}>✕</button>
            </span>
          );
        })}
        {!!a?.elenco?.length && (
          <select
            value=""
            onChange={(e) => { const v = Number(e.target.value); if (v) a?.addChar?.(id, v); }}
            title="Põe um personagem da biblioteca nesta cena: a imagem-base vira âncora e o lock entra no prompt"
            style={{ background: "var(--bg)", border: "1px solid var(--line2)", borderRadius: 8, color: "var(--muted)", fontSize: 10, padding: "2px 4px", fontFamily: "inherit", maxWidth: 120 }}
          >
            <option value="">+ personagem</option>
            {a.elenco.filter((e) => !(d.charIds ?? []).includes(e.id)).map((e) => (
              <option key={e.id} value={e.id}>{e.name}</option>
            ))}
          </select>
        )}
        {/* CENÁRIO anexado (F5 — biblioteca de lugares). Mesma mecânica do personagem: escolhido
            aqui, a imagem dele entra como âncora e reaparece igual em qualquer outra cena que
            anexe o mesmo cenário — é o que faz o "quarto da Mel" ser o mesmo quarto na cena 3 e
            na cena 8, em vez de um fundo novo a cada geração. */}
        {(() => {
          const sc = a?.cenarios?.find((c) => c.id === d.scenarioId);
          if (!sc) return null;
          return (
            <span title={sc.image_url ? "cenário anexado — imagem em uso como âncora" : "cenário anexado — sem imagem, só o nome"}
              style={{ display: "inline-flex", alignItems: "center", gap: 3, fontSize: 10, padding: "1px 6px", borderRadius: 999, background: "var(--panel2)", color: sc.image_url ? "var(--green, #3fb950)" : "var(--muted)" }}>
              📍 {sc.name}
              <button onClick={() => a?.delScenario?.(id)} title="Tirar o cenário da cena"
                style={{ background: "none", border: "none", color: "inherit", cursor: "pointer", padding: 0, fontSize: 11, lineHeight: 1 }}>✕</button>
            </span>
          );
        })()}
        {!d.scenarioId && !!a?.cenarios?.length && (
          <select
            value=""
            onChange={(e) => { const v = Number(e.target.value); if (v) a?.addScenario?.(id, v); }}
            title="Anexa um cenário da biblioteca a esta cena: a imagem dele vira âncora de ambiente"
            style={{ background: "var(--bg)", border: "1px solid var(--line2)", borderRadius: 8, color: "var(--muted)", fontSize: 10, padding: "2px 4px", fontFamily: "inherit", maxWidth: 120 }}
          >
            <option value="">+ cenário</option>
            {a.cenarios.map((c) => (
              <option key={c.id} value={c.id}>{c.name}{c.image_url ? "" : " (sem imagem)"}</option>
            ))}
          </select>
        )}
        {!(d.refUrls?.length || d.charIds?.length) && (
          <span style={{ color: "var(--muted)", fontSize: 10 }}>sem personagem — a IA inventa um</span>
        )}
      </div>

      {/* thumbnail / preview. O quadro é 300px de largura no canvas — pequeno demais pra julgar
          se a imagem presta; daí o AMPLIAR. E cada peça (quadro/clipe/narração) tem o próprio
          descarte: refazer só a imagem não pode obrigar a apagar a cena e reescrever tudo. */}
      <div style={{ position: "relative", height: CARTAO_MIDIA_H, background: "var(--bg)", display: "flex", alignItems: "center", justifyContent: "center" }}>
        {d.imageUrl ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img src={d.imageUrl} alt="" onClick={() => a?.ampliar?.(d.imageUrl!, "image")}
            title="Clique pra ampliar" style={{ width: "100%", height: "100%", objectFit: "cover", cursor: "zoom-in" }} />
        ) : (
          <span style={{ color: "var(--muted)", fontSize: 11 }}>sem imagem</span>
        )}
        {busy && (
          <div style={{ position: "absolute", inset: 0, display: "grid", placeItems: "center", background: "rgba(0,0,0,.45)" }}>
            <Loader2 size={22} className="spin" style={{ color: "var(--peach)" }} />
          </div>
        )}
        <div className="nodrag" style={{ position: "absolute", right: 6, top: 6, display: "flex", gap: 4 }}>
          {d.imageUrl && (
            <>
              <button onClick={() => a?.ampliar?.(d.imageUrl!, "image")} title="Ampliar quadro" style={iconBtn}>
                <Maximize2 size={13} />
              </button>
              {/* Salva o QUADRO GERADO como Cenário reutilizável na biblioteca (F5) — sem isso, um
                  fundo bom saído aqui morria na cena e nunca dava pra reaproveitar em outro plano. */}
              {a?.salvarCenario && !d.scenarioId && (
                <button onClick={() => a.salvarCenario?.(id)} title="Salvar este quadro como Cenário reutilizável na biblioteca" style={iconBtn}>
                  <MapPinPlus size={13} />
                </button>
              )}
              <button onClick={() => set({ imageUrl: undefined })} title="Excluir o quadro (a cena e o texto ficam)" style={iconBtn}>
                <ImageOff size={13} />
              </button>
            </>
          )}
          {d.clipUrl && (
            <>
              <button onClick={() => a?.ampliar?.(d.clipUrl!, "video")} title="Ver o clipe grande" style={iconBtn}>
                <Maximize2 size={13} />
              </button>
              <button onClick={() => set({ clipUrl: undefined })} title="Excluir o clipe (a cena fica)" style={iconBtn}>
                <VideoOff size={13} />
              </button>
            </>
          )}
        </div>
        {d.clipUrl && (
          <a className="nodrag" href={d.clipUrl} target="_blank" rel="noreferrer" download
            title="Baixar clipe" style={{ position: "absolute", right: 6, bottom: 6, background: "rgba(0,0,0,.6)", borderRadius: 8, padding: 5, color: "#fff" }}>
            <Download size={14} />
          </a>
        )}
      </div>

      {/* campos */}
      <div style={{ display: "grid", gap: 6, padding: 10 }}>
        <AutoTextarea value={d.prompt} placeholder="Visual da cena (prompt)…"
          onChange={(v) => set({ prompt: v })} style={field} />
        <AutoTextarea value={d.narracao} placeholder="Narração (texto falado)…"
          onChange={(v) => set({ narracao: v })} style={field} />
        {/* A locução é ancorada NESTA cena na montagem: se a fala não cabe no clipe, o trecho é
            esticado (slow sutil e, no excedente, último frame segurado — isso se vê). Avisar aqui,
            enquanto o texto está sendo escrito, evita descobrir só no filme montado. */}
        {avisoFala(d.narracao, d.duration) && (
          <div style={{ fontSize: 10, color: "#d19a3a", lineHeight: 1.35 }}>
            ⏱ {avisoFala(d.narracao, d.duration)}
          </div>
        )}

        <div style={{ display: "flex", gap: 6 }}>
          {/* Estilo DESTA cena. O normal é vir do "Estilo do filme" (barra), aplicado em massa —
              aqui é o ajuste fino de um plano específico, que a barra não desfaz sozinha. */}
          <select className="nodrag" value={d.estilo} title="Técnica visual desta cena (o padrão vem do Estilo do filme, na barra)"
            onChange={(e) => set({ estilo: e.target.value })} style={{ ...field, flex: 2 }}>
            {styleOptions(d.estilo).map(([v, label]) => <option key={v} value={v}>{label}</option>)}
          </select>
          <select className="nodrag" value={d.aspect} onChange={(e) => set({ aspect: e.target.value })} style={{ ...field, flex: 1 }}>
            {ASPECTS.map((s) => <option key={s} value={s}>{s}</option>)}
          </select>
          <select className="nodrag" value={d.duration} onChange={(e) => set({ duration: e.target.value })} style={{ ...field, flex: 1 }}>
            {DURATIONS.map((s) => <option key={s} value={s}>{s}s</option>)}
          </select>
        </div>

        {/* 🎞️ MOVIMENTO DA CENA — de onde vem a animação deste plano. É a escolha que decide se a
            cena custa crédito: no web-doc a maior parte dos planos é imagem parada com push-in, e
            a câmera programada (zoompan do ffmpeg sobre o quadro já aprovado) entrega isso de
            graça, na hora e sem drift de identidade. O clipe de IA segue como default — quem tem
            movimento REAL (alguém andando, água correndo) continua precisando dele. */}
        <div style={{ display: "flex", gap: 6 }}>
          <select className="nodrag" value={d.motion ?? "ia"} title="De onde vem o movimento desta cena"
            onChange={(e) => {
              const m = e.target.value as "ia" | "camera";
              // Ao entrar na câmera programada, já nasce com um movimento válido: select vazio
              // viraria 422 no /camclip ("movimento não suportado") no primeiro clique.
              set({ motion: m, ...(m === "camera" && !d.camMove ? { camMove: "push_in" } : {}) });
            }}
            style={{ ...field, flex: 2 }}>
            <option value="ia">🎥 Clipe de IA (crédito)</option>
            <option value="camera">🎞️ Câmera programada (sem crédito)</option>
          </select>
          {d.motion === "camera" && (
            <select className="nodrag" value={d.camMove ?? "push_in"} title="Movimento da câmera sobre o quadro parado"
              onChange={(e) => set({ camMove: e.target.value })} style={{ ...field, flex: 2 }}>
              {CAM_MOVES.map(([k, label]) => <option key={k} value={k}>{label}</option>)}
            </select>
          )}
        </div>
        {d.motion === "camera" && !d.imageUrl && (
          <div style={{ fontSize: 10, color: "#d19a3a", lineHeight: 1.35 }}>
            🖼️ A câmera programada anima o QUADRO desta cena — gere a imagem antes.
          </div>
        )}

        {d.audioUrl && (
          <div className="nodrag" style={{ display: "flex", alignItems: "center", gap: 4 }}>
            <audio controls src={d.audioUrl} style={{ flex: 1, height: 30 }} />
            <button onClick={() => set({ audioUrl: undefined })} title="Excluir a narração (a cena e o texto ficam)" style={iconBtn}>
              <VolumeX size={13} />
            </button>
          </div>
        )}

        {/* Corrigir a cena = editar o texto acima (prompt/narração) e clicar de novo — o botão
            já REGENERA e SUBSTITUI a peça (imagem/narração/clipe já prontos incluídos), então o
            rótulo muda pra "Editar" quando já existe algo pra corrigir; sem isso o botão parecia
            "gerar de novo do zero" em vez de "corrigir o que saiu errado". */}
        <div style={{ display: "flex", gap: 6 }}>
          <button className="nodrag" style={btn} disabled={busy || !d.prompt.trim()} onClick={() => a?.genImage(id)}
            title={d.imageUrl ? "Regera o quadro com o prompt atual — substitui a imagem" : "Gera o quadro da cena"}>
            <ImageIcon size={13} /> {d.imageUrl ? "Editar imagem" : "Imagem"}
          </button>
          <button className="nodrag" style={btn} disabled={busy || !d.narracao.trim()} onClick={() => a?.genNarracao(id)}
            title={d.audioUrl ? "Regera a narração com o texto atual — substitui o áudio" : "Narra o texto da cena"}>
            <Mic size={13} /> {d.audioUrl ? "Editar narração" : "Narrar"}
          </button>
          {/* Na câmera programada o que manda é o QUADRO (não o prompt): sem imagem não há o que
              animar, então o botão fica travado até ela existir. */}
          <button className="nodrag" style={btn}
            disabled={busy || (d.motion === "camera" ? !d.imageUrl : !d.prompt.trim())}
            onClick={() => a?.genClip(id)}
            title={d.motion === "camera"
              ? "Aplica a câmera programada sobre o quadro desta cena — sem gastar crédito"
              : d.clipUrl ? "Regera o clipe com o prompt/quadro atuais — substitui o vídeo" : "Anima a cena"}>
            <Clapperboard size={13} /> {d.motion === "camera" ? (d.clipUrl ? "Refazer câmera" : "Câmera") : d.clipUrl ? "Editar clipe" : "Clipe"}
          </button>
        </div>
        {d.error && <div style={{ color: "var(--peach)", fontSize: 11 }}>{d.error}</div>}
      </div>
    </div>
  );
}

export default memo(SceneNodeBase);
