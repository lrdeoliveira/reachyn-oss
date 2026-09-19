"use client";

import { sfetch } from "@/lib/api";
import { useEffect, useState } from "react";
import { marcaMotor, nomeModelo } from "@/lib/motor";

// Seletor de MODELO DE TEXTO (kind=text no catálogo): qualidade × custo do resumo da pesquisa,
// conteúdo (posts), roteiro de História/Filme e bíblia de Personagem. O slug escolhido vai como
// `textModel` no body — o servidor resolve modelo/custo (white-label). Vazio = default do servidor
// (Equilibrado). Compartilhado entre Pesquisar/Resumo/Editar/Histórias/Filme/Personagens.
type TextModel = {
  slug: string; display_name: string; cost_credits: number | null; real_name?: string;
  is_unstable?: boolean; unstable_reason?: string;
  // ORIGEM (só operador): de qual CONTA sai a geração. Os seletores de imagem e vídeo já traziam;
  // o de texto não, e era o único lugar do app onde não dava pra ver por qual conta o crédito ia
  // embora — justamente na linha que atende pesquisa, post, roteiro, personagem e carrossel.
  origem?: string; runs_on?: "nuvem" | "estudio" | "mac";
};

// cache de módulo: os modelos não mudam durante a sessão — evita refetch a cada tela.
let cache: TextModel[] | null = null;

export function TextModelSelect({ value, onChange, style, label, title }: {
  value: string;
  onChange: (slug: string) => void;
  style?: React.CSSProperties;
  label?: string;  // com label → coluna (rótulo em cima), padrão dos forms; sem → inline compacto 🧠
  title?: string;
}) {
  const [models, setModels] = useState<TextModel[]>(cache ?? []);
  useEffect(() => {
    if (cache) return;
    sfetch("/api/gen-models?kind=text").then((r) => r.json()).then((j) => {
      const list: TextModel[] = Array.isArray(j) ? j : j?.data ?? [];
      cache = list;
      setModels(list);
    }).catch(() => {});
  }, []);
  // Sincroniza a UI com o DEFAULT REAL do servidor: sem isso o <select> mostrava a 1ª opção
  // (Rápido) com value="" — mentia o modelo que seria usado.
  //
  // ⚠️ NUNCA pré-selecionar um nível INSTÁVEL. O servidor degrada o default sozinho pro nível
  // estável mais próximo (ResolvesTextModel::textModelFor), mas só quando o cliente NÃO manda
  // `textModel` — escolha explícita ele respeita, porque a tela avisa e a decisão é do cliente.
  // Pré-selecionar aqui É uma escolha explícita: fixar "txt-equilibrado" ANULAVA a degradação e
  // mandava todo mundo de volta pro nível caído (2026-08-03, as 4 linhas Claude do agregador).
  useEffect(() => {
    if (value || models.length === 0) return;
    const padrao = models.find((m) => m.slug === "txt-equilibrado");
    const escolha = padrao && !padrao.is_unstable ? padrao : models.find((m) => !m.is_unstable);
    if (escolha) onChange(escolha.slug);
  }, [value, models, onChange]);
  if (models.length < 2) return null; // catálogo sem escolha → não polui a UI
  const tipBase = title || "Qualidade do texto gerado (resumo, roteiro, legenda) — modelos melhores custam mais créditos";
  const escolhido = models.find((m) => m.slug === value);
  // O motivo da instabilidade vive na DICA, não numa linha de texto. Como frase solta ele
  // quebrava o layout: o modo inline é um inline-flex, e uma sentença inteira ali empurrava o
  // seletor. Na dica a informação continua acessível e ocupa zero espaço; o ⚠️ na opção é o
  // sinal visível, que é tudo que precisa aparecer sem ser pedido.
  const tip = escolhido?.is_unstable
    ? `${tipBase}\n\n⚠️ ${escolhido.unstable_reason || "Este nível está instável — a geração pode cair numa linha reserva."}`
    : tipBase;
  const select = (
    <select value={value} onChange={(e) => onChange(e.target.value)} title={tip}
      style={{ background: "var(--bg2)", color: "var(--text)", border: "1px solid var(--line)", borderRadius: 8, padding: label ? "8px 12px" : "6px 10px", fontSize: label ? ".9rem" : ".8rem", width: label ? "auto" : undefined }}>
      {models.map((m) => (
        // ⚠️ na opção instável: o catálogo já marcava, mas ninguém via — e o cliente escolhia
        // (e pagava) um modelo que o provedor estava recusando, recebendo a linha reserva calada.
        <option key={m.slug} value={m.slug}>
          {m.is_unstable ? "⚠️ " : ""}{marcaMotor(m)} · {m.display_name}{m.cost_credits != null ? ` · ${m.cost_credits} créd` : ""}{m.real_name && nomeModelo(m) !== m.display_name ? ` (${m.real_name})` : ""}
        </option>
      ))}
    </select>
  );
  if (label) {
    // Modo COLUNA: mesmo padrão visual dos campos "Estilo" / "Modelo da base" dos forms.
    return (
      <div style={{ display: "flex", flexDirection: "column", gap: 5, ...style }}>
        <label className="txt" style={{ color: "var(--muted)", fontSize: ".78rem" }}>{label}</label>
        {select}
      </div>
    );
  }
  return (
    <label style={{ display: "inline-flex", alignItems: "center", gap: 6, fontSize: ".78rem", color: "var(--muted)", ...style }} title={tip}>
      🧠
      {select}
    </label>
  );
}
