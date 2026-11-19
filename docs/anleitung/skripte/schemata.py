#!/usr/bin/env python3
"""Zeichnet die Schemata der Anleitung als SVG (Farben der Designsprache, Text als Text, keine Emojis).
Aufruf: python3 schemata.py <Zielordner>"""
import sys
from pathlib import Path
from xml.sax.saxutils import escape as x

BG, INK, MUTED, LINE, ACC, SOFT, SURF = "#fbf8f4", "#242321", "#77736c", "#d9d3ca", "#c75d3b", "#eee8df", "#fffdf9"
SANS, MONO, SERIF = "Inter, sans-serif", "'Geist Mono', monospace", "'Instrument Serif', serif"

class Svg:
    def __init__(self, w, h):
        self.w, self.h, self.p = w, h, []
    def rect(self, x0, y0, w, h, fill=SURF, stroke=LINE, r=4, sw=1.2, dash=None):
        d = f' stroke-dasharray="{dash}"' if dash else ""
        self.p.append(f'<rect x="{x0}" y="{y0}" width="{w}" height="{h}" rx="{r}" fill="{fill}" stroke="{stroke}" stroke-width="{sw}"{d}/>')
    def text(self, x0, y0, s, size=15, fill=INK, font=SANS, weight=400, anchor="start", style="normal", ls=0):
        self.p.append(f'<text x="{x0}" y="{y0}" font-family="{font}" font-size="{size}" fill="{fill}" font-weight="{weight}" text-anchor="{anchor}" font-style="{style}" letter-spacing="{ls}">{x(s)}</text>')
    def line(self, x1, y1, x2, y2, stroke=LINE, sw=1.2, dash=None):
        d = f' stroke-dasharray="{dash}"' if dash else ""
        self.p.append(f'<line x1="{x1}" y1="{y1}" x2="{x2}" y2="{y2}" stroke="{stroke}" stroke-width="{sw}"{d}/>')
    def arrow(self, x1, y1, x2, y2, stroke=ACC, sw=2):
        self.line(x1, y1, x2, y2, stroke, sw)
        import math
        a = math.atan2(y2 - y1, x2 - x1); L = 10
        pts = [(x2, y2), (x2 - L * math.cos(a - 0.45), y2 - L * math.sin(a - 0.45)), (x2 - L * math.cos(a + 0.45), y2 - L * math.sin(a + 0.45))]
        self.p.append(f'<polygon points="{" ".join(f"{a_:.1f},{b_:.1f}" for a_, b_ in pts)}" fill="{stroke}"/>')
    def circle(self, cx, cy, r, fill=ACC):
        self.p.append(f'<circle cx="{cx}" cy="{cy}" r="{r}" fill="{fill}"/>')
    def box(self, x0, y0, w, h, title, sub=None, accent=False, mono=False):
        self.rect(x0, y0, w, h, fill=SOFT if accent else SURF, stroke=ACC if accent else LINE)
        ty = y0 + (h / 2 + 5 if not sub else h / 2 - 3)
        self.text(x0 + w / 2, ty, title, 16, INK, MONO if mono else SANS, 600, "middle")
        if sub:
            self.text(x0 + w / 2, ty + 20, sub, 12.5, MUTED, SANS, 400, "middle")
    def num(self, cx, cy, n):
        self.circle(cx, cy, 13); self.text(cx, cy + 5, str(n), 14, "#fffdf9", SANS, 700, "middle")
    def save(self, path):
        s = f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {self.w} {self.h}" role="img"><rect width="{self.w}" height="{self.h}" fill="{BG}"/>' + "".join(self.p) + "</svg>"
        Path(path).write_text(s, encoding="utf-8")

def tree(s, x0, y0, rows, size=15):
    """rows: (Tiefe, Name, Hinweis, betont)"""
    for i, (d, name, note, acc) in enumerate(rows):
        y = y0 + i * 26
        if d: s.line(x0 + (d - 1) * 24 + 8, y - 15, x0 + (d - 1) * 24 + 8, y - 4, LINE)
        if d: s.line(x0 + (d - 1) * 24 + 8, y - 4, x0 + d * 24 - 2, y - 4, LINE)
        s.text(x0 + d * 24, y, name, size, ACC if acc else INK, MONO, 600 if acc else 400)
        if note: s.text(x0 + d * 24 + 10 + len(name) * 9.1, y, note, 12.5, MUTED)

def ablauf(o):
    s = Svg(900, 330)
    s.text(450, 36, "Zwei Wege, ein Ergebnis", 26, INK, SERIF, 400, "middle")
    s.rect(30, 62, 410, 240, SURF); s.rect(460, 62, 410, 240, SURF)
    s.text(50, 92, "Webspace (nur FTP)", 17, ACC, SANS, 700); s.text(480, 92, "Docker (eigener Server)", 17, ACC, SANS, 700)
    for i, t in enumerate(["Zip herunterladen und prüfen", "Mit dem FTP-Programm hochladen", "app/.env ausfüllen", "/admin/setup im Browser öffnen"]):
        s.num(58, 128 + i * 42, i + 1); s.text(82, 133 + i * 42, t, 15)
    for i, t in enumerate(["Zip entpacken, .env anlegen", "docker compose up -d --build", "Reverse-Proxy mit HTTPS davorsetzen", "/admin/setup im Browser öffnen"]):
        s.num(488, 128 + i * 42, i + 1); s.text(512, 133 + i * 42, t, 15)
    s.save(o / "s_ablauf.svg")

def zip_aufbau(o):
    s = Svg(900, 330)
    s.text(450, 36, "Inhalt des Zip-Archivs", 26, INK, SERIF, 400, "middle")
    rows = [(0, "lichtkammer-0.1.0-rc1/", "", False), (1, "app/", "das Programm (gesperrt), nach /app hochladen", True), (1, "public/", "die Webseite, in den Webroot hochladen", True),
            (1, ".env.example", "Vorlage, wird zu app/.env", False), (1, "Docker-Dateien", "Dockerfile, compose.yaml, docker/: nur für Docker (Kapitel 3), nicht hochladen", False), (1, "INSTALL.txt, LICENSE, NOTICE, README.md, VERSION", "", False)]
    tree(s, 40, 90, rows, 16)
    s.rect(40, 290, 820, 1, LINE)
    s.text(40, 318, "Nicht enthalten: deine Einstellungen (app/.env), Datenbank, Originale und erzeugte Bilder. Beim Update bleiben sie unberührt.", 13, MUTED)
    s.h = 330; s.save(o / "s_zip.svg")

def webroot(o):
    s = Svg(900, 400)
    s.text(450, 36, "So sieht der Webroot nach dem Hochladen aus", 26, INK, SERIF, 400, "middle")
    rows = [(0, "Webroot  (z. B. fotos.example.org/)", "", False), (1, "index.php", "", False), (1, "admin/ , assets/ , media/ ...", "aus dem Ordner public", False),
            (1, "app/", "aus dem Ordner app, per .htaccess gesperrt", True), (2, "src/ , templates/ , migrations/", "", False), (2, ".env", "Ihre Einstellungen (neu angelegt)", True),
            (0, "lichtkammer-data/", "Datenbank (Pflicht, neben dem Webroot, nicht darin)", False), (0, "lichtkammer-originals/", "Originalfotos (Pflicht, neben dem Webroot)", False)]
    tree(s, 40, 90, rows, 15.5)
    s.rect(40, 320, 820, 56, SOFT, ACC)
    s.text(60, 345, "Wichtig: Die Datei heißt app/.env (im Ordner app), nicht .env im Webroot.", 14.5, INK, SANS, 600)
    s.text(60, 366, "Der Admin lehnt eine .env im Webroot ab.", 13, MUTED)
    s.save(o / "s_webroot.svg")

def envdatei(o):
    s = Svg(900, 330)
    s.text(450, 34, "app/.env: das Mindeste", 26, INK, SERIF, 400, "middle")
    s.rect(40, 54, 820, 190, "#242321", "#242321")
    lines = [("LK_BASE_URL=https://fotos.example.org", "#f6f0e8"), ("LK_SITE_NAME=Beispielfoto", "#f6f0e8"), ("LK_APP_KEY=<44 Zeichen, von der Seite „Admin nicht verfügbar“>", "#e47a55"), ("LK_SETUP_TOKEN=<48 Zeichen, ebenso>", "#e47a55"), ("LK_DATA_DIR=/www/htdocs/vXXXXXX/lichtkammer-data", "#f6f0e8"), ("LK_ORIGINALS_DIR=/www/htdocs/vXXXXXX/lichtkammer-originals", "#f6f0e8")]
    for i, (t, c) in enumerate(lines): s.text(62, 92 + i * 28, t, 14.5, c, MONO)
    for i, t in enumerate(["Adresse ohne Schrägstrich am Ende", "Name der Seite (im Admin und als Titel)", "Geheimer Schlüssel, sicher aufbewahren", "Nur für die Ersteinrichtung, danach Zeile löschen"]):
        s.num(40 + 20 + i * 0, 275, 0) if False else None
    s.text(40, 274, "Orange: Geheimwerte. Den Schlüssel im Passwort-Manager sichern, getrennt von der Datensicherung.", 13.5, MUTED)
    s.text(40, 298, "LK_DATA_DIR und LK_ORIGINALS_DIR sind Pflicht: zwei Ordner über dem Webroot (der Pfad kommt vom Anbieter).", 13.5, MUTED)
    s.save(o / "s_env.svg")

def docker(o):
    s = Svg(900, 360)
    s.text(450, 34, "Docker in drei Schritten", 26, INK, SERIF, 400, "middle")
    s.rect(40, 54, 820, 240, "#242321", "#242321")
    L = [("# 1. Zip entpacken, .env aus .env.example anlegen", "#b8aea4"), ("openssl rand -base64 32   # für LK_APP_KEY", "#f6f0e8"), ("openssl rand -hex 24      # für LK_SETUP_TOKEN", "#f6f0e8"),
         ("", "#f6f0e8"), ("# 2. Bauen und starten (im entpackten Ordner)", "#b8aea4"), ("docker compose up -d --build", "#e47a55"), ("", "#f6f0e8"), ("# 3. Im Browser /admin/setup öffnen", "#b8aea4")]
    for i, (t, c) in enumerate(L): s.text(62, 88 + i * 24, t, 14.5, c, MONO)
    s.text(40, 330, "Update später: neues Zip darüber entpacken, docker compose up -d --build", 14.5, INK, MONO)
    s.save(o / "s_docker.svg")

def proxy(o):
    s = Svg(900, 295)
    s.text(450, 34, "Docker hinter einem Reverse-Proxy", 26, INK, SERIF, 400, "middle")
    s.box(30, 90, 170, 80, "Browser", "https://fotos.example.org")
    s.box(280, 90, 190, 80, "Reverse-Proxy", "beendet HTTPS (Zertifikat)", True)
    s.box(550, 70, 320, 110, "Container lichtkammer", "Port 80 intern, nach außen nur 127.0.0.1:8080", True)
    s.arrow(200, 125, 278, 125); s.arrow(470, 125, 548, 125)
    s.text(239, 113, "HTTPS", 11.5, ACC, SANS, 600, "middle"); s.text(509, 113, "HTTP", 11.5, ACC, SANS, 600, "middle")
    s.rect(550, 205, 320, 56, SOFT, LINE); s.text(710, 228, "Volumes: Daten, Originale, Medien", 14, INK, SANS, 600, "middle"); s.text(710, 248, "bleiben bei Updates erhalten", 12.5, MUTED, SANS, 400, "middle")
    s.line(710, 180, 710, 205, LINE)
    s.text(30, 215, "In der .env:", 12.5, MUTED, SANS, 600); s.text(30, 236, "LK_BASE_URL=https://…", 12.5, MUTED, MONO); s.text(30, 256, "LK_TRUST_PROXY=1", 12.5, MUTED, MONO); s.text(30, 276, "LK_TRUSTED_PROXIES=<Proxy-Adresse>", 12.5, MUTED, MONO)
    s.save(o / "s_proxy.svg")

def update(o):
    s = Svg(900, 300)
    s.text(450, 34, "Aktualisieren", 26, INK, SERIF, 400, "middle")
    s.rect(30, 56, 410, 220, SURF); s.rect(460, 56, 410, 220, SURF)
    s.text(50, 86, "Webspace", 17, ACC, SANS, 700); s.text(480, 86, "Docker", 17, ACC, SANS, 700)
    for i, t in enumerate(["Sicherung: Datenordner und Originale laden", "Neues Zip entpacken", "public und app darüber hochladen", "Admin öffnen, einmal „Veröffentlichen“"]):
        s.num(58, 120 + i * 40, i + 1); s.text(82, 125 + i * 40, t, 14.5)
    for i, t in enumerate(["Sicherung der Volumes", "Neues Zip über den Ordner entpacken", "docker compose up -d --build", "Admin öffnen, einmal „Veröffentlichen“"]):
        s.num(488, 120 + i * 40, i + 1); s.text(512, 125 + i * 40, t, 14.5)
    s.save(o / "s_update.svg")

def veroeff(o):
    s = Svg(900, 230)
    s.text(450, 34, "Vom Entwurf zur Live-Seite", 26, INK, SERIF, 400, "middle")
    xs = [30, 250, 470, 690]
    for xx, (t, sub, a) in zip(xs, [("Bearbeiten", "Fotos, Galerien, Seiten", False), ("Vorschau", "nur für dich sichtbar", False), ("Veröffentlichen", "erzeugt fertiges HTML", True), ("Live", "Besucher sehen die Seite", False)]):
        s.box(xx, 70, 180, 80, t, sub, a)
    for xx in (210, 430, 650): s.arrow(xx, 110, xx + 38, 110)
    s.path = None
    s.line(780, 160, 780, 195, ACC, 1.5); s.line(780, 195, 560, 195, ACC, 1.5); s.arrow(560, 195, 560, 154, ACC, 1.5)
    s.text(670, 216, "Rückgängig: frühere Fassung zurückholen", 13, ACC, SANS, 600, "middle")
    s.save(o / "s_veroeffentlichen.svg")

def aufbau(o):
    s = Svg(900, 300)
    s.text(450, 34, "Wie Lichtkammer arbeitet", 26, INK, SERIF, 400, "middle")
    s.box(30, 80, 200, 90, "Admin (PHP)", "dort arbeitest du", True)
    s.box(350, 60, 200, 60, "SQLite-Datenbank", "Texte, Galerien, Seiten")
    s.box(350, 140, 200, 60, "Originale", "Fotos, nie verändert")
    s.box(670, 80, 200, 90, "Fertiges HTML", "und verkleinerte Bilder")
    s.arrow(230, 110, 348, 92); s.arrow(230, 140, 348, 170); s.arrow(550, 92, 668, 112); s.arrow(550, 170, 668, 142)
    s.text(450, 250, "Besucher bekommen nur fertige Dateien: schnell, sicher, ohne Datenbankzugriff.", 14.5, MUTED, SANS, 400, "middle")
    s.save(o / "s_aufbau.svg")

def ftp(o):
    s = Svg(900, 420)
    s.text(450, 34, "Typisches FTP-Programm (Schema)", 26, INK, SERIF, 400, "middle")
    s.rect(30, 56, 840, 340, SURF)
    s.rect(30, 56, 840, 34, SOFT, LINE); s.text(46, 78, "Verbindung: FTPS (verschlüsselt) · Host · Benutzer · Passwort", 13, MUTED, MONO)
    s.text(50, 116, "Dein Rechner", 14, ACC, SANS, 700); s.text(470, 116, "Server (Webroot der Domain)", 14, ACC, SANS, 700)
    s.line(450, 98, 450, 396, LINE)
    for i, (n, a) in enumerate([("lichtkammer-0.1.0-rc1/", False), ("  app/", True), ("  public/", True), ("  .env.example", False), ("  INSTALL.txt", False)]):
        s.text(50, 146 + i * 28, n, 14, ACC if a else INK, MONO)
    for i, (n, a) in enumerate([("index.php", False), ("admin/  assets/  media/", False), ("app/", True), ("  .env", True)]):
        s.text(470, 146 + i * 28, n, 14, ACC if a else INK, MONO)
    s.arrow(400, 200, 460, 200, ACC, 2.5); s.text(430, 190, "1", 14, ACC, SANS, 700, "middle")
    s.arrow(400, 228, 460, 228, ACC, 2.5); s.text(430, 258, "2", 14, ACC, SANS, 700, "middle")
    s.text(50, 330, "1  Inhalt von public in den Webroot ziehen", 13.5, INK); s.text(50, 352, "2  Ordner app daneben ziehen", 13.5, INK); s.text(50, 374, "3  .env aus der Vorlage anlegen und nach app/.env laden", 13.5, INK)
    s.save(o / "s_ftp.svg")

def wp(o):
    s = Svg(900, 260)
    s.text(450, 34, "Umzug von WordPress", 26, INK, SERIF, 400, "middle")
    xs = [30, 250, 470, 690]
    for xx, (t, sub, a) in zip(xs, [("Export", "Skript auf deinem Rechner", False), ("Paket hochladen", "per FTPS in den Importordner", False), ("Import im Admin", "Foto für Foto, fortsetzbar", True), ("Durchsehen", "Alt-Texte, Fotografen, veröffentlichen", False)]):
        s.box(xx, 70, 180, 90, t, sub, a)
    for xx in (210, 430, 650): s.arrow(xx, 115, xx + 38, 115)
    s.text(450, 215, "Alles landet zuerst als Entwurf. WordPress wird nur gelesen, nie verändert.", 14.5, MUTED, SANS, 400, "middle")
    s.save(o / "s_wordpress.svg")

if __name__ == "__main__":
    o = Path(sys.argv[1]); o.mkdir(parents=True, exist_ok=True)
    for f in (ablauf, zip_aufbau, webroot, envdatei, docker, proxy, update, veroeff, aufbau, ftp, wp): f(o)
    print("ok")
