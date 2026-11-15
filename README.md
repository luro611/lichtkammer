# Lichtkammer

Schlankes Open-Source-Foto-CMS für ausschließlich eigene Bilder. PHP 8.3+ und SQLite, keine Datenbank, kein Composer und kein SSH auf dem Server nötig. Öffentliche Seiten werden als fertiges HTML ausgeliefert.

**Stand:** Version 0.1.0-dev, Projektgerüst (Schritt 1). Noch keine Anmeldung, keine Bildverwaltung.

Lizenz: AGPL-3.0-or-later (siehe `LICENSE`). Plan: `docs/planung/03 Plan br-foto.md`.

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
# Tests (PHP 8.5 im Container, kein lokales PHP nötig)
docker run --rm -v "$PWD":/app -w /app php:8.5-cli php tests/run.php

# Lokal starten
docker compose up --build        # http://localhost:8080
```

## Schriften und Design

Schriften liegen lokal in `public/assets/fonts/` (Instrument Serif, Inter, Archivo, Geist Mono, OFL-1.1, siehe `LIZENZEN.txt`). Die statischen OTF-Schnitte für Dokumente liegen in `otf/`. Farben und Schriften folgen der Designsprache von lurohome.de; Rahmen und Pillen sind bewusst runder (Apple-Stil).
