"use client";

import { useEffect, useState } from "react";
import { sfetch } from "@/lib/api";

// Lê `provedores` (lista completa), NÃO o `saldos` legado: aquele só carrega itens do tipo
// "creditos", então a voz — que é COTA DE CARACTERES — ficava de fora da sidebar.
type Item = {
  id: string; label: string; ok: boolean;
  tipo: "creditos" | "caracteres" | null;
  saldo: number | null; limite?: number | null; unidade?: string | null;
};
type Resposta = { ok: boolean; provedores?: Item[] };

/**
 * Saldo REAL das contas nos provedores, na sidebar — só pro OPERADOR. nuvem e assinatura contam
 * em CRÉDITO; a voz conta em CARACTERES (cota que renova). Unidades diferentes no mesmo
 * contador se resolvem formatando cada uma no seu formato — esconder a que "não cabia" fazia a
 * conta de voz sumir da vista justamente quando acabar é o que derruba a narração.
 *
 * NÃO confundir com <CreditBalance/>, que é a cota do PLANO do cliente. Aqui é o saldo que,
 * quando zera, derruba a geração paga daquele provedor: em 2026-07-20 o da nuvem acabou e o
 * sintoma chegou como "geração falhando", não como "acabou o crédito". Este contador encurta
 * esse diagnóstico — e vale igual pro assinatura (2026-08-01).
 *
 * O endpoint devolve 403 pra quem não é operador; nesse caso o componente simplesmente não
 * renderiza — cliente nunca vê o nome do provedor (white-label, guideline #6).
 *
 * Recarrega ao focar a aba e a cada 5min (o backend cacheia pelo mesmo tempo, então bater
 * mais que isso só gastaria requisição sem trazer número novo).
 */
export function ProviderCredit() {
  const [saldos, setSaldos] = useState<Item[]>([]);

  useEffect(() => {
    let vivo = true;
    const load = () =>
      sfetch("/api/provider-credit")
        .then((r) => (r.ok ? r.json() : null))
        // Só os que responderam COM número: provedor sem consulta de saldo (a IA/Google)
        // não vira linha vazia na barra.
        .then((j: Resposta | null) => {
          if (vivo && j?.provedores) setSaldos(j.provedores.filter((p) => p.ok && p.saldo !== null));
        })
        .catch(() => {});
    load();
    const iv = setInterval(load, 300_000);
    window.addEventListener("focus", load);
    return () => {
      vivo = false;
      clearInterval(iv);
      window.removeEventListener("focus", load);
    };
  }, []);

  if (!saldos.length) return null;

  return (
    <div style={{ display: "flex", flexDirection: "column", gap: 6 }}>
      {saldos.map((s) => {
        // O limiar depende da UNIDADE: crédito abaixo de 1000 some numa sessão de geração;
        // cota de caracteres é relativa, então o que importa é a fração restante.
        const frac = s.limite ? s.saldo! / s.limite : null;
        const baixo = frac !== null ? frac < 0.15 : s.saldo! < 1000;
        // Caractere é número grande e o que importa é a ordem de grandeza, não a unidade.
        const texto = s.tipo === "caracteres" && s.saldo! >= 1000
          ? `${Math.round(s.saldo! / 1000)}k`
          : s.saldo!.toLocaleString("pt-BR", { maximumFractionDigits: 0 });
        return (
          <div
            key={s.id}
            title={s.tipo === "caracteres"
              ? `${s.label}: ${s.saldo!.toLocaleString("pt-BR")} de ${(s.limite ?? 0).toLocaleString("pt-BR")} caracteres restantes na cota. Atualiza a cada 5 min.`
              : `Saldo da conta ${s.label} — crédito no provedor, não cota do plano do cliente. Atualiza a cada 5 min.`}
            style={{
              display: "flex", alignItems: "center", justifyContent: "space-between", gap: 8,
              padding: "8px 11px", borderRadius: 9, fontSize: ".76rem",
              border: `1px solid ${baixo ? "rgba(239,68,68,.45)" : "var(--line)"}`,
              background: baixo ? "rgba(239,68,68,.08)" : "transparent",
              color: baixo ? "#fca5a5" : "var(--muted)",
            }}
          >
            <span style={{ whiteSpace: "nowrap" }}>{baixo ? "⚠️" : s.tipo === "caracteres" ? "🎙️" : "🔌"} {s.label}</span>
            <strong style={{ whiteSpace: "nowrap" }}>{texto}</strong>
          </div>
        );
      })}
    </div>
  );
}
