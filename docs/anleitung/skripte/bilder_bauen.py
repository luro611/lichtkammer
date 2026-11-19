#!/usr/bin/env python3
"""Bereitet die Rohbilder (Bildschirmfotos) für die Anleitung auf: Hinweisbalken übermalen, zuschneiden, Rand, Markierungen,
Designs-Raster, verkleinern, Farben reduzieren, Metadaten weg. Aufruf: python3 bilder_bauen.py <Ordner mit shots/> <Zielordner>"""
import sys
from pathlib import Path
from PIL import Image, ImageDraw, ImageFont

SRC, OUT = Path(sys.argv[1]) / "shots", Path(sys.argv[2])
OUT.mkdir(parents=True, exist_ok=True)
ACC, BG = (199, 93, 59), (245, 242, 237)
JPEG = {"t_oeffentlich_warm", "t_oeffentlich_gruen"}
KEEP_BANNER = {"t_dashboard_token", "t_pubband"}
CROP = {"t_editor": (0, 620, None, 1740), "t_pubband": (0, 41, None, 150), "t_system": (0, 0, None, 900), "t_protokoll": (0, 0, None, 700),
        "t_fotos_liste": (0, 0, None, 1250), "t_medien_liste": (0, 0, None, 1250), "t_dashboard": (0, 0, None, 1000)}
FONT = None
for f in ("/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf",):
    if Path(f).exists(): FONT = ImageFont.truetype(f, 15)
FONT = FONT or ImageFont.load_default()

def near(a, b, t=14): return all(abs(x - y) <= t for x, y in zip(a, b))
def marker(d, x, y, n):
    d.ellipse([x - 13, y - 13, x + 13, y + 13], fill=ACC); d.text((x, y), str(n), fill=(255, 253, 249), anchor="mm", font=FONT)
def frame(d, box, n=None):
    d.rounded_rectangle(box, radius=4, outline=ACC, width=3)
    if n: marker(d, box[0] - 4, box[1] - 4, n)

def save(im, n):
    w = 1100 if im.width > 1100 else im.width
    if im.width != w: im = im.resize((w, round(im.height * w / im.width)), Image.LANCZOS)
    clean = Image.new("RGB", im.size); clean.paste(im.convert("RGB"))   # keine Metadaten
    if n in JPEG: clean.save(OUT / f"{n}.jpg", quality=80, optimize=True)
    else: clean.quantize(colors=128, method=Image.Quantize.MEDIANCUT, dither=Image.Dither.NONE).save(OUT / f"{n}.png", optimize=True)

def pad(im, m=14):
    out = Image.new("RGB", (im.width + 2 * m, im.height + 2 * m), BG); out.paste(im, (m, m)); return out

n_done = 0
for p in sorted(SRC.glob("t_*.png")):
    n = p.stem
    if n.startswith("t_d_") or n in ("t_setup_form_leer",): continue
    im = Image.open(p).convert("RGB")
    if n not in KEEP_BANNER and im.width >= 1000 and near(im.getpixel((700, 20)), ACC, 20) and im.getpixel((100, 20)) != im.getpixel((700, 20)):
        ImageDraw.Draw(im).rectangle([230, 0, im.width, 41], fill=BG)
    if n == "t_editor":
        blk = im.crop((238, 1035, im.width, 1740)); save(pad(blk), "t_block_bildtext")
    if n in CROP:
        x0, y0, x1, y1 = CROP[n]; im = im.crop((x0, y0, x1 or im.width, min(y1 or im.height, im.height)))
    d = ImageDraw.Draw(im)
    if n == "t_dashboard":
        for i, box in enumerate([(12, 64, 217, 102), (12, 156, 217, 312), (12, 334, 217, 486), (12, 508, 217, 852), (12, 866, 217, 960)], 1): frame(d, box, i)
    if n not in ("t_dashboard", "t_dashboard_token", "t_setup_form", "t_enroll", "t_login", "t_totp", "t_nicht_verfuegbar", "t_oeffentlich_warm", "t_oeffentlich_gruen", "t_pubband"):
        im = pad(im)
    save(im, n); n_done += 1
# Designs-Raster 3 x 2
names = ["warm", "klar", "studio", "blau", "rot", "gruen"]
if all((SRC / f"t_d_{k}.png").exists() for k in names):
    tw, th = 560, 315; g = Image.new("RGB", (3 * tw + 4 * 10, 2 * th + 3 * 10), BG)
    for i, k in enumerate(names):
        t = Image.open(SRC / f"t_d_{k}.png").convert("RGB").crop((0, 41, 1280, 760)).resize((tw, th), Image.LANCZOS)
        g.paste(t, (10 + (i % 3) * (tw + 10), 10 + (i // 3) * (th + 10)))
    save(g, "t_designs")
print("fertig", n_done, len(list(OUT.iterdir())))
