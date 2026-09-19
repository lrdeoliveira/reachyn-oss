"use client";

export default function GlobalError({ reset }: { error: Error & { digest?: string }; reset: () => void }) {
  return (
    <html lang="pt-BR">
      <body style={{ margin: 0, minHeight: "100vh", display: "grid", placeItems: "center", background: "#141110", color: "#efe7e2", fontFamily: "Inter, system-ui, sans-serif" }}>
        <div style={{ maxWidth: 420, textAlign: "center", padding: 32 }}>
          <p style={{ fontWeight: 800, letterSpacing: "-0.02em" }}>Reachyn</p>
          <h1 style={{ fontSize: "1.35rem", fontWeight: 800 }}>Não foi possível abrir esta tela</h1>
          <p style={{ color: "#b3a89e" }}>Recarregue para tentar de novo, ou volte e entre outra vez.</p>
          <button
            type="button"
            onClick={() => reset()}
            style={{
              background: "linear-gradient(135deg, #e24a31, #b8321f)",
              color: "#fff",
              border: 0,
              borderRadius: 10,
              padding: "10px 18px",
              fontWeight: 700,
              cursor: "pointer",
            }}
          >
            Recarregar
          </button>
        </div>
      </body>
    </html>
  );
}
