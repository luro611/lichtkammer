# Lichtkammer

Schlankes Open-Source-Foto-CMS für ausschließlich eigene Bilder (früherer Arbeitsname PHOTO-CMS). Erste Installation: br-foto.com, Plan in `docs/planung/03 Plan br-foto.md`. Admin und Build laufen auf dem Homeserver, die öffentliche Seite wird statisch nach `dist/` erzeugt und inkrementell zu all-inkl.com übertragen.

## Stand

- **Phase:** Vorbereitung. Schritt 0 (Analyse und Architekturvorschlag) ist noch nicht begonnen.
- **Vor der Freigabe keinen Anwendungscode schreiben.** Jeder Schritt aus dem Prompt-Plan wird einzeln beauftragt, danach berichten und auf Freigabe warten.

## Planung

Liegt unter `docs/planung/`:

- `01 Konzept PHOTO-CMS.md`: Ausgangskonzept mit Architektur, Datenmodell, Themes, Bildpipeline, Roadmap und Prompt-Plan (Schritte 0 bis 10)
- `02 Offene Fragen.md`: Fragen, die vor oder in Schritt 0 zu klären sind
- `03 Plan br-foto.md`: aktueller Plan (alles auf all-inkl, PHP und SQLite, TOTP und Passkey), ersetzt Konzept 01 in Teilen

## Projekt-Eckdaten

- **Name:** Lichtkammer (Entscheidung von Lutz, 15.11.2026). Repo `luro611/lichtkammer`, Ordner und Repo werden nach Schritt 0 umbenannt.
- **Plattform:** GitHub unter `luro611/…` (privat bis zum Release). Nicht Codeberg.
- **Lizenz:** AGPL-3.0-or-later (Entscheidung von Lutz, 15.11.2026). Open Source für die Allgemeinheit.
- **Versionen:** 0.x bis zum öffentlichen Release, dann 1.0.0.
- **Ziele:** Homeserver-LXC und all-inkl.com. Keine Lizenzkosten.
- **Nutzer:** Lutz (technisch, Einrichtung und Veröffentlichung) und eine zweite, nicht-technische Person (Projekte, Alben, Fotos).
- **Oberfläche:** Admin deutschsprachig, klar und fehlertolerant.
- **Stack:** im Konzept PHP und SQLite; in Schritt 0 zu bestätigen (siehe offene Fragen, MediaDB und PermLens sind Python/FastAPI).

## Media-DB (MediaDB)

- Pfad: `/Users/luro/Documents/Projekte/mediadb`. Python/FastAPI, mehrere getrennte SQLite-Dateien unter `data/` (`musik.db`, `filme.db`, `serien.db`, `buecher.db`, `verleih.db`).
- **Darf auf keinen Fall beschädigt werden.** Nur lesend analysieren; bestehende Tabellen, Spalten und Indizes bleiben unverändert.
- Keine Migration ohne vorher verifiziertes Backup und dokumentierten Rollback.

## Designsprache

Für die Admin-Oberfläche gilt voraussichtlich dieselbe Designsprache wie MediaDB (`mediadb/static/style.css`, Schriften lokal aus `mediadb/static/fonts/`). Die öffentlichen Themes sind davon unabhängig. Marke „luro“ (Wortmarke in der Fußzeile, Siegel) beim Bau der Oberfläche anbieten, nicht ungefragt einbauen.

## Regeln

- Keine privaten Fotos, Zugangsdaten, EXIF-Geheimnisse oder personenbezogenen Daten im Repository.
- Zugangsdaten nur in `.env`; im Repository nur `.env.example`.
- Originalbilder werden nie überschrieben.
- `krentzphotography.com` ist nur Designreferenz; Gestaltung, Texte, Assets und Code bleiben eigenständig.
