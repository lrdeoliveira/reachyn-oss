"use client";

// 🎴 CARTÃO DE CENA — o storyboard da aba /video com a MESMA cara do cartão da Montagem
// (aba /roteiro, components/roteiro/SceneNode.tsx): quadro/clipe em cima, texto no meio, botões
// no rodapé. Pedido do Luciano (2026-08-05) por consistência entre as duas telas.
//
// REUSO x RÉPLICA — a decisão: o SceneNode NÃO foi importado. Ele é um nó do React Flow (recebe
// `NodeProps`, desenha `Handle`s de ligação, lê ações do `RoteiroCtx` e edita um `SceneData` do
// canvas) — usá-lo aqui obrigaria a montar meio canvas em volta de um storyboard que é uma lista.
// O que é de fato compartilhado (a casca visual: moldura, campo, botão, selo, altura da mídia)
// saiu do SceneNode pra `components/roteiro/cartaoCena.ts` e é IMPORTADO pelos dois — nada de CSS
// duplicado. A estrutura (cabeçalho → mídia → texto → rodapé) é replicada de propósito, porque os
// campos são outros: aqui manda a FRASE FALADA, com o contador de caracteres da régua de locução.

import { Clapperboard, Download, Loader2 } from "lucide-react";
import { cartaoBadge, cartaoBox, cartaoBtn, cartaoField, CARTAO_MIDIA_H } from "@/components/roteiro/cartaoCena";

/** Mesmo shape do beat da VideoStudio (a fonte é ela — aqui só se lê e edita). */
export type VoxBeat = {
  caption: string; script: string; image_prompt: string; image_prompt_b?: string;
  sfx?: string; clip_url?: string;
};

const lblMini: React.CSSProperties = { fontSize: 10, color: "var(--muted)" };

export function SceneCard({
  beat, index, intervalo, maxChars, tamanho, gerando, travado, vox, onEditar, onGerar,
}: {
  beat: VoxBeat;
  index: number;
  /** "00:00–00:06" — posição desta cena no filme. */
  intervalo: string;
  /** Teto de caracteres da fala (régua de locução) e o tamanho atual do script. */
  maxChars: number;
  tamanho: number;
  gerando: boolean;
  travado: boolean;
  /** Estilo Vox: a cena tem DUAS artes (cada metade da frase ganha a sua). Nos outros estilos a
   *  cena é UM plano só — mostrar dois campos ali prometeria uma densidade que não existe. */
  vox: boolean;
  onEditar: (campo: keyof VoxBeat, valor: string) => void;
  onGerar: () => void;
}) {
  const estourou = tamanho > maxChars;
  const status = gerando ? "clip" : beat.clip_url ? "done" : "idle";
  const statusLabel = gerando ? "gerando cena…" : beat.clip_url ? "pronta" : "rascunho";

  return (
    <div style={{ ...cartaoBox, display: "flex", flexDirection: "column" }}>
      {/* cabeçalho — nº da cena, intervalo no filme e o estado da geração */}
      <div style={{ display: "flex", alignItems: "center", gap: 6, padding: "8px 10px", borderBottom: "1px solid var(--line)" }}>
        <strong style={{ fontSize: 12 }}>Cena {index + 1}</strong>
        <span style={lblMini} title="Posição desta cena no filme">{intervalo}</span>
        <span style={{ marginLeft: "auto", ...cartaoBadge(status) }}>{statusLabel}</span>
      </div>

      {/* mídia — o clipe desta cena quando existe. Mesma altura do cartão da Montagem. */}
      <div style={{ position: "relative", height: CARTAO_MIDIA_H, background: "var(--bg)", display: "flex", alignItems: "center", justifyContent: "center" }}>
        {beat.clip_url && !gerando ? (
          <video src={beat.clip_url} controls preload="metadata"
            style={{ width: "100%", height: "100%", objectFit: "contain", background: "var(--bg)" }} />
        ) : (
          <span style={{ color: "var(--muted)", fontSize: 11 }}>{gerando ? "" : "sem cena gerada"}</span>
        )}
        {gerando && (
          <div style={{ position: "absolute", inset: 0, display: "grid", placeItems: "center", background: "rgba(0,0,0,.45)" }}>
            <Loader2 size={22} className="spin" style={{ color: "var(--peach)" }} />
          </div>
        )}
      </div>

      {/* texto da cena — título curto + a FRASE FALADA (com o contador da régua) + ilustrações */}
      <div style={{ display: "grid", gap: 6, padding: 10 }}>
        <input value={beat.caption} onChange={(e) => onEditar("caption", e.target.value)}
          placeholder="Título curto do capítulo (5-10 palavras)"
          style={{ ...cartaoField, color: "var(--muted)" }} />
        <textarea value={beat.script} onChange={(e) => onEditar("script", e.target.value)} rows={3}
          placeholder="A frase falada desta cena"
          // 🔴 Estourou o teto: a borda avisa no campo que está sendo editado, não só no contador.
          style={{ ...cartaoField, ...(estourou ? { borderColor: "var(--red)" } : {}) }} />
        <span
          style={{
            alignSelf: "flex-end", fontSize: 10,
            color: estourou ? "var(--red)" : "var(--muted)",
            fontWeight: estourou ? 600 : 400,
          }}
          title={`A frase é FALADA na cena. Acima de ${maxChars} caracteres não cabe e o áudio é cortado no meio.`}>
          {tamanho}/{maxChars}{estourou ? " · não cabe na cena" : ""}
        </span>
        <details>
          <summary style={{ ...lblMini, cursor: "pointer" }}>{vox ? "ilustrações deste capítulo (2)" : "imagem desta cena"}</summary>
          <textarea value={beat.image_prompt} onChange={(e) => onEditar("image_prompt", e.target.value)} rows={2}
            placeholder={vox ? "1ª metade da frase" : "O que aparece na tela nesta cena"}
            style={{ ...cartaoField, marginTop: 4, color: "var(--muted)" }} />
          {vox && (
            <textarea value={beat.image_prompt_b ?? ""} onChange={(e) => onEditar("image_prompt_b", e.target.value)} rows={2}
              placeholder="2ª metade da frase — outra coisa, não a mesma cena variada"
              style={{ ...cartaoField, marginTop: 4, color: "var(--muted)" }} />
          )}
        </details>
      </div>

      {/* RODAPÉ — as ações da cena, sempre na base do cartão (como na Montagem). `marginTop:auto`
          mantém a fileira alinhada entre cartões de alturas diferentes na grade. */}
      <div style={{ display: "flex", gap: 6, padding: 10, paddingTop: 0, marginTop: "auto" }}>
        <button type="button" style={{ ...cartaoBtn, opacity: travado || gerando ? 0.5 : 1 }}
          disabled={travado || gerando} onClick={onGerar}
          title={beat.clip_url
            ? "Refaz SÓ esta cena (1 imagem + 1 clipe) — as outras ficam como estão."
            : "Gera só esta cena (1 imagem + 1 clipe) pra conferir antes de montar o filme."}>
          <Clapperboard size={13} /> {gerando ? "Gerando…" : beat.clip_url ? "Regerar cena" : "Gerar cena"}
        </button>
        {/* EXPORTAR = baixar o clipe desta cena (pra edição externa). Sem cena pronta não há o que
            exportar: o botão fica visível e travado, em vez de sumir e mudar o rodapé de lugar. */}
        {beat.clip_url ? (
          <a href={beat.clip_url} target="_blank" rel="noreferrer" download
            style={{ ...cartaoBtn, textDecoration: "none" }} title="Baixar o clipe desta cena">
            <Download size={13} /> Exportar
          </a>
        ) : (
          <span style={{ ...cartaoBtn, opacity: 0.4, cursor: "default" }} title="Gere a cena antes de exportar">
            <Download size={13} /> Exportar
          </span>
        )}
      </div>
    </div>
  );
}
