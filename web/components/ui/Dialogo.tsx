"use client";

import { useEffect, useRef, useState } from "react";

/**
 * Diálogo do app — confirmar uma ação ou pedir um texto, no visual do site.
 *
 * Substitui `window.confirm`/`window.prompt`, que destoavam de tudo (fonte do sistema, botões do
 * navegador, sem as cores da marca) e, pior, tratam qualquer decisão como igual: o mesmo "OK"
 * cinza para "remover elemento" e para "refazer tudo, consumindo créditos".
 *
 * `perigo` pinta a ação destrutiva de vermelho — para o clique de gastar/apagar não ter o mesmo
 * peso visual do clique de seguir adiante.
 *
 * Mesma casca do GalleryPicker (overlay + var(--panel) + var(--line)) para o app ter UM modal, e
 * não um por tela.
 */
export type DialogoPedido = {
  titulo: string;
  /** Texto explicativo. Quebra de linha vira parágrafo. */
  mensagem?: string;
  confirmar?: string;
  cancelar?: string;
  perigo?: boolean;
  /** Quando presente, o diálogo pede TEXTO e devolve o que foi digitado. */
  campos?: { label: string; placeholder?: string; multilinha?: boolean; obrigatorio?: boolean }[];
};

export function Dialogo({ pedido, onFechar }: { pedido: DialogoPedido; onFechar: (r: string[] | null) => void }) {
  const [vals, setVals] = useState<string[]>(() => (pedido.campos ?? []).map(() => ""));
  const primeiro = useRef<HTMLInputElement | HTMLTextAreaElement | null>(null);

  // Foco no primeiro campo (ou no confirmar) e ESC fecha — o que o diálogo nativo dava de graça
  // e um modal caseiro costuma esquecer.
  useEffect(() => {
    primeiro.current?.focus();
    const onKey = (e: KeyboardEvent) => { if (e.key === "Escape") onFechar(null); };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [onFechar]);

  const faltaObrigatorio = (pedido.campos ?? []).some((c, i) => c.obrigatorio !== false && !vals[i]?.trim());
  const confirmar = () => { if (!faltaObrigatorio) onFechar(pedido.campos ? vals.map((v) => v.trim()) : []); };

  const inp: React.CSSProperties = {
    background: "var(--bg2)", color: "var(--text)", border: "1px solid var(--line)",
    borderRadius: 8, padding: "9px 11px", fontSize: ".88rem", width: "100%",
  };

  return (
    <div onClick={() => onFechar(null)} style={{ position: "fixed", inset: 0, zIndex: 70, background: "rgba(0,0,0,.7)", display: "flex", alignItems: "center", justifyContent: "center", padding: 20 }}>
      <div onClick={(e) => e.stopPropagation()} role="dialog" aria-modal="true" aria-label={pedido.titulo}
        style={{ background: "var(--panel)", border: "1px solid var(--line)", borderRadius: 14, padding: 20, width: "min(520px,95vw)", maxHeight: "85vh", overflow: "auto" }}>
        <strong style={{ fontSize: "1rem", color: "var(--peach)", display: "block", marginBottom: 10 }}>{pedido.titulo}</strong>

        {pedido.mensagem && pedido.mensagem.split("\n").filter(Boolean).map((p, i) => (
          <p key={i} className="txt" style={{ color: "var(--muted)", fontSize: ".86rem", lineHeight: 1.5, margin: "0 0 10px" }}>{p}</p>
        ))}

        {(pedido.campos ?? []).map((c, i) => (
          <div key={c.label} style={{ display: "flex", flexDirection: "column", gap: 5, marginBottom: 12 }}>
            <label className="txt" style={{ color: "var(--muted)", fontSize: ".78rem" }}>{c.label}</label>
            {c.multilinha ? (
              <textarea ref={i === 0 ? (el) => { primeiro.current = el; } : undefined} rows={4} placeholder={c.placeholder}
                value={vals[i] ?? ""} onChange={(e) => setVals((v) => v.map((x, k) => (k === i ? e.target.value : x)))}
                style={{ ...inp, resize: "vertical", fontFamily: "inherit" }} />
            ) : (
              <input ref={i === 0 ? (el) => { primeiro.current = el; } : undefined} placeholder={c.placeholder}
                value={vals[i] ?? ""} onChange={(e) => setVals((v) => v.map((x, k) => (k === i ? e.target.value : x)))}
                onKeyDown={(e) => { if (e.key === "Enter" && !c.multilinha) confirmar(); }}
                style={inp} />
            )}
          </div>
        ))}

        <div style={{ display: "flex", gap: 8, justifyContent: "flex-end", marginTop: 6 }}>
          <button className="btn edit" style={{ flex: "none", padding: "8px 14px" }} onClick={() => onFechar(null)}>{pedido.cancelar ?? "Cancelar"}</button>
          <button className={pedido.perigo ? "btn no" : "btn ok"} style={{ flex: "none", padding: "8px 16px", opacity: faltaObrigatorio ? .5 : 1 }}
            disabled={faltaObrigatorio} onClick={confirmar}>{pedido.confirmar ?? "Confirmar"}</button>
        </div>
      </div>
    </div>
  );
}

/**
 * Hook que dá o `perguntar()` — devolve uma Promise, para o chamador continuar lendo como o
 * `confirm`/`prompt` nativo lia, sem espalhar máquina de estado por cada botão.
 *
 *   const { perguntar, dialogo } = useDialogo();
 *   if (await perguntar({ titulo: "Remover?", perigo: true })) { … }
 *   // e {dialogo} no JSX
 */
export function useDialogo() {
  const [pedido, setPedido] = useState<DialogoPedido | null>(null);
  const resolver = useRef<((r: string[] | null) => void) | null>(null);

  const perguntar = (p: DialogoPedido): Promise<string[] | null> => {
    setPedido(p);
    return new Promise((res) => { resolver.current = res; });
  };

  const dialogo = pedido
    ? <Dialogo pedido={pedido} onFechar={(r) => { setPedido(null); resolver.current?.(r); resolver.current = null; }} />
    : null;

  return { perguntar, dialogo };
}
