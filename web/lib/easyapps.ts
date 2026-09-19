// 🎛️ AJUSTES (EasyApps) — catálogo ÚNICO das operações de edição de imagem por 1 clique.
// Contrato do POST /api/studio/easyapp: { kind, imageUrl, aspect?, params?, characterId? }.
//
// Por que existe: o catálogo vivia só dentro do Rápido (7 operações, com parâmetro e personagem),
// enquanto a grade da Mídia chamava o MESMO endpoint com um payload mínimo e só 2 operações
// (relight/product_bg) — sem parâmetro. Resultado: "Reiluminar" na Mídia nunca deixava escolher o
// clima da luz, e trocar fundo/roupa/detalhar rosto simplesmente não existiam ali. Duas telas, um
// endpoint, dois contratos. Com o catálogo aqui, as duas oferecem o mesmo e não voltam a divergir.
//
// `upscale` e `bg_remove` NÃO estão aqui de propósito: o backend os redireciona para enhance()
// (outro custo, outro motor) e as duas telas já os expõem como ação direta, sem parâmetro.

export type EasyApp = {
  kind: string;
  label: string;
  /** chave dentro de `params` que o backend lê (StudioController::easyapp). */
  paramKey?: "mood" | "background" | "outfit";
  /** rótulo do campo livre quando a operação aceita parâmetro. */
  paramLabel?: string;
  /** exige um personagem da biblioteca (identidade travada) — o backend devolve 422 sem ele. */
  precisaPersonagem?: boolean;
};

export const EASYAPPS: EasyApp[] = [
  { kind: "relight", label: "💡 Reiluminar", paramKey: "mood", paramLabel: "Clima da luz (ex.: golden hour, neon)" },
  { kind: "product_bg", label: "🖼️ Trocar fundo do produto", paramKey: "background", paramLabel: "Novo fundo do produto (ex.: mármore)" },
  { kind: "bg_change", label: "🌆 Trocar fundo (qualquer cena)", paramKey: "background", paramLabel: "Novo fundo da cena (ex.: praia ao pôr do sol)" },
  { kind: "cloth_change", label: "👗 Trocar roupa", paramKey: "outfit", paramLabel: "Nova roupa (ex.: vestido vermelho)", precisaPersonagem: true },
  { kind: "face_detail", label: "🙂 Detalhar rosto" },
];

export const easyAppDe = (kind: string): EasyApp | undefined => EASYAPPS.find((e) => e.kind === kind);

/** Monta o `params` do payload a partir do texto livre. Vazio → objeto vazio (o backend tem
 *  default próprio pra cada operação). */
export function easyAppParams(kind: string, valor: string): Record<string, string> {
  const e = easyAppDe(kind);
  const v = valor.trim();
  return e?.paramKey && v ? { [e.paramKey]: v } : {};
}
