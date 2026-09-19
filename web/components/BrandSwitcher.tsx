"use client";

import { type Brand, setActiveTenant } from "@/lib/api";

/**
 * Seletor de MARCA (Fase 2). Troca a marca/Tenant ativa do usuário dentro da sua organização.
 * Ao trocar, persiste a escolha (localStorage via setActiveTenant) e recarrega — assim TODAS as
 * telas refazem fetch já sob a nova marca (o header X-Tenant-Id passa a apontar pra ela). Só
 * aparece quando a org tem mais de uma marca.
 */
export function BrandSwitcher({ brands, activeId }: { brands: Brand[]; activeId: number | null }) {
  if (!brands || brands.length <= 1) return null;

  return (
    <div style={{ padding: "0 14px 12px" }}>
      <label style={{ fontSize: ".72rem", color: "var(--muted)", display: "block", marginBottom: 4 }}>Marca</label>
      <select
        value={activeId ?? ""}
        onChange={(e) => {
          setActiveTenant(e.target.value);
          window.location.reload();
        }}
        style={{
          width: "100%", background: "var(--bg2)", color: "var(--text)",
          border: "1px solid var(--line)", borderRadius: 8, padding: "7px 9px", fontSize: ".85rem",
        }}
      >
        {brands.map((b) => (
          <option key={b.id} value={b.id}>{b.name}</option>
        ))}
      </select>
    </div>
  );
}
