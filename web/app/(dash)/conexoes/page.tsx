"use client";

import { sfetch } from "@/lib/api";
import { useEffect, useState } from "react";

// A seção "Blog & outros" (conexão por chave — só WordPress) saiu em 2026-07-29: o produto não
// publica mais em blog, não havia nenhuma conexão salva, e o formulário guardava credencial sem
// validar nada. Sobrou o que se usa: perfis + redes sociais por OAuth.
type Conn = { id: string; platform: string; label: string; status: string; detail: string };
type Network = { key: string; label: string; icon: string };
type Account = { id: string; platform: string; name: string };
type Profile = { id: string; name: string; is_default: boolean; zernio_profile_id: string | null; accounts: Account[] };

const CONSOLE = process.env.NEXT_PUBLIC_CONSOLE_URL ?? "";

export default function ConexoesPage() {
  const [conns, setConns] = useState<Conn[]>([]);
  const [networks, setNetworks] = useState<Network[]>([]);
  const [profiles, setProfiles] = useState<Profile[]>([]);
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<string | null>(null);
  const [netLimit, setNetLimit] = useState<number | null>(null);
  const [limitWarn, setLimitWarn] = useState<string | null>(null);
  // Adicionar perfil (novo ou registrar um já existente no conector social pelo id)
  const [addOpen, setAddOpen] = useState(false);
  const [addName, setAddName] = useState("");
  const [addExisting, setAddExisting] = useState("");

  async function load() {
    const r = await sfetch("/api/connections");
    const d = await r.json();
    setConns(d.connections ?? []);
    setNetworks(d.networks ?? []);
    setProfiles(d.profiles ?? []);
    try {
      const u = await (await sfetch("/api/usage")).json();
      setNetLimit(typeof u?.limits?.networks === "number" ? u.limits.networks : null);
    } catch { /* usage indisponível não quebra a página */ }
  }
  useEffect(() => { load(); }, []);

  // Gate de teto de redes (backend redireciona pra cá com ?erro=limite_redes&limite=N)
  useEffect(() => {
    const p = new URLSearchParams(window.location.search);
    if (p.get("erro") === "limite_redes") {
      const lim = p.get("limite");
      setLimitWarn(
        lim
          ? `Você atingiu o limite de ${lim} rede(s) por perfil do seu plano. Remova uma rede ou aumente o limite.`
          : "Você atingiu o limite de redes do seu plano."
      );
      window.history.replaceState({}, "", window.location.pathname); // não reaparece no refresh
    }
  }, []);


  async function addProfile() {
    if (!addName.trim()) { setMsg("❌ Dê um nome ao perfil."); return; }
    setBusy(true); setMsg(null);
    const r = await sfetch("/api/connections/profile", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ name: addName.trim(), zernio_profile_id: addExisting.trim() || undefined }),
    });
    const d = await r.json();
    setBusy(false);
    if (d.ok) { setMsg(`✅ Perfil "${addName.trim()}" criado.`); setAddOpen(false); setAddName(""); setAddExisting(""); await load(); }
    else setMsg(`❌ ${d.error || "não foi possível criar o perfil"}`);
  }

  async function disconnect(accountId: string) {
    if (!confirm("Desconectar esta rede?")) return;
    await sfetch("/api/connections", { method: "DELETE", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ accountId }) });
    await load();
  }

  const totalAccounts = profiles.reduce((n, p) => n + p.accounts.length, 0);

  return (
    <>
      <h1 className="h1">Conexões</h1>
      <div style={{ border: "1px solid rgba(245,158,11,.4)", background: "rgba(245,158,11,.08)", borderRadius: 10, padding: "10px 14px", margin: "8px 0 14px", fontSize: ".9rem", lineHeight: 1.5 }}>
        ⚠️ <strong>Antes de conectar:</strong> esteja com a conta da rede social <strong>logada neste navegador</strong>. A conexão abre o login da própria plataforma — se você não estiver logado (ou estiver na conta errada), ela conecta a conta errada ou falha.
      </div>
      <p className="sub">
        Cada <strong>perfil</strong> é um conjunto de contas (ex.: Marca, Pessoal, Cliente X). Crie quantos precisar —
        ao publicar, você escolhe em quais perfis postar.
        {` ${totalAccounts} rede(s) conectada(s).`}
      </p>

      {limitWarn && (
        <div className="card" style={{ background: "rgba(227,179,65,.12)", border: "1px solid #e3b341", padding: "12px 16px", margin: "10px 0" }}>
          <p className="txt" style={{ color: "#e3b341", margin: 0 }}>⚠️ {limitWarn}</p>
        </div>
      )}

      {/* ───── Adicionar perfil ───── */}
      <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", margin: "8px 0", gap: 8, flexWrap: "wrap" }}>
        <h2 className="h2" style={{ margin: 0 }}>Perfis de redes</h2>
        <button className="btn ok" style={{ flex: "none", padding: "8px 14px" }} onClick={() => { setAddOpen((o) => !o); setMsg(null); }}>+ Adicionar perfil</button>
      </div>
      {addOpen && (
        <div className="card" style={{ padding: 16, marginBottom: 12, display: "flex", flexDirection: "column", gap: 10 }}>
          <div style={{ display: "flex", flexDirection: "column", gap: 4 }}>
            <label className="txt" style={{ color: "var(--muted)", fontSize: ".82rem" }}>Nome do perfil</label>
            <input value={addName} placeholder="ex: Pessoal, Marca BR, Cliente X" onChange={(e) => setAddName(e.target.value)} style={{ background: "var(--bg2)", color: "var(--text)", border: "1px solid var(--line)", borderRadius: 8, padding: "10px 12px", fontSize: ".9rem" }} />
          </div>
          <div style={{ display: "flex", flexDirection: "column", gap: 4 }}>
            <label className="txt" style={{ color: "var(--muted)", fontSize: ".82rem" }}>ID de profile existente <span style={{ fontWeight: 400 }}>(opcional — em branco cria um novo)</span></label>
            <input value={addExisting} placeholder="deixe vazio para criar um perfil novo" onChange={(e) => setAddExisting(e.target.value)} style={{ background: "var(--bg2)", color: "var(--text)", border: "1px solid var(--line)", borderRadius: 8, padding: "10px 12px", fontSize: ".9rem", fontFamily: "ui-monospace, monospace" }} />
          </div>
          <div>
            <button className="btn ok" disabled={busy} onClick={addProfile}>{busy ? "Criando..." : "Criar perfil"}</button>
          </div>
        </div>
      )}

      {/* ───── Perfis: cada um com sua grade de redes (OAuth white-label, 1 clique) ───── */}
      {profiles.length === 0 && <p className="txt" style={{ color: "var(--muted)" }}>Nenhum perfil ainda. Crie o primeiro acima.</p>}
      {profiles.map((prof) => (
        <section key={prof.id} className="card" style={{ padding: 16, marginBottom: 16 }}>
          <div style={{ display: "flex", alignItems: "center", gap: 8, marginBottom: 10, flexWrap: "wrap" }}>
            <strong style={{ fontSize: "1.02rem" }}>{prof.name}</strong>
            {prof.is_default && <span className="badge" style={{ background: "rgba(96,165,250,.15)", color: "#60a5fa" }}>padrão</span>}
            <span className="txt" style={{ color: "var(--muted)", fontSize: ".8rem" }}>· {prof.accounts.length} conta(s)</span>
          </div>
          <div className="grid">
            {networks.map((n) => {
              const accs = prof.accounts.filter((a) => a.platform === n.key);
              const atLimit = false; // F-pub: sem teto de redes — cada conta conectada custa $8/mês (sem limite)
              return (
                <article key={n.key} className="card">
                  <div className="body">
                    <span className="title">{n.icon} {n.label}</span>
                    {accs.length ? (
                      <span className="badge" style={{ background: "rgba(63,185,80,.15)", color: "var(--green)" }}>✅ {accs.length} conta(s)</span>
                    ) : (
                      <span className="badge" style={{ background: "var(--bg2)", color: "var(--muted)" }}>— não conectada</span>
                    )}
                    {accs.map((a) => (
                      <p key={a.id} className="txt" style={{ display: "flex", justifyContent: "space-between", alignItems: "center", gap: 8 }}>
                        <span>{a.name}</span>
                        <button className="btn edit" style={{ padding: "2px 8px", fontSize: ".75rem" }} onClick={() => disconnect(a.id)}>remover</button>
                      </p>
                    ))}
                  </div>
                  <div className="acts">
                    {/* Navegação completa → 302 pro OAuth da rede (no PERFIL escolhido) → volta pro Reachyn. */}
                    {atLimit ? (
                      <span className="btn" style={{ opacity: 0.5, cursor: "not-allowed" }} title={`Limite de ${netLimit} redes por perfil atingido`}>Limite atingido</span>
                    ) : (
                      <a className="btn ok" href={`${CONSOLE}/connect/${n.key}?profile=${prof.id}`}>{accs.length ? "Conectar outra" : "Conectar"}</a>
                    )}
                  </div>
                </article>
              );
            })}
          </div>
        </section>
      ))}

      {msg && <p className="txt" style={{ marginTop: 14 }}>{msg}</p>}
    </>
  );
}
