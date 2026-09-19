// Manual de uso do Reachyn — servido como HTML estático self-contained (public/manual.html) e
// exibido aqui num iframe same-origin. Assim o manual completo (identidade + índice + tema próprio)
// vive DENTRO do app, acessível por qualquer usuário logado, sem depender de serviço externo.
export const metadata = { title: "Manual · Reachyn" };

export default function AjudaPage() {
  return (
    <iframe
      src="/manual.html"
      title="Manual do Reachyn"
      style={{
        display: "block",
        width: "100%",
        height: "calc(100vh - 88px)",
        border: "1px solid var(--line)",
        borderRadius: 14,
        background: "var(--bg)",
      }}
    />
  );
}
