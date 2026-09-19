"use client";

import { useEffect, useState } from "react";
import { sfetch } from "@/lib/api";

type Credits = { balance: number; monthly: number; exempt: boolean; costs: Record<string, number> };

/**
 * Saldo de créditos sempre visível na sidebar: mostra o saldo atual, quanto cada operação gasta,
 * e BAIXA em tempo real conforme o usuário gera (a api dispara o evento 'reachyn:credits' após
 * cada geração; também refaz fetch ao voltar o foco pra aba). Some pro plano interno (exempt).
 */
export function CreditBalance() {
  const [c, setC] = useState<Credits | null>(null);

  function load() {
    sfetch("/api/usage").then((r) => r.json()).then((j) => { if (j.ok && j.credits) setC(j.credits); }).catch(() => {});
  }
  useEffect(() => {
    load();
    const onChange = () => load();
    window.addEventListener("reachyn:credits", onChange);
    window.addEventListener("focus", onChange);
    return () => {
      window.removeEventListener("reachyn:credits", onChange);
      window.removeEventListener("focus", onChange);
    };
  }, []);

  if (!c) return null;

  return (
    <a
      href="/plano"
      style={{
        display: "block", textDecoration: "none", margin: "0 12px 12px", padding: "10px 12px",
        border: "1px solid var(--line)", borderRadius: 12, background: "var(--bg2)",
      }}
      title="Ver plano, extrato e comprar créditos"
    >
      <div style={{ display: "flex", alignItems: "baseline", gap: 8 }}>
        <span style={{ fontSize: "1.05rem" }}>💎</span>
        {c.exempt ? (
          <span style={{ fontWeight: 700, color: "var(--green)" }}>Ilimitado</span>
        ) : (
          <>
            <span style={{ fontSize: "1.15rem", fontWeight: 800, color: "var(--green)" }}>{c.balance.toLocaleString("pt-BR")}</span>
            <span style={{ fontSize: ".72rem", color: "var(--muted)" }}>créditos</span>
          </>
        )}
      </div>
      {!c.exempt && (
        <div style={{ marginTop: 6, fontSize: ".66rem", lineHeight: 1.5, color: "var(--muted)" }}>
          Custo: 🖼️ {c.costs.image} · 🎬 {c.costs.video} · 🎞️ {c.costs.short} · ⭐ {c.costs.veo}{c.costs.audio != null ? ` · 🎵 ${c.costs.audio}` : ""}
          <span style={{ display: "block", color: "var(--green)", opacity: .85 }}>+{c.monthly.toLocaleString("pt-BR")}/mês do plano</span>
        </div>
      )}
    </a>
  );
}
