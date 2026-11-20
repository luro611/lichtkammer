# 47 Prüfung Code Teil E, Block 2 (Dateien in Blöcken: Detailfeld, Editor, Logo aus Medien)

Prüfung durch Opus am 20.11.2026. Geprüft: Commits `cef6d52`, `ceff097`, `5d877e2` (nach `3800de3`) gegen Entwurf 44 und die Berichte 45 und 46. Gelesen: der ganze Diff (PHP, Vorlagen, CSS, `page-editor.js`, `page-material.js`, `medien.js`). Suite: 1 789 Prüfungen, alle grün (auch nach meiner CSS-Änderung). Praktisch geprüft in einer Wegwerf-Instanz (Docker-Image aus dem Repo, Apache, Chromium mit echter Maus über Playwright). **Nichts hochgeladen, nichts gepusht, kein Server angefasst.**

## Gesamturteil

**Keine Befunde mit Risiko mittel oder höher.** Block 2 kann nach der Fable-Prüfung auf den Testserver. Ein Schönheitsfehler (Palettenkarte „Datei zum Herunterladen“ lief über den Rand) ist behoben, der Rest sind Hinweise.

## Geprüft

| Punkt | Ergebnis |
|---|---|
| Datenformat v:1 alter Blöcke | `Blocks.php` ist im Diff nicht berührt; Format byte-gleich wie in Bericht 46. Der Editor schickt `{v:1,blocks}` unverändert; `_`-Felder (`_fm`, `_r`, `_id`) fallen heraus. Im Browser: gespeichertes JSON = `{"t":"button",…,"href":"","file":1}` und `{"t":"download","file":1,"title":"","style":"normal"}`. |
| Ziehen mit echter Maus (Chromium) | Datei auf Knopf: `file` gesetzt, Beschriftung mit Dateiname vorbelegt. Datei auf Textblock: neuer Datei-Block davor (Abweichung vom Entwurf, dokumentiert und sinnvoll). Datei auf Datei-Block: ersetzt. Auf Einfügepunkt: neuer Block. Rückgängig (Knopf und Strg+Z) stellt jedes Mal den alten Zustand her, Blockzahl stimmt (Fehlerbild B1 aus Bericht 42 tritt nicht auf). |
| „Wohin?“ per Tastatur | Enter auf Kachel öffnet den Dialog, Einträge für Knopf, Datei-Block, am Ende, hinter Block N, Spalten; Auswahl funktioniert. Texte nur per `textContent`. |
| Dateidialog und Liste | Nur öffentliche, nicht gelöschte Dateien (privates `Zweite.pdf` erscheint nicht), keine Adresse in der Antwort, `no-store`, 60 je Seite, Suche mit maskierten Platzhaltern, SVG nur bei bestandenem Schutzkopf. Dateinamen werden nur per `textContent` und `setAttribute` ausgegeben (kein `innerHTML`); `editorPayload` nutzt `JSON_HEX_*`. |
| Detailfeld | Seite ohne Skript und JSON aus **einer** Funktion (`detailData`), Route nur lesend, angemeldet (303 ohne Anmeldung), `no-store`, ID streng als Ziffern. Speichern („Öffentlich/Privat“) läuft über die vorhandene POST-Route mit CSRF-Feld und Rolle des Wrappers; kein neuer Schreibweg. Im Browser geprüft: Seitenleiste, Esc, Fokus zurück. |
| Logo aus Medien | Kennung `f<Nummer>` mit `/D`, Eignung über Endung der **gespeicherten** Datei (svg, png, webp), `bytes` und zusätzlich beim Lesen höchstens `LOGO_MAX + 1` Byte, Pfad nur über `Files::absolute` (Mustergeprüft), Papierkorb und PDF ausgeschlossen, Fallback auf Seitenname plus Warnung (kein harter Fehler). Alte 16-stellige Logos und `Settings` ohne `Files` unverändert. |
| Live-Vorschau mit Download-Block | Vorschau zeigt Titel, Art, Größe und Link `/media/dateien/…` (ohne Vorschau-Präfix). Die Konsolenmeldungen „Blocked script execution“ sind die gewollte Sandbox des iframes. |
| Links im Probelauf (H2 aus 46) | `issueLinks` schlüsselt nach Meldung, Ausgabe maskiert, Adressen nur aus Konstanten und Zahlen. |

## Befunde und Hinweise

| Nr. | Risiko | Befund | Stand |
|---|---|---|---|
| N1 | niedrig | Palettenkarte „Datei zum Herunterladen“ lief im schmalen Seitenstreifen über den Rand. | **behoben** (`.epalcard{overflow-wrap:anywhere}`), im Browser gesehen vorher |
| H1 | Hinweis | `Usage` zählt Logo-Rollen als Verwendung auch beim **Privatstellen**; dort kommt dann eine überflüssige Rückfrage, weil ein Logo aus Medien nicht öffentlich sein muss. Beim Löschen ist die Rückfrage richtig. | offen, harmlos |
| H2 | Hinweis | Die Dateiliste im Editor prüft nicht, ob die öffentliche Kopie auf der Platte liegt. Fehlt sie, meldet der Bau „Kopie fehlt“ (Reparatur läuft beim Öffnen von Medien). Der Editor zeigt es erst nach dem Speichern. | offen |
| H3 | Hinweis | Das Detailfeld nennt den Benutzernamen des Hochladenden auch der Rolle Redaktion (N8 aus Bericht 45). | offen, Lutz entscheidet |
| H4 | Hinweis | Logos aus PNG/WebP können Metadaten tragen (N3 aus 45); jedes Logo steckt in jeder Seite. Die Begründung „kein JPEG“ im Entwurf ist unvollständig; Logos aus `data/brand/` fehlen weiter in der Sicherung (besteht schon), Logos aus Medien nicht. | offen |
| H5 | Hinweis | Nicht geprüft: Firefox, Safari, Touch, alle sechs Designs für die neuen Bedienelemente (Download-Block in Block 1 geprüft), echter Apache für die Auslieferung der Kopien. | Lutz bzw. Fable |

## Was soll Fable besonders prüfen

1. `Settings::mediaLogo`/`logoUri`: liest eine private Datei in jede Seite; Pfad, Größe, Endung gegen Inhalt, Data-URL-Typ (SVG als `<img>`).
2. `GET /admin/medien/detail` und `GET /admin/dateien/auswahl`: Rollen, keine Adresse privater Dateien, JSON-Ausgabe, Parameter (`q`, `seite`, `id`, `art`).
3. `Files::pickPublic` (Suche mit `LIKE`/ESCAPE, Seitenversatz, SVG-Selbsttest bei jeder Anfrage: Kosten).
4. Detailfeld: Formular „Öffentlich/Privat“ im JSON-Panel (CSRF-Token aus dem ersten `_csrf`-Feld der Seite, `ordner`).
5. Editor: `editorPayload` (`files`, Namen aus der Datenbank im JSON), `setFile`/`clearFile` und Undo.
6. Weiter offen aus Bericht 46: echter Apache, Kopien nach Reparatur.
