"use client";

import { sfetch } from "@/lib/api";
import { useEffect, useState } from "react";

type Usage = {
  plan: "starter" | "pro" | "studio" | "unlimited";
  limits: { video: number; image: number; "premium-video"?: number; premium?: boolean; networks?: number };
  usage: { image: number; video: number; "premium-video"?: number };
  networks: number;
};

function Bar({ used, total }: { used: number; total: number }) {
  const pct = total > 0 ? Math.min(100, Math.round((used / total) * 100)) : 0;
  const color = pct >= 100 ? "var(--red, #d93d26)" : pct >= 80 ? "#e3b341" : "var(--green)";
  return (
    <div style={{ background: "var(--bg2)", borderRadius: 6, height: 10, overflow: "hidden", marginTop: 6 }}>
      <div style={{ width: `${pct}%`, height: "100%", background: color }} />
    </div>
  );
}

export default function PlanoPage() {
  const [d, setD] = useState<Usage | null>(null);
  useEffect(() => { sfetch("/api/usage").then((r) => r.json()).then((j) => j.ok && setD(j)); }, []);

  if (!d) return <><h1 className="h1">Plano & Uso</h1><p className="sub">Carregando…</p></>;

  const planName = d.plan === "unlimited" ? "Ilimitado (interno)" : d.plan === "studio" ? "Studio" : d.plan === "pro" ? "Pro" : "Starter";

  return (
    <>
      <h1 className="h1">Plano & Uso</h1>
      <p className="sub">Seu consumo do mês x os limites do plano.</p>

      <div className="grid">
        <article className="card">
          <div className="body">
            <span className="title">Plano {planName}</span>
            <p className="txt">{d.limits.premium ? "Imagens, vídeos, shorts e vídeo premium." : "Imagens, vídeos e shorts."}</p>
          </div>
        </article>

        <article className="card">
          <div className="body">
            <span className="title">🖼️ Imagens</span>
            <p className="txt">{d.usage.image} / {d.limits.image} no mês</p>
            <Bar used={d.usage.image} total={d.limits.image} />
          </div>
        </article>

        <article className="card">
          <div className="body">
            <span className="title">🎬 Vídeos</span>
            {d.limits.video > 0 ? (
              <>
                <p className="txt">{d.usage.video} / {d.limits.video} no mês</p>
                <Bar used={d.usage.video} total={d.limits.video} />
              </>
            ) : (
              <p className="txt" style={{ color: "var(--muted)" }}>Disponível no plano Pro.</p>
            )}
          </div>
        </article>

        <article className="card">
          <div className="body">
            <span className="title">🔌 Redes conectadas</span>
            <p className="txt">{d.networks} / {d.limits.networks ?? 0} rede(s)</p>
          </div>
        </article>
      </div>
    </>
  );
}
