"use client";

// 🧊 Aba 3D (grupo Games) — o acervo de MALHAS do estúdio num lugar só.
// A malha NASCE AQUI desde 2026-07-31: o botão "Gerar malha (Estúdio)" a produz a partir
// da imagem-base, no mesmo ComfyUI que já serve imagem e vídeo. (Não roda neste Mac —
// é CUDA —, por isso o Estúdio é remoto.) O upload continua valendo para malha feita
// fora ou modelada no Blender. Aqui você: vê quem tem malha, gera/sobe/troca,
// inspeciona no viewer interativo e gera a ÂNCORA DE ÂNGULO (render WebGL no
// enquadramento pedido → PNG na Galeria) — a mesma âncora que a Montagem usa por
// plano, e que serve de direção pro ControlNet (img-local-pose) e pros sprites
// direcionais (oeste/norte = geometria, não adivinhação).

import { useCallback, useEffect, useRef, useState } from "react";
import { useToast } from "@/components/ui/Toast";
import { sfetch, Console } from "@/lib/api";
import { renderMalha } from "@/lib/renderMalha";
import { Viewer3D } from "@/components/Viewer3D";
import { Box, Upload, Camera, Users, Package, RotateCw, Compass, Trash2 } from "lucide-react";

type Asset = {
  tipo: "character" | "element";
  id: number;
  nome: string;
  thumb: string | null;
  mesh: boolean;
  // Estado da geração ASSÍNCRONA da malha, vindo do banco: "gerando" | "erro" | "aviso" | null.
  // A geração leva ~5 minutos de GPU; o navegador não espera isso numa requisição HTTP (o clique
  // virava HTTP 499 — conexão fechada pelo cliente), então quem manda no botão é este campo.
  meshStatus: string | null;
  // O MOTIVO por trás do status: a ressalva do juiz ('aviso') ou a causa da falha ('erro').
  // Sem ele o alerta é oco — "falhou, tente de novo" faz o usuário reclicar num problema que
  // muitas vezes está no DADO (descrição × imagem-base que não combinam), não na geração.
  meshMsg: string | null;
};

// Ângulos/alturas: as MESMAS chaves do camera3d (cameraDoPlano) — o render daqui tem
// que falar a língua da decupagem, senão a âncora avulsa diverge da âncora do plano.
const ANGULOS: [string, string][] = [
  ["frontal", "Frente"],
  ["tres_quartos", "Três quartos"],
  ["lateral", "Perfil"],
  ["costas", "Costas"],
];
const ALTURAS: [string, string][] = [
  ["olhos", "Altura dos olhos"],
  ["alto", "Câmera alta"],
  ["joelho", "Câmera baixa"],
];
const ENQUADRAMENTOS: [string, string][] = [
  ["figura", "Corpo inteiro"],
  ["conjunto", "Aberto"],
  ["medio", "Meio corpo"],
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
const capText: React.CSSProperties = { color: "var(--muted)", fontSize: ".78rem" };
// Motivo único do gate das receitas 3D (tooltip + legenda): fala de LUGAR/capacidade, nunca do
// nome do programa que renderiza por baixo — white-label (#6), vocabulário de lib/motor.ts.
const ESTUDIO_3D_OFF =
  "Indisponível agora: o render 3D roda no seu estúdio local, que não está respondendo. Ligue o estúdio e recarregue esta página.";
const fieldLabel: React.CSSProperties = { display: "flex", flexDirection: "column", gap: 5 };

export default function TresDPage() {
  const toast = useToast();
  // Trava do polling: sair da aba no meio de uma geração não pode deixar um laço de fetch vivo.
  const vivo = useRef(true);
  useEffect(() => () => { vivo.current = false; }, []);
  const [assets, setAssets] = useState<Asset[]>([]);
  const [sel, setSel] = useState<Asset | null>(null);
  const [subindo, setSubindo] = useState(false);
  const [gerandoMalha, setGerandoMalha] = useState(false);
  const [excluindoMalha, setExcluindoMalha] = useState(false);
  // O gerador de malha existe NESTE Estúdio? O ComfyUI pode estar de pé sem o modelo de malha
  // (é o estado de toda sessão nova do Colab). Sem isto o botão apareceria e falharia depois de
  // minutos de espera — o oposto de uma interface honesta.
  const [temGerador, setTemGerador] = useState(false);
  // Render (blender-bridge) é capacidade SEPARADA do gerador de malha: desde 2026-08-01 o
  // bridge roda em container e funciona em produção, enquanto o gerador segue exigindo
  // COMFY_URL. Gatear o render pelo sinal do gerador desabilitava um recurso que funciona.
  const [temRender, setTemRender] = useState(false);
  // render de âncora
  const [angulo, setAngulo] = useState("frontal");
  const [altura, setAltura] = useState("olhos");
  const [enq, setEnq] = useState("figura");
  const [aspect, setAspect] = useState("1:1");
  const [rendendo, setRendendo] = useState(false);
  const [ancora, setAncora] = useState<string | null>(null);
  // 🎛️ Estúdio 3D (blender-bridge no host): receitas por botão — Fase 2 do docs/ESTUDIO-3D.md.
  const [receitaBusy, setReceitaBusy] = useState<"" | "turntable" | "direcoes">("");
  const [receitaFiles, setReceitaFiles] = useState<{ name: string; url: string; mime: string }[]>([]);

  const carregar = useCallback(async () => {
    try {
      const [rc, re] = await Promise.all([sfetch("/api/characters"), sfetch("/api/elements")]);
      const jc = await rc.json().catch(() => []);
      const je = await re.json().catch(() => []);
      type Row = { id: number; name: string; mesh_url?: string | null; mesh_status?: string | null; mesh_msg?: string | null };
      const chars = (Array.isArray(jc) ? jc : jc?.data ?? []) as (Row & { base_url?: string | null })[];
      const elems = (Array.isArray(je) ? je : je?.data ?? []) as (Row & { image_url?: string | null })[];
      const lista: Asset[] = [
        ...chars.map((c) => ({ tipo: "character" as const, id: c.id, nome: c.name, thumb: c.base_url ?? null, mesh: !!c.mesh_url, meshStatus: c.mesh_status ?? null, meshMsg: c.mesh_msg ?? null })),
        ...elems.map((e) => ({ tipo: "element" as const, id: e.id, nome: e.name, thumb: e.image_url ?? null, mesh: !!e.mesh_url, meshStatus: e.mesh_status ?? null, meshMsg: e.mesh_msg ?? null })),
      ];
      // Quem tem malha vem primeiro — a aba é sobre 3D, não sobre o cadastro.
      lista.sort((a, b) => Number(b.mesh) - Number(a.mesh));
      setAssets(lista);
      setSel((prev) => (prev ? lista.find((a) => a.tipo === prev.tipo && a.id === prev.id) ?? null : null));
      return lista;
    } catch {
      /* console fora do ar: a lista fica vazia e o vazio orienta */
      return null;
    }
  }, []);

  useEffect(() => {
    carregar();
    // O gerador de malha só aparece se ESTE Estúdio o tiver instalado. Nunca derruba a aba:
    // falhou a consulta, o botão simplesmente não existe e o upload segue como sempre.
    sfetch("/api/mesh-health")
      .then((r) => r.json())
      .then((d) => { setTemGerador(!!d?.ok); setTemRender(!!d?.render); })
      .catch(() => setTemGerador(false));
  }, [carregar]);

  // 🔁 POLLING da malha — o único jeito de saber que terminou.
  // A geração leva minutos de GPU (medido em produção: ~285s). Enquanto ela era feita DENTRO da
  // requisição HTTP, o navegador desistia no meio e o servidor registrava HTTP 499 (conexão
  // fechada pelo cliente): a malha até saía, mas a tela nunca ficava sabendo. Agora o POST só
  // enfileira e volta na hora; quem descobre o fim é este laço, relendo o `mesh_status` do asset.
  const aguardarMalha = useCallback(async (a: Asset): Promise<string | null> => {
    // 150 × 5s = 12,5 min de teto — larga folga sobre os ~5 min do pior caso medido. Se estourar,
    // o servidor destrava o asset sozinho (auto-heal de 30 min) e o botão volta a funcionar.
    for (let n = 0; n < 150 && vivo.current; n++) {
      await new Promise((r) => setTimeout(r, 5000));
      const lista = await carregar();
      const at = lista?.find((x) => x.tipo === a.tipo && x.id === a.id);
      if (!at) return "sumiu";
      // null = pronta (é o estado limpo no banco); "erro"/"aviso" falam por si.
      if (at.meshStatus !== "gerando") return at.meshStatus;
    }
    return "timeout";
  }, [carregar]);

  // Retoma o acompanhamento de qualquer malha que ficou gerando de uma sessão anterior (recarregou
  // a página, trocou de aba). A fila continua rodando no servidor — a tela é que precisa reencontrá-la.
  const retomado = useRef(false);
  useEffect(() => {
    if (retomado.current || assets.length === 0) return;
    const pendente = assets.find((a) => a.meshStatus === "gerando");
    if (!pendente) return;
    retomado.current = true;
    // Não mexe no flag local de propósito: quem trava o botão nesse caso é o `mesh_status` do
    // próprio asset (estado do servidor). O laço só precisa manter a lista fresca até acabar.
    void aguardarMalha(pendente);
  }, [assets, aguardarMalha]);

  async function subirMalha(a: Asset, f: File) {
    setSubindo(true);
    try {
      const fd = new FormData();
      fd.append("tipo", a.tipo);
      fd.append("id", String(a.id));
      fd.append("file", f);
      const r = await sfetch("/api/mesh-upload", { method: "POST", body: fd });
      const d = await r.json().catch(() => ({}));
      if (!r.ok || !d?.ok) {
        toast.err(d?.error || "Não foi possível subir a malha.");
        return;
      }
      toast.ok("Malha no ar — inspecione no viewer.");
      await carregar();
      setSel({ ...a, mesh: true });
    } finally {
      setSubindo(false);
    }
  }

  // 🧊 GERAR a malha no Estúdio (Hunyuan3D, nós nativos do ComfyUI) a partir da imagem do asset —
  // antes a geometria só entrava por upload, feita fora. Leva minutos de GPU e não custa
  // crédito. A malha gerada entra pelo MESMO caminho do upload, então viewer, âncora de ângulo
  // e exportação Unity funcionam sem saber de onde ela veio.
  async function gerarMalha(a: Asset) {
    if (!a.thumb) {
      toast.err("Este item ainda não tem imagem — gere ou escolha uma antes de virar objeto 3D.");

      return;
    }
    setGerandoMalha(true);
    try {
      const r = await sfetch("/api/mesh-generate", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ tipo: a.tipo, id: a.id, imageUrl: a.thumb }),
      });
      const d = await r.json().catch(() => ({}));
      if (!r.ok || !d?.ok) {
        toast.err(d?.error || "A geração de malha falhou.");

        return;
      }
      toast.ok("Na fila do Estúdio — leva alguns minutos. Pode deixar a aba aberta.");
      await carregar();

      const fim = await aguardarMalha(a);
      if (fim === "erro") {
        toast.err("A geração de malha falhou no Estúdio — tente de novo ou suba um .glb.");
      } else if (fim === "aviso") {
        // O juiz de visão do engine entregou a malha COM ressalva (pedestal, parte faltando):
        // ela está no viewer e a decisão de regerar é sua.
        toast.err("Malha pronta, mas com ressalva do controle de qualidade — confira no viewer.");
      } else if (fim === "timeout" || fim === "sumiu") {
        toast.err("A malha ainda está sendo gerada — recarregue a página em alguns minutos.");
      } else {
        toast.ok("Malha pronta — inspecione no viewer.");
      }
    } finally {
      setGerandoMalha(false);
    }
  }

  // 🗑️ Excluir a malha — malha que saiu errada (caso real 2026-07-31: anatomia trocada, o
  // gerador inventa o lado que a imagem não mostra) não pode ficar presa no asset até outra
  // ser gerada por cima. Limpa só os ponteiros (o arquivo fica no storage); o card volta a
  // "sem malha" e Gerar/Subir recomeçam do zero.
  async function excluirMalha(a: Asset) {
    if (!confirm(`Excluir a malha de "${a.nome}"? Dá pra gerar ou subir outra depois.`)) return;
    setExcluindoMalha(true);
    try {
      const r = await sfetch(`/api/mesh/${a.tipo}/${a.id}`, { method: "DELETE" });
      const d = await r.json().catch(() => ({}));
      if (!r.ok || !d?.ok) {
        toast.err(d?.error || "Não foi possível excluir a malha.");
        return;
      }
      toast.ok("Malha excluída — gere ou suba outra quando quiser.");
      setAncora(null);
      setReceitaFiles([]);
      await carregar();
      setSel({ ...a, mesh: false });
    } finally {
      setExcluindoMalha(false);
    }
  }

  // Âncora de ângulo avulsa: renderiza a malha no enquadramento pedido e salva o PNG na
  // Galeria (vira referência de geração — pose/ângulo por geometria).
  async function gerarAncora() {
    if (!sel?.mesh) return;
    setRendendo(true);
    setAncora(null);
    try {
      const rm = await sfetch(`/api/mesh/${sel.tipo}/${sel.id}`);
      if (!rm.ok) {
        toast.err("Não foi possível ler a malha.");
        return;
      }
      const { blob } = await renderMalha(await rm.arrayBuffer(), { angulo, altura, enquadramento: enq }, aspect);
      const file = new File([blob], `ancora-${sel.nome}-${angulo}.png`, { type: "image/png" });
      const up = await Console.upload(file);
      const url = up.media?.[0]?.url;
      if (!up.ok || !url) {
        toast.err(up.error || "O render saiu, mas não foi possível salvar na Galeria.");
        return;
      }
      setAncora(url);
      toast.ok("Âncora na Galeria — use como referência em qualquer geração.");
    } catch {
      toast.err("Falha ao renderizar a âncora.");
    } finally {
      setRendendo(false);
    }
  }

  // Receita 3D no servidor (Blender headless no host, via console→engine→bridge): turntable
  // gira o asset num mp4; direções renderiza as 8 vistas com alpha nativo (âncoras de sprite
  // SEM chroma key). Resultado persiste no Scality e entra na Galeria sozinho.
  async function receita3d(receita: "turntable" | "direcoes") {
    if (!sel?.mesh || receitaBusy) return;
    setReceitaBusy(receita);
    setReceitaFiles([]);
    try {
      const r = await sfetch(`/api/mesh/${sel.tipo}/${sel.id}/render`, {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ receita }),
      });
      const d = await r.json().catch(() => ({}));
      if (!r.ok || !d?.ok) {
        toast.err(d?.error || "O render 3D falhou.");
        return;
      }
      setReceitaFiles(Array.isArray(d.files) ? d.files : []);
      toast.ok(receita === "turntable" ? "Turntable na Galeria!" : "8 direções na Galeria!");
    } catch {
      toast.err("Erro de rede no render 3D.");
    } finally {
      setReceitaBusy("");
    }
  }

  return (
    <>
      <h1 className="h1">3D</h1>
      <p className="sub">
        As malhas dos seus personagens e elementos: <strong>gere no Estúdio</strong> a partir da
        imagem-base (ou suba um <code>.glb</code> feito fora), inspecione no viewer e gere âncoras
        de ângulo — o render exato que guia a geração de imagem, os planos da Montagem e os
        sprites direcionais.
      </p>

      {assets.length === 0 && (
        <p style={capText}>
          Nenhum personagem ou elemento ainda — crie em <a href="/personagens" style={{ color: "var(--text)" }}>Personagens</a>{" "}
          ou <a href="/elementos" style={{ color: "var(--text)" }}>Elementos</a> e volte aqui pra dar corpo 3D a eles. 🧊
        </p>
      )}

      {/* ACERVO — cards; quem tem malha vem primeiro */}
      <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fill, minmax(150px, 1fr))", gap: 12, marginBottom: 20 }}>
        {assets.map((a) => (
          <button
            key={`${a.tipo}-${a.id}`}
            type="button"
            onClick={() => { setSel(a); setAncora(null); }}
            style={{
              textAlign: "left",
              padding: 10,
              borderRadius: 12,
              cursor: "pointer",
              border: `1px solid ${sel?.tipo === a.tipo && sel?.id === a.id ? "var(--red)" : "var(--line2)"}`,
              background: "var(--panel)",
              display: "flex",
              flexDirection: "column",
              gap: 8,
            }}
          >
            {a.thumb ? (
              /* eslint-disable-next-line @next/next/no-img-element */
              <img src={a.thumb} alt={a.nome} style={{ width: "100%", aspectRatio: "1", objectFit: "cover", borderRadius: 8, background: "var(--bg2)" }} />
            ) : (
              <div style={{ width: "100%", aspectRatio: "1", borderRadius: 8, background: "var(--bg2)", display: "flex", alignItems: "center", justifyContent: "center", color: "var(--muted)" }}>
                {a.tipo === "character" ? <Users size={28} /> : <Package size={28} />}
              </div>
            )}
            <span style={{ fontSize: ".84rem", fontWeight: 600, display: "flex", alignItems: "center", gap: 6 }}>
              {a.mesh && <Box size={13} style={{ color: "var(--green, #7dc98f)", flex: "none" }} />}
              <span style={{ overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap" }}>{a.nome}</span>
            </span>
            <span style={{ ...capText, fontSize: ".72rem" }}>
              {a.tipo === "character" ? "personagem" : "elemento"} ·{" "}
              {a.meshStatus === "gerando" ? "gerando malha…" : a.mesh ? "com malha" : "sem malha"}
            </span>
          </button>
        ))}
      </div>

      {/* ASSET SELECIONADO — upload + viewer + âncora de ângulo */}
      {sel && (
        <div className="card" style={{ marginBottom: 20 }}>
          <div className="body" style={{ gap: 14 }}>
            <div style={{ display: "flex", alignItems: "center", gap: 10, flexWrap: "wrap" }}>
              <strong style={{ fontSize: "1rem" }}>🧊 {sel.nome}</strong>
              {/* 🎛️ Gerar no Estúdio: a geometria nasce da imagem do próprio asset, sem sair
                  daqui e sem crédito. Só aparece com o gerador instalado no servidor, e só
                  habilita com imagem — o gerador parte de uma. */}
              {temGerador && (() => {
                // "Gerando" vem do BANCO (`mesh_status`), não de um flag local: a fila continua
                // rodando mesmo se a página for recarregada ou aberta noutra aba, e o botão tem
                // que refletir isso — travado até o worker terminar.
                const naFila = gerandoMalha || sel.meshStatus === "gerando";

                return (
                  <button
                    type="button"
                    className="btn ok"
                    onClick={() => sel && gerarMalha(sel)}
                    disabled={naFila || subindo || !sel.thumb}
                    title={!sel.thumb
                      ? "Este item ainda não tem imagem — gere uma antes de virar objeto 3D."
                      : "Gera a malha no SEU servidor a partir da imagem deste item. Leva alguns minutos de GPU e não gasta crédito."}
                    style={{ display: "inline-flex", alignItems: "center", gap: 6, fontSize: ".82rem", padding: "7px 13px" }}
                  >
                    {naFila ? <span className="spinner" style={{ width: 13, height: 13 }} /> : <Box size={14} />}
                    {naFila ? "Gerando malha…" : sel.mesh ? "Gerar malha de novo" : "Gerar malha (Estúdio)"}
                  </button>
                );
              })()}
              {sel.meshStatus === "gerando" && (
                <span style={capText}>
                  No Estúdio agora — leva alguns minutos. Pode navegar; ao voltar aqui a tela reencontra a geração.
                </span>
              )}
              {sel.meshStatus === "erro" && (
                <span style={{ ...capText, color: "var(--red)" }}>
                  {/* O motivo do juiz, quando existe, SUBSTITUI a frase genérica: ele diz o que
                      fazer ("a descrição e a imagem-base precisam combinar"), e a genérica não. */}
                  {sel.meshMsg || "A última geração de malha falhou."} Tente de novo ou suba um <code>.glb</code>.
                </span>
              )}
              {sel.meshStatus === "aviso" && sel.meshMsg && (
                <span style={{ ...capText, color: "var(--amber, #b45309)" }}>
                  Malha entregue com ressalva: {sel.meshMsg}
                </span>
              )}
              <label className="btn" style={{ display: "inline-flex", alignItems: "center", gap: 6, fontSize: ".82rem", cursor: subindo ? "wait" : "pointer", padding: "7px 13px" }}
                title="GLB é o formato que os geradores exportam e o navegador lê direto — até 80MB, textura embutida vale">
                <Upload size={14} /> {subindo ? "Enviando…" : sel.mesh ? "Trocar malha (.glb)" : "Subir malha (.glb)"}
                <input
                  type="file"
                  accept=".glb,model/gltf-binary"
                  disabled={subindo}
                  style={{ display: "none" }}
                  onChange={(e) => {
                    const f = e.target.files?.[0];
                    e.currentTarget.value = "";
                    if (f) subirMalha(sel, f);
                  }}
                />
              </label>
              {sel.mesh && (
                <button
                  type="button"
                  className="btn no"
                  onClick={() => sel && excluirMalha(sel)}
                  disabled={excluindoMalha || subindo || gerandoMalha}
                  title="Tira a malha deste item (o arquivo continua no storage). Use quando a malha saiu errada — dá pra gerar ou subir outra depois."
                  style={{ display: "inline-flex", alignItems: "center", gap: 6, fontSize: ".82rem", padding: "7px 13px", flex: "none" }}
                >
                  {excluindoMalha ? <span className="spinner" style={{ width: 13, height: 13 }} /> : <Trash2 size={14} />}
                  Excluir malha
                </button>
              )}
            </div>

            {sel.mesh ? (
              <>
                <Viewer3D meshPath={`${sel.tipo}/${sel.id}`} />
                <span style={capText}>Arraste pra orbitar · scroll pra zoom · botão direito pra transladar.</span>

                {/* Âncora de ângulo — mesma língua da decupagem (camera3d) */}
                <div style={{ display: "flex", gap: 10, alignItems: "flex-end", flexWrap: "wrap", borderTop: "1px solid var(--line2)", paddingTop: 12 }}>
                  <label style={fieldLabel}>
                    <span style={capText}>Ângulo</span>
                    <select value={angulo} onChange={(e) => setAngulo(e.target.value)} style={selectStyle}>
                      {ANGULOS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                    </select>
                  </label>
                  <label style={fieldLabel}>
                    <span style={capText}>Altura da câmera</span>
                    <select value={altura} onChange={(e) => setAltura(e.target.value)} style={selectStyle}>
                      {ALTURAS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                    </select>
                  </label>
                  <label style={fieldLabel}>
                    <span style={capText}>Enquadramento</span>
                    <select value={enq} onChange={(e) => setEnq(e.target.value)} style={selectStyle}>
                      {ENQUADRAMENTOS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                    </select>
                  </label>
                  <label style={fieldLabel}>
                    <span style={capText}>Formato</span>
                    <select value={aspect} onChange={(e) => setAspect(e.target.value)} style={selectStyle}>
                      <option value="1:1">1:1</option>
                      <option value="16:9">16:9</option>
                      <option value="9:16">9:16</option>
                    </select>
                  </label>
                  <button type="button" className="btn ok" onClick={gerarAncora} disabled={rendendo}
                    style={{ display: "flex", alignItems: "center", gap: 7, padding: "10px 18px" }}>
                    {rendendo ? <span className="spinner" style={{ width: 14, height: 14 }} /> : <Camera size={15} />}
                    Âncora de ângulo → Galeria
                  </button>
                </div>

                {ancora && (
                  <figure style={{ margin: 0 }}>
                    {/* eslint-disable-next-line @next/next/no-img-element */}
                    <img src={ancora} alt="âncora" style={{ maxHeight: 240, borderRadius: 10, border: "1px solid var(--line2)", background: "var(--bg2)" }} />
                    <figcaption style={{ ...capText, marginTop: 5 }}>
                      Na Galeria — sirva como referência de pose/ângulo em Imagem, Montagem ou Sprites.
                    </figcaption>
                  </figure>
                )}

                {/* 🎛️ Estúdio 3D — receitas do renderizador headless que roda no host (bridge):
                    render de VERDADE (luz de estúdio, alpha nativo), sem abrir programa nenhum.

                    GATE (2026-08-01): estas duas receitas dependem do bridge de render, que até
                    hoje só existia como processo no Mac do operador — em produção elas falhavam
                    100% das vezes, sempre com o clique já dado. O bridge virou container do stack
                    e o `/v1/mesh/health` passou a responder `render` (bridge) separado de `ok`
                    (gerador de malha), que são capacidades independentes. Gateamos por `temRender`
                    e, em vez de ESCONDER a seção, mantemos ela visível com os botões DESABILITADOS
                    e o motivo escrito ao lado — esconder apagaria uma capacidade real do produto
                    para quem tem o estúdio ligado, e o objetivo aqui é só não deixar clicar no que
                    falha. Quando o gerador responde mas o bridge está fora, o erro do POST ainda é a
                    última rede de proteção. */}
                <div style={{ display: "flex", gap: 10, alignItems: "center", flexWrap: "wrap", borderTop: "1px solid var(--line2)", paddingTop: 12 }}>
                  <span style={{ ...capText, color: "var(--text)", fontWeight: 600 }}>Estúdio 3D</span>
                  <button type="button" className="btn" onClick={() => receita3d("turntable")} disabled={!!receitaBusy || !temRender}
                    title={temRender
                      ? "Giro 360° do asset num vídeo curto — apresentação, sem gastar crédito, no seu estúdio 3D"
                      : ESTUDIO_3D_OFF}
                    style={{ display: "flex", alignItems: "center", gap: 7, padding: "9px 15px" }}>
                    {receitaBusy === "turntable" ? <span className="spinner" style={{ width: 14, height: 14 }} /> : <RotateCw size={15} />}
                    Turntable → Galeria
                  </button>
                  <button type="button" className="btn" onClick={() => receita3d("direcoes")} disabled={!!receitaBusy || !temRender}
                    title={temRender
                      ? "8 vistas (sul→sudeste) com fundo transparente NATIVO — âncoras direcionais de sprite sem chroma key"
                      : ESTUDIO_3D_OFF}
                    style={{ display: "flex", alignItems: "center", gap: 7, padding: "9px 15px" }}>
                    {receitaBusy === "direcoes" ? <span className="spinner" style={{ width: 14, height: 14 }} /> : <Compass size={15} />}
                    8 direções → Galeria
                  </button>
                  {receitaBusy && <span style={capText}>Renderizando no seu estúdio 3D… (segundos a ~1 min)</span>}
                  {!temRender && !receitaBusy && <span style={capText}>{ESTUDIO_3D_OFF}</span>}
                </div>

                {receitaFiles.length > 0 && (
                  <div style={{ display: "flex", flexDirection: "column", gap: 8 }}>
                    {receitaFiles[0]?.mime === "video/mp4" ? (
                      <video src={receitaFiles[0].url} controls autoPlay loop playsInline
                        style={{ maxHeight: 300, borderRadius: 10, border: "1px solid var(--line2)", background: "#000", alignSelf: "flex-start" }} />
                    ) : (
                      <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fill, minmax(96px, 1fr))", gap: 8 }}>
                        {receitaFiles.map((f) => (
                          <figure key={f.url} style={{ margin: 0 }}>
                            {/* eslint-disable-next-line @next/next/no-img-element */}
                            <img src={f.url} alt={f.name} style={{ width: "100%", aspectRatio: "1", objectFit: "contain", borderRadius: 8, border: "1px solid var(--line2)", background: "var(--bg2)" }} />
                            <figcaption style={{ ...capText, fontSize: ".68rem", textAlign: "center" }}>{f.name.replace(".png", "")}</figcaption>
                          </figure>
                        ))}
                      </div>
                    )}
                    <span style={capText}>Tudo na Galeria — as direções servem de âncora nos Sprites e nas gerações 2D.</span>
                  </div>
                )}
              </>
            ) : (
              <span style={capText}>
                Este {sel.tipo === "character" ? "personagem" : "elemento"} ainda não tem malha.
                Gere no Estúdio a partir da imagem-base, ou suba um <code>.glb</code> feito fora.
              </span>
            )}
          </div>
        </div>
      )}
    </>
  );
}
