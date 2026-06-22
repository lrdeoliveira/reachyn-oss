"use client";

import { usePathname } from "next/navigation";
import { useEffect, useState } from "react";
import { Search, FileText, Image as ImageIcon, CheckCircle2, Send, Plug, CreditCard, ShieldCheck, KeyRound, GalleryHorizontalEnd, Newspaper } from "lucide-react";
import { sfetch } from "@/lib/api";

const CONSOLE = process.env.NEXT_PUBLIC_CONSOLE_URL ?? "";

const FLOW = [
  { href: "/", label: "Pesquisar", icon: Search },
  { href: "/editar", label: "Conteúdo", icon: FileText },
  { href: "/midia", label: "Mídia", icon: ImageIcon },
  { href: "/aprovar", label: "Aprovar", icon: CheckCircle2 },
  { href: "/publicar", label: "Publicar", icon: Send },
  { href: "/publicacoes", label: "Publicações", icon: Newspaper },
  { href: "/galeria", label: "Galeria", icon: GalleryHorizontalEnd },
];
const ACCOUNT = [
  { href: "/conexoes", label: "Conexões", icon: Plug },
  { href: "/plano", label: "Plano & Uso", icon: CreditCard },
];

export function SideNav() {
  const path = usePathname();
  // Operador (admin) vê o link pro painel admin; cliente comum não.
  const [operator, setOperator] = useState(false);
  useEffect(() => {
    sfetch("/api/me").then((r) => r.json()).then((u) => {
      if (u?.role === "operator" || u?.role === "admin") setOperator(true);
    }).catch(() => {});
  }, []);

  const render = (items: typeof FLOW) => items.map(({ href, label, icon: Icon }) => (
    <a key={href} href={href} className={path === href ? "active" : ""}><Icon size={17} /> {label}</a>
  ));
  return (
    <>
      <div className="navgroup">Fluxo</div>
      <nav className="nav">{render(FLOW)}</nav>
      <div className="navgroup">Conta</div>
      <nav className="nav">{render(ACCOUNT)}</nav>
      {operator && (
        <>
          <div className="navgroup">Operador</div>
          <nav className="nav">
            {/* Chaves API: pesquisa + geração + publicação, num lugar só. Admin: painel de operação. */}
            <a href="/chaves-api" className={path === "/chaves-api" ? "active" : ""}><KeyRound size={17} /> Chaves API</a>
            <a href={`${CONSOLE}/admin`}><ShieldCheck size={17} /> Admin</a>
          </nav>
        </>
      )}
    </>
  );
}
