"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";

export default function ConexaoOkPage() {
  const router = useRouter();
  const [rede, setRede] = useState("rede");
  useEffect(() => {
    setRede(new URLSearchParams(window.location.search).get("rede") ?? "rede");
    const t = setTimeout(() => router.push("/conexoes"), 2500);
    return () => clearTimeout(t);
  }, [router]);
  return (
    <div style={{ textAlign: "center", padding: "60px 20px" }}>
      <div style={{ fontSize: "3rem" }}>✅</div>
      <h1 className="h1">{rede.charAt(0).toUpperCase() + rede.slice(1)} conectada!</h1>
      <p className="sub">Sua conta foi vinculada ao Reachyn. Redirecionando para Conexões…</p>
      <a className="btn ok" href="/conexoes">Voltar agora</a>
    </div>
  );
}
