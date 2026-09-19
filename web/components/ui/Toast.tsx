"use client";

// Toasts do kit compartilhado (S1 do PLANO-UX-INTERFACE): feedback de sucesso/erro/info que
// NÃO depende da página — substitui os alert() nativos e o padrão "msg no rodapé" pra eventos
// globais (ex.: job concluído vindo do JobCenter). Fila com auto-dismiss; erros ficam mais tempo.
// Uso: const toast = useToast(); toast.ok("Vídeo pronto!", { label: "Ver", href: "/galeria" });

import { createContext, useCallback, useContext, useMemo, useRef, useState } from "react";

export type ToastKind = "ok" | "err" | "info";
export type ToastAction = { label: string; href?: string; onClick?: () => void };
export type Toast = { id: number; kind: ToastKind; text: string; action?: ToastAction };

type ToastApi = {
  push: (kind: ToastKind, text: string, action?: ToastAction) => void;
  ok: (text: string, action?: ToastAction) => void;
  err: (text: string, action?: ToastAction) => void;
  info: (text: string, action?: ToastAction) => void;
};

const ToastCtx = createContext<ToastApi | null>(null);

export function useToast(): ToastApi {
  const ctx = useContext(ToastCtx);
  if (!ctx) throw new Error("useToast fora do <ToastProvider>");
  return ctx;
}

export function ToastProvider({ children }: { children: React.ReactNode }) {
  const [toasts, setToasts] = useState<Toast[]>([]);
  const seq = useRef(0);

  const dismiss = useCallback((id: number) => {
    setToasts((t) => t.filter((x) => x.id !== id));
  }, []);

  const push = useCallback((kind: ToastKind, text: string, action?: ToastAction) => {
    const id = ++seq.current;
    setToasts((t) => [...t.slice(-3), { id, kind, text, action }]); // máx. 4 na tela
    // erro fica mais tempo na tela (10s); sucesso/info 6s
    window.setTimeout(() => dismiss(id), kind === "err" ? 10000 : 6000);
  }, [dismiss]);

  const api = useMemo<ToastApi>(() => ({
    push,
    ok: (text, action) => push("ok", text, action),
    err: (text, action) => push("err", text, action),
    info: (text, action) => push("info", text, action),
  }), [push]);

  return (
    <ToastCtx.Provider value={api}>
      {children}
      {/* aria-live: leitores de tela anunciam o feedback sem roubar o foco */}
      <div className="toasts" aria-live="polite" role="status">
        {toasts.map((t) => (
          <div key={t.id} className={`toast ${t.kind}`}>
            <span className="toast-txt">{t.text}</span>
            {t.action && (t.action.href
              ? <a className="toast-act" href={t.action.href}>{t.action.label}</a>
              : <button className="toast-act" onClick={() => { t.action?.onClick?.(); dismiss(t.id); }}>{t.action.label}</button>)}
            <button className="toast-x" aria-label="Fechar aviso" onClick={() => dismiss(t.id)}>×</button>
          </div>
        ))}
      </div>
    </ToastCtx.Provider>
  );
}
