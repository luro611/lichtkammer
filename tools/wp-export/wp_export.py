#!/usr/bin/env python3
"""
Lichtkammer: Importpaket aus einer WordPress-Seite erzeugen (Schritt 7, Umzug).

Liest NUR über die offene REST-API (GET) und lädt öffentliche Bilddateien herunter. WordPress selbst wird nicht
verändert, es gibt keine Anmeldung. Das Paket landet in einem Ordner AUSSERHALB des Repositorys:

    <ordner>/manifest.json   Galerien, Seiten (als Blöcke), Fotos mit Feldern, Weiterleitungen
    <ordner>/bilder/…        größte verfügbare Fassung jedes Bildes
    <ordner>/dateien/…       PDF-Dateien (z. B. Flyer)

Danach den Ordner (per FTPS) in den Importordner der Lichtkammer-Installation legen und im Admin unter
„Import“ verarbeiten. Siehe docs/planung/13 Umzug von WordPress.md.

Aufruf (Beispiel):
    python3 tools/wp-export/wp_export.py --quelle https://br-foto.com --ausgabe ~/lichtkammer-import/br-foto \
        --fotograf "Vorname Nachname" --fotograf "Vorname2 Nachname2"

    --probelauf   nur lesen und zählen, nichts herunterladen oder schreiben
    --pruefen     vorhandenes Paket gegen die Prüfsummen prüfen

Nur Python-Standardbibliothek. Keine Zugangsdaten, keine Personendaten im Code: Namen der Fotografen kommen per --fotograf.
"""
import argparse
import hashlib
import html
import json
import os
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from concurrent.futures import ThreadPoolExecutor
from html.parser import HTMLParser

UA = "lichtkammer-export/0.1 (+nur lesend)"
IMG_EXT = ("jpg", "jpeg", "png", "webp")
MAX_GALLERY_BLOCK = 12  # Blocks.MAX_PHOTOS in der Lichtkammer


# --------------------------------------------------------------------------- HTTP

def http(url, binary=False, tries=4, accept_404=False):
    last = None
    for i in range(tries):
        try:
            safe_url = urllib.parse.quote(url, safe=":/?&=%#~+@!$'()*,;[]")  # Sonderzeichen im Dateinamen
            req = urllib.request.Request(safe_url, headers={"User-Agent": UA})
            with urllib.request.urlopen(req, timeout=60) as r:
                data = r.read()
                return (data if binary else data.decode("utf-8")), r.headers
        except urllib.error.HTTPError as e:
            if e.code == 404 and accept_404:
                return None, None
            last = e
            if e.code in (400, 401, 403, 404, 410):
                break
        except Exception as e:  # noqa: BLE001 - Netzfehler: erneut versuchen
            last = e
        time.sleep(1.5 * (i + 1))
    raise RuntimeError(f"{url}: {last}")


def api_all(base, route, fields=None):
    out, page = [], 1
    while True:
        q = f"per_page=100&page={page}" + (f"&_fields={fields}" if fields else "")
        body, h = http(f"{base}/wp-json/wp/v2/{route}?{q}")
        out += json.loads(body)
        pages = int(h.get("X-WP-TotalPages", "1"))
        if page >= pages:
            return out
        page += 1
        time.sleep(0.15)


# --------------------------------------------------------------------------- HTML-Baum

VOID = {"br", "img", "hr", "input", "meta", "link", "source"}


class Node:
    def __init__(self, tag, attrs=None):
        self.tag = tag
        self.attrs = dict(attrs or [])
        self.kids = []
        self.parent = None

    def cls(self):
        return (self.attrs.get("class") or "").split()

    def text(self):
        return "".join(k if isinstance(k, str) else k.text() for k in self.kids)

    def walk(self):
        for k in self.kids:
            if isinstance(k, Node):
                yield k
                yield from k.walk()


class Tree(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.root = Node("root")
        self.cur = self.root

    def handle_starttag(self, tag, attrs):
        n = Node(tag, attrs)
        n.parent = self.cur
        self.cur.kids.append(n)
        if tag not in VOID:
            self.cur = n

    def handle_startendtag(self, tag, attrs):
        n = Node(tag, attrs)
        n.parent = self.cur
        self.cur.kids.append(n)

    def handle_endtag(self, tag):
        if tag in VOID:
            return
        n = self.cur
        while n is not self.root and n.tag != tag:
            n = n.parent
        if n is not self.root:
            self.cur = n.parent

    def handle_data(self, data):
        self.cur.kids.append(data)


def parse_html(s):
    t = Tree()
    t.feed(s)
    t.close()
    return t.root


# --------------------------------------------------------------------------- Hilfen

def norm_ws(s):
    return re.sub(r"[ \t\r\n ​]+", " ", s)


def url_path(u):
    p = urllib.parse.urlparse(u)
    return urllib.parse.unquote(p.path)


def stem_key(path):
    """Schlüssel eines Bildpfads ohne Größen-, -scaled- und Bearbeitungs-Endungen."""
    p = re.sub(r"\.[A-Za-z0-9]+$", "", path.lower())
    p = re.sub(r"-e\d{9,}$", "", p)
    p = re.sub(r"-scaled$", "", p)
    p = re.sub(r"-\d+x\d+$", "", p)
    return p


def safe_name(name):
    name = urllib.parse.unquote(name)
    name = re.sub(r"[^A-Za-z0-9._-]+", "_", name).strip("._") or "datei"
    return name[:120]


def pdf_name(name):
    """Dateiname eines PDFs: sicher, Endung immer .pdf (klein), auch bei langen Namen."""
    base = safe_name(name)
    if base.lower().endswith(".pdf"):
        base = base[:-4]
    return base.strip("._")[:110] + ".pdf" if base.strip("._") else "datei.pdf"


def title_clean(s):
    return norm_ws(html.unescape(re.sub(r"<[^>]+>", "", s or ""))).strip()


def _norm_name(x):
    x = x.replace("\u00d7", "x").lower()
    x = re.sub(r"\.[a-z0-9]{2,4}$", "", x)
    return re.sub(r"[-_\s]+", " ", x).strip()


def meaningful_title(title, filename):
    """WordPress setzt als Titel den Dateinamen; nur echte, später vergebene Titel übernehmen."""
    t = title_clean(title)
    if not t:
        return ""
    a, b = _norm_name(t), _norm_name(re.sub(r"-scaled(?=\.|$)", "", filename))
    if a == b or a.startswith(b) or b.startswith(a) or t.lower().startswith("http"):
        return ""
    if re.fullmatch(r"[\w .-]+\.(jpe?g|png|tiff?|webp)", t, re.I):
        return ""
    return t[:200]


def meaningful_caption(caption):
    c = title_clean(caption)
    if not c or c.lower().startswith("http") or "continue reading" in c.lower():
        return ""
    return c[:1000]


def tiff_to_jpeg(data):
    """TIFF -> JPEG mit sips (macOS) oder ImageMagick; None, wenn kein Werkzeug da ist."""
    import shutil
    import subprocess
    import tempfile
    with tempfile.TemporaryDirectory() as d:
        src, dst = d + "/in.tif", d + "/out.jpg"
        open(src, "wb").write(data)
        if shutil.which("sips"):
            cmd = ["sips", "-s", "format", "jpeg", "-s", "formatOptions", "95", src, "--out", dst]
        elif shutil.which("magick"):
            cmd = ["magick", src, "-quality", "95", dst]
        else:
            return None
        try:
            subprocess.run(cmd, check=True, capture_output=True, timeout=120)
            return open(dst, "rb").read()
        except Exception:  # noqa: BLE001
            return None


# --------------------------------------------------------------------------- Medien

class Media:
    def __init__(self, items, base):
        self.base = base
        self.byid = {}
        self.byurl = {}
        self.bykey = {}
        for m in items:
            md = m.get("media_details") if isinstance(m.get("media_details"), dict) else {}
            rec = {
                "id": m["id"], "mime": m.get("mime_type", ""), "source_url": m.get("source_url", ""),
                "alt": (m.get("alt_text") or "").strip(), "title": (m.get("title") or {}).get("rendered", ""),
                "caption": (m.get("caption") or {}).get("rendered", ""), "md": md,
            }
            self.byid[m["id"]] = rec
            urls = [rec["source_url"]]
            if md.get("original_image") and rec["source_url"]:
                urls.append(rec["source_url"].rsplit("/", 1)[0] + "/" + md["original_image"])
            for s in (md.get("sizes") or {}).values():
                urls.append(s.get("source_url", ""))
            for u in urls:
                if u:
                    self.byurl.setdefault(url_path(u).lower(), m["id"])
                    self.bykey.setdefault(stem_key(url_path(u)), set()).add(m["id"])

    def find(self, url):
        if not url:
            return None
        p = url_path(url).lower()
        if p in self.byurl:
            return self.byurl[p]
        ids = self.bykey.get(stem_key(url_path(url)))
        if ids and len(ids) == 1:
            return next(iter(ids))
        return None

    def candidates(self, rec):
        """Download-Kandidaten, größte Fassung zuerst: ungeskaltes Original, Vollbild, dann Größen absteigend."""
        md, src = rec["md"], rec["source_url"]
        c = []
        if md.get("original_image") and src:
            c.append(("original", src.rsplit("/", 1)[0] + "/" + md["original_image"]))
        if src:
            c.append(("full", src))
        sizes = sorted((md.get("sizes") or {}).values(), key=lambda s: -int(s.get("width") or 0))
        for s in sizes:
            if s.get("source_url"):
                c.append(("size", s["source_url"]))
        seen, out = set(), []
        for kind, u in c:
            ext = u.rsplit(".", 1)[-1].lower().split("?")[0]
            if ext in IMG_EXT and u not in seen:
                seen.add(u)
                out.append((kind, u))
        return out


def sniff(data):
    if data[:3] == b"\xff\xd8\xff":
        return "jpg"
    if data[:8] == b"\x89PNG\r\n\x1a\n":
        return "png"
    if data[:4] == b"RIFF" and data[8:12] == b"WEBP":
        return "webp"
    if data[:5] == b"%PDF-":
        return "pdf"
    return ""


# --------------------------------------------------------------------------- Inhalt → Blöcke

class Converter:
    def __init__(self, media, site_host, gallery_paths, page_paths, files):
        self.media = media
        self.host = site_host
        self.gallery_paths = gallery_paths  # alter Pfad (klein, ohne / am Ende) -> neuer Pfad
        self.page_paths = page_paths
        self.files = files  # alter Pfad -> Dateiname (Platzhalter lk-file:)
        self.used = []  # wp-Medien-IDs in Reihenfolge des Auftretens
        self.problems = []

    def _use(self, mid):
        if mid not in self.used:
            self.used.append(mid)

    def link(self, href):
        if not href:
            return None
        href = html.unescape(href).strip()
        if href.startswith(("mailto:", "tel:", "#")):
            return href if not href.startswith("tel:") else None
        p = urllib.parse.urlparse(href)
        if p.scheme in ("http", "https") and p.netloc.replace("www.", "") == self.host.replace("www.", ""):
            path = urllib.parse.unquote(p.path).lower().rstrip("/")
            if path in self.files:
                return "lk-file:" + self.files[path]
            if path in self.gallery_paths:
                return self.gallery_paths[path]
            if path in self.page_paths:
                return self.page_paths[path]
            if re.search(r"\.(jpe?g|png|webp|tiff?|gif)$", path):
                return None  # Link auf Bilddatei: wird zur Lightbox, kein Link nötig
            return (urllib.parse.unquote(p.path) or "/") + (("#" + p.fragment) if p.fragment else "")
        if p.scheme in ("http", "https"):
            return href
        if href.startswith("/") and not href.startswith("//"):
            return href
        return None

    # --- Fließtext
    def inline(self, node):
        out = []
        for k in node.kids:
            if isinstance(k, str):
                out.append(norm_ws(k))
                continue
            t = k.tag
            if t == "br":
                out.append("\n")
            elif t in ("strong", "b"):
                out.append(self._wrap("**", self.inline(k)))
            elif t in ("em", "i"):
                out.append(self._wrap("*", self.inline(k)))
            elif t == "a":
                inner = self.inline(k)
                href = self.link(k.attrs.get("href", ""))
                if href and inner.strip():
                    inner = inner.strip()
                    out.append(f"[{inner.replace(']', ')').replace('[', '(')}]({href})")
                else:
                    out.append(inner)
            elif t in ("img", "script", "style", "object", "figure"):
                continue
            else:
                out.append(self.inline(k))
        s = "".join(out)
        s = re.sub(r" *\n *", "\n", s)
        return s

    @staticmethod
    def _wrap(mark, s):
        if not s.strip():
            return s
        lead = s[: len(s) - len(s.lstrip())]
        trail = s[len(s.rstrip()):]
        core = s.strip()
        if core.startswith(mark) and core.endswith(mark):  # doppelt gemoppelt
            return s
        return f"{lead}{mark}{core}{mark}{trail}"

    # --- Blöcke
    def blocks(self, nodes):
        out, para = [], []

        def flush():
            if para:
                text = "\n\n".join(para).strip()
                para.clear()
                # lange Texte an Absatzgrenzen teilen (Blocks: höchstens 20000 Zeichen)
                while len(text) > 19000:
                    cut = text.rfind("\n\n", 0, 19000)
                    cut = cut if cut > 0 else 19000
                    out.append({"t": "text", "text": text[:cut].strip()})
                    text = text[cut:].strip()
                if text:
                    out.append({"t": "text", "text": text})

        for n in nodes:
            if isinstance(n, str):
                if n.strip():
                    para.append(norm_ws(n).strip())
                continue
            t, c = n.tag, n.cls()
            if re.fullmatch(r"h[1-6]", t):
                flush()
                txt = self.inline(n).strip()
                txt = re.sub(r"^\*\*(.*)\*\*$", r"\1", txt, flags=re.S)  # ganze Überschrift fett: unnötig
                if txt.strip():
                    out.append({"t": "heading", "level": 2 if t in ("h1", "h2") else 3, "text": txt.strip()})
            elif t == "p":
                imgs = [x for x in n.walk() if x.tag == "img"]
                rl = any("rl-gallery-container" in x.cls() for x in n.walk())
                if rl or imgs:
                    # Bilder/Galerien in Absätzen: Text davor/danach getrennt behandeln
                    flush()
                    out += self.blocks(n.kids)
                    continue
                txt = self.inline(n).strip()
                if txt:
                    para.append(txt)
            elif t in ("ul", "ol"):
                items = []
                for i, li in enumerate([x for x in n.kids if isinstance(x, Node) and x.tag == "li"], 1):
                    s = re.sub(r"\s*\n\s*", " ", self.inline(li)).strip()
                    if s:
                        items.append((f"{i}. " if t == "ol" else "- ") + s)
                if items:
                    para.append("\n".join(items))
            elif t == "hr":
                flush()
                out.append({"t": "rule"})
            elif t == "table":
                rows = []
                for tr in [x for x in n.walk() if x.tag == "tr"]:
                    cells = [re.sub(r"\s*\n\s*", " ", self.inline(td)).strip() for td in tr.kids if isinstance(td, Node) and td.tag in ("td", "th")]
                    cells = [x for x in cells if x]
                    if cells:
                        rows.append("- " + " – ".join(cells))
                if rows:
                    para.append("\n".join(rows))
            elif "rl-gallery-container" in c:
                flush()
                out += self.rl_gallery(n)
            elif t == "figure" and "wp-block-gallery" in c:
                flush()
                ids = []
                for im in [x for x in n.walk() if x.tag == "img"]:
                    mid = self._img_id(im)
                    if mid:
                        ids.append(mid)
                out += self.gallery_blocks(ids)
            elif t == "figure" or "wp-block-image" in c:
                flush()
                out += self.image_block(n)
            elif t == "img":
                flush()
                out += self.image_block(n)
            elif "wp-block-file" in c:
                flush()
                a = [x for x in n.walk() if x.tag == "a" and "wp-block-file__button" not in x.cls()]
                if a:
                    href = self.link(a[0].attrs.get("href", ""))
                    label = norm_ws(a[0].text()).strip() or "Datei"
                    if href:
                        out.append({"t": "button", "label": (label + " (PDF)")[:80], "href": href})
            elif t in ("script", "style", "object", "noscript"):
                continue
            else:  # div, section, columns …: Inhalt flach übernehmen
                flush()
                out += self.blocks(n.kids)
        flush()
        return out

    def _img_id(self, im):
        for c in im.cls():
            m = re.fullmatch(r"wp-image-(\d+)", c)
            if m and int(m.group(1)) in self.media.byid:
                return int(m.group(1))
        mid = self.media.find(im.attrs.get("src", ""))
        if mid is None:
            self.problems.append("Bild nicht gefunden: " + im.attrs.get("src", "?"))
        return mid

    def image_block(self, n):
        ims = [x for x in ([n] if n.tag == "img" else n.walk()) if x.tag == "img"]
        out = []
        for im in ims:
            mid = self._img_id(im)
            if mid is None:
                continue
            self._use(mid)
            cap = ""
            for f in n.walk() if n.tag != "img" else []:
                if f.tag == "figcaption":
                    cap = re.sub(r"\s*\n\s*", " ", self.inline(f)).strip()
            href = None
            a = im.parent
            while a is not None and a is not n.parent:
                if a.tag == "a":
                    href = self.link(a.attrs.get("href", ""))
                    break
                a = a.parent
            blk = {"t": "image", "wp": mid, "alt": html.unescape(im.attrs.get("alt", "")).strip(), "caption": re.sub(r"[*]", "", cap)[:300]}
            if href and not href.startswith("lk-file:"):
                blk["href"] = href
            out.append(blk)
        return out

    def rl_gallery(self, n):
        ids = []
        for a in [x for x in n.walk() if x.tag == "a" and "rl-gallery-link" in x.cls()]:
            mid = self.media.find(a.attrs.get("href", ""))
            if mid is None:
                self.problems.append("Galeriebild nicht gefunden: " + a.attrs.get("href", "?"))
            elif mid not in ids:
                ids.append(mid)
        return self.gallery_blocks(ids)

    def gallery_blocks(self, ids):
        out = []
        for i in range(0, len(ids), MAX_GALLERY_BLOCK):
            chunk = ids[i:i + MAX_GALLERY_BLOCK]
            for m in chunk:
                self._use(m)
            out.append({"t": "gallery", "photos": [{"wp": m} for m in chunk], "cols": 3})
        return out


def blocks_to_markdown(blocks):
    parts = []
    for b in blocks:
        if b["t"] == "heading":
            parts.append("#" * b["level"] + " " + b["text"].replace("\n", " "))
        elif b["t"] == "text":
            parts.append(b["text"])
    return "\n\n".join(parts).strip()


def gallery_ids(root, media):
    ids = []
    for n in root.walk():
        if n.tag == "a" and "rl-gallery-link" in n.cls():
            mid = media.find(n.attrs.get("href", ""))
            if mid is not None and mid not in ids:
                ids.append(mid)
    return ids


def split_title(title):
    m = re.match(r"^(.*?)[\s]+((?:19|20)\d{2})(?:[/_.\-]\d{1,4})?(?:\s*[|].*)?$", title.strip())
    if m and m.group(1).strip():
        return m.group(1).strip(), int(m.group(2))
    return "", None


# --------------------------------------------------------------------------- Hauptablauf

def main():
    ap = argparse.ArgumentParser(description="Importpaket aus WordPress (nur lesend)")
    ap.add_argument("--quelle", required=True, help="Adresse der WordPress-Seite, z. B. https://br-foto.com")
    ap.add_argument("--ausgabe", default=os.path.expanduser("~/lichtkammer-import/wordpress"), help="Zielordner (außerhalb des Repos)")
    ap.add_argument("--fotograf", action="append", default=[], help="Name eines Fotografen (mehrfach). Wird im Urheber-Feld der Bilder gesucht.")
    ap.add_argument("--probelauf", action="store_true", help="nur lesen und zählen, nichts herunterladen oder schreiben")
    ap.add_argument("--pruefen", action="store_true", help="vorhandenes Paket gegen die Prüfsummen prüfen")
    ap.add_argument("--parallel", type=int, default=4)
    a = ap.parse_args()
    base = a.quelle.rstrip("/")
    out = os.path.abspath(os.path.expanduser(a.ausgabe))
    here = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))
    if out == here or out.startswith(here + os.sep):
        sys.exit("Der Zielordner darf nicht im Repository liegen.")
    host = urllib.parse.urlparse(base).netloc

    if a.pruefen:
        return verify(out)

    print("Lese Medienliste …")
    media = Media(api_all(base, "media", "id,mime_type,source_url,alt_text,title,caption,media_details"), base)
    print(f"  {len(media.byid)} Mediendateien")
    print("Lese Seiten und Galerien …")
    pages = api_all(base, "pages")
    types = {"featured-content": api_all(base, "featured-content"), "portfolio": api_all(base, "jetpack-portfolio")}
    try:
        info = json.loads(http(base + "/wp-json")[0])
    except Exception:  # noqa: BLE001
        info = {}

    # Dateien (PDF) aus der Mediathek
    files = {}
    file_recs = []
    for rec in media.byid.values():
        if rec["mime"] == "application/pdf" and rec["source_url"]:
            name = pdf_name(os.path.basename(url_path(rec["source_url"])))
            files[url_path(rec["source_url"]).lower().rstrip("/")] = name
            file_recs.append({"wp": rec["id"], "name": name, "url": rec["source_url"], "old_path": url_path(rec["source_url"])})
    for p in pages:  # PDF-Links, die nicht in der Mediathek stehen
        for m in re.finditer(r'href="([^"]+\.pdf)"', p["content"]["rendered"], re.I):
            u = html.unescape(m.group(1))
            key = url_path(u).lower().rstrip("/")
            if key not in files and urllib.parse.urlparse(u).netloc.replace("www.", "") == host.replace("www.", ""):
                name = pdf_name(os.path.basename(url_path(u)))
                files[key] = name
                file_recs.append({"wp": 0, "name": name, "url": u, "old_path": url_path(u)})

    # Alte Adressen -> neue Adressen (Galerien erhalten ihren Titel als Adresse; hier nur Platzhalter, der Import löst auf)
    gallery_paths, page_paths = {}, {}
    new_gal = {}
    for kind, items in types.items():
        for it in items:
            slug = it["slug"]
            old = url_path(it["link"]).lower().rstrip("/")
            gallery_paths[old] = "lk-gallery:" + str(it["id"])
            new_gal[it["id"]] = kind
    # Seite, die nur ein großes Foto-Raster ist, wird eine Galerie (Raster-Blöcke zeigen höchstens 12 Fotos)
    page_as_gallery = {}
    for p in pages:
        root = parse_html(p["content"]["rendered"])
        if len(gallery_ids(root, media)) > MAX_GALLERY_BLOCK:
            page_as_gallery[p["id"]] = True
            gallery_paths[url_path(p["link"]).lower().rstrip("/")] = "lk-gallery:" + str(p["id"])
    for p in pages:
        if p["id"] not in page_as_gallery:
            page_paths[url_path(p["link"]).lower().rstrip("/")] = "lk-page:" + p["slug"]

    conv = Converter(media, host, gallery_paths, page_paths, files)
    used_by = {}  # wp-media-id -> erste Galerie (Ort/Jahr)
    galleries, out_pages, redirects = [], [], []

    def add_gallery(src, kind):
        root = parse_html(src["content"]["rendered"])
        ids = gallery_ids(root, media)
        title = title_clean(src["title"]["rendered"])
        # Text außerhalb der Raster als Beschreibung
        blocks = conv.blocks(root.kids)
        text_blocks = [b for b in blocks if b["t"] in ("heading", "text")]
        desc = blocks_to_markdown(text_blocks) if any(b["t"] == "text" for b in text_blocks) else ""
        place, year = split_title(title)
        cover = src.get("featured_media") or None
        g = {"wp": src["id"], "kind": kind, "title": title, "description": desc, "photos": ids,
             "cover": cover if cover in ids else None, "place": place, "year": year, "date": src["date"][:10], "old_path": url_path(src["link"])}
        galleries.append(g)
        for i in ids:
            used_by.setdefault(i, g)
        redirects.append({"from": url_path(src["link"]), "kind": "gallery", "wp": src["id"]})

    for kind, items in types.items():
        for it in items:
            add_gallery(it, kind)
    for p in pages:
        if p["id"] in page_as_gallery:
            add_gallery(p, "page")

    skip = {"home", "hero"}
    order = 0
    for p in pages:
        if p["id"] in page_as_gallery:
            continue
        if p["slug"] in skip:
            redirects.append({"from": url_path(p["link"]), "kind": "home"})
            continue
        order += 1
        root = parse_html(p["content"]["rendered"])
        blocks = conv.blocks(root.kids)
        has_media = any(b["t"] in ("image", "gallery", "button") for b in blocks)
        rec = {"wp": p["id"], "slug": p["slug"], "title": title_clean(p["title"]["rendered"]), "old_path": url_path(p["link"]), "order": order,
               "nav": "footer" if re.search(r"impressum|datenschutz", p["slug"]) else "main"}
        if has_media:
            rec["blocks"] = blocks
        else:
            rec["body"] = blocks_to_markdown(blocks) if all(b["t"] in ("heading", "text", "rule") for b in blocks) else ""
            if any(b["t"] == "rule" for b in blocks):
                rec["body"] = "\n\n".join("---" if b["t"] == "rule" else ("#" * b["level"] + " " + b["text"] if b["t"] == "heading" else b["text"]) for b in blocks)
        out_pages.append(rec)
        redirects.append({"from": url_path(p["link"]), "kind": "page", "slug": p["slug"]})
    for arch in ("/featured-content/", "/portfolio/"):
        redirects.append({"from": arch, "kind": "galleries"})
    for f in file_recs:
        redirects.append({"from": f["old_path"], "kind": "file", "name": f["name"]})

    # Fotografen und Fotos
    known = [n.strip() for n in a.fotograf if n.strip()]

    def photographer(rec):
        im = rec["md"].get("image_meta") or {}
        val = html.unescape(" ".join(str(im.get(k) or "") for k in ("copyright", "credit"))).lower()
        hit = [n for n in known if n.lower() in val]
        return hit[0] if len(hit) == 1 else None

    # alle Medien, die Bilder sind (auch nicht verwendete: nichts wird aussortiert)
    photos, skipped = [], []
    referenced = set(conv.used)
    for g in galleries:
        referenced.update(g["photos"])
    hero_ids = []
    for p in pages:
        if p["slug"] in skip and p.get("featured_media"):
            hero_ids.append(p["featured_media"])
    for mid, rec in media.byid.items():
        if rec["mime"] == "application/pdf":
            continue
        cands = media.candidates(rec)
        if not cands and rec["mime"] == "image/tiff" and rec["source_url"]:
            cands = [("tiff", rec["source_url"])]  # wird lokal nach JPEG gewandelt (die Lichtkammer liest kein TIFF)
        if not cands:
            skipped.append({"wp": mid, "reason": "kein lesbares Format (" + rec["mime"] + ")", "url": rec["source_url"]})
            continue
        fname = os.path.basename(url_path(rec["source_url"]))
        g = used_by.get(mid)
        photos.append({
            "wp": mid, "name": fname, "alt": rec["alt"], "title": meaningful_title(rec["title"], fname),
            "caption": meaningful_caption(rec["caption"]), "photographer": photographer(rec),
            "place": g["place"] if g else "", "year": g["year"] if g else None, "used": mid in referenced,
            "_cands": cands,
        })

    print(f"  {len(galleries)} Galerien, {len(out_pages)} Seiten, {len(photos)} Bilder, {len(file_recs)} Dateien")
    if skipped:
        print(f"  {len(skipped)} Medien ohne lesbares Bildformat: " + ", ".join(s['url'].rsplit('/', 1)[-1] for s in skipped[:5]))
    if conv.problems:
        print(f"  {len(conv.problems)} Hinweise zu Bildern in Texten, z. B. {conv.problems[0]}")
    noalt = sum(1 for p in photos if not p["alt"])
    print(f"  Alt-Text fehlt bei {noalt} von {len(photos)} Bildern")
    if a.probelauf:
        print("Probelauf: nichts geschrieben.")
        return

    # Herunterladen
    os.makedirs(out + "/bilder", exist_ok=True)
    os.makedirs(out + "/dateien", exist_ok=True)

    def fetch(p):
        for kind, url in p["_cands"]:
            target = f"{out}/bilder/{p['wp']}-{safe_name(os.path.basename(url_path(url)))}"
            if os.path.isfile(target) and os.path.getsize(target) > 0:
                data = open(target, "rb").read()
                return p, kind, target, data
            try:
                data, _ = http(url, binary=True, accept_404=True)
            except RuntimeError:
                continue
            if data is not None and kind == "tiff":
                data = tiff_to_jpeg(data)
                target = re.sub(r"\.[A-Za-z0-9]+$", ".jpg", target)
            if data is None or sniff(data) not in ("jpg", "png", "webp"):
                continue
            tmp = target + ".part"
            with open(tmp, "wb") as fh:
                fh.write(data)
            os.replace(tmp, target)
            return p, kind, target, data
        return p, None, None, None

    done = 0
    results = []
    with ThreadPoolExecutor(max_workers=max(1, min(a.parallel, 8))) as ex:
        for p, kind, target, data in ex.map(fetch, photos):
            done += 1
            if target is None:
                skipped.append({"wp": p["wp"], "reason": "Download fehlgeschlagen", "url": p["_cands"][0][1]})
            else:
                p["file"] = "bilder/" + os.path.basename(target)
                p["sha256"] = hashlib.sha256(data).hexdigest()
                p["bytes"] = len(data)
                p["source"] = kind
                results.append(p)
            if done % 50 == 0 or done == len(photos):
                print(f"  {done}/{len(photos)} Bilder", flush=True)
    for p in results:
        p.pop("_cands", None)

    fout = []
    for f in file_recs:
        data, _ = http(f["url"], binary=True, accept_404=True)
        if data is None or sniff(data) != "pdf":
            skipped.append({"wp": f["wp"], "reason": "PDF nicht ladbar", "url": f["url"]})
            continue
        with open(f"{out}/dateien/{f['name']}", "wb") as fh:
            fh.write(data)
        fout.append({"name": f["name"], "file": "dateien/" + f["name"], "sha256": hashlib.sha256(data).hexdigest(), "bytes": len(data), "old_path": f["old_path"]})

    results.sort(key=lambda p: p["wp"])
    manifest = {
        "format": "lichtkammer-import", "version": 1, "source": base, "created": int(time.time()),
        "site": {"name": info.get("name", ""), "description": info.get("description", "")},
        "photographers": known, "hero_wp": (hero_ids or [None])[0],
        "galleries": sorted(galleries, key=lambda g: (g["year"] or 0, g["date"]), reverse=True),
        "pages": out_pages, "photos": results, "files": fout, "redirects": redirects, "skipped": skipped,
    }
    # Fotos: nur Verweise auf vorhandene Dateien behalten
    have = {p["wp"] for p in results}
    for g in manifest["galleries"]:
        g["photos"] = [i for i in g["photos"] if i in have]
        if g["cover"] not in have:
            g["cover"] = None
    prune_blocks(out_pages, have)
    with open(out + "/manifest.json.part", "w", encoding="utf-8") as fh:
        json.dump(manifest, fh, ensure_ascii=False, indent=1)
    os.replace(out + "/manifest.json.part", out + "/manifest.json")
    total = sum(p["bytes"] for p in results)
    print(f"Fertig: {out}\n  {len(results)} Bilder ({total / 1048576:.0f} MB), {len(fout)} Dateien, {len(skipped)} übersprungen")


def prune_blocks(pages, have):
    for pg in pages:
        keep = []
        for b in pg.get("blocks", []):
            if b["t"] == "image" and b["wp"] not in have:
                continue
            if b["t"] == "gallery":
                b["photos"] = [x for x in b["photos"] if x["wp"] in have]
                if not b["photos"]:
                    continue
            keep.append(b)
        if "blocks" in pg:
            pg["blocks"] = keep


def verify(out):
    m = json.load(open(out + "/manifest.json", encoding="utf-8"))
    bad = 0
    for item in m["photos"] + m["files"]:
        path = os.path.join(out, item["file"])
        if not os.path.isfile(path) or hashlib.sha256(open(path, "rb").read()).hexdigest() != item["sha256"]:
            print("FEHLER:", item["file"])
            bad += 1
    print(f"{len(m['photos'])} Bilder, {len(m['files'])} Dateien geprüft, {bad} Fehler")
    sys.exit(1 if bad else 0)


if __name__ == "__main__":
    main()
