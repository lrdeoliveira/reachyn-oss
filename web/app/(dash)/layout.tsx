"use client";

import { useEffect, useState } from "react";
import { LogOut, Menu } from "lucide-react";
import { SideNav } from "@/components/SideNav";
import { BrandSwitcher } from "@/components/BrandSwitcher";
import { CreditBalance } from "@/components/CreditBalance";
import { ProviderCredit } from "@/components/ProviderCredit";
import { ToastProvider } from "@/components/ui/Toast";
import { JobCenterProvider, JobsBell } from "@/lib/jobs";
import { sfetch, loginUrl, consumeSsoCode, type Brand, getActiveTenant, setActiveTenant } from "@/lib/api";

export default function DashLayout({ children }: { children: React.ReactNode }) {
  const [email, setEmail] = useState("");
  const [brands, setBrands] = useState<Brand[]>([]);
  const [activeId, setActiveId] = useState<number | null>(null);
  const [menuOpen, setMenuOpen] = useState(false);

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
        if (d?.email) {
          setEmail(d.email);
          setBrands(d.brands ?? []);
          setActiveId(d.active_tenant_id ?? null);
          // 1ª carga sem marca escolhida → fixa a default que o servidor resolveu (p/ o X-Tenant-Id).
          if (d.active_tenant_id && !getActiveTenant()) setActiveTenant(d.active_tenant_id);
        } else window.location.href = loginUrl();
      })
      .catch(() => {});
  }, []);

  return (
    // Toast + JobCenter no LAYOUT: o acompanhamento de jobs e os avisos sobrevivem à navegação
    // entre páginas (S1 — Central de Tarefas).
    <ToastProvider>
      <JobCenterProvider>
        <div className="app">
          <div className="topbar">
            <button type="button" className="menu-btn" aria-label="Abrir menu" onClick={() => setMenuOpen(true)}><Menu size={20} /></button>
            <div className="brand"><span className="dot">R</span> Reachyn</div>
          </div>
          {menuOpen && <div className="backdrop" onClick={() => setMenuOpen(false)} />}
          {/* Fecha o drawer mobile quando o usuário clica em QUALQUER link da barra. Era um
              `useEffect` reagindo à mudança de pathname — que o lint reprova (setState direto
              no efeito, gera render em cascata) e que só disparava em navegação client-side.
              O clique é o gesto real: pega igual, sem efeito e sem depender do tipo de rota. */}
          <aside
            className={menuOpen ? "side open" : "side"}
            onClick={(e) => { if ((e.target as HTMLElement).closest("a")) setMenuOpen(false); }}
          >
            <div className="brand"><span className="dot">R</span> Reachyn</div>
            <BrandSwitcher brands={brands} activeId={activeId} />
            {/* Saldo no TOPO: cada geração gasta crédito, então o saldo é informação de decisão
                constante — no rodapé, embaixo da lista de navegação, ficava fora da dobra e o
                usuário só descobria o fim dos créditos quando a geração falhava. */}
            <CreditBalance />
            {/* Saldo do PROVEDOR (só operador; o componente não renderiza pros demais). Fica
                logo abaixo da cota do plano porque são coisas diferentes que se confundem
                fácil: acima é o crédito do cliente, aqui é o dinheiro na conta do agregador. */}
            <ProviderCredit />
            <SideNav />
            <JobsBell />
            <div className="foot">
              <div style={{ marginBottom: 8, color: "var(--muted)", fontSize: ".78rem", overflow: "hidden", textOverflow: "ellipsis" }}>{email}</div>
              <button type="button" onClick={handleLogout} className="logout"><LogOut size={15} /> Sair</button>
            </div>
          </aside>
          <main className="main">{children}</main>
        </div>
      </JobCenterProvider>
    </ToastProvider>
  );
}
