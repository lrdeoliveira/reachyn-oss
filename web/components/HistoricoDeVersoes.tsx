"use client";

// HISTÓRICO de imagens de um asset (personagem, cenário, elemento) — a tomada anterior a um clique.
//
// POR QUE EXISTE: regerar SOBRESCREVIA. A URL nova entrava no lugar da antiga e a anterior sumia do
// produto (o arquivo continuava no storage, mas sem endereço à vista). Em 2026-07-27 isso apagou a
// primeira versão do cenário "Casa do filhote" — que era justamente a prova do defeito em
// investigação. Ideia lida no 3D Gen Studio, que versiona cada asset.

import { useCallback, useEffect, useState } from "react";
import { sfetch } from "@/lib/api";
import { useToast } from "@/components/ui/Toast";
import { History, RotateCcw, X } from "lucide-react";

type Versao = { id: number; url: string; campo: string; model?: string | null; created_at?: string };

export function HistoricoDeVersoes({ tipo, id, campo, onRestaurado }: {
  tipo: "character" | "scenario" | "element";
  id: number;
  /** Quando o asset tem mais de uma imagem (personagem: base + sheet), mostra só a deste campo. */
  campo?: string;
  onRestaurado: (asset: Record<string, unknown>) => void;
}) {
  const toast = useToast();
  const [versoes, setVersoes] = useState<Versao[] | null>(null);
  const [busy, setBusy] = useState(false);

  const carregar = useCallback(async () => {
    try {
      const r = await sfetch(`/api/asset-versions?tipo=${tipo}&id=${id}`);
      const j = await r.json().catch(() => []);
      setVersoes(Array.isArray(j) ? j.filter((v: Versao) => !campo || v.campo === campo) : []);
    } catch { setVersoes([]); }
  }, [tipo, id, campo]);

  useEffect(() => { void carregar(); }, [carregar]);

  async function restaurar(v: Versao) {
    setBusy(true);
    try {
      const r = await sfetch(`/api/asset-versions/${v.id}/restore`, { method: "POST" });
      const d = await r.json().catch(() => ({}));
      if (!r.ok || !d?.ok) { toast.err(d?.error || "Não deu pra restaurar."); return; }
      onRestaurado(d.asset);
      await carregar();
      toast.ok("Versão restaurada. A que estava no ar virou histórico.");
    } catch { toast.err("Erro ao restaurar."); } finally { setBusy(false); }
  }

  async function esquecer(v: Versao) {
    setVersoes((p) => (p ?? []).filter((x) => x.id !== v.id));
    await sfetch(`/api/asset-versions/${v.id}`, { method: "DELETE" }).catch(() => {});
  }

  // Sem histórico não ocupa espaço: a maioria dos assets tem uma versão só, e uma faixa vazia em
  // cada cartão seria ruído puro.
  if (!versoes || versoes.length === 0) return null;

  return (
    <div style={{ display: "flex", alignItems: "center", gap: 7, flexWrap: "wrap" }}>
      <span title="Versões anteriores desta imagem — clique numa pra voltar a ela"
        style={{ display: "inline-flex", alignItems: "center", gap: 4, fontSize: ".72rem", color: "var(--muted)" }}>
        <History size={12} /> antes ({versoes.length})
      </span>
      {versoes.map((v) => (
        <span key={v.id} style={{ position: "relative", display: "inline-block" }}>
          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img src={v.url} alt="versão anterior" onClick={() => !busy && restaurar(v)}
            title={`Voltar pra esta versão${v.model ? ` (${v.model})` : ""}`}
            style={{ width: 46, height: 46, objectFit: "cover", borderRadius: 6, cursor: busy ? "wait" : "pointer", border: "1px solid var(--line2)", display: "block" }} />
          <RotateCcw size={11} style={{ position: "absolute", bottom: 2, left: 2, color: "#fff", filter: "drop-shadow(0 1px 2px rgba(0,0,0,.9))", pointerEvents: "none" }} />
          <button type="button" onClick={() => void esquecer(v)} title="Tirar do histórico (o arquivo continua no storage)"
            style={{ position: "absolute", top: -5, right: -5, width: 16, height: 16, borderRadius: "50%", border: "none", background: "var(--bg2)", color: "var(--muted)", cursor: "pointer", display: "grid", placeItems: "center", padding: 0 }}>
            <X size={9} />
          </button>
        </span>
      ))}
    </div>
  );
}
