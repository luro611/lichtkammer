# Lichtkammer

Schlankes Open-Source-Foto-CMS für ausschließlich eigene Bilder. PHP 8.3+ und SQLite, keine Datenbank, kein Composer und kein SSH auf dem Server nötig. Öffentliche Seiten werden als fertiges HTML ausgeliefert.

**Stand:** Version 0.1.0-dev, Projektgerüst und Anmeldung (Schritt 1 und 2: Passwort, TOTP, Wiederherstellungscodes, Einladung, Protokoll). Noch keine Bildverwaltung.

Lizenz: AGPL-3.0-or-later (siehe `LICENSE`). Plan: `docs/planung/03 Plan br-foto.md`.

## Installation

- **Webspace nur mit FTP:** das Zip `lichtkammer-<Version>.zip` vom GitHub-Release laden und der Anleitung `INSTALL.txt` darin folgen (Hochladen, `app/.env` ausfüllen, `/admin/setup`). Aktualisieren: neues Zip über die alte Installation hochladen; `app/.env`, Daten und Fotos sind nicht im Zip.
- **Docker:** `compose.yaml` mit dem Image `ghcr.io/luro611/lichtkammer:<Version>`. Aktualisieren: `docker compose pull && docker compose up -d`.
- Jede Version wird aus einem Tag (`vX.Y.Z`) automatisch als Zip und Image gebaut (`.github/workflows/release.yml`); Entwurf: `docs/planung/15 Entwurf Distribution.md`.

## Zwei Betriebsarten, ein Code

| | all-inkl (FTPS) | Docker (eigener LXC) |
|---|---|---|
| Code | `./deploy.sh` lädt `public/` in den Webroot und `app/` nach `/app` | `docker compose up -d --build` |
| Konfiguration | `app/.env` (nach `.env.example`) | Umgebungsvariablen / `.env` für Compose |
| Daten, Originale | über dem Webroot, per `LK_DATA_DIR` und `LK_ORIGINALS_DIR` | Volumes außerhalb des Webroots |
| HTTPS | durch den Webhoster | Reverse-Proxy, `LK_TRUST_PROXY=1` |

Alle Pfade stehen in `app/src/Config.php`, sonst nirgends.

## Entwicklung

```bash
# Tests (PHP 8.5 mit intl im Container, kein lokales PHP nötig)
docker build -t lichtkammer-test -f tests/Dockerfile tests
docker run --rm -v "$PWD":/app -w /app lichtkammer-test php tests/run.php

# Lokal starten (.env aus .env.example; LK_APP_KEY und LK_SETUP_TOKEN erzeugen, LK_BASE_URL=http://localhost:8080)
docker compose up --build        # http://localhost:8080, Einrichtung unter /admin/setup
```

## Schriften und Design

Schriften liegen lokal in `public/assets/fonts/` (Instrument Serif, Inter, Archivo, Geist Mono, OFL-1.1, siehe `LIZENZEN.txt`). Die statischen OTF-Schnitte für Dokumente liegen in `otf/`. Farben und Schriften folgen der Designsprache von lurohome.de; Rahmen und Pillen sind bewusst runder (Apple-Stil).
