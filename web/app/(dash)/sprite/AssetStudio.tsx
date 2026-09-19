"use client";

// 🎮 ASSETS DE JOGO — terceiro modo da aba Sprites: gera as OUTRAS categorias de asset
// (tileset, background parallax, textura seamless, GUI, ícones, props), não só personagem.
// Catálogo de tipos/estilos/tamanhos em lib/gameAssets.ts (derivado do acervo Craftpix +
// convenções craftpix.net/itch.io/Unity). Uma geração por clique via Engine.image (síncrono,
// mesmo canal do Motor local); salvar anexa na Galeria via /api/sprite/asset-gallery.
// A prévia do seamless é um MOSAICO 3×3 (background-repeat) — a costura aparece na hora,
// sem depender de fé no prompt.

import { useEffect, useState } from "react";
import { useToast } from "@/components/ui/Toast";
import { Engine, Console, Sprite, ApiError, type GenModelInfo } from "@/lib/api";
import { Wand2, Save, Check, Grid3x3 } from "lucide-react";
import { ComfyStatus, useComfy } from "@/components/ComfyStatus";
import { marcaMotor, nomeModelo } from "@/lib/motor";
import { ASSET_KINDS, GAME_STYLE_LABELS, assetPrompt, type AssetKind } from "@/lib/gameAssets";

const IMAGE_PREF = ["img-pro", "img-criativo", "img-ultra"]; // prancha/tileset pedem disciplina de layout → pro primeiro

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

function pick(models: GenModelInfo[], prefs: string[]): string {
  for (const p of prefs) if (models.some((m) => m.slug === p)) return p;
  return models[0]?.slug ?? "";
}

function rotuloModelo(m: GenModelInfo): string {
  const custo = m.cost_credits != null ? ` · ${m.cost_credits} cr` : "";
  return `${marcaMotor(m)} · ${nomeModelo(m)}${custo}${m.is_unstable ? " ⚠️" : ""}`;
}

export function AssetStudio() {
  const toast = useToast();
  const comfy = useComfy();

  const [imgModels, setImgModels] = useState<GenModelInfo[]>([]);
  const [modelo, setModelo] = useState("");

  const [kind, setKind] = useState<AssetKind>("tileset-platformer");
  const [tema, setTema] = useState("");
  const [estilo, setEstilo] = useState("vetor-cartoon");
  const [tamanho, setTamanho] = useState<number>(ASSET_KINDS["tileset-platformer"].sizes[1][0]);
  const [seamless, setSeamless] = useState(false);

  const [busy, setBusy] = useState(false);
  const [resultado, setResultado] = useState<string | null>(null);
  const [salvando, setSalvando] = useState(false);
  const [salvo, setSalvo] = useState(false);

  const info = ASSET_KINDS[kind];
  // seamless: "on" = sempre ligado (textura) · "off" = não se aplica · opt/x = escolha
  const seamlessAtivo = info.seamless === "on" || (seamless && info.seamless !== "off");

  useEffect(() => {
    Console.imageModels().then((r) => {
      const list = (r.data ?? []).filter((m) => !m.utility);
      setImgModels(list);
      setModelo(pick(list, IMAGE_PREF));
    }).catch(() => {});
  }, []);

  function trocarTipo(k: AssetKind) {
    setKind(k);
    const sizes = ASSET_KINDS[k].sizes;
    setTamanho((sizes[1] ?? sizes[0])[0]);
    setSeamless(false);
    setResultado(null);
    setSalvo(false);
  }

  async function gerar() {
    if (!tema.trim()) {
      toast.err("Descreva o tema do asset (ex.: floresta de outono, caverna de lava, espada élfica).");
      return;
    }
    setBusy(true);
    setResultado(null);
    setSalvo(false);
    try {
      const r = await Engine.image({
        prompt: assetPrompt(kind, tema.trim(), estilo, tamanho, seamlessAtivo),
        model: modelo || pick(imgModels, IMAGE_PREF),
        aspect: info.aspect,
      });
      if (!r?.url) {
        toast.err("A geração não retornou o asset.");
        return;
      }
      setResultado(r.url);
      toast.ok(seamlessAtivo ? "Asset pronto — confira a costura no mosaico." : "Asset pronto.");
    } catch (e) {
      toast.err(e instanceof ApiError ? e.message : "Erro ao gerar o asset.");
    } finally {
      setBusy(false);
    }
  }

  async function salvar() {
    if (!resultado) return;
    setSalvando(true);
    try {
      await Sprite.assetGallery(resultado, tema.trim().slice(0, 50) || info.label);
      setSalvo(true);
      toast.ok("Salvo no acervo — o asset está na Galeria.");
    } catch (e) {
      toast.err(e instanceof ApiError ? e.message : "Erro ao salvar na Galeria.");
    } finally {
      setSalvando(false);
    }
  }

  return (
    <>
      <div className="card" style={{ marginBottom: 16 }}>
        <div className="body" style={{ gap: 12 }}>
          <span style={{ fontSize: ".95rem", fontWeight: 700, display: "flex", alignItems: "center", gap: 8 }}>
            <Wand2 size={16} /> O que gerar
            <span style={{ marginLeft: "auto" }}><ComfyStatus info={comfy} /></span>
          </span>

          {/* Tipo — as categorias de marketplace (Craftpix/itch.io/Unity) */}
          <div style={{ display: "flex", gap: 8, flexWrap: "wrap" }}>
            {(Object.entries(ASSET_KINDS) as [AssetKind, (typeof ASSET_KINDS)[AssetKind]][]).map(([k, v]) => (
              <button key={k} type="button" onClick={() => trocarTipo(k)} disabled={busy} title={v.hint}
                style={{
                  padding: "6px 13px", borderRadius: 999, fontSize: ".82rem", cursor: "pointer",
                  border: `1px solid ${kind === k ? "var(--red)" : "var(--line2)"}`,
                  background: kind === k ? "rgba(226,74,49,.16)" : "var(--bg2)",
                  color: kind === k ? "var(--text)" : "var(--muted)",
                }}>
                {v.label}
              </button>
            ))}
          </div>
          <span style={capText}>{info.hint}</span>

          <div style={{ display: "flex", gap: 10, alignItems: "flex-end", flexWrap: "wrap" }}>
            <label style={{ ...fieldLabel, flex: "1 1 280px" }}>
              <span style={capText}>Tema (bioma, material, conjunto…)</span>
              <input value={tema} onChange={(e) => setTema(e.target.value)} disabled={busy}
                placeholder="ex.: floresta de outono com folhas caídas / metal enferrujado / poções de alquimia" style={inputStyle} />
            </label>
            <label style={fieldLabel}>
              <span style={capText}>Estilo visual</span>
              <select value={estilo} onChange={(e) => setEstilo(e.target.value)} disabled={busy} style={selectStyle}>
                {GAME_STYLE_LABELS.map(([v, l]) => (
                  <option key={v} value={v}>{l}</option>
                ))}
              </select>
            </label>
            <label style={fieldLabel}>
              <span style={capText}>{info.sizeLabel}</span>
              <select value={String(tamanho)} onChange={(e) => setTamanho(Number(e.target.value))} disabled={busy} style={selectStyle}
                title="Tamanho lógico do asset — entra no prompt como régua de leitura (tile/ícone/base).">
                {info.sizes.map(([v, l]) => (
                  <option key={v} value={v}>{l}</option>
                ))}
              </select>
            </label>
            {info.seamless !== "off" && (
              <label style={{ ...fieldLabel, alignSelf: "center", flexDirection: "row", alignItems: "center", gap: 7 }}
                title={info.seamless === "x"
                  ? "Loop horizontal: a borda esquerda continua na direita — pra rolagem infinita."
                  : "Repete sem costura: bordas opostas casam pixel a pixel nos dois eixos."}>
                <input type="checkbox" checked={seamlessAtivo} disabled={busy || info.seamless === "on"}
                  onChange={(e) => setSeamless(e.target.checked)} />
                <span style={{ fontSize: ".86rem" }}>🔁 Seamless{info.seamless === "x" ? " (horizontal)" : ""}</span>
              </label>
            )}
            {imgModels.length > 0 && (
              <label style={fieldLabel}>
                <span style={capText}>Motor</span>
                <select value={modelo} onChange={(e) => setModelo(e.target.value)} disabled={busy} style={selectStyle}>
                  {imgModels.map((m) => (
                    <option key={m.slug} value={m.slug}>{rotuloModelo(m)}</option>
                  ))}
                </select>
              </label>
            )}
            <button type="button" className="btn ok" onClick={gerar} disabled={busy}
              style={{ display: "flex", alignItems: "center", gap: 7, padding: "10px 18px" }}>
              {busy ? <span className="spinner" style={{ width: 14, height: 14 }} /> : <Wand2 size={15} />}
              {resultado ? "Regerar asset" : "Gerar asset"}
            </button>
          </div>
        </div>
      </div>

      {resultado && (
        <div className="card" style={{ marginBottom: 16 }}>
          <div className="body" style={{ gap: 12 }}>
            <span style={{ fontSize: ".95rem", fontWeight: 700, display: "flex", alignItems: "center", gap: 8 }}>
              <Grid3x3 size={16} /> Resultado
            </span>
            <div style={{ display: "flex", gap: 14, flexWrap: "wrap", alignItems: "flex-start" }}>
              <figure style={{ margin: 0, flex: "1 1 320px", maxWidth: 560 }}>
                {/* eslint-disable-next-line @next/next/no-img-element */}
                <img src={resultado} alt="asset" style={{ width: "100%", display: "block", imageRendering: estilo.startsWith("pixel") ? "pixelated" : "auto", borderRadius: 10, border: "1px solid var(--line2)", background: "var(--bg2)" }} />
                <figcaption style={{ ...capText, fontSize: ".72rem", textAlign: "center" }}>asset</figcaption>
              </figure>
              {seamlessAtivo && (
                <figure style={{ margin: 0, flex: "1 1 320px", maxWidth: 560 }}>
                  {/* Mosaico 3×3: a prova visual do seamless — costura ruim salta aos olhos aqui. */}
                  <div style={{
                    width: "100%", aspectRatio: info.seamless === "x" ? "3 / 1" : "1 / 1",
                    backgroundImage: `url(${resultado})`,
                    backgroundSize: info.seamless === "x" ? "auto 100%" : "33.34% 33.34%",
                    backgroundRepeat: info.seamless === "x" ? "repeat-x" : "repeat",
                    borderRadius: 10, border: "1px solid var(--line2)",
                    imageRendering: estilo.startsWith("pixel") ? "pixelated" : "auto",
                  }} />
                  <figcaption style={{ ...capText, fontSize: ".72rem", textAlign: "center" }}>
                    prévia repetida — a costura tem que sumir
                  </figcaption>
                </figure>
              )}
            </div>
            <div style={{ display: "flex", gap: 8, alignItems: "center", flexWrap: "wrap" }}>
              {salvo ? (
                <span style={{ ...capText, color: "var(--text)", display: "inline-flex", alignItems: "center", gap: 5 }}>
                  <Check size={14} /> Salvo na Galeria
                </span>
              ) : (
                <button type="button" className="btn ok" onClick={salvar} disabled={salvando}
                  style={{ display: "flex", alignItems: "center", gap: 6, fontSize: ".82rem" }}>
                  {salvando ? <span className="spinner" style={{ width: 12, height: 12 }} /> : <Save size={14} />}
                  Salvar no acervo
                </button>
              )}
              <a className="btn" href={resultado} target="_blank" rel="noreferrer" style={{ fontSize: ".8rem", textDecoration: "none" }}>abrir arquivo</a>
            </div>
          </div>
        </div>
      )}
    </>
  );
}
