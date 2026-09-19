// ONDE cada motor roda — vocabulário ÚNICO do FoxAssets (definido em 2026-07-30).
//
// O problema que isto resolve: a tela misturava três coisas muito diferentes sob nomes que não
// diziam nada. "Econômico" e "Estúdio Local" apareciam lado a lado no mesmo seletor, sem contar
// que um cobra por peça e o outro depende de um servidor estar de pé. Pior: "Estúdio LOCAL"
// mentia — o ComfyUI costuma estar no Colab, que de local não tem nada.
//
// Três lugares, e a diferença entre eles é o que muda a decisão ANTES do clique:
//   ☁️ Nuvem     — o serviço de geração. Sempre disponível, cobra crédito por peça.
//   🎛️ Estúdio   — o SEU ComfyUI, esteja ele nesta máquina ou num Colab. Não cobra crédito por
//                  peça (gasta tempo de GPU), mas só funciona com o servidor de pé.
//   💻 Este Mac  — o que roda nesta máquina por bridge: a CLI de imagem e o Blender.
//                  Usa assinatura/CPU-GPU daqui, não crédito.
//
// White-label (#6): tudo aqui fala de LUGAR, nunca de provedor. Nenhum rótulo pode virar o nome
// de uma empresa de IA.

export type MotorLugar = "nuvem" | "estudio" | "mac";

export const MOTORES: Record<MotorLugar, { icone: string; nome: string; ajuda: string }> = {
  nuvem: {
    icone: "☁️",
    nome: "Nuvem",
    ajuda: "Roda no serviço de geração: sempre disponível e cobra crédito por peça.",
  },
  estudio: {
    icone: "🎛️",
    nome: "Estúdio",
    ajuda:
      "Roda no SEU servidor de geração (nesta máquina ou no Colab): não cobra crédito por peça, " +
      "gasta tempo de GPU — e só funciona com o servidor de pé.",
  },
  mac: {
    icone: "💻",
    nome: "Este Mac",
    ajuda: "Roda nesta máquina (CLI de imagem, Blender): usa sua assinatura e o hardware daqui, não crédito.",
  },
};

/** Lugar de um modelo do catálogo. Sem a flag (catálogo antigo) assume nuvem: é o que era antes
 *  de existir motor próprio, e errar pra "nuvem" é o erro seguro — no máximo o rótulo some. */
export function lugarDe(m?: { runs_on?: MotorLugar } | null): MotorLugar {
  return m?.runs_on ?? "nuvem";
}

// ─── ORIGEM (só operador) ────────────────────────────────────────────────────
//
// O `runs_on` acima é o vocabulário do CLIENTE e continua valendo. Mas pra ORGANIZAR A CRIAÇÃO
// ele é grosso demais: KIE, MiniMax e Google viram todos "☁️ Nuvem", e Higgsfield e mmx viram os
// dois "💻 Este Mac" — sendo que cada um consome uma CONTA diferente, e é a conta que acaba.
// (2026-07-20: o saldo do KIE zerou e o sintoma chegou como "geração falhando".)
//
// Por isso o backend manda `origem` com o nome da origem — e SÓ pro operador (mesmo tratamento do
// `real_name`; o cliente não recebe o campo e continua vendo só o lugar). White-label (#6) segue
// intacto: quem não é operador nunca lê estes nomes.

/** Ícone por origem. Chave = o que o backend manda em `origem` (GenModelResource). */
const ICONE_ORIGEM: Record<string, string> = {
  KIE: "☁️",          // agregador pago — crédito por peça, o saldo que mais some
  Higgsfield: "✨",    // conta de assinatura própria (bridge no Mac), saldo próprio na sidebar
  ComfyUI: "🎛️",      // o servidor do operador (Colab ou este Mac) — GPU em vez de crédito
  Mac: "💻",          // CLI desta máquina (mmx/cursor/agy)
  MiniMax: "☁️",
  Google: "☁️",
  ElevenLabs: "☁️",
};

type ComOrigem = { runs_on?: MotorLugar; origem?: string } | null | undefined;

/** Nome da origem quando o backend mandou (operador); senão null — e o rótulo cai no LUGAR. */
export function origemDe(m?: ComOrigem): string | null {
  const o = m?.origem?.trim();

  return o ? o : null;
}

/** Ícone da origem, com o do lugar como reserva (cliente, ou origem nova ainda sem ícone). */
export function iconeDe(m?: ComOrigem): string {
  const o = origemDe(m);

  return (o && ICONE_ORIGEM[o]) || MOTORES[lugarDe(m)].icone;
}

/** Prefixo pro nome do modelo dentro de um <option> (onde não cabe tooltip nem cor).
 *  Operador lê "☁️ KIE"; cliente lê só "☁️" — o campo `origem` nem chega nele. */
export function marcaMotor(m?: ComOrigem): string {
  const o = origemDe(m);

  return o ? `${iconeDe(m)} ${o}` : MOTORES[lugarDe(m)].icone;
}

/** Rótulo completo pra chip/badge: "☁️ KIE" pro operador, "🎛️ Estúdio" pro cliente. */
export function rotuloMotor(m?: ComOrigem): string {
  const o = origemDe(m);
  if (o) {
    return `${iconeDe(m)} ${o}`;
  }
  const l = MOTORES[lugarDe(m)];

  return `${l.icone} ${l.nome}`;
}

/** Texto do tooltip: a origem manda no NOME, o lugar explica o CUSTO (que é o que decide). */
export function ajudaMotor(m?: ComOrigem): string {
  const o = origemDe(m);
  const base = MOTORES[lugarDe(m)].ajuda;

  return o ? `Origem: ${o}. ${base}` : base;
}

/**
 * Nome do modelo pra mostrar DEPOIS da origem, sem repetir a origem.
 *
 * O operador lê o `real_name` (o modelo real por baixo) — mas nos motores de bridge o
 * provider_model_id É o nome do adapter ("higgsfield", "higgsfield-soul"), então a linha saía
 * "✨ Higgsfield · higgsfield · 2 cr": a origem duas vezes e o modelo nenhuma. Nesse caso vale
 * mais o display_name ("Nano Banana 2"), que diz QUAL modelo é — e o sufixo "(Higgsfield)" dele
 * também sai, porque o ícone e a origem já estão na frente.
 */
export function nomeModelo(m?: { real_name?: string; display_name?: string; origem?: string } | null): string {
  const origem = m?.origem?.trim() ?? "";
  const real = m?.real_name?.trim() ?? "";
  const exibicao = m?.display_name?.trim() ?? "";
  const redundante = origem !== "" && real.toLowerCase().startsWith(origem.toLowerCase());
  const nome = redundante || real === "" ? exibicao : real;

  return origem === ""
    ? nome
    : nome.replace(new RegExp(`\\s*\\(${origem}\\)\\s*$`, "i"), "").trim() || nome;
}
