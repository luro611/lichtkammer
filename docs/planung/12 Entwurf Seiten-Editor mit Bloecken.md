# Entwurf: Seiten-Editor mit Blöcken

Stand: 16.11.2026, **zur Freigabe durch Lutz**. Gebaut wird erst nach Freigabe. Grundlage: Schritt 5 (`11`), Bildverwaltung (`10`). Auslöser: Lutz reicht ein Textfeld mit Markdown nicht; er will Seiten mit Bildern und Struktur bauen, bedienbar für die nicht-technische zweite Person.

## 1. Ziel und Grenzen

- Eine Seite besteht aus **Blöcken**, die man hinzufügt, ausfüllt und per Ziehen (oder Pfeilknöpfen) umsortiert.
- Ausgabe **nur über feste, geprüfte Vorlagen** je Blocktyp. Kein freies HTML, keine Skripte, kein eingebetteter Fremdcode (Sicherheit, XSS, Datenschutz).
- **Keine Bibliothek**: ein kleines eigenes Skript (`page-editor.js`, ca. 300 Zeilen, wie das Sortieren in `admin.js`). Ohne Skript bleibt der Editor als einfaches Formular bedienbar (Abschnitt 6).
- Nicht Teil: Videos, Einbettungen (YouTube, Karten), Tabellen, Farben/Schriften pro Block, Mehr-Ebenen-Verschachtelung.

## 2. Blocktypen (Version 1)

| Typ | Felder | Ausgabe |
|---|---|---|
| Überschrift | Text, Ebene 2 oder 3 | `<h2>`/`<h3>` |
| Text | Text mit der bisherigen Markdown-Teilmenge (fett, kursiv, Listen, Links; keine Überschriften/Bilder darin) | `<p>`, `<ul>`, … wie `Markdown::render` |
| Bild | Foto aus der Fotobibliothek, **Alt-Text (Pflicht)**, Bildunterschrift (optional), Breite: schmal, normal, volle Breite, optional Link | `<figure>` mit `<picture>`/srcset wie in Galerien |
| Galerie-Raster | 2 bis 12 Fotos aus der Bibliothek, je Alt-Text, Spalten 2/3/4 | Raster, Klick öffnet die Lightbox (wie Galerien) |
| Zitat | Text, Quelle (optional) | `<blockquote>` |
| Trennlinie | – | `<hr>` |
| Knopf/Link | Beschriftung, Ziel (Adresse der eigenen Seite, `https://`, `mailto:`) | `<a class="knopf">` |
| Zwei Spalten | links und rechts je **eine Liste einfacher Blöcke** (Überschrift, Text, Bild, Zitat, Knopf; nicht wieder Spalten oder Raster) | zwei Spalten, auf Handy untereinander |

Später denkbar (nicht jetzt): Galerie-Kachel („Galerie X“ einbinden), Abstand, Inhaltsverzeichnis.

## 3. Datenmodell

- Neue Spalte `pages.blocks TEXT` (JSON, Migration 5). `body` bleibt für die Übernahme erhalten (Abschnitt 4), wird nach der Umstellung nur noch gelesen, nie mehr geschrieben.
- Format (`v` = Formatversion, für spätere Änderungen):

```json
{"v":1,"blocks":[
 {"t":"heading","level":2,"text":"Wer wir sind"},
 {"t":"text","text":"Wir sind **Anna** und Ben …"},
 {"t":"image","photo":123,"alt":"Anna am Strand","caption":"Lissabon 2019","width":"normal"},
 {"t":"gallery","photos":[{"photo":5,"alt":"…"},{"photo":6,"alt":"…"}],"cols":3},
 {"t":"quote","text":"…","by":"…"},
 {"t":"rule"},
 {"t":"button","label":"Zu den Galerien","href":"/galerien/"},
 {"t":"columns","left":[{…}],"right":[{…}]}
]}
```

- Fotos werden **nur per ID** gespeichert, nie als Pfad. Beim Rendern werden Pfade und Größen aus der Datenbank geholt (wie bei Galerien). Fotos im Papierkorb oder nicht `ready` fehlen mit einer klaren Meldung beim Veröffentlichen (Sperre, wie schon bei leeren Seiten).
- **Eine Prüfklasse `Blocks`** ist die einzige Stelle, die das JSON annimmt: kennt nur die Typen oben, kappt Längen (Text 20.000, Alt 250, Bildunterschrift 300), prüft Typ/Pflichtfelder/Fotos/Adressen (Ziele nur `/…`, `https://`, `http://`, `mailto:`, wie in `Markdown`), höchstens 100 Blöcke je Seite, JSON höchstens 200 KB. Unbekanntes wird abgelehnt, nicht stillschweigend übernommen. Beim Speichern **und** beim Rendern wird geprüft.
- Reihenfolge = Reihenfolge im Array. Keine Block-IDs in der Datenbank (nur im Editor kurzlebig).

## 4. Migration vorhandener Seiten

- Migration 5 (mit der vorhandenen Datenbank-Sicherung vor Migrationen): fügt `blocks` hinzu, **ändert `body` nicht**.
- Beim ersten Öffnen einer Seite im Editor wird `body` einmalig in Blöcke umgewandelt: `##`/`###` → Überschrift, Absatz-/Listen-Gruppen → Text, `>` → Zitat, `---` → Trennlinie. Was nicht sicher erkannt wird, bleibt als Textblock (nichts geht verloren). Die Umwandlung wird **erst beim Speichern** festgeschrieben; bis dahin zeigt die Seite weiter `body`.
- Renderer: ist `blocks` gefüllt, gilt `blocks`, sonst `body` (Markdown wie bisher). Damit bleibt jede unveränderte Seite identisch.
- Rückweg: `body` bleibt in der Datenbank. Eine Seite lässt sich per Knopf „Als einfachen Text speichern“ zurückwandeln (nur wenn sie nur Überschrift/Text/Zitat/Trennlinie enthält).

## 5. Bedienung (für die nicht-technische Person)

Seite bearbeiten: oben Titel, Adresse, Status, Verlinkung (wie bisher), darunter die Blockliste.

- Zwischen den Blöcken und am Ende: Knopf **„+ Block hinzufügen“** mit großen Karten (Symbol, Name, ein Satz). Kein Fachbegriff.
- Jeder Block ist eine Karte mit Kopfzeile (Name des Typs), Knöpfen **↑ ↓ Duplizieren Löschen** (Löschen mit Rückfrage) und einem Griff zum Ziehen. Zuklappen zeigt eine Kurzfassung.
- **Bild wählen**: Knopf „Foto auswählen“ öffnet eine Auswahl (Dialog `<dialog>`) mit der Fotobibliothek: Suche, Raster der Vorschaubilder, Auswahl per Klick/Enter (Raster: mehrere für Galerie-Block). Vorhandener Titel/Alt-Text des Fotos wird als **Vorschlag** in Alt-Text und Bildunterschrift vorgetragen und kann geändert werden.
- Sichtbare, deutsche Hinweise statt Fehlerseiten: Beim Speichern erscheint oben „Gespeichert“ (siehe Rückmeldung in Schritt 5) und die Liste der Probleme wird am jeweiligen Block markiert („Bitte Alt-Text eintragen“).
- Knöpfe unten: **Speichern**, **Speichern und zur Liste**, **Vorschau** (gespeicherter Stand, im neuen Tab). Eine **Live-Vorschau neben dem Editor** ist nicht Teil von Version 1 (Aufwand; die Vorschau nach dem Speichern genügt).
- Ungespeicherte Änderungen: Hinweis beim Verlassen der Seite.
- Kein „Rückgängig“ im Editor in Version 1. Das Veröffentlichen mit Rückgängig (Schritt 5) fängt Fehler auf der Live-Seite auf.

## 6. Technik im Admin

- Formular sendet **ein verstecktes Feld `blocks`** mit dem JSON (vom Skript aus den Feldern aufgebaut) plus CSRF-Token wie bisher. Der Server verwirft alles, was nicht durch `Blocks::parse` kommt.
- **Ohne JavaScript**: der Editor zeigt das JSON nicht an; stattdessen bleibt das bisherige Textfeld (Markdown) für Seiten ohne Blöcke bedienbar, und der Block-Editor erklärt, dass er Skript braucht. Das ist bewusst: kein zweiter Editor ohne Skript. Barrierefreiheit gilt trotzdem (nächster Absatz).
- **Barrierefreiheit**: alles per Tastatur bedienbar (Tab-Reihenfolge, Pfeilknöpfe statt nur Ziehen, `aria-live` für „Block verschoben“, `<dialog>` mit Fokus-Falle und Esc), sichtbarer Fokus, Beschriftungen an allen Feldern. **Alt-Text ist bei Bild und Galerie-Bildern Pflicht**; das Speichern eines veröffentlichten Seitenstands ohne Alt-Text wird mit Hinweis am Block abgelehnt, Entwürfe dürfen unvollständig gespeichert werden (nur Veröffentlichen sperrt, wie bei Galerien). Dekorative Bilder: Schalter „Nur Dekoration“ setzt `alt=""` ausdrücklich.
- Dateien: `app/src/Site/Blocks.php` (Prüfen, Umwandeln aus Markdown, Rendern), `app/templates/admin/page_edit.php` (Editor), `public/assets/js/page-editor.js`, Vorlage `app/templates/site/blocks/<typ>.php` je Block, CSS im Theme (`site.css`) und Admin (`app.css`). Foto-Auswahl nutzt einen schmalen JSON-Weg `GET /admin/fotos/auswahl?q=…` (nur Id, Titel, Vorschau-Adresse).

## 7. Einbindung ins statische HTML und die Vorschau

- `Renderer` ruft für Seiten mit `blocks` den neuen Blockrenderer; die Ausgabe ist **dieselbe Funktion** für Veröffentlichen und Vorschau (Vorschau nur mit `/admin/vorschau`-Präfix für interne Links). Bilder nutzen dieselben Varianten (WebP/JPEG, srcset, `width`/`height` gegen Layoutsprünge) wie die Galerien; Raster-Block und Lightbox verwenden das bestehende `site.js`.
- Sperren beim Veröffentlichen (Probelauf): fehlender Alt-Text, Foto nicht mehr vorhanden/Papierkorb, leere Seite (kein Block), ungültige Ziele.
- Nach dem Löschen eines Fotos in der Bibliothek zeigt „Papierkorb“ weiter die bisherige Warnung; zusätzlich nennt der Papierkorb, auf welchen Seiten das Foto verwendet wird (Abfrage über `blocks`).
- Sitemap, Navigation und Adressen unverändert.

## 8. Sicherheit (Zusammenfassung)

Whitelist der Typen; alle Texte beim Rendern mit `h()` maskiert, Markdown-Teilmenge wie bisher (rohes HTML wird maskiert); Adressen nur aus erlaubter Liste; Fotos nur per Id aus der Datenbank, Pfade nie aus dem JSON; Größen- und Anzahlgrenzen; CSRF und Sitzungsprüfung wie alle Admin-Wege; Audit-Eintrag `page_saved`. Tests: XSS in jedem Textfeld, `javascript:`-Links, fremde Foto-Ids, Papierkorb-Foto, überlange Felder, kaputtes JSON, Migration alter Seiten (Ausgabe vorher = nachher), Reihenfolge, Spalten.

## 9. Aufwand und Reihenfolge

Version 1 in zwei Teilen: **(a)** Datenmodell, `Blocks`, Migration, Rendern, Vorschau, Veröffentlichen, Tests; **(b)** Editor-Oberfläche mit Foto-Auswahl, Sortieren, Tastaturbedienung, Tests. Danach Prüfung durch Opus (Risiko mittel: nimmt strukturierte Eingaben an, rendert in öffentliches HTML).

## 10. Entscheidungen für Lutz

| # | Frage | Empfehlung |
|---|---|---|
| B1 | Blocktypen wie in Abschnitt 2 (Überschrift, Text, Bild, Galerie-Raster, Zitat, Trennlinie, Knopf, Zwei Spalten) für Version 1? | ja |
| B2 | Block-Editor braucht JavaScript (kein zweiter Editor ohne Skript)? | ja |
| B3 | Live-Vorschau neben dem Editor erst später, in Version 1 nur Vorschau nach dem Speichern? | ja |
| B4 | Bestehende Markdown-Seiten beim ersten Öffnen automatisch in Blöcke umwandeln (nach dem Speichern festgeschrieben, `body` bleibt als Sicherung)? | ja |
| B5 | Alt-Text-Pflicht wie bei Galerien: Entwurf darf unvollständig sein, Veröffentlichen wird gesperrt? | ja |
