"use client";

// PLAYER DE STORYBOARD (animatic) — toca os QUADROS em sequência, no tempo real de cada cena, com
// a narração por cima. É o ensaio do filme antes de existir filme.
//
// POR QUE EXISTE: até aqui só dava pra sentir ritmo DEPOIS de renderizar os clipes — ou seja,
// depois de pagar. Descobrir que o segundo ato arrasta custava o lote inteiro de vídeo. Os quadros,
// as narrações e as durações já estão no roteiro; tocá-los em ordem custa zero e mostra o mesmo:
// onde a história para em pé e onde ela se arrasta.
//
// O TEMPO DE CADA CENA: quando existe narração gerada, é o ÁUDIO que manda (a fala não pode ser
// cortada no meio); sem áudio, vale a duração escolhida pro clipe. É a mesma regra da montagem.

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { Play, Pause, SkipBack, SkipForward, X } from "lucide-react";
import type { SceneData } from "@/lib/roteiro";

type Cena = { id: string; data: SceneData };

const PADRAO_SEG = 5;      // cena sem duração declarada
const seg = (d?: string) => Math.max(1, Number(d) || PADRAO_SEG);
const mmss = (s: number) => `${Math.floor(s / 60)}:${String(Math.max(0, Math.round(s % 60))).padStart(2, "0")}`;

export function StoryboardPlayer({ cenas, onClose }: { cenas: Cena[]; onClose: () => void }) {
  const [i, setI] = useState(0);
  const [tocando, setTocando] = useState(true);
  const [passado, setPassado] = useState(0);          // segundos já corridos DENTRO da cena
  // Duração REAL da narração, medida quando o áudio carrega. Antes disso vale a duração declarada
  // — é estimativa, e uma estimativa visível é melhor que um total que muda sozinho sem explicação.
  const [durAudio, setDurAudio] = useState<Record<string, number>>({});
  const audioRef = useRef<HTMLAudioElement | null>(null);

  const cena = cenas[i];
  const duracao = useCallback(
    (c: Cena) => (c.data.audioUrl && durAudio[c.id]) || seg(c.data.duration),
    [durAudio],
  );
  const total = useMemo(() => cenas.reduce((s, c) => s + duracao(c), 0), [cenas, duracao]);
  const decorrido = useMemo(() => cenas.slice(0, i).reduce((s, c) => s + duracao(c), 0) + passado, [cenas, i, passado, duracao]);

  const irPara = useCallback((idx: number) => {
    setI(Math.max(0, Math.min(cenas.length - 1, idx)));
    setPassado(0);
  }, [cenas.length]);

  // Relógio da cena: avança sozinho e vira a página quando o tempo acaba. Quando há narração, quem
  // decide o fim é o `ended` do áudio (abaixo) — aqui o limite é só a rede de segurança.
  useEffect(() => {
    if (!tocando || !cena) return;
    const t = setInterval(() => {
      setPassado((p) => {
        const lim = duracao(cena);
        if (p + 0.1 < lim) return p + 0.1;
        if (i < cenas.length - 1) { setI(i + 1); return 0; }
        setTocando(false);   // fim do filme: para no último quadro, não volta ao início
        return lim;
      });
    }, 100);

    return () => clearInterval(t);
  }, [tocando, cena, i, cenas.length, duracao]);

  // A narração acompanha o quadro. Trocar de cena recomeça o áudio do zero; pausar pausa os dois.
  useEffect(() => {
    const a = audioRef.current;
    if (!a) return;
    if (!cena?.data.audioUrl) { a.pause(); return; }
    if (a.src !== cena.data.audioUrl) a.src = cena.data.audioUrl;
    a.currentTime = Math.min(passado, a.duration || passado);
    if (tocando) void a.play().catch(() => {});
    else a.pause();
    // `passado` de propósito FORA das deps: ele muda 10× por segundo e re-sincronizar o áudio a
    // cada tique deixaria a fala picotada.
  }, [i, tocando, cena?.data.audioUrl]); // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.key === "Escape") onClose();
      if (e.key === " ") { e.preventDefault(); setTocando((t) => !t); }
      if (e.key === "ArrowRight") irPara(i + 1);
      if (e.key === "ArrowLeft") irPara(i - 1);
    };
    window.addEventListener("keydown", onKey);

    return () => window.removeEventListener("keydown", onKey);
  }, [onClose, irPara, i]);

  if (!cena) return null;
  const semQuadro = !cena.data.imageUrl;

  return (
    <div style={{ position: "fixed", inset: 0, zIndex: 1200, background: "rgba(0,0,0,.95)", display: "flex", flexDirection: "column" }}>
      {/* cabeçalho */}
      <div style={{ display: "flex", alignItems: "center", gap: 10, padding: "10px 16px", flexWrap: "wrap" }}>
        <strong style={{ color: "#fff", fontSize: ".92rem" }}>▶️ Storyboard</strong>
        <span style={{ color: "rgba(255,255,255,.6)", fontSize: ".8rem" }}>
          cena {i + 1} de {cenas.length} · {mmss(decorrido)} / {mmss(total)}
          {cenas.some((c) => c.data.audioUrl && !durAudio[c.id]) ? " (estimado)" : ""}
        </span>
        <button type="button" onClick={onClose} title="Fechar (Esc)"
          style={{ marginLeft: "auto", background: "none", border: "none", color: "#fff", cursor: "pointer", padding: 4, lineHeight: 0 }}>
          <X size={20} />
        </button>
      </div>

      {/* QUADRO. Centralização com FLEX, não com grid: `max-height: 100%` numa imagem dentro de
          `display:grid + place-items:center` se mede contra a linha do grid — que por sua vez cresce
          até a altura natural da imagem. O percentual vira circular, a foto vaza o container (155px
          medidos) e entra por baixo da legenda. Com flex, o 100% se mede contra a altura definida. */}
      <div style={{ flex: 1, minHeight: 0, display: "flex", alignItems: "center", justifyContent: "center", padding: "0 16px", overflow: "hidden" }}>
        {semQuadro ? (
          // Cena sem imagem não interrompe o ensaio: o ritmo é o que se está julgando, e o buraco
          // no meio da sequência é justamente a informação de que falta gerar aquele quadro.
          <div style={{ textAlign: "center", color: "rgba(255,255,255,.5)", border: "1px dashed rgba(255,255,255,.25)", borderRadius: 12, padding: "48px 32px", maxWidth: 620 }}>
            <div style={{ fontSize: "2rem", marginBottom: 8 }}>🎞️</div>
            <div style={{ fontSize: ".9rem", color: "#fff", marginBottom: 6 }}>{cena.data.titulo || `Cena ${i + 1}`}</div>
            <div style={{ fontSize: ".82rem" }}>sem quadro gerado — o tempo dela continua contando</div>
          </div>
        ) : (
          // eslint-disable-next-line @next/next/no-img-element
          <img src={cena.data.imageUrl} alt={cena.data.titulo || `Cena ${i + 1}`}
            style={{ maxWidth: "100%", maxHeight: "100%", objectFit: "contain", borderRadius: 10 }} />
        )}
      </div>

      {/* narração da cena: é o que se OUVE — ler junto ajuda a julgar o ritmo mesmo sem TTS gerado */}
      {cena.data.narracao && (
        <div style={{ padding: "12px 24px 0", textAlign: "center", color: "rgba(255,255,255,.85)", fontSize: ".92rem", lineHeight: 1.5, maxWidth: 900, margin: "0 auto" }}>
          {cena.data.narracao}
        </div>
      )}

      {/* linha do tempo: cada cena é um bloco proporcional à própria duração — o desenho já mostra
          qual delas está comendo o filme. Clicar salta pra cena. */}
      <div style={{ display: "flex", gap: 3, padding: "14px 16px 0" }}>
        {cenas.map((c, idx) => {
          const prog = idx < i ? 1 : idx > i ? 0 : Math.min(1, passado / duracao(c));
          return (
            <button key={c.id} type="button" onClick={() => irPara(idx)} title={`${c.data.titulo || `Cena ${idx + 1}`} · ${Math.round(duracao(c))}s`}
              style={{ flex: duracao(c), height: 6, padding: 0, border: "none", borderRadius: 3, cursor: "pointer", background: "rgba(255,255,255,.22)", position: "relative", overflow: "hidden" }}>
              <span style={{ position: "absolute", inset: 0, width: `${prog * 100}%`, background: "var(--red, #e0575b)" }} />
            </button>
          );
        })}
      </div>

      {/* controles */}
      <div style={{ display: "flex", alignItems: "center", justifyContent: "center", gap: 14, padding: "12px 16px 18px" }}>
        <button type="button" onClick={() => irPara(i - 1)} title="Cena anterior (←)" style={ctrl}><SkipBack size={18} /></button>
        <button type="button" onClick={() => setTocando((t) => !t)} title={tocando ? "Pausar (espaço)" : "Tocar (espaço)"}
          style={{ ...ctrl, background: "var(--red, #e0575b)", borderColor: "transparent", width: 46, height: 46 }}>
          {tocando ? <Pause size={20} /> : <Play size={20} />}
        </button>
        <button type="button" onClick={() => irPara(i + 1)} title="Próxima cena (→)" style={ctrl}><SkipForward size={18} /></button>
      </div>

      <audio ref={audioRef}
        onLoadedMetadata={(e) => {
          const d = e.currentTarget.duration;
          if (Number.isFinite(d) && d > 0) setDurAudio((p) => ({ ...p, [cena.id]: d }));
        }}
        onEnded={() => { if (i < cenas.length - 1) irPara(i + 1); else setTocando(false); }}
      />
    </div>
  );
}

const ctrl: React.CSSProperties = {
  display: "grid", placeItems: "center", width: 38, height: 38, borderRadius: "50%",
  background: "rgba(255,255,255,.1)", border: "1px solid rgba(255,255,255,.2)", color: "#fff", cursor: "pointer",
};
