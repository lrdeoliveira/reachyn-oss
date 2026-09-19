import type { Metadata } from "next";
import "./globals.css";

// Sem isto o Next pré-renderiza o shell e manda `Cache-Control: s-maxage=31536000`.
// Depois de um deploy o HTML antigo aponta pra chunks com hash que já não existem —
// a página “pisca” e cai em “This page couldn’t load” (browser do Cursor / webview).
export const dynamic = "force-dynamic";
export const revalidate = 0;

export const metadata: Metadata = {
  title: "Reachyn",
  description: "Produção de conteúdo white-label — pesquisa, conteúdo e estúdio de mídia.",
};

export default function RootLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  return (
    <html lang="pt-BR">
      <body>{children}</body>
    </html>
  );
}
