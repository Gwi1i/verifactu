"""Genera icono y banners para WordPress.org sin sellos ni afirmaciones de certificación."""
import os
from PIL import Image, ImageDraw, ImageFont

OUT = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "wp-org-assets")
FONTS = r"C:\Windows\Fonts"

def font(size, bold=False):
    candidates = ["segoeuib.ttf", "arialbd.ttf", "calibrib.ttf"] if bold else ["segoeui.ttf", "arial.ttf", "calibri.ttf"]
    for c in candidates:
        p = os.path.join(FONTS, c)
        if os.path.exists(p):
            return ImageFont.truetype(p, size)
    return ImageFont.load_default()

BG = (10, 14, 23)
BG2 = (17, 24, 39)
BLUE = (59, 130, 246)
GREEN = (16, 185, 129)
CYAN = (6, 182, 212)
WHITE = (249, 250, 251)
MUTED = (156, 163, 175)

def lerp(a, b, t):
    return tuple(int(a[k] + (b[k] - a[k]) * t) for k in range(3))

def horizontal_gradient(w, h, c0, c1):
    img = Image.new("RGB", (w, h))
    strip = Image.new("RGB", (w, 1))
    strip.putdata([lerp(c0, c1, x / max(1, w - 1)) for x in range(w)])
    return img.paste(strip.resize((w, h)), (0, 0)) or img

def diagonal_gradient(size, c0, c1):
    img = Image.new("RGB", (size, size))
    data = []
    for y in range(size):
        for x in range(size):
            data.append(lerp(c0, c1, (x + y) / max(1, 2 * (size - 1))))
    img.putdata(data)
    return img

def rounded_mark(size):
    """Cuadrado redondeado con degradado azul-verde y un símbolo # blanco."""
    img = diagonal_gradient(size, BLUE, GREEN).convert("RGBA")
    d = ImageDraw.Draw(img)
    lw = max(3, size // 12)
    m = int(size * 0.26)
    for k in (0.40, 0.60):
        y = int(size * k)
        d.line([(m, y), (size - m, y)], fill=WHITE, width=lw)
        x = int(size * k)
        d.line([(x + lw // 2, m), (x - lw // 2, size - m)], fill=WHITE, width=lw)
    mask = Image.new("L", (size, size), 0)
    ImageDraw.Draw(mask).rounded_rectangle((0, 0, size - 1, size - 1), radius=size // 5, fill=255)
    img.putalpha(mask)
    return img

def icon(size):
    img = horizontal_gradient(size, size, BG, BG2).convert("RGBA")
    mark = rounded_mark(int(size * 0.72))
    img.alpha_composite(mark, ((size - mark.width) // 2, (size - mark.height) // 2))
    img.convert("RGB").save(os.path.join(OUT, f"icon-{size}x{size}.png"))

def banner(w, h):
    img = horizontal_gradient(w, h, BG, BG2).convert("RGBA")
    s = h / 250.0
    mark = rounded_mark(int(140 * s))
    img.alpha_composite(mark, (int(48 * s), (h - mark.height) // 2))
    d = ImageDraw.Draw(img)
    x = int(225 * s)
    d.text((x, int(48 * s)), "Gwii Invoice Hash", font=font(int(50 * s), bold=True), fill=WHITE)
    d.text((x, int(110 * s)), "for WooCommerce", font=font(int(30 * s)), fill=CYAN)
    d.text((x, int(160 * s)), "SHA-256 chained invoice hash + AEAT QR URL (VeriFactu format)", font=font(int(16 * s)), fill=MUTED)
    d.text((x, int(186 * s)), "Independent GPLv2 plugin. Not affiliated with AEAT or WooCommerce.", font=font(int(15 * s)), fill=MUTED)
    img.convert("RGB").save(os.path.join(OUT, f"banner-{w}x{h}.png"))

os.makedirs(OUT, exist_ok=True)
icon(128); icon(256)
banner(772, 250); banner(1544, 500)
print("assets generados en", OUT)
