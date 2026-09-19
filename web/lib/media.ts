// Origem do storage de mídia do Reachyn (Scality).
//
// Prod = s3.example.com (default: sem env, nada muda em produção). A stack dev sobe um
// Scality local (docker-compose.dev.yml) em http://localhost:8333 — o host de prod estava
// hardcodado aqui, então em dev TODA mídia local era classificada como "de fora" e sumia do
// front. A CSP em next.config.ts lê a mesma env.
export const MEDIA_ORIGIN = process.env.NEXT_PUBLIC_MEDIA_BASE || "https://s3.example.com";

// Prefixo do nosso bucket. Toda mídia que geramos vive em /public/reachyn/... — é o que permite
// reconhecer um arquivo NOSSO mesmo quando a URL gravada aponta pra um host que já morreu.
const OWN_PATH_PREFIX = "/public/";

// A URL do storage é gravada no banco no momento da geração — e o host muda com o tempo (quick
// tunnel efêmero → túnel nomeado → localhost em dev). Sem isto, mídia perfeitamente viva aparece
// como "indisponível" só porque o host de ontem não existe mais, e o botão "Limpar quebradas"
// APAGA arquivo bom. Aqui o caminho manda: se o path é do nosso bucket, servimos do storage
// ATUAL. Mídia de terceiros (fal.media expirado) não tem esse prefixo e segue intocada.
export function mediaUrl(url: string): string {
  try {
    const u = new URL(url, MEDIA_ORIGIN);
    if (u.origin === MEDIA_ORIGIN) return url;
    if (u.pathname.startsWith(OWN_PATH_PREFIX)) return MEDIA_ORIGIN + u.pathname + u.search;
    return url;
  } catch {
    return url;
  }
}

// Só a mídia do nosso storage carrega; o resto (fal.media expirado, storage antigo) costuma
// estar morto e o front marca como "indisponível". Comparar a ORIGEM inteira (protocolo+host+
// porta) e não um `includes` de substring: `evil.com/?x=s3.example.com` passaria no includes.
// Avalia a URL já normalizada — host velho de arquivo nosso não conta como mídia de fora.
export function isOwnMedia(url: string): boolean {
  try {
    return new URL(mediaUrl(url), MEDIA_ORIGIN).origin === MEDIA_ORIGIN;
  } catch {
    return false;
  }
}
