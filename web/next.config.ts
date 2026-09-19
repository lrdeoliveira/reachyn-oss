import type { NextConfig } from "next";

// AUD-010: security headers na camada do app (defesa em profundidade junto ao Traefik).
// CSP em modo ENFORCE. O HTML é dinâmico (force-dynamic), mas o bootstrap RSC ainda
// injeta scripts inline cujo conteúdo varia por request — nonce/hash estático não
// cobre isso, então script-src precisa de 'unsafe-inline'. Mesmo assim a
// CSP bloqueia scripts de origens externas e mantém default-src/object-src/base-uri/
// form-action/frame-ancestors travados. O vetor de XSS (upload) já foi fechado na origem
// (AUD-006) e o front não tem sinks de innerHTML.
// Origem pública do storage. Prod = s3.example.com (default, sem env); a stack dev sobe um
// Scality local em http://localhost:8333. Hardcodar o host de prod bloqueava TODA a mídia em dev.
const MEDIA_ORIGIN = process.env.NEXT_PUBLIC_MEDIA_BASE || "https://s3.example.com";

// SÓ EM DEV. O React em modo dev usa eval() (reconstrói callstacks) e o Turbopack liga o HMR por
// websocket; sem isso a página responde 200 e NÃO hidrata — app morto, sem nenhum clique. O console
// também vive noutra origem em dev (:8210), então o fetch do browser precisa dela liberada.
// Em prod isDev=false → a CSP resultante é byte-a-byte a mesma de antes (enforce, AUD-010).
const isDev = process.env.NODE_ENV === "development";
const devOnly = (...v: (string | undefined)[]) => (isDev ? v.filter(Boolean) as string[] : []);

const csp = [
  "default-src 'self'",
  ["img-src 'self'", MEDIA_ORIGIN, "data: blob:"].join(" "),
  ["media-src 'self'", MEDIA_ORIGIN, "blob:"].join(" "),
  "style-src 'self' 'unsafe-inline'",
  ["script-src 'self' 'unsafe-inline'", ...devOnly("'unsafe-eval'")].join(" "),
  [
    "connect-src 'self'",
    MEDIA_ORIGIN,
    ...devOnly(process.env.NEXT_PUBLIC_CONSOLE_URL, "ws://localhost:3210"),
  ].join(" "),
  "font-src 'self' data:",
  "frame-ancestors 'self'",
  "base-uri 'self'",
  "form-action 'self'",
  "object-src 'none'",
].join("; ");

const nextConfig: NextConfig = {
  // O router do App Router também cacheia o payload RSC no cliente. Sem isto, um
  // deploy novo deixa o webview com o shell velho mesmo depois do HTML deixar de
  // ser estático (force-dynamic no layout).
  experimental: {
    staleTimes: { dynamic: 0, static: 30 },
  },
  async headers() {
    return [
      {
        source: "/_next/static/:path*",
        headers: [
          { key: "Cache-Control", value: "public, max-age=31536000, immutable" },
        ],
      },
      {
        // HTML e rotas dinâmicas — nunca s-maxage. O matcher exclui chunks imutáveis.
        source: "/((?!_next/static|_next/image|icon.png|apple-icon.png).*)",
        headers: [
          {
            key: "Cache-Control",
            value: "private, no-cache, no-store, max-age=0, must-revalidate",
          },
        ],
      },
      {
        source: "/:path*",
        headers: [
          { key: "Content-Security-Policy", value: csp },
          { key: "X-Frame-Options", value: "SAMEORIGIN" },
          { key: "X-Content-Type-Options", value: "nosniff" },
          { key: "Referrer-Policy", value: "strict-origin-when-cross-origin" },
          {
            key: "Strict-Transport-Security",
            value: "max-age=31536000; includeSubDomains; preload",
          },
        ],
      },
    ];
  },
};

export default nextConfig;
