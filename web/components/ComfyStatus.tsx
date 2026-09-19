"use client";

// 🟢 Luz de status do ESTÚDIO LOCAL (ComfyUI no host) — Fase 1 do docs/ESTUDIO-3D.md.
// Acende verde quando o ComfyUI responde e mostra qual checkpoint responde pelos modelos
// img-local-*. Sem isto, o usuário só descobria que o estúdio caiu quando a geração
// estourava timeout — a bolinha conta ANTES de gastar o clique.

import { useEffect, useState } from "react";
import { sfetch } from "@/lib/api";

export type ComfyInfo = {
  ok: boolean;           // o servidor RESPONDEU (só isso — não quer dizer que está pronto)
  checkpoint: string;    // COMFY_CKPT ativo (o arquivo em models/checkpoints)
  checkpoints?: string[]; // instalados — trocar = editar COMFY_CKPT no .env + restart do engine
  // O modelo que vamos pedir existe lá? Servidor de pé com a pasta de modelos vazia é o estado
  // normal de um ComfyUI recém-aberto no Colab (os modelos vêm do Drive e ainda não montaram).
  // Antes disto a bolinha acendia verde nesse caso e a geração só falhava depois.
  checkpoint_instalado?: boolean;
  remoto?: boolean;      // ComfyUI fora desta máquina (Colab por túnel, servidor de GPU)
};

/** Pronto de verdade = servidor de pé E com o checkpoint que os workflows pedem.
 *  Se a lista de instalados não pôde ser lida, não acusa falta (o campo vem indefinido). */
export function comfyPronto(i: ComfyInfo): boolean {
  return i.ok && i.checkpoint_instalado !== false;
}

/** Busca o estado uma vez na montagem (health é barato mas não precisa de polling). */
export function useComfy(): ComfyInfo | null {
  const [info, setInfo] = useState<ComfyInfo | null>(null);
  useEffect(() => {
    sfetch("/api/comfy/health")
      .then((r) => r.json())
      .then((d) => setInfo(d && typeof d.ok === "boolean" ? (d as ComfyInfo) : { ok: false, checkpoint: "" }))
      .catch(() => setInfo({ ok: false, checkpoint: "" }));
  }, []);
  return info;
}

/** Nome amigável do checkpoint: sem extensão, sem underscores. */
export function nomeCkpt(arquivo: string): string {
  return arquivo.replace(/\.(safetensors|ckpt)$/i, "").replace(/[_-]+/g, " ").trim();
}

/** Card da Central de Geração (Chaves API → Geração): o Estúdio Local por inteiro — estado,
 * checkpoint ativo e instalados. Trocar de checkpoint = editar COMFY_CKPT no .env da raiz e
 * reiniciar o engine (decisão da Fase 1: automatizar só se doer). */
export function EstudioLocalCard() {
  const info = useComfy();
  if (!info) return null;
  return (
    <div style={{ border: "1px solid var(--line2)", borderRadius: 12, background: "var(--bg2)", padding: "14px 16px", marginTop: 12, display: "flex", flexDirection: "column", gap: 8 }}>
      <strong style={{ fontSize: ".95rem", display: "flex", alignItems: "center", gap: 8 }}>
        {/* Verde = pronto · âmbar = de pé mas sem o modelo · apagado = não respondeu. */}
        <span style={{ width: 9, height: 9, borderRadius: 999,
          background: comfyPronto(info) ? "#3fbf5a" : info.ok ? "#e0a300" : "var(--line2)",
          boxShadow: comfyPronto(info) ? "0 0 6px rgba(63,191,90,.7)" : info.ok ? "0 0 6px rgba(224,163,0,.7)" : "none" }} />
        ComfyUI {info.remoto ? "(remoto)" : "(neste Mac)"}
      </strong>
      {info.ok && !comfyPronto(info) && (
        // O caso do Colab novo: servidor de pé, models/ vazia. Dizer isto aqui evita o clique
        // que gera erro lá na frente.
        <span style={{ fontSize: ".84rem", color: "#e0a300" }}>
          O servidor respondeu, mas <b>{nomeCkpt(info.checkpoint)}</b> não está instalado nele
          {(info.checkpoints?.length ?? 0) === 0
            ? " — a pasta de modelos está vazia."
            : ` — lá existe: ${info.checkpoints!.map(nomeCkpt).join(" · ")}.`}
          {" "}Instale o modelo (no Colab, monte o Drive e rode{" "}
          <code>scripts/comfy/colab_setup_foxassets.py</code>) ou aponte <code>COMFY_CKPT</code>{" "}
          para um dos que existem.
        </span>
      )}
      {comfyPronto(info) ? (
        <>
          <span style={{ fontSize: ".84rem" }}>
            Motor de imagem pronto — os modelos “🎛️ ComfyUI” do seletor geram com{" "}
            <b>{nomeCkpt(info.checkpoint)}</b>, sem crédito
            {info.remoto ? ", numa GPU fora deste Mac." : " e sem internet."}
          </span>
          {(info.checkpoints?.length ?? 0) > 1 && (
            <span style={{ fontSize: ".8rem", color: "var(--muted)" }}>
              Instalados: {info.checkpoints!.map(nomeCkpt).join(" · ")} — pra trocar, edite{" "}
              <code>COMFY_CKPT</code> no <code>.env</code> e reinicie o engine.
            </span>
          )}
        </>
      ) : info.ok ? null : (
        <span style={{ fontSize: ".84rem", color: "var(--muted)" }}>
          {info.remoto
            ? <>Não respondeu. O ComfyUI remoto caiu ou o túnel mudou de endereço — atualize{" "}
               <code>COMFY_URL</code> no <code>.env</code> e recrie o engine.</>
            : <>Desligado. Rode <code>comfyui start</code> no terminal — o serviço sobe sozinho no
               próximo login.</>}
          {" "}Checkpoint configurado: <b>{nomeCkpt(info.checkpoint)}</b>.
        </span>
      )}
    </div>
  );
}

/** Chip compacto: bolinha + "🎛️ ComfyUI (onde) · <checkpoint>" (ou dica de como ligar).
 *
 *  O rótulo perdeu o "Local" de propósito (2026-07-30): o ComfyUI quase sempre está num Colab, e
 *  chamar aquilo de "local" fazia o operador procurar na máquina errada quando caía. O chip diz
 *  ONDE ele está — "neste Mac" ou "remoto" — que é o que muda onde ir consertar.
 *
 *  E passou a se chamar ComfyUI pelo nome (2026-08-01): o seletor de modelo agora mostra a ORIGEM
 *  de cada motor (☁️ nuvem · ✨ assinatura · 🎛️ ComfyUI · 💻 Mac), e a luz tem de usar a MESMA
 *  palavra do seletor — "Estúdio" aqui e "ComfyUI" lá são a mesma coisa, e duas palavras pra
 *  mesma coisa é o que fazia procurar no lugar errado. */
export function ComfyStatus({ info }: { info: ComfyInfo | null }) {
  if (!info) return null; // ainda carregando — não pisca "desligado" à toa
  const onde = info.remoto ? "remoto" : "neste Mac";
  return (
    <span
      title={comfyPronto(info)
        ? `ComfyUI ${onde} pronto — gera com ${info.checkpoint}, sem gastar crédito`
        : info.ok
          ? `ComfyUI ${onde} de pé, mas sem o modelo ${info.checkpoint} instalado — a geração vai falhar`
          : info.remoto
            ? "ComfyUI remoto não respondeu — servidor fora do ar ou túnel expirado (confira o COMFY_URL)"
            : "ComfyUI desligado — rode `comfyui start` no terminal pra ligar"}
      style={{ display: "inline-flex", alignItems: "center", gap: 6, fontSize: ".78rem", color: "var(--muted)" }}
    >
      <span style={{ width: 8, height: 8, borderRadius: 999,
        background: comfyPronto(info) ? "#3fbf5a" : info.ok ? "#e0a300" : "var(--line2)",
        boxShadow: comfyPronto(info) ? "0 0 6px rgba(63,191,90,.7)" : info.ok ? "0 0 6px rgba(224,163,0,.7)" : "none" }} />
      🎛️ ComfyUI ({onde}){comfyPronto(info) ? ` · ${nomeCkpt(info.checkpoint)}` : info.ok ? " · sem modelo" : " · fora do ar"}
    </span>
  );
}
