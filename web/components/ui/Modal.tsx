"use client";

// Modal acessível do kit compartilhado (S1): overlay + Esc fecha + foco preso dentro do diálogo
// + scroll da página travado. Substitui os overlays caseiros (EditModal, lightbox) que cada tela
// reinventava sem Esc/foco. Conteúdo estiliza por conta própria (card etc.); aqui é só o chassi.

import { useEffect, useRef } from "react";

type Props = {
  onClose: () => void;
  children: React.ReactNode;
  /** rótulo do diálogo pra leitores de tela */
  label?: string;
  /** largura máxima do conteúdo (default 560) */
  maxWidth?: number | string;
};

export function Modal({ onClose, children, label, maxWidth = 560 }: Props) {
  const boxRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const prevOverflow = document.body.style.overflow;
    document.body.style.overflow = "hidden"; // trava o scroll do fundo
    const prevFocus = document.activeElement as HTMLElement | null;

    const onKey = (e: KeyboardEvent) => {
      if (e.key === "Escape") { e.stopPropagation(); onClose(); }
      if (e.key === "Tab" && boxRef.current) {
        // foco preso: Tab circula só dentro do modal
        const focusables = boxRef.current.querySelectorAll<HTMLElement>(
          'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])',
        );
        if (focusables.length === 0) return;
        const first = focusables[0];
        const last = focusables[focusables.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
      }
    };
    document.addEventListener("keydown", onKey);
    // foco inicial no primeiro focável (ou no próprio box)
    const t = window.setTimeout(() => {
      const el = boxRef.current?.querySelector<HTMLElement>("button, [href], input, select, textarea");
      (el ?? boxRef.current)?.focus();
    }, 0);

    return () => {
      document.removeEventListener("keydown", onKey);
      document.body.style.overflow = prevOverflow;
      window.clearTimeout(t);
      prevFocus?.focus?.();
    };
  }, [onClose]);

  return (
    <div className="modal-overlay" onClick={onClose}>
      <div
        ref={boxRef}
        className="modal-box"
        role="dialog"
        aria-modal="true"
        aria-label={label}
        tabIndex={-1}
        style={{ maxWidth }}
        onClick={(e) => e.stopPropagation()}
      >
        {children}
      </div>
    </div>
  );
}
