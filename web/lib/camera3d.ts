// A CÂMERA DO PLANO EM NÚMEROS — o vocabulário de decupagem vira posição de câmera de verdade.
//
// POR QUE ISTO EXISTE: a decupagem já diz ângulo, altura e enquadramento de cada plano, mas quem
// desenha é um modelo de imagem, que INTERPRETA o texto. Com a malha do personagem em mãos, o
// mesmo vocabulário vira geometria exata: dá pra renderizar o plano pedido e usar o render como
// âncora daquele plano. É a diferença entre pedir "contra-plongée" e mostrar o contra-plongée.
//
// TUDO EM FRAÇÃO DA ALTURA DO SUJEITO, nunca em metros: a malha vem de onde o usuário quiser
// (Trellis, Tripo, Blender) e cada gerador exporta numa escala. Medir em "altura do sujeito" faz a
// mesma regra valer pra um boneco de 2 unidades e pra um carro de 400.

/** Fração da altura do sujeito em que a câmera fica. */
const ALTURA: Record<string, number> = {
  chao: 0.05,
  joelho: 0.28,
  peito: 0.75,
  olhos: 0.94,
  alto: 1.25,
};

/** Rotação em torno do sujeito, em graus. 0 = de frente; cresce no sentido horário visto de cima. */
const AZIMUTE: Record<string, number> = {
  frontal: 0,
  tres_quartos: 35,
  lateral: 90,
  costas: 180,
  over_shoulder: 155,   // quase às costas de quem está em quadro
  pov: 0,
  plongee: 25,          // o ângulo alto/baixo entra na ALTURA; aqui só um leve desvio
  contra_plongee: 25,
};

/** Altura extra (fração) quando o ângulo é plongée/contra-plongée — eles são altura, não azimute. */
const ALTURA_DO_ANGULO: Record<string, number> = {
  plongee: 1.45,
  contra_plongee: 0.12,
  pov: 0.94,
};

/**
 * Quanto do sujeito cabe no quadro, em fração da altura dele. 1 = corpo inteiro com folga;
 * 0.25 = só a cabeça. É o que separa um plano geral de um close.
 */
const COBERTURA: Record<string, number> = {
  geral: 2.6,
  conjunto: 1.25,
  americano: 0.75,
  medio: 0.55,
  close: 0.3,
  detalhe: 0.15,
};

/** O ALVO que a câmera mira, em fração da altura (perto do topo nos planos fechados: é o rosto). */
const ALVO: Record<string, number> = {
  geral: 0.5,
  conjunto: 0.5,
  americano: 0.66,
  medio: 0.78,
  close: 0.9,
  detalhe: 0.92,
};

export type PlanoCamera = {
  funcao?: string | null; enquadramento?: string | null; angulo?: string | null; altura?: string | null;
};

/**
 * Posição/alvo/FOV da câmera para este plano, dado o tamanho do sujeito.
 *
 * @param alturaSujeito altura da caixa que envolve a malha (na unidade dela)
 * @param fov  campo de visão vertical em graus (lente); 35 ≈ retrato
 */
export function cameraDoPlano(p: PlanoCamera, alturaSujeito: number, fov = 35) {
  const enq = p.enquadramento || "conjunto";
  const ang = p.angulo || "frontal";
  // Master cobre a cena inteira: mesmo sem enquadramento escolhido, ele abre.
  const cobertura = (COBERTURA[enq] ?? 1.25) * (p.funcao === "master" && !p.enquadramento ? 2 : 1);

  // Distância que faz `cobertura × altura` caber na vertical do quadro — trigonometria simples,
  // e é o que garante que "close" seja close em qualquer escala de malha.
  const dist = (cobertura * alturaSujeito) / (2 * Math.tan((fov * Math.PI) / 360));

  const fracAltura = ALTURA_DO_ANGULO[ang] ?? ALTURA[p.altura || "olhos"] ?? 0.94;
  const rad = ((AZIMUTE[ang] ?? 0) * Math.PI) / 180;

  return {
    // -Z é a FRENTE do sujeito (convenção do glTF: modelos olham para -Z).
    posicao: [Math.sin(rad) * dist, fracAltura * alturaSujeito, -Math.cos(rad) * dist] as [number, number, number],
    alvo: [0, (ALVO[enq] ?? 0.5) * alturaSujeito, 0] as [number, number, number],
    fov,
  };
}
