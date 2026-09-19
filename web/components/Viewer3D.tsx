"use client";

// 🧊 Viewer 3D interativo (aba Games → 3D): carrega o GLB do asset via console
// (/api/mesh/{tipo}/{id} — autenticado, mesma origem; buscar direto no storage esbarra
// em CORS) e deixa orbitar/zoom com o mouse. Luz chapada e fundo neutro de propósito —
// é ferramenta de INSPEÇÃO, não render final (o render de âncora usa renderMalha).
//
// three imperativo (sem react-three-fiber): uma dependência a menos e o mesmo padrão do
// renderMalha — o React só é dono do <div>; o ciclo de vida do WebGL vive no useEffect.

import { useEffect, useRef, useState } from "react";
import * as THREE from "three";
import { GLTFLoader } from "three/examples/jsm/loaders/GLTFLoader.js";
import { OrbitControls } from "three/examples/jsm/controls/OrbitControls.js";
import { sfetch } from "@/lib/api";

export function Viewer3D({ meshPath, height = 380 }: {
  /** `tipo/id` do dono da malha (ex.: "character/16"). */
  meshPath: string;
  height?: number;
}) {
  const mount = useRef<HTMLDivElement | null>(null);
  const [erro, setErro] = useState<string | null>(null);
  const [carregando, setCarregando] = useState(true);

  useEffect(() => {
    const el = mount.current;
    if (!el) return;
    let vivo = true;
    let frame = 0;

    const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
    const scene = new THREE.Scene();
    const camera = new THREE.PerspectiveCamera(35, 1, 0.01, 100);
    const controls = new OrbitControls(camera, renderer.domElement);
    controls.enableDamping = true;

    scene.add(new THREE.AmbientLight(0xffffff, 1.1));
    const sol = new THREE.DirectionalLight(0xffffff, 1.4);
    sol.position.set(2, 4, 3);
    scene.add(sol);

    const resize = () => {
      const w = el.clientWidth || 1;
      renderer.setSize(w, height, false);
      camera.aspect = w / height;
      camera.updateProjectionMatrix();
    };

    (async () => {
      try {
        setCarregando(true);
        const r = await sfetch(`/api/mesh/${meshPath}`);
        if (!r.ok) throw new Error(`malha ${r.status}`);
        const buf = await r.arrayBuffer();
        if (!vivo) return;
        const gltf = await new Promise<{ scene: THREE.Object3D }>((ok, err) =>
          new GLTFLoader().parse(buf, "", (g) => ok(g as unknown as { scene: THREE.Object3D }), err));
        if (!vivo) return;

        const obj = gltf.scene;
        scene.add(obj);
        // Enquadra pela caixa da malha — cada gerador exporta numa escala diferente,
        // então a câmera se ajusta ao sujeito, nunca a metros.
        const box = new THREE.Box3().setFromObject(obj);
        const size = box.getSize(new THREE.Vector3());
        const centro = box.getCenter(new THREE.Vector3());
        const maior = Math.max(size.x, size.y, size.z) || 1;
        camera.position.set(centro.x + maior * 0.9, centro.y + maior * 0.55, centro.z + maior * 1.4);
        controls.target.copy(centro);
        controls.update();

        el.appendChild(renderer.domElement);
        resize();
        window.addEventListener("resize", resize);
        const tick = () => {
          frame = requestAnimationFrame(tick);
          controls.update();
          renderer.render(scene, camera);
        };
        tick();
        setCarregando(false);
      } catch (e) {
        if (vivo) {
          setErro(e instanceof Error ? e.message : "não foi possível carregar a malha");
          setCarregando(false);
        }
      }
    })();

    return () => {
      vivo = false;
      cancelAnimationFrame(frame);
      window.removeEventListener("resize", resize);
      controls.dispose();
      renderer.dispose();
      if (renderer.domElement.parentElement === el) el.removeChild(renderer.domElement);
    };
  }, [meshPath, height]);

  return (
    <div ref={mount} style={{ width: "100%", height, borderRadius: 10, border: "1px solid var(--line2)", background: "var(--bg2)", overflow: "hidden", position: "relative" }}>
      {carregando && !erro && (
        <span style={{ position: "absolute", inset: 0, display: "flex", alignItems: "center", justifyContent: "center", color: "var(--muted)", fontSize: ".85rem" }}>
          Carregando a malha…
        </span>
      )}
      {erro && (
        <span style={{ position: "absolute", inset: 0, display: "flex", alignItems: "center", justifyContent: "center", color: "var(--red)", fontSize: ".85rem" }}>
          {erro}
        </span>
      )}
    </div>
  );
}
