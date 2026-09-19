import React, {useMemo} from 'react';
import {AbsoluteFill, interpolate, spring, useCurrentFrame, useVideoConfig} from 'remotion';

export type Word = {text: string; start: number; end: number};

// Espelha os campos de estilo da legenda queimada do ffmpeg-service (subtitle_* do /shortform):
// mesma allowlist de fonte, mesma semântica de tamanho/contorno (escala ASS PlayRes 288), pra o
// usuário trocar entre legenda estática e animada sem o visual "pular".
export type CaptionStyle = {
  pos: 'bottom' | 'middle' | 'top';
  size: number; // tamanho em unidades ASS (px = size * height / 288)
  color: string;
  accentColor: string; // cor de realce da palavra ativa
  border: number; // espessura do contorno em unidades ASS
  borderColor: string;
  font: string; // sans|serif|mono|dejavu|dejavu-serif|noto
  opacity: number; // transparência do texto 0..90 (0 = opaco)
  bg: boolean;
  bgColor: string;
  bgOpacity: number; // 0..100
};

export type CaptionsProps = {
  words: Word[]; // tempos em segundos, relativos ao início do segmento
  duration: number;
  fps: number;
  width: number;
  height: number;
  // 'vox' = a assinatura tipográfica do documentário explicativo: condensada branca CARIMBANDO
  // palavra a palavra + sweep de marca-texto (accentColor) varrendo sob a palavra ativa.
  preset: 'pop' | 'karaoke' | 'bounce' | 'vox';
  style: CaptionStyle;
};

export const defaultCaptionsProps: CaptionsProps = {
  words: [
    {text: 'LEGENDA', start: 0.2, end: 0.7},
    {text: 'ANIMADA', start: 0.7, end: 1.2},
    {text: 'NO', start: 1.2, end: 1.5},
    {text: 'REACHYN', start: 1.5, end: 2.2},
  ],
  duration: 3,
  fps: 30,
  width: 1080,
  height: 1920,
  preset: 'pop',
  style: {
    pos: 'bottom',
    size: 20,
    color: '#FFFFFF',
    accentColor: '#FFD700',
    border: 3,
    borderColor: '#000000',
    font: 'sans',
    opacity: 0,
    bg: false,
    bgColor: '#000000',
    bgOpacity: 60,
  },
};

// Fontes reais do container (mesmos pacotes apt do ffmpeg-service) + fallbacks pra dev no Mac.
const FONTS: Record<string, string> = {
  // condensada do preset vox — Liberation Sans Narrow vem no MESMO pacote apt (fonts-liberation)
  // já instalado no container; fallbacks pra dev no Mac.
  condensed: "'Liberation Sans Narrow', 'Arial Narrow', 'Helvetica Neue', sans-serif",
  sans: "'Liberation Sans', Arial, Helvetica, sans-serif",
  serif: "'Liberation Serif', 'Times New Roman', serif",
  mono: "'Liberation Mono', 'Courier New', monospace",
  dejavu: "'DejaVu Sans', Verdana, sans-serif",
  'dejavu-serif': "'DejaVu Serif', Georgia, serif",
  noto: "'Noto Sans', Arial, sans-serif",
};

// Página = grupo de palavras exibidas juntas. Fecha com PAGE_MAX_WORDS palavras ou quando já
// dura PAGE_MIN_DUR — mais palavras por página que o cue estático (2), porque o realce da
// palavra ativa mantém a leitura guiada mesmo com mais texto na tela.
const PAGE_MAX_WORDS = 3;
const PAGE_MIN_DUR = 0.9;

type Page = {words: Word[]; start: number; end: number};

const paginate = (words: Word[], duration: number): Page[] => {
  const pages: Page[] = [];
  let cur: Word[] = [];
  for (const w of words) {
    cur.push(w);
    const start = cur[0].start;
    const end = cur[cur.length - 1].end;
    if (end - start >= PAGE_MIN_DUR || cur.length >= PAGE_MAX_WORDS) {
      pages.push({words: cur, start, end});
      cur = [];
    }
  }
  if (cur.length) {
    // sobra curta no final: junta na página anterior (mesma regra do agrupador de cues)
    if (pages.length) {
      const last = pages[pages.length - 1];
      last.words = last.words.concat(cur);
      last.end = cur[cur.length - 1].end;
    } else {
      pages.push({words: cur, start: cur[0].start, end: cur[cur.length - 1].end});
    }
  }
  // estica cada página até o início da próxima (sem "piscar" entre páginas)
  for (let i = 0; i < pages.length; i++) {
    pages[i].end = i + 1 < pages.length ? pages[i + 1].start : Math.min(duration, pages[i].end + 0.3);
  }
  return pages;
};

const hexToRgba = (hexColor: string, alpha: number): string => {
  const m = /^#([0-9A-Fa-f]{6})$/.exec(hexColor || '');
  if (!m) return `rgba(0,0,0,${alpha})`;
  const n = parseInt(m[1], 16);
  return `rgba(${(n >> 16) & 255},${(n >> 8) & 255},${n & 255},${alpha})`;
};

// Contorno "chunky" estilo ASS: text-shadow em 8 direções (CSS text-stroke corrói o glifo por
// ser centrado; sombra empilhada cresce só pra fora, como o Outline do libass).
const outlineShadow = (radius: number, color: string): string => {
  if (radius <= 0) return '0 2px 6px rgba(0,0,0,0.35)';
  const dirs: string[] = [];
  for (let a = 0; a < 8; a++) {
    const dx = Math.round(Math.cos((a * Math.PI) / 4) * radius);
    const dy = Math.round(Math.sin((a * Math.PI) / 4) * radius);
    dirs.push(`${dx}px ${dy}px 0 ${color}`);
  }
  dirs.push('0 2px 6px rgba(0,0,0,0.35)');
  return dirs.join(', ');
};

export const Captions: React.FC<CaptionsProps> = ({words, duration, preset, style, width, height}) => {
  const frame = useCurrentFrame();
  const {fps} = useVideoConfig();
  const t = frame / fps;

  const pages = useMemo(() => paginate(words, duration), [words, duration]);
  const page = pages.find((p) => t >= p.start && t < p.end);
  if (!page) return null;

  const px = Math.max(8, ((style.size || 20) * height) / 288); // paridade visual com a legenda ASS
  const strokePx = Math.max(1, Math.round((((style.border ?? 3) * height) / 288) * 0.5));
  const textAlpha = 1 - Math.max(0, Math.min(90, style.opacity || 0)) / 100;
  // vox: a condensada É a assinatura — só cede se o usuário escolheu uma fonte de propósito.
  const fontFamily =
    preset === 'vox' && (!style.font || style.font === 'sans')
      ? FONTS.condensed
      : FONTS[style.font] || FONTS.sans;
  const shadow = outlineShadow(strokePx, style.borderColor || '#000000');

  // entrada sutil da página (todos os presets): zoom 0.94→1
  const pageIn = spring({
    frame: frame - Math.round(page.start * fps),
    fps,
    config: {damping: 14, stiffness: 180, mass: 0.7},
    durationInFrames: Math.max(2, Math.round(fps * 0.35)),
  });
  const pageScale = interpolate(pageIn, [0, 1], [0.94, 1]);

  // palavra ativa = a última que já começou a ser falada
  let active = -1;
  page.words.forEach((w, i) => {
    if (t >= w.start) active = i;
  });

  const vertical: React.CSSProperties =
    style.pos === 'top'
      ? {justifyContent: 'flex-start', paddingTop: height * 0.12}
      : style.pos === 'middle'
        ? {justifyContent: 'center'}
        : {justifyContent: 'flex-end', paddingBottom: height * 0.16};

  return (
    <AbsoluteFill style={{display: 'flex', alignItems: 'center', ...vertical}}>
      <div
        style={{
          maxWidth: width * 0.86,
          transform: `scale(${pageScale})`,
          display: 'flex',
          flexWrap: 'wrap',
          justifyContent: 'center',
          alignItems: 'baseline',
          columnGap: px * 0.28,
          rowGap: px * 0.1,
          ...(style.bg
            ? {
                backgroundColor: hexToRgba(style.bgColor || '#000000', Math.max(0, Math.min(100, style.bgOpacity ?? 60)) / 100),
                padding: `${px * 0.3}px ${px * 0.55}px`,
                borderRadius: px * 0.35,
              }
            : {}),
        }}
      >
        {page.words.map((w, i) => {
          const isActive = i === active;
          const started = t >= w.start;
          const sWord = spring({
            frame: frame - Math.round(w.start * fps),
            fps,
            config:
              preset === 'pop'
                ? {damping: 11, stiffness: 220, mass: 0.5} // overshoot rápido
                : preset === 'bounce'
                  ? {damping: 9, stiffness: 170, mass: 0.8} // quica
                  : {damping: 13, stiffness: 200, mass: 0.6},
          });

          let opacity = textAlpha;
          let transform = 'none';
          if (preset === 'pop') {
            // cada palavra entra no instante em que é FALADA (invisível antes)
            opacity = started ? textAlpha * Math.min(1, sWord * 1.6) : 0;
            transform = started ? `scale(${interpolate(sWord, [0, 1], [0.4, 1])})` : 'scale(0.4)';
          } else if (preset === 'karaoke') {
            // página inteira visível; a palavra ativa cresce enquanto é falada
            transform = isActive ? `scale(${1 + 0.12 * sWord})` : 'scale(1)';
          } else if (preset === 'vox') {
            // CARIMBO: a palavra bate no instante falado — vem de cima (grande→lugar), snap
            // duro, overshoot mínimo. "Stamp", não "pop": a diferença é entrar POR CIMA.
            opacity = started ? textAlpha : 0;
            transform = started ? `scale(${interpolate(sWord, [0, 1], [1.22, 1])})` : 'scale(1.22)';
          } else {
            // bounce: página visível; a palavra ativa dá um pulinho
            transform = isActive ? `translateY(${interpolate(sWord, [0, 1], [px * 0.35, 0])}px)` : 'translateY(0)';
          }

          // 🖍️ SWEEP DE MARCA-TEXTO (só no vox): a barra na cor de realce se DESENHA da esquerda
          // pra direita sob a palavra assim que ela é falada — e FICA, como tinta de marca-texto
          // (sumir depois leria como erro de render, não como grifo). O texto continua BRANCO;
          // no vox o realce é a barra, nunca a cor da letra.
          const sweep =
            preset === 'vox' && started
              ? Math.max(0, Math.min(1, (t - w.start) / Math.max(0.12, Math.min(0.3, w.end - w.start))))
              : 0;

          return (
            <span
              key={`${i}-${w.text}`}
              style={{
                display: 'inline-block',
                position: 'relative',
                fontFamily,
                fontSize: px,
                fontWeight: preset === 'vox' ? 900 : 800,
                lineHeight: 1.25,
                textTransform: 'uppercase',
                whiteSpace: 'pre',
                color: hexToRgba(
                  preset !== 'vox' && isActive ? style.accentColor || '#FFD700' : style.color || '#FFFFFF',
                  1,
                ),
                opacity,
                transform,
                textShadow: shadow,
              }}
            >
              {sweep > 0 && (
                <span
                  style={{
                    position: 'absolute',
                    left: '-0.06em',
                    bottom: '0.04em',
                    width: `${sweep * 104}%`,
                    height: '0.38em',
                    background: hexToRgba(style.accentColor || '#FFD700', 0.85),
                    transform: 'rotate(-1.2deg)', // traço de mão, não régua
                    borderRadius: '0.10em',
                    zIndex: -1,
                  }}
                />
              )}
              {w.text}
            </span>
          );
        })}
      </div>
    </AbsoluteFill>
  );
};
