// RENDER DA MALHA no ângulo do plano — a âncora que a decupagem pediu, desenhada de verdade.
//
// Roda no NAVEGADOR (WebGL), não no servidor: não precisa de GPU dedicada nem de fila, e o
// resultado sai em segundos. O que sobe pro servidor é só o PNG.
//
// O fundo é neutro e a luz é chapada de propósito: isto não é o quadro final, é ÂNCORA — quem
// desenha a cena é o modelo de imagem depois, usando este render como referência de pose e ângulo.
// Sombra dura ou cenário aqui contaminariam a geração.

import * as THREE from "three";
import { GLTFLoader } from "three/examples/jsm/loaders/GLTFLoader.js";
import { cameraDoPlano, type PlanoCamera } from "@/lib/camera3d";

export type ResultadoRender = { blob: Blob; largura: number; altura: number };

const ASPECTOS: Record<string, number> = { "9:16": 9 / 16, "1:1": 1, "16:9": 16 / 9 };

/**
 * Renderiza a malha no enquadramento do plano e devolve o PNG.
 *
 * @param aspect proporção do filme — o render tem que nascer no mesmo formato do quadro final,
 *               senão a âncora sugere um enquadramento que a cena não vai ter.
 */
export async function renderMalha(
  /** Bytes do GLB. NÃO é URL de propósito: o loader busca URL por fetch, e um `blob:` esbarra no
   *  `connect-src` da CSP — afrouxar a política pra isso seria pagar caro por conveniência. */
  glb: ArrayBuffer,
  plano: PlanoCamera,
  aspect: keyof typeof ASPECTOS | string = "16:9",
  lado = 768,
): Promise<ResultadoRender> {
  const razao = ASPECTOS[aspect] ?? 16 / 9;
  const largura = razao >= 1 ? lado : Math.round(lado * razao);
  const altura = razao >= 1 ? Math.round(lado / razao) : lado;

  const canvas = document.createElement("canvas");
  canvas.width = largura;
  canvas.height = altura;

  const renderer = new THREE.WebGLRenderer({ canvas, antialias: true, alpha: false, preserveDrawingBuffer: true });
  renderer.setPixelRatio(1);
  renderer.setSize(largura, altura, false);

  try {
    const gltf = await new Promise<{ scene: THREE.Object3D }>((ok, erro) =>
      new GLTFLoader().parse(glb, "", (g) => ok(g as unknown as { scene: THREE.Object3D }), erro));
    const cena = new THREE.Scene();
    cena.background = new THREE.Color(0xf0f0f0);   // cinza claro, como o fundo da imagem-base
    cena.add(gltf.scene);

    // O sujeito é CENTRADO no chão (y=0): toda a matemática de câmera fala em fração da altura
    // dele, e uma malha exportada com origem no umbigo quebraria a régua.
    const caixa = new THREE.Box3().setFromObject(gltf.scene);
    const tamanho = caixa.getSize(new THREE.Vector3());
    const centro = caixa.getCenter(new THREE.Vector3());
    gltf.scene.position.set(-centro.x, -caixa.min.y, -centro.z);

    const alturaSujeito = Math.max(tamanho.y, 1e-6);
    const { posicao, alvo, fov } = cameraDoPlano(plano, alturaSujeito);

    const cam = new THREE.PerspectiveCamera(fov, largura / altura, alturaSujeito / 100, alturaSujeito * 100);
    cam.position.set(...posicao);
    cam.lookAt(...alvo);

    // Luz chapada: frontal forte + ambiente. Sem sombra dura — a âncora mostra FORMA e ÂNGULO, e
    // uma sombra marcada viajaria pro i2i como se fosse a luz da cena.
    cena.add(new THREE.AmbientLight(0xffffff, 1.6));
    const key = new THREE.DirectionalLight(0xffffff, 1.5);
    key.position.set(posicao[0], posicao[1] + alturaSujeito, posicao[2]);
    cena.add(key);
    const fill = new THREE.DirectionalLight(0xffffff, 0.6);
    fill.position.set(-posicao[0], alturaSujeito, -posicao[2]);
    cena.add(fill);

    renderer.render(cena, cam);

    const blob = await new Promise<Blob | null>((r) => canvas.toBlob(r, "image/png"));
    if (!blob) throw new Error("canvas vazio");

    return { blob, largura, altura };
  } finally {
    // WebGL não é coletado sozinho: sem isto, um render por plano estoura o limite de contextos do
    // navegador (~16) e os seguintes falham em silêncio.
    renderer.dispose();
  }
}
