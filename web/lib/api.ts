// Client de API do Reachyn.
// Tudo passa pelo console Laravel (Sanctum bearer): a geração é um proxy autenticado
// console → engine, com enforcement de quota. O engine não é exposto ao browser.
//
// Domínio único (reachyn.agency): web e console são MESMA ORIGEM → chamadas relativas
// (sem CORS). Em dev, aponte NEXT_PUBLIC_CONSOLE_URL para o console (ex.: http://localhost:8000).
import type { MotorLugar } from "@/lib/motor";

const CONSOLE = process.env.NEXT_PUBLIC_CONSOLE_URL ?? "";

// AUD-021: o Bearer efêmero (SSO) é guardado SÓ em memória (variável de módulo), nunca em
// localStorage/sessionStorage. Em prod o fluxo é same-origin com cookie de sessão (Sanctum
// stateful), então o token nem é necessário entre reloads; mantê-lo fora de storage
// persistente impede que um XSS o leia/exfiltre. Após um reload o app cai no cookie de sessão
// (ou refaz o SSO exchange). Trade-off aceito: o Bearer não sobrevive a F5.
let memToken = "";

export function getToken(): string {
  return memToken;
}

export function setToken(value: string): void {
  memToken = value ?? "";
}

// Marca ATIVA (Fase 2): qual marca/Tenant o usuário está operando agora. NÃO é segredo — o
// servidor valida que a marca pertence à ORG do usuário (TenantScope::activeTenantId), então
// pode viver em localStorage (sobrevive a reload). Enviada no header X-Tenant-Id em toda request.
let activeTenant = "";
export function getActiveTenant(): string {
  if (activeTenant) return activeTenant;
  if (typeof window !== "undefined") activeTenant = window.localStorage.getItem("reachyn.activeTenant") ?? "";
  return activeTenant;
}
export function setActiveTenant(id: string | number | null): void {
  activeTenant = id != null ? String(id) : "";
  if (typeof window !== "undefined") {
    if (activeTenant) window.localStorage.setItem("reachyn.activeTenant", activeTenant);
    else window.localStorage.removeItem("reachyn.activeTenant");
  }
}

/**
 * SSO one-time code (AUD-011): se a URL trouxer ?code=, troca-o por um Bearer efêmero
 * via POST /api/studio/sso-exchange e o guarda. O code é de uso único (server-side) e é
 * removido da URL em seguida — o token nunca aparece na barra de endereços/Referer/logs.
 */
export async function consumeSsoCode(): Promise<void> {
  if (typeof window === "undefined") return;
  const params = new URLSearchParams(window.location.search);
  const code = params.get("code");
  if (!code) return;
  try {
    const r = await fetch(`${CONSOLE}/api/studio/sso-exchange`, {
      method: "POST",
      credentials: "include",
      headers: { "Content-Type": "application/json", Accept: "application/json" },
      body: JSON.stringify({ code }),
    });
    if (r.ok) {
      const d = (await r.json().catch(() => ({}))) as { token?: string };
      if (d.token) setToken(d.token);
    }
  } catch {
    /* silencioso: cai no fluxo de sessão same-origin */
  } finally {
    params.delete("code");
    const qs = params.toString();
    window.history.replaceState({}, "", window.location.pathname + (qs ? `?${qs}` : ""));
  }
}

// Avisa o indicador de saldo (CreditBalance) que algo pode ter debitado créditos → ele refaz fetch.
// Disparado após chamadas de GERAÇÃO (paths de /generate ou /studio/), onde o saldo muda.
function signalCreditsMaybe(path: string): void {
  if (typeof window === "undefined") return;
  if (path.includes("/generate") || path.includes("/studio/")) {
    window.dispatchEvent(new Event("reachyn:credits"));
  }
}

/** Erro com status HTTP — o Studio usa 402 para disparar upsell de plano. */
export class ApiError extends Error {
  constructor(message: string, readonly status: number) {
    super(message);
  }
}

/** URL do login do cliente (painel Filament). */
export function loginUrl(): string {
  return `${CONSOLE}/app/login`;
}

function xsrfFromCookie(): string {
  if (typeof document === "undefined") return "";
  const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
  return m ? decodeURIComponent(m[1]) : "";
}

/** Garante o cookie de CSRF do Sanctum antes de requests mutantes por sessão. */
async function ensureCsrf(): Promise<string> {
  let token = xsrfFromCookie();
  if (!token) {
    await fetch(`${CONSOLE}/sanctum/csrf-cookie`, { credentials: "include" });
    token = xsrfFromCookie();
  }
  return token;
}

// Auth: sessão do login Filament (cookie, Sanctum stateful) com fallback de
// Bearer token (dev / SSO ?sso=). Same-origin em prod; CORS+credentials em dev.
async function api<T>(path: string, init?: RequestInit): Promise<T> {
  const method = (init?.method ?? "GET").toUpperCase();
  const headers: Record<string, string> = {
    "Content-Type": "application/json",
    Accept: "application/json",
    ...(init?.headers as Record<string, string> | undefined),
  };

  const bearer = getToken();
  if (bearer) headers.Authorization = `Bearer ${bearer}`;
  else if (method !== "GET") headers["X-XSRF-TOKEN"] = await ensureCsrf();

  const at = getActiveTenant();
  if (at) headers["X-Tenant-Id"] = at; // marca ativa (Fase 2) — validada no servidor contra a org

  const r = await fetch(`${CONSOLE}/api${path}`, {
    ...init,
    credentials: "include",
    cache: "no-store", // dados dinâmicos: nunca do cache do browser
    headers,
  });
  const data = await r.json().catch(() => ({}));
  if (!r.ok) throw new ApiError((data as { message?: string }).message ?? `console ${path} ${r.status}`, r.status);
  if (method !== "GET") signalCreditsMaybe(path); // geração concluída → atualiza o saldo na sidebar
  return data as T;
}

/**
 * fetch para os endpoints do console (dashboard): mesma origem em prod, console em dev,
 * sempre com sessão (cookie) + CSRF nas mutações. Retorna Response cru (caller faz .json()).
 * `path` começa com "/api/..." (igual ao dashboard antigo).
 */
export async function sfetch(path: string, init?: RequestInit): Promise<Response> {
  const method = (init?.method ?? "GET").toUpperCase();
  const headers: Record<string, string> = { ...(init?.headers as Record<string, string> | undefined) };
  if (!(init?.body instanceof FormData) && !headers["Content-Type"] && method !== "GET") {
    headers["Content-Type"] = "application/json";
  }
  headers.Accept = headers.Accept ?? "application/json";
  const bearer = getToken();
  if (bearer) headers.Authorization = `Bearer ${bearer}`;
  else if (method !== "GET" && method !== "HEAD") headers["X-XSRF-TOKEN"] = await ensureCsrf();

  const at = getActiveTenant();
  if (at) headers["X-Tenant-Id"] = at; // marca ativa (Fase 2) — validada no servidor contra a org

  // cache: "no-store" — dados do dashboard são dinâmicos; nunca servir do cache do browser
  // (senão uma resposta antiga, ex. publicação com menos redes, "gruda" após republicar).
  const res = await fetch(`${CONSOLE}${path}`, { ...init, credentials: "include", cache: "no-store", headers });
  if (res.ok && method !== "GET" && method !== "HEAD") signalCreditsMaybe(path); // gerou → atualiza saldo
  return res;
}

export type Source = { title: string; url: string; content: string; source: string };
export type Research = { answer: string; results: Source[] };
export type Summary = { summary: string; brief: string };
export type TextResult = { post: string; image_prompt: string };
export type Approval = {
  id: number;
  keyword: string;
  preview_text: string;
  image_url: string;
  video_url: string;
  status: string;
  created_at: string;
};

export type UsageKind = { used: number; limit: number; remaining: number; pct: number };
export type Usage = {
  period: string;
  plan: string;
  premium: boolean;
  kinds: Record<"image" | "video" | "veo", UsageKind>;
};
export type Brand = { id: number; name: string; slug: string };
export type Me = {
  id: number;
  name: string;
  email: string;
  tenant: { id: number; name: string; plan: string; voice_id: string | null } | null;
  // Fase 2 — organização (billing) + marcas acessíveis + marca ativa (selecionada).
  organization?: { id: number; name: string; plan: string } | null;
  brands?: Brand[];
  active_tenant_id?: number | null;
};

// Compositor de posts (next/og) — identidade visual da marca sobre a imagem crua.
export type BrandKit = {
  name: string;
  handle: string | null;
  initial: string;
  logoUrl: string | null;
  primary: string;
  ink: string;
};
export type ComposeFormat = "feed" | "retrato" | "story" | "paisagem";
export type ComposeArgs = {
  draftId: number;
  imageUrl: string;
  format?: ComposeFormat;
  kicker?: string;
  titulo?: string;
  slides?: string[]; // carrossel: 1 título por slide (>1 → carrossel com pager)
  subtitulo?: string;
  cta?: string;
  platforms?: string[];
};
export type ComposedItem = {
  id: string;
  kind: string;
  url: string;
  style?: string;
  platforms?: string[];
  composed?: boolean;
};
export type BrandKitForm = {
  brand_primary?: string;
  brand_ink?: string;
  brand_handle?: string;
  brand_logo_url?: string;
};

function gen<T>(path: string, body: unknown): Promise<T> {
  return api<T>(`/generate${path}`, { method: "POST", body: JSON.stringify(body) });
}

// Modelo de geração exposto ao browser (GenModelResource — sem provider/model reais, white-label).
export type GenModelInfo = {
  slug: string;
  display_name: string;
  kind: string;
  subtype: string | null;
  cost_credits: number | null;
  min_plan: string | null;
  is_unstable?: boolean;
  real_name?: string;   // nome REAL do modelo (provider_model_id) — só o OPERADOR recebe (white-label)
  premium?: boolean;    // Veo (áudio nativo, lento, /v1/veo) — a caixa síncrona prompt→vídeo exclui
  film_tail?: boolean;  // controla o ÚLTIMO quadro (primeiro+último no i2v); habilita o modo keyframe
  refs_max?: number;    // quantas imagens-âncora aceita por clipe: 0=só texto, 1=uma base, >1=várias refs
  utility?: boolean;    // utilitário (mapa PBR/refino/conserto) — roda por botão próprio, não é motor de geração
  runs_on?: MotorLugar; // ONDE roda: nuvem (custa crédito) · estudio (seu ComfyUI) · mac (CLI daqui)
  // ORIGEM nomeada — "KIE" | "Higgsfield" | "ComfyUI" | "Mac" | "MiniMax" | "Google" | …
  // Só o OPERADOR recebe (igual `real_name`). É o que diz de QUAL CONTA sai a peça: `runs_on`
  // junta KIE/MiniMax/Google em "nuvem" e Higgsfield/mmx em "mac", e é a conta que acaba.
  origem?: string;
  qualities?: { key: string; label: string; p?: number | null; p5?: number | null; p10?: number | null }[];
};

export type Voice = { id: string; name: string; category?: string };
// Args da caixa única prompt→vídeo. Só `prompt` é obrigatório; o servidor tem defaults.
export type VideoArgs = {
  prompt: string;
  imageUrl?: string;    // i2v: imagem-base (do nosso S3) a animar — legado, uma âncora só
  imageUrls?: string[]; // âncoras do clipe (do nosso S3): referências de identidade, ou início/fim
  keyframes?: boolean;  // imageUrls[0] é o primeiro quadro e [1] o último (exige modelo film_tail)
  smooth?: boolean;     // movimento fluido: interpola o clipe pronto de 24 para 48 fps
  persona?: string;     // direção de estilo da aba Prompts (kind=video), já em TEXTO
  aspect?: string;      // 9:16 | 1:1 | 16:9
  duration?: string;    // "5" | "10"
  model?: string;       // slug do modelo (vazio = KIE ativo mais barato)
  quality?: string;     // key do tier de resolução (só modelos KIE com qualities)
  style?: string;
  narration?: boolean;  // narração ElevenLabs (precisa de chave válida)
  voiceId?: string;
  lang?: string;        // pt-BR | en-US
  subtitles?: boolean;
  // Estilo da legenda QUEIMADA (mesmos campos do engine SubtitleStyle) — só valem com
  // subtitles=true. Ausentes = look padrão (branco, borda preta, embaixo).
  subtitlePos?: string;         // bottom | middle | top
  subtitleSize?: number;        // fonte 12..56 (régua PlayRes 288)
  subtitleColor?: string;       // #RRGGBB do texto
  subtitleBorder?: number;      // espessura do contorno 1..10
  subtitleBorderColor?: string; // #RRGGBB do contorno
  subtitleFont?: string;        // sans|serif|mono|dejavu|dejavu-serif|noto
  subtitleOpacity?: number;     // transparência do texto 0..90
  subtitleBg?: boolean;         // caixa atrás do texto
  subtitleBgColor?: string;     // #RRGGBB da caixa
  subtitleBgOpacity?: number;   // opacidade da caixa 10..100
  music?: boolean;
  separateParts?: boolean; // devolve clipe CRU + áudio da narração à parte (montar no editor)
  narrationText?: string;  // texto falado (peças separadas); vazio = usa o prompt
  // Personagens da cena (ids da biblioteca): o servidor resolve o `lock` atual de cada um e o
  // injeta no prompt (IDENTITY LOCK). Complementa a âncora de imagem — visual + textual.
  charIds?: number[];
};

export const Engine = {
  research: (keyword: string, sources: string[]) => gen<Research>("/research", { keyword, sources }),
  summarize: (keyword: string, sources: Source[]) => gen<Summary>("/summarize", { keyword, sources }),
  text: (keyword: string, brief: string, platform: string) =>
    gen<TextResult>("/text", { keyword, brief, platform }),
  // Imagem via ENGINE (KIE/MiniMax, seletor de modelo do catálogo — padrão Reachyn). Complementa o
  // motor mmx local (rota Next /api/image). Só `prompt` é obrigatório; model vazio = KIE mais barato.
  // charIds: personagens da biblioteca — o servidor resolve o `lock` atual e injeta no prompt
  // (IDENTITY LOCK). Vale pra imagem também porque o quadro é o que vira âncora do clipe: trava
  // só no vídeo conserta tarde, o personagem já teria nascido errado no keyframe.
  image: (args: { prompt: string; aspect?: string; style?: string; model?: string; imageUrl?: string; imageUrls?: string[]; persona?: string; charIds?: number[] }) =>
    gen<{ ok?: boolean; url: string; name?: string; storage?: string; model?: string }>("/image", args),
  // Mídia pesada (jobs longos)
  short: (keyword: string, brief: string, voiceId = "") =>
    gen<{ url: string }>("/short", { keyword, brief, voiceId }),
  // Caixa única prompt→vídeo: SÍNCRONO (a resposta já traz a URL do clipe). Só `prompt` é
  // obrigatório; o resto tem default no servidor (9:16, 5s, KIE mais barato). Com separateParts,
  // `audioUrl` traz a narração à parte (pra montar no editor local).
  video: (args: VideoArgs) => gen<{ url: string; audioUrl?: string; parts?: boolean }>("/video", args),
  veo: (keyword: string, brief: string) => gen<{ url: string }>("/veo", { keyword, brief }),
  thumbnail: (videoUrl: string, title: string) =>
    gen<{ url: string }>("/thumbnail", { videoUrl, title }),
};

// ——— 🕹️ Sprites de jogo (aba /sprite) ———
// Proxy console → engine → motor hospedado. Jobs são ASSÍNCRONOS (enqueue → poll ~15s até
// status terminal: completed | partial | failed | canceled). O shape é o do serviço,
// repassado como veio — por isso os campos são opcionais/defensivos.
export type SpriteArtifact = { name: string; url: string };
export type SpriteStep = { id?: string; status?: string; error?: string; warnings?: string[] };
export type SpriteJob = {
  id: string;
  status: string;
  type?: string;
  characterName?: string;
  actions?: string[];
  createdAt?: number | string;
  steps?: SpriteStep[];
  artifacts?: SpriteArtifact[];
  creditsDebited?: number;
  creditsRefunded?: number;
  // progress pode ser null num job terminado — decidir por status/steps, nunca por ele.
  progress?: { step?: string; index?: number; total?: number } | null;
};
export type SpriteMe = { planCredits?: number; topupCredits?: number; total?: number; notice?: string };

// Os endpoints de sprite devolvem erros como {error} (não {message}) — helper próprio
// pra ApiError carregar a mensagem REAL (validação e saldo são orientação de uso).
async function sj<T>(path: string, init?: RequestInit): Promise<T> {
  const r = await sfetch(`/api${path}`, init);
  const d = (await r.json().catch(() => ({}))) as T & { error?: string; message?: string };
  if (!r.ok) throw new ApiError(d.error ?? d.message ?? `sprite ${r.status}`, r.status);
  return d;
}

export const Sprite = {
  me: () => sj<SpriteMe>("/sprite/me"),
  jobs: (limit = 25) => sj<{ jobs?: SpriteJob[] }>(`/sprite/jobs?limit=${limit}`),
  job: (id: string) => sj<{ job?: SpriteJob; notice?: string }>(`/sprite/jobs/${encodeURIComponent(id)}`),
  create: (payload: Record<string, unknown>) =>
    sj<{ jobId?: string; credits?: number; notice?: string }>("/sprite/jobs", { method: "POST", body: JSON.stringify(payload) }),
  persist: (id: string, name?: string) =>
    sj<{ ok?: boolean; status?: string; files?: Record<string, string> }>(`/sprite/jobs/${encodeURIComponent(id)}/persist`, {
      method: "POST",
      body: JSON.stringify({ name: name ?? "" }),
    }),
  // ——— Motor LOCAL (pipeline próprio; âncora/clipe saem do Engine.image/Engine.video) ———
  frames: (videoUrl: string, fps?: number) =>
    sj<{ token?: string; count?: number; cols?: number; rows?: number; contact_url?: string }>("/sprite/frames", {
      method: "POST",
      body: JSON.stringify({ video_url: videoUrl, ...(fps ? { fps } : {}) }),
    }),
  normalize: (args: { token?: string; frames?: number[]; sheet_url?: string; cols?: number; rows?: number; chroma?: string; tolerance?: number; cell?: number; fps?: number; name?: string }) =>
    sj<{ sheet_url?: string; preview_url?: string; manifest_url?: string; frames?: number; cell?: number; fps?: number }>(
      "/sprite/normalize",
      { method: "POST", body: JSON.stringify(args) },
    ),
  // ——— Assets de jogo (tileset/background/textura/GUI/ícones/props) ———
  // Anexa um asset já gerado (URL do NOSSO acervo) na Galeria, idempotente por URL.
  assetGallery: (url: string, name?: string) =>
    sj<{ ok?: boolean }>("/sprite/asset-gallery", {
      method: "POST",
      body: JSON.stringify({ url, name: name ?? "" }),
    }),
};

export const Console = {
  me: () => api<Me>("/me"),
  usage: () => api<Usage>("/usage"),
  approvals: () => api<Approval[]>("/approvals"),
  createApproval: (data: Partial<Approval> & { meta?: Record<string, unknown> }) =>
    api<Approval>("/approvals", { method: "POST", body: JSON.stringify(data) }),
  approve: (id: number) => api<{ status: string }>(`/approvals/${id}/approve`, { method: "POST" }),
  reject: (id: number) => api<{ status: string }>(`/approvals/${id}/reject`, { method: "POST" }),
  // Brand kit visual da marca (lê/salva) — consumido pelo compositor de posts.
  brandKit: () => api<{ ok: boolean; brand_kit: BrandKit }>("/studio/brand-kit"),
  saveBrandKit: (form: BrandKitForm) =>
    api<{ ok: boolean; brand_kit: BrandKit }>("/studio/brand-kit", { method: "POST", body: JSON.stringify(form) }),
  // Compõe um post de marca a partir de uma imagem crua do draft → nova mídia composta.
  compose: (args: ComposeArgs) =>
    api<{ ok: boolean; draftId: number; item: ComposedItem | null; items: ComposedItem[]; media: ComposedItem[] }>(
      "/studio/compose",
      { method: "POST", body: JSON.stringify(args) },
    ),
  // Põe as cenas sem clipe na FILA do worker (o lote deixa de depender da aba).
  // `avisos`: cenas cuja locução não cabe no clipe (o servidor mede o texto). Não bloqueiam o
  // lote — 10s custa quase o dobro de 5s, então trocar a duração é decisão de quem paga.
  roteiroRender: (args: { name?: string; model?: string; smooth?: boolean; draftId?: number; cenas: unknown[] }) =>
    api<{ ok: boolean; draftId: number; fila: number; prontas: number;
          avisos?: { cena: number; titulo: string; aviso: string }[] }>("/roteiro/render", { method: "POST", body: JSON.stringify(args) }),
  /** Estado das cenas na fila (clipe pronto por índice). */
  roteiroStatus: (draftId: number) =>
    api<{ ok: boolean; draftId: number; cenas: { clipUrl: string | null; imageUrl: string | null }[]; filmUrl?: string | null; montando?: boolean }>(`/roteiro/${draftId}`),
  /** Quadro da cena NA FILA do worker — a geração síncrona segurava um worker do php-fpm por
   *  20-40s e travava o app inteiro quando eram várias cenas. Devolve só o draftId; o quadro
   *  chega pelo mesmo polling do clipe. */
  /** `imageRoles` — o PAPEL de cada referência, alinhado por índice a `imageUrls`. Sem papel toda
   *  referência é lida como IDENTIDADE, e uma imagem mandada só pra fixar o LOOK faz o modelo
   *  copiar o assunto dela. É o que sustenta a imagem-chave de estilo (papel `estilo`). */
  roteiroImagem: (args: { draftId?: number; index: number; prompt: string; aspect?: string; style?: string; model?: string; imageUrls?: string[]; imageRoles?: string[]; charIds?: number[]; name?: string }) =>
    api<{ ok: boolean; draftId: number; queued: boolean }>("/roteiro/imagem", { method: "POST", body: JSON.stringify(args) }),
  /** 🎞️ CÂMERA PROGRAMADA (Ken Burns) sobre o quadro parado da cena — zoompan do ffmpeg, SEM IA
   *  de vídeo e sem gastar crédito. `move` só aceita as keys de CAM_MOVE_KEYS (lib/shots.ts);
   *  qualquer outra volta 422 com a lista suportada e a cena cai no clipe de IA. */
  roteiroCamclip: (args: { draftId?: number; index: number; imageUrl: string; move: string; duration?: string; aspect?: string; name?: string }) =>
    api<{ ok: boolean; draftId: number; url?: string; move?: string; error?: string; supported?: string[] }>(
      "/roteiro/camclip", { method: "POST", body: JSON.stringify(args) }),
  /** Plano do filme: uma ideia em texto → N cenas planejadas (não gera mídia). */
  filmplan: (args: { brief: string; beats?: number; clipDuration?: string; style?: string; persona?: string }) =>
    gen<{ title?: string; beats?: { title?: string; frame_prompt?: string; move_prompt?: string; voiceover?: string }[]; music_prompt?: string }>("/filmplan", args),
  /** Monta os clipes na ordem num filme só (concat + color-match no engine).
   *  `scripts` = a fala de CADA cena, alinhada a clipUrls (cena muda vai como ""): a locução é
   *  ancorada no início da sua cena e o trecho estica se a fala não couber. `script` (texto
   *  emendado) é o modo corrido antigo — vale só como reserva, porque cola tudo no segundo 0. */
  assemble: (args: { clipUrls: string[]; aspect?: string; name?: string; draftId?: number; transition?: string; smooth?: boolean; music?: boolean; musicPrompt?: string; narration?: boolean; script?: string; scripts?: string[]; voiceId?: string; subtitles?: boolean }) =>
    gen<{ url?: string; queued?: boolean; draftId?: number }>("/assemble", args),
  videoModels: () => api<{ data: GenModelInfo[] }>("/gen-models?kind=video&all=1"),
  imageModels: () => api<{ data: GenModelInfo[] }>("/gen-models?kind=image&all=1"),
  // Vozes ElevenLabs pra narração. Degrada com graça: sem chave válida vem { voices: [] }.
  voices: () => api<{ ok?: boolean; voices: Voice[] }>("/studio/voices"),
  // Narração SÍNCRONA (ElevenLabs via ffmpeg-service) — texto → áudio no S3. Usada por nó no
  // canvas de roteiro. `voice_id`/`lang` opcionais (default = os do tenant).
  tts: (text: string, voiceId?: string, lang?: string) =>
    api<{ ok: boolean; url?: string; error?: string }>("/studio/tts", {
      method: "POST",
      body: JSON.stringify({ text, ...(voiceId ? { voice_id: voiceId } : {}), ...(lang ? { lang } : {}) }),
    }),
  // Carregar mídia própria (imagem/vídeo/áudio) → Scality/S3, anexada à galeria. multipart via
  // /studio/upload (allowlist + limites no servidor). Retorna a URL S3 no primeiro item de media.
  upload: async (file: File): Promise<{ ok: boolean; draftId?: number; media?: ComposedItem[]; error?: string }> => {
    const kind = file.type.startsWith("video") ? "video" : file.type.startsWith("audio") ? "audio" : "image";
    const fd = new FormData();
    fd.append("kind", kind);
    fd.append("file", file);
    const r = await sfetch("/api/studio/upload", { method: "POST", body: fd });
    return r.json().catch(() => ({ ok: false, error: "resposta inválida do servidor" }));
  },
};
