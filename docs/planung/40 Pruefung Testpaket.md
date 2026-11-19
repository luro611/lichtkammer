# 40 Prüfung Testpaket 0.1.0-rc1 (Opus, 19.11.2026)

**Ergebnis: weitergeben ja, mit den hier schon behobenen Korrekturen und dem neu gebauten Paket.** Das alte Paket (Prüfsumme `b3cc78bb…`) bitte nicht mehr verwenden. Neues Paket: `dist/lichtkammer-testpaket-0.1.0-rc1.zip`, Prüfsumme `3d3dbc4b…f4f38` (Datei `.sha256` daneben). Zweimal gebaut, beide Male byte-gleich. Tests: 1 527 Prüfungen, 0 Fehler.

## 1. Datenschutz und Geheimnisse
Geprüft: Testpaket und inneres Zip entpackt (169 Dateien), alle Texte, PDF-Text, PDF-Metadaten, Zip-Attribute, versteckte Dateien, Mailadressen.
- Keine Schlüssel, Token, Passwörter, Gmail-Adresse, `/Users/`-Pfade, Heimnetz- oder Servernamen (`pve01`, `pve10`, `luroPVE`), keine echten Kundennummern, keine echten QR- oder Wiederherstellungscodes in den Bildern (Platzhalter erkennbar). Einzige Kontaktadresse: `info@lurohome.de` (plus Beispieladresse `name(at)beispiel.de`).
- PDF-Metadaten: nur Titel, Erzeuger WeasyPrint, Sprache. Kein Autor, kein Pfad. Zip: feste Zeitstempel, Rechte 644/755, keine fremden Benutzerkennungen.
- Versteckte Dateien sind nur die gewollten (`.htaccess`, `.user.ini`, `.env.example`, `.dockerignore`).
- **N1 (niedrig, behoben):** `tools/testpaket.sh` (nicht im Paket, aber im Repo) enthielt die echte Kundennummer im Prüfmuster. Jetzt allgemeines Muster. Beim Öffentlichmachen des Repos steht sie noch in der Git-Historie (Bericht 38/39, Weg B).
- **N2 (niedrig, behoben):** `compose.yaml` im Paket hatte interne Hinweise („Lutz bestätigt … Testserver“, „Entwurf 20“, „Schritt 8c“). Neutralisiert.
- **N3 (niedrig, offen, für den Release):** In Quelltext-Kommentaren des Software-Zips steht der Vorname „Lutz“ in „Entscheidung von Lutz …“ (`Auth/RateLimiter.php`, `design.js`, `site.css`, `app.css`) und der Name „br-foto“ (`site.css`, `app.css`, `Config.php`, `Site/Settings.php`, `.env.example` als Beispieldomains). Keine Geheimnisse; für externe Tester unkritisch, vor dem öffentlichen Release in die Liste aus Bericht 38.
- `NOTICE` und `Dockerfile` nennen `github.com/luro611/lichtkammer`; das Repo ist noch privat, der Link führt für Tester ins Leere. Absicht, aber Lutz sollte es wissen.

## 2. Inhalt
- Dateiliste des Testpakets genau wie erlaubt (Zip, `.sha256`, PDF, `INSTALL.txt`, `LIESMICH-Tester.txt`, `LICENSE`, `NOTICE`). Kein `docs/planung`, `CLAUDE.md`, `deploy`, `tests`, keine Berichte im Software-Zip. `LICENSE` AGPL-3.0, `NOTICE` nennt die Schriften (OFL-1.1). Prüfsumme des inneren Zips stimmt.
- **B1 (mittel, behoben):** `tools/INSTALL.txt` im Software-Zip nannte `LK_DATA_DIR` „empfohlen“. Tatsächlich startet der Admin ohne beide Ordner nicht (in Docker bestätigt über die Anleitung, auf Webspace vom Vorgänger geprüft). Jetzt „Pflicht“ mit beiden Ordnern.
- **B2 (niedrig, behoben):** Das `README.md` im Zip verwies auf `docs/planung/…` (nicht enthalten) und riet zu `docker compose pull` mit einem Image `ghcr.io/luro611/…`, das ein Tester nicht holen kann. Auf „bauen aus dem Ordner“ geändert.
- **B3 (niedrig, behoben):** Die äußere `INSTALL.txt` endete mit einem unklaren Satz („Frage bei … nach dem Zugang“). Entfernt.

## 3. Richtigkeit der Anleitung
Alle Namen von Menüpunkten und Knöpfen (rund 60) gegen Vorlagen und Code geprüft: stimmen. Zahlen stimmen mit dem Code (100 MB Foto, 30 Tage Papierkorb, 30 Minuten Leerlauf, 12 Stunden Sitzung, 24 Stunden Gerätevertrauen, 20 Fassungen, Menüs je Rolle). Sicherheitshinweise richtig (Sicherung nur auf verschlüsselten Datenträger, `LK_APP_KEY` getrennt, Token nach Einrichtung löschen).
- **B4 (niedrig, behoben):** Zwei Sätze in Ich-Form („das habe ich ausprobiert“, „Ich habe diesen Weg … ausprobiert“) – gehören nicht in eine Anleitung. Entfernt.
- **B5 (niedrig, behoben):** Die Rollenbeschreibung „Redaktion = Inhalte“ war zu knapp: Redaktion sieht auch Logos, Einstellungen, Papierkorb, Protokoll, Mein Konto. Genauer beschrieben.
- **B6 (niedrig, behoben):** Zeichen ☾ und ⠿ kamen in keiner der Schriften vor; die PDF bettete deshalb DejaVu Sans ein. Durch Worte ersetzt, jetzt nur noch Inter, Archivo, Instrument Serif, Geist Mono.
- **B7 (niedrig, behoben):** Wiederherstellung unter Docker: Es fehlte, wie der Sicherungsordner ins Volume kommt. Beispielbefehl (`docker compose cp`, `chown`) ergänzt. **Dieser Befehl ist nicht praktisch ausprobiert.**
- Offen (niedrig): HEIC geht nur, wenn der Server Imagick mit HEIC kann; die Anleitung nennt es als erlaubt, bei Fehlern kommt eine klare Meldung. Vor dem ersten Veröffentlichen zeigt die Startseite „Diese Seite ist noch nicht veröffentlicht“ (503), das erwartet ein Tester vielleicht nicht; ein Satz in Kapitel 2 und 3 würde helfen.
- Checkliste in `LIESMICH-Tester.txt` deckt die wichtigen Funktionen ab (Installation, Anmeldung, Medien, Galerien, Seiten, Designs, Veröffentlichen, Fassungen, Konten, Sicherung, Update).
- **Layout PDF (53 Seiten):** Titelseite mit Siegel, Inhaltsverzeichnis, Fußzeile mit Wortmarke, keine Leerseiten, keine abgeschnittenen Bilder auf den Seiten 1 bis 24 (Sichtprüfung). Seiten 25 bis 53 nur über Textmenge je Seite auf Leerseiten geprüft, nicht einzeln angesehen. Einige Seiten haben viel Weißraum, weil ein großes Bild nicht mehr passte; kein Fehler.

## 4. Praktische Probe
- **Docker** (frischer Ordner aus dem Zip, Port 18080): `docker compose up -d --build` baut, `/health` antwortet „ok“ mit Version, `/admin/setup` mit Token, Konto, App-Kopplung (Code selbst berechnet), Wiederherstellungscodes, Token-Hinweis, nach Löschen des Tokens und Neustart antwortet `/admin/setup` mit 404, Anmeldung mit Code, Systemprüfung (erwartete Hinweise „Adresse“ und „.user.ini“), Veröffentlichen, danach Startseite 200. Alles wie in der Anleitung.
- **Webspace-Nachbau** (Apache, `public` und `app` im Webroot, `app/.env` nur mit Adresse): „Admin nicht verfügbar“ zeigt die zwei fertigen Zeilen, `app/.env`, `app/src` und `.env.example` sind gesperrt (403). Die Einrichtung selbst nicht bis zum Ende gefahren (ohne HTTPS nicht möglich); das hat die Vorgänger-Sitzung belegt.
- Aufgeräumt: Container, Volumes und das gebaute Image dieser Probe entfernt; vorhandene fremde Images (`lichtkammer-test`, `lichtkammer:dev` usw.) nicht angefasst.

## Korrekturen
Alle oben mit „behoben“ markierten Punkte sind im Commit enthalten, das Paket ist neu gebaut. Offen für Lutz/später: N3, Satz zum 503 vor dem ersten Veröffentlichen, Docker-Befehl aus B7 einmal ausprobieren, Link auf das private Repository.
