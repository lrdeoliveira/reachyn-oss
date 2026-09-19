"use client";

// 🚀 Publicar — o disparo do rascunho corrente (Studio step="publish") + o compositor "➕ Novo
// post" (?novo=1), que substituiu a aba /postar (2026-08-05): mídia própria já está aprovada
// por definição, então o upload manual publica direto daqui, sem fila.
// O botão "+ Novo" do cabeçalho do Studio leva pra ?novo=1 (ver Studio.novo()).

import { Suspense } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { Studio } from "@/components/Studio";
import { NovoPost } from "@/components/publicar/NovoPost";

function PublicarInner() {
  const router = useRouter();
  const params = useSearchParams();
  if (params.get("novo")) {
    return <NovoPost onClose={() => router.replace("/publicar")} />;
  }
  return <Studio step="publish" />;
}

// useSearchParams exige Suspense no app router (CSR bailout) — sem ele o build reclama.
export default function Page() {
  return (
    <Suspense fallback={null}>
      <PublicarInner />
    </Suspense>
  );
}
