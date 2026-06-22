"use client";

import { sfetch } from "@/lib/api";
import { useEffect, useState } from "react";

type Field = { name: string; label: string; type: string };
type Platform = { key: string; label: string; auth: "key" | "oauth"; fields: Field[]; note?: string };
type Conn = { id: string; platform: string; label: string; status: string; detail: string };
type Network = { key: string; label: string; icon: string };
type Account = { id: string; platform: string; name: string };

const CONSOLE = process.env.NEXT_PUBLIC_CONSOLE_URL ?? "";

export default function ConexoesPage() {
  const [platforms, setPlatforms] = useState<Platform[]>([]);
  const [conns, setConns] = useState<Conn[]>([]);
  const [networks, setNetworks] = useState<Network[]>([]);
  const [accounts, setAccounts] = useState<Account[]>([]);
  const [open, setOpen] = useState<string | null>(null);
  const [form, setForm] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<string | null>(null);
  const [netLimit, setNetLimit] = useState<number | null>(null);
  const [limitWarn, setLimitWarn] = useState<string | null>(null);

  async function load() {
    const r = await sfetch("/api/connections");
    const d = await r.json();
    setPlatforms(d.platforms ?? []);
    setConns(d.connections ?? []);
    setNetworks(d.networks ?? []);
    setAccounts(d.accounts ?? []);
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
          ? `Você atingiu o limite de ${lim} rede(s) do seu plano. Remova uma rede para conectar outra.`
          : "Você atingiu o limite de redes do seu plano."
      );
      window.history.replaceState({}, "", window.location.pathname); // não reaparece no refresh
    }
  }, []);

  const statusOf = (key: string) => conns.find((c) => c.platform === key);
  const connected = (netKey: string) => accounts.filter((a) => a.platform === netKey);

  async function connectKey(p: Platform) {
    setBusy(true); setMsg(null);
    const r = await sfetch("/api/connections", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ platform: p.key, credentials: form }),
    });
    const d = await r.json();
    setMsg(d.ok ? `✅ ${d.detail}` : `❌ ${d.detail || d.error}`);
    setBusy(false);
    await load();
    if (d.ok) { setOpen(null); setForm({}); }
  }

  async function disconnect(accountId: string) {
    if (!confirm("Desconectar esta rede?")) return;
    await sfetch("/api/connections", { method: "DELETE", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ accountId }) });
    await load();
  }

  return (
    <>
      <h1 className="h1">Conexões</h1>
      <p className="sub">
        Conecte suas redes sociais e seu blog.
        {netLimit != null
          ? ` Seu plano inclui ${netLimit} rede(s) — ${accounts.length} conectada(s).`
          : ""}
      </p>

      {limitWarn && (
        <div className="card" style={{ background: "rgba(227,179,65,.12)", border: "1px solid #e3b341", padding: "12px 16px", margin: "10px 0" }}>
          <p className="txt" style={{ color: "#e3b341", margin: 0 }}>⚠️ {limitWarn}</p>
        </div>
      )}

      {/* ───── Redes sociais (OAuth white-label, 1 clique) ───── */}
      <h2 className="h2" style={{ marginTop: 8 }}>Redes sociais</h2>
      <div className="grid">
        {networks.map((n) => {
          const accs = connected(n.key);
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
                {/* Navegação completa → 302 pro OAuth da rede → volta pro Reachyn.
                    Rede nova bloqueada ao atingir o teto do plano; reconexão (já tem conta) sempre liberada. */}
                {!accs.length && netLimit != null && accounts.length >= netLimit ? (
                  <span className="btn" style={{ opacity: 0.5, cursor: "not-allowed" }} title={`Limite de ${netLimit} redes do plano atingido`}>Limite atingido</span>
                ) : (
                  <a className="btn ok" href={`${CONSOLE}/connect/${n.key}`}>{accs.length ? "Conectar outra" : "Conectar"}</a>
                )}
              </div>
            </article>
          );
        })}
      </div>

      {/* ───── Blog / outras (chave manual) ───── */}
      <h2 className="h2" style={{ marginTop: 24 }}>Blog & outros</h2>
      <div className="grid">
        {platforms.map((p) => {
          const st = statusOf(p.key);
          const badge =
            st?.status === "connected" ? <span className="badge" style={{ background: "rgba(63,185,80,.15)", color: "var(--green)" }}>✅ conectado</span>
            : st?.status === "error" ? <span className="badge" style={{ background: "rgba(217,61,38,.15)", color: "#ff9b8a" }}>❌ erro</span>
            : <span className="badge" style={{ background: "var(--bg2)", color: "var(--muted)" }}>— não conectado</span>;
          return (
            <article key={p.key} className="card">
              <div className="body">
                <span className="title">{p.label}</span>
                {badge}
                {st?.detail && <p className="txt">{st.detail}</p>}
              </div>
              <div className="acts">
                <button className="btn ok" onClick={() => { setOpen(open === p.key ? null : p.key); setForm({}); setMsg(null); }}>
                  {st ? "Reconectar" : "Conectar"}
                </button>
              </div>
              {open === p.key && (
                <div style={{ padding: "0 16px 16px", display: "flex", flexDirection: "column", gap: 8 }}>
                  {p.fields.map((f) => (
                    <input
                      key={f.name}
                      type={f.type === "password" ? "password" : "text"}
                      placeholder={f.label}
                      value={form[f.name] ?? ""}
                      onChange={(e) => setForm({ ...form, [f.name]: e.target.value })}
                      style={{ background: "var(--bg2)", color: "var(--text)", border: "1px solid var(--line)", borderRadius: 8, padding: "10px 12px", fontSize: ".9rem" }}
                    />
                  ))}
                  <button className="btn ok" disabled={busy} onClick={() => connectKey(p)}>
                    {busy ? "Validando..." : "Validar e salvar"}
                  </button>
                  {msg && <p className="txt">{msg}</p>}
                </div>
              )}
            </article>
          );
        })}
      </div>
    </>
  );
}
