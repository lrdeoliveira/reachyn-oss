// Compositor visual nativo do Reachyn — endpoint INTERNO (server-to-server).
// Chamado pelo console (StudioController::compose) com X-Compose-Token. Recebe imagem crua + brand kit
// + textos + formato e devolve o PNG do post pronto. Zero browser, zero serviço externo, zero custo/imagem.
import { ImageResponse } from 'next/og'
import { timingSafeEqual } from 'node:crypto'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { PostDeMarca, SlideEditorial, StoryboardCard, FORMATS, type Formato, type BrandKit, type PostContent, type ShotCardContent, type SlideContent } from './templates'

export const runtime = 'nodejs'
export const dynamic = 'force-dynamic'

// Fontes Inter (SIL OFL) embutidas no projeto. Runtime nodejs: fetch() não abre file://, então
// lemos do disco. O Dockerfile copia todo /app, então os .woff seguem presentes em prod.
const FONT_DIR = join(process.cwd(), 'app', 'api', 'compose', 'fonts')
const FONTS = [
  { name: 'Inter', weight: 400 as const, style: 'normal' as const, data: readFileSync(join(FONT_DIR, 'Inter-400.woff')) },
  { name: 'Inter', weight: 700 as const, style: 'normal' as const, data: readFileSync(join(FONT_DIR, 'Inter-700.woff')) },
  { name: 'Inter', weight: 900 as const, style: 'normal' as const, data: readFileSync(join(FONT_DIR, 'Inter-900.woff')) },
]

// Anti-SSRF: só compõe sobre mídia do nosso próprio storage (espelha isOwnMediaUrl do console).
// Compara a ORIGEM inteira (protocolo+host+porta) — tão estrito quanto o par protocolo+host de
// antes, mas configurável: o https+host de prod era fixo e rejeitava o Scality local (:8333) em dev.
// Sem env o valor é o de produção, então prod não muda. MEDIA_HOST segue aceito por compat.
const ALLOWED_ORIGIN = new URL(
  process.env.MEDIA_S3_PUBLIC_BASE
    || (process.env.MEDIA_HOST ? `https://${process.env.MEDIA_HOST}` : 'https://s3.example.com'),
).origin
function isOwnUrl(u: string | undefined): boolean {
  if (!u) return true // logoUrl ausente é ok
  try {
    return new URL(u).origin === ALLOWED_ORIGIN
  } catch {
    return false
  }
}

function tokenOk(header: string | null): boolean {
  const expected = process.env.COMPOSE_TOKEN || ''
  if (!expected || !header) return false
  const a = Buffer.from(header)
  const b = Buffer.from(expected)
  return a.length === b.length && timingSafeEqual(a, b)
}

export async function POST(req: Request) {
  if (!tokenOk(req.headers.get('x-compose-token'))) {
    return new Response('unauthorized', { status: 401 })
  }

  let body: { type?: string; format?: Formato; brand?: BrandKit; content?: PostContent; shot?: ShotCardContent; slide?: SlideContent }
  try {
    body = await req.json()
  } catch {
    return new Response('invalid json', { status: 400 })
  }

  // Shot-card / model sheet de storyboard (documento por cena) — paisagem 1600×900.
  if (body.type === 'shotcard') {
    const shot = body.shot
    if (!shot?.imageUrl || !shot?.projectTitle || typeof shot.shotNumber !== 'number') {
      return new Response('missing shot.imageUrl/projectTitle/shotNumber', { status: 400 })
    }
    if (!isOwnUrl(shot.imageUrl)) {
      return new Response('shot.imageUrl host not allowed', { status: 400 })
    }
    const SW = 1600, SH = 900
    try {
      return new ImageResponse(<StoryboardCard w={SW} h={SH} content={shot} />, {
        width: SW, height: SH, fonts: FONTS, headers: { 'Cache-Control': 'no-store' },
      })
    } catch (e) {
      return new Response('shotcard compose failed: ' + (e as Error).message, { status: 500 })
    }
  }

  // 🎠 Slide editorial de carrossel: fundo gerado pela IA + TODO o texto composto aqui.
  // `slide.imageUrl` é opcional — slide sólido no registro tonal da marca é uma composição válida
  // (e a mais barata: não gasta imagem nenhuma).
  if (body.type === 'carousel') {
    const slide = body.slide
    const brandC = body.brand
    if (!brandC?.name || !brandC?.primary || !slide?.role || !Array.isArray(slide?.blocks)) {
      return new Response('missing brand.name/brand.primary/slide.role/slide.blocks', { status: 400 })
    }
    if (!isOwnUrl(slide.imageUrl) || !isOwnUrl(brandC.logoUrl)) {
      return new Response('slide.imageUrl/logoUrl host not allowed', { status: 400 })
    }
    const fmt: Formato = body.format && body.format in FORMATS ? body.format : 'retrato'
    const { w: sw, h: sh } = FORMATS[fmt]
    try {
      return new ImageResponse(<SlideEditorial w={sw} h={sh} kit={brandC} slide={slide} />, {
        width: sw, height: sh, fonts: FONTS, headers: { 'Cache-Control': 'no-store' },
      })
    } catch (e) {
      return new Response('carousel compose failed: ' + (e as Error).message, { status: 500 })
    }
  }

  const format: Formato = body.format && body.format in FORMATS ? body.format : 'feed'
  const brand = body.brand
  const content = body.content
  if (!brand?.name || !brand?.primary || !content?.imageUrl || !content?.titulo) {
    return new Response('missing brand.name/brand.primary/content.imageUrl/content.titulo', { status: 400 })
  }
  if (!isOwnUrl(content.imageUrl) || !isOwnUrl(brand.logoUrl)) {
    return new Response('imageUrl/logoUrl host not allowed', { status: 400 })
  }

  const { w, h } = FORMATS[format]
  try {
    return new ImageResponse(<PostDeMarca w={w} h={h} kit={brand} content={content} />, {
      width: w,
      height: h,
      fonts: FONTS,
      headers: { 'Cache-Control': 'no-store' },
    })
  } catch (e) {
    return new Response('compose failed: ' + (e as Error).message, { status: 500 })
  }
}
