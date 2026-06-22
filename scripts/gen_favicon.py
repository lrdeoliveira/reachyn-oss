#!/usr/bin/env python3
"""Gera o favicon do Reachyn (marca: quadrado arredondado, gradiente vermelho + 'R').
Cores da marca (landing): --red #e24a31 → --red-l #ffb4a6, fundo #141110.
Saídas: ICO multi-size + PNGs para web (Next), landing (estático) e console (Filament)."""
import os
from PIL import Image, ImageDraw, ImageFont, ImageFilter

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
FONT = "/System/Library/Fonts/Supplemental/Arial Rounded Bold.ttf"
RED_DARK = (184, 50, 31)    # #b8321f
RED = (226, 74, 49)         # #e24a31
RED_LITE = (255, 180, 166)  # #ffb4a6
WHITE = (255, 244, 240)

S = 512                     # canvas mestre
PAD = 44                    # margem do quadrado
RADIUS = 116                # cantos arredondados (~22%)


def lerp(a, b, t):
    return tuple(round(a[i] + (b[i] - a[i]) * t) for i in range(3))


def gradient(size, c0, c1):
    """Gradiente diagonal (top-left → bottom-right)."""
    img = Image.new("RGB", (size, size))
    px = img.load()
    for y in range(size):
        for x in range(size):
            t = (x + y) / (2 * (size - 1))
            px[x, y] = lerp(c0, c1, t)
    return img


def rounded_mask(size, pad, radius):
    m = Image.new("L", (size, size), 0)
    d = ImageDraw.Draw(m)
    d.rounded_rectangle([pad, pad, size - pad, size - pad], radius=radius, fill=255)
    return m


def make_master():
    base = Image.new("RGBA", (S, S), (0, 0, 0, 0))
    grad = gradient(S, RED, RED_DARK).convert("RGBA")
    mask = rounded_mask(S, PAD, RADIUS)

    # leve brilho diagonal (canto superior esquerdo mais claro)
    glow = Image.new("RGBA", (S, S), (0, 0, 0, 0))
    gd = ImageDraw.Draw(glow)
    gd.ellipse([PAD - 30, PAD - 30, S * 0.62, S * 0.62], fill=(255, 220, 210, 90))
    glow = glow.filter(ImageFilter.GaussianBlur(60))

    card = Image.composite(grad, base, mask)
    card = Image.alpha_composite(card, Image.composite(glow, base, mask))

    # letra R
    draw = ImageDraw.Draw(card)
    font = ImageFont.truetype(FONT, 320)
    txt = "R"
    bb = draw.textbbox((0, 0), txt, font=font)
    tw, th = bb[2] - bb[0], bb[3] - bb[1]
    tx = (S - tw) / 2 - bb[0]
    ty = (S - th) / 2 - bb[1] - 6
    # sombra sutil
    draw.text((tx + 4, ty + 5), txt, font=font, fill=(120, 24, 12, 120))
    draw.text((tx, ty), txt, font=font, fill=WHITE)
    return card


def save_png(img, path, size):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    img.resize((size, size), Image.LANCZOS).save(path, "PNG")
    print("png", size, path)


def save_ico(img, path):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    sizes = [(16, 16), (32, 32), (48, 48), (64, 64), (128, 128), (256, 256)]
    img.save(path, "ICO", sizes=sizes)
    print("ico", path)


def save_svg(path):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    svg = '''<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512">
  <defs>
    <linearGradient id="g" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0" stop-color="#e24a31"/>
      <stop offset="1" stop-color="#b8321f"/>
    </linearGradient>
    <radialGradient id="glow" cx="0.32" cy="0.30" r="0.7">
      <stop offset="0" stop-color="#ffe0d2" stop-opacity="0.55"/>
      <stop offset="1" stop-color="#ffe0d2" stop-opacity="0"/>
    </radialGradient>
  </defs>
  <rect x="44" y="44" width="424" height="424" rx="116" fill="url(#g)"/>
  <rect x="44" y="44" width="424" height="424" rx="116" fill="url(#glow)"/>
  <text x="256" y="356" font-family="Arial Rounded MT Bold, Arial, sans-serif"
        font-size="320" font-weight="800" text-anchor="middle" fill="#fff4f0">R</text>
</svg>
'''
    with open(path, "w") as f:
        f.write(svg)
    print("svg", path)


def main():
    m = make_master()

    # web (Next.js App Router) — favicon.ico + apple/icon pngs em app/
    save_ico(m, os.path.join(ROOT, "web/app/favicon.ico"))
    save_png(m, os.path.join(ROOT, "web/app/icon.png"), 512)
    save_png(m, os.path.join(ROOT, "web/app/apple-icon.png"), 180)

    # landing (estático) — ico + pngs + svg em landing/
    save_ico(m, os.path.join(ROOT, "landing/favicon.ico"))
    save_png(m, os.path.join(ROOT, "landing/favicon-32.png"), 32)
    save_png(m, os.path.join(ROOT, "landing/apple-touch-icon.png"), 180)
    save_svg(os.path.join(ROOT, "landing/favicon.svg"))

    # console (Filament) — substitui o favicon vazio + png pro panel
    save_ico(m, os.path.join(ROOT, "console/public/favicon.ico"))
    save_png(m, os.path.join(ROOT, "console/public/favicon-32.png"), 32)

    print("OK")


if __name__ == "__main__":
    main()
