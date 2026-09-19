"use client";

// ✨ REFINO LOCAL — i2i de baixa denoise no motor local: poli o "quase bom" SEM trocar a
// identidade, sem crédito de nuvem e sem limite. Irmão do inpaint (mesma fila serial do ComfyUI,
// mesmo caminho assíncrono; o resultado entra como mídia NOVA no mesmo rascunho).
//
// O endpoint POST /api/studio/refine-local existia desde a fusão FoxAssets (2026-08-01) com rota,
// gate de plano e controller prontos — e NENHUMA tela o chamava. Feature paga inalcançável, achada
// na auditoria de 2026-08-07.
//
// Diferença de UX pro inpaint: aqui NÃO há máscara. O refino passa na imagem inteira, então a
// única entrada é o prompt (opcional) que orienta o que melhorar.

import { useState } from "react";
import { sfetch } from "@/lib/api";
import { useComfy, comfyPronto } from "@/components/ComfyStatus";

export function RefinarModal({ url, draftId, onClose, onQueued }: {
  url: string;
  draftId: number;
  onClose: () => void;
  onQueued: () => void;
}) {
  const [prompt, setPrompt] = useState("");
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState("");
  // 🟢 O refino roda no ESTÚDIO LOCAL (ComfyUI). Se ele não responde, o botão avisa ANTES do
  // clique — mandar pra uma fila que não existe só produz erro dois minutos depois.
  const comfy = useComfy();
  const pronto = comfy ? comfyPronto(comfy) : false;

  async function enviar() {
    setBusy(true);
    setMsg("");
    try {
      const r = await sfetch("/api/studio/refine-local", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ draftId, url, prompt: prompt.trim() }),
      });
      const d = await r.json().catch(() => ({}));
      if (!r.ok || !d?.ok) {
        setMsg(d?.error || "Não deu pra enviar o refino.");
        setBusy(false);
        return;
      }
      onQueued();
      onClose();
    } catch {
      setMsg("Não deu pra enviar o refino agora.");
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
        style={{ background: "var(--bg2)", border: "1px solid var(--line)", borderRadius: 14, padding: 18, width: "min(560px, 100%)", display: "flex", flexDirection: "column", gap: 12, maxHeight: "90vh", overflow: "auto" }}
      >
        <strong style={{ fontSize: "1rem" }}>✨ Refinar imagem</strong>
        <p className="txt" style={{ color: "var(--muted)", fontSize: ".82rem", margin: 0 }}>
          Passa um acabamento na imagem inteira sem trocar a identidade do que está nela. Roda no
          seu estúdio local — não gasta crédito de nuvem.
        </p>

        <img src={url} alt="" style={{ width: "100%", maxHeight: 280, objectFit: "contain", borderRadius: 10, background: "var(--bg)" }} />

        <label className="txt" style={{ fontSize: ".82rem" }}>
          O que melhorar <span style={{ color: "var(--muted)" }}>(opcional)</span>
          <textarea
            value={prompt}
            onChange={(e) => setPrompt(e.target.value)}
            rows={3}
            placeholder="Ex.: mais nitidez na textura do papel, bordas mais limpas"
            style={{ width: "100%", marginTop: 6, background: "var(--bg)", color: "var(--text)", border: "1px solid var(--line)", borderRadius: 10, padding: 10, fontSize: ".88rem", resize: "vertical" }}
          />
        </label>

        {comfy && !pronto && (
          <div className="err" style={{ margin: 0 }}>
            O estúdio local não está respondendo agora — sem ele o refino não roda. Abra o ComfyUI e
            tente de novo.
          </div>
        )}
        {msg && <div className="err" style={{ margin: 0 }}>{msg}</div>}

        <div style={{ display: "flex", gap: 8, justifyContent: "flex-end" }}>
          <button type="button" className="btn" onClick={onClose} disabled={busy}>Cancelar</button>
          <button type="button" className="btn ok" onClick={enviar} disabled={busy || !comfy || !pronto}>
            {busy ? "Enviando…" : "Refinar"}
          </button>
        </div>
      </div>
    </div>
  );
}
