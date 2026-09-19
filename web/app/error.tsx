"use client";

export default function Error({ reset }: { error: Error & { digest?: string }; reset: () => void }) {
  return (
    <div style={{ minHeight: "100vh", display: "grid", placeItems: "center", padding: 32 }}>
      <div style={{ maxWidth: 420, textAlign: "center" }}>
        <div className="brand" style={{ justifyContent: "center", paddingBottom: 16 }}>
          <span className="dot">R</span> Reachyn
        </div>
        <h1 className="h1" style={{ fontSize: "1.35rem" }}>Não foi possível abrir esta tela</h1>
        <p className="sub">Recarregue para tentar de novo, ou volte e entre outra vez.</p>
        <button type="button" className="btn ok" onClick={() => reset()}>Recarregar</button>
      </div>
    </div>
  );
}
