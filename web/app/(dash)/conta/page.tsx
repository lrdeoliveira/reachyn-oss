"use client";

// S5 (PLANO-UX-INTERFACE): Minha conta — senha, segurança (2FA), avisos por e-mail e
// privacidade/LGPD num lugar só, dentro do Studio (antes essas ações viviam escondidas
// no painel de login ou nem existiam).

import { sfetch } from "@/lib/api";
import { useToast } from "@/components/ui/Toast";
import { useEffect, useState } from "react";

const CONSOLE = process.env.NEXT_PUBLIC_CONSOLE_URL ?? "";

export default function ContaPage() {
  const toast = useToast();
  const [me, setMe] = useState<{ name?: string; email?: string } | null>(null);
  const [cur, setCur] = useState("");
  const [nova, setNova] = useState("");
  const [conf, setConf] = useState("");
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    sfetch("/api/me").then((r) => r.json()).then(setMe).catch(() => {});
  }, []);

  async function trocarSenha() {
    if (!cur || !nova) { toast.err("Preencha a senha atual e a nova."); return; }
    if (nova !== conf) { toast.err("A confirmação não bate com a nova senha."); return; }
    setBusy(true);
    try {
      const r = await sfetch("/api/account/password", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ current_password: cur, password: nova, password_confirmation: conf }),
      });
      const d = await r.json();
      if (d.ok) { toast.ok("Senha alterada com sucesso."); setCur(""); setNova(""); setConf(""); }
      else toast.err(d.error || d.message || "Não foi possível alterar a senha (mín. 10 caracteres, com maiúscula, minúscula e número).");
    } catch { toast.err("Não foi possível alterar a senha agora."); }
    setBusy(false);
  }

  return (
    <>
      <h1 className="h1">Minha conta</h1>
      <p className="sub">{me?.email ? `Logado como ${me.email}.` : ""} Senha, segurança e privacidade.</p>

      <div className="grid" style={{ gridTemplateColumns: "repeat(auto-fit, minmax(min(340px,100%),1fr))" }}>
        <article className="card">
          <div className="body">
            <span className="title">🔑 Trocar senha</span>
            <p className="txt">Mínimo de 10 caracteres, com maiúscula, minúscula e número.</p>
            <input type="password" autoComplete="current-password" placeholder="Senha atual" value={cur} onChange={(e) => setCur(e.target.value)} style={inp} />
            <input type="password" autoComplete="new-password" placeholder="Nova senha" value={nova} onChange={(e) => setNova(e.target.value)} style={inp} />
            <input type="password" autoComplete="new-password" placeholder="Confirmar a nova senha" value={conf} onChange={(e) => setConf(e.target.value)} style={inp} />
            <button className="btn ok" disabled={busy} onClick={trocarSenha} style={{ marginTop: 4 }}>{busy ? "Salvando…" : "Alterar senha"}</button>
          </div>
        </article>

        <article className="card">
          <div className="body">
            <span className="title">🛡 Segurança — verificação em 2 etapas</span>
            <p className="txt">Adicione um segundo fator (aplicativo autenticador) ao seu login. Recomendado: protege sua conta e seus créditos mesmo se a senha vazar.</p>
            <a className="btn edit" style={{ textAlign: "center" }} href={`${CONSOLE}/app/profile`} target="_blank" rel="noreferrer">Configurar 2FA no perfil →</a>
          </div>
        </article>

        <article className="card">
          <div className="body">
            <span className="title">✉️ Avisos por e-mail</span>
            <p className="txt">Escolha o que o Reachyn pode te avisar (geração pronta, aprovações, créditos, trial).</p>
            <a className="btn edit" style={{ textAlign: "center" }} href="/plano">Ajustar em Plano &amp; Uso →</a>
          </div>
        </article>

        <article className="card">
          <div className="body">
            <span className="title">🔒 Privacidade &amp; meus dados (LGPD)</span>
            <p className="txt">Exporte uma cópia dos seus dados ou peça a exclusão definitiva da conta.</p>
            <a className="btn edit" style={{ textAlign: "center" }} href={`${CONSOLE}/conta/privacidade`} target="_blank" rel="noreferrer">Abrir Privacidade &amp; Meus Dados →</a>
          </div>
        </article>
      </div>
    </>
  );
}

const inp: React.CSSProperties = { width: "100%", background: "var(--bg2)", color: "var(--text)", border: "1px solid var(--line)", borderRadius: 8, padding: "9px 11px", fontSize: ".9rem" };
