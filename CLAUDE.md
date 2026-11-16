# Lichtkammer

Schlankes Open-Source-Foto-CMS für ausschließlich eigene Bilder (früherer Arbeitsname PHOTO-CMS). Erste Installation: br-foto.com, Plan in `docs/planung/03 Plan br-foto.md`. Admin, Bildverarbeitung und Veröffentlichen laufen in PHP direkt auf dem Webspace (all-inkl, nur FTPS) oder in Docker; öffentliche Seiten werden beim Veröffentlichen als fertiges HTML erzeugt.

## Stand

- **Phase:** Schritt 0 (Bestandsaufnahme) ist erledigt, siehe `docs/planung/04 Bestandsaufnahme.md`. Schritt 1 (Projektgerüst) ist gebaut und geprüft. Schritt 2 (Anmeldung, Entwurf `05`, Gutachten `07`) ist gebaut (Version 0.1.0-dev) und von Fable geprüft (`08 Pruefung Code Anmeldung.md`, alle Befunde behoben, 288 Tests, abgenommen). Seit `1a52696` Designumschalter. Schritte 3 (Datenmodell und Bildpipeline, `09`), 4 (Bildverwaltung, Entwurf `10`) und 5 (öffentliche Seite, Entwurf `11`) sind gebaut, von Opus geprüft und auf dem Testserver (Stand 4f5c37d, 616 Prüfungen); Abnahme von Schritt 5 durch Lutz (Einstellungen, Vorschau, erstes Veröffentlichen) steht noch aus. **Reihenfolge (Lutz, 16.11.2026): Passkey (Schritt 6) kommt ganz zum Schluss, nach dem Livegang.** Seiten-Editor mit Blöcken (Entwurf `12`, freigegeben 16.11.2026) ist gebaut (Migration 5, `Site/Blocks.php`, `page-editor.js`, Tests in `tests/bloecke.php`), von Opus geprüft, Befunde behoben, auf dem Testserver. **Schritt 7 (Umzug von WordPress, `docs/planung/13`) ist gebaut** (Migration 6, `Import/Importer.php`, Admin „Import“, `tools/wp-export/`, Weiterleitungen über `_redirects.json`), Tests grün; Opus-Prüfung, Hochladen und Import auf dem Testserver warten auf Freigabe von Lutz. Nächste Schritte: 8 (Härtung und Livegang), dann 6 (Passkey), jeweils nach Freigabe von Lutz.
- **Jeder Schritt aus dem Plan wird einzeln beauftragt**, danach berichten und auf Freigabe warten.
- **Testserver:** `lichtkammer.br-foto.com` (all-inkl Konto A, HTTPS aktiv). Zugang nur in `deploy/.env` und im Schlüsselbund (Dienst `web-ftp-lichtkammer.br-foto.com`). Hochladen nur nach Probelauf und Freigabe von Lutz. Docker zuerst auf pve10.
- **Tests (kein lokales PHP):** einmal `docker build -t lichtkammer-test -f tests/Dockerfile tests` (jetzt mit GD, exif und Imagick, dauert ein paar Minuten), dann `docker run --rm -v "$PWD":/app -w /app lichtkammer-test php tests/run.php` (PHP 8.5 CLI mit `intl`, GD, exif, Imagick).
- **Admin-Konfiguration:** ohne `LK_APP_KEY` (`openssl rand -base64 32`), `LK_BASE_URL` und `LK_DATA_DIR` außerhalb des Webroots startet der Admin nicht. Beschreibung in `.env.example`.

## Planung

Liegt unter `docs/planung/`:

- `01 Konzept PHOTO-CMS.md`: Ausgangskonzept mit Architektur, Datenmodell, Themes, Bildpipeline, Roadmap und Prompt-Plan (Schritte 0 bis 10)
- `02 Offene Fragen.md`: Fragen und Antworten von Lutz (15.11.2026)
- `03 Plan br-foto.md`: aktueller Plan (alles auf all-inkl, PHP und SQLite, TOTP und Passkey, zwei Betriebsarten), ersetzt Konzept 01 in Teilen
- `04 Bestandsaufnahme.md`: gemessene Werte von WordPress und all-inkl; Abschnitt 4a: zwei Betriebsarten all-inkl und Docker
- `04 Bestandsaufnahme.md`: Serverwerte all-inkl und Bestand br-foto.com

## Projekt-Eckdaten

- **Name:** Lichtkammer (Entscheidung von Lutz, 15.11.2026). Repo [`luro611/lichtkammer`](https://github.com/luro611/lichtkammer), Ordner `~/Documents/Projekte/lichtkammer` (bis 15.11.2026 `PHOTO-CMS`).
- **Plattform:** GitHub unter `luro611/…` (privat bis zum Release). Nicht Codeberg.
- **Lizenz:** AGPL-3.0-or-later (Entscheidung von Lutz, 15.11.2026). Open Source für die Allgemeinheit.
- **Versionen:** 0.x bis zum öffentlichen Release, dann 1.0.0.
- **Ziele:** all-inkl.com (Testinstallation `lichtkammer.br-foto.com`) und Docker im LXC (zuerst pve10). Keine Lizenzkosten.
- **Nutzer:** Lutz (technisch, Einrichtung und Veröffentlichung) und eine zweite, nicht-technische Person (Projekte, Alben, Fotos).
- **Oberfläche:** Admin deutschsprachig, klar und fehlertolerant.
- **Stack:** PHP 8.3+ und SQLite, ohne Framework, ohne Composer auf dem Server. Keine Anbindung an die MediaDB.

## Media-DB (MediaDB)

- Pfad: `/Users/luro/Documents/Projekte/mediadb`. Python/FastAPI, mehrere getrennte SQLite-Dateien unter `data/` (`musik.db`, `filme.db`, `serien.db`, `buecher.db`, `verleih.db`).
- **Darf auf keinen Fall beschädigt werden.** Nur lesend analysieren; bestehende Tabellen, Spalten und Indizes bleiben unverändert.
- Keine Migration ohne vorher verifiziertes Backup und dokumentierten Rollback.

## Designsprache

Designsprache von lurohome.de und rothe.it (`/Users/luro/Documents/Projekte/Webseiten/lurohome/src/style.css`), Schriften lokal. Die Verwaltung hat einen Umschalter für zwei Designs (lurohome mit 2 px, rothe.it neutral und rund) und hell/dunkel (`public/assets/js/design.js`, `data-design`/`data-theme` am `<html>`, gemerkt in localStorage). Ausnahme nur für das öffentliche Theme von br-foto: runde Rahmen im Apple-Stil statt 2 px (Entscheidung von Lutz, 15.11.2026). Marke luro (Wortmarke, Siegel kompakt) wie in der globalen CLAUDE.md. BR-Foto-Logo: Variante B3 (offener Fokusrahmen, Terrakotta-Punkt, Wortmarke br.foto) gewählt von Lutz am 15.11.2026. SVG-Dateien (hell, dunkel, farbvariabel, App-Symbol für Favicon) in `~/Nextcloud/Documents/BR-FOTO/Logo-neu/`, Entwürfe als `BR-Foto-Logo-Muster.pdf` daneben. Gehört zur Installation br-foto, nicht in dieses Repo.

## Regeln

- Keine privaten Fotos, Zugangsdaten, EXIF-Geheimnisse oder personenbezogenen Daten im Repository.
- Zugangsdaten nur in `.env`; im Repository nur `.env.example`.
- Originalbilder werden nie überschrieben.
- `krentzphotography.com` ist nur Designreferenz; Gestaltung, Texte, Assets und Code bleiben eigenständig.
