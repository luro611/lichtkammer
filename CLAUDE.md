# Lichtkammer

Schlankes Open-Source-Foto-CMS für ausschließlich eigene Bilder (früherer Arbeitsname PHOTO-CMS). Erste Installation: br-foto.com, Plan in `docs/planung/03 Plan br-foto.md`. Admin, Bildverarbeitung und Veröffentlichen laufen in PHP direkt auf dem Webspace (all-inkl, nur FTPS) oder in Docker; öffentliche Seiten werden beim Veröffentlichen als fertiges HTML erzeugt.

## Stand

- **Phase:** Schritt 0 (Bestandsaufnahme) abgeschlossen, siehe `04 Bestandsaufnahme.md`. Als Nächstes Schritt 1 (Projektgerüst) aus `03 Plan br-foto.md`, Abschnitt 11.
- **Vor der Freigabe keinen Anwendungscode schreiben.** Jeder Schritt aus dem Prompt-Plan wird einzeln beauftragt, danach berichten und auf Freigabe warten.

## Planung

Liegt unter `docs/planung/`:

- `01 Konzept PHOTO-CMS.md`: Ausgangskonzept mit Architektur, Datenmodell, Themes, Bildpipeline, Roadmap und Prompt-Plan (Schritte 0 bis 10)
- `02 Offene Fragen.md`: Fragen und Antworten von Lutz (15.11.2026)
- `03 Plan br-foto.md`: aktueller Plan (alles auf all-inkl, PHP und SQLite, TOTP und Passkey), ersetzt Konzept 01 in Teilen; Abschnitt 4a: zwei Betriebsarten all-inkl und Docker
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

Designsprache von lurohome.de und rothe.it (`/Users/luro/Documents/Projekte/Webseiten/lurohome/src/style.css`), Schriften lokal. Ausnahme nur für das öffentliche Theme von br-foto: runde Rahmen im Apple-Stil statt 2 px (Entscheidung von Lutz, 15.11.2026). Marke luro (Wortmarke, Siegel kompakt) wie in der globalen CLAUDE.md. Logo-Entwürfe für BR-Foto: `~/Nextcloud/Documents/BR-FOTO/BR-Foto-Logo-Muster.pdf`, Auswahl offen.

## Regeln

- Keine privaten Fotos, Zugangsdaten, EXIF-Geheimnisse oder personenbezogenen Daten im Repository.
- Zugangsdaten nur in `.env`; im Repository nur `.env.example`.
- Originalbilder werden nie überschrieben.
- `krentzphotography.com` ist nur Designreferenz; Gestaltung, Texte, Assets und Code bleiben eigenständig.
