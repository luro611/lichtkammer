# Prüfung: Code Medienverwaltung, zweite Runde (Opus, Diff `110413b..945c6c7`)

Stand: 18.11.2026. Zweite, unabhängige Prüfung mit `/code-review max` auf Opus. Geprüft wurde der ganze Diff der Medienverwaltung (Bau `dc9c5bf`, `119b951`, `9766bc5`) samt den Korrekturen der ersten Prüfung (`945c6c7`, Bericht `18`). Der Bericht `18` wurde erst **nach** der eigenen Durchsicht gelesen. Grundlage: Entwurf `16`, Prüfung des Entwurfs `17`.

Vorgehen: eigene Durchsicht der Sicherheitskerne (Auslieferung mit Range und Kopfzeilen, Upload, Ordner, Papierkorb, Routen mit CSRF und Rollen, `medien.js`, Migration 10), dazu sieben unabhängige Finder (Zeile für Zeile, entfernte Prüfungen, Aufrufer über Dateigrenzen, Sprachfallen in PHP/SQLite/JS, Schichtgrenzen, Aufräumen, Auflagen und Konventionen). Vermutungen wurden in Docker gegen den echten Code belegt (PDF-Stichprobe, SVG-Angriffsfälle, Tests). Ausgangslage: 1 102 Prüfungen grün.


## Gesamturteil

**Der Sicherheitskern hält; die Funktion hatte mehrere echte Lücken, die jetzt behoben sind.** Kein Weg, eine Datei als Pfad zu missbrauchen, PHP oder HTML einzuschleusen oder ohne Anmeldung an private Dateien zu kommen. CSRF und Rollen stimmen auf allen neuen Routen, das Protokoll enthält keine Namen, Range 206/416 und Kopfzeilen sind richtig. Eine echte Lücke in der SVG-Positivliste (M1 unten) hat die erste Prüfung übersehen; sie ist durch Auslieferung als `<img>` bzw. mit CSP-`sandbox` heute entschärft, aber behoben. Dazu kamen mehrere Funktionsfehler mit mittlerem Risiko (PDF-Fehlablehnungen, stilles Löschen fertiger Ordner-Uploads, Ablegen im falschen Ordner, Datenverlust bei gleichzeitigem Löschen und Wiederherstellen). Nach den Korrekturen: **1 116 Prüfungen grün** (vorher 1 102).

## Befunde nach Risiko

### Mittel (alle behoben, mit Tests)

| Nr. | Befund | Fundstelle | Behebung |
|---|---|---|---|
| M1 | **SVG: `href` neben `xlink:href` wurde nie geprüft.** `iterator_to_array($el->attributes)` nimmt den lokalen Namen als Schlüssel; `href` und `xlink:href` überdecken sich, das erste blieb ungeprüft in der „bereinigten“ Datei (`<use href="https://…" xlink:href="#a"/>`, auch `data:`/`javascript:`). Betraf Medien-SVG und Logos; alte Logo-Prüfung war hier strenger. | `Media/SvgCheck.php` `walk()` | Liste ohne Schlüssel (`iterator_to_array(…, false)`), Test beider Reihenfolgen |
| M2 | **PDF: harmlose große PDFs wurden zufällig abgelehnt.** `/JS` und `/AA` kommen in komprimierten Bild- und Schriftdaten zufällig vor (etwa einmal je 10 MB). Gemessen: 5 MB Zufallsstrom 20 von 40 abgelehnt; ein Finder fand 16 echte System-PDFs mit Fehlalarm. | `Media/PdfCheck.php` | Kurze Namen zählen nur mit passendem Wert (Text, Hex-Text, Wörterbuch, Verweis), auch über Kommentare, NUL und Blockgrenzen; Überlappung 4 KB; Regex-Fehler lehnen ab statt durchzulassen. Danach 0 von 40. Tests für Fehlalarm und Tarnung. |
| M3 | **Plan-Aufräumen löschte fertige Ordner-Uploads.** `plan_items` blieben nach Erfolg stehen; nach 24 Stunden löschte der nächste Plan-Aufruf (beliebige Person) leere Ordner und leere Entwurfsgalerien hart, ohne Papierkorb und Protokoll (z. B. Ordner nur mit RAW-Dateien, Galerie mit Beschreibung). Widerspricht Entwurf 6 („leere Ordner werden mit angelegt“, „alles Fertige bleibt“). | `Admin/MedienPages.php` `planCleanup`/`planRemove` | Neue Route `POST /admin/medien/plan/fertig`; `medien.js` meldet fertige Läufe, Zeilen bleiben für die Stundenbegrenzung (`ref_id = 0`). Nur liegengelassene Pläne werden aufgeräumt. Test. |
| M4 | **Dateien auf einen Ordner im Inhaltsbereich ziehen lud in den aktuellen Ordner.** Der `drop`-Empfänger von `.mmain` lief vor dem Ordner-Empfänger. (Ein Finder hat es im Browser nachgestellt.) | `public/assets/js/medien.js` | Hauptbereich überlässt Ablagen auf Ordnern dem Ordner-Empfänger |
| M5 | **Endgültig löschen gegen gleichzeitiges Wiederherstellen.** Original (und öffentliche Kopie) wurden gelöscht, bevor bzw. obwohl das bedingte `DELETE` 0 Zeilen traf: lebende Zeile ohne Original. Neu erreichbar über Zweig-Löschen und das automatische Aufräumen. | `Media/Files.php` `purge`, `Media/Catalog.php` `purge` | Erst Zeile löschen (nur wenn noch im Papierkorb), dann Dateien; Variantenpfade vorher lesen (ON DELETE CASCADE) |
| M6 | **Ordner löschen ohne Hinweis auf die Live-Seite.** Varianten verschwinden sofort, die Live-Seite zeigt kaputte Bilder, die Meldung sagte nur „30 Tage wiederherstellbar“. Der Weg über „Fotos“ warnt. | `MedienPages.php` `medienTrash` | Hinweis „bitte neu veröffentlichen“, wenn betroffene Fotos live sind (`Builder::isLive`); ungenutzte Galerienliste entfernt. Rückfrage mit Zahlen vorher fehlt weiter (offen, O1). |
| M7 | **Zurückgeholte Fotos blieben auf „wartet“.** Nach dem Wiederherstellen eines Ordners ging es zum Papierkorb, der nichts aufbereitet; sofortiges Veröffentlichen ließ die Fotos still aus Galerien fallen. | `MedienPages.php` `medienTrashAction` | Bei wartenden Fotos Weiterleitung auf `/admin/medien` (dort läuft die Aufbereitung) |

### Niedrig (behoben)

- **N1** Endgültig löschen eines großen Ordners entfernte je Klick nur 200 Einträge ohne Hinweis. Jetzt in Stapeln bis zum Ende (Zeitgrenze 15 s). Test mit 230 Einträgen.
- **N2** `purgeRows`: Retten mit Zusatz „(gerettet n)“ lief bei 255 Zeichen langen Namen endlos (in `BEGIN IMMEDIATE`). Name vor dem Zusatz gekürzt. Test.
- **N3** Öffentliche Kopien gelöschter Ordner wurden nur 200 je Anfrage entfernt; jetzt alle Stapel beim Löschen.
- **N4** Datei umbenennen stand nicht im Protokoll (Entwurf E7). Neues Ereignis `file_renamed`.
- **N5** SVG nur aus BOM: `ValueError` und Fehlerseite 500 beim Logo statt Meldung. Behoben, Test.
- **N6** Affinity- (`serif:`) und Vectornator-Metadaten führten zur irreführenden Ablehnung „enthält Skripte“; jetzt still entfernt wie Inkscape. Test.
- **N7** Aufbereiten-Schleife lud die Seite neu, während ein Upload lief (Abbruch des Uploads). Wartet jetzt.
- **N8** Gemerkte Galerie-Wahl im Upload-Dialog wurde nie wiederhergestellt.
- **N9** Sortierung „Art“ absteigend kehrte nur die zweite Spalte um.

### Offen (Entscheidung oder späterer Block)

- **O1** Rückfrage beim Löschen ohne Zahlen und ohne „aus 1 veröffentlichten Galerie“ (Entwurf 4); nach dem Löschen kommt jetzt der Live-Hinweis (M6).
- **O2** `Folders::restore` prüft Tiefe und Ordnerzahl nicht: Zweig löschen, Elternordner tiefer schieben, zurückholen ergibt bis zu 15 Ebenen (Brotkrumen abgeschnitten, tiefe Ordner fehlen im Baum). Selten, harmlos. Vorschlag: bei Überschreitung in die Wurzel.
- **O3** Eine im Plan ausdrücklich gewählte Galerie wird beim Abschluss nicht auf „Entwurf“ geprüft; wird sie während eines langen Uploads veröffentlicht, kommen Fotos ohne Alt-Text hinein und das Veröffentlichen meldet Fehler. Normale Uploads in veröffentlichte Galerien sind gewollt, deshalb nicht still geändert.
- **O4** SVG-Prüfung strenger als die alte Logo-Prüfung: `@media` (helles/dunkles Favicon), `@font-face`, `<switch>`, `feTurbulence` werden abgelehnt, `overflow` still entfernt. Sicher, aber für manche Logos unbequem. Vorschlag: `@media (prefers-color-scheme)` und `overflow` in die Positivliste.
- **O5** „Bestand übernehmen“: übersprungene PDFs (Dublette, `/OpenAction`) werden nicht gemeldet, der Knopf bleibt dann sichtbar; endgültiges Löschen einer übernommenen Datei entfernt auch `/media/dokumente/…` und damit Ziele alter Weiterleitungen (so gewollt, aber ohne eigene Rückfrage). Kein Test für den Weg.
- **O6** `/OpenAction` lehnt auch harmlose Anfangsansichten ab (LaTeX/hyperref). Entspricht dem Entwurf; bei Beschwerden auf `/OpenAction` mit Aktion `/S /JavaScript|Launch` einengen.
- **O7** Speicher- bzw. Quota-Prüfung, `mod_headers`-Hinweis in der Voraussetzungsprüfung, Link Galerie → Ordner, `aria-selected`/Pfeiltasten im Baum, `lastModified`, Unterordner-Liste nicht paginiert (Auswahl über 500 wird still gekappt).
- **O8** Aufräumen (nicht Fehler): `copyExclusive` doppelt in `Files` und `Library`, Wurzel bei Fotos `NULL`, bei Dateien und Ordnern `0` (zehn Sonderfälle; solange Migration 10 nirgends läuft, ließe sich `photos.folder_id` noch kostenlos auf `NOT NULL DEFAULT 0` setzen), N+1-Abfragen beim Rendern (zwei `files->get` je Datei, drei COUNT je Unterordner, Papierkorbzähler), `mediaPlan` liest die Dubletten in der Schreibsperre, `setSiteDirty` auch bei Aktionen ohne Wirkung auf die Seite, `Names::isJunk` ungenutzt.
- **Außerhalb des Diffs:** `app/src/Site/Blocks.php:436` erzeugt `href=""/media/…"` (doppeltes Anführungszeichen) seit `beb9967`; Links im Lichtkasten der Bildleiste sind kaputt. Eigene kleine Korrektur.

## Tragen die Korrekturen der ersten Prüfung (18)?

- **M1 (öffentliche Kopien beim Ordner-Löschen): trägt**, entfernte aber nur 200 je Anfrage (jetzt N3). Wiederherstellen macht privat, wie beschrieben.
- **M2 (Rennen beim endgültigen Löschen): trägt** für lebenden Inhalt unter gelöschten Ordnern; das Retten konnte aber endlos laufen (N2). Das eigentliche Datenverlust-Rennen Löschen gegen Wiederherstellen (M5) hat die erste Prüfung übersehen.
- **N1 bis N4: tragen.**
- **Übersehen hat die erste Prüfung:** die SVG-Lücke M1, die PDF-Fehlablehnungen M2 (ihr Korpus enthielt nur kleine PDFs), das Plan-Aufräumen M3, den Ablagefehler M4, die Rennen M5, die fehlenden Hinweise M6/M7.

## Auflagen aus Entwurf 16 und Prüfung 17

Umgesetzt und getestet: Schema mit Teilindex und Wurzel 0, Papierkorb-Zweige mit `trash_root_id`, Zusammenführen, Stapel, Namensregeln an allen vier Stellen, Typ nur am Inhalt, SVG-Positivliste (nach M1 vollständig), PDF-Filter (nach M2 brauchbar), Auslieferung mit Range und Köpfen, öffentliche Kopie mit `.htaccess`, CSRF, Rollen („Bestand übernehmen“ nur Administrator), Audit ohne Namen, Mengen- und Größengrenzen, JSON-Plan mit Körpergrenze und Stundenbegrenzung, Fingerabdruck beim Fortsetzen. Teilweise oder fehlend: siehe O1, O4, O5, O7 (dazu Bild-/SVG-Grenze nicht einstellbar, Zusammenfassung zeigt Namensänderungen erst im Bericht).

## Bewertung der bekannten Abweichungen

1. **PDF ohne `sandbox`-Kopf: vertretbar.** Chromium zeigt PDFs mit CSP `sandbox` nicht an. Schutz: Marker-Filter (jetzt ohne Fehlalarme, aber weiter blind für komprimierte Objektströme), fester Typ, `nosniff`, nur Angemeldete laden hoch, Betrachter läuft abgeschottet. Grenze im Handbuch nennen.
2. **SVG öffentlich nur nach Selbsttest (E13): vertretbar und sicher** (scheitert geschlossen). Nachteil: in Docker hinter einem Proxy erreicht der Server seine eigene Adresse oft nicht, dann bleibt SVG immer privat; das Ergebnis wird eine Stunde gemerkt.
3. **„Zu vorhandener Galerie hinzufügen“ fehlt: vertretbar**, die Warnung kommt (allerdings erst im Abschlussbericht).

## Tests

Neue Prüfungen in `tests/medien.php` (Abschnitt „Prüfung 19“): SVG `href`/`xlink:href` in beiden Reihenfolgen, BOM, Affinity/Vectornator; PDF-Fehlalarm auf 5 MB Strom, `/JS` als Hex-Text, Verweis, nach Kommentar und NUL, `/AA`, Blockgrenze mit Leerraum und mit Verweis; fertiger Plan bleibt nach 24 Stunden; Zweig mit 230 Einträgen in einem Klick; Retten mit 255 Zeichen. **1 116 Prüfungen, 0 Fehler.**

## Weitere Runde?

Nach den Regeln (neue Befunde mit Risiko mittel) **ja, aber kurz:** ein Blick nur auf den Korrektur-Commit dieser Prüfung (`PdfCheck`, `SvgCheck::walk`, `planDone`, `purge`-Reihenfolge, `medien.js`). Vor dem Livegang bleibt der Browser-Test von Lutz (Ziehen vom Finder auf Ordner, Wiederherstellen, großer Ordner-Upload) und die geplante Fable-Prüfung der Dateiannahme und Auslieferung.
