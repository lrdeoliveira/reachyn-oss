"use client";

// 🎞️→🧍 QUADRO DO VÍDEO VIRA BASE — congela um quadro de um vídeo do acervo e o usa como imagem
// base de um PERSONAGEM ou de um CENÁRIO (ficha nova ou uma que já existe).
//
// O endpoint POST /api/studio/frame-to-base veio da fusão FoxAssets (2026-08-01) com rota e
// controller prontos e NENHUMA tela chamando — feature inalcançável, achada na auditoria de
// 2026-08-07. Fica FORA do gate de plano de propósito: congelar quadro não consome crédito de IA.
//
// O quadro é escolhido por FRAÇÃO do vídeo (0 = começo, 0.999 = fim), que é o contrato do
// /frames-at do ffmpeg-service. O preview local usa o mesmo número no <video>, então o que se vê
// é o que vai ser congelado — sem isso o usuário escolheria às cegas.

import { useEffect, useRef, useState } from "react";
import { sfetch } from "@/lib/api";

type Ficha = { id: number; name: string };

export function QuadroParaBaseModal({ url, onClose, onDone }: {
  url: string;
  onClose: () => void;
  onDone: (msg: string) => void;
}) {
  const [alvo, setAlvo] = useState<"character" | "scenario">("character");
  const [fracao, setFracao] = useState(0);
  const [nome, setNome] = useState("");
  const [fichaId, setFichaId] = useState(""); // "" = criar ficha nova
  const [fichas, setFichas] = useState<Ficha[]>([]);
  const [dur, setDur] = useState(0);
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState("");
  const videoRef = useRef<HTMLVideoElement | null>(null);

  // Trocar de alvo ZERA a ficha escolhida na mesma ação (não num efeito, que dispararia render em
  // cascata): um id de personagem não significa nada na tabela de cenários, e mandá-lo adiante
  // gravaria o quadro na ficha errada.
  function trocarAlvo(k: "character" | "scenario") {
    setAlvo(k);
    setFichaId("");
  }

  // Fichas existentes do tipo escolhido.
  useEffect(() => {
    const rota = alvo === "character" ? "/api/characters" : "/api/scenarios";
    sfetch(rota)
      .then((r) => r.json())
      // As duas rotas devolvem ARRAY PURO (não {characters: [...]}) — mesmo formato que o Roteiro
      // já consome. Tratar como objeto com chave deixaria a lista sempre vazia, em silêncio.
      .then((j) => setFichas((Array.isArray(j) ? j : j?.data ?? []) as Ficha[]))
      .catch(() => setFichas([]));
  }, [alvo]);

  // Move o preview junto do slider: o quadro mostrado é o quadro que será congelado.
  function mover(f: number) {
    setFracao(f);
    const v = videoRef.current;
    if (v && dur > 0) v.currentTime = Math.min(f * dur, Math.max(dur - 0.05, 0));
  }

  async function enviar() {
    setBusy(true);
    setMsg("");
    try {
      const r = await sfetch("/api/studio/frame-to-base", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          url,
          at: fracao,
          target: alvo,
          id: fichaId ? Number(fichaId) : undefined,
          name: nome.trim() || undefined,
        }),
      });
      const d = await r.json().catch(() => ({}));
      if (!r.ok || !d?.ok) {
        setMsg(d?.error || "Não deu pra congelar o quadro.");
        setBusy(false);
        return;
      }
      const tipo = d.target === "character" ? "Personagem" : "Cenário";
      onDone(`${tipo} "${d.name}" recebeu o quadro como base.`);
      onClose();
    } catch {
      setMsg("Não deu pra congelar o quadro agora.");
      setBusy(false);
    }
  }

  return (
    <div
      onClick={onClose}
      style={{ position: "fixed", inset: 0, zIndex: 1100, background: "rgba(0,0,0,.86)", display: "flex", alignItems: "center", justifyContent: "center", padding: 16 }}
    >
      <div
        onClick={(e) => e.stopPropagation()}
        style={{ background: "var(--bg2)", border: "1px solid var(--line)", borderRadius: 14, padding: 18, width: "min(620px, 100%)", display: "flex", flexDirection: "column", gap: 12, maxHeight: "90vh", overflow: "auto" }}
      >
        <strong style={{ fontSize: "1rem" }}>🎞️ Usar um quadro como base</strong>
        <p className="txt" style={{ color: "var(--muted)", fontSize: ".82rem", margin: 0 }}>
          Congela um quadro deste vídeo e o guarda como imagem base de um personagem ou cenário.
          Não gasta crédito.
        </p>

        <video
          ref={videoRef}
          src={url}
          muted
          playsInline
          preload="metadata"
          onLoadedMetadata={(e) => setDur(e.currentTarget.duration || 0)}
          style={{ width: "100%", maxHeight: 300, borderRadius: 10, background: "var(--bg)" }}
        />

        <label className="txt" style={{ fontSize: ".82rem" }}>
          Posição no vídeo — {Math.round(fracao * 100)}%
          <input
            type="range" min={0} max={0.999} step={0.001} value={fracao}
            onChange={(e) => mover(Number(e.target.value))}
            style={{ width: "100%", marginTop: 6 }}
          />
        </label>

        <div style={{ display: "flex", gap: 8, flexWrap: "wrap" }}>
          {([["character", "🧍 Personagem"], ["scenario", "🏞️ Cenário"]] as const).map(([k, label]) => (
            <button key={k} type="button" onClick={() => trocarAlvo(k)} className={alvo === k ? "chip on" : "chip"}>
              {label}
            </button>
          ))}
        </div>

        <label className="txt" style={{ fontSize: ".82rem" }}>
          Ficha de destino
          <select
            value={fichaId}
            onChange={(e) => setFichaId(e.target.value)}
            style={{ width: "100%", marginTop: 6, background: "var(--bg)", color: "var(--text)", border: "1px solid var(--line)", borderRadius: 10, padding: 10, fontSize: ".88rem" }}
          >
            <option value="">➕ Criar uma ficha nova</option>
            {fichas.map((f) => <option key={f.id} value={f.id}>{f.name}</option>)}
          </select>
        </label>

        {/* O nome só existe pra ficha NOVA — numa existente ele seria ignorado pelo servidor, e
            mostrar campo que não faz nada ensina o usuário a desconfiar da tela. */}
        {!fichaId && (
          <label className="txt" style={{ fontSize: ".82rem" }}>
            Nome da ficha nova <span style={{ color: "var(--muted)" }}>(vazio = "Do vídeo")</span>
            <input
              value={nome}
              onChange={(e) => setNome(e.target.value)}
              maxLength={120}
              placeholder="Ex.: Raposa do curta"
              style={{ width: "100%", marginTop: 6, background: "var(--bg)", color: "var(--text)", border: "1px solid var(--line)", borderRadius: 10, padding: 10, fontSize: ".88rem" }}
            />
          </label>
        )}

        {fichaId && (
          <div className="txt" style={{ color: "var(--muted)", fontSize: ".78rem" }}>
            ⚠️ A imagem base atual dessa ficha será substituída por este quadro.
          </div>
        )}

        {msg && <div className="err" style={{ margin: 0 }}>{msg}</div>}

        <div style={{ display: "flex", gap: 8, justifyContent: "flex-end" }}>
          <button type="button" className="btn" onClick={onClose} disabled={busy}>Cancelar</button>
          <button type="button" className="btn ok" onClick={enviar} disabled={busy}>
            {busy ? "Congelando…" : "Usar este quadro"}
          </button>
        </div>
      </div>
    </div>
  );
}
