// caption-service — micro HTTP que renderiza a CAMADA de legenda animada (Remotion) como
// WebM transparente (VP8 + alpha, imageFormat png + pixelFormat yuva420p). O ffmpeg-service
// compõe o overlay por cima do segmento; este serviço NUNCA toca no vídeo em si.
// Secure-by-default: sem porta publicada no host (só rede interna do stack) e X-Service-Token
// obrigatório (mesmo padrão AUD-008 do ffmpeg-service).
import http from 'node:http';
import {createReadStream} from 'node:fs';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import crypto from 'node:crypto';
import {fileURLToPath} from 'node:url';
import {renderMedia, selectComposition, makeCancelSignal} from '@remotion/renderer';

const DIR = path.dirname(fileURLToPath(import.meta.url));
const PORT = parseInt(process.env.PORT || '3979', 10);
const TOKEN = process.env.CAPTION_SERVICE_TOKEN || '';
if (!TOKEN) {
  console.error('CAPTION_SERVICE_TOKEN ausente — o serviço não sobe sem auth (secure-by-default).');
  process.exit(1);
}
const BUNDLE = path.join(DIR, 'bundle');
const MAX_BODY = 2 * 1024 * 1024;
const MAX_QUEUE = 8; // acima disso responde 429 — backpressure em vez de fila infinita
// timeout TOTAL de um render (watchdog via cancelSignal); sem ele um render travado
// entala a fila serializada pra sempre.
const RENDER_TIMEOUT_MS = parseInt(process.env.CAPTION_RENDER_TIMEOUT_MS || '300000', 10);
// null = deixa o Remotion decidir (metade dos cores); na VPS o compose fixa 2 pra não
// disputar CPU com o ffmpeg-service.
const CONCURRENCY = process.env.CAPTION_RENDER_CONCURRENCY ? parseInt(process.env.CAPTION_RENDER_CONCURRENCY, 10) : null;

let serveUrl = BUNDLE;
// A imagem Docker traz o bundle pronto (scripts/prebundle.mjs no build). Em dev local,
// bundla no primeiro start se não existir.
async function ensureBundle() {
  try {
    await fs.access(path.join(BUNDLE, 'index.html'));
  } catch {
    console.log('bundle ausente — gerando (dev)…');
    const {bundle} = await import('@remotion/bundler');
    serveUrl = await bundle({entryPoint: path.join(DIR, 'remotion', 'index.ts'), outDir: BUNDLE});
  }
}

// Fila SERIALIZADA: 1 render por vez. Chrome + encode PNG→VP8 saturam a máquina; renders
// paralelos só alongam todo mundo e estouram memória.
let queueLen = 0;
let chain = Promise.resolve();
const enqueue = (job) => {
  queueLen++;
  const run = () => job().finally(() => { queueLen--; });
  const p = chain.then(run, run);
  chain = p.then(() => {}, () => {});
  return p;
};

const HEX = /^#[0-9A-Fa-f]{6}$/;
const hex = (v, fb) => (typeof v === 'string' && HEX.test(v) ? v : fb);
const clamp = (v, lo, hi, fb) => {
  const n = Number(v);
  return Number.isFinite(n) ? Math.min(hi, Math.max(lo, n)) : fb;
};

class BadRequest extends Error {}

// Allowlist + clamps na ENTRADA (mesma filosofia do server.py): nada do body chega cru
// no React nem no nome de arquivo.
function sanitize(body) {
  if (!Array.isArray(body.words) || body.words.length < 1 || body.words.length > 600) {
    throw new BadRequest('words: array de 1..600 itens');
  }
  const words = body.words
    .map((w) => {
      const text = String(w && w.text ? w.text : '').trim().slice(0, 80);
      const start = clamp(w && w.start, 0, 600, null);
      let end = clamp(w && w.end, 0, 600, null);
      if (!text || start === null || end === null) return null;
      if (end <= start) end = start + 0.05;
      return {text, start, end};
    })
    .filter(Boolean)
    .sort((a, b) => a.start - b.start);
  if (!words.length) throw new BadRequest('words: nenhuma palavra válida');

  const duration = clamp(body.duration, 0.5, 180, null);
  if (duration === null) throw new BadRequest('duration: 0.5..180s');
  const fps = Math.round(clamp(body.fps, 12, 60, 30));
  const even = (v, fb) => {
    const n = Math.round(clamp(v, 16, 2160, fb));
    return n - (n % 2);
  };
  const width = even(body.width, 1080);
  const height = even(body.height, 1920);
  const preset = ['pop', 'karaoke', 'bounce', 'vox'].includes(body.preset) ? body.preset : 'pop';

  const s = body.style && typeof body.style === 'object' ? body.style : {};
  const style = {
    pos: ['top', 'middle'].includes(s.pos) ? s.pos : 'bottom',
    size: Math.round(clamp(s.size, 0, 72, 0)) || 20,
    color: hex(s.color, '#FFFFFF'),
    accentColor: hex(s.accentColor, '#FFD700'),
    border: Math.round(clamp(s.border, 0, 12, 3)),
    borderColor: hex(s.borderColor, '#000000'),
    font: ['sans', 'serif', 'mono', 'dejavu', 'dejavu-serif', 'noto'].includes(s.font) ? s.font : 'sans',
    opacity: Math.round(clamp(s.opacity, 0, 90, 0)),
    bg: Boolean(s.bg),
    bgColor: hex(s.bgColor, '#000000'),
    bgOpacity: Math.round(clamp(s.bgOpacity, 0, 100, 60)),
  };

  return {words, duration, fps, width, height, preset, style};
}

const authOk = (req) => {
  const got = String(req.headers['x-service-token'] || '');
  const a = Buffer.from(got);
  const b = Buffer.from(TOKEN);
  return a.length === b.length && crypto.timingSafeEqual(a, b);
};

const readBody = (req) =>
  new Promise((resolve, reject) => {
    const chunks = [];
    let size = 0;
    req.on('data', (c) => {
      size += c.length;
      if (size > MAX_BODY) {
        reject(new BadRequest('body > 2MB'));
        req.destroy();
        return;
      }
      chunks.push(c);
    });
    req.on('end', () => resolve(Buffer.concat(chunks)));
    req.on('error', reject);
  });

const json = (res, code, obj) => {
  const b = JSON.stringify(obj);
  res.writeHead(code, {'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(b)});
  res.end(b);
};

async function render(inputProps) {
  const out = path.join(os.tmpdir(), `caption_${Date.now()}_${crypto.randomBytes(4).toString('hex')}.webm`);
  const {cancelSignal, cancel} = makeCancelSignal();
  const watchdog = setTimeout(() => cancel(), RENDER_TIMEOUT_MS);
  try {
    const composition = await selectComposition({serveUrl, id: 'captions', inputProps});
    await renderMedia({
      composition,
      serveUrl,
      codec: 'vp8',
      imageFormat: 'png',
      pixelFormat: 'yuva420p', // combinação documentada pro WebM com canal alpha
      outputLocation: out,
      inputProps,
      concurrency: CONCURRENCY,
      cancelSignal,
      logLevel: 'warn',
    });
    return out;
  } finally {
    clearTimeout(watchdog);
  }
}

const server = http.createServer(async (req, res) => {
  try {
    if (req.method === 'GET' && req.url === '/health') {
      json(res, 200, {ok: true, queue: queueLen});
      return;
    }
    if (req.method !== 'POST' || req.url !== '/render') {
      json(res, 404, {error: 'not found'});
      return;
    }
    if (!authOk(req)) {
      json(res, 401, {error: 'unauthorized'});
      return;
    }
    if (queueLen >= MAX_QUEUE) {
      json(res, 429, {error: 'fila cheia — tente de novo em instantes'});
      return;
    }
    let inputProps;
    try {
      inputProps = sanitize(JSON.parse((await readBody(req)).toString('utf8')));
    } catch (e) {
      json(res, 400, {error: e instanceof BadRequest ? e.message : 'JSON inválido'});
      return;
    }
    const t0 = Date.now();
    const out = await enqueue(() => render(inputProps));
    try {
      const st = await fs.stat(out);
      res.writeHead(200, {'Content-Type': 'video/webm', 'Content-Length': st.size});
      await new Promise((resolve, reject) => {
        const rs = createReadStream(out);
        rs.pipe(res);
        rs.on('end', resolve);
        rs.on('error', reject);
      });
      console.log(`render ok: ${inputProps.words.length} palavras, ${inputProps.duration}s, ${Date.now() - t0}ms`);
    } finally {
      fs.unlink(out).catch(() => {});
    }
  } catch (e) {
    console.error('render falhou:', e && e.message ? e.message : e);
    if (!res.headersSent) json(res, 500, {error: 'render falhou'});
    else res.destroy();
  }
});

await ensureBundle();
server.listen(PORT, '0.0.0.0', () => console.log(`caption-service na :${PORT} (bundle: ${serveUrl})`));
