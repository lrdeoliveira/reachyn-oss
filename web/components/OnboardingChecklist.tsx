"use client";

// S3 (PLANO-UX-INTERFACE): checklist de primeiro uso — ativação do freemium.
// Aparece na home enquanto o usuário não completou os 4 passos-chave; cada passo marca
// SOZINHO (estado derivado do banco via GET /api/onboarding). Dismissível (localStorage).
// Também faz o papel de tour: cada item linka direto pra tela certa.

import { useEffect, useState } from "react";
import { sfetch } from "@/lib/api";

type Steps = { connected: boolean; media: boolean; published: boolean };
const LS_KEY = "reachyn.onboarding.dismissed";

const ITEMS: { key: keyof Steps; label: string; desc: string; href: string }[] = [
  { key: "connected", label: "Conecte uma rede social", desc: "é onde seu conteúdo vai ser publicado", href: "/conexoes" },
  { key: "media", label: "Gere sua primeira mídia", desc: "uma imagem ou vídeo na aba Mídia", href: "/midia" },
  { key: "published", label: "Aprove e publique", desc: "revise a peça e mande pras redes", href: "/aprovacoes" },
];

export function OnboardingChecklist() {
  const [steps, setSteps] = useState<Steps | null>(null);
  const [hidden, setHidden] = useState(true);

  useEffect(() => {
    if (typeof window !== "undefined" && window.localStorage.getItem(LS_KEY)) return; // dispensado
    sfetch("/api/onboarding").then((r) => r.json()).then((j) => {
      if (j.ok) { setSteps(j.steps); setHidden(false); }
    }).catch(() => {});
  }, []);

  if (hidden || !steps) return null;
  const done = ITEMS.filter((i) => steps[i.key]).length;
  if (done === ITEMS.length) return null; // completou tudo — some pra sempre

  return (
    <article className="card" style={{ marginBottom: 22, borderColor: "rgba(226,74,49,.35)" }}>
      <div className="body">
        <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", gap: 10 }}>
          <span className="title">🦊 Primeiros passos no Reachyn — {done}/{ITEMS.length}</span>
          <button
            aria-label="Dispensar checklist"
            onClick={() => { setHidden(true); window.localStorage.setItem(LS_KEY, "1"); }}
            style={{ background: "none", border: 0, color: "var(--muted)", cursor: "pointer", fontSize: "1rem" }}
          >×</button>
        </div>
        <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(min(220px,100%),1fr))", gap: 10, marginTop: 8 }}>
          {ITEMS.map((it, i) => (
            <a key={it.key} href={it.href} style={{
              display: "flex", gap: 10, alignItems: "flex-start", padding: "10px 12px", borderRadius: 10,
              background: steps[it.key] ? "rgba(63,185,80,.08)" : "var(--bg2)",
              border: `1px solid ${steps[it.key] ? "rgba(63,185,80,.4)" : "var(--line)"}`,
            }}>
              <span aria-hidden style={{ fontSize: "1rem", lineHeight: 1.3 }}>{steps[it.key] ? "✅" : `${i + 1}️⃣`}</span>
              <span style={{ minWidth: 0 }}>
                <span style={{ display: "block", fontWeight: 700, fontSize: ".86rem", textDecoration: steps[it.key] ? "line-through" : "none", opacity: steps[it.key] ? 0.7 : 1 }}>{it.label}</span>
                <span style={{ display: "block", color: "var(--muted)", fontSize: ".76rem" }}>{it.desc}</span>
              </span>
            </a>
          ))}
        </div>
      </div>
    </article>
  );
}
