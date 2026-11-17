# Anleitung (PDF)

`Lichtkammer-Anleitung.pdf` ist die ausführliche Anleitung für Installation (Webspace per FTP, Docker), Update und den Admin, mit Bildern. Sie gehört nicht ins Release-Zip (das nimmt nur `app/`, `public/` und Beilagen).

## PDF neu bauen

Docker wird gebraucht (WeasyPrint und Pillow laufen im Container, nichts wird lokal installiert):

```bash
docs/anleitung/bauen.sh
```

Das Skript baut das Bauimage, installiert die statischen OTF-Schriften aus `otf/` im Container und schreibt `Lichtkammer-Anleitung.pdf`. Version (aus `app/src/Config.php`) und Datum kommen automatisch auf die Titelseite.

## Aufbau

- `inhalt.html`: der Text. Bilder: `<fig b="name">Unterschrift</fig>` (Bildschirmfoto aus `bilder/`), `<fig s="name">…</fig>` (Schema aus `bilder/schemata/`).
- `anleitung.css`: Gestaltung (Designsprache lurohome.de, Schriften Instrument Serif, Inter, Archivo, Geist Mono).
- `bauen.py`, `bauen.sh`, `Dockerfile`: Bauwerkzeug.
- `assets/`: luro-Siegel.
- `bilder/`: fertig aufbereitete Bildschirmfotos (PNG/JPEG) und `schemata/` (SVG).
- `skripte/`: `schemata.py` (zeichnet die SVG-Schemata), `demofotos.py` (erzeugt die neutralen Beispielbilder), `bilder_bauen.py` (Rohbilder zuschneiden, Hinweisbalken übermalen, Markierungen, verkleinern), `shotlib.py` und `pw_run.sh` (Hilfen für die Bildschirmfotos mit Playwright).

## Bildschirmfotos neu machen

Die Bildschirmfotos stammen aus einer frischen lokalen Instanz (`docker build -t lk-anleitung-test .`, Start mit eigenem Test-`.env`, `LK_BASE_URL=http://localhost:8080`, Daten **außerhalb** des Repos) und wurden mit Playwright (Chromium im Container, Fenster 1280 Pixel breit) aufgenommen. Die Testwerte (Schlüssel, Token, Passwort, Authenticator-Geheimnis) sind Wegwerfwerte und stehen nirgends im Repo; QR-Code, Schlüssel und Wiederherstellungscodes sind auf den Bildern unkenntlich gemacht. Beispielfotos: `skripte/demofotos.py <Ordner>`. Alle Fotos und Namen („Beispielfoto“) sind frei erfunden.
