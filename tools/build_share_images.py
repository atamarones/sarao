"""Genera las imágenes para compartir enlaces (Open Graph, 1200x630 JPG).

Uso: python tools/build_share_images.py RUTA_A_FUENTES
Fuentes (Google Fonts, OFL): BigShoulders.ttf (variable), InstrumentSerif-Italic.ttf, Outfit.ttf (variable).
Salida: public_html/assets/img/og-home.jpg y og-carta.jpg
"""
import os
import sys

from PIL import Image, ImageDraw, ImageEnhance, ImageFilter, ImageFont

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
IMG = os.path.join(ROOT, 'public_html', 'assets', 'img')
FONTS = sys.argv[1]
W, H = 1200, 630
INK = (7, 9, 13)
PAPER = (246, 241, 248)
NEON = (255, 45, 61)
CROWN = (248, 184, 0)
MUTED = (200, 206, 214)


def font(name, size, weight=None):
    f = ImageFont.truetype(os.path.join(FONTS, name), size)
    if weight:
        f.set_variation_by_axes([weight])
    return f


def cover(path, focus=(0.5, 0.4)):
    im = Image.open(path).convert('RGB')
    s = max(W / im.width, H / im.height)
    im = im.resize((round(im.width * s), round(im.height * s)), Image.LANCZOS)
    x = round((im.width - W) * focus[0])
    y = round((im.height - H) * focus[1])
    return im.crop((x, y, x + W, y + H))


def shade(im):
    """Oscurece hacia la izquierda y hacia abajo para que el texto se lea."""
    im = ImageEnhance.Brightness(im).enhance(0.8)
    grad = Image.new('L', (W, H))
    px = grad.load()
    for x in range(W):
        for y in range(H):
            a = max(0.0, 1 - x / (W * 0.78)) * 235 + (y / H) ** 2 * 120
            px[x, y] = min(240, int(a))
    black = Image.new('RGB', (W, H), INK)
    return Image.composite(black, im, grad)


def glow_text(base, xy, text, fnt, color, radius=14):
    layer = Image.new('RGBA', base.size, (0, 0, 0, 0))
    ImageDraw.Draw(layer).text(xy, text, font=fnt, fill=color + (255,))
    blur = layer.filter(ImageFilter.GaussianBlur(radius))
    base.alpha_composite(blur)
    base.alpha_composite(blur)
    base.alpha_composite(layer)


def pill(draw, xy, text, fnt, fill, color):
    x, y = xy
    tw = draw.textlength(text, font=fnt)
    draw.rounded_rectangle((x, y, x + tw + 44, y + 56), radius=28, fill=fill)
    draw.text((x + 22, y + 28), text, font=fnt, fill=color, anchor='lm')
    return x + tw + 44


def logo(base, xy, width):
    lg = Image.open(os.path.join(IMG, 'logo-dark.png')).convert('RGBA')
    h = round(lg.height * width / lg.width)
    lg = lg.resize((width, h), Image.LANCZOS)
    halo = Image.new('RGBA', base.size, (0, 0, 0, 0))
    halo.paste(lg, xy, lg)
    base.alpha_composite(halo.filter(ImageFilter.GaussianBlur(10)))
    base.alpha_composite(halo)


def make(photo, focus, eyebrow, line1, neon_line, sub, cta, out):
    im = shade(cover(os.path.join(IMG, 'site', photo), focus)).convert('RGBA')
    d = ImageDraw.Draw(im)
    logo(im, (64, 46), 210)
    d.text((66, 178), eyebrow, font=font('Outfit.ttf', 22, 600), fill=CROWN)
    d.text((60, 206), line1, font=font('BigShoulders.ttf', 132, 900), fill=PAPER)
    glow_text(im, (64, 330), neon_line, font('InstrumentSerif-Italic.ttf', 108), NEON)
    d = ImageDraw.Draw(im)
    d.text((68, 470), sub, font=font('Outfit.ttf', 28, 400), fill=MUTED)
    end = pill(d, (66, 528), cta, font('Outfit.ttf', 24, 600), NEON, (255, 255, 255))
    d.text((end + 22, 556), 'saraopub.com', font=font('Outfit.ttf', 26, 500), fill=PAPER, anchor='lm')
    im.convert('RGB').save(os.path.join(IMG, out), 'JPEG', quality=86, optimize=True, progressive=True)
    print(out, os.path.getsize(os.path.join(IMG, out)) // 1024, 'KB')


make('escenario-duo.webp', (0.75, 0.35), 'KARAOKE BAR · LA CANDELARIA, BOGOTÁ',
     'EL MEJOR KARAOKE', 'de Bogotá', '+8.000 canciones · Rumba crossover · Cócteles',
     'Reserva tu mesa', 'og-home.jpg')
make('mesa-ladrillo.webp', (0.7, 0.5), 'EL SARAO PUB · CARTA DIGITAL',
     'LA CARTA', 'para cantar mejor', 'Cócteles, cervezas, aguardiente, whisky y promos',
     'Ver precios', 'og-carta.jpg')
