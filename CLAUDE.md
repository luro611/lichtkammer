# Lichtkammer

Schlankes Open-Source-Foto-CMS für ausschließlich eigene Bilder (früherer Arbeitsname PHOTO-CMS). Erste Installation: br-foto.com, Plan in `docs/planung/03 Plan br-foto.md`. Admin, Bildverarbeitung und Veröffentlichen laufen in PHP direkt auf dem Webspace (all-inkl, nur FTPS) oder in Docker; öffentliche Seiten werden beim Veröffentlichen als fertiges HTML erzeugt.

## Stand

- **Phase:** Schritt 0 (Bestandsaufnahme) ist erledigt, siehe `docs/planung/04 Bestandsaufnahme.md`. Schritt 1 (Projektgerüst) ist gebaut und geprüft. Schritt 2 (Anmeldung, Entwurf `05`, Gutachten `07`) ist gebaut (Version 0.1.0-dev) und von Fable geprüft (`08 Pruefung Code Anmeldung.md`, alle Befunde behoben, 288 Tests, abgenommen). Seit `1a52696` Designumschalter. Schritte 3 (Datenmodell und Bildpipeline, `09`), 4 (Bildverwaltung, Entwurf `10`) und 5 (öffentliche Seite, Entwurf `11`) sind gebaut, von Opus geprüft und auf dem Testserver (Stand 4f5c37d, 616 Prüfungen); Abnahme von Schritt 5 durch Lutz (Einstellungen, Vorschau, erstes Veröffentlichen) steht noch aus. **Reihenfolge (Lutz, 16.11.2026): Passkey (Schritt 6) kommt ganz zum Schluss, nach dem Livegang.** Seiten-Editor mit Blöcken (Entwurf `12`, freigegeben 16.11.2026) ist gebaut (Migration 5, `Site/Blocks.php`, `page-editor.js`, Tests in `tests/bloecke.php`), von Opus geprüft, Befunde behoben, auf dem Testserver. **Schritt 7 (Umzug von WordPress, `docs/planung/13`) ist gebaut** (Migration 6, `Import/Importer.php`, Admin „Import“, `tools/wp-export/`, Weiterleitungen über `_redirects.json`), Tests grün; Import auf dem Testserver ist gelaufen (Lutz, 18.11.2026: Inhalte sind drin und stehen wie gewünscht); die Opus-Prüfung von Schritt 7 ist nicht belegt. Originale liegen laut Bericht 21 noch im Webroot (E19). **Medienverwaltung (Entwurf `16`, Prüfung `17`, freigegeben 18.11.2026) ist gebaut** (Migration 10, Blöcke A bis E und Teile von D und F, Tests in `tests/medien.php`), in drei Runden geprüft (Berichte `18`, `19` mit Abschnitt „Runde 3“), seit 18.11.2026 auf dem Testserver (Stand df27aba, 1 120 Prüfungen, Medienseite läuft laut Lutz). Vor dem Livegang: Browser-Test von Lutz und Fable-Prüfung von Dateiannahme und Auslieferung. Offen aus Entwurf 16: Detailfeld (D), Verknüpfungen „Knopf-Block wählt Datei“, „Logo aus Medien“, „Verwendet in“ (F), Einzel-Upload ohne Skript. Nächste Schritte: 8 (Härtung und Livegang), dann 6 (Passkey), jeweils nach Freigabe von Lutz.
- **Schritt 8 (Härtung und Livegang, Entwurf `20`, Prüfung `21`, alle Entscheidungen E1 bis E19 am 18.11.2026 freigegeben):** Teil 8a ist gebaut, von Opus geprüft (`22`, B1 bis B3 behoben, neue Klasse `Media/SafeMove`), **nichts auf dem Server** (Stand der Tests: 1 191 Prüfungen). Gebaut: Originale außerhalb des Webroots (`LK_ORIGINALS_DIR`, Startprüfung, automatischer Umzug `Media/OriginalsMover`, `docs/betrieb/Originale-verschieben.md`); Sicherung bei der Ersteinrichtung einspielen (`Backup/Restore`, `/admin/setup/wiederherstellen`, Bereitstellungsordner `restore/`, Originale zurückholen unter `/admin/wiederherstellung`, Format und Ablauf in `docs/betrieb/Wiederherstellung.md`); `LK_REDIRECT_HOSTS` (Hauptadresse `www.br-foto.com`, E4), `LK_REDIRECTS_PERMANENT` (M2); `.htaccess` (HTTP→HTTPS, Authorization, nosniff, mehr Sperren); `./deploy.sh --env-setzen` (M5); `tools/livepruefung.sh`; `docs/betrieb/Umstellung-Server.md` (Schritt-für-Schritt für Testserver und Livegang), `WordPress-haerten.md` (E3). **Noch nicht aus 8a:** Systemprüfung im Admin, neutrale Konfig-Fehlerseite (C6), `.user.ini`/`ErrorDocument`, COOP/CORP, Datenordner-Warnung, Docker-Härtung (`compose.yaml`), `NOTICE`. **Offen:** 8b (Sicherungsweg `/admin/sicherung`, liefert das Format aus `Wiederherstellung.md`, Fable-Prüfung), 8c bis 8i. Beim Livegang `Config::VERSION` auf `0.2.0` (E5).
- **Admin-Navigation und Designs (Entwurf `23`, 18.11.2026) sind gebaut, nichts auf dem Server, Opus-Prüfung steht aus:** Seitenleiste Inhalte / Seiten / Verwaltung, Seiten `Alle Seiten`, `Neue Seite`, `Menü & Fußzeile`; vier Designs Warm, Klar, Studio, Blau (hell/dunkel, `tools/kontrast.py`, alte Werte `lurohome`/`rothe` werden abgebildet).
- **Design der öffentlichen Seite wählbar (Entwurf `25`, 18.11.2026) ist gebaut, nichts auf dem Server, Opus-Prüfung steht aus:** Einstellung `public_design` (Warm/Klar/Studio/Blau/Rot/Grün, seit Nachtrag zu Entwurf 25 sechs Designs, Vorgabe Warm = bisheriges br-foto-Aussehen), steht beim Veröffentlichen als `data-design` im HTML; `site.css` mit allen Farbsätzen und Formen, Kontrast mit `tools/kontrast.py` (Admin und Seite). Bereits Veröffentlichtes ändert sich erst beim nächsten Veröffentlichen.
- **Seite per Ziehen zusammenbauen (Entwurf `29`, freigegeben 19.11.2026): Teile A, B, C sind gebaut** (Bausteinleiste, Einfügepunkte, Rückgängig, Ziehen und „Verschieben nach …“ zwischen Seite und Spalten; nur `page-editor.js`, `app.css`, Hilfetexte; Test angepasst, 1 239 Prüfungen grün), nichts auf dem Server, von Opus geprüft (Bericht `30`, B1 bis B6 behoben, offen N1 bis N6 mit Risiko niedrig). Offen: D (Seitenstreifen Fotos), E (Dateien, Feld `file`, zusammen mit Entwurf 16 F), F (Vorschau neben dem Editor). Entwurf 28 (Speichern/Veröffentlichen) berührt dieselben Dateien und kommt danach.
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

Designsprache von lurohome.de und rothe.it (`/Users/luro/Documents/Projekte/Webseiten/lurohome/src/style.css`), Schriften lokal. Die Verwaltung hat einen Umschalter für vier Designs (Warm = lurohome mit 2 px, Klar = neutral und rund, Studio = Anmutung wie apple.com, Blau) und hell/dunkel (`public/assets/js/design.js`, `data-design`/`data-theme` am `<html>`, gemerkt in localStorage). Ausnahme nur für das öffentliche Theme von br-foto: runde Rahmen im Apple-Stil statt 2 px (Entscheidung von Lutz, 15.11.2026). Marke luro (Wortmarke, Siegel kompakt) wie in der globalen CLAUDE.md. BR-Foto-Logo: Variante B3 (offener Fokusrahmen, Terrakotta-Punkt, Wortmarke br.foto) gewählt von Lutz am 15.11.2026. SVG-Dateien (hell, dunkel, farbvariabel, App-Symbol für Favicon) in `~/Nextcloud/Documents/BR-FOTO/Logo-neu/`, Entwürfe als `BR-Foto-Logo-Muster.pdf` daneben. Gehört zur Installation br-foto, nicht in dieses Repo.

## Regeln

- Keine privaten Fotos, Zugangsdaten, EXIF-Geheimnisse oder personenbezogenen Daten im Repository.
- Zugangsdaten nur in `.env`; im Repository nur `.env.example`.
- Originalbilder werden nie überschrieben.
- `krentzphotography.com` ist nur Designreferenz; Gestaltung, Texte, Assets und Code bleiben eigenständig.
