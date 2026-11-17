#!/usr/bin/env python3
"""Bereitet die Rohbilder (Bildschirmfotos) für die Anleitung auf: Hinweisbalken übermalen, zuschneiden,
Markierungen setzen, verkleinern, Farben reduzieren. Aufruf: python3 bilder_bauen.py <Ordner mit shots/> <Zielordner>"""
import sys
from pathlib import Path
from PIL import Image, ImageDraw, ImageFont

SRC, OUT = Path(sys.argv[1]) / "shots", Path(sys.argv[2])
OUT.mkdir(parents=True, exist_ok=True)
ACC, BG = (199, 93, 59), (245, 242, 237)
JPEG = {"t_oeffentlich_start", "t_oeffentlich_galerie", "t_oeffentlich_lightbox", "t_oeffentlich_handy", "t_oeffentlich_start2", "t_oeffentlich_galerie2", "t_oeffentlich_seite2", "t_oeffentlich_galerien", "t_vorschau_start", "t_oeffentlich_seite"}
CROP = {"t_fotos_leer": (0, 0, None, 640), "t_upload_laeuft": (0, 0, None, 680), "t_menue_vorher": (0, 0, None, 760), "t_menue_nachher": (0, 0, None, 760), "t_protokoll": (0, 0, None, 560), "t_seite_unten": (230, 0, None, None)}
KEEP_BANNER = {"t_dashboard_token"}
NAMES = ["t_nicht_verfuegbar", "t_setup_form", "t_enroll", "t_codes", "t_login", "t_totp", "t_dashboard_token", "t_dashboard", "t_design_rothe_dunkel", "t_design_luro_dunkel",
         "t_fotografen", "t_fotos_leer", "t_upload_laeuft", "t_fotos_liste", "t_fotos_auswahl", "t_mehrfach", "t_foto_detail", "t_galerien_leer", "t_galerie_neu",
         "t_galerien_liste", "t_galerie_edit", "t_galerie_fotos", "t_upload_galerie", "t_zuordnung_vorschau", "t_zuordnung_fertig", "t_papierkorb", "t_seiten_liste", "t_seite_kopf",
         "t_blockmenue", "t_fotoauswahl", "t_block_bildtext", "t_block_raster", "t_block_karussell", "t_block_spalten", "t_seite_gespeichert", "t_seite_unten", "t_start_vorbefuellt",
         "t_block_titelbild", "t_block_startseitenfotos", "t_block_kacheln", "t_start_gespeichert", "t_logos_zuordnen", "t_logos_fertig", "t_einstellungen", "t_menue_vorher", "t_menue_nachher",
         "t_vorschau_start", "t_veroeffentlichen_fehler", "t_fassungen", "t_oeffentlich_start", "t_oeffentlich_galerien", "t_oeffentlich_galerie", "t_oeffentlich_lightbox",
         "t_oeffentlich_seite2", "t_oeffentlich_handy", "t_konto", "t_protokoll", "t_konten", "t_import"]

def near(a, b, t=14): return all(abs(x - y) <= t for x, y in zip(a, b))

def marker(d, x, y, n):
    d.ellipse([x - 13, y - 13, x + 13, y + 13], fill=ACC)
    d.text((x, y), str(n), fill=(255, 253, 249), anchor="mm", font=FONT)

def frame(d, box, n=None):
    d.rounded_rectangle(box, radius=4, outline=ACC, width=3)
    if n: marker(d, box[0] - 4, box[1] - 4, n)

FONT = None
for f in ("/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf",):
    if Path(f).exists(): FONT = ImageFont.truetype(f, 15)
FONT = FONT or ImageFont.load_default()

for n in NAMES:
    p = SRC / f"{n}.png"
    if not p.exists(): print("FEHLT", n); continue
    im = Image.open(p).convert("RGB")
    if n not in KEEP_BANNER and im.width >= 1000 and near(im.getpixel((700, 20)), ACC, 20) and im.getpixel((100, 20)) != im.getpixel((700, 20)):
        ImageDraw.Draw(im).rectangle([230, 0, im.width, 41], fill=BG)
    if n in CROP:
        x0, y0, x1, y1 = CROP[n]; im = im.crop((x0, y0, x1 or im.width, y1 or im.height))
    d = ImageDraw.Draw(im)
    if n == "t_dashboard":
        frame(d, (12, 76, 217, 112), 1); frame(d, (12, 162, 217, 322), 2); frame(d, (12, 326, 217, 486), 3); frame(d, (12, 490, 217, 648), 4); frame(d, (12, 906, 217, 982), 5)
    if n == "t_veroeffentlichen_fehler":
        pass
    w = 1100 if im.width > 1100 else im.width
    if im.width != w: im = im.resize((w, round(im.height * w / im.width)), Image.LANCZOS)
    if n in JPEG: im.save(OUT / f"{n}.jpg", quality=80, optimize=True)
    else: im.quantize(colors=128, method=Image.Quantize.MEDIANCUT, dither=Image.Dither.NONE).save(OUT / f"{n}.png", optimize=True)
print("fertig", len(list(OUT.iterdir())))
