"use client";

// 🎬 ABA VÍDEO — a aba ÚNICA de vídeo do estúdio (Vídeo + Vox unificadas em 2026-08-06).
//
// A aba Vox absorveu a aba Vídeo — e não o contrário. O layout que ficou é o do Vox (painel de
// controles + STORYBOARD de beats editáveis em cartões, cena a cena, com timestamps, contador de
// caracteres, player por cena, montador e exportação) porque é ele que põe a revisão no lado
// BARATO: o roteiro custa uma chamada de texto, a peça custa uma imagem + um clipe POR cena.
// A caixa "um prompt → um clipe" da aba Vídeo antiga entregava o vídeo pronto sem nenhum ponto de
// conferência no meio — a peça ruim só se descobria assistindo, depois de paga.
//
// O Vox deixou de ser aba e virou um ESTILO ("📰 Vox — colagem de papel"), com TODA a doutrina do
// formato intacta (preset vox no backend, voxImageDirective/voxMotionPrompt, legenda tipográfica,
// régua de caracteres, motor próprio). Nos outros estilos o pipeline é o GENÉRICO que a aba Vídeo
// sempre usou: sem preset, com o modelo/persona/cor/personagem escolhidos aqui.
//
// Fluxo: Conteúdo → "Gerar Storyboard" (texto, barato, editável) → cena a cena OU "Gerar Filme
// Completo" (o caro). O resultado cai na Galeria.
//
// ⚠️ Precedente seguido: a aba Movies absorvendo a aba Filme (a69638e) — a rota velha vira
// redirect 307 (/vox → /video), o componente legado é DELETADO e o SideNav fica com um item só.

import { useEffect, useRef, useState } from "react";
import { Console, sfetch, type GenModelInfo } from "@/lib/api";
import { useJobs } from "@/lib/jobs";
import { SceneCard, type VoxBeat } from "@/components/video/SceneCard";
import { cartaoGrade } from "@/components/roteiro/cartaoCena";
import { EscolherImagem } from "@/components/EscolherImagem";
import { EstiloLegenda, useEstiloLegenda, legendaPayload } from "@/components/EstiloLegenda";
import { ajudaMotor, marcaMotor, nomeModelo, rotuloMotor } from "@/lib/motor";

// Um capítulo do roteiro, como o engine devolve e aceita de volta (/v1/beats). Mesmo shape que o
// StudioController::voxBeatsFrom aceita — campo novo do beat entra LÁ também, sempre.
// `script` é a frase FALADA; `image_prompt`/`image_prompt_b` são as DUAS artes do capítulo
// (cada metade da frase ganha a sua); `sfx` volta intacto (acabamento, não roteiro).
// `clip_url` (V2) é o clipe JÁ GERADO desta cena — vem do servidor (story.vox.beats), nunca é
// enviado de volta como campo editável; editar script/ilustração invalida o clipe no servidor.
// O TIPO mora no cartão (SceneCard), que é quem o edita campo a campo.

// 🎙️ Uma voz do provedor, como GET /api/studio/voices devolve (engine /v1/voices → speech.Voice).
type Voz = { id: string; name: string; gender?: string; accent?: string; language?: string; category?: string };

// Rótulo do narrador na lista: nome + o que distingue uma voz da outra (gênero/sotaque).
const rotuloVoz = (v: Voz) => {
  const extra = [v.gender, v.accent].map((s) => (s || "").trim()).filter(Boolean).join(" · ");
  return extra ? `${v.name} (${extra})` : v.name;
};

// Mesmo draft compartilhado do Studio: a peça do Vox continua anexando ao rascunho corrente,
// como fazia quando era card — mudar isso agora quebraria o caminho galeria→legenda→publicação.
const DRAFT_KEY = "reachyn_draft";

// ── Presets das três seções opcionais ────────────────────────────────────────────────────────
// O item "(padrão)" tem valor VAZIO de propósito: vazio = o padrão Vox do engine, intacto.
// Escolher um preset só PREENCHE o campo de texto — o cliente pode editar por cima; o que vai
// pro servidor é sempre o texto do campo, não o preset.
//
// Estilo e Direção vão em INGLÊS porque apendam em prompts de imagem/i2v calibrados em inglês
// (voxImageDirective/voxMotionPrompt); o Roteiro vai em PT-BR porque apenda no prompt de
// segmentação, que é escrito em PT-BR.
const PRESETS_ROTEIRO: [string, string][] = [
  ["Vox clássico (padrão)", ""],
  ["Comercial 15s", "Estruture como um COMERCIAL de 15 segundos: beat 1 = GANCHO de ~2s (uma imagem/número que para o dedo); depois o PROBLEMA (a dor concreta de quem assiste); a SOLUÇÃO (o produto/ideia entrando como resposta direta); a PROVA (número, comparação ou demonstração concreta); e o ÚLTIMO beat = CTA claro e único (uma ação só, dita sem rodeio)."],
];
const PRESETS_ESTILO: [string, string][] = [
  ["Colagem de papel (padrão)", ""],
  ["Paper diorama sépia", "Sepia-toned paper diorama worlds built from aged newspaper: cut-out figures with black censor bars over the eyes, letterpress typography texture on objects, warm sepia and ink-black palette, vintage archival newsprint atmosphere."],
];
const PRESETS_DIRECAO: [string, string][] = [
  ["Stop-motion flat lay (padrão)", ""],
  ["Documentário observacional", "Observational documentary pacing: slow, smooth dolly-like drift of the paper elements, long contemplative takes, gentle and unhurried motion — nothing snaps or pops, everything glides."],
];

// 🎨 ESTILO da peça — o seletor que unificou as duas abas.
//
// "vox" é o PRESET editorial (colagem de papel, motor próprio, segmentação própria, tipografia
// própria); as outras chaves são as EXATAS que o engine reconhece em content.videoStyleDirective
// — fora desta lista o engine não aplica diretriz nenhuma, então o seletor não inventa opção.
const ESTILO_VOX = "vox";
const ESTILOS: [string, string][] = [
  [ESTILO_VOX, "📰 Vox — colagem de papel (jornalismo explicativo)"],
  ["", "Sem estilo"],
  ["cinematografico", "Cinematográfico"],
  ["dinamico", "Dinâmico"],
  ["documental", "Documental"],
  ["slowmotion", "Câmera lenta"],
  ["aereo", "Aéreo (drone)"],
  ["timelapse", "Timelapse"],
  ["noir", "Noir"],
  ["vintage", "Vintage"],
  ["cyberpunk", "Cyberpunk"],
  ["anime", "Anime"],
  ["3d", "3D animado"],
  ["vlog", "Vlog (POV)"],
];

// Persona de vídeo = direção de estilo escrita por você na aba Prompts (kind=video). Veio da aba
// Vídeo antiga junto com o seletor de Cor (presets "🎨 Cor:" do catálogo de imagem).
type Persona = { id: number; title: string; content: string };

// ⏱️ Duração de UMA cena (s). O Vox é 6 por DOUTRINA — mas aceita 8 desde 2026-08-30, que é o
// beat do Vox Factory (a ferramenta do Google Labs Flow que a casa usa): o teto de 90 caracteres
// de lá sai da MESMA régua de 12,2 c/s, então um roteiro escrito lá cabe aqui sem truncar. Nos
// outros estilos quem manda é o seletor (5|10), o mesmo da aba Vídeo antiga. Os timestamps dos
// cartões derivam daqui.
const DUR_VOX = 6;
const DURACOES_VOX = ["6", "8"];

// 📏 RÉGUA DE LOCUÇÃO — espelha `charsPerSec`/`narrationRespiro`/`beatScriptSize` do engine
// (engine/internal/content/longform.go). MEDIDO em locução PT-BR: ~12,2 caracteres por segundo.
// A cena tem `dur` segundos, menos ~0,6s de respiro (entrada/saída) ⇒ nos 6s do Vox dá 65
// caracteres. Passou disso, a frase NÃO cabe no clipe e o áudio termina cortado no meio.
//
// ⚠️ O engine CORTA o excesso (rede de baixo, determinística) e o StudioController RECUSA a
// montagem com fala maior que o clipe. Esta tela existe pra o usuário não descobrir isso pelo
// vídeo pronto: ele edita o script aqui, então ele vê o contador aqui.
// Mudou a régua no Go? Muda aqui e no PHP junto — são as três metades da mesma verdade.
const CHARS_POR_SEG = 12.2;
const RESPIRO_SEG = 0.6;
const tetoChars = (dur: number) => Math.max(0, Math.floor((dur - RESPIRO_SEG) * CHARS_POR_SEG));

// Tamanho do script de um beat, medido como o TTS mede (sem espaço em branco nas pontas).
const tamScript = (s: string) => (s || "").trim().length;

// mm:ss a partir de segundos (o filme Vox nunca passa de minutos — hh não existe no formato).
const mmss = (s: number) => `${String(Math.floor(s / 60)).padStart(2, "0")}:${String(Math.floor(s % 60)).padStart(2, "0")}`;

// Intervalo [início–fim] do beat `i` na peça (ordem × duração da cena).
const intervalo = (i: number, dur: number) => `${mmss(i * dur)}–${mmss((i + 1) * dur)}`;

// 📋 SRT → lista de falas, na ordem. O bloco de legenda é `n / hh:mm:ss,mmm --> hh:mm:ss,mmm /
// texto (1+ linhas)`, separado por linha em branco. Só o TEXTO interessa aqui: os tempos do
// arquivo externo são do vídeo de origem, e na montagem quem manda é o início da CENA (o
// ffmpeg-service ancora cada fala em anchor_voice_starts). Fora do formato, cai no TXT.
function parseSRT(txt: string): string[] {
  return txt
    .replace(/\r/g, "")
    .split(/\n\s*\n/)
    .map((bloco) =>
      bloco
        .split("\n")
        .filter((l) => !/^\d+$/.test(l.trim()) && !l.includes("-->"))
        .join(" ")
        .trim(),
    )
    .filter(Boolean);
}

// Rótulos das redes — mesmo dicionário do compositor NovoPost (Publicar).
const PLATFORM_LABEL: Record<string, string> = {
  linkedin: "LinkedIn", instagram: "Instagram", facebook: "Facebook",
  x: "X / Twitter", twitter: "X / Twitter", youtube: "YouTube", threads: "Threads", tiktok: "TikTok",
  pinterest: "Pinterest", reddit: "Reddit", bluesky: "Bluesky", googlebusiness: "Google Business", blog: "Blog",
};

// ── Design system da casa (app/globals.css) ──────────────────────────────────────────────────
// Esta tela nasceu com estilos inline próprios (moldura de card copiada na mão, borda azul
// #38bdf8, aviso de saldo em rgba vermelho solto, campos com `background: transparent` e
// `var(--fg)` — token que NEM EXISTE). Resultado: a /vox não parecia irmã da /galeria ou da
// /publicar. Agora a casca vem das CLASSES prontas (.card/.body/.acts, .btn/.btn.ok/.btn.edit,
// .chip, .err, .empty, .txt, .h1/.sub) e o que sobra de inline usa SÓ tokens — zero hex cru.
//
// `inp` é o mesmo campo do compositor de posts (components/publicar/NovoPost.tsx): o app não tem
// classe de formulário no globals.css, e repetir a MESMA constante é o padrão já adotado nas
// telas do (dash). `sel`/`ta` são ele com o ajuste do controle.
const inp = { width: "100%", background: "var(--bg2)", color: "var(--text)", border: "1px solid var(--line)", borderRadius: 8, padding: "9px 11px", fontSize: ".9rem" } as const;
const sel = { ...inp, width: "auto" } as const;
const ta = { ...inp, resize: "vertical" } as const;
const lbl = { color: "var(--muted)", fontSize: ".78rem" } as const;
// Área de arrastar/escolher arquivo — mesma do NovoPost (tokens, sem hex).
const dropzone = { display: "flex", flexDirection: "column", alignItems: "center", justifyContent: "center", gap: 7, padding: "26px 18px", border: "1px dashed var(--line2)", borderRadius: 12, background: "var(--bg2)", cursor: "pointer", textAlign: "center", transition: ".16s" } as const;

// Uma seção opcional recolhível (ROTEIRO/ESTILO/DIREÇÃO): dropdown de presets que preenche o
// campo + texto livre. Vazio = padrão Vox — a seção fechada não muda nada na peça.
function SecaoExtra({ titulo, dica, presets, valor, onChange, placeholder }: {
  titulo: string; dica: string; presets: [string, string][];
  valor: string; onChange: (v: string) => void; placeholder: string;
}) {
  return (
    <details style={{ background: "var(--bg2)", border: "1px solid var(--line)", borderRadius: 10, padding: "10px 12px" }}>
      <summary className="txt" style={{ cursor: "pointer", fontSize: ".82rem", fontWeight: 600 }}>
        {titulo} <span style={{ color: "var(--muted)", fontWeight: 400 }}>— opcional{valor.trim() ? " · em uso" : ""}</span>
      </summary>
      <p className="txt" style={{ color: "var(--muted)", margin: "8px 0 6px", fontSize: ".76rem" }}>{dica}</p>
      <select
        style={{ ...sel, marginBottom: 6 }}
        value=""
        onChange={(e) => { const p = presets.find(([n]) => n === e.target.value); if (p) onChange(p[1]); }}
        title="Escolher um preset preenche o campo abaixo — dá pra editar por cima."
      >
        <option value="" disabled>Presets…</option>
        {presets.map(([n]) => <option key={n} value={n}>{n}</option>)}
      </select>
      <textarea value={valor} onChange={(e) => onChange(e.target.value)} rows={3} maxLength={2000}
        placeholder={placeholder} style={ta} />
    </details>
  );
}

export function VideoStudio() {
  const jobs = useJobs();
  const [conteudo, setConteudo] = useState("");
  const [beatsN, setBeatsN] = useState(6);
  const [aspect, setAspect] = useState("9:16");
  // 🎨 ESTILO — o campo que unificou as abas. "vox" = preset editorial; qualquer outro = caminho
  // genérico da aba Vídeo antiga (sem preset, com modelo/persona/cor/personagem escolhidos aqui).
  const [estilo, setEstilo] = useState<string>(ESTILO_VOX);
  // ⏱️ Duração da cena no estilo genérico (o Vox é 6s por doutrina, sem seletor).
  const [duracao, setDuracao] = useState("5");
  const [lang, setLang] = useState("pt-BR");
  // 🎬 Catálogo de motores de vídeo (só no genérico — o Vox tem motor próprio no servidor).
  const [models, setModels] = useState<GenModelInfo[]>([]);
  const [model, setModel] = useState("");
  const [quality, setQuality] = useState("");
  // Direção escrita (personas kind=video), paleta de cor e personagem da biblioteca — os três
  // vieram inteiros da aba Vídeo antiga.
  const [personas, setPersonas] = useState<Persona[]>([]);
  const [personaId, setPersonaId] = useState("");
  const [cores, setCores] = useState<Persona[]>([]);
  const [corId, setCorId] = useState("");
  const [chars, setChars] = useState<{ id: number; name: string; base_url?: string | null }[]>([]);
  const [charId, setCharId] = useState("");
  // 🖼️ Imagem-base (i2v): o engine usa a MESMA referência em TODAS as cenas da peça (ver
  // longform.go — `opt.refs()` é lido dentro do laço por beat). O rótulo diz isso: prometer
  // "imagem da primeira cena" seria mentir sobre o que o pipeline faz.
  const [imagemBase, setImagemBase] = useState("");
  const [escolhendoBase, setEscolhendoBase] = useState(false);
  // Estilo da legenda QUEIMADA (só no genérico — o Vox tem a tipografia própria do formato).
  const [estiloLegenda, ajustarLegenda] = useEstiloLegenda();
  // A narração CONDUZ o formato, a legenda é o 2º canal da ideia e a trilha sustenta — os três
  // nascem LIGADOS (a doutrina do formato). Desligar é decisão explícita do cliente.
  const [narracao, setNarracao] = useState(true);
  const [legenda, setLegenda] = useState(true);
  const [musica, setMusica] = useState(true);
  // 🎙️ NARRADOR — quem lê a peça. "" = padrão da conta (a voz do tenant, que no RedFoxCode é a
  // voz clonada). `vozes` vem do provedor; `vozTenant` é só pra rotular o padrão com o nome dela.
  const [vozes, setVozes] = useState<Voz[]>([]);
  const [voz, setVoz] = useState("");
  const [vozTenant, setVozTenant] = useState("");
  // As três direções opcionais — vazias, a peça é o Vox padrão de sempre.
  const [roteiroExtra, setRoteiroExtra] = useState("");
  const [estiloExtra, setEstiloExtra] = useState("");
  const [direcaoExtra, setDirecaoExtra] = useState("");
  // Storyboard pra aprovar ANTES de gastar: null = ainda não pediu.
  const [beats, setBeats] = useState<VoxBeat[] | null>(null);
  const [busy, setBusy] = useState<null | "storyboard" | "filme" | "montar" | "cena">(null);
  const [msg, setMsg] = useState<string | null>(null);
  // 💳 Saldo do provedor. null = não deu pra saber → não mostra nada (mostrar "0" sem ter
  // perguntado assusta à toa e ensina o cliente a ignorar o aviso).
  const [saldo, setSaldo] = useState<number | null>(null);

  // 🎬 CENA A CENA (V2): quais índices estão gerando agora (valor = clip_url ANTES do disparo,
  // pra detectar a troca — regerar substitui uma URL por outra, não "" por algo).
  const [cenasGerando, setCenasGerando] = useState<Record<number, string>>({});
  const pollRef = useRef<ReturnType<typeof setInterval> | null>(null);

  useEffect(() => {
    sfetch("/api/studio/saldo").then((r) => r.json())
      .then((d) => { if (d?.ok) setSaldo(Number(d.credits)); })
      .catch(() => {});
  }, []);

  // 🎬 Catálogo do estilo genérico: motores de vídeo, personas de direção, paletas de cor e
  // personagens. Tudo veio da aba Vídeo antiga — some daqui = feature que morreu sem decisão.
  useEffect(() => {
    Console.videoModels()
      .then((r) => {
        // Premium (premium) fora: sai por outro endpoint (/v1/veo), que não conhece storyboard nem
        // preset — a mesma incompatibilidade que o servidor recusa explicitamente.
        const list = (r.data ?? []).filter((m) => !m.premium).sort((a, b) => (a.cost_credits ?? 0) - (b.cost_credits ?? 0));
        setModels(list);
        if (list[0]) setModel(list[0].slug);
      })
      .catch(() => {});
    sfetch("/api/prompts?kind=video").then((r) => r.json())
      .then((d) => setPersonas(Array.isArray(d) ? d : [])).catch(() => {});
    // Os presets de COR vivem no catálogo de imagem (é onde foram cadastrados), mas direção de
    // cor é texto de direção — vale igual pra vídeo. Reconhecidos pelo prefixo do título.
    sfetch("/api/prompts?kind=image").then((r) => r.json())
      .then((d) => setCores(Array.isArray(d) ? d.filter((x: Persona) => x.title.startsWith("🎨 Cor:")) : [])).catch(() => {});
    sfetch("/api/characters").then((r) => r.json())
      .then((j) => setChars((Array.isArray(j) ? j : j?.data ?? []).map((c: { id: number; name: string; base_url?: string | null }) => ({ id: c.id, name: c.name, base_url: c.base_url }))))
      .catch(() => {});
  }, []);

  // 🎙️ Vozes do provedor + a voz da conta (pra dizer QUAL é o "padrão da conta"). As duas falham
  // caladas de propósito: sem lista o seletor some e a peça sai na voz da conta, como sempre saiu.
  useEffect(() => {
    sfetch("/api/studio/voices").then((r) => r.json())
      .then((d) => { if (d?.ok && Array.isArray(d.voices)) setVozes(d.voices as Voz[]); })
      .catch(() => {});
    sfetch("/api/usage").then((r) => r.json())
      .then((d) => { if (d?.ok) setVozTenant(String(d.voice_id ?? "")); })
      .catch(() => {});
  }, []);

  // 💾 Restaura o storyboard salvo (story.vox) ao abrir: o fluxo cena a cena atravessa vários
  // requests e dias — sem isto, um F5 jogava fora o roteiro com cenas já pagas penduradas nele.
  useEffect(() => {
    const draftId = typeof window !== "undefined" ? localStorage.getItem(DRAFT_KEY) : null;
    if (!draftId) return;
    sfetch(`/api/studio/draft?id=${draftId}`).then((r) => r.json()).then((d) => {
      const vox = d?.ok ? d.draft?.story?.vox : null;
      if (!vox?.beats?.length) return;
      setBeats(vox.beats as VoxBeat[]);
      if (vox.tema) setConteudo(String(vox.tema));
      if (vox.aspect === "16:9" || vox.aspect === "9:16") setAspect(vox.aspect);
      if (vox.voice_id) setVoz(String(vox.voice_id));
      if (vox.style) setEstilo(String(vox.style));
      if (vox.model) setModel(String(vox.model));
      if (["5", "6", "8", "10"].includes(String(vox.duration))) setDuracao(String(vox.duration));
      // Só no Vox o `style_extra` é o TEXTO do campo; no genérico ele é a craft inteira
      // (persona + cor + texto livre), que recolocar no campo duplicaria a direção.
      if (vox.style === undefined || vox.style === "vox") {
        if (vox.style_extra) setEstiloExtra(String(vox.style_extra));
        if (vox.direction_extra) setDirecaoExtra(String(vox.direction_extra));
      }
      setMsg("💾 Storyboard salvo restaurado — continue de onde parou.");
    }).catch(() => {});
  }, []);

  // Encerra o polling ao desmontar (o JobCenter continua avisando; só a atualização inline para).
  useEffect(() => () => { if (pollRef.current) clearInterval(pollRef.current); }, []);

  const clampBeats = (n: number) => Math.min(12, Math.max(2, Math.round(n) || 6));

  // ── Derivados do ESTILO — é o seletor que decide o pipeline inteiro ─────────────────────────
  const ehVox = estilo === ESTILO_VOX;
  // Duração da cena: 6s fixos no Vox (doutrina do formato), o seletor nos outros estilos.
  // No Vox a escolha é 6 ou 8; qualquer outro valor (o "5" do genérico, por exemplo) volta pra
  // doutrina do formato em vez de virar clipe com teto de fala errado.
  const durCena = ehVox ? (DURACOES_VOX.includes(duracao) ? Number(duracao) : DUR_VOX) : (Number(duracao) || 5);
  const MAX_CHARS = tetoChars(durCena);
  const modeloAtual = models.find((m) => m.slug === model);
  const qualities = modeloAtual?.qualities ?? [];
  // 🎨 A DIREÇÃO do estilo genérico vira UM texto (o engine recebe craft, não slug): persona
  // escrita + paleta de cor + o texto livre da seção 🎨 Estilo. Fonte ÚNICA: é este mesmo texto
  // que vai no filme inteiro E que é salvo com o storyboard pra a cena avulsa reusar — se os dois
  // divergirem, a cena regerada sai com outra cara no meio da peça.
  const craftGenerico = [
    personas.find((x) => String(x.id) === personaId)?.content,
    cores.find((x) => String(x.id) === corId)?.content,
    estiloExtra.trim() || undefined,
  ].filter(Boolean).join("\n\n");

  // ✅ Escreve SÓ o storyboard (texto, barato) pra revisão. Não gera imagem nem clipe.
  async function gerarStoryboard() {
    if (!conteudo.trim()) return setMsg("❌ Escreva o conteúdo antes.");
    setBusy("storyboard"); setMsg(null);
    try {
      const r = await sfetch("/api/studio/vox-roteiro", {
        method: "POST", headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          prompt: conteudo, scenes: clampBeats(beatsN), aspect, lang,
          // 🎨 O estilo decide a segmentação: "vox" pede o preset editorial (voxBeatSystem, duas
          // ilustrações por beat); qualquer outro cai na segmentação genérica de sempre.
          style: estilo || "", duration: String(durCena),
          ...(roteiroExtra.trim() ? { script_rules: roteiroExtra.trim() } : {}),
        }),
      });
      const d = await r.json();
      if (!d.ok) { setMsg("❌ " + d.error); return; }
      const novos = d.beats as VoxBeat[];
      setBeats(novos);
      // 💾 Persiste já: storyboard novo derruba clip_url de cena cujo texto mudou (regra do
      // servidor) e sobrevive a F5. O retorno traz os beats com os clipes preservados.
      const salvo = await salvarStoryboard(novos);
      if (salvo) setBeats(salvo);
      setMsg("✅ Storyboard pronto — leia, ajuste o que quiser e gere o filme (inteiro ou cena a cena).");
    } catch (e) { setMsg("❌ " + (e as Error).message); } finally { setBusy(null); }
  }

  // Edita UM campo de UM capítulo — corrigir uma frase é mais barato que pedir outro roteiro.
  function editarBeat(i: number, campo: keyof VoxBeat, valor: string) {
    setBeats((bs) => bs && bs.map((b, k) => (k === i ? { ...b, [campo]: valor } : b)));
  }

  // 💾 Salva o storyboard no rascunho (story.vox). O servidor preserva clip_url por índice
  // quando o texto da cena não mudou — e derruba quando mudou (clipe de texto velho é mentira).
  // Devolve os beats como ficaram salvos (com os clip_url que sobreviveram) ou null em erro.
  async function salvarStoryboard(bs: VoxBeat[]): Promise<VoxBeat[] | null> {
    const draftId = typeof window !== "undefined" ? localStorage.getItem(DRAFT_KEY) : null;
    const r = await sfetch("/api/studio/vox-storyboard", {
      method: "POST", headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        draftId, beats: bs, tema: conteudo, aspect,
        // 🎙️ O narrador vai JUNTO do storyboard (o servidor valida e grava em story.vox) — o
        // fluxo atravessa dias e a peça tem de sair na voz que estava na tela na aprovação.
        voice_id: voz,
        // 🎨 Estilo/motor/duração viajam COM o storyboard: a cena regerada daqui a dois dias tem
        // de sair no mesmo estilo e no mesmo motor, senão a emenda aparece na montagem.
        style: estilo, model: ehVox ? "" : model, duration: String(durCena),
        // No Vox é a direção de ARTE extra do formato; no genérico é a craft inteira (persona +
        // cor + texto livre) — é o que a geração cena a cena lê de volta.
        vox_style_extra: ehVox ? estiloExtra.trim() : craftGenerico,
        vox_direction_extra: ehVox ? direcaoExtra.trim() : "",
      }),
    });
    const d = await r.json();
    if (!d.ok) { setMsg("❌ " + d.error); return null; }
    if (d.draftId && typeof window !== "undefined") localStorage.setItem(DRAFT_KEY, String(d.draftId));
    return (d.vox?.beats as VoxBeat[]) ?? null;
  }

  // 🎬 UMA cena (V2): salva o storyboard como está (edições contam) e manda gerar/regerar só o
  // beat `i`. Custa 1 imagem + 1 clipe — a unidade de refazer deixou de ser a peça inteira.
  // 📏 Capítulos cujo script não cabe na cena (ver MAX_CHARS). Devolve os NÚMEROS visíveis na
  // tela (1-based), pra a mensagem apontar a linha que o usuário tem de mexer.
  const beatsEstourados = (bs: VoxBeat[]) =>
    bs.map((b, i) => (tamScript(b.script) > MAX_CHARS ? i + 1 : 0)).filter(Boolean);

  async function gerarCena(i: number) {
    if (!beats) return;
    // Barra ANTES de gastar: o engine corta o excesso e a cena sairia com a frase encurtada
    // sem o usuário saber. Aqui ele decide o que cortar — é o texto dele.
    if (tamScript(beats[i].script) > MAX_CHARS) {
      return setMsg(`❌ Capítulo ${i + 1}: a frase tem ${tamScript(beats[i].script)} caracteres e só cabem ${MAX_CHARS} nos ${durCena}s da cena — encurte antes de gerar (senão a narração sai cortada no meio).`);
    }
    setBusy("cena"); setMsg(null);
    try {
      const salvos = await salvarStoryboard(beats);
      if (!salvos) return;
      setBeats(salvos);
      const draftId = typeof window !== "undefined" ? localStorage.getItem(DRAFT_KEY) : null;
      const r = await sfetch("/api/studio/vox-cena", {
        method: "POST", headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ draftId, index: i }),
      });
      const d = await r.json();
      if (!d.ok) return setMsg("❌ " + d.error);
      setCenasGerando((g) => ({ ...g, [i]: salvos[i]?.clip_url ?? "" }));
      setMsg(`🎬 Cena ${i + 1} em geração — o player aparece aqui quando ficar pronta (alguns minutos).`);
      iniciarPollingCenas();
    } catch (e) { setMsg("❌ " + (e as Error).message); } finally { setBusy(null); }
  }

  // Polling das cenas em geração: relê o rascunho e troca o clip_url do beat quando o job grava.
  // Um intervalo só pra todas as cenas — cada tick resolve as que ficaram prontas.
  function iniciarPollingCenas() {
    if (pollRef.current) return; // já rodando
    let n = 0;
    pollRef.current = setInterval(async () => {
      n++;
      const draftId = typeof window !== "undefined" ? localStorage.getItem(DRAFT_KEY) : null;
      if (!draftId) return;
      const d = await sfetch(`/api/studio/draft?id=${draftId}`).then((x) => x.json()).catch(() => null);
      const remotos = (d?.ok ? d.draft?.story?.vox?.beats : null) as VoxBeat[] | null;
      if (remotos) {
        setCenasGerando((g) => {
          const resto = { ...g };
          let mudou = false;
          for (const k of Object.keys(resto)) {
            const i = Number(k);
            const url = remotos[i]?.clip_url ?? "";
            if (url && url !== resto[i]) {
              delete resto[i];
              mudou = true;
              setBeats((bs) => bs && bs.map((b, j) => (j === i ? { ...b, clip_url: url } : b)));
            }
          }
          if (mudou && Object.keys(resto).length === 0) setMsg("✅ Cena(s) pronta(s) — confira no player e monte o filme quando todas estiverem no ponto.");
          if (Object.keys(resto).length === 0 && pollRef.current) { clearInterval(pollRef.current); pollRef.current = null; }
          return resto;
        });
      }
      // ~10 min de teto: geração presa não deve pollar pra sempre (o clipe ainda entra na
      // Galeria se sair depois; só a atualização inline desiste).
      if (n > 75 && pollRef.current) {
        clearInterval(pollRef.current); pollRef.current = null;
        setCenasGerando({});
        setMsg("⏳ A cena está demorando — se ela sair, aparece na Galeria; tente recarregar a página em alguns minutos.");
      }
    }, 8000);
  }

  // 🎬 MONTAGEM FINAL do fluxo cena a cena: concatena as cenas PRONTAS na ordem do storyboard
  // (mesmo /api/studio/vox-montar do montador de uploads). Trilha opcional = toggle 🎵 Música.
  async function montarCenas() {
    const prontos = (beats ?? []).filter((b) => b.clip_url);
    if (prontos.length < 2) return setMsg("❌ Gere ao menos 2 cenas antes de montar.");
    setBusy("montar"); setMsg(null);
    try {
      const draftId = typeof window !== "undefined" ? localStorage.getItem(DRAFT_KEY) : null;
      let before = 0;
      if (draftId) {
        const rr = await sfetch(`/api/studio/draft?id=${draftId}`).then((x) => x.json()).catch(() => null);
        if (rr?.ok) before = (rr.draft.media || []).length;
      }
      const r = await sfetch("/api/studio/vox-montar", {
        method: "POST", headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          draftId, clipUrls: prontos.map((b) => b.clip_url), aspect, music: musica,
          // 🎙️ As cenas geradas aqui saem MUDAS (o /v1/voxscene não narra) — a voz entra na
          // montagem, ancorada por cena, com a fala do próprio storyboard. Antes este caminho
          // entregava um filme sem narração e sem legenda, e o cliente tinha de refazer a peça
          // inteira pelo "Gerar Filme Completo" só pra ganhar a voz.
          ...(narracao
            ? {
                narration: true,
                scripts: prontos.map((b) => b.script.trim()),
                durations: prontos.map(() => durCena),
                ...(voz ? { voice_id: voz } : {}),
                ...(legenda ? { subtitles: true, ...(ehVox ? {} : legendaPayload(estiloLegenda)) } : {}),
              }
            : {}),
        }),
      });
      const d = await r.json();
      if (!d.ok) return setMsg("❌ " + d.error);
      if (d.draftId && typeof window !== "undefined") localStorage.setItem(DRAFT_KEY, String(d.draftId));
      setMsg("🎬 Montando o filme com as cenas prontas — aparece na Galeria em alguns minutos.");
      jobs.registerJob({ type: "draft-media", draftId: Number(d.draftId || draftId), baseline: before, href: "/galeria", label: `🎬 Cenas — ${conteudo.slice(0, 40)}` });
    } catch (e) { setMsg("❌ " + (e as Error).message); } finally { setBusy(null); }
  }

  // 🎬 O caro: uma imagem + um clipe POR capítulo. Registra na Central de Tarefas e o resultado
  // cai na Galeria (categoria Vox) — mesmo se o cliente sair desta tela.
  async function gerarFilme() {
    if (!conteudo.trim()) return setMsg("❌ Escreva o conteúdo antes de gerar.");
    // 📏 Storyboard revisado: nenhum capítulo pode ir pro filme com frase maior que a cena.
    // O filme inteiro é caro — descobrir a narração cortada no vídeo pronto é pagar duas vezes.
    if (beats) {
      const fora = beatsEstourados(beats);
      if (fora.length) {
        return setMsg(`❌ ${fora.length === 1 ? "O capítulo" : "Os capítulos"} ${fora.join(", ")} ${fora.length === 1 ? "tem frase" : "têm frases"} maior${fora.length === 1 ? "" : "es"} que ${MAX_CHARS} caracteres — não cabe nos ${durCena}s da cena e a narração sairia cortada no meio. Encurte antes de gerar o filme.`);
      }
    }
    setBusy("filme"); setMsg(null);
    try {
      const draftId = typeof window !== "undefined" ? localStorage.getItem(DRAFT_KEY) : null;
      // Baseline do job draft-media = nº de mídias ANTES do disparo (é como o JobCenter detecta
      // a chegada da peça). Sem draft corrente, o servidor cria um → baseline 0.
      let before = 0;
      if (draftId) {
        const rr = await sfetch(`/api/studio/draft?id=${draftId}`).then((x) => x.json()).catch(() => null);
        if (rr?.ok) before = (rr.draft.media || []).length;
      }
      const r = await sfetch("/api/studio/media", {
        method: "POST", headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          draftId, kind: "video",
          prompt: conteudo, scenes: clampBeats(beatsN), aspect,
          // ✅ Storyboard aprovado (quando houve revisão): o servidor PULA a segmentação e gera
          // exatamente o que foi lido. Sem isto ele reescreveria e entregaria outra peça.
          // Vale nos DOIS caminhos desde a unificação (o StudioController::media repassa `beats`
          // fora do preset também — antes ficava dentro do `if preset === vox`).
          ...(beats ? { beats } : {}),
          // Toggles EXPLÍCITOS (o card antigo mandava os três true fixos): quem desliga a
          // legenda perde a tipografia Vox — a tela avisa antes, o servidor obedece.
          narration: narracao, music: musica, subtitles: legenda,
          // 🎙️ Narrador escolhido. Vazio = a voz da conta (o servidor resolve o fallback e
          // recusa id fora da allowlist do provedor).
          ...(narracao && voz ? { voice_id: voz } : {}),
          duration: String(durCena), lang,
          // 📰 ESTILO VOX → preset editorial. O `preset` é o que faz o servidor trocar de uma vez
          // segmentação, motor de imagem, linguagem visual, prompt de movimento e acabamento —
          // e é ele que marca a peça como Vox na Galeria (o media() sobrescreve o style).
          // 📰 SEM `model` no Vox de propósito: o formato tem MOTOR PRÓPRIO (VOX_VIDEO_SLUG).
          ...(ehVox
            ? {
                preset: "vox",
                // 🎨/🎥 Direções extras: o engine APENDA por cima do padrão do formato.
                ...(estiloExtra.trim() ? { vox_style_extra: estiloExtra.trim() } : {}),
                ...(direcaoExtra.trim() ? { vox_direction_extra: direcaoExtra.trim() } : {}),
              }
            : {
                // 🎬 CAMINHO GENÉRICO — o que a aba Vídeo sempre fez: estilo + motor + direção
                // escrita + paleta + personagem, SEM preset nenhum.
                style: estilo,
                ...(model ? { model } : {}),
                ...(quality ? { quality } : {}),
                // Direção + cor viram UM texto de persona (o engine recebe craft, não slug) —
                // mesmo contrato do Filme e da aba Vídeo antiga.
                ...(craftGenerico ? { persona: craftGenerico } : {}),
                ...(charId ? { charIds: [Number(charId)] } : {}),
                ...(imagemBase ? { imageUrl: imagemBase } : {}),
                // Legenda queimada no visual escolhido (a tipografia Vox não existe fora do Vox).
                ...(legenda ? legendaPayload(estiloLegenda) : {}),
              }),
        }),
      });
      const d = await r.json();
      if (!d.ok) return setMsg("❌ " + d.error);
      const did = d.draftId || draftId;
      if (d.draftId && typeof window !== "undefined") localStorage.setItem(DRAFT_KEY, String(d.draftId));
      setMsg((ehVox ? "📰" : "🎬") + " Montando o filme — leva alguns minutos. Ele aparece na Galeria quando ficar pronto; a Central de Tarefas (🔔 no topo) avisa.");
      jobs.registerJob({ type: "draft-media", draftId: Number(did), baseline: before, href: "/galeria", label: `${ehVox ? "📰 Vox" : "🎬 Vídeo"} — ${conteudo.slice(0, 40)}` });
    } catch (e) { setMsg("❌ " + (e as Error).message); } finally { setBusy(null); }
  }

  // ── 🎬 MONTADOR — clipes prontos (gerados fora, ex.: Google Flow) → filme concatenado ──────
  // Sobe os MP4s pro nosso storage (/api/studio/upload), ordena, e /api/studio/vox-montar
  // concatena pelo MESMO caminho de montagem da aba Filme (/v1/filmassemble). O resultado cai
  // na Galeria como peça Vox.
  // Um clipe da fila: arquivo subido + a DURAÇÃO REAL medida nele + a FALA daquela cena.
  type ClipeFila = { name: string; url: string; dur: number; script: string };
  const [clipes, setClipes] = useState<ClipeFila[]>([]);
  const [subindo, setSubindo] = useState(false);
  const [montMusica, setMontMusica] = useState(false);
  const [montMusicaDesc, setMontMusicaDesc] = useState("");
  const [montAspect, setMontAspect] = useState("9:16");
  const [montMsg, setMontMsg] = useState<string | null>(null);
  // 🎙️ Montagem COMPLETA (2026-08-06): narração por cena + legenda, além da trilha. Antes o
  // montador SÓ concatenava — quem exportava os clipes de fora (Google Flow etc.) subia o vídeo
  // cru e não tinha como completar a peça.
  const [montNarracao, setMontNarracao] = useState(false);
  const [montLegenda, setMontLegenda] = useState(false);
  // Roteiro colado de uma vez, distribuído por cena (TXT linha a linha ou SRT).
  const [montRoteiro, setMontRoteiro] = useState("");
  // 🖐️ DRAG-AND-DROP (V2): índice do clipe sendo arrastado. HTML5 drag events puros — o projeto
  // não usa lib de DnD e uma dependência pra reordenar uma lista curta não se paga. Os botões
  // ↑/↓ continuam como fallback de acessibilidade (drag não funciona por teclado).
  const [dragIdx, setDragIdx] = useState<number | null>(null);

  // ⏱️ DURAÇÃO REAL do arquivo, medida ANTES do upload no próprio navegador (metadata do
  // <video>). É ela que dá o teto de caracteres da fala daquela cena — usar um valor fixo (os 6s
  // do Vox) mentiria sobre clipe externo, que tem a duração que tem.
  //
  // ⚠️ PENDÊNCIA CONHECIDA: a medição é do CLIENTE. O servidor re-checa o teto com a duração que
  // a tela mandou (StudioController::voxMontar), mas não a mede sozinho — um ffprobe por URL
  // exigiria endpoint novo no ffmpeg-service + engine + console. O risco é só estético (fala
  // vazando pra cena seguinte numa peça do próprio usuário), não de gasto: a montagem custa 1
  // short independentemente. Quando o probe existir, a régua passa a sair dele.
  function duracaoDoArquivo(file: File): Promise<number> {
    return new Promise((resolve) => {
      const url = URL.createObjectURL(file);
      const v = document.createElement("video");
      v.preload = "metadata";
      const fim = (d: number) => { URL.revokeObjectURL(url); resolve(d); };
      v.onloadedmetadata = () => fim(Number.isFinite(v.duration) ? v.duration : 0);
      v.onerror = () => fim(0);
      v.src = url;
    });
  }

  // 📋 Distribui um roteiro colado pelas cenas, na ordem. Aceita SRT (blocos numerados com
  // timecode) e TXT (uma linha por cena) — é o que sai das ferramentas externas.
  function distribuirRoteiro() {
    const bruto = montRoteiro.trim();
    if (!bruto) return setMontMsg("❌ Cole o roteiro antes de distribuir.");
    if (clipes.length === 0) return setMontMsg("❌ Suba os clipes antes — a distribuição é por cena.");
    const falas = bruto.includes("-->") ? parseSRT(bruto) : bruto.split(/\r?\n/).map((l) => l.trim()).filter(Boolean);
    if (falas.length === 0) return setMontMsg("❌ Não encontrei nenhuma fala no que foi colado.");
    setClipes((cs) => cs.map((c, i) => ({ ...c, script: falas[i] ?? "" })));
    setMontNarracao(true);
    setMontMsg(falas.length === clipes.length
      ? `✅ ${falas.length} falas distribuídas — uma por cena.`
      : `⚠️ ${falas.length} falas para ${clipes.length} cenas: ${falas.length > clipes.length ? "as que sobraram foram ignoradas" : "as cenas finais ficaram mudas"}. Confira antes de montar.`);
  }

  async function subirClipes(files: FileList | null) {
    if (!files || files.length === 0) return;
    setSubindo(true); setMontMsg(null);
    try {
      // Sequencial de propósito: cada upload pode ter 100MB — paralelizar N vídeos pelo mesmo
      // link do cliente só divide a banda e multiplica timeout.
      for (const f of Array.from(files)) {
        // Mede ANTES de subir: o arquivo local está aqui, e depois do upload só teríamos a URL.
        const dur = await duracaoDoArquivo(f);
        const fd = new FormData();
        fd.append("file", f);
        fd.append("kind", "video");
        const draftId = typeof window !== "undefined" ? localStorage.getItem(DRAFT_KEY) : null;
        if (draftId) fd.append("draftId", draftId);
        const r = await sfetch("/api/studio/upload", { method: "POST", body: fd });
        const d = await r.json();
        if (!d.ok) { setMontMsg(`❌ ${f.name}: ${d.error || "falha no upload"}`); continue; }
        if (d.draftId && typeof window !== "undefined") localStorage.setItem(DRAFT_KEY, String(d.draftId));
        const item = (d.media as { url: string }[] | undefined)?.at(-1);
        if (item?.url) setClipes((cs) => [...cs, { name: f.name, url: item.url, dur, script: "" }]);
      }
    } catch (e) { setMontMsg("❌ " + (e as Error).message); } finally { setSubindo(false); }
  }

  function moverClipe(i: number, delta: -1 | 1) {
    setClipes((cs) => {
      const j = i + delta;
      if (j < 0 || j >= cs.length) return cs;
      const out = [...cs];
      [out[i], out[j]] = [out[j], out[i]];
      return out;
    });
  }

  // Solta o clipe arrastado na posição `alvo` (antes do item que está lá).
  function soltarClipe(alvo: number) {
    setClipes((cs) => {
      if (dragIdx === null || dragIdx === alvo) return cs;
      const out = [...cs];
      const [movido] = out.splice(dragIdx, 1);
      out.splice(alvo, 0, movido);
      return out;
    });
    setDragIdx(null);
  }

  async function montarFilme() {
    if (clipes.length < 2) return setMontMsg("❌ Suba ao menos 2 clipes pra montar.");
    // 📏 BARRA A MONTAGEM com cena estourada. O /concat-clips NÃO estica o clipe: a fala maior
    // que a cena vaza pra próxima e a última é cortada. O servidor recusa igual — aqui é pra o
    // usuário ver QUAL cena antes de esperar minutos por um vídeo desalinhado.
    if (montNarracao) {
      const fora = clipes
        .map((c, i) => (c.script.trim() && c.dur > 0 && c.script.trim().length > tetoChars(c.dur) ? i + 1 : 0))
        .filter(Boolean);
      if (fora.length) {
        return setMontMsg(`❌ ${fora.length === 1 ? "A cena" : "As cenas"} ${fora.join(", ")} ${fora.length === 1 ? "tem fala" : "têm falas"} maior${fora.length === 1 ? "" : "es"} do que cabe no clipe — encurte antes de montar (a montagem não estica o vídeo: a fala vazaria pra cena seguinte).`);
      }
      if (clipes.every((c) => !c.script.trim())) {
        return setMontMsg("❌ Narração ligada e nenhuma fala escrita — escreva o texto das cenas ou desligue a narração.");
      }
    }
    setBusy("montar"); setMontMsg(null);
    try {
      const draftId = typeof window !== "undefined" ? localStorage.getItem(DRAFT_KEY) : null;
      let before = 0;
      if (draftId) {
        const rr = await sfetch(`/api/studio/draft?id=${draftId}`).then((x) => x.json()).catch(() => null);
        if (rr?.ok) before = (rr.draft.media || []).length;
      }
      const r = await sfetch("/api/studio/vox-montar", {
        method: "POST", headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          draftId, clipUrls: clipes.map((c) => c.url), aspect: montAspect,
          music: montMusica, ...(montMusica && montMusicaDesc.trim() ? { musicPrompt: montMusicaDesc.trim() } : {}),
          // 🎙️ Montagem COMPLETA: a fala de cada cena + a duração real medida no arquivo (é ela
          // que o servidor usa pra recobrar o teto). Sem narração, nada disto vai — e o servidor
          // faz o concat puro de sempre.
          ...(montNarracao
            ? {
                narration: true,
                scripts: clipes.map((c) => c.script.trim()),
                durations: clipes.map((c) => c.dur),
                ...(voz ? { voice_id: voz } : {}),
                ...(montLegenda ? { subtitles: true, ...legendaPayload(estiloLegenda) } : {}),
              }
            : {}),
        }),
      });
      const d = await r.json();
      if (!d.ok) return setMontMsg("❌ " + d.error);
      if (d.draftId && typeof window !== "undefined") localStorage.setItem(DRAFT_KEY, String(d.draftId));
      setMontMsg("🎬 Montando o filme — aparece na Galeria (categoria Vox) em alguns minutos; a Central de Tarefas (🔔) avisa.");
      jobs.registerJob({ type: "draft-media", draftId: Number(d.draftId || draftId), baseline: before, href: "/galeria", label: `🎬 Montagem Vox — ${clipes.length} clipes` });
    } catch (e) { setMontMsg("❌ " + (e as Error).message); } finally { setBusy(null); }
  }

  // ── ⏱️ Roteiro com timestamps — pra sincronizar edição externa e descrição de vídeo ────────
  const [copiado, setCopiado] = useState<"" | "roteiro" | "capitulos">("");
  // [00:00–00:06] frase — o formato de conferência/edição (intervalo completo).
  const roteiroComTimestamps = (bs: VoxBeat[]) =>
    bs.map((b, i) => `[${intervalo(i, durCena)}] ${b.script.trim()}`).join("\n");
  // Capítulos do YouTube usam SÓ o início e o título curto (caption); a 1ª linha TEM de ser
  // 00:00 — regra do próprio YouTube pra ativar os capítulos.
  const capitulosYouTube = (bs: VoxBeat[]) =>
    bs.map((b, i) => `${mmss(i * durCena)} ${(b.caption || b.script).trim()}`).join("\n");
  async function copiar(texto: string, qual: "roteiro" | "capitulos") {
    try {
      await navigator.clipboard.writeText(texto);
      setCopiado(qual);
      setTimeout(() => setCopiado(""), 2000);
    } catch { setMsg("❌ Não deu pra copiar — selecione o texto e copie na mão."); }
  }

  // ── ✍️ TEXTO DE PUBLICAÇÃO — copy por rede a partir do tema+roteiro do Vox ──────────────────
  // Mesmo mecanismo do compositor NovoPost/aba Conteúdo: POST /api/studio/text {draftId,
  // platform}. O draft é o do storyboard salvo (o voxStoryboard grava keyword = tema, que é de
  // onde o /v1/text tira o assunto).
  const [redes, setRedes] = useState<string[]>([]);          // redes conectadas
  const [redesSel, setRedesSel] = useState<string[]>([]);    // redes escolhidas pra copy
  const [textos, setTextos] = useState<Record<string, string>>({});
  const [textoBusy, setTextoBusy] = useState<string | null>(null); // "all" | plataforma

  useEffect(() => {
    sfetch("/api/connections").then((r) => r.json()).then((d) => {
      if (d.ok) setRedes(Array.from(new Set(((d.accounts ?? []) as { platform: string }[]).map((a) => a.platform))));
    }).catch(() => {});
  }, []);

  async function gerarTextoDe(draftId: string, platform: string): Promise<string> {
    const r = await sfetch("/api/studio/text", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ draftId, platform }) });
    const d = await r.json();
    if (!d.ok || !String(d.post || "").trim()) throw new Error(d.error || `não foi possível gerar o texto de ${PLATFORM_LABEL[platform] ?? platform}`);
    return String(d.post).trim();
  }

  // Gera a copy das redes escolhidas (sequencial: cada geração é cobrada e o endpoint tem
  // throttle por minuto — rajada paralela só colecionaria 429). Exige storyboard salvo: é ele
  // que garante draft com keyword = tema (sem tema o redator não tem em que se apoiar).
  async function gerarTextos() {
    if (redesSel.length === 0) return setMsg("❌ Escolha ao menos uma rede pra gerar o texto.");
    setTextoBusy("all"); setMsg(null);
    try {
      let draftId = typeof window !== "undefined" ? localStorage.getItem(DRAFT_KEY) : null;
      if (!draftId || !beats) {
        if (!beats) return setMsg("❌ Gere (ou restaure) o storyboard antes — a copy nasce do tema+roteiro.");
        const salvos = await salvarStoryboard(beats);
        if (!salvos) return;
        draftId = localStorage.getItem(DRAFT_KEY);
      }
      for (const p of redesSel) {
        const post = await gerarTextoDe(String(draftId), p);
        setTextos((t) => ({ ...t, [p]: post }));
      }
      setMsg("✅ Textos gerados — revise antes de publicar (a IA inventa número com confiança).");
    } catch (e) { setMsg("❌ " + (e as Error).message); } finally { setTextoBusy(null); }
  }

  // ↻ Regenera SÓ uma rede — as outras ficam como estão.
  async function regerarTexto(p: string) {
    const draftId = typeof window !== "undefined" ? localStorage.getItem(DRAFT_KEY) : null;
    if (!draftId) return setMsg("❌ Gere o storyboard antes.");
    setTextoBusy(p); setMsg(null);
    try {
      const post = await gerarTextoDe(draftId, p);
      setTextos((t) => ({ ...t, [p]: post }));
    } catch (e) { setMsg("❌ " + (e as Error).message); } finally { setTextoBusy(null); }
  }

  const anyBusy = busy !== null || subindo;

  // ✨ NOVO — zera a criação e devolve a aba ao estado de tela em branco.
  //
  // Sem isto, a única forma de começar outra peça era recarregar a página: o storyboard aprovado,
  // os clipes da fila e as direções escritas ficavam na tela e entravam, sem querer, na peça
  // seguinte. Um storyboard velho sobrevivendo à troca de assunto não é sujeira visual — é a peça
  // errada sendo gerada e cobrada.
  //
  // O que NÃO se perde de propósito: catálogos carregados do servidor (motores, personas, cores,
  // personagens, vozes, saldo) e as preferências de formato/estilo/idioma/narrador. São a bancada
  // de trabalho, não o conteúdo; quem começa uma peça nova quer a mesma bancada.
  function novo() {
    if ((beats?.length || clipes.length > 0 || conteudo.trim()) && !confirm("Limpar esta criação e começar do zero? O storyboard e os clipes desta tela serão descartados.")) {
      return;
    }
    // 🧹 O RASCUNHO em si. Sem isto o "Novo" seria cosmético: a tela limpava, mas o próximo
    // storyboard era gravado NO MESMO draft (salvarStoryboard reusa o DRAFT_KEY), então a peça
    // nova nascia dentro da anterior — herdando mídia, story.vox e histórico dela.
    if (typeof window !== "undefined") localStorage.removeItem(DRAFT_KEY);
    // ⏹️ E o polling das cenas, que escreve em setBeats: deixá-lo vivo repovoaria o storyboard
    // que acabamos de limpar, com os beats do rascunho antigo.
    if (pollRef.current) { clearInterval(pollRef.current); pollRef.current = null; }

    setConteudo("");
    setBeats(null);
    setImagemBase("");
    setRoteiroExtra(""); setEstiloExtra(""); setDirecaoExtra("");
    setCenasGerando({});
    setMsg(null);
    // Montador: a fila de clipes e o roteiro colado são desta peça, não da próxima.
    setClipes([]); setMontRoteiro(""); setMontMusicaDesc(""); setMontMsg(null); setDragIdx(null);
    if (typeof window !== "undefined") window.scrollTo({ top: 0, behavior: "smooth" });
  }

  return (
    <>
      <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", gap: 12, flexWrap: "wrap" }}>
        <h1 className="h1" style={{ margin: 0 }}>🎬 Vídeo</h1>
        <button type="button" className="btn edit" style={{ flex: "none", padding: "8px 14px" }}
          onClick={novo} disabled={anyBusy}
          title={anyBusy ? "Espere a geração terminar" : "Limpar esta criação e começar do zero"}>
          ✨ Novo
        </button>
      </div>
      <p className="sub">
        {ehVox ? (
          <>
            <b>Vox</b> — jornalismo explicativo: uma voz conduz o raciocínio e cada frase ganha a sua
            própria ilustração em <b>colagem de papel</b>, no instante em que é dita.
          </>
        ) : (
          <>
            Vídeo narrado <b>cena a cena</b> no estilo escolhido, com o motor, a direção e o
            personagem que você definir.
          </>
        )}{" "}
        Escreva o conteúdo, <b>revise o storyboard de graça</b> e só então gere o filme — inteiro ou
        cena a cena.
      </p>

      {/* Painel principal no .card/.body da casa (era uma moldura inline com borda AZUL — a única
          cor fora da paleta no app inteiro). */}
      <article className="card">
        <div className="body" style={{ gap: 12 }}>
        <div style={{ display: "flex", flexDirection: "column", gap: 4 }}>
          <label className="txt" style={lbl}>Conteúdo — o tema, ou um roteiro seu colado inteiro</label>
          <textarea value={conteudo} onChange={(e) => setConteudo(e.target.value)} rows={4}
            placeholder="Ex.: por que a conta de luz subiu por causa dos data centers — ou cole aqui o texto que a peça deve seguir"
            style={ta} />
        </div>

        {/* 💳 AVISO DE SALDO — antes dos botões, não depois do prejuízo (caso 04/08: 3 de 6 cenas).
            Só aparece quando o saldo é REALMENTE baixo — aviso permanente vira ruído. */}
        {saldo !== null && saldo < 35 * clampBeats(beatsN) && (
          <div className="err" style={{ margin: 0 }}>
            ⚠️ Saldo de <strong>{saldo.toFixed(0)}</strong> créditos no provedor — pouco para {clampBeats(beatsN)} capítulos.
            A peça pode sair pela metade. <strong>Gerar o storyboard continua de graça</strong>: prepare agora e gere o filme depois de recarregar.
          </div>
        )}

        {/* 🎨 ESTILO — o campo que decide o pipeline. Fica no TOPO porque tudo abaixo dele muda:
            no Vox o motor/segmentação/tipografia são do formato; nos outros estilos aparecem o
            seletor de motor, a direção escrita, a paleta e o personagem. */}
        <div style={{ display: "flex", gap: 14, flexWrap: "wrap", alignItems: "flex-end" }}>
          <div style={{ display: "flex", flexDirection: "column", gap: 4, flex: 1, minWidth: 260 }}>
            <label className="txt" style={lbl}>Estilo</label>
            <select value={estilo} onChange={(e) => setEstilo(e.target.value)} style={{ ...sel, width: "100%" }}
              title="O estilo define a peça inteira. 'Vox' é o formato editorial com motor e linguagem próprios; os demais seguem o caminho de vídeo comum.">
              {ESTILOS.map(([v, label]) => <option key={v || "nenhum"} value={v}>{label}</option>)}
            </select>
          </div>
          {!ehVox && models.length > 0 && (
            <div style={{ display: "flex", flexDirection: "column", gap: 4 }}>
              <label className="txt" style={lbl}>Motor</label>
              <select value={model} onChange={(e) => { setModel(e.target.value); setQuality(""); }} style={{ ...sel, maxWidth: 280 }}>
                {models.map((m) => (
                  <option key={m.slug} value={m.slug}>
                    {marcaMotor(m)} · {nomeModelo(m)}{m.cost_credits != null ? ` · ${m.cost_credits} cr` : ""}{m.is_unstable ? " ⚠️" : ""}
                  </option>
                ))}
              </select>
            </div>
          )}
          {!ehVox && qualities.length > 0 && (
            <div style={{ display: "flex", flexDirection: "column", gap: 4 }}>
              <label className="txt" style={lbl}>Resolução</label>
              <select value={quality} onChange={(e) => setQuality(e.target.value)} style={sel}>
                <option value="">Padrão</option>
                {qualities.map((q) => <option key={q.key} value={q.key}>{q.label}</option>)}
              </select>
            </div>
          )}
          <div style={{ display: "flex", flexDirection: "column", gap: 4 }}>
            <label className="txt" style={lbl}>Duração da cena</label>
            <select value={ehVox ? String(durCena) : duracao} onChange={(e) => setDuracao(e.target.value)} style={sel}
              title="Quantos segundos cada cena dura. É dela que sai o teto de caracteres da fala.">
              {ehVox ? (
                <>
                  {/* 6s = a doutrina do formato (o clipe testado). 8s = o beat do Vox Factory
                      (Google Labs), pra um roteiro escrito lá caber aqui sem truncar a fala. */}
                  <option value="6">6 segundos (padrão Vox)</option>
                  <option value="8">8 segundos (padrão Vox Factory)</option>
                </>
              ) : (
                <>
                  <option value="5">5 segundos</option>
                  <option value="10">10 segundos</option>
                </>
              )}
            </select>
          </div>
          {!ehVox && personas.length > 0 && (
            <div style={{ display: "flex", flexDirection: "column", gap: 4 }}>
              <label className="txt" style={lbl}>Direção</label>
              <select value={personaId} onChange={(e) => setPersonaId(e.target.value)} style={{ ...sel, maxWidth: 220 }}
                title="Personas que você escreveu na aba Prompts (kind=video)">
                <option value="">Sem direção</option>
                {personas.map((x) => <option key={x.id} value={String(x.id)}>{x.title}</option>)}
              </select>
            </div>
          )}
          {!ehVox && cores.length > 0 && (
            <div style={{ display: "flex", flexDirection: "column", gap: 4 }}>
              <label className="txt" style={lbl}>Cor</label>
              <select value={corId} onChange={(e) => setCorId(e.target.value)} style={{ ...sel, maxWidth: 200 }}
                title="Paleta cinematográfica — combina com a direção escolhida">
                <option value="">Sem paleta</option>
                {cores.map((c) => <option key={c.id} value={String(c.id)}>{c.title.replace("🎨 Cor: ", "")}</option>)}
              </select>
            </div>
          )}
          {!ehVox && chars.length > 0 && (
            <div style={{ display: "flex", flexDirection: "column", gap: 4 }}>
              <label className="txt" style={lbl}>Personagem</label>
              <select value={charId} onChange={(e) => setCharId(e.target.value)} style={{ ...sel, maxWidth: 200 }}
                title="Injeta o IDENTITY LOCK do personagem no prompt de cada cena">
                <option value="">Nenhum</option>
                {chars.map((c) => <option key={c.id} value={String(c.id)}>{c.name}{c.base_url ? "" : " (sem base)"}</option>)}
              </select>
            </div>
          )}
          {!ehVox && (
            <div style={{ display: "flex", flexDirection: "column", gap: 4 }}>
              <label className="txt" style={lbl}>Imagem base (opcional)</label>
              <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
                {imagemBase && (
                  // eslint-disable-next-line @next/next/no-img-element
                  <img src={imagemBase} alt="Imagem base" style={{ width: 38, height: 38, objectFit: "cover", borderRadius: 8, border: "1px solid var(--line2)" }} />
                )}
                <button type="button" className="btn edit" style={{ flex: "none", padding: "8px 12px", fontSize: ".8rem" }}
                  onClick={() => setEscolhendoBase(true)}
                  title="A referência é usada em TODAS as cenas da peça (o engine lê as refs dentro do laço por cena) — serve pra travar cenário/produto, não pra 'a imagem da 1ª cena'.">
                  {imagemBase ? "Trocar" : "Escolher"}
                </button>
                {imagemBase && (
                  <button type="button" className="btn edit" style={{ flex: "none", padding: "8px 10px", fontSize: ".8rem" }}
                    onClick={() => setImagemBase("")} title="Tirar a imagem base">✕</button>
                )}
              </div>
            </div>
          )}
        </div>
        {!ehVox && modeloAtual && (
          <p className="txt" style={{ color: "var(--muted)", margin: 0, fontSize: ".76rem" }} title={ajudaMotor(modeloAtual)}>
            {rotuloMotor(modeloAtual)} · {nomeModelo(modeloAtual)} · {modeloAtual.cost_credits ?? "—"} créditos por cena
          </p>
        )}

        <div style={{ display: "flex", gap: 14, flexWrap: "wrap", alignItems: "flex-end" }}>
          <div style={{ display: "flex", flexDirection: "column", gap: 4 }}>
            <label className="txt" style={lbl}>Beats (capítulos)</label>
            <input type="number" min={2} max={12} value={beatsN}
              onChange={(e) => setBeatsN(Number(e.target.value))}
              onBlur={() => setBeatsN(clampBeats(beatsN))}
              style={{ ...sel, width: 90 }}
              title="Quantas frases o raciocínio tem (2 a 12). Cada uma vira uma ilustração e um trecho de narração — mais beats, peça mais longa e mais cara." />
          </div>
          <div style={{ display: "flex", flexDirection: "column", gap: 4 }}>
            <label className="txt" style={lbl}>Formato</label>
            <select value={aspect} onChange={(e) => setAspect(e.target.value)} style={sel} title="Proporção da peça">
              <option value="9:16">🖼️ 9:16 vertical</option>
              <option value="16:9">🖼️ 16:9 horizontal</option>
            </select>
          </div>
          <div style={{ display: "flex", flexDirection: "column", gap: 4 }}>
            <label className="txt" style={lbl}>Idioma</label>
            <select value={lang} onChange={(e) => setLang(e.target.value)} style={sel} title="Idioma do roteiro e da narração">
              <option value="pt-BR">🇧🇷 Português</option>
              <option value="en-US">🇺🇸 Inglês</option>
            </select>
          </div>
          {/* 🎙️ NARRADOR — quem lê a peça. Some quando o provedor não respondeu (a peça continua
              saindo na voz da conta) e quando a narração está desligada (não há o que narrar).
              ⚠️ RÉGUA DE LOCUÇÃO x VOZ: os 12,2 caracteres/segundo do contador (CHARS_POR_SEG,
              commit e4ec1cc) foram MEDIDOS numa voz específica — a padrão da conta. Voz mais
              rápida sobra tempo na cena; voz mais lenta ESTOURA os 6s e o áudio sai cortado, com
              o contador dizendo que cabia. Nenhuma régua por voz foi implementada aqui de
              propósito: exige medir cada voz (locução real, não estimativa). Quando isso for
              feito, o teto passa a depender do `voz` escolhido — e o mesmo vale pro engine
              (charsPerSec em engine/internal/content/longform.go), que corta pelo mesmo número. */}
          {narracao && vozes.length > 0 && (
            <div style={{ display: "flex", flexDirection: "column", gap: 4 }}>
              <label className="txt" style={lbl}>Narrador</label>
              <select
                value={voz && voz !== vozTenant ? voz : ""}
                onChange={(e) => setVoz(e.target.value)}
                style={{ ...sel, maxWidth: 240 }}
                title="Quem narra a peça. O padrão é a voz da conta; a régua de caracteres do storyboard foi medida nela.">
                <option value="">
                  🎙️ Padrão da conta{(() => {
                    const v = vozes.find((x) => x.id === vozTenant);
                    return v ? ` — ${v.name}` : "";
                  })()}
                </option>
                {vozes.filter((v) => v.id !== vozTenant).map((v) => (
                  <option key={v.id} value={v.id}>🎙️ {rotuloVoz(v)}</option>
                ))}
              </select>
            </div>
          )}
          {([
            ["🎙️ Narração", narracao, setNarracao, "A voz que conduz o raciocínio — é ela que dita o ritmo da peça."],
            ["🔤 Legenda", legenda, setLegenda, "A tipografia Vox: condensada carimbando palavra a palavra + sweep de marca-texto."],
            ["🎵 Música", musica, setMusica, "Trilha de fundo discreta sustentando a narração."],
          ] as [string, boolean, (v: boolean) => void, string][]).map(([nome, on, set, dica]) => (
            <label key={nome} className="txt" title={dica}
              style={{ display: "flex", alignItems: "center", gap: 6, fontSize: ".82rem", cursor: "pointer", paddingBottom: 9 }}>
              <input type="checkbox" checked={on} onChange={(e) => set(e.target.checked)} /> {nome}
            </label>
          ))}
        </div>
        {/* Aviso sutil: a legenda animada É parte da assinatura visual do formato. */}
        {/* Narrador fora do padrão: o contador N/65 continua valendo pela voz da conta (ver o
            comentário da régua no seletor) — dizer isso aqui evita confiar cegamente no número. */}
        {narracao && voz && voz !== vozTenant && (
          <p className="txt" style={{ color: "var(--muted)", margin: 0, fontSize: ".76rem" }}>
            ℹ️ O contador de caracteres do storyboard foi medido na <strong>voz padrão da conta</strong>. Uma voz
            mais lenta pode não caber nos {durCena}s mesmo dentro do limite — confira a 1ª cena antes de gerar o filme todo.
          </p>
        )}
        {!legenda && ehVox && (
          <p className="txt" style={{ color: "var(--muted)", margin: 0, fontSize: ".76rem" }}>
            ℹ️ Sem legenda a peça perde a <strong>tipografia Vox</strong> (a palavra carimbando na tela) — que é parte da assinatura do formato.
          </p>
        )}
        {/* 🔤 Estilo da legenda QUEIMADA — só fora do Vox: o formato tem tipografia própria
            (animada, no caption-service) e um force_style por cima dela brigaria com a arte. */}
        {legenda && !ehVox && (
          <EstiloLegenda estilo={estiloLegenda} onChange={ajustarLegenda} aspect={aspect} disabled={anyBusy} />
        )}

        {/* As três direções opcionais. Fechadas/vazias = o Vox padrão, intacto. */}
        <SecaoExtra titulo="📝 Roteiro" dica="Regras extras de ESTRUTURA do roteiro — entram por cima das regras do formato, nunca no lugar delas."
          presets={PRESETS_ROTEIRO} valor={roteiroExtra} onChange={setRoteiroExtra}
          placeholder="Ex.: cada beat termina com um número concreto; o público é dono de restaurante…" />
        <SecaoExtra titulo="🎨 Estilo (texto livre)"
          dica={ehVox
            ? "Direção de arte extra, apendada à colagem de papel padrão (em inglês rende melhor — vai direto pro motor de imagem)."
            : "Direção de arte extra — entra JUNTO da direção e da paleta escolhidas acima, como texto de persona (em inglês rende melhor)."}
          presets={PRESETS_ESTILO} valor={estiloExtra} onChange={setEstiloExtra}
          placeholder="Ex.: warm sepia palette, letterpress typography texture on objects…" />
        {/* 🎥 Direção de MOVIMENTO só existe no Vox: o campo é o `vox_direction_extra`, que o
            engine apenda no voxMotionPrompt. Fora do preset ele não tem onde entrar — mostrar o
            campo seria oferecer um controle que o servidor descarta em silêncio. */}
        {ehVox && (
          <SecaoExtra titulo="🎥 Direção" dica="Movimento/câmera extra, apendado ao stop-motion padrão (em inglês rende melhor — vai direto pro motor de vídeo). A câmera travada continua lei."
            presets={PRESETS_DIRECAO} valor={direcaoExtra} onChange={setDirecaoExtra}
            placeholder="Ex.: slow gliding motion, long contemplative takes…" />
        )}

        {/* ✅ DOIS BOTÕES, e a ordem importa: o barato primeiro. O storyboard custa uma chamada
            de texto; o filme custa uma imagem + um clipe POR capítulo. */}
        <div style={{ display: "flex", gap: 10, flexWrap: "wrap" }}>
          <button className="btn edit" style={{ flex: "none", padding: "9px 14px" }} disabled={anyBusy} onClick={gerarStoryboard}
            title="Escreve só o storyboard pra você ler e ajustar. Não gera imagem nem vídeo, e não consome cota de mídia.">
            {busy === "storyboard" ? "Escrevendo…" : "📝 Gerar Storyboard"}
          </button>
          <button className="btn ok" style={{ flex: "none", padding: "9px 14px" }} disabled={anyBusy} onClick={gerarFilme}
            title={beats ? "Gera o filme sobre o storyboard aprovado abaixo" : "Gera direto, sem revisar o storyboard"}>
            {busy === "filme" ? "Montando…" : beats ? "🎬 Gerar Filme Completo (com este storyboard)" : "🎬 Gerar Filme Completo"}
          </button>
        </div>

        {/* Mensagem da tela: erro entra na .err da casa (mesma caixa de erro da /publicar e da
            /galeria); aviso/sucesso segue como texto — .err pra tudo faria "✅ pronto" parecer
            falha. */}
        {msg && (msg.startsWith("❌")
          ? <div className="err" style={{ margin: 0 }}>{msg.replace(/^❌\s*/, "")}</div>
          : <p className="txt" style={{ margin: 0, fontSize: ".88rem" }}>{msg}</p>)}

        {/* ✅ O STORYBOARD, ABERTO PRA REVISÃO — frase falada em destaque (é ela que vira
            narração), ilustrações recolhidas. Tudo editável ANTES de gastar. */}
        {beats && (
          <div style={{ display: "flex", flexDirection: "column", gap: 8, borderTop: "1px solid var(--line)", paddingTop: 10 }}>
            <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", gap: 8, flexWrap: "wrap" }}>
              <strong className="txt" style={{ fontSize: ".84rem" }}>Storyboard — {beats.length} capítulos</strong>
              <button type="button" className="btn edit" style={{ flex: "none", padding: "4px 10px", fontSize: ".75rem" }}
                disabled={anyBusy} onClick={gerarStoryboard} title="Escreve outro storyboard pro mesmo conteúdo (continua sem gerar mídia)">
                ↻ Escrever outro
              </button>
            </div>
            <p className="txt" style={{ color: "var(--muted)", margin: 0, fontSize: ".76rem" }}>
              Leia como um texto só: o 1º capítulo tem de se sustentar sozinho e o último precisa <strong>concluir</strong> — não terminar num “mas”.
            </p>
            {/* 🎴 GRADE DE CARTÕES — mesma linguagem visual do cartão de cena da MONTAGEM (aba
                /roteiro): quadro/clipe em cima, texto no meio, ações no rodapé. A casca vem de
                components/roteiro/cartaoCena (compartilhada com o SceneNode); o cartão em si é o
                VoxSceneCard, que explica por que o SceneNode não foi importado direto. */}
            <div style={cartaoGrade}>
              {beats.map((b, i) => (
                <SceneCard
                  key={i}
                  vox={ehVox}
                  beat={b}
                  index={i}
                  intervalo={intervalo(i, durCena)}
                  maxChars={MAX_CHARS}
                  tamanho={tamScript(b.script)}
                  gerando={cenasGerando[i] !== undefined}
                  travado={anyBusy}
                  onEditar={(campo, valor) => editarBeat(i, campo, valor)}
                  onGerar={() => gerarCena(i)}
                />
              ))}
            </div>
            {/* ⏱️ ROTEIRO COM TIMESTAMPS — o storyboard como texto copiável, em dois formatos:
                [início–fim] frase (pra sincronizar edição externa) e capítulos do YouTube
                (`00:00 Título`, só o início + caption — a 1ª linha em 00:00 é exigência do
                próprio YouTube pra ativar os capítulos). */}
            <details style={{ background: "var(--bg2)", border: "1px solid var(--line)", borderRadius: 10, padding: "10px 12px" }}>
              <summary className="txt" style={{ cursor: "pointer", fontSize: ".82rem", fontWeight: 600 }}>
                🕒 Roteiro com timestamps <span style={{ color: "var(--muted)", fontWeight: 400 }}>— copiável (edição externa · capítulos do YouTube)</span>
              </summary>
              <div style={{ display: "flex", flexDirection: "column", gap: 10, marginTop: 8 }}>
                <div>
                  <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", gap: 8, marginBottom: 4 }}>
                    <span className="txt" style={{ fontSize: ".78rem", color: "var(--muted)" }}>Roteiro completo — um intervalo por cena</span>
                    <button type="button" className="btn edit" style={{ flex: "none", padding: "3px 10px", fontSize: ".74rem" }}
                      onClick={() => copiar(roteiroComTimestamps(beats), "roteiro")}>
                      {copiado === "roteiro" ? "✅ Copiado" : "📋 Copiar"}
                    </button>
                  </div>
                  <pre className="txt" style={{ margin: 0, padding: 10, background: "var(--panel2)", border: "1px solid var(--line2)", borderRadius: 8, fontSize: ".76rem", whiteSpace: "pre-wrap", overflowX: "auto" }}>
                    {roteiroComTimestamps(beats)}
                  </pre>
                </div>
                <div>
                  <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", gap: 8, marginBottom: 4 }}>
                    <span className="txt" style={{ fontSize: ".78rem", color: "var(--muted)" }}>Capítulos (YouTube) — cole na descrição do vídeo</span>
                    <button type="button" className="btn edit" style={{ flex: "none", padding: "3px 10px", fontSize: ".74rem" }}
                      onClick={() => copiar(capitulosYouTube(beats), "capitulos")}>
                      {copiado === "capitulos" ? "✅ Copiado" : "📋 Copiar"}
                    </button>
                  </div>
                  <pre className="txt" style={{ margin: 0, padding: 10, background: "var(--panel2)", border: "1px solid var(--line2)", borderRadius: 8, fontSize: ".76rem", whiteSpace: "pre-wrap", overflowX: "auto" }}>
                    {capitulosYouTube(beats)}
                  </pre>
                </div>
              </div>
            </details>

            {/* Montagem FINAL do cena a cena: concat das cenas prontas (+ trilha se 🎵 ligado).
                Fica aqui embaixo porque só faz sentido depois de olhar as cenas. */}
            {(() => {
              const prontos = beats.filter((b) => b.clip_url).length;
              return (
                <div style={{ display: "flex", gap: 10, alignItems: "center", flexWrap: "wrap", borderTop: "1px solid var(--line)", paddingTop: 10 }}>
                  <button className="btn ok" style={{ flex: "none", padding: "9px 14px" }}
                    disabled={anyBusy || prontos < 2 || Object.keys(cenasGerando).length > 0} onClick={montarCenas}
                    title="Concatena as cenas prontas, na ordem do storyboard, num filme só (trilha opcional pelo toggle 🎵 Música).">
                    {busy === "montar" ? "Montando…" : `🎬 Montar filme (${prontos}/${beats.length} cenas prontas)`}
                  </button>
                  <span className="txt" style={{ color: "var(--muted)", fontSize: ".74rem" }}>
                    Monta com a <strong>voz e a legenda</strong> das falas do storyboard (toggles acima). A tipografia
                    animada do Vox só sai pelo “Gerar Filme Completo”, que produz a peça do zero.
                  </span>
                </div>
              );
            })()}
          </div>
        )}
        </div>
      </article>

      {/* 🎬 MONTADOR — pra quem já tem os clipes prontos (gerados fora, ex.: Google Flow) e só
          precisa do filme final: sobe os MP4s, ordena, escreve a fala de cada cena e monta.
          Montagem COMPLETA desde 2026-08-06: narração ancorada por cena + legenda word-level +
          trilha, tudo pelo /v1/filmassemble → ffmpeg-service /concat-clips (que já sabia fazer
          isso; faltava a tela mandar `scripts`). Sem falas escritas = concat puro, como antes. */}
      <article className="card" style={{ marginTop: 16 }}>
        <div className="body" style={{ gap: 12 }}>
        <strong className="title">🎬 Montar filme</strong>
        <p className="txt" style={{ color: "var(--muted)", margin: "-4px 0 0", fontSize: ".82rem" }}>
          Já tem os clipes prontos? Suba os vídeos, arrume a ordem, escreva a <strong>fala de cada
          cena</strong> e monte a peça completa (voz + legenda + trilha) — ela cai na
          <strong> Galeria</strong>. O áudio original dos clipes é preservado por baixo.
        </p>
        {/* Dropzone no MESMO desenho do compositor de posts (NovoPost): caixa tracejada em
            var(--line2) sobre var(--bg2). Era um <input type=file> cru no meio do card. */}
        <label htmlFor="vox-clipes" style={dropzone}>
          <span style={{ fontSize: ".9rem", color: "var(--text)", fontWeight: 600 }}>
            {subindo ? "⏳ Subindo…" : "🎞️ Clique para escolher os clipes"}
          </span>
          <span style={{ fontSize: ".74rem", color: "var(--muted)" }}>MP4/MOV, até 100MB cada · dá pra escolher vários de uma vez</span>
        </label>
        <input id="vox-clipes" type="file" accept="video/mp4,video/quicktime" multiple disabled={anyBusy}
          onChange={(e) => { void subirClipes(e.target.files); e.target.value = ""; }} style={{ display: "none" }} />
        {clipes.length === 0 && !subindo && (
          <div className="empty" style={{ padding: 22, fontSize: ".85rem" }}>Nenhum clipe na fila de montagem ainda.</div>
        )}
        {clipes.length > 0 && (
          <div style={{ display: "flex", flexDirection: "column", gap: 6 }}>
            {clipes.map((c, i) => (
              // 🖐️ Arraste pra reordenar (↑/↓ continuam como fallback de teclado). preventDefault
              // no dragOver é o que habilita o drop no HTML5 — sem ele o navegador recusa.
              <div key={c.url + i} style={{ display: "flex", flexDirection: "column" }}>
              <div draggable={!anyBusy}
                onDragStart={() => setDragIdx(i)}
                onDragOver={(e) => e.preventDefault()}
                onDrop={(e) => { e.preventDefault(); soltarClipe(i); }}
                onDragEnd={() => setDragIdx(null)}
                title="Arraste pra mudar a posição na montagem"
                style={{ display: "flex", alignItems: "center", gap: 8, background: "var(--bg2)", border: `1px solid ${dragIdx === i ? "var(--red)" : "var(--line)"}`, borderRadius: 8, padding: "6px 10px", cursor: anyBusy ? "default" : "grab", opacity: dragIdx === i ? 0.6 : 1 }}>
                <span className="txt" style={{ color: "var(--muted)", fontSize: ".78rem" }} aria-hidden>⠿</span>
                <span className="txt" style={{ color: "var(--muted)", fontSize: ".78rem", minWidth: 18 }}>{i + 1}</span>
                <span className="txt" style={{ flex: 1, minWidth: 0, overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap", fontSize: ".82rem" }}>{c.name}</span>
                {/* ⏱️ DURAÇÃO REAL do clipe — é ela que define quanto texto cabe nesta cena. */}
                <span className="txt" style={{ color: "var(--muted)", fontSize: ".76rem", whiteSpace: "nowrap" }}
                  title={c.dur > 0 ? "Duração medida no arquivo — o teto de caracteres da fala sai daqui." : "Não deu pra medir a duração deste arquivo; o teto de caracteres fica desligado nesta cena."}>
                  {c.dur > 0 ? `${c.dur.toFixed(1)}s` : "—"}
                </span>
                <button type="button" className="btn edit" style={{ flex: "none", padding: "2px 8px" }} disabled={anyBusy || i === 0} onClick={() => moverClipe(i, -1)} title="Subir na ordem">↑</button>
                <button type="button" className="btn edit" style={{ flex: "none", padding: "2px 8px" }} disabled={anyBusy || i === clipes.length - 1} onClick={() => moverClipe(i, 1)} title="Descer na ordem">↓</button>
                <button type="button" className="btn edit" style={{ flex: "none", padding: "2px 8px" }} disabled={anyBusy} onClick={() => setClipes((cs) => cs.filter((_, k) => k !== i))} title="Tirar da montagem (o arquivo continua no acervo)">✕</button>
                </div>
                {/* 🎙️ A FALA desta cena. O contador usa a duração REAL do clipe (não os 6s fixos
                    do Vox): a montagem não estica o vídeo, então texto a mais vaza pra cena
                    seguinte e a última é cortada. */}
                {montNarracao && (() => {
                  const teto = c.dur > 0 ? tetoChars(c.dur) : 0;
                  const tam = c.script.trim().length;
                  const estourou = teto > 0 && tam > teto;
                  return (
                    <div style={{ display: "flex", flexDirection: "column", gap: 3, marginTop: 6 }}>
                      <textarea value={c.script} rows={2} disabled={anyBusy}
                        onChange={(e) => setClipes((cs) => cs.map((x, k) => (k === i ? { ...x, script: e.target.value } : x)))}
                        placeholder={`Fala da cena ${i + 1} — o que o narrador diz enquanto este clipe roda`}
                        style={{ ...ta, ...(estourou ? { borderColor: "var(--red)" } : {}) }} />
                      <span style={{ alignSelf: "flex-end", fontSize: 10, color: estourou ? "var(--red)" : "var(--muted)", fontWeight: estourou ? 600 : 400 }}>
                        {teto > 0 ? `${tam}/${teto}${estourou ? " · não cabe neste clipe" : ""}` : `${tam} caracteres · duração desconhecida`}
                      </span>
                    </div>
                  );
                })()}
              </div>
            ))}
          </div>
        )}

        {/* 📋 ROTEIRO INTEIRO → uma fala por cena. Atalho pra quem já exportou o TXT/SRT da
            ferramenta externa em vez de digitar cena a cena. */}
        {clipes.length > 0 && (
          <details style={{ background: "var(--bg2)", border: "1px solid var(--line)", borderRadius: 10, padding: "10px 12px" }}>
            <summary className="txt" style={{ cursor: "pointer", fontSize: ".82rem", fontWeight: 600 }}>
              📋 Colar roteiro inteiro <span style={{ color: "var(--muted)", fontWeight: 400 }}>— distribui uma fala por cena (TXT linha a linha ou SRT)</span>
            </summary>
            <textarea value={montRoteiro} onChange={(e) => setMontRoteiro(e.target.value)} rows={5} disabled={anyBusy}
              placeholder={"Uma linha por cena…\n\nou cole o SRT exportado da ferramenta (os tempos dele são ignorados: na montagem cada fala é ancorada no início da SUA cena)."}
              style={{ ...ta, marginTop: 8 }} />
            <button type="button" className="btn edit" style={{ flex: "none", padding: "6px 12px", fontSize: ".8rem", marginTop: 6 }}
              disabled={anyBusy} onClick={distribuirRoteiro}>
              ↧ Distribuir pelas {clipes.length} cenas
            </button>
          </details>
        )}
        <div style={{ display: "flex", gap: 14, flexWrap: "wrap", alignItems: "flex-end" }}>
          <div style={{ display: "flex", flexDirection: "column", gap: 4 }}>
            <label className="txt" style={lbl}>Formato</label>
            <select value={montAspect} onChange={(e) => setMontAspect(e.target.value)} style={sel} title="Proporção do filme montado — os clipes são normalizados pra ela.">
              <option value="9:16">🖼️ 9:16 vertical</option>
              <option value="16:9">🖼️ 16:9 horizontal</option>
            </select>
          </div>
          {/* 🎙️/🔤/🎵 — os três canais da peça. Narração ligada abre o campo de fala em cada
              clipe; a legenda word-level só existe COM narração (é o áudio que dá os tempos —
              regra do próprio ffmpeg-service), então ela acompanha o toggle de voz. */}
          <label className="txt" title="Lê a fala escrita em cada clipe, ancorada no início da SUA cena (anchor_voice_starts)."
            style={{ display: "flex", alignItems: "center", gap: 6, fontSize: ".82rem", cursor: "pointer", paddingBottom: 9 }}>
            <input type="checkbox" checked={montNarracao} onChange={(e) => { setMontNarracao(e.target.checked); if (!e.target.checked) setMontLegenda(false); }} /> 🎙️ Narração por cena
          </label>
          <label className="txt" title={montNarracao ? "Legenda word-level, sincronizada com a locução." : "Ligue a narração antes: a legenda word-level nasce dos tempos do áudio."}
            style={{ display: "flex", alignItems: "center", gap: 6, fontSize: ".82rem", cursor: montNarracao ? "pointer" : "default", opacity: montNarracao ? 1 : 0.5, paddingBottom: 9 }}>
            <input type="checkbox" checked={montLegenda} disabled={!montNarracao} onChange={(e) => setMontLegenda(e.target.checked)} /> 🔤 Legenda
          </label>
          <label className="txt" title="Gera uma trilha instrumental e mixa POR BAIXO do áudio original dos clipes."
            style={{ display: "flex", alignItems: "center", gap: 6, fontSize: ".82rem", cursor: "pointer", paddingBottom: 9 }}>
            <input type="checkbox" checked={montMusica} onChange={(e) => setMontMusica(e.target.checked)} /> 🎵 Trilha de fundo
          </label>
          {montMusica && (
            <input value={montMusicaDesc} onChange={(e) => setMontMusicaDesc(e.target.value)} maxLength={300}
              placeholder="Clima da trilha (opcional) — ex.: documental, tensa, piano minimalista"
              style={{ ...sel, flex: 1, minWidth: 220 }} />
          )}
          <button className="btn ok" style={{ flex: "none", padding: "9px 14px" }} disabled={anyBusy || clipes.length < 2} onClick={montarFilme}
            title="Monta os clipes na ordem acima (com voz/legenda/trilha, se ligadas) e salva o filme na Galeria.">
            {busy === "montar" ? "Montando…" : "🎬 Montar"}
          </button>
        </div>
        {montNarracao && (
          <p className="txt" style={{ color: "var(--muted)", margin: 0, fontSize: ".76rem" }}>
            🎙️ Narrador: <strong>{vozes.find((v) => v.id === (voz || vozTenant))?.name ?? "voz padrão da conta"}</strong> — é o
            MESMO seletor de narrador do painel acima (a peça tem uma voz só). A montagem <strong>não estica o vídeo</strong>:
            a fala que não cabe no clipe vaza pra cena seguinte, por isso o contador barra antes.
          </p>
        )}
        {montMsg && (montMsg.startsWith("❌")
          ? <div className="err" style={{ margin: 0 }}>{montMsg.replace(/^❌\s*/, "")}</div>
          : <p className="txt" style={{ margin: 0, fontSize: ".88rem" }}>{montMsg}</p>)}
        </div>
      </article>

      {/* ✍️ TEXTO DE PUBLICAÇÃO — o fim do fluxo: com o filme montado (ou o storyboard pronto),
          gera a copy de cada rede a partir do tema+roteiro. Mesmo POST /api/studio/text da aba
          Conteúdo/compositor — o draft do Vox já tem keyword = tema (voxStoryboard), e é dela
          que o redator tira o assunto. Texto editável + ↻ por rede; publicar é na aba Publicar. */}
      <article className="card" style={{ marginTop: 16 }}>
        <div className="body" style={{ gap: 12 }}>
        <strong className="title">✍️ Texto de publicação</strong>
        <p className="txt" style={{ color: "var(--muted)", margin: "-4px 0 0", fontSize: ".82rem" }}>
          A peça pronta ainda precisa da <strong>legenda</strong>: gere a copy de cada rede a partir do tema e do
          roteiro. Os textos ficam salvos no rascunho — a publicação segue o caminho normal (aba <a href="/publicar" style={{ color: "var(--green)" }}>Publicar</a>).
        </p>
        {redes.length === 0
          ? <p className="txt" style={{ color: "var(--muted)", margin: 0, fontSize: ".82rem" }}>Nenhuma rede conectada. Vá em <a href="/conexoes" style={{ color: "var(--green)" }}>Conexões</a> para conectar.</p>
          : (
            <div style={{ display: "flex", gap: 8, flexWrap: "wrap", alignItems: "center" }}>
              {redes.map((p) => (
                <button key={p} type="button" className={redesSel.includes(p) ? "chip on" : "chip"}
                  onClick={() => setRedesSel((s) => (s.includes(p) ? s.filter((x) => x !== p) : [...s, p]))}>
                  {PLATFORM_LABEL[p] ?? p}
                </button>
              ))}
              <button type="button" className="btn edit" style={{ flex: "none", padding: "6px 12px", fontSize: ".8rem" }}
                disabled={textoBusy !== null || anyBusy || redesSel.length === 0 || !beats}
                title={beats ? "Gera a legenda de cada rede escolhida a partir do tema + roteiro" : "Gere o storyboard antes — a copy nasce dele"}
                onClick={gerarTextos}>
                {textoBusy === "all" ? "Gerando…" : "✨ Gerar texto de publicação"}
              </button>
            </div>
          )}
        {redesSel.map((p) => (
          <label key={p}>
            <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", gap: 8, marginBottom: 4 }}>
              <span className="txt" style={{ fontSize: ".82rem" }}>{PLATFORM_LABEL[p] ?? p}</span>
              <button type="button" className="btn edit" style={{ flex: "0 0 auto", padding: "3px 10px", fontSize: ".74rem", whiteSpace: "nowrap" }}
                disabled={textoBusy !== null || anyBusy} onClick={() => regerarTexto(p)}
                title="Regenera só o texto desta rede — as outras ficam como estão.">
                {textoBusy === p ? "…" : "↻"}
              </button>
            </div>
            {/* onBlur persiste a EDIÇÃO manual (PATCH): o POST já salva o texto gerado no
                rascunho, mas a correção feita aqui morreria no F5 — e é ela que publica. */}
            <textarea value={textos[p] ?? ""} onChange={(e) => setTextos((t) => ({ ...t, [p]: e.target.value }))} rows={4}
              onBlur={() => {
                const draftId = typeof window !== "undefined" ? localStorage.getItem(DRAFT_KEY) : null;
                const text = String(textos[p] ?? "").trim();
                if (draftId && text) void sfetch("/api/studio/text", { method: "PATCH", body: JSON.stringify({ draftId, platform: p, text }) }).catch(() => {});
              }}
              placeholder={`Texto do ${PLATFORM_LABEL[p] ?? p} — gere com ✨ ou escreva aqui`} style={ta} />
          </label>
        ))}
        </div>
      </article>

      {/* 🖼️ Seletor do acervo/upload pra imagem-base do estilo genérico. */}
      <EscolherImagem
        aberto={escolhendoBase}
        onFechar={() => setEscolhendoBase(false)}
        onEscolher={(u) => setImagemBase(u)}
        titulo="Imagem base do vídeo"
      />
    </>
  );
}
