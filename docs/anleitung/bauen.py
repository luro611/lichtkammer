#!/usr/bin/env python3
"""Baut Lichtkammer-Anleitung.pdf aus inhalt.html. Läuft im Container (siehe bauen.sh)."""
import re, sys, datetime, subprocess, shutil
from pathlib import Path
from html import escape

HERE = Path("/anleitung"); REPO = Path("/repo")
version = re.search(r"VERSION = '([^']+)'", (REPO / "app/src/Config.php").read_text()).group(1)
heute = datetime.date.today().strftime("%d.%m.%Y")
body = (HERE / "inhalt.html").read_text(encoding="utf-8")

def fig(m):
    attrs, text = m.group(1), m.group(2)
    b = re.search(r'b="([^"]+)"', attrs); s = re.search(r's="([^"]+)"', attrs); w = re.search(r'w="([^"]+)"', attrs)
    if s: return f'<figure class="sch"><img src="bilder/schemata/{s.group(1)}.svg" alt=""><figcaption>{text}</figcaption></figure>'
    f = next(iter((HERE / "bilder").glob(b.group(1) + ".*")), None)
    if f is None: sys.exit("Bild fehlt: " + b.group(1))
    return f'<figure class="{w.group(1) if w else ""}"><img src="bilder/{f.name}" alt=""><figcaption>{text}</figcaption></figure>'
body = re.sub(r"<fig ([^>]*)>(.*?)</fig>", fig, body, flags=re.S)

# Inhaltsverzeichnis aus h1/h2
toc, n = [], 0
def head(m):
    global n
    n += 1; tag, attrs, inner = m.group(1), m.group(2), m.group(3)
    hid = f"h{n}"
    plain = re.sub(r"<[^>]+>", "", inner)
    toc.append((tag, hid, plain))
    attrs = re.sub(r'\sid="[^"]*"', "", attrs)
    return f'<{tag} id="{hid}"{attrs}>{inner}</{tag}>'
body = re.sub(r"<(h[12])([^>]*)>(.*?)</h[12]>", head, body, flags=re.S)
items = "".join(f'<li class="{"l1" if t=="h1" else "l2"}"><a href="#{i}"><span class="t">{escape(p)}</span><span class="dots"></span></a></li>' for t, i, p in toc)

siegel = (HERE / "assets/luro-siegel-kompakt-hell.svg").read_text()
cover = f'''<section class="cover"><img class="siegel" src="assets/luro-siegel-kompakt-hell.svg" alt="">
<p class="eyebrow">Anleitung für Installation und Alltag</p>
<h1>Licht<em>kammer</em></h1>
<p class="sub">Das schlanke Foto-CMS für deine eigenen Bilder. Installieren, einrichten, Fotos zeigen: Schritt für Schritt, mit vielen Bildern.</p>
<div class="meta"><span>Version {version} · Stand {heute}</span><span class="luro">lu<em>ro</em></span><span>AGPL-3.0-or-later</span></div></section>'''
html = f'''<!doctype html><html lang="de"><head><meta charset="utf-8"><title>Lichtkammer – Anleitung</title><link rel="stylesheet" href="anleitung.css"></head>
<body data-ver="{version}">{cover}<section class="toc"><h1>Inhalt</h1><ul>{items}</ul></section>{body}</body></html>'''
(HERE / "_gebaut.html").write_text(html, encoding="utf-8")
from weasyprint import HTML
out = HERE / "Lichtkammer-Anleitung.pdf"
HTML(filename=str(HERE / "_gebaut.html"), base_url=str(HERE) + "/").write_pdf(str(out), optimize_images=True, jpeg_quality=80)
(HERE / "_gebaut.html").unlink()
print("PDF:", out, round(out.stat().st_size / 1e6, 1), "MB")
