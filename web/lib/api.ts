// Client de API do Reachyn.
// Tudo passa pelo console Laravel (Sanctum bearer): a geração é um proxy autenticado
// console → engine, com enforcement de quota. O engine não é exposto ao browser.
//
// Domínio único (example.com): web e console são MESMA ORIGEM → chamadas relativas
// (sem CORS). Em dev, aponte NEXT_PUBLIC_CONSOLE_URL para o console (ex.: http://localhost:8000).
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

  const r = await fetch(`${CONSOLE}/api${path}`, {
    ...init,
    credentials: "include",
    cache: "no-store", // dados dinâmicos: nunca do cache do browser
    headers,
  });
  const data = await r.json().catch(() => ({}));
  if (!r.ok) throw new ApiError((data as { message?: string }).message ?? `console ${path} ${r.status}`, r.status);
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

  // cache: "no-store" — dados do dashboard são dinâmicos; nunca servir do cache do browser
  // (senão uma resposta antiga, ex. publicação com menos redes, "gruda" após republicar).
  return fetch(`${CONSOLE}${path}`, { ...init, credentials: "include", cache: "no-store", headers });
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
  kinds: Record<"image" | "video" | "premium-video", UsageKind>;
};
export type Me = {
  id: number;
  name: string;
  email: string;
  tenant: { id: number; name: string; plan: string; voice_id: string | null } | null;
};

function gen<T>(path: string, body: unknown): Promise<T> {
  return api<T>(`/generate${path}`, { method: "POST", body: JSON.stringify(body) });
}

export const Engine = {
  research: (keyword: string, sources: string[]) => gen<Research>("/research", { keyword, sources }),
  summarize: (keyword: string, sources: Source[]) => gen<Summary>("/summarize", { keyword, sources }),
  text: (keyword: string, brief: string, platform: string) =>
    gen<TextResult>("/text", { keyword, brief, platform }),
  image: (prompt: string, aspect = "1:1", style = "realista") =>
    gen<{ url: string }>("/image", { prompt, aspect, style }),
  // Mídia pesada (jobs longos)
  short: (keyword: string, brief: string, voiceId = "") =>
    gen<{ url: string }>("/short", { keyword, brief, voiceId }),
  video: (prompt: string) => gen<{ url: string }>("/video", { prompt }),
  premiumVideo: (keyword: string, brief: string) => gen<{ url: string }>("/premium-video", { keyword, brief }),
  thumbnail: (videoUrl: string, title: string) =>
    gen<{ url: string }>("/thumbnail", { videoUrl, title }),
};

export const Console = {
  me: () => api<Me>("/me"),
  usage: () => api<Usage>("/usage"),
  approvals: () => api<Approval[]>("/approvals"),
  createApproval: (data: Partial<Approval> & { meta?: Record<string, unknown> }) =>
    api<Approval>("/approvals", { method: "POST", body: JSON.stringify(data) }),
  approve: (id: number) => api<{ status: string }>(`/approvals/${id}/approve`, { method: "POST" }),
  reject: (id: number) => api<{ status: string }>(`/approvals/${id}/reject`, { method: "POST" }),
};
