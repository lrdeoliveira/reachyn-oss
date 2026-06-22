"use client";

import { useEffect, useState } from "react";
import { LogOut } from "lucide-react";
import { SideNav } from "@/components/SideNav";
import { sfetch, loginUrl, consumeSsoCode } from "@/lib/api";

export default function DashLayout({ children }: { children: React.ReactNode }) {
  const [email, setEmail] = useState("");

  // AUD-026: a rota /logout do console virou POST-only (CSRF). Em vez de um link
  // GET (vulnerável a CSRF/pré-busca), fazemos um POST same-origin via sfetch — que
  // já injeta o cookie de sessão e o header X-XSRF-TOKEN — e só então redirecionamos.
  async function handleLogout() {
    try {
      await sfetch("/logout", { method: "POST" });
    } catch {
      /* mesmo se a chamada falhar, tiramos o usuário pra tela de login */
    } finally {
      window.location.href = loginUrl();
    }
  }

  useEffect(() => {
    // AUD-011: resgata o one-time code do SSO (se houver) ANTES de checar a sessão.
    consumeSsoCode()
      .then(() => sfetch("/api/me"))
      .then((r) => (r.ok ? r.json() : null))
      .then((d) => {
        if (d?.email) setEmail(d.email);
        else window.location.href = loginUrl();
      })
      .catch(() => {});
  }, []);

  return (
    <div className="app">
      <aside className="side">
        <div className="brand"><span className="dot">R</span> Reachyn</div>
        <SideNav />
        <div className="foot">
          <div style={{ marginBottom: 8, color: "var(--muted)", fontSize: ".78rem", overflow: "hidden", textOverflow: "ellipsis" }}>{email}</div>
          <button type="button" onClick={handleLogout} className="logout"><LogOut size={15} /> Sair</button>
        </div>
      </aside>
      <main className="main">{children}</main>
    </div>
  );
}
