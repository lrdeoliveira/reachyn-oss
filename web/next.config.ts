import type { NextConfig } from "next";

// AUD-010: security headers na camada do app (defesa em profundidade junto ao Traefik).
// CSP em modo ENFORCE. Nota: as páginas do Next são pré-renderadas (estáticas) e contêm
// scripts inline de bootstrap (RSC) cujo conteúdo varia por página — nonce/hash por request
// não se aplicam a HTML estático, então script-src precisa de 'unsafe-inline'. Mesmo assim a
// CSP bloqueia scripts de origens externas e mantém default-src/object-src/base-uri/
// form-action/frame-ancestors travados. O vetor de XSS (upload) já foi fechado na origem
// (AUD-006) e o front não tem sinks de innerHTML.
const csp = [
  "default-src 'self'",
  "img-src 'self' https://s3.example.com data: blob:",
  "media-src 'self' https://s3.example.com blob:",
  "style-src 'self' 'unsafe-inline'",
  "script-src 'self' 'unsafe-inline'",
  "connect-src 'self' https://s3.example.com",
  "font-src 'self' data:",
  "frame-ancestors 'self'",
  "base-uri 'self'",
  "form-action 'self'",
  "object-src 'none'",
].join("; ");

const nextConfig: NextConfig = {
  async headers() {
    return [
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
