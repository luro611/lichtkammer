# Schritt 7: Umzug von WordPress

Stand: 16.11.2026. Gebaut (Version 0.1.0-dev), Risiko laut Plan 03 „mittel“. WordPress wird nur gelesen, nie verändert. Nichts davon ist auf dem Testserver oder der echten Domain; Hochladen, Importieren und Umschalten warten auf Freigabe von Lutz.

## Entscheidungen von Lutz (16.11.2026)

1. **Alles zieht um**, nichts wird aussortiert (alle Galerien, alle Seiten, auch nicht verwendete Bilder landen in der Mediathek).
2. **Größte von WordPress verfügbare Auflösung**; bessere Originale vom NAS ersetzen später einzelne Fotos (Funktion „Foto ersetzen“).
3. **EXIF öffentlich** (Kamera, Objektiv, Aufnahmedaten): der Import schaltet bei jeder Galerie „EXIF zeigen“ ein. GPS wird nie gespeichert und nie gezeigt.
4. **Schreibweise der Fotografennamen** wie auf der alten Seite.

## Ablauf

1. **Export auf dem eigenen Rechner:** `python3 tools/wp-export/wp_export.py --quelle https://br-foto.com --ausgabe ~/lichtkammer-import/br-foto --fotograf "Name 1" --fotograf "Name 2"`. Liest nur über die offene REST-API, lädt die Bilder, schreibt das Paket **außerhalb des Repos**. `--probelauf` zählt nur, `--pruefen` prüft ein vorhandenes Paket gegen die Prüfsummen. Der Export kann abgebrochen und neu gestartet werden (fertige Bilder werden nicht noch einmal geladen).
2. **Paket auf den Server:** den Ordner (`manifest.json`, `bilder/`, `dateien/`) per FTPS in den Importordner legen. Auf all-inkl liegt er im Webroot (`LK_IMPORT_DIR=…/import` in `app/.env`); die App legt dort eine `.htaccess` mit „Require all denied“ an und `public/.htaccess` sperrt `import/` zusätzlich. Im Docker-Betrieb Standard: `<Datenordner>/import`.
3. **Admin → Verwaltung → Import** (nur Administratoren): „Import starten“. Der Browser stößt nacheinander an: ein Foto je Anfrage (Aufnahme mit Prüfung am Inhalt, Dublettenprüfung, Varianten wie bei jedem Upload), danach der Abschluss (Galerien, Seiten, Dateien, Weiterleitungen, Startbild). Bricht die Verbindung ab, macht „Fortsetzen“ an derselben Stelle weiter; Fertiges wird nie doppelt angelegt (`import_map`).
4. **Durchsicht** auf derselben Seite: Zahlen, Alt-Text-Lücken je Galerie und Seite, Fotos ohne Fotograf, fehlgeschlagene Fotos mit Grund. Danach Alt-Texte ergänzen (Galerie → Auswahl bearbeiten → Alt-Text für alle gleich oder einzeln), Fotografen setzen, Galerien und Seiten auf „veröffentlichen“ stellen und veröffentlichen.
5. **Paket löschen** (Knopf auf der Importseite, erst nach dem Abschluss): entfernt Bilder, PDF und `manifest.json` im Importordner. Fotos und alles Übernommene bleiben.

## Paketformat (Version 1)

`manifest.json` mit `photos` (WordPress-Nr., Datei, SHA-256, Name, Alt-Text, Titel, Beschriftung, Fotograf, Ort, Jahr), `galleries` (Titel, Beschreibung, Fotos in Reihenfolge, Titelbild, Ort, Jahr), `pages` (Adresse, Titel, Menü, `blocks` oder `body` als Markdown), `files` (PDF), `redirects`, `photographers`, `hero_wp`. Verweise auf Fotos sind WordPress-Nummern; der Importer ersetzt sie durch die neuen Nummern und lässt die Blöcke durch dieselbe Prüfung laufen wie im Editor (`Blocks::parse`). Das Paket gilt als nicht vertrauenswürdig: Dateipfade nur `bilder/<name>` und `dateien/<name>.pdf` (Zeichenliste, kein `..`, keine Symlinks), Prüfsumme je Datei, Feldlängen, Positivlisten.

## Was wie umgezogen wird

- **Galerien** (Featured Content und Portfolio): Titel, Beschreibung (Text neben dem Raster, z. B. „Aufnahmen mit Sony Alpha 7“), Reihenfolge, Titelbild (ausgewähltes Beitragsbild). Adresse neu aus dem Titel: `/galerien/island-2022/`. Ort und Jahr der Fotos aus dem Titel („Island 2022“ → Ort Island, Jahr 2022), nur wenn das Feld leer ist.
- **Seiten:** Seiten mit Bildern, Rastern oder Dateien werden **Blöcke** (Überschriften, Text mit **fett** und *kursiv*, Listen, Bilder mit Unterschrift, Raster zu je 12, Trennlinien, PDF als Knopf). Seiten nur mit Text bleiben **einfacher Text** (Markdown). Eine Seite, die nur ein großes Foto-Raster ist (80 Bilder), wird eine **Galerie** („Ausstellungsbilder“), weil ein Raster-Block höchstens 12 Fotos zeigt. `home` und `hero` sind WordPress-Gerüst; das Hero-Bild wird Startbild.
- **Fotos:** größte verfügbare Fassung (ungeskaltes Original, wenn WordPress es hat, sonst die Vollansicht). TIFF wird beim Export zu JPEG gewandelt (macOS `sips` oder ImageMagick), weil Lichtkammer kein TIFF liest. Titel und Beschriftung nur, wenn sie nicht aus dem Dateinamen stammen. **Fotograf** nur, wenn im Urheberfeld der Bilddatei genau eine der angegebenen Personen steht; bei „A & B“ und leer bleibt das Feld offen.
- **Alles als Entwurf.** Galerien und Seiten werden erst nach der Durchsicht veröffentlicht; die Alt-Text-Pflicht bleibt.
- **PDF-Dateien** (Flyer) liegen danach unter `/media/dokumente/<name>.pdf`.

## Weiterleitungen (301)

Tabelle `redirects` (Migration 6): alter Pfad → Ziel (Galerie, Seite, Startseite, Galerienübersicht oder fester Pfad). Beim Veröffentlichen steht die Liste als `_redirects.json` in der Fassung (die Auslieferung braucht weiter keine Datenbank). Regeln:

- nur Ziele, die in dieser Fassung stehen (Entwurf → keine Weiterleitung, die alte Adresse bleibt 404);
- eine Adresse, unter der es jetzt eine Seite gibt, wird nie umgeleitet;
- Groß-/Kleinschreibung und Schrägstrich am Ende egal, direkt ohne Umweg;
- das Ziel wird beim Ausliefern noch einmal auf erlaubte Zeichen geprüft (kein `//`, kein `..`, keine Steuerzeichen).

Beispiele: `/featured-content/island-2022/` → `/galerien/island-2022/`, `/portfolio/` → `/galerien/`, `/impressum/datenschutzerklaerung/` → `/datenschutzerklaerung/`, `/ueber-die-ausstellung/ausstellungsrundgang/` → `/galerien/ausstellungsbilder/`, `/wp-content/uploads/…/flyer.pdf` → `/media/dokumente/flyer.pdf`. Nicht abgedeckt: Adressen mit `?p=123`, Feeds, alte Bild-URLs (`/wp-content/uploads/…jpg`).

## Tests

`tests/import.php` (neue Prüfungen in `php tests/run.php`) und `tools/wp-export/test_wp_export.py` (`python3 -m unittest discover -s tools/wp-export`).
