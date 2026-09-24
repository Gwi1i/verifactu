"""Portada para Gumroad y og:image (1280x720), sin sellos ni afirmaciones de certificación."""
import os, sys
sys.path.insert(0, os.path.dirname(__file__))
from PIL import Image, ImageDraw
from make_assets import font, horizontal_gradient, rounded_mark, BG, BG2, WHITE, CYAN, MUTED, GREEN

W, H = 1280, 720
img = horizontal_gradient(W, H, BG, BG2).convert("RGBA")
mark = rounded_mark(300)
img.alpha_composite(mark, (90, (H - 300) // 2 - 20))
d = ImageDraw.Draw(img)
x = 450
d.text((x, 150), "Gwii Invoice Hash", font=font(70, bold=True), fill=WHITE)
d.text((x, 240), "for WooCommerce", font=font(44), fill=CYAN)
lines = [
    "Huella SHA-256 encadenada y URL del QR de la AEAT",
    "en cada pedido completado (formato VeriFactu).",
    "",
    "Plugin GPLv2 gratuito. Este pago cubre soporte",
    "y actualizaciones cuando cambie la normativa.",
]
y = 330
for ln in lines:
    d.text((x, y), ln, font=font(26), fill=MUTED if ln else MUTED)
    y += 38
d.text((x, 560), "No remite registros a la AEAT. Sin afiliación con la AEAT ni con WooCommerce.", font=font(20), fill=MUTED)
d.rectangle((0, H - 6, W, H), fill=GREEN)
out = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "gumroad_cover.jpg")
img.convert("RGB").save(out, quality=92)
print("portada generada:", out)
