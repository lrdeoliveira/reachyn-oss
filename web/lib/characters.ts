"use client";

import { useEffect, useState } from "react";
import { sfetch } from "@/lib/api";

/** Personagem da biblioteca do tenant, na forma mínima que as telas usam pra oferecê-lo como
 *  identidade de um papel. */
export type LibChar = { id: number; name: string; base_url?: string; sheet_url?: string };

/**
 * Personagens da biblioteca que servem como ÂNCORA DE IDENTIDADE — só os que já têm imagem
 * (base ou model sheet); sem imagem não há o que ancorar, e oferecê-los só gera erro 422 do
 * backend depois do clique.
 *
 * O fetch + esse filtro estavam copiados em StepElenco (Movies) e Animacao — e o filtro é a parte
 * que importa: se um lado ganhasse um critério novo (ex.: personagem arquivado) e o outro não, uma
 * tela ofereceria personagem que a outra esconde, sem ninguém perceber.
 */
export function useLibraryCharacters(): LibChar[] {
  const [chars, setChars] = useState<LibChar[]>([]);
  useEffect(() => {
    sfetch("/api/characters").then((r) => r.json()).then((j) => {
      const list: LibChar[] = Array.isArray(j) ? j : j?.data ?? [];
      setChars(list.filter((c) => c.base_url || c.sheet_url));
    }).catch(() => {});
  }, []);
  return chars;
}

/** Texto do seletor "usar personagem da biblioteca". Estava escrito nas duas telas quase igual —
 *  e uma explicação de custo que diverge entre telas é pior que nenhuma. */
export const LIB_CHAR_TITLE =
  "Usa um personagem salvo (aba Personagens) como identidade deste papel: a imagem-base vira a referência e o lock vira o prompt — sem custo, sem gerar nada novo.";
export const LIB_CHAR_PLACEHOLDER = "🎭 Usar personagem da biblioteca…";
