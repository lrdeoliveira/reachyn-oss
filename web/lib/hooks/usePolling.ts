"use client";

// usePolling — UM hook de polling pro app inteiro (S1). Substitui os ~15 loops copiados de
// setInterval/setTimeout espalhados por Studio/Story/Animacao/Filme, cada um com sua própria
// lógica de cancelamento. Regras: roda `fn` a cada `interval` ms enquanto `enabled`; para
// sozinho quando `fn` retorna true (concluiu) ou quando o componente desmonta; `timeoutMs`
// opcional chama `onTimeout` e para (o chamador decide o que mostrar).

import { useEffect, useRef } from "react";

type Options = {
  interval: number;
  enabled: boolean;
  /** para de tentar depois disso (ms); 0/undefined = sem limite */
  timeoutMs?: number;
  onTimeout?: () => void;
};

export function usePolling(fn: () => Promise<boolean | void>, { interval, enabled, timeoutMs, onTimeout }: Options): void {
  const fnRef = useRef(fn);
  fnRef.current = fn;
  const onTimeoutRef = useRef(onTimeout);
  onTimeoutRef.current = onTimeout;

  useEffect(() => {
    if (!enabled) return;
    let alive = true;
    let timer: ReturnType<typeof setTimeout> | null = null;
    const started = Date.now();

    const tick = async () => {
      if (!alive) return;
      let done: boolean | void = false;
      try { done = await fnRef.current(); } catch { /* tenta de novo no próximo tick */ }
      if (!alive || done === true) return;
      if (timeoutMs && Date.now() - started >= timeoutMs) { onTimeoutRef.current?.(); return; }
      timer = setTimeout(tick, interval);
    };
    timer = setTimeout(tick, interval);

    return () => { alive = false; if (timer) clearTimeout(timer); };
  }, [enabled, interval, timeoutMs]);
}
