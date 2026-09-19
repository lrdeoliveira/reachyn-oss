"use client";

import Link from "next/link";
import { useEffect, useMemo, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { RichTextArea } from "@/components/RichTextArea";
import { NetworkPreviewTabs, NET } from "@/components/NetworkPreview";
import { GalleryPicker } from "@/components/GalleryPicker";
import { PromptPicker } from "@/components/PromptPicker";
import { Carrossel } from "@/components/Carrossel";
import { SeletorModeloImagem } from "@/components/SeletorModeloImagem";
import { EscolherImagem } from "@/components/EscolherImagem";
import { sfetch } from "@/lib/api";
import { useDialogo } from "@/components/ui/Dialogo";
import { useJobs } from "@/lib/jobs";
import { TextModelSelect } from "@/components/TextModelSelect";
// Vocabulário ÚNICO de "onde a peça é gerada" (mesmo das abas Imagem/Vídeo): a origem na frente
// do nome no <option>, o rótulo completo no badge e a luz do ComfyUI ao lado do botão que depende
// dele. Sem isto, a Mídia listava "Nano Banana 2 · 2 créd" sem dizer de qual conta sai a peça.
import { ajudaMotor, marcaMotor, nomeModelo, rotuloMotor, type MotorLugar } from "@/lib/motor";
import { ComfyStatus, useComfy } from "@/components/ComfyStatus";

const CONSOLE = process.env.NEXT_PUBLIC_CONSOLE_URL ?? "";

// Textarea que cresce com o conteúdo (sem scroll interno) e continua editável.
function AutoTextarea({ value, onChange, style, placeholder, minHeight = 70 }: { value: string; onChange: (v: string) => void; style?: React.CSSProperties; placeholder?: string; minHeight?: number }) {
  const ref = useRef<HTMLTextAreaElement>(null);
  useEffect(() => {
    const el = ref.current;
    if (el) { el.style.height = "auto"; el.style.height = Math.max(minHeight, el.scrollHeight) + "px"; }
  }, [value, minHeight]);
  return <textarea ref={ref} value={value} placeholder={placeholder} onChange={(e) => onChange(e.target.value)} style={{ ...style, minHeight, overflow: "hidden", resize: "none" }} />;
}

// 📰 O card VOX morava aqui e virou ABA PRÓPRIA (hoje /video, estilo "vox" — components/video/VideoStudio.tsx) em
// 2026-08-05: storyboard revisável, presets de roteiro/estilo/direção e montador de clipes.
type Research = { answer: string; brief: string; summary: string; results: { title: string; url: string; source?: string }[] };
type Media = { id: string; kind: string; url: string; style?: string; platforms?: string[]; scene?: number; composed?: boolean };
// v2: qualidade (resolução) com preço por duração (p5=5s, p10=10s), já com margem.
type VQuality = { key: string; label: string; p5: number | null; p10: number | null; p?: number | null };
// `runs_on`/`origem` vêm do MESMO GenModelResource que as abas Imagem/Vídeo já liam (a Mídia
// chama /gen-models sem `all=1`, mas o recurso é o mesmo) — só não estavam declarados aqui, então
// o dado chegava e era jogado fora. São eles que dizem ONDE a peça vai ser gerada (☁️ nuvem que
// cobra crédito · 🎛️ ComfyUI que depende do servidor de pé · 💻 este Mac por assinatura), que é a
// informação que muda a decisão ANTES do clique. Ver lib/motor.ts.
type MotorFields = { runs_on?: MotorLugar; origem?: string };
type VideoModel = { slug: string; display_name: string; cost_credits: number | null; real_name?: string; qualities?: VQuality[]; default_quality?: string } & MotorFields;
type ImageModel = { slug: string; display_name: string; cost_credits: number | null; real_name?: string; qualities?: VQuality[]; default_quality?: string } & MotorFields;
// TÉCNICA da imagem (do que ela é feita) — catálogo único em lib/imageStyles, compartilhado
// com Personagens. A COR mora no seletor 🎨 Cor (GRADE_OPTIONS), como no painel de vídeo.
import { IMAGE_STYLES as STYLES, imageStyleLabel } from "@/lib/imageStyles";
import { MOTION_STRUCTURES, MOTION_DUR_MIN, MOTION_DUR_MAX, parseFrases, telaEstaticaPrompt } from "@/lib/motion";
// 🎨 Color grade da montagem final (Sprint B) — os mesmos 5 presets em Story/Filme/Studio.
import { GRADE_OPTIONS } from "@/lib/effects";
import { EASYAPPS, easyAppDe, easyAppParams } from "@/lib/easyapps";
// Papéis da referência i2i — o que APROVEITAR de cada imagem. Espelha StudioController::REF_ROLES.
type RefRole = "identidade" | "composicao" | "estilo";
const REF_ROLES: [RefRole, string, string][] = [
  ["identidade", "🎭 Identidade", "Copia o assunto exatamente — mesma pessoa, produto ou personagem."],
  ["composicao", "🖼️ Composição", "Copia o enquadramento e o ângulo. Ignora o assunto e as cores."],
  ["estilo", "🎨 Direção de arte", "Copia paleta, luz e textura. Ignora o assunto e o enquadramento."],
];
// A biblioteca de estilos nomeia cada item pela FAMÍLIA a que pertence — "🎨 Estilo: Cartoon 2D",
// "🎨 Cor: Teal & Orange", "🎨 Base: Qualidade Visual". Numa lista chapada as famílias se embolam
// e o seletor de Estilo acaba oferecendo cor no meio do caminho. Agrupa por família (<optgroup>)
// e tira o prefixo repetido de cada item, já que o rótulo do grupo o diz.
const FAMILIA_RX = /^[^\p{L}]*(\p{L}+):\s*/u;
const familiaDe = (t: string) => (t || "").match(FAMILIA_RX)?.[1] ?? "Outros";
const tituloLimpo = (t: string) => (t || "").replace(FAMILIA_RX, "").trim() || t;
// Estilo primeiro (é o uso principal do seletor), Cor depois, o resto no fim.
const ORDEM_FAMILIA = ["Estilo", "Cor", "Base"];
function agruparPersonas<T extends { id: number; title: string }>(list: T[]): [string, T[]][] {
  const porFamilia = new Map<string, T[]>();
  for (const p of list) {
    const f = familiaDe(p.title);
    const atual = porFamilia.get(f);
    if (atual) atual.push(p); else porFamilia.set(f, [p]);
  }
  const peso = (f: string) => { const i = ORDEM_FAMILIA.indexOf(f); return i < 0 ? 99 : i; };
  return [...porFamilia.entries()].sort((a, b) => peso(a[0]) - peso(b[0]) || a[0].localeCompare(b[0]));
}
const VIDEO_STYLES: [string, string][] = [
  ["cinematografico", "🎬 Cinematográfico"], ["dinamico", "⚡ Dinâmico"], ["documental", "📹 Documental"],
  ["timelapse", "⏱️ Timelapse"], ["anime", "🎌 Anime"], ["3d", "🧸 3D / Pixar"],
  ["noir", "🎞️ Noir"], ["vintage", "📺 Vintage"], ["aereo", "🚁 Aéreo"],
  ["slowmotion", "🐢 Slow-motion"], ["cyberpunk", "🌃 Cyberpunk"], ["vlog", "🎙️ Vlog"],
];
// Presets de LOGOTIPO (o look; o subject vem do prompt). Texto em IA é fraco → presets são icônicos.
const LOGO_PRESETS: [string, string][] = [
  ["minimalista", "◻️ Minimalista (vetor flat)"], ["mascote", "🦊 Mascote"], ["emblema", "🛡️ Emblema / Badge"],
  ["moderno", "✨ Moderno (app icon)"], ["lettering", "🔤 Lettering / Monograma"],
];
const STYLE_LABELS: Record<string, string> = { ...Object.fromEntries(STYLES), ...Object.fromEntries(VIDEO_STYLES), viral: "✨ Viral", logo: "🏷️ Logo", gif: "✨ GIF" };
type Step = "research" | "content" | "media" | "approve" | "publish";
const KEY = "reachyn_draft";
// Opções de duração do vídeo direto (o provider gera 5s ou 10s por clipe).
const DURATIONS: [string, string][] = [["5", "5s"], ["10", "10s"]];
// Formatos: imagem aceita os 4; vídeo só vertical (9:16) ou horizontal (16:9).
const IMG_ASPECTS: [string, string][] = [["9:16", "9:16 vertical"], ["1:1", "1:1 quadrado"], ["16:9", "16:9 horizontal"], ["4:5", "4:5 retrato"]];
const VID_ASPECTS: [string, string][] = [["9:16", "9:16 vertical"], ["16:9", "16:9 horizontal"]];
// Teto de duração total do vídeo: 5 min (300s). nº máx de cenas = 300 / duração-do-clipe.
const MAX_VIDEO_SECONDS = 300;
const maxScenesFor = (dur: string) => Math.floor(MAX_VIDEO_SECONDS / (dur === "10" ? 10 : 5)); // 300/5=60; 300/10=30
// Idioma da narração — vale para o vídeo sincronizado E para o Vídeo Premium (premium).
const LANGS: [string, string][] = [["pt-BR", "Português (BR)"], ["en-US", "Inglês (US)"]];
// Fallback das vozes (BR) — usado só se a lista dinâmica da conta falhar/vier vazia, p/ nunca
// regredir a zero vozes. A lista real vem de GET /api/studio/voices.
const VOICES_FALLBACK = [
  { id: "Ey5AWb48tVX1IOcikcht", name: "Marcelo", gender: "male", accent: "BR" },
  { id: "pzfB7SVzqAOWhzxYtlEZ", name: "Wagner", gender: "male", accent: "BR" },
  { id: "YD2yOZItFdFEh7WxPLnp", name: "Juliana", gender: "female", accent: "BR" },
  { id: "jXut6osIUq8fzeZmDtEf", name: "Alina", gender: "female", accent: "BR" },
];

export function Studio({ step }: { step: Step }) {
  const jobs = useJobs();
  const router = useRouter();
  const [draftId, setDraftId] = useState<string | null>(null);
  const [keyword, setKeyword] = useState("");
  const [textModel, setTextModel] = useState(""); // seletor de modelo de texto (resumo)
  const [sources, setSources] = useState<string[]>(["web"]);
  const [research, setResearch] = useState<Research | null>(null);
  const [texts, setTexts] = useState<Record<string, string>>({});
  const [textMeta, setTextMeta] = useState<Record<string, { grounding: number; rank?: number; flags: string[] }>>({});
  // #3 idioma por rede: 'pt-BR' | 'en-US' por plataforma + padrão da conta (vem de /api/usage).
  const [textLangs, setTextLangs] = useState<Record<string, string>>({});
  const [defaultLang, setDefaultLang] = useState("pt-BR");
  const [editRef, setEditRef] = useState(false);
  const [refDraft, setRefDraft] = useState("");
  // 🎬 Roteirista do post (aba Prompts "🎬 Roteirista: …") — craft/voz de TODO texto gerado
  // (posts por rede E regeração do resumo). "" = redator padrão do engine.
  const [roteiristas, setRoteiristas] = useState<{ name: string; content: string }[]>([]);
  const [roteirista, setRoteirista] = useState("");
  const [refOpen, setRefOpen] = useState(false); // Mídia: referência colapsada por padrão (expansível)
  const [media, setMedia] = useState<Media[]>([]);
  // #2 mídia por rede: redes-alvo da PRÓXIMA mídia gerada (vazio = serve todas as redes).
  const [mediaNets, setMediaNets] = useState<string[]>([]);
  // Filtro da galeria por rede ("" = todas).
  const [galleryFilter, setGalleryFilter] = useState("");
  // Pós-processamento (Melhorar/Remover fundo) nos itens de imagem da Mídia — custo por op (kind='edit').
  const [editCosts, setEditCosts] = useState<Record<string, number | null>>({});
  const [enhancing, setEnhancing] = useState(""); // "op:mediaId" em processo
  // Ajuste (EasyApp) escolhido numa imagem da grade: abre um campo pro parâmetro antes de aplicar,
  // porque "reiluminar" sem dizer o clima da luz é o backend adivinhando.
  const [ajuste, setAjuste] = useState<{ id: string; kind: string; valor: string } | null>(null);
  const [ajusteChar, setAjusteChar] = useState(""); // personagem, só p/ trocar roupa
  // 🟢 Luz do ComfyUI: conta se o servidor de geração está de pé ANTES de gastar o clique num
  // modelo 🎛️. Sem ela, estúdio caído só aparecia como timeout depois de minutos esperando.
  const comfy = useComfy();
  // PERSONAGEM da geração (IDENTITY LOCK): o engine resolve o `lock` do id e injeta no prompt.
  // Um só estado pros dois cards — a mídia do post é do MESMO personagem, e escolher a Mel na
  // imagem e esquecer no clipe era justamente como a identidade se perdia entre as duas peças.
  const [genChar, setGenChar] = useState("");
  const [personagens, setPersonagens] = useState<{ id: number; name: string }[]>([]);
  useEffect(() => {
    sfetch("/api/characters").then((r) => r.json()).then((j) => {
      setPersonagens((Array.isArray(j) ? j : j?.data ?? []).map((c: { id: number; name?: string }) => ({ id: c.id, name: c.name || `#${c.id}` })));
    }).catch(() => {});
  }, []);
  // Compositor de posts (next/og): veste a imagem crua com a identidade da marca.
  const [composeSrc, setComposeSrc] = useState<Media | null>(null);
  const [composing, setComposing] = useState(false);
  const [cForm, setCForm] = useState({ format: "feed", kicker: "", titulo: "", subtitulo: "", cta: "" });
  const [cCarousel, setCCarousel] = useState(false); // modo carrossel: cada linha do título = 1 slide
  const [imagePrompt, setImagePrompt] = useState("");
  const [videoPrompt, setVideoPrompt] = useState("");
  const [musicPrompt, setMusicPrompt] = useState(""); // descrição da música (estilo/mood/tema)
  const [musicInstrumental, setMusicInstrumental] = useState(false); // sem vocais
  // 🎞️ MOTION — o card gera uma TELA ESTÁTICA, o usuário APROVA, e só então o clipe é enfileirado.
  // `motionTela` é o quadro pendente de aprovação: enquanto ele existe, o card mostra
  // "Aprovar e animar" / "Refazer". Refazer só descarta a tela — não cobra vídeo.
  // 🖼️ Imagem de PARTIDA do motion: em vez de sempre gerar a tela por prompt, dá pra trazer uma
  // do acervo ou do computador. Sem isto, quem já tinha a arte pronta não conseguia animá-la aqui.
  // 🎬 Clipes de motion ACUMULADOS nesta sessão. Cada aprovação de tela vira um clipe; quando há
  // 2 ou mais, dá pra juntar tudo num vídeo só. Antes o motion parava numa peça de 4-15s e montar
  // uma sequência exigia baixar cada clipe e abrir um editor fora.
  const [motionClipes, setMotionClipes] = useState<string[]>([]);
  const [motionBase, setMotionBase] = useState("");
  const [motionEscolher, setMotionEscolher] = useState(false);
  const [motionDesc, setMotionDesc] = useState("");
  const [motionStruct, setMotionStruct] = useState("camadas");
  const [motionStyle, setMotionStyle] = useState("colagem"); // técnica: a colagem é a linguagem web-doc
  const [motionAspect, setMotionAspect] = useState("9:16");
  const [motionSecs, setMotionSecs] = useState(8);
  const [motionLines, setMotionLines] = useState(""); // uma frase por linha (estrutura cartelas)
  const [motionTela, setMotionTela] = useState<string | null>(null);
  const [promptTarget, setPromptTarget] = useState<"" | "image" | "video">(""); // qual campo o PromptPicker carrega
  const [imgStyle, setImgStyle] = useState("realista");
  const [logoPreset, setLogoPreset] = useState("minimalista"); // preset do logotipo (Gerar imagem → Logo)
  const [vidStyle, setVidStyle] = useState("cinematografico");
  // 🎨 Acabamento Hollywood (Sprint B) — color grade + grain da montagem.
  const [grade, setGrade] = useState("natural");
  const [grain, setGrain] = useState(false);
  const [duration, setDuration] = useState("5"); // duração de cada clipe (s) — provider: 5/10
  const [scenes, setScenes] = useState(5); // nº de cenas do vídeo sincronizado (input manual)
  const [imgAspect, setImgAspect] = useState("9:16"); // formato da imagem: 9:16|1:1|16:9|4:5
  const [vidAspect, setVidAspect] = useState("9:16"); // formato do vídeo: 9:16|16:9
  const [voice, setVoice] = useState("Ey5AWb48tVX1IOcikcht"); // voz default
  const [voices, setVoices] = useState<{ id: string; name: string; gender?: string; accent?: string; language?: string; category?: string }[]>(VOICES_FALLBACK); // vozes da conta (dinâmicas; fallback BR)
  const [lang, setLang] = useState("pt-BR"); // idioma da narração: vale p/ sincronizado E Vídeo Premium (premium)
  // Painel unificado "Gerar vídeo": opções combináveis.
  const [narration, setNarration] = useState(false); // narração com voz (mostra voz+idioma)
  const [subtitles, setSubtitles] = useState(false);  // legenda na tela (permitida sem narração)
  // Estilo da legenda QUEIMADA (mesmo do Histórias) — o vídeo já sai com a legenda no visual escolhido.
  const [subtitlePos, setSubtitlePos] = useState("bottom");    // posição: bottom | middle | top
  const [subtitleSize, setSubtitleSize] = useState(20);        // tamanho da fonte (12..56)
  const [subtitleColor, setSubtitleColor] = useState("#FFFFFF"); // cor do texto
  const [subtitleBorder, setSubtitleBorder] = useState(3);     // espessura do contorno (1..10)
  const [subtitleBorderColor, setSubtitleBorderColor] = useState("#000000"); // cor do contorno
  const [subtitleFont, setSubtitleFont] = useState("sans");    // fonte: sans|serif|mono|dejavu|dejavu-serif|noto
  const [subtitleOpacity, setSubtitleOpacity] = useState(0);   // transparência do texto 0..90
  const [subtitleBg, setSubtitleBg] = useState(false);         // caixa (fundo) atrás do texto
  const [subtitleBgColor, setSubtitleBgColor] = useState("#000000"); // cor da caixa
  const [subtitleBgOpacity, setSubtitleBgOpacity] = useState(60);    // opacidade da caixa 0..100
  // 🎞️ Legenda ANIMADA (overlay Remotion): "" = queimada (padrão) | pop | karaoke | bounce.
  const [subtitleAnim, setSubtitleAnim] = useState("");        // preset da animação da legenda
  const [subtitleAccentColor, setSubtitleAccentColor] = useState("#FFD700"); // realce da palavra ativa
  const [music, setMusic] = useState(false);          // trilha musical de fundo
  const [premium, setPremium] = useState(false);      // qualidade Premium (premium): 1 cena, áudio nativo
  const [veoNarration, setVeoNarration] = useState(false); // Veo: troca o áudio nativo pela narração própria (voz do tenant) + legenda
  const [clipUrl, setClipUrl] = useState("");
  const [clipN, setClipN] = useState(3);
  const [myVoice, setMyVoice] = useState<string | null>(null);
  // Imagem usada como INPUT do vídeo (i2v / vídeo sincronizado). Vazio = geração por texto (padrão).
  const [inputImageUrl, setInputImageUrl] = useState("");
  const [inputImageSource, setInputImageSource] = useState<"" | "upload" | "galeria">("");
  // PAPEL da referência principal + até 2 refs EXTRAS (o backend aceita 3). Uma referência sem
  // papel é ambígua: o modelo não sabe se copia o assunto, o enquadramento ou só a paleta, e
  // acaba copiando tudo. `inputImageUrl` segue sendo a ref #1 porque o painel de vídeo (i2v) usa
  // ela como primeiro quadro — lá é sempre UMA imagem.
  const [refRole, setRefRole] = useState<RefRole>("identidade");
  const [refsExtra, setRefsExtra] = useState<{ url: string; role: RefRole }[]>([]);
  const [pickerAlvo, setPickerAlvo] = useState<"principal" | "extra">("principal");
  const [pickerOpen, setPickerOpen] = useState(false);
  // Catálogo de MODELOS de vídeo (o padrão vem do back-end, sem min_plan; o vídeo premium premium fica no toggle ⭐).
  // Cada modelo tem custo em créditos próprio (cobrança por modelo). Vazio = back-end usa o default.
  const [videoModels, setVideoModels] = useState<VideoModel[]>([]);
  const [videoModel, setVideoModel] = useState("");
  const [videoQuality, setVideoQuality] = useState(""); // v2: resolução escolhida (key da quality)
  useEffect(() => {
    sfetch("/api/gen-models?kind=video").then((r) => r.json()).then((j) => {
      const list = (Array.isArray(j) ? j : j?.data ?? []).filter((m: { min_plan?: string | null }) => !m.min_plan);
      setVideoModels(list);
      if (list[0]) setVideoModel((cur) => cur || list[0].slug);
    }).catch(() => {});
  }, []);
  // v2: ao trocar de modelo, reseta a qualidade pra default do modelo (ou 1ª). Sem qualidades → "".
  useEffect(() => {
    const m = videoModels.find((x) => x.slug === videoModel);
    setVideoQuality(m?.qualities?.length ? (m.default_quality || m.qualities[0].key) : "");
  }, [videoModel, videoModels]);
  // Catálogo de MODELOS de imagem (text-to-image). Cada um com custo próprio. Vazio = back-end default.
  const [imageModels, setImageModels] = useState<ImageModel[]>([]);
  const [imageModel, setImageModel] = useState("");
  const [imageQuality, setImageQuality] = useState(""); // qualidade (resolução) da imagem
  useEffect(() => {
    sfetch("/api/gen-models?kind=image").then((r) => r.json()).then((j) => {
      const list = (Array.isArray(j) ? j : j?.data ?? []).filter((m: { subtype?: string }) => m.subtype === "text_to_image");
      setImageModels(list);
      // Default = o MAIS BARATO, não o primeiro da lista. `list[0]` era posicional: reordenar o
      // catálogo no Filament trocava, em silêncio, o modelo padrão e o CUSTO de toda geração de
      // mídia. Aqui se gera em volume, então barato é o default certo; empate resolve pela ordem
      // do catálogo. (Personagens usa outro default de propósito — lá a base é a âncora de
      // identidade do personagem inteiro e vale pagar por qualidade; ver Personagens.tsx.)
      const barato = [...list].sort((a: ImageModel, b: ImageModel) =>
        (a.cost_credits ?? Number.MAX_SAFE_INTEGER) - (b.cost_credits ?? Number.MAX_SAFE_INTEGER))[0];
      if (barato) setImageModel((cur) => cur || barato.slug);
    }).catch(() => {});
  }, []);
  // Ao trocar de modelo de imagem, reseta a qualidade pra default (ou 1ª); sem qualidades → "".
  useEffect(() => {
    const m = imageModels.find((x) => x.slug === imageModel);
    setImageQuality(m?.qualities?.length ? (m.default_quality || m.qualities[0].key) : "");
  }, [imageModel, imageModels]);
  // PERSONAS de imagem — a direção de estilo que entra no prompt (lente, luz, grade, textura).
  // Vêm da aba Prompts (kind=image), então o cliente edita as nossas e cria as dele. "custom" =
  // escrever a minha agora, sem salvar; "" = sem persona.
  const [personas, setPersonas] = useState<{ id: number; title: string }[]>([]);
  const [personaId, setPersonaId] = useState(""); // id da persona salva | "custom" | ""
  const [personaText, setPersonaText] = useState(""); // texto livre quando personaId === "custom"
  // A biblioteca mistura famílias na MESMA lista ("🎨 Cor: X", "🎨 Estilo: X", "🎨 Base: X"), mas
  // elas são decisões diferentes e moram em caixas diferentes: as de Cor vão pro seletor 🎨 Cor,
  // o resto fica em Estilo. Ambas gravam no MESMO `personaId` — o backend aceita UMA persona por
  // geração (resolvePersona) —, então escolher de um lado limpa o outro. É o comportamento que já
  // existia; a mudança é o usuário passar a ver ONDE cada coisa mora.
  const personasCor = useMemo(() => personas.filter((p) => familiaDe(p.title) === "Cor"), [personas]);
  const personasEstilo = useMemo(() => personas.filter((p) => familiaDe(p.title) !== "Cor"), [personas]);
  useEffect(() => {
    sfetch("/api/prompts?kind=image").then((r) => r.json()).then((j) => {
      setPersonas(Array.isArray(j) ? j : j?.data ?? []);
    }).catch(() => {});
  }, []);
  // REFINADOR: qual CLI do host reescreve o pedido aplicando a persona antes de gerar.
  // Vazio = sem refino (a persona é só apensada ao prompt). Só aparece se o bridge estiver
  // no ar e com o adapter presente — nada de oferecer motor que não responde.
  const [refiners, setRefiners] = useState<{ id: string; label: string }[]>([]);
  const [refiner, setRefiner] = useState("");
  useEffect(() => {
    sfetch("/api/studio/refiners").then((r) => r.json()).then((j) => {
      if (j?.ok && Array.isArray(j.refiners)) setRefiners(j.refiners);
    }).catch(() => {});
  }, []);
  // Personas de VÍDEO — lista separada (movimento de câmera e ritmo, não composição do frame).
  const [vidPersonas, setVidPersonas] = useState<{ id: number; title: string }[]>([]);
  const [vidPersonaId, setVidPersonaId] = useState("");
  const [vidPersonaText, setVidPersonaText] = useState("");
  useEffect(() => {
    sfetch("/api/prompts?kind=video").then((r) => r.json()).then((j) => {
      setVidPersonas(Array.isArray(j) ? j : j?.data ?? []);
    }).catch(() => {});
  }, []);
  useEffect(() => { sfetch("/api/usage").then((r) => r.json()).then((j) => { if (j?.ok) { setMyVoice(j.voice_id || null); if (j.content_lang) setDefaultLang(j.content_lang); } }).catch(() => {}); }, []);
  // Custos do pós-processamento (Melhorar/Remover fundo) — pros botões na Mídia mostrarem os créditos.
  useEffect(() => {
    sfetch("/api/gen-models?kind=edit").then((r) => r.json()).then((d) => {
      const map: Record<string, number | null> = {};
      for (const m of (Array.isArray(d) ? d : d?.data ?? [])) {
        const op = m.slug === "edit-upscale" ? "upscale" : m.slug === "edit-upscale-pro" ? "upscale_pro" : m.slug === "edit-remove-bg" ? "remove_bg" : null;
        if (op) map[op] = m.cost_credits;
      }
      setEditCosts(map);
    }).catch(() => {});
  }, []);
  // Vozes de narração vêm da conta do provedor (dinâmico) — não mais hardcoded. Se a voz
  // selecionada não estiver na lista carregada, cai na primeira disponível.
  useEffect(() => {
    sfetch("/api/studio/voices").then((r) => r.json()).then((j) => {
      const vs = (j?.ok && Array.isArray(j.voices)) ? j.voices : [];
      if (vs.length) { setVoices(vs); setVoice((cur) => (vs.some((v: { id: string }) => v.id === cur) ? cur : vs[0].id)); }
    }).catch(() => {});
  }, []);
  const [busy, setBusy] = useState<string | null>(null);
  // Trava GLOBAL de execução: enquanto QUALQUER geração/ação estiver rodando
  // (busy = gerações/publicação, enhancing = melhorar/fundo/filtro, composing = compor post),
  // todos os botões de execução ficam desabilitados — evita duplo-clique e execuções concorrentes.
  const anyBusy = busy !== null || !!enhancing || composing;
  const [msg, setMsg] = useState<string | null>(null);
  // Diálogos no visual do site (ver components/ui/Dialogo) — o confirm/prompt nativo destoava.
  const { perguntar, dialogo } = useDialogo();
  const [loaded, setLoaded] = useState(false);
  // Rede ativa na pré-visualização (Aprovar/Publicar). "" = usa a 1ª com texto.
  const [previewNet, setPreviewNet] = useState("");
  // #5: redes selecionadas pra publicar (null = todas com texto, default). Subconjunto de pubPlatforms.
  const [pubSel, setPubSel] = useState<string[] | null>(null);
  // Perfis (profiles conector social) selecionados p/ publicar (null = todos com contas, default). Multi-perfil.
  const [pubProfiles, setPubProfiles] = useState<string[] | null>(null);
  // Comunidade do Reddit (subreddit) — o Reddit publica numa comunidade. Lembrado entre publicações.
  const [redditSubreddit, setRedditSubreddit] = useState("");
  // r/ = comunidade · u/ = perfil do próprio usuário (no Reddit, o perfil é o "subreddit" u_<nome>).
  const [redditAlvo, setRedditAlvo] = useState<"r" | "u">("r");
  // FORMATO do post: no Reddit um post é de um tipo só. "imagem" (default) põe a foto no feed com
  // o título e publica o texto completo no 1º COMENTÁRIO (POST /v1/inbox/comments); "texto" publica
  // o corpo no próprio post e deixa a imagem como link.
  const [redditFormato, setRedditFormato] = useState<"texto" | "imagem">("imagem");
  // TÍTULO do post. No formato imagem é o ÚNICO texto que acompanha a foto (300 chars) — por isso
  // fica à vista e editável, em vez de derivado em silêncio da 1ª linha. Vazio = 1ª linha.
  const [redditTitulo, setRedditTitulo] = useState("");
  // O valor lembrado passou a incluir o prefixo (r/ ou u/) — separa na volta pra que o select e o
  // campo mostrem exatamente a última escolha. Valor antigo (sem prefixo) cai em r/, como antes.
  useEffect(() => {
    const s = typeof window !== "undefined" ? localStorage.getItem("reachyn_reddit_subreddit") : null;
    if (!s) return;
    const m = /^([ru])\/(.+)$/i.exec(s.trim());
    if (m) { setRedditAlvo(m[1].toLowerCase() as "r" | "u"); setRedditSubreddit(m[2]); } else { setRedditSubreddit(s); }
    const f = localStorage.getItem("reachyn_reddit_formato");
    if (f === "imagem" || f === "texto") setRedditFormato(f);
  }, []);
  // Conexões conector social do tenant (carregadas nos passos Aprovar/Publicar p/ casar texto×conta).
  const [conns, setConns] = useState<{ accounts: { platform: string; name: string }[]; manual: { platform: string; status: string }[]; networks: { key: string; label: string; icon: string }[]; profiles: { id: string; name: string; is_default: boolean; accounts: { platform: string; name: string }[] }[] }>({ accounts: [], manual: [], networks: [], profiles: [] });

  // Carrega o rascunho atual (localStorage) ao montar — estado compartilhado entre os itens da sidebar.
  useEffect(() => {
    const id = typeof window !== "undefined" ? localStorage.getItem(KEY) : null;
    if (!id) { setLoaded(true); return; }
    sfetch(`/api/studio/draft?id=${id}`).then((r) => r.json()).then((d) => {
      if (d.ok) {
        setDraftId(d.draft.id); setKeyword(d.draft.keyword || "");
        setResearch(d.draft.research || null); setTexts(d.draft.texts || {});
        if (d.draft.texts_meta && typeof d.draft.texts_meta === "object") {
          setTextMeta(d.draft.texts_meta);
          // #3: recupera o idioma já escolhido por rede (texts_meta[p].lang).
          const langs: Record<string, string> = {};
          for (const [p, m] of Object.entries(d.draft.texts_meta as Record<string, { lang?: string }>)) { if (m?.lang) langs[p] = m.lang; }
          if (Object.keys(langs).length) setTextLangs(langs);
        }
        setMedia(d.draft.media || []);
      } else localStorage.removeItem(KEY);
      setLoaded(true);
    }).catch(() => setLoaded(true));
  }, []);

  // Nos passos de revisão/publicação, busca as contas conectadas (conector social) e blog.
  useEffect(() => {
    if (step !== "approve" && step !== "publish") return;
    sfetch("/api/connections").then((r) => r.json()).then((d) => {
      if (d?.ok) setConns({ accounts: d.accounts || [], manual: d.connections || [], networks: d.networks || [], profiles: d.profiles || [] });
    }).catch(() => {});
  }, [step]);

  // #2: alterna a rede-alvo da próxima mídia gerada (vazio = todas).
  const toggleMediaNet = (v: string) => setMediaNets((a) => (a.includes(v) ? a.filter((x) => x !== v) : [...a, v]));
  // Redes a que um item de mídia se destina ([] / ausente = todas, retrocompat).
  const mediaNetsOf = (m: Media) => (Array.isArray(m.platforms) ? m.platforms : []);
  // #3: idioma efetivo de uma rede = escolha da rede OU padrão da conta.
  const langOf = (p: string) => textLangs[p] ?? defaultLang;
  const go = (path: string) => { router.push(path); };
  // Mídia gerada sem pesquisa cria um rascunho no servidor — adota o id retornado pra galeria/aprovação fluírem.
  const adotarDraft = (id?: string) => { if (id && id !== draftId) { setDraftId(id); localStorage.setItem(KEY, id); } };

  // Gera o prompt do tipo escolhido (imagem OU vídeo), SOB DEMANDA (clique) e JÁ condizente
  // com o formato/duração selecionados — o prompt nunca é gerado automaticamente nem toca o
  // texto da publicação. Sobrescreve só o prompt do tipo pedido.
  async function gerarPrompt(kind: "image" | "video") {
    if (!draftId) { setMsg("❌ Faça a pesquisa primeiro (ou abra um rascunho)."); return; }
    setBusy(kind === "image" ? "imgprompt" : "vidprompt"); setMsg(null);
    try {
      const body = { draftId, kind, aspect: kind === "image" ? imgAspect : vidAspect, duration };
      const r = await sfetch("/api/studio/mediaprompts", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(body) });
      const d = await r.json();
      if (!d.ok) { setMsg("❌ " + (d.error || "não foi possível sugerir")); return; }
      if (kind === "image" && d.imagePrompt) setImagePrompt(d.imagePrompt);
      if (kind === "video" && d.videoPrompt) setVideoPrompt(d.videoPrompt);
    } catch {
      setMsg("❌ não foi possível sugerir agora — tente de novo.");
    } finally {
      setBusy(null);
    }
  }
  // Biblioteca de prompts (aba Prompts): salvar o campo atual / carregar um salvo.
  async function salvarPrompt(content: string, defaultName: string) {
    if (!content.trim()) { setMsg("❌ O campo está vazio."); return; }
    const resp = await perguntar({ titulo: "Salvar na aba Prompts", confirmar: "Salvar", campos: [{ label: "Nome do prompt", placeholder: defaultName }] });
    const nome = resp?.[0] ?? null;
    if (nome === null) return;
    const r = await sfetch("/api/prompts", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ title: nome || defaultName, content }) });
    const d = await r.json();
    setMsg(d?.ok ? "✅ Salvo na aba Prompts." : "❌ não foi possível salvar.");
  }
  function aplicarPrompt(content: string) {
    if (promptTarget === "image") setImagePrompt(content);
    else if (promptTarget === "video") setVideoPrompt(content);
    setPromptTarget(""); setMsg("✅ Prompt carregado.");
  }

  // Roteiristas da aba Prompts ("🎬 Roteirista: <nome>") — populam o seletor de craft do post.
  useEffect(() => {
    sfetch("/api/prompts").then((r) => r.json()).then((d) => {
      if (Array.isArray(d)) {
        setRoteiristas(d
          .filter((p: { title?: string }) => (p.title || "").startsWith("🎬 Roteirista:"))
          .map((p: { title: string; content: string }) => ({ name: p.title.replace("🎬 Roteirista:", "").trim(), content: p.content || "" })));
      }
    }).catch(() => {});
  }, []);

  async function pesquisar() {
    setBusy("research"); setMsg(null);
    const r = await sfetch("/api/studio/research", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ keyword, sources, textModel: textModel || undefined }) });
    const d = await r.json(); setBusy(null);
    if (!d.ok) return setMsg("❌ " + d.error);
    setDraftId(d.draftId); setResearch(d.research); localStorage.setItem(KEY, d.draftId);
    // Prompt NÃO é gerado aqui: ele é opt-in na etapa de Mídia, depois de escolher tipo/formato/duração.
  }
  async function pesquisaProfunda() {
    setBusy("deep"); setMsg("🔬 Pesquisa profunda em andamento (pode levar 1-2 min)...");
    const r = await sfetch("/api/studio/deepsearch", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ keyword }) });
    const d = await r.json(); setBusy(null);
    if (!d.ok) return setMsg("❌ " + (d.error || "falha"));
    setDraftId(d.draftId); setResearch(d.research); localStorage.setItem(KEY, d.draftId); setMsg(null);
    // Prompt NÃO é gerado aqui: opt-in na etapa de Mídia, depois de escolher tipo/formato/duração.
  }
  // Cria um rascunho EM BRANCO (sem pesquisa) e abre o editor direto. O tema é
  // OPCIONAL — sem tema, abre um rascunho "Novo conteúdo" pra escrever/gerar do zero.
  async function criarSemPesquisa() {
    setBusy("blank"); setMsg(null);
    try {
      const r = await sfetch("/api/studio/draft", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ keyword: keyword.trim() || "Novo conteúdo" }) });
      const d = await r.json();
      if (!d.ok) { setBusy(null); return setMsg("❌ " + (d.error || "não foi possível criar")); }
      localStorage.setItem(KEY, String(d.draftId));
      router.push("/editar");
    } catch {
      setBusy(null); setMsg("❌ não foi possível criar agora — tente de novo.");
    }
  }
  async function gerarTexto(p: string) {
    setBusy("t-" + p);
    const r = await sfetch("/api/studio/text", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ draftId, platform: p, persona: roteirista }) });
    const d = await r.json(); setBusy(null);
    if (d.ok) { setTexts((t) => ({ ...t, [p]: d.post })); setTextMeta((m) => ({ ...m, [p]: { grounding: d.grounding || 0, rank: d.rank_summary || 0, flags: d.flags || [] } })); }
  }
  async function salvarResumo() {
    setBusy("ref");
    const r = await sfetch("/api/studio/research", { method: "PATCH", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ draftId, summary: refDraft }) });
    const d = await r.json(); setBusy(null);
    if (d.ok) { setResearch((x) => (x ? { ...x, summary: refDraft } : x)); setEditRef(false); }
  }
  // ↻ Regera o RESUMO da pesquisa com o roteirista escolhido (ex.: 📄 Resumidor Executivo),
  // reusando as fontes já coletadas no rascunho — sem nova pesquisa, sem custo de busca.
  async function regerarResumo() {
    setBusy("resum"); setMsg(null);
    const r = await sfetch("/api/studio/resummarize", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ draftId, persona: roteirista, textModel: textModel || undefined }) });
    const d = await r.json(); setBusy(null);
    if (!d.ok) return setMsg("❌ " + (d.error || "não foi possível regerar o resumo"));
    setResearch(d.research); setMsg("✅ Resumo regenerado" + (roteirista ? " com o roteirista escolhido." : "."));
  }
  function salvarTexto(p: string, text: string) {
    setTexts((t) => ({ ...t, [p]: text }));
    sfetch("/api/studio/text", { method: "PATCH", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ draftId, platform: p, text }) }).catch(() => {});
  }
  // gerarMidia: geração por texto (padrão) OU a partir de uma imagem de input (i2i/i2v).
  // opts.imageUrl, quando presente, vai no body → o engine transforma/anima/usa-a como base.
  // Para kind "video", o painel unificado envia as opções combináveis em opts.video
  // (cenas, duração, narração, legenda, música, premium, voz, idioma, estilo).
  async function gerarMidia(
    kind: "image" | "video" | "logo" | "gif",
    opts?: {
      imageUrl?: string;
      video?: {
        scenes: number; duration: string; narration: boolean; subtitles: boolean;
        music: boolean; premium: boolean; voice_id?: string; lang: string; style: string;
      };
    },
  ) {
    setBusy(kind);
    setMsg(null);
    const imageUrl = opts?.imageUrl ?? "";
    const before = media.length;
    // LOGO — imagem t2i com scaffold de logotipo (preset). Síncrono, como a imagem.
    if (kind === "logo") {
      const body: Record<string, unknown> = {
        draftId, kind: "logo", prompt: imagePrompt || keyword, aspect: imgAspect, style: imgStyle,
        preset: logoPreset, platforms: mediaNets, model: imageModel || undefined, quality: imageQuality || undefined,
      };
      const r = await sfetch("/api/studio/media", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(body) });
      const d = await r.json();
      if (!d.ok) { setBusy(null); return setMsg("❌ " + d.error); }
      if (d.media) setMedia(d.media);
      adotarDraft(d.draftId);
      setBusy(null);
      return;
    }
    // GIF — loop curto animado (clipe → conversão .gif). Job longo → poll da galeria até aparecer.
    if (kind === "gif") {
      const body: Record<string, unknown> = {
        draftId, kind: "gif", prompt: imagePrompt || keyword, aspect: imgAspect, style: imgStyle, platforms: mediaNets,
      };
      if (imageUrl) {
        // Multi-referência POR PAPEL: o backend aceita até 3 em `imageUrls` e usa `imageRoles`
        // pra dizer ao modelo o que aproveitar de cada uma. Uma ref só continua indo no
        // `imageUrl` singular (retrocompatível com quem não manda papel).
        const extras = refsExtra.filter((r) => r.url);
        if (extras.length) {
          body.imageUrls = [imageUrl, ...extras.map((r) => r.url)];
          body.imageRoles = [refRole, ...extras.map((r) => r.role)];
        } else {
          body.imageUrl = imageUrl;
          body.imageRoles = [refRole];
        }
      } // i2v: anima a partir de uma imagem-base
      const r = await sfetch("/api/studio/media", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(body) });
      const d = await r.json();
      if (!d.ok) { setBusy(null); return setMsg("❌ " + d.error); }
      setMsg("✨ " + (d.message || "Gerando GIF… leva alguns instantes."));
      const did = d.draftId || draftId; adotarDraft(d.draftId);
      // Central de Tarefas (S1): acompanhamento sobrevive à navegação
      jobs.registerJob({ type: "draft-media", draftId: Number(did), baseline: before, href: "/galeria", label: `✨ GIF — ${keyword || "sem título"}` });
      const t0 = Date.now();
      let n = 0;
      const iv = setInterval(async () => {
        n++;
        const rr = await sfetch(`/api/studio/draft?id=${did}`).then((x) => x.json()).catch(() => null);
        if (rr?.ok) {
          setMedia(rr.draft.media || []);
          if ((rr.draft.media || []).length > before) { setMsg("✅ GIF pronto na galeria!"); clearInterval(iv); setBusy(null); return; }
        }
        const secs = Math.round((Date.now() - t0) / 1000);
        setMsg(`✨ Gerando GIF… ${secs}s`);
        if (n > 45) { clearInterval(iv); setBusy(null); setMsg("⏳ O GIF está demorando — a Central de Tarefas (🔔 no topo) avisa quando ficar pronto, mesmo se você sair desta tela."); }
      }, 4000);
      return;
    }
    if (kind === "image") {
      const body: Record<string, unknown> = {
        draftId, kind, prompt: imagePrompt || keyword, aspect: imgAspect, style: imgStyle,
        platforms: mediaNets, // #2: redes-alvo desta mídia (vazio = todas)
        // modelo de imagem escolhido (só t2i; i2i = transformar imagem roda no nano-banana).
        model: !imageUrl && imageModel ? imageModel : undefined,
        quality: !imageUrl ? imageQuality || undefined : undefined, // resolução (só t2i)
        // persona (estilo): id da salva, ou o texto digitado quando é "escrever a minha".
        personaId: personaId && personaId !== "custom" ? personaId : undefined,
        persona: personaId === "custom" && personaText.trim() ? personaText.trim() : undefined,
        refiner: refiner || undefined, // CLI que reescreve o pedido antes de gerar
        grade, // 🎨 cor: acabamento determinístico por cima da geração (embutido, sem custo extra)
        // Personagem da biblioteca → o servidor injeta o IDENTITY LOCK no prompt.
        charIds: genChar ? [Number(genChar)] : undefined,
      };
      if (imageUrl) body.imageUrl = imageUrl;
      const r = await sfetch("/api/studio/media", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(body) });
      const d = await r.json();
      if (!d.ok) { setBusy(null); return setMsg("❌ " + d.error); }
      // Síncrono ou assíncrono, decidido PELA RESPOSTA (`item` presente = já ficou pronta), não
      // por adivinhar pelo tipo de pedido. Antes o teste era `if (imageUrl)`, o que quebrou
      // quando o motor `cursor` (t2i, sem imageUrl) passou a ser assíncrono por ser lento
      // demais pro limite de ~100s do Cloudflare. Assim, motor lento novo só precisa vir
      // marcado como async no catálogo — o front não muda.
      if (d.item) {
        if (d.media) setMedia(d.media);
        adotarDraft(d.draftId);
        setBusy(null);
        // O back-end troca o motor quando o escolhido não vale pro plano (de propósito, pra não
        // travar a geração). Antes trocava calado — o usuário pedia um e recebia outro.
        if (d.aviso) setMsg("⚠️ " + d.aviso);
        return;
      }
      // Job longo → MANTÉM o botão travado durante o polling (evita gerações duplicadas).
      const ehI2i = Boolean(imageUrl);
      setMsg((d.aviso ? "⚠️ " + d.aviso + " " : "🎨 ") + (d.message || (ehI2i ? "Transformando imagem… leva alguns instantes." : "Gerando imagem… leva alguns instantes.")));
      const did = d.draftId || draftId; adotarDraft(d.draftId);
      jobs.registerJob({ type: "draft-media", draftId: Number(did), baseline: before, href: "/galeria", label: `🎨 Imagem${ehI2i ? " (i2i)" : ""} — ${keyword || "sem título"}` });
      // Poll de 4s (era 18s): o botão trava durante a geração pra evitar duplicata, então tem de
      // LIBERAR rápido assim que a imagem fica pronta — com 18s o botão ficava preso ~18s a mais
      // depois do fim ("travado, não deixa gerar outra"). O contador de segundos mostra que está
      // vivo (a geração leva ~30-40s), pra não parecer congelado.
      const t0 = Date.now();
      let n = 0;
      const iv = setInterval(async () => {
        n++;
        const rr = await sfetch(`/api/studio/draft?id=${did}`).then((x) => x.json()).catch(() => null);
        if (rr?.ok) {
          setMedia(rr.draft.media || []);
          if ((rr.draft.media || []).length > before) { setMsg("✅ Pronto na galeria!"); clearInterval(iv); setBusy(null); return; }
        }
        const secs = Math.round((Date.now() - t0) / 1000);
        setMsg(`${ehI2i ? "🎨 Transformando imagem…" : "🎨 Gerando imagem…"} ${secs}s (costuma levar ~30-40s)`);
        if (n > 45) { clearInterval(iv); setBusy(null); setMsg("⏳ Está demorando — a Central de Tarefas (🔔 no topo) avisa quando a imagem ficar pronta, mesmo se você sair desta tela."); }
      }, 4000);
      return;
    }
    // kind === "video": painel unificado. Monta o body com as opções combináveis.
    const v = opts?.video ?? { scenes, duration, narration, subtitles, music, premium, voice_id: narration ? voice : undefined, lang, style: vidStyle };
    const body: Record<string, unknown> = {
      draftId,
      kind: "video",
      prompt: videoPrompt || keyword,
      aspect: vidAspect,
      style: v.style,
      scenes: v.scenes,
      duration: v.duration,
      narration: v.narration,
      subtitles: v.subtitles,
      music: v.music,
      premium: v.premium,
      // modelo de vídeo escolhido (só no modo padrão; o premium roteia pro vídeo premium via a flag acima).
      model: !v.premium && videoModel ? videoModel : undefined,
      lang: v.lang,
      platforms: mediaNets, // #2: redes-alvo desta mídia (vazio = todas)
      // persona de vídeo (estilo): id da salva, ou o texto digitado na hora.
      personaId: vidPersonaId && vidPersonaId !== "custom" ? vidPersonaId : undefined,
      persona: vidPersonaId === "custom" && vidPersonaText.trim() ? vidPersonaText.trim() : undefined,
      // Mesmo personagem escolhido no card de Imagem: a identidade atravessa as duas peças.
      charIds: genChar ? [Number(genChar)] : undefined,
    };
    // v2: qualidade (resolução) escolhida — define o preço e a resolução da geração.
    if (!v.premium && videoQuality) body.quality = videoQuality;
    if (v.narration && v.voice_id) body.voice_id = v.voice_id;
    // Legenda ligada (modo Padrão) → manda o estilo escolhido; o vídeo já sai com a legenda certa.
    if (v.subtitles && !v.premium) {
      body.subtitlePos = subtitlePos;
      body.subtitleSize = subtitleSize;
      body.subtitleColor = subtitleColor;
      body.subtitleBorder = subtitleBorder;
      body.subtitleBorderColor = subtitleBorderColor;
      body.subtitleFont = subtitleFont;
      body.subtitleOpacity = subtitleOpacity;
      body.subtitleBg = subtitleBg;
      body.subtitleBgColor = subtitleBgColor;
      body.subtitleBgOpacity = subtitleBgOpacity;
      if (subtitleAnim) {
        body.subtitleAnim = subtitleAnim; // 🎞️ legenda animada (overlay); vazio = queimada
        body.subtitleAccentColor = subtitleAccentColor;
      }
    }
    if (imageUrl) body.imageUrl = imageUrl;
    if (!v.premium) { body.grade = grade; body.grain = grain; }
    const r = await sfetch("/api/studio/media", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(body) });
    const d = await r.json();
    if (!d.ok) { setBusy(null); return setMsg("❌ " + d.error); }
    // Vídeo é job longo. MANTÉM o botão travado (busy="video") durante TODO o polling,
    // pra não disparar várias gerações em paralelo. Só libera quando o vídeo fica pronto
    // (aparece na galeria) ou no timeout — aí o botão volta a "🎬 Gerar vídeo".
    setMsg("🎬 " + (d.message || "Gerando vídeo… leva alguns minutos. Pode navegar à vontade — a Central de Tarefas avisa quando ficar pronto."));
    const did = d.draftId || draftId; adotarDraft(d.draftId);
    jobs.registerJob({ type: "draft-media", draftId: Number(did), baseline: before, href: "/galeria", label: `🎬 Vídeo — ${keyword || "sem título"}` });
    let n = 0;
    const iv = setInterval(async () => {
      n++;
      const rr = await sfetch(`/api/studio/draft?id=${did}`).then((x) => x.json()).catch(() => null);
      if (rr?.ok) {
        setMedia(rr.draft.media || []);
        if ((rr.draft.media || []).length > before) { setMsg("✅ Vídeo pronto na galeria!"); clearInterval(iv); setBusy(null); }
      }
      // Para de atualizar a tela, mas o job segue registrado na Central de Tarefas: ela continua
      // o polling (mesmo se sair daqui) e avisa quando ficar pronto. Não é abandono.
      if (n > 60) { clearInterval(iv); setBusy(null); setMsg("⏳ O vídeo está demorando mais que o normal — a Central de Tarefas (🔔 no topo) avisa quando ficar pronto, mesmo se você sair desta tela."); }
    }, 18000);
  }
  // Gera uma faixa de música (RedFox AI · Música) e faz polling da galeria até o áudio surgir.
  async function gerarMusica() {
    if (!draftId) { setMsg("❌ Faça a pesquisa primeiro (ou abra um rascunho)."); return; }
    if (!musicPrompt.trim()) { setMsg("❌ Descreva a música (estilo, mood, tema)."); return; }
    setBusy("music"); setMsg(null);
    const before = media.length;
    const r = await sfetch("/api/studio/music", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ draftId, prompt: musicPrompt, instrumental: musicInstrumental }) });
    const d = await r.json();
    if (!d.ok) { setBusy(null); return setMsg("❌ " + d.error); }
    setMsg("🎵 " + (d.message || "Gerando música… aparece na galeria em instantes."));
    const did = d.draftId || draftId; adotarDraft(d.draftId);
    jobs.registerJob({ type: "draft-media", draftId: Number(did), baseline: before, href: "/galeria", label: `🎵 Música — ${musicPrompt.slice(0, 40) || "sem título"}` });
    let n = 0;
    const iv = setInterval(async () => {
      n++;
      const rr = await sfetch(`/api/studio/draft?id=${did}`).then((x) => x.json()).catch(() => null);
      if (rr?.ok) {
        setMedia(rr.draft.media || []);
        if ((rr.draft.media || []).length > before) { setMsg("✅ Música pronta na galeria!"); clearInterval(iv); setBusy(null); }
      }
      if (n > 40) { clearInterval(iv); setBusy(null); setMsg("⏳ A música está demorando — a Central de Tarefas (🔔 no topo) avisa quando ficar pronta, mesmo se você sair desta tela."); }
    }, 8000);
  }
  // 🎞️ MOTION — passo 1: a TELA ESTÁTICA. Passa pela MESMA rota do card de Imagem (kind:"image"),
  // com o mesmo tratamento de técnica e formato: a peça cai na galeria como qualquer imagem e,
  // ao mesmo tempo, fica pendurada em `motionTela` esperando a aprovação.
  // 📰 VOX: o card que vivia aqui virou a aba /video (components/video/VideoStudio.tsx) — estados,
  // saldo e funções vox* foram junto; nada de função órfã ficando pra trás.
  async function gerarTelaMotion() {
    // Imagem trazida do acervo/computador JÁ é a tela: pular a geração economiza o crédito e
    // respeita a escolha — quem trouxe a arte não quer que a IA desenhe outra por cima.
    if (motionBase) {
      setMotionTela(motionBase);
      return setMsg("🎞️ Tela definida a partir da sua imagem — aprove para animar.");
    }
    if (!motionDesc.trim()) return setMsg("❌ Descreva a peça antes de gerar a tela.");
    const frases = parseFrases(motionLines);
    if (motionStruct === "cartelas" && frases.length === 0) return setMsg("❌ Escreva ao menos uma frase para as cartelas.");
    setBusy("motion-tela"); setMsg(null); setMotionTela(null);
    const before = media.length;
    const body: Record<string, unknown> = {
      draftId, kind: "image", prompt: telaEstaticaPrompt(motionDesc, motionStruct, frases),
      aspect: motionAspect, style: motionStyle, platforms: mediaNets,
      model: imageModel || undefined, quality: imageQuality || undefined,
    };
    const r = await sfetch("/api/studio/media", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(body) });
    const d = await r.json();
    if (!d.ok) { setBusy(null); return setMsg("❌ " + d.error); }
    const did = d.draftId || draftId; adotarDraft(d.draftId);
    // Síncrono (motor rápido) → a tela já veio; assíncrono → polling, igual ao card de Imagem.
    if (d.item?.url) {
      if (d.media) setMedia(d.media);
      setMotionTela(d.item.url); setBusy(null);
      return setMsg("🎞️ Tela pronta — confira e aprove para animar.");
    }
    setMsg("🎞️ Gerando a tela… leva alguns instantes.");
    jobs.registerJob({ type: "draft-media", draftId: Number(did), baseline: before, href: "/galeria", label: `🎞️ Motion (tela) — ${motionDesc.slice(0, 40)}` });
    let n = 0;
    const iv = setInterval(async () => {
      n++;
      const rr = await sfetch(`/api/studio/draft?id=${did}`).then((x) => x.json()).catch(() => null);
      const lista = rr?.ok ? (rr.draft.media || []) : null;
      if (lista) {
        setMedia(lista);
        if (lista.length > before) {
          const nova = lista[lista.length - 1];
          clearInterval(iv); setBusy(null);
          if (nova?.url) { setMotionTela(nova.url); setMsg("🎞️ Tela pronta — confira e aprove para animar."); }
          return;
        }
      }
      if (n > 45) { clearInterval(iv); setBusy(null); setMsg("⏳ A tela está demorando — quando aparecer na galeria, escolha-a como base e gere de novo."); }
    }, 4000);
  }
  // 🎞️ MOTION — passo 2: APROVAR E ANIMAR. O único ponto em que o fluxo para, e é de propósito:
  // a tela estática custa pouco, o vídeo custa caro. Refazer não passa por aqui (não cobra vídeo);
  // só a aprovação enfileira o clipe. O prompt de motion é montado no BACK-END a partir da
  // estrutura escolhida — o usuário não escreve o prompt.
  async function animarTelaMotion() {
    if (!motionTela) return;
    setBusy("motion-clip"); setMsg(null);
    const before = media.length;
    const r = await sfetch("/api/studio/motion-clip", {
      method: "POST", headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        draftId, imageUrl: motionTela, structure: motionStruct, style: motionStyle,
        aspect: motionAspect, seconds: motionSecs, lines: parseFrases(motionLines),
        model: videoModel || undefined, quality: videoQuality || undefined, platforms: mediaNets,
        description: motionDesc,
      }),
    });
    const d = await r.json();
    if (!d.ok) { setBusy(null); return setMsg("❌ " + d.error); }
    setMotionTela(null); // aprovada: sai do gate
    setMsg("🎞️ " + (d.message || "Animando a tela aprovada…"));
    const did = d.draftId || draftId; adotarDraft(d.draftId);
    jobs.registerJob({ type: "draft-media", draftId: Number(did), baseline: before, href: "/galeria", label: `🎞️ Motion — ${motionDesc.slice(0, 40) || "sem título"}` });
    let n = 0;
    const iv = setInterval(async () => {
      n++;
      const rr = await sfetch(`/api/studio/draft?id=${did}`).then((x) => x.json()).catch(() => null);
      if (rr?.ok) {
        setMedia(rr.draft.media || []);
        if ((rr.draft.media || []).length > before) {
          // O clipe novo é o último item de vídeo: guarda pra poder juntar depois.
          const novos = (rr.draft.media || []).filter((m: { kind?: string; url?: string }) => m.kind === "video" && m.url);
          const ultimo = novos[novos.length - 1]?.url;
          if (ultimo) setMotionClipes((prev) => (prev.includes(ultimo) ? prev : [...prev, ultimo]));
          setMsg("✅ Motion pronto na galeria!"); clearInterval(iv); setBusy(null);
        }
      }
      if (n > 60) { clearInterval(iv); setBusy(null); setMsg("⏳ O motion está demorando — a Central de Tarefas (🔔 no topo) avisa quando ficar pronto."); }
    }, 18000);
  }
  // Junta os clipes acumulados num vídeo só. Não gera nem cobra crédito de IA — os clipes já
  // foram pagos na geração; aqui é só montagem (a mesma do filme contínuo, no ffmpeg-service).
  async function juntarMotion() {
    if (motionClipes.length < 2) return setMsg("❌ Gere pelo menos 2 clipes para juntar.");
    setBusy("motion-join"); setMsg("🎬 Juntando os clipes…");
    const r = await sfetch("/api/studio/motion-join", {
      method: "POST", headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ draftId, clipUrls: motionClipes, aspect: motionAspect, platforms: mediaNets }),
    });
    const d = await r.json();
    setBusy(null);
    if (!d.ok) return setMsg("❌ " + d.error);
    adotarDraft(d.draftId);
    const rr = await sfetch(`/api/studio/draft?id=${d.draftId || draftId}`).then((x) => x.json()).catch(() => null);
    if (rr?.ok) setMedia(rr.draft.media || []);
    setMsg(`✅ Motion montado com ${d.clipes} clipes — está na galeria.`);
  }

  async function upload(kind: "image" | "video", file: File) {
    setBusy("up-" + kind);
    const fd = new FormData(); fd.append("file", file); fd.append("draftId", draftId || ""); fd.append("kind", kind);
    const r = await sfetch("/api/studio/upload", { method: "POST", body: fd });
    const d = await r.json(); setBusy(null);
    if (d.ok) { setMedia(d.media); adotarDraft(d.draftId); } else setMsg("❌ " + d.error);
  }
  // Upload de uma imagem do PC pra usar como INPUT (não entra na galeria do rascunho como item final;
  // reusa o mesmo endpoint /api/studio/upload e adota a URL retornada como imagem de input).
  async function uploadInput(file: File) {
    setBusy("up-input"); setMsg(null);
    const fd = new FormData(); fd.append("file", file); fd.append("draftId", draftId || ""); fd.append("kind", "image");
    const r = await sfetch("/api/studio/upload", { method: "POST", body: fd });
    const d = await r.json(); setBusy(null);
    if (!d.ok) return setMsg("❌ " + d.error);
    adotarDraft(d.draftId);
    if (Array.isArray(d.media)) setMedia(d.media);
    // a URL recém-enviada é o item mais recente do tipo imagem.
    const url = d.url || (Array.isArray(d.media) ? [...d.media].reverse().find((m: Media) => m.kind === "image")?.url : "");
    if (!url) return setMsg("❌ Não foi possível obter a URL da imagem enviada.");
    if (pickerAlvo === "extra") adicionarRefExtra(url);
    else { setInputImageUrl(url); setInputImageSource("upload"); }
  }
  /** Ref extra (papel default = direção de arte: quem manda 2ª imagem quase sempre quer o LOOK
   *  dela, não outro assunto). Teto 3 = cap do backend. */
  function adicionarRefExtra(url: string) {
    setRefsExtra((cur) => (cur.length >= 2 || cur.some((r) => r.url === url) ? cur : [...cur, { url, role: "estilo" }]));
  }
  function escolherDaGaleria(url: string) {
    if (pickerAlvo === "extra") { adicionarRefExtra(url); setPickerOpen(false); return; }
    setInputImageUrl(url); setInputImageSource("galeria"); setPickerOpen(false);
  }
  function limparInput() { setInputImageUrl(""); setInputImageSource(""); setRefsExtra([]); setRefRole("identidade"); }
  async function refreshMedia() {
    if (!draftId) return;
    const r = await sfetch(`/api/studio/draft?id=${draftId}`);
    const d = await r.json(); if (d.ok) setMedia(d.draft.media || []);
  }
  // Limpa TODA a mídia desta sessão (rascunho atual) da galeria — desafoga sem trocar de rascunho.
  // Só tira as referências deste rascunho; não apaga arquivos já publicados.
  async function limparMidia() {
    if (!draftId || media.length === 0) return;
    if (!(await perguntar({ titulo: `Limpar ${media.length} mídias desta sessão?`, mensagem: "Somem da galeria desta sessão. Não apaga o que já foi publicado.", confirmar: "Limpar", perigo: true }))) return;
    const r = await sfetch("/api/studio/media-clear", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ draftId }) });
    const d = await r.json();
    if (d.ok) { setMedia([]); setMsg("🧹 Galeria da sessão limpa."); } else setMsg("❌ " + (d.error || "não foi possível limpar"));
  }
  async function clonarVoz(file: File) {
    setBusy("voice"); setMsg(null);
    const fd = new FormData(); fd.append("file", file);
    const r = await sfetch("/api/studio/voice-clone", { method: "POST", body: fd });
    const d = await r.json(); setBusy(null);
    if (d.ok) { setMyVoice(d.voice_id); setVoice(d.voice_id); setMsg("✅ Sua voz foi clonada! Já dá pra usar nos vídeos."); } else setMsg("❌ " + d.error);
  }
  async function dublar(videoUrl: string, lang: string) {
    setBusy("dub"); setMsg(null);
    const before = media.length;
    const r = await sfetch("/api/studio/dub", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ draftId, videoUrl, lang }) });
    const d = await r.json(); setBusy(null);
    if (!d.ok) return setMsg("❌ " + d.error);
    setMsg("🌎 " + d.message);
    jobs.registerJob({ type: "draft-media", draftId: Number(draftId), baseline: before, href: "/galeria", label: `🌎 Dublagem (${lang})` });
    let n = 0;
    const iv = setInterval(async () => {
      n++;
      const rr = await sfetch(`/api/studio/draft?id=${draftId}`).then((x) => x.json()).catch(() => null);
      if (rr?.ok) { setMedia(rr.draft.media || []); if ((rr.draft.media || []).length > before) { setMsg("✅ Versão dublada pronta na galeria!"); clearInterval(iv); } }
      if (n > 70) clearInterval(iv);
    }, 18000);
  }
  async function clipar() {
    if (!clipUrl.trim()) return setMsg("❌ Cole a URL do vídeo longo (MP4) ou faça upload acima.");
    setBusy("clip"); setMsg(null);
    const before = media.length;
    const r = await sfetch("/api/studio/clip", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ draftId, videoUrl: clipUrl.trim(), n: clipN }) });
    const d = await r.json(); setBusy(null);
    if (!d.ok) return setMsg("❌ " + d.error);
    setMsg("✂️ " + d.message);
    jobs.registerJob({ type: "draft-media", draftId: Number(draftId), baseline: before, href: "/galeria", label: `✂️ Clipes do vídeo longo` });
    let n = 0;
    const iv = setInterval(async () => {
      n++;
      const rr = await sfetch(`/api/studio/draft?id=${draftId}`).then((x) => x.json()).catch(() => null);
      if (rr?.ok) { setMedia(rr.draft.media || []); if ((rr.draft.media || []).length > before) { setMsg(`✅ ${(rr.draft.media || []).length - before} short(s) prontos na galeria!`); clearInterval(iv); } }
      if (n > 30) clearInterval(iv);
    }, 18000);
  }
  async function excluirMidia(id: string) {
    const r = await sfetch("/api/studio/media", { method: "DELETE", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ draftId, id }) });
    const d = await r.json(); if (d.ok) setMedia(d.media);
  }
  // Pós-processa uma imagem da Mídia (upscale / remover fundo). O resultado entra na galeria.
  async function enhanceMidia(m: Media, op: "upscale" | "upscale_pro" | "remove_bg") {
    if (enhancing) return;
    setEnhancing(`${op}:${m.id}`); setMsg(null);
    try {
      const r = await sfetch("/api/studio/enhance", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ draftId, imageUrl: m.url, op }) });
      const d = await r.json().catch(() => ({}));
      if (!d.ok || !d.media) throw new Error(d.error || "falha");
      setMedia(d.media); adotarDraft(d.draftId); setMsg("✅ Imagem processada — na galeria.");
    } catch (e) { setMsg("❌ " + (e instanceof Error ? e.message : "não foi possível processar")); }
    finally { setEnhancing(""); }
  }
  // ⚡ EasyApp 1-clique (aba Rápido): relight (reiluminar) / product_bg (trocar fundo do produto).
  // Faz i2i preservando o sujeito e anexa o resultado como NOVA imagem da galeria (como o enhance).
  async function easyappMidia(m: Media, kind: string, valor = "") {
    if (enhancing) return;
    setEnhancing(`${kind}:${m.id}`); setMsg(null);
    try {
      // `params` e `aspect` iam faltando aqui: a grade mandava só {draftId,imageUrl,kind}, então
      // "Reiluminar" nunca recebia o clima da luz e o backend caía no default. Ver lib/easyapps.
      const body: Record<string, unknown> = { draftId, imageUrl: m.url, kind, params: easyAppParams(kind, valor) };
      if (easyAppDe(kind)?.precisaPersonagem && ajusteChar) body.characterId = ajusteChar;
      const r = await sfetch("/api/studio/easyapp", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(body) });
      const d = await r.json().catch(() => ({}));
      if (!d.ok || !d.media) throw new Error(d.error || "falha");
      setMedia(d.media); adotarDraft(d.draftId); setMsg("✅ Imagem processada — na galeria.");
    } catch (e) { setMsg("❌ " + (e instanceof Error ? e.message : "não foi possível processar")); }
    finally { setEnhancing(""); }
  }
  // ⭐ Guarda a mídia no Hub "Meus Assets" (org_assets) pra reusar em outro projeto. Sem custo.
  async function salvarNosAssets(m: Media) {
    try {
      const r = await sfetch("/api/studio/assets", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ url: m.url, kind: m.kind === "video" ? "video" : "image", source: "generation", meta: { draft_id: draftId } }) });
      const j = await r.json();
      setMsg(j.ok ? (j.deduped ? "✅ Já estava nos seus Assets." : "✅ Salvo nos seus Assets.") : "❌ " + (j.error || "falha ao salvar"));
    } catch { setMsg("❌ não foi possível salvar nos Assets"); }
  }
  // 🎨 F2 — filtro Instagram na foto (2 créd, síncrono): o resultado vira NOVA imagem da galeria.
  async function filtrarMidia(m: Media, grade: string) {
    if (enhancing) return;
    setEnhancing(`filter:${m.id}`); setMsg(null);
    try {
      const r = await sfetch("/api/studio/image-filter", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ draftId, imageUrl: m.url, grade }) });
      const d = await r.json().catch(() => ({}));
      if (!d.ok) throw new Error(d.error || "falha");
      setMsg("✅ Filtro aplicado — nova imagem na galeria.");
      await refreshMedia();
    } catch (e) { setMsg("❌ " + (e instanceof Error ? e.message : "não foi possível aplicar o filtro")); }
    finally { setEnhancing(""); }
  }
  async function gerarThumb(videoUrl: string) {
    setBusy("thumb"); setMsg(null);
    const r = await sfetch("/api/studio/thumbnail", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ draftId, videoUrl }) });
    const d = await r.json(); setBusy(null);
    if (d.ok) { setMedia(d.media); setMsg("✅ Thumbnail gerada na galeria!"); } else setMsg("❌ " + d.error);
  }
  // Abre o compositor pra uma imagem: pré-preenche formato (pelo aspecto da geração) e título (tema).
  function abrirCompor(m: Media) {
    const fmt = imgAspect === "9:16" ? "story" : imgAspect === "16:9" ? "paisagem" : imgAspect === "4:5" ? "retrato" : "feed";
    setCForm({ format: fmt, kicker: "", titulo: keyword || "", subtitulo: "", cta: "" });
    setCCarousel(false);
    setComposeSrc(m);
  }
  // Compõe o post de marca (console → web next/og → S3 → nova mídia do draft).
  async function vestirMarca() {
    if (!composeSrc || !cForm.titulo.trim()) { setMsg("❌ Informe o título do post."); return; }
    setComposing(true); setMsg(null);
    try {
      const lines = cForm.titulo.split("\n").map((s) => s.trim()).filter(Boolean);
      const carousel = cCarousel && lines.length > 1;
      const body = { draftId, imageUrl: composeSrc.url, format: cForm.format, kicker: cForm.kicker, subtitulo: cForm.subtitulo, cta: cForm.cta, platforms: composeSrc.platforms || [], ...(carousel ? { slides: lines } : { titulo: cForm.titulo }) };
      const r = await sfetch("/api/studio/compose", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(body) });
      const d = await r.json().catch(() => ({}));
      if (!d.ok || !d.media) throw new Error(d.error || "falha");
      const n = Array.isArray(d.items) ? d.items.length : 1;
      setMedia(d.media); adotarDraft(d.draftId); setComposeSrc(null);
      setMsg(`✅ ${n > 1 ? n + " slides do carrossel gerados" : "Post de marca gerado"} — na galeria.`);
    } catch (e) { setMsg("❌ " + (e instanceof Error ? e.message : "não foi possível compor")); }
    finally { setComposing(false); }
  }
  async function publicar() {
    setBusy("submit");
    // #5: publica só as redes selecionadas (∩ redes com texto). null = todas com texto.
    const withText = Object.keys(texts).filter((p) => texts[p]?.trim());
    const platforms = (pubSel ?? withText).filter((p) => withText.includes(p));
    // Perfis-alvo (multi-perfil): selecionados, ou só o perfil PRINCIPAL por padrão (extras = opt-in).
    const profs = pubProfiles ?? (conns.profiles.filter((p) => p.is_default).map((p) => p.id).length
      ? conns.profiles.filter((p) => p.is_default).map((p) => p.id)
      : conns.profiles.slice(0, 1).map((p) => p.id));
    // 👽 A comunidade do Reddit é obrigatória (o servidor recusa sem ela): sem comunidade
    // escolhida o post ia pro "padrão da conta" — uma comunidade que ninguém escolheu. Barrar
    // aqui evita a viagem até o 422 e diz onde está o campo.
    if (platforms.includes("reddit") && !redditSubreddit.trim()) {
      setBusy(null);
      return setMsg(`❌ Escreva o alvo do Reddit (${redditAlvo}/…) antes de publicar.`);
    }
    const redditTarget = redditSubreddit.trim() ? `${redditAlvo}/${redditSubreddit.trim()}` : "";
    if (redditTarget) localStorage.setItem("reachyn_reddit_subreddit", redditTarget);
    localStorage.setItem("reachyn_reddit_formato", redditFormato);
    const r = await sfetch("/api/studio/submit", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ draftId, platforms, profiles: profs, reddit_subreddit: redditTarget, reddit_formato: redditFormato, reddit_title: redditTitulo.trim() }) });
    const d = await r.json();
    if (!d.ok) { setBusy(null); return setMsg("❌ " + (d.error || "falha ao publicar")); }
    // Publish assíncrono: acompanha o worker por polling do status.
    setMsg("⏳ Publicando em " + (d.platforms || []).join(", ") + "… (pode levar até ~1 min por rede)");
    const fmt = (results: { platform: string; ok: boolean; profile?: string }[]) =>
      results.map((x) => `${x.profile ? x.profile + " · " : ""}${x.platform} ${x.ok ? "✅" : "❌"}`).join("  ");
    const poll = async () => {
      try {
        const sr = await sfetch(`/api/studio/publish-status?draftId=${draftId}`);
        const sd = await sr.json();
        const p = sd.publish;
        if (p?.state === "done") { setBusy(null); setMsg("🚀 Publicado: " + fmt(p.results || [])); return; }
        if (p?.state === "failed") { setBusy(null); setMsg("❌ Falha na publicação: " + (p.error || "")); return; }
      } catch { /* segue tentando */ }
      setTimeout(poll, 3000);
    };
    setTimeout(poll, 3000);
  }
  // "+ Novo": começar do zero. Em Publicar/Aprovar = abrir o COMPOSITOR da aba Publicar
  // (?novo=1 → NovoPost: mídia própria + textos por rede + publicar direto — substituiu a
  // aba /postar em 2026-08-05). Na Pesquisa (já estamos em "/"), router.push("/") era NO-OP
  // e não limpava nada — então aqui RESETAMOS o estado do formulário na mão.
  function novo() {
    localStorage.removeItem(KEY);
    if (step === "publish" || step === "approve") { router.push("/publicar?novo=1"); return; }
    setDraftId(null); setResearch(null); setKeyword(""); setSources(["web"]);
    setTexts({}); setTextMeta({}); setRefDraft(""); setMsg(null);
    if (step !== "research") router.push("/");
  }

  const inp = { background: "var(--bg2)", color: "var(--text)", border: "1px solid var(--line)", borderRadius: 10, padding: "12px 14px", fontSize: ".95rem", width: "100%" } as const;
  const card = { background: "var(--panel)", border: "1px solid var(--line)", borderRadius: 12, padding: 16 } as const;
  const titles: Record<Step, string> = { research: "Pesquisar", content: "Conteúdo", media: "Mídia", approve: "Aprovar", publish: "Publicar" };
  // Subtítulo explicativo de cada etapa (padrão do app: todo h1 tem um .sub dizendo o que fazer aqui).
  const subs: Record<Step, string> = {
    research: "Passo 1 — pesquise o tema (ou crie sem pesquisa): o resumo com fontes alimenta o conteúdo e a mídia.",
    content: "Escreva ou gere a legenda de cada rede — o texto publicado nasce aqui.",
    media: "Gere a imagem, o vídeo ou a música da publicação — ancorados no resumo e no conteúdo.",
    approve: "Revise texto + mídia por rede e aprove o que vai ao ar.",
    publish: "Escolha as redes conectadas e publique (ou agende) a peça aprovada.",
  };
  // Seletor "🎬 Roteirista do post" — craft/voz aplicada aos textos por rede E à regeração do
  // resumo (o 📄 Resumidor Executivo é o "roteirista de resumos"). Editável na aba Prompts.
  const roteiristaSelect = roteiristas.length > 0 ? (
    <select value={roteirista} onChange={(e) => setRoteirista(e.target.value)} style={{ ...inp, width: "auto", padding: "7px 11px", maxWidth: 300, fontSize: ".82rem" }}
      title="Muda COMO os textos são escritos (mesmos roteiristas das Histórias — aba Prompts '🎬 Roteirista: …')">
      <option value="">🎬 Roteirista padrão</option>
      {roteiristas.filter((rt) => !rt.name.toLowerCase().startsWith("geral")).map((rt, i) => <option key={i} value={rt.content}>{rt.name}</option>)}
    </select>
  ) : null;
  // Card de Referência (resumo da pesquisa, editável) — reusado em Conteúdo e Mídia.
  // Versão COMPACTA da referência pra aba Mídia: colapsada (caixa pequena) e expansível, pra
  // não empurrar os controles de geração. Mesma edição (editRef/refDraft/salvarResumo).
  const referenciaCompacta = (research && (research.summary || research.answer)) ? (
    <div style={card}>
      <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", gap: 8 }}>
        <strong style={{ fontSize: ".9rem" }}>📋 Referência da pesquisa</strong>
        <div style={{ display: "flex", gap: 8 }}>
          {!editRef ? (<>
            <button className="btn edit" style={{ flex: "none", padding: "5px 11px", fontSize: ".78rem" }} onClick={() => setRefOpen((o) => !o)}>{refOpen ? "Recolher" : "Expandir"}</button>
            {/* "Regerar resumo" vivia SÓ no card do step "content", que nenhuma rota renderiza —
                a feature existe no backend (/api/studio/resummarize) mas ficou inalcançável na UI.
                Ao remover o step morto, ela volta aqui em vez de ser apagada junto. */}
            {(research?.results?.length ?? 0) > 0 && (
              <button className="btn edit" style={{ flex: "none", padding: "5px 11px", fontSize: ".78rem" }} disabled={anyBusy} onClick={regerarResumo}
                title="Regera o resumo com o roteirista escolhido no seletor, reusando as fontes já pesquisadas">
                {busy === "resum" ? "Regerando…" : "↻ Regerar"}
              </button>
            )}
            <button className="btn edit" style={{ flex: "none", padding: "5px 11px", fontSize: ".78rem" }} onClick={() => { setRefDraft(research?.summary || research?.answer || ""); setEditRef(true); setRefOpen(true); }}>✏️ Editar</button>
          </>) : (<>
            <button className="btn edit" style={{ flex: "none", padding: "5px 11px", fontSize: ".78rem" }} onClick={() => setEditRef(false)}>Cancelar</button>
            <button className="btn ok" style={{ flex: "none", padding: "5px 12px", fontSize: ".78rem" }} disabled={anyBusy} onClick={salvarResumo}>{busy === "ref" ? "Salvando…" : "Salvar"}</button>
          </>)}
        </div>
      </div>
      {editRef ? (
        <AutoTextarea value={refDraft} onChange={setRefDraft} minHeight={140} style={{ ...inp, lineHeight: 1.55, fontFamily: "inherit", marginTop: 8 }} />
      ) : refOpen ? (
        // Texto SÓ quando expandido — colapsado mostra apenas o cabeçalho (a prévia cortada com
        // gradiente parecia "quebrada"; feedback do operador 2026-07-08).
        <p className="txt" style={{ whiteSpace: "pre-wrap", margin: "8px 0 0", color: "var(--muted)", fontSize: ".85rem" }}>{research?.summary || research?.answer}</p>
      ) : null}
    </div>
  ) : null;
  // Mídia funciona avulsa (sem pesquisa): gera "qualquer imagem" e cria o rascunho na hora. Os demais passos ainda exigem rascunho.
  const needDraft = step !== "research" && step !== "media" && !draftId;
  // Plataformas com texto pronto (blog descontinuado como rede — filtra rascunhos legados).
  const pubPlatforms = Object.keys(texts).filter((p) => texts[p]?.trim() && p !== "blog");
  // Perfis-alvo da publicação. PADRÃO = só o perfil PRINCIPAL (is_default) — os demais perfis são
  // OPT-IN (o usuário marca quando quiser), pra não publicar em perfis extras sem intenção.
  // Multi-perfil: uma rede é "conectada" se QUALQUER perfil selecionado a tiver.
  const defaultProfileSel = (conns.profiles.filter((p) => p.is_default).map((p) => p.id).length
    ? conns.profiles.filter((p) => p.is_default).map((p) => p.id)
    : conns.profiles.slice(0, 1).map((p) => p.id));
  const selectedProfiles = (pubProfiles ?? defaultProfileSel);
  const selProfileObjs = conns.profiles.filter((p) => selectedProfiles.includes(p.id));
  const toggleProfile = (id: string) => setPubProfiles((cur) => { const base = cur ?? defaultProfileSel; return base.includes(id) ? base.filter((x) => x !== id) : [...base, id]; });
  const isConnected = (p: string) => selProfileObjs.some((prof) => prof.accounts.some((a) => a.platform === p));
  const netMeta = (p: string) => conns.networks.find((n) => n.key === p);
  // #5: seleção efetiva pra publicar (null = todas com texto, default) + toggle por rede.
  const selectedPubs = (pubSel ?? pubPlatforms).filter((p) => pubPlatforms.includes(p));
  const togglePub = (p: string) => setPubSel((cur) => { const base = (cur ?? pubPlatforms); return base.includes(p) ? base.filter((x) => x !== p) : [...base, p]; });

  if (!loaded) return <p className="sub">Carregando rascunho...</p>;

  return (
    <>
      {dialogo}
      <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center" }}>
        <h1 className="h1" style={{ marginBottom: 4 }}>{titles[step]}{keyword ? <span style={{ color: "var(--muted)", fontWeight: 400, fontSize: "1rem" }}> · {keyword}</span> : ""}</h1>
        <button className="btn edit" style={{ flex: "none", padding: "7px 13px" }} onClick={novo}>+ Novo</button>
      </div>
      <p className="sub" style={{ marginBottom: 8 }}>{subs[step]}</p>

      {needDraft && <div className="empty">Nenhum rascunho ativo. Comece em <Link href="/" style={{ color: "var(--peach)" }}>Pesquisar</Link>.</div>}

      {/* PESQUISAR */}
      {step === "research" && (
        <div style={{ display: "flex", flexDirection: "column", gap: 18, marginTop: 14 }}>
          <div style={{ display: "flex", flexDirection: "column", gap: 12 }}>
            <div style={{ display: "flex", gap: 8, flexWrap: "wrap", alignItems: "center" }}>
              <span className="txt" style={{ color: "var(--muted)", fontSize: ".82rem", marginRight: 2 }}>Pesquisar em:</span>
              {([["web", "🌐 Web"], ["instagram", "📸 Instagram"], ["twitter", "𝕏 Twitter"], ["youtube", "▶️ YouTube"]] as const).map(([k, label]) => (
                <button key={k} type="button" onClick={() => setSources((a) => (a.includes(k) ? a.filter((x) => x !== k) : [...a, k]))}
                  style={{ padding: "6px 13px", borderRadius: 20, fontSize: ".8rem", cursor: "pointer",
                    border: "1px solid " + (sources.includes(k) ? "var(--red)" : "var(--line)"),
                    background: sources.includes(k) ? "rgba(226,74,49,.15)" : "var(--bg2)",
                    color: sources.includes(k) ? "var(--peach)" : "var(--muted)" }}>{label}</button>
              ))}
            </div>
            <input style={inp} placeholder="tema (ex: tendências de IA em 2026)" value={keyword} onChange={(e) => setKeyword(e.target.value)} />
            <div style={{ display: "flex", gap: 10, flexWrap: "wrap", alignItems: "center" }}>
              <TextModelSelect value={textModel} onChange={setTextModel} />
              <button className="btn ok" disabled={anyBusy || !keyword.trim() || sources.length === 0} onClick={pesquisar}>{busy === "research" ? "Pesquisando..." : "Pesquisar →"}</button>
              <button className="btn edit" disabled={anyBusy || !keyword.trim()} onClick={pesquisaProfunda}
                title="Pesquisa aprofundada com IA: raciocina, lê várias fontes e já entrega o resumo sintetizado. Mais lenta (1-2 min). Plano Studio.">
                {busy === "deep" ? "Pesquisando a fundo…" : "🔬 Pesquisa profunda · Studio"}</button>
              <button className="btn edit" disabled={anyBusy} onClick={criarSemPesquisa}
                title="Pula a pesquisa e abre o editor direto (o tema é opcional).">
                {busy === "blank" ? "Criando…" : "✍️ Criar sem pesquisa →"}</button>
            </div>
            <p className="txt" style={{ color: "var(--muted)", fontSize: ".82rem", margin: 0 }}>Sem tempo pra pesquisar? <strong>Criar sem pesquisa</strong> abre o editor na hora (com ou sem tema).</p>
          </div>
          <div style={card}>
            {research ? (<>
              <strong>Resumo (IA)</strong>
              <p className="txt" style={{ whiteSpace: "pre-wrap" }}>{research.summary || research.answer}</p>
              <strong>Fontes</strong>
              <ul style={{ margin: "6px 0", paddingLeft: 18 }}>{research.results.map((r, i) => <li key={i}>{r.source && r.source !== "web" && <span style={{ fontSize: ".7rem", textTransform: "uppercase", color: "var(--muted)", marginRight: 6 }}>[{r.source}]</span>}<a href={r.url} target="_blank" rel="noopener" style={{ color: "var(--peach)" }}>{r.title}</a></li>)}</ul>
              <button className="btn ok" style={{ maxWidth: 150 }} onClick={() => go("/editar")}>Ir pra Conteúdo →</button>
            </>) : <span className="txt">O resumo da IA + fontes aparecem aqui.</span>}
          </div>
        </div>
      )}

      {/* MÍDIA */}
      {step === "media" && (
        <div style={{ display: "flex", flexDirection: "column", gap: 16, marginTop: 14 }}>
          {/* Guia de navegação: o que se gera AQUI. */}
          <div style={{ display: "flex", alignItems: "center", gap: 10, padding: "9px 14px", background: "rgba(226,74,49,.06)", border: "1px solid var(--line2)", borderRadius: 12 }}>
            <span style={{ fontSize: ".62rem", fontWeight: 800, letterSpacing: ".08em", textTransform: "uppercase", color: "var(--peach)", background: "rgba(226,74,49,.14)", border: "1px solid var(--red2)", padding: "3px 9px", borderRadius: 999, whiteSpace: "nowrap", flex: "none" }}>Quando usar</span>
            <span className="txt" style={{ fontSize: ".85rem", color: "var(--muted)" }}>Mídia <strong style={{ color: "var(--text)" }}>da publicação atual</strong> — imagem, clipe curto, GIF, logo ou música.</span>
          </div>
          {referenciaCompacta}

          {/* O que vai ser publicado — textos gerados por rede (guia pra a mídia combinar com o post) */}
          {pubPlatforms.length > 0 && (
            <div style={card}>
              <strong style={{ fontSize: ".9rem" }}>📝 Textos das publicações</strong>
              <p className="txt" style={{ color: "var(--muted)", fontSize: ".78rem", margin: "4px 0 10px" }}>O que será publicado em cada rede — guia pra a mídia combinar com o post. Edite em <a href="/editar" style={{ color: "var(--peach)" }}>Conteúdo</a>.</p>
              <div style={{ display: "flex", flexDirection: "column", gap: 8 }}>
                {pubPlatforms.map((p) => (
                  <details key={p} style={{ border: "1px solid var(--line)", borderRadius: 8, padding: "8px 12px", background: "var(--bg2)" }}>
                    <summary style={{ cursor: "pointer", display: "flex", justifyContent: "space-between", alignItems: "center", gap: 8, fontSize: ".85rem" }}>
                      <span style={{ display: "flex", alignItems: "center", gap: 6, textTransform: "capitalize", color: NET[p]?.color || "var(--text)" }}>
                        {NET[p]?.name || p}
                        <span style={{ fontSize: ".62rem", color: "var(--muted)", border: "1px solid var(--line)", borderRadius: 8, padding: "0 6px", lineHeight: "15px", textTransform: "none" }}>{langOf(p) === "en-US" ? "EN" : "PT"}</span>
                      </span>
                      {typeof textMeta[p]?.rank === "number" && (textMeta[p].rank as number) > 0 && (() => { const rk = textMeta[p].rank as number; const c = rk >= 0.5 ? "#22c55e" : rk >= 0.3 ? "#f59e0b" : "#ef4444"; return <span title="Aderência ao resumo (rank) — prioriza este texto nos prompts de mídia" style={{ fontSize: ".72rem", color: c, border: "1px solid " + c, borderRadius: 12, padding: "1px 8px" }}>rank {Math.round(rk * 100)}%</span>; })()}
                    </summary>
                    <p className="txt" style={{ whiteSpace: "pre-wrap", margin: "8px 0 0", fontSize: ".85rem" }}>{texts[p]}</p>
                  </details>
                ))}
              </div>
            </div>
          )}

          {/* 🎯 Destino da mídia — para quais redes a próxima geração se destina (vazio = todas) */}
          <div style={card}>
            <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", gap: 8, flexWrap: "wrap" }}>
              <strong style={{ fontSize: ".9rem" }}>🎯 Para quais redes é a próxima mídia?</strong>
              {mediaNets.length > 0 && (
                <button className="btn edit" style={{ flex: "none", padding: "5px 11px", fontSize: ".78rem" }} onClick={() => setMediaNets([])}>Limpar (todas)</button>
              )}
            </div>
            <p className="txt" style={{ color: "var(--muted)", fontSize: ".78rem", margin: "4px 0 10px" }}>
              A mídia gerada a seguir é anexada só às redes marcadas. Sem marcar nenhuma, ela serve <strong>todas</strong> as redes.
            </p>
            {pubPlatforms.length === 0 ? (
              <p className="txt" style={{ color: "var(--muted)", fontSize: ".82rem", margin: 0 }}>Gere o texto das redes em <a href="/editar" style={{ color: "var(--peach)" }}>Conteúdo</a> para destinar mídia por rede. Por ora, toda mídia serve todas as redes.</p>
            ) : (
              <div style={{ display: "flex", gap: 6, flexWrap: "wrap" }}>
                {pubPlatforms.map((p) => {
                  const on = mediaNets.includes(p);
                  const c = NET[p]?.color || "var(--red)";
                  return (
                    <button key={p} type="button" onClick={() => toggleMediaNet(p)} style={{ padding: "5px 12px", borderRadius: 16, fontSize: ".78rem", cursor: "pointer", textTransform: "capitalize",
                      border: "1px solid " + (on ? c : "var(--line)"), background: on ? c + "22" : "var(--bg2)", color: on ? "var(--peach)" : "var(--muted)" }}>
                      {NET[p]?.name || p}{on ? " ✓" : ""}
                    </button>
                  );
                })}
              </div>
            )}
          </div>

          {/* 🖼️ IMAGEM — formato + prompt (sob demanda) + estilo + gerar */}
          {(() => {
            const sel = { background: "var(--bg2)", color: "var(--text)", border: "1px solid var(--line)", borderRadius: 8, padding: "9px 12px", fontSize: ".82rem" } as const;
            return (
              <div style={{ ...card, borderColor: "rgba(14,165,233,.35)", display: "flex", flexDirection: "column", gap: 12 }}>
                <strong style={{ fontSize: "1rem", color: "var(--peach)" }}>🖼️ Imagem</strong>
                <div style={{ display: "flex", gap: 14, flexWrap: "wrap", alignItems: "flex-end" }}>
                  <div style={{ display: "flex", flexDirection: "column", gap: 4 }}>
                    <label className="txt" style={{ color: "var(--muted)", fontSize: ".78rem" }}>Formato</label>
                    <select value={imgAspect} onChange={(e) => setImgAspect(e.target.value)} style={sel} title="Tamanho/proporção da imagem">
                      {IMG_ASPECTS.map(([v, label]) => <option key={v} value={v}>🖼️ {label}</option>)}
                    </select>
                  </div>
                  {/* Estilo (do que a imagem é feita) e Cor (o acabamento) são coisas diferentes e
                      ficam em caixas separadas — mesmo desenho do painel de vídeo, que já tinha
                      Estilo + 🎨 Grade. Antes "noir"/"vintage" moravam no Estilo e brigavam com o
                      grade. O estado `grade` é o MESMO do vídeo: a sessão tem uma direção de cor. */}
                  <div style={{ display: "flex", flexDirection: "column", gap: 4 }}>
                    <label className="txt" style={{ color: "var(--muted)", fontSize: ".78rem" }}>Técnica</label>
                    <select value={imgStyle} onChange={(e) => setImgStyle(e.target.value)} style={sel} title="Do que a imagem é feita: foto, 3D, anime, aquarela… A cor fica ao lado, e a direção autoral no seletor Estilo.">
                      {STYLES.map(([v, label]) => <option key={v} value={v}>{label}</option>)}
                    </select>
                  </div>
                  {/* 🎨 COR — caixa ÚNICA. A cor existia em duas caixas ao mesmo tempo: o grade
                      (filtro exato, aplicado depois) e as personas "🎨 Cor: X" perdidas no meio do
                      seletor de Estilo. Dava pra escolher "Teal & Orange" nas DUAS e a cor era
                      aplicada em DOBRO. Agora é um seletor só, com os dois mecanismos rotulados e
                      mutuamente exclusivos: escolher de um lado limpa o outro (ver corValue). */}
                  <div style={{ display: "flex", flexDirection: "column", gap: 4 }}>
                    <label className="txt" style={{ color: "var(--muted)", fontSize: ".78rem" }}>🎨 Cor</label>
                    <select
                      value={personasCor.some((p) => String(p.id) === personaId) ? `persona:${personaId}` : `grade:${grade}`}
                      onChange={(e) => {
                        const [tipo, val] = e.target.value.split(":");
                        if (tipo === "persona") { setPersonaId(val); setGrade("natural"); }
                        else { setGrade(val); if (personasCor.some((p) => String(p.id) === personaId)) setPersonaId(""); }
                      }}
                      style={sel}
                      title="A cor da imagem. O filtro é exato e entra depois de gerar; a direção de cor é interpretada pelo modelo durante a geração. Nenhum dos dois cobra crédito à parte."
                    >
                      <optgroup label="Filtro — exato, aplicado depois">
                        {GRADE_OPTIONS.map(([v, label]) => <option key={v} value={`grade:${v}`}>{label}</option>)}
                      </optgroup>
                      {personasCor.length > 0 && (
                        <optgroup label="Direção de cor — interpretada na geração">
                          {personasCor.map((p) => <option key={p.id} value={`persona:${p.id}`}>{tituloLimpo(p.title)}</option>)}
                        </optgroup>
                      )}
                    </select>
                  </div>
                  {imageModels.length > 1 && (
                    <div style={{ display: "flex", flexDirection: "column", gap: 4 }}>
                      <label className="txt" style={{ color: "var(--muted)", fontSize: ".78rem" }}>Modelo</label>
                      <select value={imageModel} onChange={(e) => setImageModel(e.target.value)} disabled={Boolean(inputImageUrl)} style={{ ...sel, opacity: inputImageUrl ? .5 : 1 }} title={inputImageUrl ? "Ao partir de uma imagem, quem gera é o motor especialista em transformação — esta escolha não se aplica." : "Modelo de IA da imagem — cada um tem qualidade e custo diferentes"}>
                        {/* ORIGEM na frente ("☁️ nuvem · …", "✨ assinatura · …", "🎛️ ComfyUI · …",
                            "💻 Mac · …"), igual às abas Imagem/Vídeo: é o que separa de qual conta
                            sai a peça quando vários motores cobram de bolsos diferentes. O cliente
                            recebe só o ícone — `origem` não chega nele (white-label #6). */}
                        {imageModels.map((m) => <option key={m.slug} value={m.slug}>{marcaMotor(m)} · {nomeModelo(m)}{!m.qualities?.length && m.cost_credits != null ? ` · ${m.cost_credits} créd` : ""}</option>)}
                      </select>
                    </div>
                  )}
                  {/* ESTILO = a direção autoral apensada ao prompt (lente, luz, textura). Este é o
                      "Estilo" do produto: a aba Prompts chama a categoria de 🎨 Estilos e os itens
                      salvos são "🎨 Estilo: Cinematográfico". Quem perdeu o nome foi o seletor de
                      medium, que virou "Técnica" — antes os dois se chamavam Estilo na mesma linha. */}
                  {personasEstilo.length > 0 && (
                    <div style={{ display: "flex", flexDirection: "column", gap: 4 }}>
                      <label className="txt" style={{ color: "var(--muted)", fontSize: ".78rem" }}>Estilo</label>
                      {/* Só as famílias que NÃO são cor — as de cor moram no seletor 🎨 Cor. */}
                      <select value={personasCor.some((p) => String(p.id) === personaId) ? "" : personaId} onChange={(e) => setPersonaId(e.target.value)} style={sel} title="Direção autoral aplicada ao prompt — lente, luz e textura. Edite ou crie os seus na aba Prompts.">
                        <option value="">Sem estilo</option>
                        {agruparPersonas(personasEstilo).map(([grupo, itens]) => (
                          <optgroup key={grupo} label={grupo}>
                            {itens.map((p) => <option key={p.id} value={String(p.id)}>{tituloLimpo(p.title)}</option>)}
                          </optgroup>
                        ))}
                        <option value="custom">✏️ Escrever o meu…</option>
                      </select>
                    </div>
                  )}
                  {/* PERSONAGEM (identity lock) — o que faltava aqui e existia só nas abas
                      Imagem/Vídeo do Estúdio. Sem ele, gerar "a Mel" no post dependia de
                      descrevê-la no texto e sair parecida por sorte. Vale pro clipe também: o
                      seletor é o mesmo estado, então a identidade não se perde entre as peças. */}
                  {personagens.length > 0 && (
                    <div style={{ display: "flex", flexDirection: "column", gap: 4 }}>
                      <label className="txt" style={{ color: "var(--muted)", fontSize: ".78rem" }}>Personagem</label>
                      <select value={genChar} onChange={(e) => setGenChar(e.target.value)} style={sel} title="Trava a identidade do personagem na geração (o servidor injeta o lock no prompt). Vale para a imagem e para o vídeo.">
                        <option value="">Nenhum</option>
                        {personagens.map((c) => <option key={c.id} value={String(c.id)}>{c.name}</option>)}
                      </select>
                    </div>
                  )}
                  {/* Refinador: quem reescreve o pedido aplicando a persona antes de gerar.
                      Só lista os motores que o bridge reporta vivos. Não custa crédito. */}
                  {refiners.length > 0 && (
                    <div style={{ display: "flex", flexDirection: "column", gap: 4 }}>
                      <label className="txt" style={{ color: "var(--muted)", fontSize: ".78rem" }}>Refinar prompt</label>
                      <select value={refiner} onChange={(e) => setRefiner(e.target.value)} style={sel} title="Reescreve o seu pedido como prompt de cinema, aplicando a persona escolhida. Leva alguns segundos a mais e não consome créditos.">
                        <option value="">Não refinar</option>
                        {refiners.map((x) => <option key={x.id} value={x.id}>{x.label}</option>)}
                      </select>
                    </div>
                  )}
                  {/* Qualidade (resolução) da imagem — só nos modelos com tiers; preço por qualidade */}
                  {(() => {
                    const im = imageModels.find((m) => m.slug === imageModel);
                    return im?.qualities && im.qualities.length > 1 ? (
                      <div style={{ display: "flex", flexDirection: "column", gap: 4 }}>
                        <label className="txt" style={{ color: "var(--muted)", fontSize: ".78rem" }}>Qualidade</label>
                        <select value={imageQuality} onChange={(e) => setImageQuality(e.target.value)} disabled={Boolean(inputImageUrl)} style={{ ...sel, opacity: inputImageUrl ? .5 : 1 }} title={inputImageUrl ? "Ao partir de uma imagem, a resolução segue a imagem de base — esta escolha não se aplica." : "Resolução — mais alta = melhor qualidade e mais créditos"}>
                          {im.qualities.map((q) => <option key={q.key} value={q.key}>{q.label}{q.p != null ? ` · ${q.p} créd` : ""}</option>)}
                        </select>
                      </div>
                    ) : null;
                  })()}
                  {/* "Preset do logo" NÃO mora mais aqui: valia só pra 1 dos 3 botões e ficava
                      pedindo uma decisão que não interessa a quem vai gerar imagem ou GIF.
                      Migrou pra junto do botão "Gerar logo", que é quem o consome. */}
                </div>
                {/* Persona escrita na hora. Vale só pra esta geração; o 💾 guarda na aba Prompts
                    (kind=image) e ela passa a aparecer no select como as nossas. */}
                {personaId === "custom" && (
                  <div style={{ display: "flex", flexDirection: "column", gap: 6 }}>
                    <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", gap: 8, flexWrap: "wrap" }}>
                      <label className="txt" style={{ color: "var(--muted)", fontSize: ".82rem" }}>Seu estilo (só desta geração)</label>
                      <button
                        className="btn edit"
                        style={{ flex: "none", padding: "6px 9px", fontSize: ".78rem" }}
                        title="Salvar na aba Prompts pra reusar depois"
                        onClick={async () => {
                          const txt = personaText.trim();
                          if (!txt) return setMsg("❌ Escreva a persona antes de salvar.");
                          const rr = await perguntar({ titulo: "Salvar estilo na aba Prompts", confirmar: "Salvar", campos: [{ label: "Nome", placeholder: "🎨 Estilo: minha" }] });
                          const title = (rr?.[0] ?? "").trim();
                          if (!title) return;
                          const r = await sfetch("/api/prompts", {
                            method: "POST", headers: { "Content-Type": "application/json" },
                            body: JSON.stringify({ title, kind: "image", content: txt }),
                          });
                          const d = await r.json();
                          if (!d.ok) return setMsg("❌ Não deu pra salvar a persona.");
                          setPersonas((cur) => [{ id: d.prompt.id, title: d.prompt.title }, ...cur]);
                          setPersonaId(String(d.prompt.id)); // passa a usar a salva
                          setMsg("✅ Persona salva na aba Prompts.");
                        }}
                      >💾</button>
                    </div>
                    <textarea
                      className="txt"
                      value={personaText}
                      onChange={(e) => setPersonaText(e.target.value)}
                      rows={3}
                      placeholder="Ex.: Componha como still de cinema — lente anamórfica, luz lateral dura, grade teal and orange, grão de filme 35mm."
                      style={{ width: "100%", resize: "vertical" }}
                    />
                  </div>
                )}
                {/* 🖼️ A PARTIR DE UMA IMAGEM (i2i) — mora AQUI DENTRO, no mesmo card. Era um card
                    separado logo abaixo, e ficava decorativo: o `inputImageUrl` nunca entrava no
                    payload de kind:"image" (o botão chamava gerarMidia("image") sem opts), então
                    escolher a imagem não mudava nada na geração de imagem. Junto do botão que a
                    consome, a relação fica óbvia — e o mesmo estado segue servindo o i2v abaixo. */}
                <div style={{ display: "flex", gap: 10, alignItems: "center", flexWrap: "wrap", padding: "10px 12px", borderRadius: 10, border: "1px dashed var(--line)" }}>
                  {inputImageUrl ? (
                    <>
                      <span style={{ display: "inline-flex", flexDirection: "column", gap: 4, alignItems: "center" }}>
                        <img src={inputImageUrl} alt="referência principal" style={{ width: 52, height: 52, objectFit: "cover", borderRadius: 8, border: "1px solid var(--line)", display: "block" }} />
                        <select value={refRole} onChange={(e) => setRefRole(e.target.value as RefRole)} style={{ ...sel, padding: "3px 5px", fontSize: ".68rem" }} title={REF_ROLES.find(([v]) => v === refRole)?.[2]}>
                          {REF_ROLES.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                        </select>
                      </span>
                      {/* Refs EXTRAS: cada uma com papel próprio. É o que torna útil mandar mais de
                          uma — sem papel, o modelo copia tudo de todas e sai colagem. */}
                      {refsExtra.map((rf, i) => (
                        <span key={rf.url} style={{ display: "inline-flex", flexDirection: "column", gap: 4, alignItems: "center", position: "relative" }}>
                          <img src={rf.url} alt={`referência ${i + 2}`} style={{ width: 52, height: 52, objectFit: "cover", borderRadius: 8, border: "1px solid var(--line)", display: "block" }} />
                          <button type="button" onClick={() => setRefsExtra((c) => c.filter((x) => x.url !== rf.url))} title="Remover" style={{ position: "absolute", top: -5, right: -5, width: 18, height: 18, borderRadius: "50%", border: 0, background: "rgba(0,0,0,.75)", color: "#fff", cursor: "pointer", fontSize: ".62rem", lineHeight: "18px", padding: 0 }}>✕</button>
                          <select value={rf.role} onChange={(e) => setRefsExtra((c) => c.map((x) => x.url === rf.url ? { ...x, role: e.target.value as RefRole } : x))} style={{ ...sel, padding: "3px 5px", fontSize: ".68rem" }} title={REF_ROLES.find(([v]) => v === rf.role)?.[2]}>
                            {REF_ROLES.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                          </select>
                        </span>
                      ))}
                      <span className="txt" style={{ color: "var(--muted)", fontSize: ".76rem", flex: 1, minWidth: 160 }}>
                        {refsExtra.length === 0
                          ? <>🖼️ Gerando <strong>a partir desta imagem</strong>. Some outra para combinar papéis — ex.: identidade de uma, direção de arte de outra.</>
                          : <>🖼️ {refsExtra.length + 1} referências, cada uma com seu papel. O prompt abaixo diz o que fazer com elas.</>}
                      </span>
                      {refsExtra.length < 2 && (
                        <span style={{ display: "inline-flex", gap: 4 }}>
                          <label className="btn edit" style={{ flex: "none", padding: "6px 9px", fontSize: ".74rem", cursor: "pointer" }} title="Somar outra imagem de referência">
                            + ⬆️
                            <input type="file" accept="image/*" style={{ display: "none" }} disabled={anyBusy} onClick={() => setPickerAlvo("extra")} onChange={(e) => e.target.files?.[0] && uploadInput(e.target.files[0])} />
                          </label>
                          <button type="button" className="btn edit" style={{ flex: "none", padding: "6px 9px", fontSize: ".74rem" }} onClick={() => { setPickerAlvo("extra"); setPickerOpen(true); }} title="Somar outra referência da galeria">+ 🖼️</button>
                        </span>
                      )}
                      <button type="button" className="btn edit" style={{ flex: "none", padding: "7px 12px" }} onClick={limparInput}>✕ Usar só texto</button>
                    </>
                  ) : (
                    <>
                      <span className="txt" style={{ color: "var(--muted)", fontSize: ".78rem", flex: 1, minWidth: 180 }}>
                        🖼️ Quer partir <strong>de uma imagem</strong> em vez de só texto? Escolha a base:
                      </span>
                      <label className="btn edit" style={{ flex: "none", padding: "7px 12px", cursor: "pointer" }}>
                        {busy === "up-input" ? "Enviando…" : "⬆️ Enviar do computador"}
                        <input type="file" accept="image/*" style={{ display: "none" }} disabled={anyBusy} onClick={() => setPickerAlvo("principal")} onChange={(e) => e.target.files?.[0] && uploadInput(e.target.files[0])} />
                      </label>
                      <button type="button" className="btn edit" style={{ flex: "none", padding: "7px 12px" }} onClick={() => { setPickerAlvo("principal"); setPickerOpen(true); }}>🖼️ Da galeria</button>
                    </>
                  )}
                </div>
                {/* Prompt opcional — gerado SÓ sob demanda, já condizente com o formato escolhido */}
                <div style={{ display: "flex", flexDirection: "column", gap: 6 }}>
                  <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", gap: 8, flexWrap: "wrap" }}>
                    <label className="txt" style={{ color: "var(--muted)", fontSize: ".82rem" }}>{inputImageUrl ? "O que mudar nesta imagem" : "Prompt da imagem (opcional)"}</label>
                    <div style={{ display: "flex", gap: 6, flexWrap: "wrap" }}>
                      <button className="btn edit" style={{ flex: "none", padding: "6px 9px", fontSize: ".78rem" }} onClick={() => setPromptTarget("image")} title="Carregar da aba Prompts">📂</button>
                      <button className="btn edit" style={{ flex: "none", padding: "6px 9px", fontSize: ".78rem" }} onClick={() => salvarPrompt(imagePrompt, "Prompt de imagem")} title="Salvar na aba Prompts">💾</button>
                      {draftId && (research?.summary || research?.answer) && (
                        <button className="btn edit" style={{ flex: "none", padding: "6px 11px", fontSize: ".78rem" }} disabled={anyBusy} onClick={() => gerarPrompt("image")} title="Gera um prompt de imagem a partir da referência, já no formato escolhido — cada clique varia">{busy === "imgprompt" ? "Gerando…" : "🎲 Gerar prompt de imagem"}</button>
                      )}
                    </div>
                  </div>
                  <AutoTextarea value={imagePrompt} onChange={setImagePrompt} minHeight={70} style={{ ...inp, lineHeight: 1.5, fontFamily: "inherit" }} placeholder={inputImageUrl ? "ex: troque o fundo por uma loja iluminada e deixe a luz mais quente, mantendo a pessoa igual" : "ex: uma pequena empresária sorrindo no balcão da loja com um tablet mostrando gráficos de crescimento"} />
                </div>
                <div style={{ display: "flex", gap: 8, flexWrap: "wrap", alignItems: "center" }}>
                  <button className="btn ok" style={{ flex: "none", padding: "9px 14px" }} disabled={anyBusy} onClick={() => gerarMidia("image", { imageUrl: inputImageUrl || undefined })} title={inputImageUrl ? "Transforma a imagem escolhida seguindo o prompt" : "Gera uma imagem nova a partir do prompt"}>{busy === "image" ? (inputImageUrl ? "Transformando..." : "Gerando...") : (inputImageUrl ? "🖼️ Transformar imagem" : "🖼️ Gerar imagem")}</button>
                  <button className="btn ok" style={{ flex: "none", padding: "9px 14px" }} disabled={anyBusy} onClick={() => gerarMidia("gif", { imageUrl: inputImageUrl || undefined })} title={inputImageUrl ? "Anima a imagem escolhida num GIF (loop curto)" : "Gera um GIF animado (loop curto) a partir do prompt"}>{busy === "gif" ? "Gerando GIF…" : (inputImageUrl ? "✨ Animar em GIF" : "✨ Gerar GIF")}</button>
                  {/* Logo = botão + o preset dele, lado a lado. Juntos porque o preset só existe
                      em função deste botão; separados, viravam uma opção órfã lá em cima. */}
                  <span style={{ display: "inline-flex", alignItems: "center", gap: 6 }}>
                    <button className="btn ok" style={{ flex: "none", padding: "9px 14px" }} disabled={anyBusy} onClick={() => gerarMidia("logo")} title="Gera um logotipo com o preset ao lado — sempre a partir do texto, ignora a imagem de base">{busy === "logo" ? "Gerando…" : "🏷️ Gerar logo"}</button>
                    <select value={logoPreset} onChange={(e) => setLogoPreset(e.target.value)} style={{ ...sel, padding: "7px 10px", fontSize: ".78rem" }} title="Estilo do logotipo — vale só para o botão ao lado" aria-label="Preset do logo">
                      {LOGO_PRESETS.map(([v, label]) => <option key={v} value={v}>{label}</option>)}
                    </select>
                  </span>
                  {/* ONDE a peça vai ser gerada e QUANTO custa — o mesmo badge das abas Imagem e
                      Vídeo. Vem DEPOIS dos botões e antes do clique justamente porque é o que
                      decide: crédito da nuvem, GPU do seu servidor ou assinatura desta máquina. */}
                  {(() => {
                    const cur = imageModels.find((m) => m.slug === imageModel);
                    if (!cur) return null;
                    // i2i não usa o modelo do seletor (quem transforma é o motor especialista),
                    // então anunciar o motor escolhido aqui seria mentira.
                    if (inputImageUrl) return <span className="badge" style={{ marginLeft: "auto" }} title="Ao partir de uma imagem, quem gera é o motor especialista em transformação — o modelo do seletor não se aplica.">🖼️ i2i · motor de transformação</span>;
                    const q = cur.qualities?.find((x) => x.key === imageQuality);
                    const custo = q?.p ?? cur.cost_credits;
                    return (
                      <span className="badge" style={{ marginLeft: "auto" }} title={ajudaMotor(cur)}>
                        {rotuloMotor(cur)} · {nomeModelo(cur)} · {custo ?? "—"} créditos
                      </span>
                    );
                  })()}
                </div>
                {/* 🟢 A luz do ComfyUI só faz sentido quando existe modelo 🎛️ no catálogo — senão
                    é um aviso sobre um servidor que esta tela nunca usaria. */}
                {imageModels.some((m) => m.runs_on === "estudio") && (
                  <div style={{ display: "flex", justifyContent: "flex-end" }}>
                    <ComfyStatus info={comfy} />
                  </div>
                )}
                {/* Arquivar/Atualizar/Limpar NÃO moram mais aqui: são gestão da galeria, não
                    geração. Ficavam no card de Imagem e confundiam — "+ Arquivar imagem" parecia
                    o jeito de partir de uma foto. Migraram pra barra em cima da grade de mídia. */}
              </div>
            );
          })()}

          {/* O card "Partir de uma imagem" que ficava aqui virou o bloco tracejado DENTRO do card
              🖼️ Imagem (junto do botão que o consome). O painel de vídeo abaixo tem controles
              próprios pro mesmo `inputImageUrl`, então o i2v continua igual.

              🎬 VÍDEO DE VOLTA (2026-08-01): a geração de vídeo saíra daqui em 2026-07-23 sob a
              tese de que vídeo era só do Estúdio. Na prática partiu a Mídia em duas telas — quem
              monta um post precisava sair pra outra aba pra gerar o clipe do MESMO rascunho. Os
              endpoints nunca foram removidos (só a UI), então isto é a restauração do painel, não
              uma feature nova: Mídia volta a gerar imagem, GIF, logo, música E vídeo do post. */}

          {/* 🎬 GERAR VÍDEO — painel unificado com opções combináveis */}
          {(() => {
            // Premium (premium) = 1 cena com áudio nativo: incompatível com cenas>1, narração e legenda.
            const maxScenes = maxScenesFor(duration); // teto por 5 min: 60 (clipe 5s) ou 30 (clipe 10s)
            const effScenes = premium ? 1 : Math.max(1, Math.min(maxScenes, scenes || 1));
            const totalSec = effScenes * Number(duration);
            // v2: modelo/qualidade selecionados → preço ao vivo (p5/p10 × cenas).
            const selVideoModel = videoModels.find((m) => m.slug === videoModel);
            const selQuality = selVideoModel?.qualities?.find((q) => q.key === videoQuality);
            const perClip = selQuality ? (duration === "10" ? selQuality.p10 : selQuality.p5) : (selVideoModel?.cost_credits ?? null);
            const videoCost = !premium && perClip != null ? perClip * effScenes : null;
            const tgl = (on: boolean): React.CSSProperties => ({
              padding: "7px 13px", borderRadius: 20, fontSize: ".8rem", cursor: "pointer",
              border: "1px solid " + (on ? "var(--red)" : "var(--line)"),
              background: on ? "rgba(226,74,49,.15)" : "var(--bg2)",
              color: on ? "var(--peach)" : "var(--muted)",
            });
            const sel = { background: "var(--bg2)", color: "var(--text)", border: "1px solid var(--line)", borderRadius: 8, padding: "9px 12px", fontSize: ".82rem" } as const;
            return (
              <div style={{ ...card, borderColor: "rgba(124,58,237,.4)", display: "flex", flexDirection: "column", gap: 14 }}>
                <strong style={{ fontSize: "1rem", color: "var(--peach)" }}>🎬 Gerar vídeo</strong>

                {/* Prompt do vídeo (opcional) — gerado SÓ sob demanda, já no formato/duração escolhidos */}
                <div style={{ display: "flex", flexDirection: "column", gap: 6 }}>
                  <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", gap: 8, flexWrap: "wrap" }}>
                    <label className="txt" style={{ color: "var(--muted)", fontSize: ".82rem" }}>Prompt do vídeo (opcional)</label>
                    <div style={{ display: "flex", gap: 6, flexWrap: "wrap" }}>
                      <button className="btn edit" style={{ flex: "none", padding: "6px 9px", fontSize: ".78rem" }} onClick={() => setPromptTarget("video")} title="Carregar da aba Prompts">📂</button>
                      <button className="btn edit" style={{ flex: "none", padding: "6px 9px", fontSize: ".78rem" }} onClick={() => salvarPrompt(videoPrompt, "Prompt de vídeo")} title="Salvar na aba Prompts">💾</button>
                      {draftId && (research?.summary || research?.answer) && (
                        <button className="btn edit" style={{ flex: "none", padding: "6px 11px", fontSize: ".78rem" }} disabled={anyBusy} onClick={() => gerarPrompt("video")} title="Gera um prompt de vídeo a partir da referência, já no formato/duração escolhidos — cada clique varia">{busy === "vidprompt" ? "Gerando…" : "🎲 Gerar prompt de vídeo"}</button>
                      )}
                    </div>
                  </div>
                  <AutoTextarea value={videoPrompt} onChange={setVideoPrompt} minHeight={70} style={{ ...inp, lineHeight: 1.5, fontFamily: "inherit" }} placeholder="ex: a empresária caminhando pela loja, câmera acompanhando, luz natural, ritmo dinâmico" />
                </div>

                {/* Linha 1: formato + cenas (manual) + duração de cada clipe + estilo */}
                <div style={{ display: "flex", gap: 14, flexWrap: "wrap", alignItems: "flex-end" }}>
                  <div style={{ display: "flex", flexDirection: "column", gap: 4 }}>
                    <label className="txt" style={{ color: "var(--muted)", fontSize: ".78rem" }}>Formato</label>
                    <select value={vidAspect} onChange={(e) => setVidAspect(e.target.value)} disabled={premium} style={{ ...sel, opacity: premium ? 0.55 : 1 }} title="Vertical (9:16) ou horizontal (16:9)">
                      {VID_ASPECTS.map(([v, label]) => <option key={v} value={v}>🎬 {label}</option>)}
                    </select>
                  </div>
                  <div style={{ display: "flex", flexDirection: "column", gap: 4 }}>
                    <label className="txt" style={{ color: "var(--muted)", fontSize: ".78rem" }}>Cenas (1–{maxScenes})</label>
                    <input type="number" min={1} max={maxScenes} value={effScenes} disabled={premium}
                      onChange={(e) => setScenes(Math.max(1, Math.min(maxScenes, Number(e.target.value) || 1)))}
                      style={{ ...sel, width: 96, opacity: premium ? 0.55 : 1 }} title={`Quantas cenas/clipes o vídeo terá (1 = curto; até ${maxScenes} para ~5 min). Cada cena gasta uma geração.`} />
                  </div>
                  <div style={{ display: "flex", flexDirection: "column", gap: 4 }}>
                    <label className="txt" style={{ color: "var(--muted)", fontSize: ".78rem" }}>Duração de cada clipe</label>
                    <select value={duration} onChange={(e) => setDuration(e.target.value)} style={sel} title="Duração (em segundos) de cada cena/clipe">
                      {DURATIONS.map(([v, label]) => <option key={v} value={v}>⏱️ {label}</option>)}
                    </select>
                  </div>
                  <div style={{ display: "flex", flexDirection: "column", gap: 4 }}>
                    <label className="txt" style={{ color: "var(--muted)", fontSize: ".78rem" }}>Técnica</label>
                    <select value={vidStyle} onChange={(e) => setVidStyle(e.target.value)} style={sel} title="Como o vídeo é filmado: cinematográfico, documental, timelapse… A cor fica em 🎨 Grade, e a direção autoral no seletor Estilo.">
                      {VIDEO_STYLES.map(([v, label]) => <option key={v} value={v}>{label}</option>)}
                    </select>
                  </div>
                  {!premium && (
                    <div style={{ display: "flex", flexDirection: "column", gap: 4 }}>
                      <label className="txt" style={{ color: "var(--muted)", fontSize: ".78rem" }}>🎨 Cor</label>
                      <select value={grade} onChange={(e) => setGrade(e.target.value)} style={sel} title="Acabamento visual da montagem (color grade)">
                        {GRADE_OPTIONS.map(([v, label]) => <option key={v} value={v}>{label}</option>)}
                      </select>
                    </div>
                  )}
                  {!premium && (
                    <label className="txt" style={{ display: "flex", alignItems: "center", gap: 6, fontSize: ".82rem", cursor: "pointer", alignSelf: "flex-end", paddingBottom: 6 }} title="Ruído de filme sutil (grain), junto do color grade">
                      <input type="checkbox" checked={grain} onChange={(e) => setGrain(e.target.checked)} /> 🌾 Grain
                    </label>
                  )}
                  {!premium && videoModels.length > 1 && (
                    <div style={{ display: "flex", flexDirection: "column", gap: 4 }}>
                      <label className="txt" style={{ color: "var(--muted)", fontSize: ".78rem" }}>Modelo</label>
                      <select value={videoModel} onChange={(e) => setVideoModel(e.target.value)} style={sel} title="Modelo de IA do vídeo — cada um tem qualidade e custo diferentes">
                        {/* Mesma leitura do card de Imagem: a origem vem antes do nome do modelo. */}
                        {videoModels.map((m) => <option key={m.slug} value={m.slug}>{marcaMotor(m)} · {nomeModelo(m)}{!m.qualities?.length && m.cost_credits != null ? ` · ${m.cost_credits} créd` : ""}</option>)}
                      </select>
                    </div>
                  )}
                  {/* PERSONAGEM: o MESMO estado do card de Imagem, de propósito — a peça do post
                      é do mesmo personagem, e travar a identidade só num dos dois lados era como
                      a Mel saía diferente na imagem e no clipe do mesmo rascunho. */}
                  {personagens.length > 0 && (
                    <div style={{ display: "flex", flexDirection: "column", gap: 4 }}>
                      <label className="txt" style={{ color: "var(--muted)", fontSize: ".78rem" }}>Personagem</label>
                      <select value={genChar} onChange={(e) => setGenChar(e.target.value)} style={sel} title="Trava a identidade do personagem no clipe (o servidor injeta o lock no prompt). É o mesmo personagem escolhido no card de Imagem.">
                        <option value="">Nenhum</option>
                        {personagens.map((c) => <option key={c.id} value={String(c.id)}>{c.name}</option>)}
                      </select>
                    </div>
                  )}
                  {/* Persona de vídeo: movimento de câmera e ritmo. Não custa crédito — é texto. */}
                  {!premium && vidPersonas.length > 0 && (
                    <div style={{ display: "flex", flexDirection: "column", gap: 4 }}>
                      <label className="txt" style={{ color: "var(--muted)", fontSize: ".78rem" }}>Estilo</label>
                      <select value={vidPersonaId} onChange={(e) => setVidPersonaId(e.target.value)} style={sel} title="Direção autoral do vídeo — movimento de câmera, ritmo e look. Edite ou crie os seus na aba Prompts.">
                        <option value="">Sem estilo</option>
                        {agruparPersonas(vidPersonas).map(([grupo, itens]) => (
                          <optgroup key={grupo} label={grupo}>
                            {itens.map((p) => <option key={p.id} value={String(p.id)}>{tituloLimpo(p.title)}</option>)}
                          </optgroup>
                        ))}
                        <option value="custom">✏️ Escrever o meu…</option>
                      </select>
                    </div>
                  )}
                  {/* v2: seletor de QUALIDADE (resolução) — só quando o modelo tem tiers; preço por qualidade/duração */}
                  {!premium && selVideoModel?.qualities && selVideoModel.qualities.length > 1 && (
                    <div style={{ display: "flex", flexDirection: "column", gap: 4 }}>
                      <label className="txt" style={{ color: "var(--muted)", fontSize: ".78rem" }}>Qualidade</label>
                      <select value={videoQuality} onChange={(e) => setVideoQuality(e.target.value)} style={sel} title="Resolução — mais alta = melhor qualidade e mais créditos">
                        {selVideoModel.qualities.map((q) => {
                          const p = duration === "10" ? q.p10 : q.p5;
                          return <option key={q.key} value={q.key}>{q.label}{p != null ? ` · ${p} créd` : ""}</option>;
                        })}
                      </select>
                    </div>
                  )}
                </div>
                {/* Persona de vídeo escrita na hora — vale só pra esta geração. */}
                {vidPersonaId === "custom" && (
                  <textarea
                    className="txt"
                    value={vidPersonaText}
                    onChange={(e) => setVidPersonaText(e.target.value)}
                    rows={3}
                    placeholder="Ex.: Câmera na mão com micro-tremor, dolly lento de aproximação, corte no beat, grade teal and orange."
                    style={{ width: "100%", resize: "vertical" }}
                  />
                )}
                {/* Duração total = nº de cenas × duração de cada clipe (dinâmico). */}
                <p className="txt" style={{ color: "var(--muted)", fontSize: ".75rem", margin: "-4px 0 0" }}>
                  ⏳ Duração total ≈ <strong style={{ color: "var(--peach)" }}>{totalSec >= 60 ? `${Math.floor(totalSec / 60)}min${totalSec % 60 ? ` ${totalSec % 60}s` : ""}` : `${totalSec}s`}</strong> ({effScenes} {effScenes === 1 ? "cena" : "cenas"} × {duration}s · máx 5 min){narration && !premium ? " · com narração, cada cena se ajusta à fala (pode variar)" : ""}
                  {videoCost != null && <span style={{ color: "var(--green)" }}> · 💎 {videoCost} créd{effScenes > 1 && perClip != null ? ` (${perClip}×${effScenes})` : ""}</span>}
                  {effScenes >= 6 && <span style={{ color: "#f59e0b" }}> · ⚠️ {effScenes} cenas = {effScenes} gerações de IA (mais tempo e custo)</span>}
                </p>
                {/* ONDE o clipe é gerado — mesmo vocabulário do card de Imagem e das abas do
                    Estúdio. No Premium quem gera é a rota do Veo, não o modelo do seletor. */}
                <div style={{ display: "flex", alignItems: "center", gap: 10, flexWrap: "wrap", justifyContent: "flex-end", marginTop: -4 }}>
                  {premium
                    ? <span className="badge" title="Qualidade Premium: 1 cena com áudio nativo, por uma rota própria — o modelo do seletor não se aplica.">⭐ Premium · áudio nativo</span>
                    : selVideoModel && (
                      <span className="badge" title={ajudaMotor(selVideoModel)}>
                        {rotuloMotor(selVideoModel)} · {nomeModelo(selVideoModel)} · {perClip ?? "—"} créditos/clipe
                      </span>
                    )}
                  {videoModels.some((m) => m.runs_on === "estudio") && <ComfyStatus info={comfy} />}
                </div>

                {/* Linha 2: toggles combináveis */}
                <div style={{ display: "flex", gap: 8, flexWrap: "wrap", alignItems: "center" }}>
                  <span className="txt" style={{ color: "var(--muted)", fontSize: ".78rem", marginRight: 2 }}>Opções:</span>
                  <button type="button" onClick={() => setNarration((v) => !v)} disabled={premium} style={{ ...tgl(narration && !premium), opacity: premium ? 0.55 : 1 }} title="Adiciona voz narrando o conteúdo">🎙️ Narração{narration && !premium ? " ✓" : ""}</button>
                  <button type="button" onClick={() => setSubtitles((v) => !v)} disabled={premium} style={{ ...tgl(subtitles && !premium), opacity: premium ? 0.55 : 1 }} title="Legenda na tela (permitida mesmo sem narração)">💬 Legenda{subtitles && !premium ? " ✓" : ""}</button>
                  <button type="button" onClick={() => setMusic((v) => !v)} style={tgl(music)} title="Trilha musical de fundo">🎵 Música{music ? " ✓" : ""}</button>
                  {/* Sem toggle "🌊 Fluido" aqui de propósito: o /v1/video do engine não aceita
                      `smooth` (só as rotas que MONTAM vários clipes). O botão existe na aba Vídeo
                      do Estúdio, mas cai num campo que ninguém lê. Botão que não faz nada é pior
                      que botão ausente — volta quando o engine aceitar o campo. */}
                  <span style={{ width: 1, height: 22, background: "var(--line)", margin: "0 2px" }} />
                  <button type="button" onClick={() => setPremium((v) => !v)} style={tgl(premium)} title="Qualidade Premium (premium): 1 cena com áudio nativo">⭐ {premium ? "Premium (premium) ✓" : "Qualidade: Padrão"}</button>
                </div>

                {/* Estilo da legenda — só no modo Padrão com Legenda ligada. O vídeo já sai com a legenda
                    no visual escolhido; o ajuste fino fica pro pós-processamento. Espelha o Histórias. */}
                {subtitles && !premium && (
                  <div style={{ display: "flex", flexDirection: "column", gap: 10, padding: "12px 14px", border: "1px solid var(--line)", borderRadius: 10, background: "var(--bg2)" }}>
                    <div style={{ display: "flex", alignItems: "center", gap: 6 }} title="Onde a legenda aparece no vídeo">
                      <span className="txt" style={{ color: "var(--muted)", fontSize: ".82rem" }}>Legenda:</span>
                      <div style={{ display: "flex", border: "1px solid var(--line)", borderRadius: 16, overflow: "hidden" }}>
                        {([["top", "⬆️ Em cima"], ["middle", "⏺️ Meio"], ["bottom", "⬇️ Embaixo"]] as const).map(([v, lbl]) => {
                          const on = subtitlePos === v;
                          return <button key={v} type="button" onClick={() => setSubtitlePos(v)} style={{ padding: "6px 12px", fontSize: ".77rem", cursor: "pointer", border: 0, background: on ? "var(--red)" : "transparent", color: on ? "#fff" : "var(--muted)", fontWeight: on ? 700 : 400 }}>{lbl}</button>;
                        })}
                      </div>
                    </div>
                    {/* 🎞️ ANIMAÇÃO DA LEGENDA — estava IMPLEMENTADA de ponta a ponta e inalcançável:
                        o engine aceita subtitleAnim/subtitleAccentColor em /v1/video, o Studio já
                        mandava os dois (ver gerarMidia), mas `setSubtitleAnim` nunca era chamado —
                        não existia controle nenhum, então o preset ficava "" pra sempre e a legenda
                        saía sempre queimada. Faltava só isto aqui. */}
                    <div style={{ display: "flex", alignItems: "center", gap: 14, flexWrap: "wrap" }}>
                      <label className="txt" style={{ display: "flex", alignItems: "center", gap: 6, fontSize: ".82rem" }} title="Queimada: o texto entra fixo no vídeo. Os presets animam a legenda palavra a palavra (estilo Reels/TikTok), realçando a que está sendo falada.">
                        <span style={{ color: "var(--muted)" }}>Animação</span>
                        <select value={subtitleAnim} onChange={(e) => setSubtitleAnim(e.target.value)} style={{ fontSize: ".8rem", padding: "4px 6px", border: "1px solid var(--line)", borderRadius: 6, background: "transparent", color: "var(--fg)", cursor: "pointer" }}>
                          <option value="">Queimada (fixa)</option>
                          <option value="pop">Pop</option>
                          <option value="karaoke">Karaokê</option>
                          <option value="bounce">Bounce</option>
                        </select>
                      </label>
                      {/* A cor de realce só existe no modo animado (é a palavra ATIVA) — fora dele
                          seria um controle sem efeito, que é o bug que acabamos de corrigir. */}
                      {subtitleAnim && (
                        <label className="txt" style={{ display: "flex", alignItems: "center", gap: 6, fontSize: ".82rem" }} title="Cor da palavra que está sendo falada naquele instante">
                          <span style={{ color: "var(--muted)" }}>Realce</span>
                          <input type="color" value={subtitleAccentColor} onChange={(e) => setSubtitleAccentColor(e.target.value)} style={{ width: 34, height: 26, padding: 0, border: "1px solid var(--line)", borderRadius: 6, background: "transparent", cursor: "pointer" }} />
                        </label>
                      )}
                    </div>
                    {/* fonte · tamanho · cor · borda (+cor) · transparência */}
                    <div style={{ display: "flex", alignItems: "center", gap: 14, flexWrap: "wrap" }}>
                      <label className="txt" style={{ display: "flex", alignItems: "center", gap: 6, fontSize: ".82rem" }} title="Fonte da legenda">
                        <span style={{ color: "var(--muted)" }}>Fonte</span>
                        <select value={subtitleFont} onChange={(e) => setSubtitleFont(e.target.value)} style={{ fontSize: ".8rem", padding: "4px 6px", border: "1px solid var(--line)", borderRadius: 6, background: "transparent", color: "var(--fg)", cursor: "pointer" }}>
                          <option value="sans">Padrão</option>
                          <option value="serif">Serifada</option>
                          <option value="mono">Mono</option>
                          <option value="dejavu">Suave</option>
                          <option value="dejavu-serif">Clássica</option>
                          <option value="noto">Universal</option>
                        </select>
                      </label>
                      <label className="txt" style={{ display: "flex", alignItems: "center", gap: 6, fontSize: ".82rem" }} title="Tamanho da fonte da legenda">
                        <span style={{ color: "var(--muted)" }}>Tamanho</span>
                        <input type="range" min={12} max={56} value={subtitleSize} onChange={(e) => setSubtitleSize(Number(e.target.value))} style={{ width: 100 }} />
                        <span style={{ color: "var(--muted)", minWidth: 24, textAlign: "right" }}>{subtitleSize}</span>
                      </label>
                      <label className="txt" style={{ display: "flex", alignItems: "center", gap: 6, fontSize: ".82rem" }} title="Cor do texto da legenda">
                        <span style={{ color: "var(--muted)" }}>Cor</span>
                        <input type="color" value={subtitleColor} onChange={(e) => setSubtitleColor(e.target.value)} style={{ width: 34, height: 26, padding: 0, border: "1px solid var(--line)", borderRadius: 6, background: "transparent", cursor: "pointer" }} />
                      </label>
                      <label className="txt" style={{ display: "flex", alignItems: "center", gap: 6, fontSize: ".82rem" }} title="Espessura do contorno do texto (1..10)">
                        <span style={{ color: "var(--muted)" }}>Borda</span>
                        <input type="range" min={1} max={10} value={subtitleBorder} onChange={(e) => setSubtitleBorder(Number(e.target.value))} style={{ width: 80 }} />
                        <span style={{ color: "var(--muted)", minWidth: 16, textAlign: "right" }}>{subtitleBorder}</span>
                      </label>
                      <label className="txt" style={{ display: "flex", alignItems: "center", gap: 6, fontSize: ".82rem" }} title="Cor do contorno da legenda">
                        <span style={{ color: "var(--muted)" }}>Cor borda</span>
                        <input type="color" value={subtitleBorderColor} onChange={(e) => setSubtitleBorderColor(e.target.value)} style={{ width: 34, height: 26, padding: 0, border: "1px solid var(--line)", borderRadius: 6, background: "transparent", cursor: "pointer" }} />
                      </label>
                      <label className="txt" style={{ display: "flex", alignItems: "center", gap: 6, fontSize: ".82rem" }} title="Transparência do texto (0 = opaco)">
                        <span style={{ color: "var(--muted)" }}>Transp.</span>
                        <input type="range" min={0} max={90} value={subtitleOpacity} onChange={(e) => setSubtitleOpacity(Number(e.target.value))} style={{ width: 80 }} />
                        <span style={{ color: "var(--muted)", minWidth: 30, textAlign: "right" }}>{subtitleOpacity}%</span>
                      </label>
                    </div>
                    {/* Fundo (caixa) atrás do texto — opcional — + prévia ao vivo */}
                    <div style={{ display: "flex", alignItems: "center", gap: 14, flexWrap: "wrap" }}>
                      <label className="txt" style={{ display: "flex", alignItems: "center", gap: 6, fontSize: ".82rem", cursor: "pointer" }} title="Desenha uma caixa atrás do texto (melhora a leitura sobre fundos claros)">
                        <input type="checkbox" checked={subtitleBg} onChange={(e) => setSubtitleBg(e.target.checked)} />
                        <span style={{ color: "var(--muted)" }}>Fundo (caixa)</span>
                      </label>
                      {subtitleBg && (
                        <>
                          <label className="txt" style={{ display: "flex", alignItems: "center", gap: 6, fontSize: ".82rem" }} title="Cor da caixa">
                            <span style={{ color: "var(--muted)" }}>Cor fundo</span>
                            <input type="color" value={subtitleBgColor} onChange={(e) => setSubtitleBgColor(e.target.value)} style={{ width: 34, height: 26, padding: 0, border: "1px solid var(--line)", borderRadius: 6, background: "transparent", cursor: "pointer" }} />
                          </label>
                          <label className="txt" style={{ display: "flex", alignItems: "center", gap: 6, fontSize: ".82rem" }} title="Opacidade da caixa (100 = sólida)">
                            <span style={{ color: "var(--muted)" }}>Opacidade</span>
                            <input type="range" min={10} max={100} value={subtitleBgOpacity} onChange={(e) => setSubtitleBgOpacity(Number(e.target.value))} style={{ width: 90 }} />
                            <span style={{ color: "var(--muted)", minWidth: 30, textAlign: "right" }}>{subtitleBgOpacity}%</span>
                          </label>
                        </>
                      )}
                      {/* Prévia da legenda em ESCALA REAL: quadro na PROPORÇÃO do vídeo com altura
                          288px — a MESMA régua da legenda queimada (FontSize sobre PlayRes 288).
                          O tamanho/posição relativos são os do vídeo final (a prévia antiga, fonte
                          ×0,5 sem quadro, enganava). */}
                      <div style={{ position: "relative", flex: "none", height: 288, width: vidAspect === "16:9" ? 512 : 162, borderRadius: 8, border: "1px solid var(--line)", overflow: "hidden", background: "linear-gradient(165deg,#2b3552 0%,#131a2c 55%,#2f2216 100%)", display: "flex", alignItems: subtitlePos === "top" ? "flex-start" : subtitlePos === "middle" ? "center" : "flex-end", justifyContent: "center" }} title="Prévia em escala real: a proporção do quadro e o tamanho da legenda são os do vídeo final">
                        <span style={{ fontFamily: ({ sans: "sans-serif", serif: "serif", mono: "monospace", dejavu: "sans-serif", "dejavu-serif": "serif", noto: "sans-serif" } as Record<string, string>)[subtitleFont] || "sans-serif", fontSize: subtitleSize, lineHeight: 1.2, fontWeight: 700, textAlign: "center" as const, color: subtitleColor, opacity: 1 - subtitleOpacity / 100, WebkitTextStroke: `${Math.max(0.5, subtitleBorder * 0.7)}px ${subtitleBorderColor}`, textShadow: "1px 1px 2px rgba(0,0,0,.55)", background: subtitleBg ? `${subtitleBgColor}${Math.round(subtitleBgOpacity * 2.55).toString(16).padStart(2, "0")}` : "transparent", padding: subtitleBg ? "2px 8px" : 0, borderRadius: 4, margin: subtitlePos === "middle" ? 0 : "14px 8px", maxWidth: "94%" }}>SUA LEGENDA</span>
                      </div>
                    </div>
                  </div>
                )}

                {/* Voz + idioma — narração no modo Padrão OU "minha narração" no vídeo premium */}
                {((narration && !premium) || (premium && veoNarration)) && (
                  <div style={{ display: "flex", gap: 10, flexWrap: "wrap", alignItems: "flex-end" }}>
                    <div style={{ display: "flex", flexDirection: "column", gap: 4 }}>
                      <label className="txt" style={{ color: "var(--muted)", fontSize: ".78rem" }}>Voz</label>
                      <select value={voice} onChange={(e) => setVoice(e.target.value)} style={sel}>
                        {voices.map((v) => {
                          const g = v.gender === "female" ? "feminina" : v.gender === "male" ? "masculina" : v.gender;
                          const det = [g, v.accent].filter(Boolean).join(", ");
                          return <option key={v.id} value={v.id}>🎙️ {v.name}{det ? ` — ${det}` : ""}</option>;
                        })}
                        {myVoice && <option value={myVoice}>🎙️ Minha voz (clonada)</option>}
                      </select>
                    </div>
                    <div style={{ display: "flex", flexDirection: "column", gap: 4 }}>
                      <label className="txt" style={{ color: "var(--muted)", fontSize: ".78rem" }}>Idioma</label>
                      <select value={lang} onChange={(e) => setLang(e.target.value)} style={sel}>
                        {LANGS.map(([v, label]) => <option key={v} value={v}>🗣️ {label}</option>)}
                      </select>
                    </div>
                    <label className="btn edit" style={{ flex: "none", padding: "9px 12px", cursor: "pointer", fontSize: ".82rem" }} title="Clonar sua voz a partir de uma amostra de áudio">{busy === "voice" ? "Clonando..." : (myVoice ? "↻ Reclonar voz" : "🎙️ Clonar minha voz")}<input type="file" accept="audio/*" style={{ display: "none" }} onChange={(e) => e.target.files?.[0] && clonarVoz(e.target.files[0])} /></label>
                  </div>
                )}

                {/* Premium (premium): opção de trocar o áudio nativo pela narração própria */}
                {premium && (
                  <div style={{ display: "flex", gap: 8, alignItems: "center", flexWrap: "wrap" }}>
                    <button type="button" onClick={() => setVeoNarration((v) => !v)} style={tgl(veoNarration)} title="Gera o vídeo premium SEM o áudio nativo e coloca a SUA narração (voz) por cima, com legenda">🎙️ Minha narração{veoNarration ? " ✓" : ""}</button>
                    <span className="txt" style={{ color: "var(--muted)", fontSize: ".74rem" }}>{veoNarration ? "Veo mudo + sua voz + legenda (a IA escreve um roteiro curto da referência)." : "Padrão: 1 cena com o áudio nativo do Veo."}</span>
                  </div>
                )}

                {/* 🖼️ Partir de uma imagem (i2v) — os controles ficam AQUI DENTRO, e não só no
                    card azul acima, porque quem quer "vídeo a partir de foto" olha este painel e
                    não encontrava o botão: ele morava num card separado, e o "+ Upload imagem"
                    do painel de Imagem (que só joga na galeria) parecia ser o caminho. */}
                <div style={{ display: "flex", gap: 10, alignItems: "center", flexWrap: "wrap", padding: "10px 12px", borderRadius: 10, border: "1px dashed var(--line)" }}>
                  {inputImageUrl ? (
                    <>
                      <img src={inputImageUrl} alt="base do vídeo" style={{ width: 52, height: 52, objectFit: "cover", borderRadius: 8, border: "1px solid var(--line)", display: "block" }} />
                      <span className="txt" style={{ color: "var(--muted)", fontSize: ".78rem", flex: 1, minWidth: 180 }}>
                        🖼️ Gerando <strong>a partir desta imagem</strong> (i2v) — o vídeo começa nela.
                      </span>
                      <button type="button" className="btn edit" style={{ flex: "none", padding: "7px 12px" }} onClick={limparInput}>✕ Usar só texto</button>
                    </>
                  ) : (
                    <>
                      <span className="txt" style={{ color: "var(--muted)", fontSize: ".78rem", flex: 1, minWidth: 180 }}>
                        🖼️ Quer o vídeo <strong>a partir de uma foto</strong>? Escolha a imagem inicial:
                      </span>
                      <label className="btn edit" style={{ flex: "none", padding: "7px 12px", cursor: "pointer" }}>
                        {busy === "up-input" ? "Enviando…" : "⬆️ Enviar foto"}
                        <input type="file" accept="image/*" style={{ display: "none" }} disabled={anyBusy} onChange={(e) => e.target.files?.[0] && uploadInput(e.target.files[0])} />
                      </label>
                      <button type="button" className="btn edit" style={{ flex: "none", padding: "7px 12px" }} onClick={() => setPickerOpen(true)}>🖼️ Da galeria</button>
                    </>
                  )}
                </div>

                {/* Botão único */}
                <div style={{ display: "flex", gap: 10, alignItems: "center", flexWrap: "wrap" }}>
                  <button
                    className="btn ok"
                    style={{ flex: "none", padding: "10px 18px" }}
                    disabled={anyBusy}
                    onClick={() => gerarMidia("video", {
                      imageUrl: inputImageUrl || undefined,
                      video: {
                        scenes: effScenes, duration,
                        narration: premium ? veoNarration : narration,
                        subtitles: premium ? veoNarration : subtitles,
                        music, premium,
                        voice_id: (premium ? veoNarration : narration) ? voice : undefined,
                        lang, style: vidStyle,
                      },
                    })}
                    title="Gera o vídeo com as opções selecionadas"
                  >{busy === "video" ? "⏳ Gerando vídeo… (aguarde)" : "🎬 Gerar vídeo"}</button>
                </div>

                {/* Avisos de custo/tempo */}
                <p className="txt" style={{ color: "var(--muted)", fontSize: ".76rem", margin: 0 }}>
                  Cada opção ligada aumenta tempo e custo. Sem narração, a legenda usa um tempo estimado por cena.
                </p>
              </div>
            );
          })()}

          {/* 🎵 Gerar música (RedFox AI · Música) */}
          <div style={{ ...card, borderColor: "rgba(236,72,153,.4)" }}>
            <strong style={{ fontSize: "1rem", color: "var(--peach)" }}>🎵 Gerar música</strong>
            <p className="txt" style={{ color: "var(--muted)", margin: "4px 0 10px", fontSize: ".82rem" }}>Trilha ou tema original a partir de uma descrição (estilo, mood). Aparece na galeria como áudio.</p>
            <textarea value={musicPrompt} onChange={(e) => setMusicPrompt(e.target.value)} placeholder="Ex.: indie folk melancólico, violão suave, clima introspectivo" rows={2} style={{ width: "100%", resize: "vertical", padding: 8, borderRadius: 8, border: "1px solid var(--line)", background: "transparent", color: "var(--fg)", fontSize: ".85rem", boxSizing: "border-box" }} />
            <div style={{ display: "flex", alignItems: "center", gap: 14, flexWrap: "wrap", marginTop: 8 }}>
              <label className="txt" style={{ display: "flex", alignItems: "center", gap: 6, fontSize: ".82rem", cursor: "pointer" }} title="Sem vocais (só instrumental)">
                <input type="checkbox" checked={musicInstrumental} onChange={(e) => setMusicInstrumental(e.target.checked)} />
                <span style={{ color: "var(--muted)" }}>Instrumental (sem vocais)</span>
              </label>
              <button className="btn ok" style={{ flex: "none", padding: "9px 14px" }} disabled={anyBusy} onClick={() => gerarMusica()}>{busy === "music" ? "⏳ Gerando música…" : "🎵 Gerar música"}</button>
            </div>
          </div>

          {/* 🎠 CARROSSEL — plano editorial revisável e só depois o render dos slides.
              Vive em componente próprio porque tem estado e polling próprios: o plano é uma
              geração de texto e o render é uma fila de N imagens com a capa na frente. */}
          <Carrossel
            draftId={draftId}
            card={card}
            onDraft={adotarDraft}
            onMedia={async () => {
              // O carrossel anexa cada slide à galeria; recarrega pra aparecer aqui embaixo.
              const rr = await sfetch(`/api/studio/draft?id=${draftId}`).then((x) => x.json()).catch(() => null);
              if (rr?.ok) setMedia(rr.draft.media || []);
            }}
          />

          {/* 🎞️ MOTION — tela estática → aprovação → MP4 de motion graphics.
              Não se anima do nada: primeiro existe um quadro com todos os elementos dentro, e só
              depois de aprovado é que ele vira clipe. Animar de texto puro devolve movimento
              genérico; animar de um quadro aprovado devolve a peça do usuário se mexendo. */}
          {/* 📰 O card VOX que ficava aqui virou a aba própria /video (components/video/VideoStudio.tsx). */}
          {(() => {
            const sel = { background: "var(--bg2)", color: "var(--text)", border: "1px solid var(--line)", borderRadius: 8, padding: "9px 12px", fontSize: ".82rem" } as const;
            const estrutura = MOTION_STRUCTURES.find(([v]) => v === motionStruct);
            return (
              <div style={{ ...card, borderColor: "rgba(245,158,11,.4)", display: "flex", flexDirection: "column", gap: 12 }}>
                <strong style={{ fontSize: "1rem", color: "var(--peach)" }}>🎞️ Motion</strong>
                <p className="txt" style={{ color: "var(--muted)", margin: "-4px 0 0", fontSize: ".82rem" }}>
                  Gera uma <strong>tela estática</strong> com todos os elementos da peça. Você aprova, e ela vira um MP4 de motion graphics — camadas entrando uma a uma, segunda composição, quadro limpo no fim.
                </p>
                <textarea value={motionDesc} onChange={(e) => setMotionDesc(e.target.value)} rows={2}
                  placeholder="Ex.: como um pedido chega até a sua porta — do clique à entrega"
                  style={{ width: "100%", resize: "vertical", padding: 8, borderRadius: 8, border: "1px solid var(--line)", background: "transparent", color: "var(--fg)", fontSize: ".85rem", boxSizing: "border-box" }} />
                <div style={{ display: "flex", gap: 14, flexWrap: "wrap", alignItems: "flex-end" }}>
                  <div style={{ display: "flex", flexDirection: "column", gap: 4 }}>
                    <label className="txt" style={{ color: "var(--muted)", fontSize: ".78rem" }}>Estrutura</label>
                    <select value={motionStruct} onChange={(e) => setMotionStruct(e.target.value)} style={sel} title="Como a peça se move. Cada estrutura é um roteiro de tempos montado pelo servidor — você escolhe a intenção, não o prompt.">
                      {MOTION_STRUCTURES.map(([v, label]) => <option key={v} value={v}>{label}</option>)}
                    </select>
                  </div>
                  <div style={{ display: "flex", flexDirection: "column", gap: 4 }}>
                    {/* Mesma Técnica do card de Imagem, de propósito: motion não precisa de catálogo
                        próprio de estilo, e é na técnica que o look se trava. */}
                    <label className="txt" style={{ color: "var(--muted)", fontSize: ".78rem" }}>Técnica</label>
                    <select value={motionStyle} onChange={(e) => setMotionStyle(e.target.value)} style={sel} title="Do que a peça é feita. A colagem editorial é a linguagem do jornalismo explicativo em motion graphics.">
                      {STYLES.map(([v, label]) => <option key={v} value={v}>{label}</option>)}
                    </select>
                  </div>
                  <div style={{ display: "flex", flexDirection: "column", gap: 4 }}>
                    <label className="txt" style={{ color: "var(--muted)", fontSize: ".78rem" }}>Formato</label>
                    <select value={motionAspect} onChange={(e) => setMotionAspect(e.target.value)} style={sel} title="Proporção da peça">
                      <option value="9:16">🖼️ 9:16 vertical</option>
                      <option value="16:9">🖼️ 16:9 horizontal</option>
                    </select>
                  </div>
                  {/* MODELOS do motion, à vista no próprio card. Eles sempre foram enviados
                      (imageModel na tela, videoModel na animação), mas os selects moravam nos cards
                      de Imagem e Vídeo, lá em cima: quem estava no Motion não via com o que estava
                      gerando nem conseguia trocar sem sair daqui. Mesmo estado, agora visível —
                      trocar aqui é trocar lá, de propósito, pra não existir dois motores em
                      disputa pela mesma peça. */}
                  <SeletorModeloImagem modelos={imageModels} valor={imageModel} onChange={setImageModel} estilo={sel}
                    rotulo="Modelo da tela"
                    desabilitadoPor={motionBase ? "A tela veio da sua imagem — não há geração a fazer." : undefined} />
                  {videoModels.length > 1 && (
                    <div style={{ display: "flex", flexDirection: "column", gap: 4 }}>
                      <label className="txt" style={{ color: "var(--muted)", fontSize: ".78rem" }}>Modelo da animação</label>
                      <select value={videoModel} onChange={(e) => setVideoModel(e.target.value)} style={sel} title="Modelo que ANIMA a tela aprovada (passo 2) — é onde o custo do motion mora. Mesmo seletor do card de Vídeo.">
                        {videoModels.map((m) => <option key={m.slug} value={m.slug}>{marcaMotor(m)} · {nomeModelo(m)}{!m.qualities?.length && m.cost_credits != null ? ` · ${m.cost_credits} créd` : ""}</option>)}
                      </select>
                    </div>
                  )}
                  <div style={{ display: "flex", flexDirection: "column", gap: 4 }}>
                    <label className="txt" style={{ color: "var(--muted)", fontSize: ".78rem" }}>Duração · {motionSecs}s</label>
                    <input type="range" min={MOTION_DUR_MIN} max={MOTION_DUR_MAX} value={motionSecs}
                      onChange={(e) => setMotionSecs(Number(e.target.value))} style={{ width: 150 }}
                      title="Duração do clipe (4 a 15s — o limite do modelo de vídeo). O servidor entrega a duração mais próxima que o motor produz e avisa qual foi." />
                  </div>
                </div>
                {motionStruct === "cartelas" && (
                  <div style={{ display: "flex", flexDirection: "column", gap: 4 }}>
                    <label className="txt" style={{ color: "var(--muted)", fontSize: ".78rem" }}>Frases (uma por linha, até 6)</label>
                    <textarea value={motionLines} onChange={(e) => setMotionLines(e.target.value)} rows={3}
                      placeholder={"Você não precisa de mais tempo.\nVocê precisa de menos ruído.\nComece hoje."}
                      style={{ width: "100%", resize: "vertical", padding: 8, borderRadius: 8, border: "1px solid var(--line)", background: "transparent", color: "var(--fg)", fontSize: ".85rem", boxSizing: "border-box" }} />
                  </div>
                )}
                {estrutura && <span className="txt" style={{ color: "var(--muted)", fontSize: ".76rem" }}>{estrutura[2]}</span>}
                {/* Motor da tela — o card já mandava `imageModel` ao servidor, mas não deixava
                    escolher: a decisão ficava presa no default da aba Imagem. Com imagem de
                    partida a escolha não se aplica (a tela já existe, não há o que gerar). */}
                <div style={{ display: "flex", gap: 14, flexWrap: "wrap", alignItems: "flex-end" }}>
                  <div style={{ display: "flex", flexDirection: "column", gap: 4 }}>
                    <label className="txt" style={{ color: "var(--muted)", fontSize: ".78rem" }}>Imagem de partida</label>
                    <div style={{ display: "flex", gap: 6, alignItems: "center" }}>
                      <button type="button" className="btn edit" style={{ flex: "none", padding: "8px 12px" }} onClick={() => setMotionEscolher(true)}
                        title="Usar uma imagem da galeria ou do computador em vez de gerar a tela">
                        {motionBase ? "Trocar imagem" : "🖼️ Escolher imagem"}
                      </button>
                      {motionBase && (
                        <button type="button" className="btn" style={{ flex: "none", padding: "8px 10px" }} onClick={() => setMotionBase("")}
                          title="Voltar a gerar a tela pelo texto">Limpar</button>
                      )}
                    </div>
                  </div>
                </div>
                {motionBase && (
                  <span className="txt" style={{ color: "var(--muted)", fontSize: ".76rem" }}>
                    A tela virá da sua imagem — o texto acima segue valendo para a ANIMAÇÃO, não para desenhar o quadro.
                  </span>
                )}
                {/* FICHA DA PEÇA — o que sai no fim, à vista antes de gastar. O motion não dizia o
                    tamanho nem quanto ia durar: o operador só descobria na galeria. */}
                <span className="txt" style={{ color: "var(--muted)", fontSize: ".76rem" }}>
                  Sai em <b>{motionAspect === "16:9" ? "1920×1080" : "1080×1920"}</b> ({motionAspect}), {motionSecs}s por clipe
                  {motionClipes.length > 0 && <> · {motionClipes.length} clipe{motionClipes.length > 1 ? "s" : ""} pronto{motionClipes.length > 1 ? "s" : ""} = <b>~{motionClipes.length * motionSecs}s</b> se juntar</>}
                  . Cada tela aprovada vira um clipe; junte-os no fim para a peça completa.
                </span>
                {motionClipes.length > 0 && (
                  <div style={{ display: "flex", gap: 8, alignItems: "center", flexWrap: "wrap", padding: "8px 10px", border: "1px solid var(--line)", borderRadius: 8 }}>
                    <span className="txt" style={{ fontSize: ".8rem" }}>🎬 Sequência: {motionClipes.length} clipe{motionClipes.length > 1 ? "s" : ""}</span>
                    <button type="button" className="btn ok" style={{ flex: "none", padding: "7px 12px" }}
                      disabled={anyBusy || motionClipes.length < 2} onClick={juntarMotion}
                      title={motionClipes.length < 2 ? "Gere pelo menos 2 clipes para juntar" : "Monta os clipes na ordem num vídeo só. Não gasta crédito de IA — os clipes já foram pagos."}>
                      {busy === "motion-join" ? "Juntando…" : "🎬 Juntar num vídeo"}
                    </button>
                    <button type="button" className="btn" style={{ flex: "none", padding: "7px 10px" }} onClick={() => setMotionClipes([])}
                      title="Esvazia a fila. Os clipes continuam na galeria.">Limpar fila</button>
                  </div>
                )}
                <EscolherImagem aberto={motionEscolher} onFechar={() => setMotionEscolher(false)}
                  titulo="Imagem de partida do motion" onEscolher={(u) => { setMotionBase(u); setMotionEscolher(false); }} />
                {!motionTela ? (
                  <div style={{ display: "flex", gap: 8, alignItems: "center", flexWrap: "wrap" }}>
                    <button className="btn ok" style={{ flex: "none", padding: "9px 14px" }} disabled={anyBusy} onClick={gerarTelaMotion}>
                      {busy === "motion-tela" ? "Gerando a tela…" : motionBase ? "🎞️ Usar esta tela" : "🎞️ Gerar a tela"}
                    </button>
                    <span className="txt" style={{ color: "var(--muted)", fontSize: ".76rem" }}>Custa uma imagem. O vídeo só é cobrado depois que você aprovar.</span>
                  </div>
                ) : (
                  /* GATE DE APROVAÇÃO — o único ponto em que o fluxo para, e é de propósito: a tela
                     custa pouco, o vídeo custa caro. Refazer não cobra vídeo nenhum. */
                  <div style={{ display: "flex", gap: 12, flexWrap: "wrap", alignItems: "flex-start", border: "1px dashed rgba(245,158,11,.5)", borderRadius: 10, padding: 10 }}>
                    <img src={motionTela} alt="Tela estática gerada" style={{ width: 120, borderRadius: 8, display: "block" }} />
                    <div style={{ display: "flex", flexDirection: "column", gap: 8, flex: 1, minWidth: 220 }}>
                      <span className="txt" style={{ fontSize: ".82rem" }}>Esta é a tela que vai ser animada. Confira os elementos — o clipe só os revela, move e remove; não redesenha nada.</span>
                      <div style={{ display: "flex", gap: 8, flexWrap: "wrap" }}>
                        <button className="btn ok" style={{ flex: "none", padding: "9px 14px" }} disabled={anyBusy} onClick={animarTelaMotion}>
                          {busy === "motion-clip" ? "Animando…" : "✅ Aprovar e animar"}
                        </button>
                        <button className="btn edit" style={{ flex: "none", padding: "9px 14px" }} disabled={anyBusy} onClick={() => { setMotionTela(null); setMsg(null); }} title="Descarta esta tela e volta aos campos. Não cobra vídeo.">↺ Refazer</button>
                      </div>
                    </div>
                  </div>
                )}
              </div>
            );
          })()}

          {/* ✂️ Shorts Clipper: vídeo longo → vários shorts */}
          <div style={{ ...card, borderColor: "rgba(124,58,237,.4)" }}>
            <strong>✂️ Clipar vídeo longo → shorts</strong>
            <p className="txt" style={{ color: "var(--muted)", margin: "4px 0 10px" }}>Cole a URL de um vídeo (MP4) ou suba o arquivo em &quot;+ Arquivar vídeo&quot;, na barra da galeria abaixo. A IA transcreve, acha os melhores trechos e gera shorts 9:16 legendados. (Pro)</p>
            <div style={{ display: "flex", gap: 8, alignItems: "center", flexWrap: "wrap" }}>
              <input style={{ ...inp, flex: 1, minWidth: 240 }} placeholder="https://.../video-longo.mp4" value={clipUrl} onChange={(e) => setClipUrl(e.target.value)} />
              <select value={clipN} onChange={(e) => setClipN(Number(e.target.value))} style={{ background: "var(--bg2)", color: "var(--text)", border: "1px solid var(--line)", borderRadius: 8, padding: "9px 12px", fontSize: ".88rem" }}>
                {[1, 2, 3, 4, 5].map((k) => <option key={k} value={k}>{k} short{k > 1 ? "s" : ""}</option>)}
              </select>
              <button className="btn ok" style={{ flex: "none", padding: "9px 16px" }} disabled={anyBusy} onClick={clipar}>{busy === "clip" ? "Cortando..." : "✂️ Gerar shorts"}</button>
            </div>
          </div>

          {/* Filtro da galeria por rede (só quando há redes com texto e mídia gerada) */}
          {media.length > 0 && pubPlatforms.length > 0 && (
            <div style={{ display: "flex", gap: 6, flexWrap: "wrap", alignItems: "center" }}>
              <span className="txt" style={{ color: "var(--muted)", fontSize: ".78rem", marginRight: 2 }}>Filtrar galeria:</span>
              {["", ...pubPlatforms].map((p) => {
                const on = galleryFilter === p;
                const c = p ? (NET[p]?.color || "var(--red)") : "var(--red)";
                return (
                  <button key={p || "all"} type="button" onClick={() => setGalleryFilter(p)} style={{ padding: "4px 11px", borderRadius: 16, fontSize: ".76rem", cursor: "pointer", textTransform: "capitalize",
                    border: "1px solid " + (on ? c : "var(--line)"), background: on ? c + "22" : "var(--bg2)", color: on ? "var(--peach)" : "var(--muted)" }}>
                    {p ? (NET[p]?.name || p) : "Todas"}
                  </button>
                );
              })}
            </div>
          )}
          {/* 🗂️ Barra da galeria desta sessão — arquivar um arquivo pronto, recarregar e limpar.
              Mora AQUI (colada na grade que ela manipula) e não no card de geração de Imagem. */}
          <div style={{ display: "flex", gap: 8, flexWrap: "wrap", alignItems: "center" }}>
            <span className="txt" style={{ color: "var(--muted)", fontSize: ".78rem", marginRight: 2 }}>Mídias desta sessão:</span>
            <label className="btn edit" style={{ flex: "none", padding: "7px 12px", cursor: "pointer" }} title="Envia um arquivo pronto do computador direto pra esta galeria — não gera nada.">+ Arquivar imagem<input type="file" accept="image/*" style={{ display: "none" }} onChange={(e) => e.target.files?.[0] && upload("image", e.target.files[0])} /></label>
            <label className="btn edit" style={{ flex: "none", padding: "7px 12px", cursor: "pointer" }} title="Envia um vídeo pronto do computador direto pra esta galeria — não gera nada.">+ Arquivar vídeo<input type="file" accept="video/*" style={{ display: "none" }} onChange={(e) => e.target.files?.[0] && upload("video", e.target.files[0])} /></label>
            <button className="btn edit" style={{ flex: "none", padding: "7px 12px" }} onClick={refreshMedia}>↻ Atualizar</button>
            {media.length > 0 && <button className="btn no" style={{ flex: "none", padding: "7px 12px" }} onClick={limparMidia} title="Limpar as mídias desta sessão da galeria">🧹 Limpar</button>}
          </div>
          <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fill,minmax(min(180px, 100%), 1fr))", gap: 14 }}>
            {media.length === 0 && <span className="txt">Nenhuma mídia. Gere com IA acima, ou use &quot;+ Arquivar&quot; pra subir um arquivo pronto.</span>}
            {media.filter((m) => { if (!galleryFilter) return true; const ns = mediaNetsOf(m); return ns.length === 0 || ns.includes(galleryFilter); }).map((m) => (
              <div key={m.id} style={{ ...card, padding: 8, position: "relative" }}>
                <button onClick={() => excluirMidia(m.id)} title="Excluir" style={{ position: "absolute", top: 6, right: 6, width: 26, height: 26, borderRadius: "50%", border: 0, background: "rgba(0,0,0,.6)", color: "#fff", cursor: "pointer", fontWeight: 700, zIndex: 5 }}>✕</button>
                {m.kind === "image" ? <img src={m.url} alt="" style={{ width: "100%", borderRadius: 8, display: "block" }} /> : m.kind === "audio" ? <audio src={m.url} controls style={{ width: "100%", marginTop: 26 }} /> : <video src={m.url} controls preload="metadata" style={{ width: "100%", borderRadius: 8 }} />}
                {m.style && <div style={{ textAlign: "center", fontSize: ".72rem", color: "var(--muted)", marginTop: 5 }}>{STYLE_LABELS[m.style] || imageStyleLabel(m.style)}</div>}
                {/* Badge de destino: redes a que esta mídia se destina (ou "todas") */}
                <div style={{ display: "flex", flexWrap: "wrap", gap: 3, justifyContent: "center", marginTop: 5 }}>
                  {mediaNetsOf(m).length === 0
                    ? <span style={{ fontSize: ".64rem", color: "var(--muted)", border: "1px solid var(--line)", borderRadius: 10, padding: "0 7px", lineHeight: "16px" }}>🌐 todas as redes</span>
                    : mediaNetsOf(m).map((p) => { const c = NET[p]?.color || "var(--red)"; return <span key={p} style={{ fontSize: ".64rem", color: c, border: "1px solid " + c, borderRadius: 10, padding: "0 7px", lineHeight: "16px", textTransform: "capitalize" }}>{NET[p]?.name || p}</span>; })}
                </div>
                {m.kind === "image" && (m.style !== "gif") && <div style={{ display: "flex", gap: 4, marginTop: 6 }}>
                  <button className="btn edit" style={{ flex: 1, padding: "5px 0", fontSize: ".72rem" }} disabled={anyBusy} onClick={() => enhanceMidia(m, "upscale")} title="Aumenta a nitidez/resolução">{enhancing === `upscale:${m.id}` ? "…" : `✨ Melhorar${editCosts.upscale != null ? ` ${editCosts.upscale}` : ""}`}</button>
                  <button className="btn edit" style={{ flex: 1, padding: "5px 0", fontSize: ".72rem" }} disabled={anyBusy} onClick={() => enhanceMidia(m, "remove_bg")} title="Remove o fundo (PNG transparente)">{enhancing === `remove_bg:${m.id}` ? "…" : `✂️ Fundo${editCosts.remove_bg != null ? ` ${editCosts.remove_bg}` : ""}`}</button>
                </div>}
                {/* ⚡ EasyApps 1-clique (aba Rápido): reiluminar / trocar o fundo do produto (i2i, preserva o sujeito). */}
                {m.kind === "image" && (m.style !== "gif") && <div style={{ display: "flex", gap: 4, marginTop: 4 }}>
                  {/* Um seletor no lugar de 2 botões fixos: a grade oferecia só relight e
                      product_bg, sem parâmetro, enquanto o Rápido tinha as 5 com parâmetro.
                      Agora as duas telas leem o MESMO catálogo (lib/easyapps). */}
                  <select value="" disabled={anyBusy} onChange={(e) => { if (e.target.value) setAjuste({ id: m.id, kind: e.target.value, valor: "" }); }} style={{ flex: 1, padding: "5px 6px", fontSize: ".72rem", background: "var(--bg2)", color: "var(--text)", border: "1px solid var(--line)", borderRadius: 8 }} title="Ajustes de 1 clique nesta imagem (reiluminar, trocar fundo, trocar roupa, detalhar rosto)">
                    <option value="">{enhancing.endsWith(`:${m.id}`) ? "Processando…" : "🎛️ Ajustar…"}</option>
                    {EASYAPPS.map((e) => <option key={e.kind} value={e.kind}>{e.label}</option>)}
                  </select>
                </div>}
                {ajuste?.id === m.id && (() => {
                  const cfg = easyAppDe(ajuste.kind);
                  const faltaChar = Boolean(cfg?.precisaPersonagem) && !ajusteChar;
                  return (
                    <div style={{ display: "flex", flexDirection: "column", gap: 5, marginTop: 5, padding: 7, borderRadius: 8, border: "1px dashed var(--line)" }}>
                      <span className="txt" style={{ fontSize: ".72rem", color: "var(--peach)" }}>{cfg?.label}</span>
                      {cfg?.precisaPersonagem && (
                        <select value={ajusteChar} onChange={(e) => setAjusteChar(e.target.value)} style={{ padding: "4px 6px", fontSize: ".72rem", background: "var(--bg2)", color: "var(--text)", border: "1px solid var(--line)", borderRadius: 6 }}>
                          <option value="">Escolha o personagem…</option>
                          {personagens.map((c) => <option key={c.id} value={String(c.id)}>{c.name}</option>)}
                        </select>
                      )}
                      {cfg?.paramKey && (
                        <input value={ajuste.valor} onChange={(e) => setAjuste({ ...ajuste, valor: e.target.value })} placeholder={cfg.paramLabel} style={{ padding: "4px 6px", fontSize: ".72rem", background: "var(--bg2)", color: "var(--text)", border: "1px solid var(--line)", borderRadius: 6 }} />
                      )}
                      <div style={{ display: "flex", gap: 4 }}>
                        <button className="btn ok" style={{ flex: 1, padding: "4px 0", fontSize: ".72rem" }} disabled={anyBusy || faltaChar} title={faltaChar ? "Trocar roupa exige um personagem da sua biblioteca" : undefined}
                          onClick={() => { const a = ajuste; setAjuste(null); easyappMidia(m, a.kind, a.valor); }}>Aplicar</button>
                        <button className="btn edit" style={{ flex: "none", padding: "4px 9px", fontSize: ".72rem" }} onClick={() => setAjuste(null)}>✕</button>
                      </div>
                    </div>
                  );
                })()}
                {m.kind === "image" && m.style !== "gif" && !m.composed && <button className="btn edit" style={{ width: "100%", marginTop: 4, padding: "5px 0", fontSize: ".78rem" }} onClick={() => abrirCompor(m)} title="Compõe título, logo e CTA sobre a imagem, no formato da rede">🎨 Vestir com a marca</button>}
                {/* 🎨 F2 — filtro Instagram na FOTO (2 créd): gera uma NOVA imagem filtrada na galeria. */}
                {/* Mesmo catálogo do 🎨 Cor do card, de propósito: é a MESMA cor. A diferença é
                    QUANDO — lá entra junto da geração (incluso); aqui é uma operação nova sobre
                    uma imagem pronta, gera outro arquivo e por isso cobra. O rótulo diz isso,
                    senão parecem dois recursos diferentes com preços inexplicavelmente distintos. */}
                {m.kind === "image" && m.style !== "gif" && (
                  <select value="" disabled={anyBusy} onChange={(e) => { if (e.target.value) filtrarMidia(m, e.target.value); }} style={{ width: "100%", marginTop: 4, padding: "5px 6px", fontSize: ".74rem", background: "var(--bg2)", color: "var(--text)", border: "1px solid var(--line)", borderRadius: 8 }} title="Troca a cor DESTA imagem já pronta e salva como uma nova (a original fica). Na geração, a mesma cor sai inclusa pelo seletor 🎨 Cor.">
                    <option value="">{enhancing === `filter:${m.id}` ? "Aplicando cor…" : "🎨 Trocar a cor… · 2 créd"}</option>
                    {GRADE_OPTIONS.filter(([v]) => v !== "natural").map(([v, label]) => <option key={v} value={v}>{label}</option>)}
                  </select>
                )}
                {m.kind === "video" && <button className="btn edit" style={{ width: "100%", marginTop: 6, padding: "5px 0", fontSize: ".78rem" }} disabled={anyBusy} onClick={() => gerarThumb(m.url)}>{busy === "thumb" ? "..." : "🖼️ Gerar thumbnail"}</button>}
                {m.kind === "video" && <div style={{ display: "flex", gap: 4, marginTop: 4 }}>
                  <button className="btn edit" style={{ flex: 1, padding: "5px 0", fontSize: ".74rem" }} disabled={anyBusy} onClick={() => dublar(m.url, "en")} title="Dublar para inglês">🌎 EN</button>
                  <button className="btn edit" style={{ flex: 1, padding: "5px 0", fontSize: ".74rem" }} disabled={anyBusy} onClick={() => dublar(m.url, "es")} title="Dublar para espanhol">🌎 ES</button>
                </div>}
                {(m.kind === "image" || m.kind === "video") && m.style !== "gif" && <button className="btn edit" style={{ width: "100%", marginTop: 4, padding: "5px 0", fontSize: ".72rem" }} disabled={anyBusy} onClick={() => salvarNosAssets(m)} title="Guardar no Hub Meus Assets pra reusar depois">⭐ Salvar nos Assets</button>}
              </div>
            ))}
          </div>
          <button className="btn ok" style={{ maxWidth: 150 }} onClick={() => go("/aprovar")}>Ir pra Aprovar →</button>

          {pickerOpen && <GalleryPicker onPick={escolherDaGaleria} onClose={() => setPickerOpen(false)} />}
          {promptTarget && <PromptPicker onPick={aplicarPrompt} onClose={() => setPromptTarget("")} />}
          {composeSrc && (
            <div onClick={() => !composing && setComposeSrc(null)} style={{ position: "fixed", inset: 0, background: "rgba(0,0,0,.6)", display: "flex", alignItems: "center", justifyContent: "center", zIndex: 50, padding: 16 }}>
              <div onClick={(e) => e.stopPropagation()} style={{ ...card, width: "100%", maxWidth: 520, display: "flex", flexDirection: "column", gap: 10 }}>
                <div style={{ display: "flex", gap: 12 }}>
                  <img src={composeSrc.url} alt="" style={{ width: 96, height: 96, objectFit: "cover", borderRadius: 8 }} />
                  <div style={{ flex: 1 }}>
                    <strong>🎨 Vestir com a marca</strong>
                    <p className="txt" style={{ margin: "4px 0 0", fontSize: ".8rem" }}>Compõe título, logo e CTA sobre esta imagem, no formato da rede — com a identidade da sua marca.</p>
                  </div>
                </div>
                <select value={cForm.format} onChange={(e) => setCForm({ ...cForm, format: e.target.value })} style={inp}>
                  <option value="feed">Feed 1:1 · Instagram/Facebook</option>
                  <option value="retrato">Retrato 4:5 · Instagram/Pinterest</option>
                  <option value="story">Story 9:16 · Reels/TikTok</option>
                  <option value="paisagem">Paisagem 16:9 · YouTube/LinkedIn/X</option>
                </select>
                <label className="txt" style={{ display: "flex", alignItems: "center", gap: 8, fontSize: ".84rem" }}>
                  <input type="checkbox" checked={cCarousel} onChange={(e) => setCCarousel(e.target.checked)} /> Carrossel — uma linha do título = um slide (pager 1/N, CTA no último)
                </label>
                <input placeholder="Etiqueta (ex: Oferta da semana)" value={cForm.kicker} onChange={(e) => setCForm({ ...cForm, kicker: e.target.value })} style={inp} maxLength={40} />
                <textarea placeholder={cCarousel ? "Um título por linha — cada linha vira um slide do carrossel" : "Título do post *"} value={cForm.titulo} onChange={(e) => setCForm({ ...cForm, titulo: e.target.value })} style={{ ...inp, minHeight: cCarousel ? 110 : 60, resize: "vertical" }} maxLength={cCarousel ? 1200 : 200} />
                <input placeholder="Subtítulo (opcional)" value={cForm.subtitulo} onChange={(e) => setCForm({ ...cForm, subtitulo: e.target.value })} style={inp} maxLength={300} />
                <input placeholder="Botão / CTA (ex: Peça agora)" value={cForm.cta} onChange={(e) => setCForm({ ...cForm, cta: e.target.value })} style={inp} maxLength={40} />
                <div style={{ display: "flex", gap: 8, justifyContent: "flex-end", marginTop: 4 }}>
                  <button className="btn" onClick={() => setComposeSrc(null)} disabled={composing}>Cancelar</button>
                  <button className="btn ok" onClick={vestirMarca} disabled={anyBusy || !cForm.titulo.trim()}>{composing ? "Compondo…" : "Compor post"}</button>
                </div>
              </div>
            </div>
          )}
        </div>
      )}

      {/* APROVAR (revisão + ajustes finais) — editor à esquerda, pré-visualização por rede à direita */}
      {step === "approve" && draftId && (
        <div style={{ display: "flex", flexDirection: "column", gap: 14, marginTop: 14 }}>
          <p className="sub">Revise e faça os ajustes finais antes de publicar. Veja ao lado como fica em cada rede. Selecione um trecho e use <strong>B</strong> pra deixar em negrito (ou <em>I</em> pra itálico).</p>
          {pubPlatforms.length === 0 ? (
            /* Sem legenda não há abas de rede — mas o rascunho pode ter mídia. Mostrar o vídeo/imagem
               AQUI é o que separa "falta o texto" de "carregou o rascunho errado / quebrou": sem isso
               a página vinha 100% vazia e parecia bug (era o caminho do Estúdio de Animação → Aprovar). */
            <div className="empty" style={{ ...card, color: "var(--muted)", textAlign: "center", lineHeight: 1.6 }}>
              Nenhum texto pra revisar ainda — um post precisa de <strong>legenda por rede</strong>.<br />Gere/escreva em <a href="/editar" style={{ color: "var(--peach)" }}>Texto e legendas</a>.
              {(() => {
                const m = media.filter((x) => !x.scene)[0];
                if (!m) return null;
                return (
                  <div style={{ marginTop: 14 }}>
                    <p style={{ fontSize: ".8rem", marginBottom: 8 }}>A mídia deste rascunho já está aqui — falta só a legenda:</p>
                    {m.kind === "video"
                      ? <video controls src={m.url} style={{ width: "100%", maxWidth: 320, maxHeight: "46vh", objectFit: "contain", background: "#000", borderRadius: 12, display: "block", margin: "0 auto" }} />
                      /* eslint-disable-next-line @next/next/no-img-element */
                      : <img src={m.url} alt="" style={{ width: "100%", maxWidth: 320, borderRadius: 12, display: "block", margin: "0 auto" }} />}
                  </div>
                );
              })()}
            </div>
          ) : (() => {
            const cur = previewNet && pubPlatforms.includes(previewNet) ? previewNet : pubPlatforms[0];
            // #4: mídia destinada à rede ATIVA (platforms vazio/ausente = serve todas).
            // Exclui as CENAS intermediárias da história (campo `scene`) — só o vídeo final entra.
            const curMedia = media.filter((m) => { if (m.scene) return false; const ns = mediaNetsOf(m); return ns.length === 0 || ns.includes(cur); });
            return (
              <div style={{ display: "flex", flexDirection: "column", gap: 14 }}>
                {/* abas de rede + editor da ativa (as próprias abas já são a pré-visualização por rede) */}
                <div style={{ display: "flex", flexDirection: "column", gap: 10 }}>
                  <div style={{ display: "flex", gap: 6, flexWrap: "wrap" }}>
                    {pubPlatforms.map((p) => {
                      const active = p === cur;
                      const hasText = !!(texts[p] && texts[p].trim());
                      return (
                        <button key={p} onClick={() => setPreviewNet(p)} title={"Editar " + p}
                          style={{ padding: "5px 12px", borderRadius: 16, fontSize: ".8rem", cursor: "pointer", textTransform: "capitalize", fontWeight: active ? 700 : 400,
                            border: "1px solid " + (NET[p]?.color || "var(--line)"),
                            background: active ? (NET[p]?.color || "var(--line)") : (NET[p]?.color || "var(--line)") + "22",
                            color: active ? "#fff" : "var(--text)" }}>
                          {hasText ? "● " : ""}{p}
                        </button>
                      );
                    })}
                  </div>
                  <div style={card}>
                    <div style={{ display: "flex", alignItems: "center", gap: 8, marginBottom: 8, flexWrap: "wrap" }}>
                      <strong style={{ textTransform: "capitalize" }}>{cur}</strong>
                      {/* #4: badge de idioma da rede (da #3) */}
                      <span style={{ fontSize: ".66rem", color: "var(--muted)", border: "1px solid var(--line)", borderRadius: 8, padding: "0 7px", lineHeight: "16px" }}>{langOf(cur) === "en-US" ? "EN" : "PT"}</span>
                      <div style={{ marginLeft: "auto", display: "flex", gap: 8, alignItems: "center", flexWrap: "wrap" }}>
                        {roteiristaSelect}
                        <button className="btn edit" style={{ flex: "none", padding: "6px 12px", fontSize: ".8rem" }} disabled={anyBusy} onClick={() => gerarTexto(cur)}
                          title="Regera SÓ o texto desta rede com o roteirista escolhido — as outras redes ficam como estão">
                          {busy === "t-" + cur ? "Regerando…" : "↻ Regerar esta rede"}
                        </button>
                      </div>
                    </div>
                    <RichTextArea value={texts[cur] || ""} onChange={(v) => salvarTexto(cur, v)} placeholder="Ajuste o texto aqui..." />
                  </div>
                </div>
                {/* #4: mídia DESTA rede (não toda a galeria) — é o que vai ao ar nesta rede */}
                {media.length > 0 && (
                  <div style={card}>
                    <strong style={{ fontSize: ".9rem", textTransform: "capitalize" }}>Mídia de {NET[cur]?.name || cur} ({curMedia.length})</strong>
                    {curMedia.length === 0 ? (
                      <p className="txt" style={{ color: "var(--muted)", fontSize: ".82rem", margin: "8px 0 0" }}>Nenhuma mídia destinada a esta rede. Defina o destino na aba <a href="/midia" style={{ color: "var(--peach)" }}>Mídia</a> (sem destino = serve todas).</p>
                    ) : (
                      <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fill,minmax(min(90px, 100%), 1fr))", gap: 6, marginTop: 8 }}>
                        {curMedia.map((m) => (
                          <div key={m.id} style={{ position: "relative" }}>
                            {m.kind === "image"
                              ? <img src={m.url} alt="" style={{ width: "100%", borderRadius: 6, display: "block" }} />
                              : <video src={m.url} style={{ width: "100%", borderRadius: 6 }} />}
                            {mediaNetsOf(m).length === 0 && <span style={{ position: "absolute", top: 3, left: 3, fontSize: ".58rem", color: "#fff", background: "rgba(0,0,0,.6)", borderRadius: 6, padding: "0 5px", lineHeight: "14px" }}>🌐 todas</span>}
                          </div>
                        ))}
                      </div>
                    )}
                  </div>
                )}
              </div>
            );
          })()}
          <div style={{ display: "flex", gap: 10, flexWrap: "wrap" }}>
            <button className="btn ok" style={{ flex: "none", padding: "10px 18px" }} onClick={() => go("/publicar")}>Aprovar e ir pra Publicar →</button>
            <button className="btn edit" style={{ flex: "none", padding: "10px 18px" }} onClick={() => go("/editar")}>✏️ Editar no editor completo</button>
            <button className="btn no" style={{ flex: "none", padding: "10px 18px" }} onClick={async () => {
              if (!(await perguntar({ titulo: "Descartar este rascunho?", mensagem: "Ele não será publicado.", confirmar: "Descartar", perigo: true }))) return;
              setDraftId(null); setResearch(null); setTexts({}); setMedia([]);
              localStorage.removeItem(KEY);
              setMsg("Rascunho rejeitado e descartado.");
              go("/");
            }}>✕ Rejeitar / descartar</button>
          </div>
        </div>
      )}

      {/* PUBLICAR */}
      {step === "publish" && draftId && (
        <div style={{ display: "flex", flexDirection: "column", gap: 14, marginTop: 14 }}>
          <div style={card}>
            <p><strong>Tema:</strong> {keyword}</p>
            <p><strong>Plataformas:</strong> {selectedPubs.join(", ") || "—"}{selectedPubs.length < pubPlatforms.length ? ` (${selectedPubs.length} de ${pubPlatforms.length} selecionadas)` : ""}</p>
            <p><strong>Mídia:</strong> {media.filter((m) => m.kind === "image").length} imagem(ns) · {media.filter((m) => m.kind === "video").length} vídeo(s)</p>
            <p style={{ color: "var(--muted)", fontSize: ".85rem" }}>
              {media.some((m) => m.kind === "video")
                ? "→ Será anexado o vídeo mais recente nas redes."
                : media.some((m) => m.kind === "image")
                ? `→ Serão anexadas até 4 imagens nas redes; a 1ª vira capa no blog.`
                : "→ Sem mídia: publica só o texto."}
            </p>
          </div>

          {/* Pré-visualização do que vai ao ar (com atalho pra editar) */}
          {pubPlatforms.length > 0 && (
            <div style={card}>
              <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: 6, gap: 8 }}>
                <strong style={{ fontSize: ".95rem" }}>👁 Como vai ficar</strong>
                <button className="btn edit" style={{ flex: "none", padding: "6px 12px" }} onClick={() => go("/editar")}>✏️ Editar conteúdo</button>
              </div>
              <NetworkPreviewTabs
                platforms={pubPlatforms}
                active={previewNet || pubPlatforms[0]}
                onActive={setPreviewNet}
                texts={texts}
                medias={media.filter((m) => { if (m.scene) return false; const ns = mediaNetsOf(m); const net = previewNet || pubPlatforms[0]; return ns.length === 0 || ns.includes(net); }).map((m) => ({ url: m.url, kind: m.kind }))}
              />
            </div>
          )}

          {/* Perfis-alvo (multi-perfil): marque 1+ perfis; publica em todos os marcados de uma vez. */}
          {conns.profiles.length > 0 && (
            <div style={card}>
              <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: 8, gap: 8, flexWrap: "wrap" }}>
                <strong>👥 Perfis — onde publicar</strong>
                <a className="btn edit" style={{ flex: "none", padding: "6px 12px", textDecoration: "none" }} href="/conexoes">Gerenciar perfis</a>
              </div>
              {conns.profiles.map((prof) => {
                const on = selectedProfiles.includes(prof.id);
                const nets = [...new Set(prof.accounts.map((a) => a.platform))];
                return (
                  <label key={prof.id} style={{ display: "flex", justifyContent: "space-between", alignItems: "center", gap: 8, padding: "8px 0", borderTop: "1px solid var(--line)", cursor: "pointer", opacity: on ? 1 : 0.55 }}>
                    <span style={{ display: "flex", alignItems: "center", gap: 8 }}>
                      <input type="checkbox" checked={on} onChange={() => toggleProfile(prof.id)} style={{ width: 16, height: 16, cursor: "pointer" }} />
                      {prof.name}{prof.is_default ? " (padrão)" : ""}
                    </span>
                    <span className="txt" style={{ color: "var(--muted)", fontSize: ".8rem" }}>{prof.accounts.length} conta(s){nets.length ? ": " + nets.join(", ") : ""}</span>
                  </label>
                );
              })}
              <p className="txt" style={{ color: "var(--muted)", fontSize: ".78rem", margin: "6px 0 0" }}>Marque 1 ou mais perfis. Cada rede selecionada abaixo é postada em todos os perfis marcados que a tenham conectada.</p>
            </div>
          )}

          {/* #5: seleção de destino + conexões — marque as redes a publicar (checkbox) */}
          <div style={card}>
            <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: 8, gap: 8, flexWrap: "wrap" }}>
              <strong>🔗 Publicar em — selecione as redes</strong>
              <div style={{ display: "flex", gap: 8 }}>
                {pubPlatforms.length > 1 && (
                  <button className="btn edit" style={{ flex: "none", padding: "6px 11px", fontSize: ".78rem" }} onClick={() => setPubSel(selectedPubs.length === pubPlatforms.length ? [] : null)}>{selectedPubs.length === pubPlatforms.length ? "Limpar seleção" : "Selecionar todas"}</button>
                )}
                <a className="btn edit" style={{ flex: "none", padding: "6px 12px", textDecoration: "none" }} href="/conexoes">Gerenciar</a>
              </div>
            </div>
            {pubPlatforms.length === 0 ? (
              <div style={{ display: "flex", flexDirection: "column", gap: 10 }}>
                <p className="txt" style={{ color: "var(--peach)", margin: 0, fontSize: ".9rem", lineHeight: 1.5 }}>
                  ⚠️ Nenhuma rede tem <strong>legenda</strong> ainda — e um post precisa de <strong>mídia + texto</strong>. Sua mídia está pronta; falta o texto por rede pra poder publicar.
                </p>
                <div style={{ display: "flex", gap: 8, flexWrap: "wrap" }}>
                  <a className="btn ok" style={{ flex: "none", padding: "8px 15px", textDecoration: "none" }} href="/editar">✍️ Escrever / gerar as legendas</a>
                </div>
                <p className="txt" style={{ color: "var(--muted)", fontSize: ".78rem", margin: 0 }}>
                  Numa <strong>história</strong>, o texto se gera no rodapé <strong>“🎬 Juntar tudo → ✍️ Gerar textos do post”</strong>. Em qualquer rascunho, dá pra escrever/gerar em <strong>Conteúdo / Legendas</strong>.
                </p>
              </div>
            ) : pubPlatforms.map((p) => {
              const ok = isConnected(p); const meta = netMeta(p); const on = selectedPubs.includes(p);
              return (
                <label key={p} style={{ display: "flex", justifyContent: "space-between", alignItems: "center", gap: 8, padding: "8px 0", borderTop: "1px solid var(--line)", cursor: "pointer", opacity: on ? 1 : 0.55 }}>
                  <span style={{ display: "flex", alignItems: "center", gap: 8, textTransform: "capitalize" }}>
                    <input type="checkbox" checked={on} onChange={() => togglePub(p)} style={{ accentColor: NET[p]?.color || "var(--red)", width: 16, height: 16, cursor: "pointer" }} />
                    {meta?.icon ? meta.icon + " " : ""}{meta?.label || p}
                  </span>
                  {ok
                    ? <span style={{ fontSize: ".78rem", color: "var(--green,#3fb950)" }}>✅ conectada</span>
                    : <a className="btn ok" style={{ flex: "none", padding: "4px 12px", fontSize: ".76rem", textDecoration: "none" }} href={p === "blog" ? "/conexoes" : `${CONSOLE}/connect/${p}`} onClick={(e) => e.stopPropagation()}>Conectar →</a>}
                </label>
              );
            })}
          </div>

          {selectedPubs.includes("reddit") && (
            <div style={{ display: "flex", flexDirection: "column", gap: 5, margin: "2px 0" }}>
              <label className="txt" style={{ color: "var(--muted)", fontSize: ".8rem" }}>👽 Onde publicar no Reddit</label>
              {/* O alvo é OBRIGATÓRIO e tem dois sabores: comunidade (r/) ou o próprio perfil (u/).
                  O estado guarda o prefixo junto, porque é ele que o servidor traduz na hora de
                  publicar — e é o que o repost precisa repetir. */}
              <div style={{ display: "flex", alignItems: "center", gap: 4, maxWidth: 340 }}>
                <select
                  value={redditAlvo}
                  onChange={(e) => setRedditAlvo(e.target.value as "r" | "u")}
                  style={{ ...inp, flex: "none", width: 64, padding: "8px 6px" }}
                  title="r/ = comunidade · u/ = seu perfil"
                >
                  <option value="r">r/</option>
                  <option value="u">u/</option>
                </select>
                <input value={redditSubreddit} onChange={(e) => setRedditSubreddit(e.target.value.replace(/^\/?([ru]\/)?/i, ""))} placeholder="RedFoxCode" style={{ ...inp, padding: "8px 10px" }} />
              </div>
              <span className="txt" style={{ color: "var(--muted)", fontSize: ".72rem" }}>
                {redditAlvo === "u"
                  ? "Publica no SEU perfil do Reddit (u/) — sem regras de moderação de comunidade."
                  : "Publica na comunidade (r/). Cada comunidade tem regras próprias e pode remover o post."}
              </span>

              <label className="txt" style={{ color: "var(--muted)", fontSize: ".8rem", marginTop: 6 }}>Formato do post</label>
              <div style={{ display: "flex", gap: 10, flexWrap: "wrap" }}>
                {([["imagem", "🖼️ Imagem + texto no 1º comentário"], ["texto", "📝 Tudo no post (sem imagem)"]] as const).map(([v, rotulo]) => (
                  <label key={v} className="txt" style={{ display: "flex", alignItems: "center", gap: 5, fontSize: ".82rem", cursor: "pointer" }}>
                    <input type="radio" name="reddit-formato" checked={redditFormato === v} onChange={() => setRedditFormato(v)} />
                    {rotulo}
                  </label>
                ))}
              </div>
              <span className="txt" style={{ color: "var(--muted)", fontSize: ".72rem" }}>
                {redditFormato === "imagem"
                  ? "A imagem aparece no feed com o título, e o texto completo entra logo abaixo como 1º comentário — é assim que se faz no Reddit, porque post de imagem não tem corpo."
                  : "O texto inteiro é publicado no corpo do post, mas a imagem entra só como LINK: o Reddit não embute imagem em post de texto."}
              </span>

              {/* O título é permanente no Reddit (não dá pra editar depois de publicar), então ele
                  aparece aqui ANTES de ir — e não montado por dedução em cima do texto da peça. */}
              <label className="txt" style={{ color: "var(--muted)", fontSize: ".8rem", marginTop: 6 }}>
                Título do post {redditFormato === "imagem" && <b>(é o texto que vai junto com a imagem)</b>}
              </label>
              <textarea
                value={redditTitulo}
                onChange={(e) => setRedditTitulo(e.target.value.slice(0, 300))}
                rows={2}
                placeholder={(texts.reddit || "").split("\n").find((l) => l.trim()) || "Em branco = a 1ª linha do texto do Reddit"}
                style={{ ...inp, resize: "vertical" }}
              />
              <span className="txt" style={{ color: redditTitulo.length > 285 ? "#ff9b8a" : "var(--muted)", fontSize: ".72rem" }}>
                {redditTitulo.length}/300 · em branco = a 1ª linha do texto do Reddit. O Reddit NÃO deixa editar o título depois de publicado.
              </span>
            </div>
          )}
          {pubPlatforms.length > 0 && !selectedPubs.some(isConnected) && (
            <p className="txt" style={{ color: "#ff9b8a", fontSize: ".85rem", margin: 0 }}>⚠ Nenhuma rede selecionada está conectada — marque ao menos uma rede conectada para publicar.</p>
          )}
          <button className="btn ok" style={{ maxWidth: 260 }} disabled={anyBusy || !selectedPubs.some(isConnected)} onClick={publicar}>{busy === "submit" ? "Publicando..." : `🚀 Publicar agora${selectedPubs.length > 0 ? ` (${selectedPubs.filter(isConnected).length})` : ""}`}</button>
        </div>
      )}

      {msg && <p className="txt" style={{ marginTop: 18, fontSize: "1rem" }}>{msg}</p>}
    </>
  );
}
