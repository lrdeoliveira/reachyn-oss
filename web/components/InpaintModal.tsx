"use client";

import { sfetch } from "@/lib/api";
import { useRef, useState } from "react";
import { X } from "lucide-react";

/**
 * 🩹 CONSERTO POR MÁSCARA (Fase 2.2 do docs/ESTUDIO-3D.md): pinta-se a área a mudar e o motor
 * local redesenha SÓ ali — o resto da imagem nem entra no ruído (SetLatentNoiseMask no engine).
 *
 * O canvas de pintura é TRANSPARENTE por cima da imagem — nunca lemos os pixels dela, então
 * não há canvas "tainted" nem dor de CORS com o storage. Na hora de aplicar, a máscara nasce
 * num canvas offscreen PRETO no tamanho NATURAL da imagem, com os traços brancos escalados:
 * é o formato que o engine espera (pintado = muda, preto = fica; canal red).
 */
export function InpaintModal({ url, draftId, onClose, onQueued }: {
  url: string;
  draftId: number;
  onClose: () => void;
  /** Chamado quando o conserto entra na fila — o dono decide como avisar/recarregar. */
  onQueued: () => void;
}) {
  const imgRef = useRef<HTMLImageElement | null>(null);
  const canvasRef = useRef<HTMLCanvasElement | null>(null);
  const desenhando = useRef(false);
  const ultimo = useRef<{ x: number; y: number } | null>(null);
  const [pincel, setPincel] = useState(36);
  const [pintou, setPintou] = useState(false);
  const [prompt, setPrompt] = useState("");
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<string | null>(null);

  // O canvas cobre a imagem EXIBIDA 1:1 (mesmo tamanho em px de tela) — o mouse desenha onde
  // o olho vê. A conversão pro tamanho natural acontece só no export.
  function ajustarCanvas() {
    const img = imgRef.current, cv = canvasRef.current;
    if (!img || !cv) return;
    if (cv.width !== img.clientWidth || cv.height !== img.clientHeight) {
      cv.width = img.clientWidth;
      cv.height = img.clientHeight;
    }
  }

  function ponto(e: React.PointerEvent): { x: number; y: number } {
    const r = canvasRef.current!.getBoundingClientRect();
    return { x: e.clientX - r.left, y: e.clientY - r.top };
  }

  function traco(a: { x: number; y: number }, b: { x: number; y: number }) {
    const ctx = canvasRef.current?.getContext("2d");
    if (!ctx) return;
    ctx.strokeStyle = "rgba(255,80,80,.6)"; // vermelho translúcido: dá pra ver o que há embaixo
    ctx.lineWidth = pincel;
    ctx.lineCap = "round";
    ctx.lineJoin = "round";
    ctx.beginPath();
    ctx.moveTo(a.x, a.y);
    ctx.lineTo(b.x, b.y);
    ctx.stroke();
    setPintou(true);
  }

  function down(e: React.PointerEvent) {
    e.preventDefault();
    ajustarCanvas();
    desenhando.current = true;
    const p = ponto(e);
    ultimo.current = p;
    traco(p, { x: p.x + 0.01, y: p.y + 0.01 }); // clique parado também pinta
  }
  function move(e: React.PointerEvent) {
    if (!desenhando.current || !ultimo.current) return;
    const p = ponto(e);
    traco(ultimo.current, p);
    ultimo.current = p;
  }
  function up() { desenhando.current = false; ultimo.current = null; }

  function limpar() {
    const cv = canvasRef.current;
    cv?.getContext("2d")?.clearRect(0, 0, cv.width, cv.height);
    setPintou(false);
  }

  async function aplicar() {
    const img = imgRef.current, cv = canvasRef.current;
    if (!img || !cv || busy) return;
    if (!pintou) { setMsg("Pinte a área que você quer mudar."); return; }
    if (!prompt.trim()) { setMsg("Descreva o que deve aparecer na área pintada."); return; }
    setBusy(true); setMsg(null);
    try {
      // Máscara no tamanho NATURAL: fundo preto + traços (qualquer pixel com alpha vira branco).
      const out = document.createElement("canvas");
      out.width = img.naturalWidth || cv.width;
      out.height = img.naturalHeight || cv.height;
      const ctx = out.getContext("2d")!;
      ctx.fillStyle = "#000";
      ctx.fillRect(0, 0, out.width, out.height);
      ctx.globalCompositeOperation = "source-over";
      // Os traços são translúcidos na tela; na máscara viram branco CHAPADO: desenha o canvas
      // escalado e depois força qualquer alpha > 0 pra branco puro.
      ctx.drawImage(cv, 0, 0, out.width, out.height);
      const px = ctx.getImageData(0, 0, out.width, out.height);
      for (let i = 0; i < px.data.length; i += 4) {
        const pintado = px.data[i] > 10 || px.data[i + 1] > 10 || px.data[i + 2] > 10;
        px.data[i] = px.data[i + 1] = px.data[i + 2] = pintado ? 255 : 0;
        px.data[i + 3] = 255;
      }
      ctx.putImageData(px, 0, 0);
      const blob = await new Promise<Blob | null>((res) => out.toBlob(res, "image/png"));
      if (!blob) { setMsg("Não deu pra montar a máscara."); setBusy(false); return; }

      const fd = new FormData();
      fd.append("draftId", String(draftId));
      fd.append("url", url);
      fd.append("prompt", prompt.trim());
      fd.append("mask", new File([blob], "mask.png", { type: "image/png" }));
      const r = await sfetch("/api/studio/inpaint", { method: "POST", body: fd });
      const d = await r.json().catch(() => ({}));
      if (!r.ok || !d?.ok) { setMsg(d?.error || "Não deu pra enviar o conserto."); setBusy(false); return; }
      onQueued();
      onClose();
    } catch {
      setMsg("Não deu pra enviar o conserto agora.");
      setBusy(false);
    }
  }

  return (
    <div style={{ position: "fixed", inset: 0, zIndex: 1100, background: "rgba(0,0,0,.92)", display: "flex", flexDirection: "column", alignItems: "center", justifyContent: "center", gap: 12, padding: 20 }}>
      <div style={{ display: "flex", alignItems: "center", gap: 10, flexWrap: "wrap", color: "#fff", maxWidth: "min(92vw, 960px)", width: "100%" }}>
        <strong style={{ fontSize: ".9rem" }}>🩹 Corrigir um detalhe</strong>
        <span style={{ fontSize: ".78rem", opacity: .75 }}>pinte a área a mudar e descreva o que entra no lugar — só ela é redesenhada</span>
        <button type="button" onClick={onClose} title="Fechar" style={{ marginLeft: "auto", background: "none", border: "none", color: "#fff", cursor: "pointer", padding: 6, lineHeight: 0 }}>
          <X size={20} />
        </button>
      </div>

      <div style={{ position: "relative", maxWidth: "min(92vw, 960px)", maxHeight: "58vh" }}>
        {/* eslint-disable-next-line @next/next/no-img-element */}
        <img ref={imgRef} src={url} alt="imagem a corrigir" onLoad={ajustarCanvas}
          style={{ display: "block", maxWidth: "100%", maxHeight: "58vh", borderRadius: 8 }} />
        <canvas ref={canvasRef} onPointerDown={down} onPointerMove={move} onPointerUp={up} onPointerLeave={up}
          style={{ position: "absolute", inset: 0, width: "100%", height: "100%", cursor: "crosshair", touchAction: "none", borderRadius: 8 }} />
      </div>

      <div style={{ display: "flex", alignItems: "center", gap: 10, flexWrap: "wrap", maxWidth: "min(92vw, 960px)", width: "100%" }}>
        <label style={{ display: "inline-flex", alignItems: "center", gap: 6, color: "#fff", fontSize: ".78rem" }}>
          pincel
          <input type="range" min={8} max={120} value={pincel} onChange={(e) => setPincel(Number(e.target.value))} />
        </label>
        <button type="button" className="btn edit" onClick={limpar} style={{ flex: "none", padding: "7px 12px", fontSize: ".8rem" }}>Limpar</button>
        <input value={prompt} onChange={(e) => setPrompt(e.target.value)}
          placeholder="o que entra na área pintada — ex: casaco amarelo de chuva"
          style={{ flex: "1 1 260px", background: "var(--bg2)", color: "var(--text)", border: "1px solid var(--line2)", borderRadius: 8, padding: "8px 10px", fontSize: ".82rem", fontFamily: "inherit" }} />
        <button type="button" className="btn ok" onClick={() => void aplicar()} disabled={busy}
          style={{ flex: "none", padding: "8px 14px", fontSize: ".82rem" }}>
          {busy ? "Enviando…" : "Aplicar conserto"}
        </button>
      </div>
      {msg && <div style={{ color: "#ffb4b4", fontSize: ".8rem", maxWidth: "min(92vw, 960px)", width: "100%" }}>{msg}</div>}
    </div>
  );
}
