"use client";

// 🕹️ Motor LOCAL de sprites — pipeline próprio (docs/PLANO-SPRITE-LOCAL.md), sem chave
// externa: âncora sul por imagem (gpt-image-2 + ref do grid de pixels), ciclo por vídeo
// i2v (seedance), extração de frames + normalização no ffmpeg-service. Os prompts são a
// adaptação dos templates ABERTOS (MIT) de ai-game-spritesheets — as regras que importam:
// grid ref SÓ na âncora (2ª imagem contamina o i2v), walk NUNCA por image-gen, fundo
// chroma pedido NO PROMPT (o recorte vira chroma key determinístico), escala única entre
// frames (drift dentro da célula é o inimigo).

import { useEffect, useRef, useState } from "react";
import { useToast } from "@/components/ui/Toast";
import { Engine, Console, Sprite, ApiError, type GenModelInfo } from "@/lib/api";
import { Wand2, Clapperboard, Grid3x3, Scissors, RotateCcw, Check } from "lucide-react";
import { ComfyStatus, useComfy } from "@/components/ComfyStatus";
import { marcaMotor, nomeModelo } from "@/lib/motor";
import { styleBlock } from "@/lib/gameAssets";

// Refs do pipeline no NOSSO storage. O grid entra SÓ na âncora; o guia 5×2 SÓ na prancha.
//
// 🐛 Estavam MORTAS (2026-08-02): apontavam pro storage ANTIGO do FoxAssets
// (foxassets-s3.redfoxcode.com.br → HTTP 530) e os mesmos caminhos no storage atual davam
// 403, porque nunca foram migradas. Falha SILENCIOSA da pior espécie: o i2i recebe a ref
// quebrada, o provedor a ignora e devolve uma imagem — a âncora perde a disciplina de pixel
// e a prancha perde o layout, sem erro nenhum na tela. O comentário antigo aqui já mandava
// "em prod re-semear via /persist-bytes e trocar aqui" e isso nunca foi feito, porque não
// havia como REFAZER as imagens: eram artefatos manuais de dev que ninguém sabia reproduzir.
//
// Agora são geradas por `tools/sprite-refs/gerar_refs.py`, derivadas dos números que estão
// nos PRÓPRIOS prompts abaixo (1024² com bloco de 4px = frame lógico de 256; guia 1280×512
// com dez células de 256²). Re-semear é rodar o script e trocar as duas URLs — o README ao
// lado tem o comando do /persist-bytes.
const GRID_REF = "https://s3.example.com/public/reachyn/sprite-ref/1785694219431-dd42adb2.png";
const SHEET_GUIDE = "https://s3.example.com/public/reachyn/sprite-ref/1785694219677-0fde4c42.png";

// Defaults preferidos (slugs curados; fallback = 1º da lista). O MOTOR é escolha sua em
// cada passo — o seletor lista tudo que o catálogo oferece (KIE, CLI local, Estúdio Local);
// estes são só o ponto de partida (pedido 2026-07-27: "usarmos as CLI e o KIE").
const IMAGE_PREF = ["img-criativo", "img-pro", "img-referencia"]; // criativo = gpt-image-2 (o do pipeline), aceita 1 ref
const SHEET_PREF = ["img-pro", "img-referencia", "img-ultra"]; // prancha exige 2 refs (âncora + guia) → nano-banana
const VIDEO_PREF = ["vid-realista", "vid-economico", "vid-dinamico"]; // realista = seedance-2; economico = seedance-1.5-pro (ativo)

const CHROMAS: [string, string][] = [
  ["#FF00FF", "Magenta (padrão)"],
  ["#00FF00", "Verde (personagem rosa/magenta)"],
];

// Estilos de personagem (subset do catálogo lib/gameAssets.ts que funciona em sprite animado —
// o pipeline chroma/i2v é agnóstico de estilo; o que muda é o bloco "Style:" dos prompts).
// "pixel-16bit" é o comportamento histórico da aba (o antigo "polished SNES-era" cravado).
const ESTILOS: [string, string][] = [
  ["pixel-16bit", "Pixel art 16-bit (padrão)"],
  ["pixel-8bit", "Pixel art 8-bit"],
  ["pixel-hibit", "Pixel art moderna (hi-bit)"],
  ["vetor-cartoon", "Vetor cartoon"],
  ["chibi", "Chibi"],
  ["tiny", "Tiny style (mini)"],
  ["hand-painted", "Pintado à mão"],
  ["dark-fantasy", "Dark fantasy"],
  ["sci-fi", "Sci-fi"],
  ["pre-rendered", "Pré-renderizado 3D (anos 90)"],
];

// Tamanho LÓGICO do frame: régua de leitura no prompt + tamanho real da célula na
// spritesheet final (campo `cell` do /sprite/normalize, 64..512 — já existia na API,
// só não tinha UI). 256 é o comportamento histórico.
const FRAMES_PX: [number, string][] = [
  [64, "64 px (retrô)"],
  [128, "128 px"],
  [256, "256 px (padrão)"],
  [512, "512 px (HD)"],
];

type Acao = "walk" | "idle" | "attack";
const ACOES: [Acao, string][] = [
  ["walk", "🚶 Caminhada"],
  ["idle", "🧍 Parado (idle)"],
  ["attack", "⚔️ Ataque"],
];

const selectStyle: React.CSSProperties = {
  background: "var(--bg2)",
  color: "var(--text)",
  border: "1px solid var(--line2)",
  borderRadius: 10,
  padding: "10px 12px",
  fontSize: ".9rem",
  fontFamily: "inherit",
};
const inputStyle: React.CSSProperties = { ...selectStyle };
const fieldLabel: React.CSSProperties = { display: "flex", flexDirection: "column", gap: 5 };
const capText: React.CSSProperties = { color: "var(--muted)", fontSize: ".78rem" };
const etapaTitle: React.CSSProperties = { fontSize: ".95rem", fontWeight: 700, display: "flex", alignItems: "center", gap: 8 };

// Template 02 do pipeline: âncora sul canônica (tudo deriva dela). O bloco "Style:" vem do
// catálogo (lib/gameAssets.ts) — era cravado em "polished SNES-era"; agora é escolha do usuário.
function anchorPrompt(nome: string, desc: string, chroma: string, estilo: string, framePx: number): string {
  return `Intended use: a single south-facing idle sprite frame for a top-down 2D action game. Final artwork should behave like one logical ${framePx}x${framePx} in-game frame, delivered at 1024x1024 so the sprite reads cleanly at its logical size.

Image 1 role: pixel-grid anchor. Use it only to enforce chunky pixel-art block discipline and a centered single-frame composition. Do not copy its content.

Subject:
- ${nome}: ${desc}, facing SOUTH directly toward the camera in 3/4 top-down game perspective.
- This is the canonical idle frame.
- calm readable idle expression/pose.

Frame rules:
- One character only, centered.
- Full body visible.
- Visible body fits within the intended logical sprite box.
- Anchor/foot plant at bottom-center.
- Preserve idle readability, not an attack pose.

Style:
- ${styleBlock(estilo)}
- chunky readable silhouette
- crisp edges
- consistent top-left light source

Background:
- solid removable chroma color ${chroma}, filling everything outside the sprite silhouette
- no scenery, props, borders, UI, text, logo, or watermark

Avoid:
- photorealism
- painterly blending
- anti-aliased halos
- extra characters
- complex background
- symbols/runes/text`;
}

// Template 05 (i2v): movimento por ação, direção SUL (a âncora é sul; direções novas
// pedem âncoras direcionais — passo 04, próxima iteração).
// ⚠️ O fundo tem que ser exigido PELA COR: "flat neutral background" fez o seedance trocar
// o magenta por BRANCO (validado 2026-07-27) — e fundo branco come o chroma key.
function motionPrompt(acao: Acao, chroma: string): string {
  const motion = {
    walk: `- low-fidelity, readable, game-sprite reference motion
- small looping in-place walk
- subtle vertical bobbing
- alternating leg steps
- light clothing/equipment sway
- minimal arm swing
- feet remain visible
- character does not translate across the frame`,
    idle: `- frozen statue pose, feet glued to the ground
- no stepping, no walking, no foot lift, no weight shift
- only a tiny breathing bob and light clothing sway
- character does not translate across the frame`,
    attack: `- one simple readable melee attack swing, repeated as a loop
- attack happens in place, feet planted
- returns to the starting pose between swings
- character does not translate across the frame`,
  }[acao];
  return `Animate this single character into a simple SOUTH-facing in-place ${acao} cycle for a top-down 2D game.

The character must face SOUTH, directly toward the camera, for the entire clip.
Preserve the exact identity, art style, proportions, palette, costume, and silhouette from the input image.
Do not turn toward any other direction.
Do not pivot, rotate, or show a quarter-turn view.
Do not change body orientation at any point.

Keep the camera fixed and centered.
Keep the framing unchanged.
Keep the character centered on the exact same solid ${chroma} background from the input image — the background must remain flat, uniform ${chroma} for the entire clip.
Do not change, lighten, or replace the background color.
Do not turn the background into a floor, room, horizon, outdoor scene, perspective grid, shadow plane, or environment.

Motion:
${motion}

One character only.
No scene.
No extra props.
No labels.
No arrows.
No camera movement.
No zoom.
No magic, fireball, spell effects, smoke, particles, glow, trails, or impacts.`;
}

// Template 06/07 (prancha 5×2 por image-gen): as 10 poses do movimento numa imagem só —
// o fluxo "gero vários sprites e animo na Unity", sem passar por vídeo. Walk fica FORA
// deste caminho (regra do pipeline: ciclo de caminhada por image-gen não fecha; é i2v).
function poseSheetPrompt(acao: "idle" | "attack", nome: string, chroma: string, estilo: string): string {
  const seq = acao === "attack"
    ? `Frame 1: neutral ready stance, feet planted, no active effect.
Frame 2: begins the attack wind-up, body still facing SOUTH.
Frame 3: anticipation pose, attack arm rises.
Frame 4: peak of the wind-up, weight shifts back slightly.
Frame 5: swing begins, arm moving forward.
Frame 6: full swing extended, the strongest attack pose.
Frame 7: follow-through, body leaning into the strike.
Frame 8: recoil, arm returning.
Frame 9: settles back toward neutral.
Frame 10: return to calm ready stance.`
    : `Frame 1: neutral idle stance, feet planted, relaxed.
Frame 2: chest rises slightly (inhale begins).
Frame 3: inhale continues, shoulders lift a hint.
Frame 4: peak of the inhale, tallest pose (1-2 pixels taller).
Frame 5: brief hold, eyes blink.
Frame 6: exhale begins, shoulders ease down.
Frame 7: exhale continues, chest lowers.
Frame 8: lowest relaxed pose.
Frame 9: tiny cloth/equipment settle.
Frame 10: back to the exact frame 1 neutral stance for a seamless loop.`;
  return `Intended use:
Create a 10-frame 5x2 spritesheet for a top-down 2D game character ${acao} animation.

Input images:
Image 1 is the identity anchor for ${nome}. Preserve the exact character identity, outfit, proportions, prop placement, silhouette, palette, and SOUTH-facing direction.
Image 2 is the 5x2 spritesheet layout/style guide. Use it only as a layout guide for ten equal cells across a 1280x512 sheet.

Primary request:
Generate ${nome} performing a SOUTH-facing ${acao} loop. The character faces SOUTH, directly toward the camera, in every frame. The feet stay on a stable, identical ground baseline in every cell.

Canvas and layout:
- 1280x512 PNG spritesheet
- 5 columns by 2 rows
- ten equal 256x256 cells
- frame order left to right across top row, then left to right across bottom row
- character fully visible in each cell, including both feet
- consistent character scale, camera, and ground baseline across all frames
- solid ${chroma} chroma background filling every cell outside the character silhouette

Frame sequence:
${seq}

Style:
- same art style as Image 1: ${styleBlock(estilo)}
- crisp edges, chunky readable silhouette
- no motion blur

Avoid:
- photorealism, painterly blending, anti-aliased halos
- extra characters, scenery, borders, grid lines, cell numbers, text, watermark
- changing the background color between cells`;
}

function pick(models: GenModelInfo[], prefs: string[]): string {
  for (const p of prefs) if (models.some((m) => m.slug === p)) return p;
  return models[0]?.slug ?? "";
}

// Rótulo do motor no seletor (padrão das abas Imagem/Vídeo: ORIGEM · nome real · custo).
//
// A origem entrou em 2026-08-01: esta aba escolhe motor em TRÊS passos (âncora, clipe, prancha) e
// cada um pode cair numa conta diferente — nuvem cobra crédito por peça, assinatura consome a
// assinatura, ComfyUI só gasta GPU. Sem o nome na frente, os três passos pareciam a mesma coisa e
// não dava pra organizar a criação por bolso. Vem de lib/motor.ts (mesmo vocabulário do app);
// `marcaMotor` devolve só o ícone quando o campo `origem` não veio (cliente, white-label #6).
function rotuloModelo(m: GenModelInfo): string {
  const custo = m.cost_credits != null ? ` · ${m.cost_credits} cr` : "";

  return `${marcaMotor(m)} · ${nomeModelo(m)}${custo}${m.is_unstable ? " ⚠️" : ""}`;
}

export function MotorLocal() {
  const toast = useToast();
  // 🟢 Luz do Estúdio Local: os seletores de motor oferecem img-local-* — a bolinha conta se
  // o ComfyUI está de pé antes do clique.
  const comfy = useComfy();

  const [imgModels, setImgModels] = useState<GenModelInfo[]>([]);
  const [vidModels, setVidModels] = useState<GenModelInfo[]>([]);
  // Motor de CADA passo é escolha sua (CLI local, KIE, Estúdio Local…); os defaults são o
  // caminho validado do pipeline. Preenchidos quando o catálogo chega.
  const [modeloAncora, setModeloAncora] = useState("");
  const [modeloClipe, setModeloClipe] = useState("");
  const [modeloPrancha, setModeloPrancha] = useState("");

  // Etapa 1 — âncora
  const [nome, setNome] = useState("");
  const [desc, setDesc] = useState("");
  const [chroma, setChroma] = useState("#FF00FF");
  const [estilo, setEstilo] = useState("pixel-16bit"); // bloco Style: dos prompts (catálogo gameAssets)
  const [framePx, setFramePx] = useState(256); // frame lógico (prompt) + célula da sheet final (`cell`)
  const [ancora, setAncora] = useState<string | null>(null);
  const [ancoraBusy, setAncoraBusy] = useState(false);

  // Etapa 2 — movimento: por VÍDEO (i2v, obrigatório pra walk) ou por PRANCHA 5×2
  // (image-gen direto — "gero vários sprites e animo na Unity", sem vídeo no meio).
  const [fonte, setFonte] = useState<"video" | "prancha">("video");
  const [acao, setAcao] = useState<Acao>("walk");
  const [clipe, setClipe] = useState<string | null>(null);
  const [clipeBusy, setClipeBusy] = useState(false);
  const [prancha, setPrancha] = useState<string | null>(null);
  const [pranchaBusy, setPranchaBusy] = useState(false);
  const [elapsed, setElapsed] = useState(0);
  const timer = useRef<ReturnType<typeof setInterval> | null>(null);

  // Etapa 3 — frames + seleção
  const [frames, setFrames] = useState<{ token: string; count: number; cols: number; rows: number; contact_url: string } | null>(null);
  const [framesBusy, setFramesBusy] = useState(false);
  const [sel, setSel] = useState<number[]>([]);

  // Etapa 4 — resultado
  const [fps, setFps] = useState(10);
  const [normBusy, setNormBusy] = useState(false);
  const [resultado, setResultado] = useState<{ sheet_url?: string; preview_url?: string; manifest_url?: string } | null>(null);

  useEffect(() => {
    Console.imageModels().then((r) => {
      // utilitários (mapa PBR/refino/conserto) não são motor de âncora/prancha
      const list = (r.data ?? []).filter((m) => !m.utility);
      setImgModels(list);
      setModeloAncora(pick(list, IMAGE_PREF));
      setModeloPrancha(pick(list.filter((m) => (m.refs_max ?? 0) >= 2), SHEET_PREF));
    }).catch(() => {});
    Console.videoModels().then((r) => {
      const list = r.data ?? [];
      setVidModels(list);
      setModeloClipe(pick(list.filter((m) => (m.refs_max ?? 0) >= 1 && !m.premium), VIDEO_PREF));
    }).catch(() => {});
  }, []);

  useEffect(() => {
    if (clipeBusy) {
      setElapsed(0);
      timer.current = setInterval(() => setElapsed((s) => s + 1), 1000);
    } else if (timer.current) {
      clearInterval(timer.current);
      timer.current = null;
    }
    return () => {
      if (timer.current) clearInterval(timer.current);
    };
  }, [clipeBusy]);

  async function gerarAncora() {
    if (!nome.trim() || !desc.trim()) {
      toast.err("Dê um nome e descreva o personagem.");
      return;
    }
    setAncoraBusy(true);
    setResultado(null);
    try {
      // Motor sem suporte a referência (ex.: CLI local): a âncora sai só do prompt —
      // funciona, mas o grid ref é o que segura a disciplina de pixel (aviso no seletor).
      const aceitaRef = (imgModels.find((m) => m.slug === modeloAncora)?.refs_max ?? 0) >= 1;
      const r = await Engine.image({
        prompt: anchorPrompt(nome.trim(), desc.trim(), chroma, estilo, framePx),
        model: modeloAncora || pick(imgModels, IMAGE_PREF),
        imageUrls: aceitaRef ? [GRID_REF] : undefined,
        aspect: "1:1",
      });
      if (!r?.url) {
        toast.err("A geração não retornou a âncora.");
        return;
      }
      setAncora(r.url);
      setClipe(null);
      setFrames(null);
      setSel([]);
      toast.ok("Âncora pronta — confira o personagem e o fundo chroma.");
    } catch (e) {
      toast.err(e instanceof ApiError ? e.message : "Erro ao gerar a âncora.");
    } finally {
      setAncoraBusy(false);
    }
  }

  async function gerarClipe() {
    if (!ancora) return;
    setClipeBusy(true);
    setFrames(null);
    setSel([]);
    setResultado(null);
    try {
      const r = await Engine.video({
        prompt: motionPrompt(acao, chroma),
        imageUrl: ancora,
        model: modeloClipe || pick(vidModels, VIDEO_PREF),
        aspect: "1:1",
        duration: "5",
      });
      if (!r?.url) {
        toast.err("A geração não retornou o clipe.");
        return;
      }
      setClipe(r.url);
      toast.ok("Clipe pronto — extraia os frames e escolha o loop.");
    } catch (e) {
      toast.err(e instanceof ApiError ? e.message : "Erro ao gerar o clipe.");
    } finally {
      setClipeBusy(false);
    }
  }

  // Prancha 5×2: as 10 poses numa imagem só (2 refs: âncora + guia de layout).
  async function gerarPrancha() {
    if (!ancora) return;
    const a = acao === "walk" ? "idle" : acao; // walk é do caminho de vídeo (regra do pipeline)
    setPranchaBusy(true);
    setSel([]);
    setResultado(null);
    try {
      const r = await Engine.image({
        prompt: poseSheetPrompt(a, nome.trim() || "the character", chroma, estilo),
        model: modeloPrancha || pick(imgModels, SHEET_PREF),
        imageUrls: [ancora, SHEET_GUIDE],
        aspect: "21:9",
      });
      if (!r?.url) {
        toast.err("A geração não retornou a prancha.");
        return;
      }
      setPrancha(r.url);
      toast.ok("Prancha pronta — confira as 10 poses e recorte.");
    } catch (e) {
      toast.err(e instanceof ApiError ? e.message : "Erro ao gerar a prancha.");
    } finally {
      setPranchaBusy(false);
    }
  }

  async function extrairFrames() {
    if (!clipe) return;
    setFramesBusy(true);
    setSel([]);
    setResultado(null);
    try {
      const r = await Sprite.frames(clipe);
      if (!r.token || !r.contact_url) {
        toast.err("A extração não retornou os frames.");
        return;
      }
      setFrames({ token: r.token, count: r.count ?? 0, cols: r.cols ?? 8, rows: r.rows ?? 1, contact_url: r.contact_url });
      toast.ok(`${r.count} frames — clique nos que formam UM ciclo completo, na ordem.`);
    } catch (e) {
      toast.err(e instanceof ApiError ? e.message : "Erro ao extrair os frames.");
    } finally {
      setFramesBusy(false);
    }
  }

  function toggleFrame(idx: number) {
    setSel((prev) => (prev.includes(idx) ? prev.filter((x) => x !== idx) : prev.length >= 16 ? prev : [...prev, idx]));
  }

  async function normalizar() {
    const daPrancha = fonte === "prancha";
    if (daPrancha ? !prancha : !frames || sel.length < 2) {
      toast.err(daPrancha ? "Gere a prancha primeiro." : "Escolha pelo menos 2 frames (ideal: 8-12 de um ciclo completo).");
      return;
    }
    setNormBusy(true);
    try {
      const r = await Sprite.normalize(
        daPrancha
          ? { sheet_url: prancha!, cols: 5, rows: 2, frames: sel.length ? sel : undefined, chroma, cell: framePx, fps, name: nome.trim() }
          : { token: frames!.token, frames: sel, chroma, cell: framePx, fps, name: nome.trim() },
      );
      if (!r.sheet_url) {
        toast.err("A normalização não retornou a spritesheet.");
        return;
      }
      setResultado(r);
      toast.ok("Spritesheet pronta — salva na Galeria.");
    } catch (e) {
      if (e instanceof ApiError && e.status === 410) {
        setFrames(null);
        setSel([]);
        toast.err("Os frames expiraram — extraia de novo.");
      } else {
        toast.err(e instanceof ApiError ? e.message : "Erro ao normalizar.");
      }
    } finally {
      setNormBusy(false);
    }
  }

  return (
    <>
      {/* ETAPA 1 — ÂNCORA (o frame canônico: tudo deriva dele) */}
      <div className="card" style={{ marginBottom: 16 }}>
        <div className="body" style={{ gap: 12 }}>
          <span style={etapaTitle}><Wand2 size={16} /> 1 · Âncora do personagem <span style={capText}>(sul, o frame canônico)</span><span style={{ marginLeft: "auto" }}><ComfyStatus info={comfy} /></span></span>
          <div style={{ display: "flex", gap: 10, flexWrap: "wrap" }}>
            <label style={{ ...fieldLabel, flex: "0 1 220px" }}>
              <span style={capText}>Nome</span>
              <input value={nome} onChange={(e) => setNome(e.target.value)} disabled={ancoraBusy} placeholder="ex.: raposa-capuz" style={inputStyle} />
            </label>
            <label style={{ ...fieldLabel, flex: "1 1 300px" }}>
              <span style={capText}>Descrição (arquétipo, roupa, adereços)</span>
              <input value={desc} onChange={(e) => setDesc(e.target.value)} disabled={ancoraBusy} placeholder="ex.: a chubby red fox knight, yellow hood, leather boots, small sword on the belt" style={inputStyle} />
            </label>
            <label style={fieldLabel}>
              <span style={capText}>Estilo</span>
              <select value={estilo} onChange={(e) => setEstilo(e.target.value)} disabled={ancoraBusy} style={selectStyle}
                title="Estilo visual do sprite — vale pra âncora e pra prancha (o clipe i2v preserva o estilo da âncora).">
                {ESTILOS.map(([v, l]) => (
                  <option key={v} value={v}>{l}</option>
                ))}
              </select>
            </label>
            <label style={fieldLabel}>
              <span style={capText}>Frame</span>
              <select value={String(framePx)} onChange={(e) => setFramePx(Number(e.target.value))} disabled={ancoraBusy} style={selectStyle}
                title="Tamanho lógico do frame — régua de leitura do sprite e tamanho real de cada célula na spritesheet final.">
                {FRAMES_PX.map(([v, l]) => (
                  <option key={v} value={v}>{l}</option>
                ))}
              </select>
            </label>
            <label style={fieldLabel}>
              <span style={capText}>Fundo de recorte</span>
              <select value={chroma} onChange={(e) => setChroma(e.target.value)} disabled={ancoraBusy} style={selectStyle}
                title="Cor chapada que o recorte remove depois. Magenta salvo se o personagem for rosa/magenta.">
                {CHROMAS.map(([v, l]) => (
                  <option key={v} value={v}>{l}</option>
                ))}
              </select>
            </label>
            {imgModels.length > 0 && (
              <label style={fieldLabel}>
                <span style={capText}>Motor</span>
                <select value={modeloAncora} onChange={(e) => setModeloAncora(e.target.value)} disabled={ancoraBusy} style={selectStyle}
                  title="Qual IA gera a âncora: KIE, CLI local ou Estúdio Local. Motor sem suporte a referência gera só do prompt (perde a disciplina do grid).">
                  {imgModels.map((m) => (
                    <option key={m.slug} value={m.slug}>
                      {rotuloModelo(m)}{(m.refs_max ?? 0) < 1 ? " · sem ref" : ""}
                    </option>
                  ))}
                </select>
              </label>
            )}
            <button type="button" className="btn ok" onClick={gerarAncora} disabled={ancoraBusy}
              style={{ alignSelf: "flex-end", display: "flex", alignItems: "center", gap: 7, padding: "10px 18px" }}>
              {ancoraBusy ? <span className="spinner" style={{ width: 14, height: 14 }} /> : <Wand2 size={15} />}
              {ancora ? "Regerar âncora" : "Gerar âncora"}
            </button>
          </div>
          {ancora && (
            /* eslint-disable-next-line @next/next/no-img-element */
            <img src={ancora} alt="âncora" style={{ width: 200, height: 200, objectFit: "contain", imageRendering: "pixelated", borderRadius: 10, border: "1px solid var(--line2)", background: "var(--bg2)" }} />
          )}
        </div>
      </div>

      {/* ETAPA 2 — MOVIMENTO: vídeo i2v (walk exige) ou prancha 5×2 (poses direto por imagem) */}
      {ancora && (
        <div className="card" style={{ marginBottom: 16 }}>
          <div className="body" style={{ gap: 12 }}>
            <span style={etapaTitle}><Clapperboard size={16} /> 2 · Movimento</span>
            <div style={{ display: "flex", gap: 8 }}>
              {([["video", "🎬 Por vídeo (i2v)"], ["prancha", "🖼️ Prancha de poses 5×2"]] as const).map(([v, l]) => (
                <button key={v} type="button" onClick={() => { setFonte(v); setSel([]); setResultado(null); if (v === "prancha" && acao === "walk") setAcao("idle"); }}
                  title={v === "prancha" ? "As 10 poses numa imagem só — sem vídeo no meio. Caminhada fica de fora (por imagem o ciclo não fecha; é o caso do vídeo)." : "O clipe é só andaime interno pra tirar poses consistentes — o produto continua sendo os sprites."}
                  style={{ padding: "6px 14px", borderRadius: 999, fontSize: ".82rem", cursor: "pointer", border: `1px solid ${fonte === v ? "var(--red)" : "var(--line2)"}`, background: fonte === v ? "rgba(226,74,49,.16)" : "var(--bg2)", color: fonte === v ? "var(--text)" : "var(--muted)" }}>
                  {l}
                </button>
              ))}
            </div>
            <div style={{ display: "flex", gap: 10, alignItems: "flex-end", flexWrap: "wrap" }}>
              <label style={fieldLabel}>
                <span style={capText}>Ação</span>
                <select value={acao} onChange={(e) => setAcao(e.target.value as Acao)} disabled={clipeBusy || pranchaBusy} style={selectStyle}>
                  {ACOES.filter(([v]) => fonte === "video" || v !== "walk").map(([v, l]) => (
                    <option key={v} value={v}>{l}</option>
                  ))}
                </select>
              </label>
              {fonte === "video" ? (
                <>
                  <label style={fieldLabel}>
                    <span style={capText}>Motor do clipe</span>
                    <select value={modeloClipe} onChange={(e) => setModeloClipe(e.target.value)} disabled={clipeBusy} style={selectStyle}
                      title="Qual IA anima a âncora (i2v). Só motores que aceitam imagem-base.">
                      {vidModels.filter((m) => (m.refs_max ?? 0) >= 1 && !m.premium).map((m) => (
                        <option key={m.slug} value={m.slug}>{rotuloModelo(m)}</option>
                      ))}
                    </select>
                  </label>
                  <button type="button" className="btn ok" onClick={gerarClipe} disabled={clipeBusy}
                    style={{ display: "flex", alignItems: "center", gap: 7, padding: "10px 18px" }}>
                    {clipeBusy ? <span className="spinner" style={{ width: 14, height: 14 }} /> : <Clapperboard size={15} />}
                    {clipe ? "Regerar clipe" : "Gerar clipe (~1-2 min)"}
                  </button>
                  {clipeBusy && <span style={capText}>Gerando… {elapsed}s</span>}
                </>
              ) : (
                <>
                  <label style={fieldLabel}>
                    <span style={capText}>Motor da prancha</span>
                    <select value={modeloPrancha} onChange={(e) => setModeloPrancha(e.target.value)} disabled={pranchaBusy} style={selectStyle}
                      title="Qual IA desenha as 10 poses. A prancha precisa de 2 referências (âncora + guia) — só motores que aceitam.">
                      {imgModels.filter((m) => (m.refs_max ?? 0) >= 2).map((m) => (
                        <option key={m.slug} value={m.slug}>{rotuloModelo(m)}</option>
                      ))}
                    </select>
                  </label>
                  <button type="button" className="btn ok" onClick={gerarPrancha} disabled={pranchaBusy}
                    style={{ display: "flex", alignItems: "center", gap: 7, padding: "10px 18px" }}>
                    {pranchaBusy ? <span className="spinner" style={{ width: 14, height: 14 }} /> : <Clapperboard size={15} />}
                    {prancha ? "Regerar prancha" : "Gerar prancha 5×2"}
                  </button>
                </>
              )}
              <span style={capText}>Direção: sul (de frente) — direções novas pedem âncoras direcionais, próxima iteração.</span>
            </div>
            {fonte === "video" && clipe && (
              <video src={clipe} controls autoPlay loop muted playsInline style={{ width: 280, borderRadius: 10, border: "1px solid var(--line2)", background: "#000" }} />
            )}
          </div>
        </div>
      )}

      {/* ETAPA 3 (prancha) — conferir/selecionar células da prancha (vazio = todas as 10) */}
      {fonte === "prancha" && prancha && (
        <div className="card" style={{ marginBottom: 16 }}>
          <div className="body" style={{ gap: 12 }}>
            <span style={etapaTitle}><Grid3x3 size={16} /> 3 · Conferir as poses <span style={capText}>(clique pra escolher; nada marcado = todas as 10)</span></span>
            <div style={{ position: "relative", width: "100%" }}>
              {/* eslint-disable-next-line @next/next/no-img-element */}
              <img src={prancha} alt="prancha" style={{ width: "100%", display: "block", borderRadius: 8, imageRendering: "pixelated" }} />
              <div style={{ position: "absolute", inset: 0, display: "grid", gridTemplateColumns: "repeat(5, 1fr)", gridTemplateRows: "repeat(2, 1fr)" }}>
                {Array.from({ length: 10 }).map((_, i) => {
                  const idx = i + 1;
                  const ord = sel.indexOf(idx);
                  return (
                    <button key={idx} type="button" onClick={() => toggleFrame(idx)} title={`pose ${idx}`}
                      style={{ position: "relative", border: ord >= 0 ? "2px solid var(--red)" : "1px solid transparent", background: ord >= 0 ? "rgba(226,74,49,.22)" : "transparent", cursor: "pointer", padding: 0 }}>
                      {ord >= 0 && (
                        <span style={{ position: "absolute", top: 2, right: 2, minWidth: 18, height: 18, borderRadius: 999, background: "var(--red)", color: "#fff", fontSize: ".68rem", fontWeight: 700, display: "inline-flex", alignItems: "center", justifyContent: "center", padding: "0 4px" }}>
                          {ord + 1}
                        </span>
                      )}
                    </button>
                  );
                })}
              </div>
            </div>
          </div>
        </div>
      )}

      {/* ETAPA 3 (vídeo) — FRAMES + SELEÇÃO DO LOOP */}
      {fonte === "video" && clipe && (
        <div className="card" style={{ marginBottom: 16 }}>
          <div className="body" style={{ gap: 12 }}>
            <span style={etapaTitle}><Grid3x3 size={16} /> 3 · Escolher os frames do ciclo</span>
            {!frames ? (
              <button type="button" className="btn ok" onClick={extrairFrames} disabled={framesBusy}
                style={{ alignSelf: "flex-start", display: "flex", alignItems: "center", gap: 7, padding: "10px 18px" }}>
                {framesBusy ? <span className="spinner" style={{ width: 14, height: 14 }} /> : <Grid3x3 size={15} />}
                Extrair frames
              </button>
            ) : (
              <>
                <span style={capText}>
                  Ache um pose neutra, siga até ela repetir (= um ciclo) e clique 8-12 frames espaçados por igual, NA ORDEM.
                  Selecionados: {sel.length ? sel.join(" → ") : "nenhum"}
                  {sel.length > 0 && (
                    <button type="button" onClick={() => setSel([])} style={{ marginLeft: 8, background: "none", border: "none", color: "var(--red)", cursor: "pointer", fontSize: ".78rem" }}>
                      limpar
                    </button>
                  )}
                </span>
                <div style={{ position: "relative", width: "100%" }}>
                  {/* eslint-disable-next-line @next/next/no-img-element */}
                  <img src={frames.contact_url} alt="frames" style={{ width: "100%", display: "block", borderRadius: 8 }} />
                  <div style={{ position: "absolute", inset: 0, display: "grid", gridTemplateColumns: `repeat(${frames.cols}, 1fr)`, gridTemplateRows: `repeat(${frames.rows}, 1fr)` }}>
                    {Array.from({ length: frames.count }).map((_, i) => {
                      const idx = i + 1;
                      const ord = sel.indexOf(idx);
                      return (
                        <button
                          key={idx}
                          type="button"
                          onClick={() => toggleFrame(idx)}
                          title={`frame ${idx}`}
                          style={{
                            position: "relative",
                            border: ord >= 0 ? "2px solid var(--red)" : "1px solid transparent",
                            background: ord >= 0 ? "rgba(226,74,49,.22)" : "transparent",
                            cursor: "pointer",
                            padding: 0,
                          }}
                        >
                          {ord >= 0 && (
                            <span style={{ position: "absolute", top: 2, right: 2, minWidth: 18, height: 18, borderRadius: 999, background: "var(--red)", color: "#fff", fontSize: ".68rem", fontWeight: 700, display: "inline-flex", alignItems: "center", justifyContent: "center", padding: "0 4px" }}>
                              {ord + 1}
                            </span>
                          )}
                        </button>
                      );
                    })}
                  </div>
                </div>
              </>
            )}
          </div>
        </div>
      )}

      {/* ETAPA 4 — NORMALIZAR + SPRITESHEET */}
      {((fonte === "video" && frames && sel.length >= 2) || (fonte === "prancha" && prancha)) && (
        <div className="card" style={{ marginBottom: 16 }}>
          <div className="body" style={{ gap: 12 }}>
            <span style={etapaTitle}><Scissors size={16} /> 4 · Spritesheet</span>
            <div style={{ display: "flex", gap: 10, alignItems: "flex-end", flexWrap: "wrap" }}>
              <label style={fieldLabel}>
                <span style={capText}>Velocidade do loop</span>
                <select value={String(fps)} onChange={(e) => setFps(Number(e.target.value))} disabled={normBusy} style={selectStyle}>
                  <option value="8">8 fps</option>
                  <option value="10">10 fps</option>
                  <option value="12">12 fps</option>
                </select>
              </label>
              <button type="button" className="btn ok" onClick={normalizar} disabled={normBusy}
                style={{ display: "flex", alignItems: "center", gap: 7, padding: "10px 18px" }}>
                {normBusy ? <span className="spinner" style={{ width: 14, height: 14 }} /> : <Scissors size={15} />}
                Recortar e montar ({fonte === "prancha" && sel.length === 0 ? 10 : sel.length} poses)
              </button>
              <span style={capText}>Chroma key {chroma} → recorte pela silhueta real → pés na mesma linha → strip {framePx}px.</span>
            </div>
            {resultado && (
              <div style={{ display: "flex", flexDirection: "column", gap: 10 }}>
                <div style={{ display: "flex", gap: 14, alignItems: "flex-start", flexWrap: "wrap" }}>
                  {resultado.preview_url && (
                    <figure style={{ margin: 0, textAlign: "center" }}>
                      {/* eslint-disable-next-line @next/next/no-img-element */}
                      <img src={resultado.preview_url} alt="preview" style={{ height: 160, imageRendering: "pixelated", borderRadius: 10, border: "1px solid var(--line2)" }} />
                      <figcaption style={{ ...capText, fontSize: ".72rem" }}>preview do loop</figcaption>
                    </figure>
                  )}
                  <div style={{ flex: "1 1 320px", overflowX: "auto", border: "1px solid var(--line2)", borderRadius: 10, background: "var(--bg2)" }}>
                    {/* eslint-disable-next-line @next/next/no-img-element */}
                    {resultado.sheet_url && <img src={resultado.sheet_url} alt="spritesheet" style={{ height: 160, imageRendering: "pixelated", display: "block" }} />}
                  </div>
                </div>
                <div style={{ display: "flex", gap: 8, flexWrap: "wrap", alignItems: "center" }}>
                  <span style={{ ...capText, color: "var(--text)", display: "inline-flex", alignItems: "center", gap: 5 }}>
                    <Check size={14} /> Salvo na Galeria
                  </span>
                  {resultado.sheet_url && (
                    <a className="btn" href={resultado.sheet_url} target="_blank" rel="noreferrer" style={{ fontSize: ".8rem", textDecoration: "none" }}>spritesheet.png</a>
                  )}
                  {resultado.manifest_url && (
                    <a className="btn" href={resultado.manifest_url} target="_blank" rel="noreferrer" style={{ fontSize: ".8rem", textDecoration: "none" }}>manifest.json</a>
                  )}
                  <button type="button" className="btn" onClick={() => { setClipe(null); setPrancha(null); setFrames(null); setSel([]); setResultado(null); }}
                    style={{ display: "flex", alignItems: "center", gap: 6, fontSize: ".8rem" }}>
                    <RotateCcw size={13} /> Outra ação com a mesma âncora
                  </button>
                </div>
              </div>
            )}
          </div>
        </div>
      )}
    </>
  );
}
