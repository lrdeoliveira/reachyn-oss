// Templates de composição visual nativa do Reachyn.
// Renderizados por next/og (Satori -> SVG -> resvg-wasm). Sem browser, sem serviço externo, sem custo por imagem.
// Cada template é o equivalente a um "brand template" do Canva, porém nativo e white-label.

export type BrandKit = {
  name: string
  handle?: string
  initial?: string
  logoUrl?: string      // logo do tenant (opcional); se ausente, usa `initial` num quadrado colorido
  primary: string       // cor da marca (pill/kicker/logo)
  ink?: string          // cor do texto sobre superfície clara (CTA)
}

export type PostContent = {
  imageUrl: string      // imagem crua gerada pela IA (fundo)
  kicker?: string       // etiqueta curta (ex: "Oferta da semana")
  titulo: string        // manchete
  subtitulo?: string
  cta?: string          // texto do botão
  pager?: { index: number; total: number } // slide N de M (carrossel)
}

export type Formato = 'feed' | 'retrato' | 'story' | 'paisagem'

export const FORMATS: Record<Formato, { w: number; h: number; redes: string }> = {
  feed:     { w: 1080, h: 1080, redes: 'Instagram/Facebook feed (1:1)' },
  retrato:  { w: 1080, h: 1350, redes: 'Instagram/Pinterest retrato (4:5)' },
  story:    { w: 1080, h: 1920, redes: 'Stories/Reels/TikTok (9:16)' },
  paisagem: { w: 1200, h: 675,  redes: 'YouTube/LinkedIn/X paisagem (16:9)' },
}

// Marca a Logo do tenant: <img> se tiver logoUrl, senão um quadrado com a inicial.
function LogoMark({ kit, s }: { kit: BrandKit; s: number }) {
  const size = Math.round(92 * s)
  if (kit.logoUrl) {
    return (
      // eslint-disable-next-line @next/next/no-img-element
      <img
        src={kit.logoUrl}
        width={size}
        height={size}
        style={{ width: size, height: size, borderRadius: Math.round(24 * s), objectFit: 'cover' }}
        alt=""
      />
    )
  }
  return (
    <div
      style={{
        width: size, height: size, borderRadius: Math.round(24 * s),
        display: 'flex', alignItems: 'center', justifyContent: 'center',
        backgroundColor: kit.primary, color: '#fff',
        fontSize: Math.round(52 * s), fontWeight: 900,
      }}
    >
      {kit.initial ?? kit.name.charAt(0).toUpperCase()}
    </div>
  )
}

/**
 * Template "Post de Marca": veste a imagem crua da IA com a identidade do tenant.
 * Preenche as lacunas #1 (composição título+logo+CTA), #3 (brand kit) e #5 (multi-formato).
 */
export function PostDeMarca({
  w, h, kit, content,
}: {
  w: number; h: number; kit: BrandKit; content: PostContent
}) {
  const s = Math.min(w, h) / 1080                   // escala relativa (paisagem baixa reduz proporcional)
  const pad = Math.round(76 * s)
  const safeBottom = h >= 1600 ? Math.round(210 * s) : pad   // safe zone da UI de Stories
  const ink = kit.ink ?? '#141414'

  return (
    <div style={{ width: w, height: h, display: 'flex', position: 'relative', fontFamily: 'Inter' }}>
      {/* 1) imagem crua da IA cobrindo o canvas (satori busca a URL server-side) */}
      {/* eslint-disable-next-line @next/next/no-img-element */}
      <img
        src={content.imageUrl}
        width={w}
        height={h}
        style={{ objectFit: 'cover', position: 'absolute', top: 0, left: 0 }}
        alt=""
      />

      {/* 2) overlay de legibilidade (escurece topo e base, preserva o meio) */}
      <div
        style={{
          position: 'absolute', top: 0, left: 0, width: w, height: h, display: 'flex',
          background:
            'linear-gradient(180deg, rgba(0,0,0,0.55) 0%, rgba(0,0,0,0) 26%, rgba(0,0,0,0) 46%, rgba(0,0,0,0.86) 100%)',
        }}
      />

      {/* 3) conteúdo */}
      <div
        style={{
          position: 'relative', display: 'flex', flexDirection: 'column',
          justifyContent: 'space-between', width: w, height: h,
          paddingTop: Math.round(70 * s), paddingLeft: pad, paddingRight: pad, paddingBottom: safeBottom,
        }}
      >
        {/* topo: identidade da marca */}
        <div style={{ display: 'flex', alignItems: 'center' }}>
          <LogoMark kit={kit} s={s} />
          <div style={{ display: 'flex', flexDirection: 'column', marginLeft: Math.round(24 * s) }}>
            <div style={{ color: '#fff', fontSize: Math.round(40 * s), fontWeight: 900, lineHeight: 1.05 }}>
              {kit.name}
            </div>
            {kit.handle ? (
              <div
                style={{
                  color: 'rgba(255,255,255,0.72)', fontSize: Math.round(27 * s),
                  fontWeight: 400, marginTop: Math.round(4 * s),
                }}
              >
                {kit.handle}
              </div>
            ) : null}
          </div>
          {content.pager ? (
            <div
              style={{
                marginLeft: 'auto', display: 'flex', alignItems: 'center',
                backgroundColor: 'rgba(0,0,0,0.42)', color: '#fff',
                fontSize: Math.round(28 * s), fontWeight: 900,
                paddingTop: Math.round(8 * s), paddingBottom: Math.round(8 * s),
                paddingLeft: Math.round(20 * s), paddingRight: Math.round(20 * s),
                borderRadius: 999,
              }}
            >
              {content.pager.index + '/' + content.pager.total}
            </div>
          ) : null}
        </div>

        {/* base: mensagem + CTA */}
        <div style={{ display: 'flex', flexDirection: 'column' }}>
          {content.kicker ? (
            <div style={{ display: 'flex' }}>
              <div
                style={{
                  display: 'flex', backgroundColor: kit.primary, color: '#fff',
                  fontSize: Math.round(26 * s), fontWeight: 900, letterSpacing: Math.round(2 * s),
                  paddingTop: Math.round(12 * s), paddingBottom: Math.round(12 * s),
                  paddingLeft: Math.round(24 * s), paddingRight: Math.round(24 * s),
                  borderRadius: 999, textTransform: 'uppercase',
                }}
              >
                {content.kicker}
              </div>
            </div>
          ) : null}

          <div
            style={{
              color: '#fff', fontSize: Math.round(82 * s), fontWeight: 900, lineHeight: 1.04,
              marginTop: Math.round(26 * s), letterSpacing: Math.round(-1 * s),
            }}
          >
            {content.titulo}
          </div>

          {content.subtitulo ? (
            <div
              style={{
                color: 'rgba(255,255,255,0.9)', fontSize: Math.round(34 * s),
                fontWeight: 400, lineHeight: 1.3, marginTop: Math.round(20 * s),
              }}
            >
              {content.subtitulo}
            </div>
          ) : null}

          {content.cta ? (
            <div style={{ display: 'flex', marginTop: Math.round(40 * s) }}>
              <div
                style={{
                  display: 'flex', alignItems: 'center', backgroundColor: '#fff', color: ink,
                  fontSize: Math.round(34 * s), fontWeight: 900,
                  paddingTop: Math.round(22 * s), paddingBottom: Math.round(22 * s),
                  paddingLeft: Math.round(44 * s), paddingRight: Math.round(44 * s),
                  borderRadius: 999,
                }}
              >
                {content.cta + '  →'}
              </div>
            </div>
          ) : null}
        </div>
      </div>
    </div>
  )
}

// ── 🎠 Slide editorial de carrossel ────────────────────────────────────────────────────────────
// A imagem de fundo vem da IA; TODO o texto é composto aqui. Essa divisão é a decisão central da
// feature: gerador de imagem erra ortografia em PT-BR, corta palavra na borda e troca a fonte
// entre slides — e um carrossel só funciona se os N slides parecerem a MESMA peça.
//
// As regras abaixo não são gosto: são o design system destilado do sistema editorial de origem.
// Três níveis de hierarquia (âncora / contexto / metadado), texto nos dois terços inferiores,
// accent só em palavra-chave, e barra de progresso SEM NÚMERO — contador na arte é marca de
// template genérico, e o swipe já é nativo da rede.

export type SlideContent = {
  imageUrl?: string        // fundo gerado pela IA (ausente = fundo sólido no registro tonal)
  role: string             // capa | hook | mecanismo | prova | expansao | aplicacao | direcao | assinatura
  tag?: string             // rótulo funcional (vazio na capa e na assinatura)
  blocks: string[]         // blocos de copy na ordem de leitura
  accent?: string[]        // palavras-chave que recebem a cor da marca (máx. 3)
  index: number            // 1-based
  total: number
  tone?: 'light' | 'dark'  // registro tonal — vem do brief visual da marca, não de um default
  signature?: string       // assinatura de rodapé repetida em todos os slides
  // 🎞️ REVELADO POR CAMADAS (vídeo Vox): quantos blocos já estão VISÍVEIS. Ausente = todos
  // (o slide estático do carrossel, comportamento original).
  //
  // Os blocos ocultos são desenhados com opacity 0, NÃO removidos: o layout precisa ficar
  // idêntico pixel a pixel entre os quadros do mesmo slide, senão o texto que já estava na tela
  // se desloca quando o próximo bloco entra — e aí o corte lê como "mudou de slide", não como
  // "apareceu uma camada". É o que separa motion de slideshow.
  reveal?: number
}

// Paleta do slide conforme o registro tonal. `dark` e `light` NÃO são temas soltos: o brief visual
// da marca decide qual vale, e slide escuro aguenta menos texto que slide claro.
function slideSkin(tone: 'light' | 'dark', kit: BrandKit) {
  return tone === 'dark'
    ? { bg: '#141210', fg: '#ffffff', body: 'rgba(255,255,255,0.72)', meta: 'rgba(255,255,255,0.45)', rule: 'rgba(255,255,255,0.16)', accent: kit.primary }
    : { bg: '#F1ECE3', fg: '#141210', body: 'rgba(20,18,16,0.68)', meta: 'rgba(20,18,16,0.45)', rule: 'rgba(20,18,16,0.14)', accent: kit.primary }
}

// Texto com palavras-chave em cor de destaque. Satori não faz rich text inline, então cada palavra
// vira um span num container que quebra linha. Comparação sem acento/pontuação porque a IA devolve
// a palavra-chave no lema ("critério") e o texto a traz flexionada ("critérios,").
function Realce({
  texto, accent, cor, corAccent, size, weight, lh, s,
}: { texto: string; accent: string[]; cor: string; corAccent: string; size: number; weight: number; lh: number; s: number }) {
  const norm = (x: string) =>
    x.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9]/g, '')
  const chaves = new Set(accent.map(norm).filter((x) => x.length > 2))
  const palavras = texto.split(/\s+/).filter(Boolean)
  return (
    <div style={{ display: 'flex', flexWrap: 'wrap', width: '100%' }}>
      {palavras.map((p, i) => {
        const n = norm(p)
        const hit = chaves.size > 0 && [...chaves].some((k) => n === k || (k.length > 4 && n.startsWith(k)))
        return (
          <div
            key={i}
            style={{
              display: 'flex', color: hit ? corAccent : cor,
              fontSize: size, fontWeight: hit ? Math.max(weight, 700) : weight,
              lineHeight: lh, marginRight: Math.round(size * 0.26), letterSpacing: Math.round(-0.5 * s),
            }}
          >
            {p}
          </div>
        )
      })}
    </div>
  )
}

export function SlideEditorial({
  w, h, kit, slide,
}: { w: number; h: number; kit: BrandKit; slide: SlideContent }) {
  const s = Math.min(w, h) / 1080
  const px = (n: number) => Math.round(n * s)
  const tone: 'light' | 'dark' = slide.tone === 'dark' ? 'dark' : 'light'
  const c = slideSkin(tone, kit)
  const pad = px(76)                       // margem horizontal segura (mín. 52px na régua, com folga)
  const capa = slide.role === 'capa'
  const assinatura = slide.role === 'assinatura'
  const accent = (slide.accent ?? []).slice(0, 3)
  const blocos = slide.blocks.filter((b) => b && b.trim())
  // Opacidade do bloco i no revelado por camadas (1 = visível). Ver SlideContent.reveal.
  const op = (i: number) => (slide.reveal == null || i < slide.reveal ? 1 : 0)
  const progresso = Math.max(0.08, Math.min(1, slide.index / Math.max(1, slide.total)))
  // Sobre foto, o texto sempre corre em claro com gradiente de proteção — contraste é regra, não estilo.
  const sobreFoto = Boolean(slide.imageUrl) && (capa || tone === 'dark')
  const fg = sobreFoto ? '#ffffff' : c.fg
  const body = sobreFoto ? 'rgba(255,255,255,0.82)' : c.body
  const meta = sobreFoto ? 'rgba(255,255,255,0.6)' : c.meta

  return (
    <div style={{ width: w, height: h, display: 'flex', position: 'relative', backgroundColor: c.bg, fontFamily: 'Inter' }}>
      {slide.imageUrl ? (
        // eslint-disable-next-line @next/next/no-img-element
        <img src={slide.imageUrl} width={w} height={h} style={{ objectFit: 'cover', position: 'absolute', top: 0, left: 0 }} alt="" />
      ) : null}

      {/* Gradiente de proteção: forte na base (onde o texto mora), leve no topo (barra da marca). */}
      {slide.imageUrl ? (
        <div
          style={{
            position: 'absolute', top: 0, left: 0, width: w, height: h, display: 'flex',
            background: capa
              ? 'linear-gradient(180deg, rgba(0,0,0,0.42) 0%, rgba(0,0,0,0.08) 30%, rgba(0,0,0,0.72) 62%, rgba(0,0,0,0.92) 100%)'
              : tone === 'dark'
                ? 'linear-gradient(180deg, rgba(0,0,0,0.62) 0%, rgba(0,0,0,0.42) 40%, rgba(0,0,0,0.88) 100%)'
                : 'linear-gradient(180deg, rgba(241,236,227,0.72) 0%, rgba(241,236,227,0.92) 45%, rgba(241,236,227,0.98) 100%)',
          }}
        />
      ) : null}

      {/* Accent bar — fio da cor da marca atravessando o topo. Repete em todos os slides. */}
      <div style={{ position: 'absolute', top: 0, left: 0, width: w, height: px(7), display: 'flex', backgroundColor: kit.primary }} />

      <div
        style={{
          position: 'relative', display: 'flex', flexDirection: 'column', justifyContent: 'space-between',
          width: w, height: h, paddingTop: px(46), paddingLeft: pad, paddingRight: pad, paddingBottom: px(64),
        }}
      >
        {/* NÍVEL 3 — metadado: barra da marca + tag funcional. Organiza sem competir. */}
        <div style={{ display: 'flex', flexDirection: 'column' }}>
          <div style={{ display: 'flex', alignItems: 'center' }}>
            <div style={{ display: 'flex', color: meta, fontSize: px(21), fontWeight: 700, letterSpacing: px(2), textTransform: 'uppercase' }}>
              {kit.name}
            </div>
            {kit.handle ? (
              <div style={{ display: 'flex', color: meta, fontSize: px(21), fontWeight: 400, marginLeft: px(16) }}>
                {kit.handle}
              </div>
            ) : null}
            {capa && kit.logoUrl ? (
              // Capa: logo pequena e discreta — não compete com a headline.
              // eslint-disable-next-line @next/next/no-img-element
              <img src={kit.logoUrl} width={px(64)} height={px(64)} style={{ width: px(64), height: px(64), objectFit: 'contain', marginLeft: 'auto' }} alt="" />
            ) : null}
          </div>
          {slide.tag ? (
            <div style={{ display: 'flex', marginTop: px(34) }}>
              <div
                style={{
                  display: 'flex', color: kit.primary, fontSize: px(22), fontWeight: 700,
                  letterSpacing: px(3), textTransform: 'uppercase',
                }}
              >
                {slide.tag}
              </div>
            </div>
          ) : null}
        </div>

        {assinatura ? (
          // Slide de assinatura: a marca é o protagonista visual, o CTA vem abaixo em hierarquia
          // menor. Opticamente um pouco abaixo do centro — parece mais premium que centralizado.
          <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', flex: 1, marginBottom: px(40) }}>
            {kit.logoUrl ? (
              // eslint-disable-next-line @next/next/no-img-element
              <img src={kit.logoUrl} width={px(360)} height={px(360)} style={{ width: px(360), height: px(360), objectFit: 'contain' }} alt="" />
            ) : (
              <div style={{ display: 'flex', color: fg, fontSize: px(96), fontWeight: 900, letterSpacing: px(-3), textAlign: 'center' }}>
                {kit.name.toUpperCase()}
              </div>
            )}
            {blocos[0] ? (
              <div style={{ display: 'flex', color: body, fontSize: px(38), fontWeight: 700, marginTop: px(44), textAlign: 'center', opacity: op(0) }}>
                {blocos[0]}
              </div>
            ) : null}
            {kit.handle ? (
              <div style={{ display: 'flex', color: meta, fontSize: px(24), fontWeight: 400, marginTop: px(20) }}>{kit.handle}</div>
            ) : null}
          </div>
        ) : (
          // Conteúdo ancorado no terço INFERIOR; o topo fica como respiro. O `marginTop: auto`
          // empurra o bloco pra baixo — sem ele o texto flutua no meio do slide e o princípio do
          // terço inferior vira só comentário.
          <div style={{ display: 'flex', flexDirection: 'column', marginTop: 'auto' }}>
            {/* NÍVEL 1 — âncora: o primeiro bloco é o que o olho vê primeiro. */}
            {blocos[0] ? (
              <div style={{ display: 'flex', opacity: op(0) }}>
                <Realce
                  texto={capa && blocos[1] ? blocos[0] : blocos[0]}
                  accent={capa ? [] : accent}
                  cor={capa ? meta : fg}
                  corAccent={kit.primary}
                  size={capa ? px(30) : px(62)}
                  weight={capa ? 700 : 900}
                  lh={capa ? 1.2 : 1.06}
                  s={s}
                />
              </div>
            ) : null}
            {capa && blocos[1] ? (
              <div style={{ display: 'flex', marginTop: px(20), opacity: op(1) }}>
                <Realce texto={blocos[1]} accent={accent} cor={fg} corAccent={kit.primary} size={px(88)} weight={900} lh={1.02} s={s} />
              </div>
            ) : null}
            {/* NÍVEL 2 — contexto: explica a âncora, peso claramente menor. */}
            {!capa && blocos[1] ? (
              <div style={{ display: 'flex', marginTop: px(28), opacity: op(1) }}>
                <Realce texto={blocos[1]} accent={accent} cor={body} corAccent={kit.primary} size={px(36)} weight={400} lh={1.34} s={s} />
              </div>
            ) : null}
            {!capa && blocos.length > 2 ? (
              <div style={{ display: 'flex', marginTop: px(18), opacity: op(2) }}>
                <Realce texto={blocos.slice(2).join(' ')} accent={accent} cor={body} corAccent={kit.primary} size={px(32)} weight={400} lh={1.34} s={s} />
              </div>
            ) : null}
          </div>
        )}

        {/* Rodapé: assinatura de detalhe + barra de progresso. Sem número, sem seta de swipe. */}
        <div style={{ display: 'flex', flexDirection: 'column', marginTop: px(34) }}>
          {slide.signature ? (
            <div style={{ display: 'flex', color: meta, fontSize: px(19), fontWeight: 400, marginBottom: px(16) }}>
              {slide.signature}
            </div>
          ) : null}
          <div style={{ display: 'flex', width: '100%', height: px(4), backgroundColor: c.rule, borderRadius: px(2) }}>
            <div style={{ display: 'flex', width: Math.round((w - pad * 2) * progresso), height: px(4), backgroundColor: kit.primary, borderRadius: px(2) }} />
          </div>
        </div>
      </div>
    </div>
  )
}

// ── Shot-card / "model sheet" de storyboard (documento por cena, estilo prancha de produção).
// Quadro grande + KEY IDEAS + tabela de specs (câmera/lente/mov/luz/áudio) + Director's Note.
// Renderiza como PNG paisagem pra baixar/aprovar/compartilhar — o equivalente nativo aos
// historia board.jpeg de referência.
export type ShotCardContent = {
  imageUrl: string          // quadro da cena (keyframe/imagem gerada) — do nosso storage
  projectTitle: string      // título do projeto/história/filme
  sequence?: string         // ex: "Introdução"
  shotNumber: number        // nº do shot/cena (1-based)
  shotTitle?: string        // título da cena
  camera?: string           // plano de câmera (ex: "Close-up")
  lighting?: string         // iluminação (ex: "Low-key")
  lens?: string             // ex: "85mm"
  movement?: string         // movimento da câmera
  duration?: string         // ex: "5s"
  format?: string           // ex: "16:9"
  audio?: string            // ex: "Vento, respiração"
  voiceover?: string        // locução
  directorNote?: string     // nota de direção (prosa)
  keyIdeas?: string[]       // conceitos-chave
  primary?: string          // cor de destaque da marca (default vermelho RedFox)
}

export function StoryboardCard({ w, h, content }: { w: number; h: number; content: ShotCardContent }) {
  const s = w / 1600
  const paper = '#f3efe6', ink = '#1c1a17', sub = '#6b6459', line = '#cfc7b8'
  const accent = content.primary || '#e24a31'
  const px = (n: number) => Math.round(n * s)
  const specs: [string, string][] = [
    ['Cena', content.shotTitle ? content.shotNumber + ' — ' + content.shotTitle : String(content.shotNumber)],
    ['Duração', content.duration || '—'],
    ['Formato', content.format || '16:9'],
    ['Câmera', content.camera || 'Livre'],
    ['Lente', content.lens || '—'],
    ['Movimento', content.movement || '—'],
    ['Iluminação', content.lighting || '—'],
    ['Áudio', content.audio || '—'],
  ]
  const shotLabel = (content.sequence ? content.sequence + ' — ' : '') + 'Shot ' + String(content.shotNumber).padStart(2, '0')
  return (
    <div style={{ width: w, height: h, display: 'flex', flexDirection: 'column', backgroundColor: paper, fontFamily: 'Inter', color: ink, padding: px(46) }}>
      {/* HEADER */}
      <div style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', borderBottom: px(3) + 'px solid ' + ink, paddingBottom: px(14) }}>
        <div style={{ display: 'flex', flexDirection: 'column' }}>
          <div style={{ display: 'flex', fontSize: px(20), fontWeight: 700, letterSpacing: px(3), color: accent, textTransform: 'uppercase' }}>Storyboard</div>
          <div style={{ display: 'flex', fontSize: px(46), fontWeight: 900, letterSpacing: px(-1), lineHeight: 1 }}>{content.projectTitle}</div>
        </div>
        <div style={{ display: 'flex', fontSize: px(32), fontWeight: 900, textTransform: 'uppercase' }}>{shotLabel}</div>
      </div>
      {/* MAIN: quadro (esq) + key ideas (dir) */}
      <div style={{ display: 'flex', flex: 1, marginTop: px(22) }}>
        <div style={{ display: 'flex', flexDirection: 'column', width: px(940) }}>
          <div style={{ display: 'flex', width: px(940), height: px(365), backgroundColor: '#000', border: px(2) + 'px solid ' + ink }}>
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img src={content.imageUrl} width={px(940)} height={px(365)} style={{ objectFit: 'cover' }} alt="" />
          </div>
        </div>
        <div style={{ display: 'flex', flexDirection: 'column', flex: 1, marginLeft: px(34) }}>
          {content.shotTitle ? <div style={{ display: 'flex', fontSize: px(30), fontWeight: 900, marginBottom: px(16) }}>{content.shotTitle}</div> : null}
          {(content.keyIdeas || []).slice(0, 8).map((k, i) => (
            <div key={i} style={{ display: 'flex', alignItems: 'flex-start', marginBottom: px(11), fontSize: px(24), lineHeight: 1.25 }}>
              <div style={{ display: 'flex', color: accent, marginRight: px(10), fontWeight: 900 }}>•</div>
              <div style={{ display: 'flex', flex: 1 }}>{k}</div>
            </div>
          ))}
        </div>
      </div>
      {/* BOTTOM: specs (esq) + Director's Note (dir) */}
      <div style={{ display: 'flex', marginTop: px(16), borderTop: px(2) + 'px solid ' + line, paddingTop: px(14) }}>
        <div style={{ display: 'flex', flexDirection: 'column', width: px(720) }}>
          {specs.map(([k, v], i) => (
            <div key={i} style={{ display: 'flex', borderBottom: px(1) + 'px solid ' + line, paddingTop: px(6), paddingBottom: px(6) }}>
              <div style={{ display: 'flex', width: px(190), fontSize: px(20), fontWeight: 700, color: sub, textTransform: 'uppercase', letterSpacing: px(1) }}>{k}</div>
              <div style={{ display: 'flex', flex: 1, fontSize: px(22) }}>{v}</div>
            </div>
          ))}
        </div>
        <div style={{ display: 'flex', flexDirection: 'column', flex: 1, marginLeft: px(34) }}>
          <div style={{ display: 'flex', fontSize: px(20), fontWeight: 900, color: accent, textTransform: 'uppercase', letterSpacing: px(2), marginBottom: px(10) }}>Director&apos;s Note</div>
          {content.directorNote ? <div style={{ display: 'flex', fontSize: px(24), lineHeight: 1.35 }}>{content.directorNote}</div> : null}
          {content.voiceover ? (
            <div style={{ display: 'flex', flexDirection: 'column', marginTop: px(16) }}>
              <div style={{ display: 'flex', fontSize: px(18), fontWeight: 700, color: sub, textTransform: 'uppercase', letterSpacing: px(2), marginBottom: px(6) }}>Locução</div>
              <div style={{ display: 'flex', fontSize: px(23), fontStyle: 'italic' }}>{'"' + content.voiceover + '"'}</div>
            </div>
          ) : null}
        </div>
      </div>
    </div>
  )
}
