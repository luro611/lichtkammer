# Entwurf 29: Eine Seite per Ziehen zusammenbauen

Stand: 19.11.2026. **Nur Entwurf, nichts gebaut.** Wunsch von Lutz (19.11.2026): Eine Seite soll sich ganz einfach aus Inhalten und Blöcken zusammenschieben lassen. Zielperson ist Matthias (nicht-technisch).
Grundlage: Entwürfe `12` (Seiten-Editor), `14` (Blockstile), `16` (Medienverwaltung), `28` (Speichern und Veröffentlichen). Dieser Entwurf hängt **nicht** an den offenen Antworten zu `28`; er sagt nur, wo beide zusammenstoßen (Abschnitt 4).

## 1. Bestand (belegt mit Dateien)

### Was heute geht

- **Blocktypen** (`TYPES` in `public/assets/js/page-editor.js`, Prüfung in `app/src/Site/Blocks.php`): Überschrift, Text, Bild, Galerie-Raster, Zitat, Trennlinie, Knopf/Link, Zwei Spalten, Bild und Text, Karussell, Galerien-Kacheln. Nur Startseite (`home_edit.php`): Titelbild (`hero`) und Startseiten-Fotos (`homephotos`). Grenzen: 100 Blöcke, 200 KB, 12 Fotos je Galerie, 20 Einträge je Spalte (`Blocks::MAX_*`).
- **Hinzufügen:** Am Ende der Liste ein Knopf „+ Block hinzufügen“ (`addMenu`, `<details>` mit Karten aus Name und einem Satz). Ein neuer Block landet **immer ganz unten**. Es gibt kein Einfügen an einer bestimmten Stelle.
- **Sortieren:** Ziehgriff `⠿` (HTML5-Ziehen, `dragstart`/`drop`, Linie zeigt die Ablagestelle) **und** Pfeilknöpfe ↑ ↓ (Tastatur, Sprachausgabe über `say()`). Ziehen funktioniert nur innerhalb **derselben** Liste (`drag.list !== list` bricht ab): Ein Block lässt sich also nicht in eine Spalte hinein- oder aus ihr herausziehen.
- **Je Block:** Zuklappen mit Kurzfassung, Duplizieren, Löschen (mit Rückfrage, außer der Block ist leer). Alles in `renderBlock`.
- **Spalten:** Block „Zwei Spalten“ mit links/rechts je einer Liste, darin nur Überschrift, Text, Bild, Zitat, Knopf (`Blocks::SIMPLE`). Keine Verschachtelung weiter.
- **Fotos:** Pro Bildblock Knopf „Foto auswählen“ → Dialog `pick()` (Suche, Raster, mehrere für Galerie/Karussell), Quelle `/admin/fotos/auswahl`. Alt-Text/Titel werden als Vorschlag übernommen. Bildausschnitt über `focusPicker`.
- **Speichern/Vorschau:** Der ganze Zustand geht als JSON in ein verstecktes Feld (`sync()`), der Server baut ihn neu auf und verwirft alles Unbekannte (`Blocks::parse`). „Vorschau“ zeigt den **gespeicherten** Stand in einem neuen Tab (`/admin/vorschau/{slug}`). Beim Verlassen mit ungespeicherten Änderungen erscheint ein Hinweis (`beforeunload`). Kein Rückgängig (bewusst, Entwurf 12, Abschnitt 5).

### Was für Matthias umständlich ist

1. **Neuer Block nur unten.** Wer zwischen zwei Blöcke etwas braucht, muss unten hinzufügen und dann mehrfach ↑ drücken oder ziehen.
2. **Auswahlmenü versteckt sich** hinter `<details>` und ist bei langer Seite weit weg (man scrollt nach unten, wählt, scrollt zurück).
3. **Ziehen geht nur innerhalb einer Liste.** Eine Überschrift in eine Spalte zu bringen heißt: in der Spalte neu anlegen, Text abtippen, alten Block löschen.
4. **Dateien (PDF, Logo, Grafik) kommen nicht in Blöcke.** Der Knopf-Block hat nur ein freies Textfeld „Ziel“; die Adresse einer öffentlichen PDF (`/media/dokumente/…`) muss man kennen und abtippen. Entwurf `16`, Teil F („Knopf-Block wählt Datei“, „Logo aus Medien“, „Verwendet in“) ist offen. Die Medienseite (`public/assets/js/medien.js`) kann Dateien ziehen, aber nur dort (Verschieben in Ordner), nicht in den Seiten-Editor.
5. **Fotos nur über einen Dialog** je Block, nicht „Foto nehmen und ablegen“. Ein neuer Bildblock ist erst leer und verlangt ein weiteres Klicken.
6. **Kein Rückgängig.** Ein versehentlich gelöschter oder verschobener Block ist weg (Rückfrage nur beim Löschen).
7. **Keine Vorschau beim Arbeiten.** Erst speichern, dann neuer Tab; die Karten zeigen Formularfelder, nicht das Aussehen.
8. **Ziehen am Handy:** HTML5-Ziehen (`draggable`) funktioniert auf Touchgeräten nicht zuverlässig; dort bleiben nur die Pfeilknöpfe.

## 2. Vorschläge

Leitplanken für alles: per Tastatur bedienbar (jede Zieh-Handlung hat eine Knopf-Entsprechung), Handy-tauglich, Designvariablen aus `app.css` (alle sechs Designs, hell/dunkel, keine festen Farben), **keine neue Bibliothek**, nur Erweiterung von `page-editor.js` im bisherigen Stil (Ausgabe nur `textContent`/Attribute). Der Server bleibt die einzige Stelle, die Block-Daten annimmt.

### (a) Blockleiste statt verstecktem Menü

Skizze (Text):

```
┌ Bausteine ──────────┐  ┌ Seite ───────────────────────────┐
│ [Ab] Überschrift    │  │ 1. Überschrift                   │
│ [¶ ] Text           │  │ ── hier einfügen ──              │
│ [▣ ] Bild           │  │ 2. Bild                          │
│ [▦ ] Galerie        │  │ ── hier einfügen ──              │
│ [❝ ] Zitat  …       │  │ 3. Text                          │
└─────────────────────┘  └──────────────────────────────────┘
```

- Links (Handy: oben als einklappbare Leiste, Knopf „Bausteine“) stehen die Blocktypen als kleine Karten. **Ziehen** einer Karte in die Seite legt einen leeren Block an der Ablagestelle an. **Gleichwertig ohne Maus:** Zwischen allen Blöcken ein Einfügepunkt „+ hier einfügen“ (Knopf), der das Auswahlmenü an dieser Stelle öffnet; Tastatur: Enter auf der Karte, dann Einfügepunkt wählen.
- Die Karte „Bild“ legt direkt den Fotodialog auf (neuer Block ohne Foto ist sinnlos).
- Bestehendes `addMenu` bleibt als „+ Block hinzufügen“ am Ende erhalten (kein Bruch).

Pro: löst Punkte 1 und 2; macht „zusammenschieben“ sichtbar; geringer Umbau (neuer Einfügepunkt = `list.splice` statt `push`).
Contra: Auf schmalen Bildschirmen braucht die Leiste Platz; Ziehen am Handy nur über die Einfügepunkt-Knöpfe, nicht per Finger.

### (b) Fotos, Galerien, Dateien aus einem Seitenstreifen

Skizze:

```
┌ Material ───────────┐
│ Fotos │ Galerien │ Dateien │   ← Reiter
│ [Suche …]                  │
│ ▢ ▢ ▢ ▢   (Foto-Kacheln)   │
└────────────────────────────┘
  Foto auf einen Bildblock ziehen = Foto setzt sich ein
  Foto auf freie Stelle ziehen   = neuer Bildblock mit diesem Foto
  Galerie auf freie Stelle       = Galerie-Raster mit ihren Fotos (bis 12)
  PDF auf freie Stelle           = Knopf-Block „PDF öffnen“ mit der öffentlichen Adresse
  Logo/Grafik auf freie Stelle   = Bild-Datei-Block (neu, siehe unten)
```

- Der Streifen nutzt dieselbe Quelle wie der Dialog (`/admin/fotos/auswahl`) und, neu, eine Liste öffentlicher Dateien aus der Medienverwaltung (Entwurf `16`). **Privat** gespeicherte Dateien erscheinen **nicht** (nur Öffentliche haben eine Adresse); wer eine private Datei will, bekommt den Hinweis „erst in Medien auf ‚Öffentlich‘ stellen“.
- Gespeichert wird weiter **nur die Id** (Foto) bzw. eine Datei-Id (neu); die Adresse setzt der Server beim Veröffentlichen aus der Id ein. Dann bricht ein Knopf nicht, wenn die Datei umbenannt wird, und „Verwendet in“ (Entwurf 16, F) wird möglich. **Das ist eine Änderung des Datenformats** (`Blocks::norm`, `Blocks::render`, Version bleibt `v:1`, neues optionales Feld `file`; alte Seiten mit `href` bleiben unverändert gültig).
- Tastaturweg: Auf der Kachel Enter → „Wohin?“ (Liste der passenden Blöcke oder „Neuer Block am Ende / hinter Block N“).
- Logo aus Medien (Einstellungen) ist ein **eigenes** Teilstück, kein Teil des Seiten-Editors; es kann mit gebaut werden, ist aber unabhängig.

Pro: löst Punkte 4 und 5; der Standardweg „Foto wählen“ wird ein Handgriff; nur Ids im Block, kleiner Sicherheitsrand.
Contra: Größter Teil des Aufwands (neue Datenquelle, neues Feld, Prüfung); Foto-Raster im Streifen kann bei 10 000 Fotos nur gestaffelt laden (wie `pick()` mit „Mehr laden“); auf dem Handy ersetzt der Dialog den Streifen.

### (c) Verschieben über Listen hinweg, Spalten, Duplizieren, Löschen mit Rückgängig

- **Ziehen zwischen Liste und Spalten:** `drag.list`-Prüfung lockern: Ablage in eine Spalte erlaubt, wenn `SIMPLE`; sonst roter Rahmen und Hinweis „Das geht nicht in Spalten“. Gegenstück ohne Maus: Knopf „Verschieben nach …“ je Block (Liste: „Hauptseite“, „Spalte links in Block 3“, …), wie in Entwurf 16 für Dateien vorgesehen.
- **Spalten:** Heute zwei Spalten, 50/50, nur einfache Blöcke. Vorschlag erste Stufe: **nichts am Modell ändern**, nur Ziehen hinein und heraus ermöglichen. Zweite Stufe (nur wenn Lutz will): Verhältnis wählbar (50/50, 2/3 : 1/3), drei Spalten. **Keine** Verschachtelung Spalten-in-Spalten (Server begrenzt Tiefe 16, aber Lesbarkeit und Handy sprechen dagegen).
- **Duplizieren, Reihenfolge, Zuklappen:** bleiben; Duplizieren setzt den Fokus auf die Kopie (gibt es schon).
- **Rückgängig:** Einfacher Weg ohne Bibliothek: Vor jeder **Struktur**änderung (Hinzufügen, Löschen, Verschieben, Duplizieren) wird `JSON.stringify(state)` auf einen Stapel gelegt (höchstens 30 Schritte). Knopf „↶ Rückgängig“ und `Strg/Cmd+Z` (nur außerhalb von Textfeldern, dort gilt das Browser-Rückgängig). Tippen in Feldern zählt nicht als Schritt. Beim Löschen **entfällt die Rückfrage**, an ihre Stelle tritt „Block gelöscht. [Rückgängig]“ (Leiste unten, 8 Sekunden, auch per Tastatur erreichbar; `say()` sagt es an).
  Der Stapel liegt **nur im Speicher**; nach Neuladen oder Speichern beginnt er neu.

Pro: Rückgängig nimmt die Angst vor dem Ziehen und ersetzt Rückfragen (Entwurf 12 hatte es bewusst weggelassen). Kein neues Datenformat.
Contra: Stapel nutzt Speicher (bei 200 KB × 30 = höchstens 6 MB, unbedenklich); Ziehen zwischen Listen erhöht die Zustandsfehler-Fläche (Tests nötig, siehe Abschnitt 5).

### (d) Live-Vorschau neben dem Editor

Drei Stufen, jeweils für sich nutzbar:

1. **Nur Kurzansicht in den Karten** (heute schon beim Zuklappen). Ausbauen: Beim Zuklappen ein kleines Abbild (Foto-Thumbnail, erste Textzeilen).
2. **Vorschau im Seitenfeld (`<iframe>`)**: Ein Knopf „Vorschau neben dem Editor“ speichert **nicht**, sondern schickt den aktuellen Block-JSON per POST an eine neue Admin-Route, die dieselbe Ausgabe wie die Vorschau erzeugt, **ohne zu speichern** (gleicher Renderer `Blocks::render`, gleiche Prüfung `Blocks::parse`, Antwort wie `/admin/vorschau`). Aktualisierung bei Knopfdruck und, wenn gewünscht, 1 Sekunde nach der letzten Änderung. Handy: Vorschau als eigener Reiter statt daneben.
3. Echte „Bearbeiten im Seitenbild“ (Klick direkt in die Seite): **nicht empfohlen** – deutlich mehr Aufwand und Risiko als der Nutzen.

Pro (Stufe 2): Matthias sieht sofort, was er baut; keine Doppelarbeit beim Renderer; Prüfung läuft wie beim Speichern (nur Ids, nur geprüfte Daten).
Contra: Eine zusätzliche Route (muss wie alle POST-Routen Anmeldung, CSRF und Ratenbegrenzung haben); die Seite im Iframe lädt Fotovarianten (bei vielen Fotos langsam); Vorschau zeigt nur den Stand der Seite, nicht den von `28` (Veröffentlichen).

## 3. Empfehlung

Reihenfolge nach Nutzen für Matthias und Aufwand:

1. **Teil A: Einfügepunkte und Blockleiste (a)**, dazu **Teil B: Rückgängig (c)**. Die beiden machen den größten Unterschied bei geringer Änderung des Datenformats (keine).
2. **Teil C: Ziehen in und aus Spalten (c)**, gleiche Änderungsfläche in `page-editor.js`.
3. **Teil D: Seitenstreifen für Fotos und Galerien (b)**, zuerst nur Fotos und Galerien (vorhandene Quelle).
4. **Teil E: Dateien (PDF, Logo) per Ziehen (b)** gemeinsam mit Entwurf `16`, Teil F; das ist die einzige Stufe mit neuem Feld im Datenformat.
5. **Teil F: Vorschau neben dem Editor, Stufe 2 (d)**, wenn Lutz sie wünscht.

Jeder Teil lässt den Editor lauffähig; man kann nach jedem Teil stoppen.

## 4. Risiken

- **Sicherheit der Block-Daten:** Alles bleibt wie heute: der Browser liefert nur Vorschläge, `Blocks::parse` baut neu auf. Neu zu prüfen: das Feld `file` (nur Ids, geprüft gegen öffentlich markierte, nicht gelöschte Dateien; nie ein frei eingegebener Pfad), die „Vorschau ohne Speichern“-Route (angemeldet, CSRF, Größenbegrenzung wie `MAX_JSON`, kein Schreiben in Datenbank oder Dateien, kein Cache). Zieh-Daten (`dataTransfer`) werden nie als HTML eingefügt, nur gelesen und gegen die eigene Liste geprüft.
- **Fremde Dateien ziehen:** Das Ablegen von Dateien aus dem Finder in die Seite ist **nicht** Teil dieses Entwurfs (das ist Upload, Entwurf 16). Der Editor reagiert nur auf Zieh-Daten, die er selbst gesetzt hat (Kennung im `dataTransfer`), alles andere ignoriert er.
- **Größe:** Die Grenzen (100 Blöcke, 200 KB) bleiben. Rückgängig-Stapel höchstens 30 Schritte. Der Seitenstreifen lädt gestaffelt (Seiten zu je 60 Kacheln). Die Datei `page-editor.js` wächst um geschätzt 300 bis 500 Zeilen (heute 680); wenn sie unübersichtlich wird, in zwei Dateien teilen (`page-editor.js`, `page-material.js`), weiterhin ohne Bibliothek.
- **Speichern und Veröffentlichen (Entwurf 28):** Der Editor bleibt Teil desselben Formulars. Wenn `28` die Knöpfe ändert („Speichern und veröffentlichen“, „Nur speichern“), ändert sich in diesem Entwurf nichts außer, dass die Vorschau Stufe 2 klar „nicht gespeichert, nicht online“ trägt. **Rückgängig gilt nur vor dem Speichern**; nach dem Speichern greift die Versionierung beim Veröffentlichen (Zurücksetzen). Das muss in der Oberfläche in einem Satz stehen.
- **Alte Seiten bleiben unverändert:** Kein neues Pflichtfeld, `v:1` bleibt. Bestehende Blöcke mit `href` ohne `file` werden weiter wie bisher ausgegeben. Die Blockleiste und das Ziehen ändern nur den Zustand im Browser, nicht das gespeicherte Format. Test mit den vorhandenen Beispielseiten und dem WordPress-Import (Entwurf 13) in beiden Richtungen: Datei öffnen, ohne Änderung speichern, Ausgabe vergleichen (byte-gleich).
- **Barrierefreiheit:** Jede Zieh-Handlung hat Knopf und Ansage; Fokus wird nach jeder Änderung gesetzt (`focusId`, wie heute). Risiko: Rückgängig mit Fokusverlust – muss getestet werden.
- **Handy:** HTML5-Ziehen ist dort unzuverlässig; deshalb wird jede Zieh-Funktion in jeder Stufe zusätzlich als Antippen-Ablauf („Wohin?“) gebaut, nicht nur als Fallback gedacht. Touch-Ziehen mit Zeigerereignissen ist möglich, aber aufwendiger; nicht in der ersten Fassung.
- **Browserprüfung:** Ziehen und Fokus lassen sich nicht rein in PHP-Tests belegen; es braucht einen Prüflauf im Browser (Chrome, Safari, Firefox, Handy-Breite) und Prüfpunkte pro Teil.

## 5. Aufwand (grob, Sonnet baubar, Opus prüft je Block)

| Teil | Inhalt | Dateien | Umfang | Risiko |
|---|---|---|---|---|
| **A** | Einfügepunkte, Blockleiste (Handy: einklappbar) | `page-editor.js`, `app.css` | klein bis mittel | niedrig |
| **B** | Rückgängig (Stapel, Leiste, Tastenkürzel), Rückfrage beim Löschen ersetzen | `page-editor.js`, `app.css` | klein | niedrig |
| **C** | Ziehen und „Verschieben nach …“ zwischen Hauptliste und Spalten | `page-editor.js` | mittel | mittel (Zustandsfehler) |
| **D** | Seitenstreifen Fotos und Galerien (vorhandene Quelle), Ablegen auf Bildblock oder freie Stelle | `page-editor.js` (evtl. neue Datei), kleine Route für Galerie-Fotos | mittel | mittel |
| **E** | Dateien aus Medien (PDF, Logo) in Blöcke, Feld `file`, Auslieferung | `Blocks.php`, Medien, `page-editor.js`, Tests `tests/bloecke.php`, `tests/medien.php`; Entwurf 16 Teil F | mittel bis groß | **hoch** (Datenformat, öffentliche Dateien) |
| **F** | Vorschau neben dem Editor (Route ohne Speichern) | `AdminController`, Vorlage, `page-editor.js` | mittel | mittel (Sicherheit der Route) |

Tests: neue Prüfungen in `tests/bloecke.php` (`parse` mit/ohne `file`, alte Seiten byte-gleich), für Skriptlogik eine Browser-Prüfliste je Teil (im Entwurf, nicht automatisiert, da es keinen Browser-Testlauf im Projekt gibt). Reihenfolge der Prüfungen: Opus je Block (A+B+C zusammen, D+E, F); **Fable** bei Teil E (Dateiauslieferung) und Teil F (Route), weil sie Daten betreffen, die nach außen gehen.

## 6. Offene Fragen an Lutz (höchstens vier, mit Empfehlung)

1. **Welche Blöcke fehlen?** Heute: Überschrift, Text, Bild, Galerie, Zitat, Trennlinie, Knopf, Spalten, Bild und Text, Karussell. Beispiele, die denkbar wären: Datei-Download (PDF mit Titel und Größe), Video-Einbettung, Karte, Kontaktformular, „Abstand“, Hinweisfeld, Logo-Reihe. **Empfehlung:** zuerst nur **Datei-Download** (kommt mit Teil E), den Rest erst, wenn ein Fall auf br-foto.com ihn braucht.
2. **Spalten: ja oder nein, und wie viele?** **Empfehlung:** Die vorhandenen zwei Spalten behalten, Ziehen hinein und heraus (Teil C), Verhältnis wählbar (2/3 : 1/3) später; keine drei Spalten, keine Verschachtelung.
3. **Vorschau neben dem Editor (Teil F) jetzt oder später?** **Empfehlung:** Später, nach A bis E; ohne sie reicht „Vorschau (gespeicherter Stand)“ nach dem Speichern. Wenn Matthias oft zwischen Editor und Vorschau wechselt, vorziehen.
4. **Reihenfolge und Umfang:** Soll mit **A, B, C** (ohne neues Datenformat) begonnen werden, und gehört **E** (Dateien in Blöcke, Feld `file`, Fable-Prüfung) in dieselbe Runde wie Entwurf 16, Teil F? **Empfehlung:** Ja zu A, B, C als erster Block; E zusammen mit 16/F als eigener Block danach.

## 7. Stand Umsetzung (19.11.2026): Teile A, B, C gebaut

Gebaut in `page-editor.js`/`app.css`, Datenformat und `Blocks::parse` unverändert. Browserprüfung (eingebauter Browser, Testseite mit dem echten Skript, hell/dunkel, 375 px und 1400 px) – Prüfpunkte:

- **A:** Einfügepunkt öffnet Menü an der Stelle und legt dort an (ok); Baustein antippen, dann Einfügepunkt (ok, Esc wählt ab); Bausteinleiste per Ziehen auf Einfügepunkt (ok, simulierte Zieh-Ereignisse); Leiste einklappbar, auf breitem Fenster offen und mitlaufend, Handy ohne seitliches Scrollen (ok). **Nicht geprüft:** echtes Ziehen mit der Maus in Safari/Firefox, Neuer Bildblock mit echtem Fotodialog (Testseite ohne Fotoliste).
- **B:** Knopf und Strg/Cmd+Z (nicht in Textfeldern), Leiste nach dem Löschen, Stapel nach Speichern leer (ok). Nach dem Einfügen steht der Fokus im neuen Feld, dort gilt Strg+Z des Browsers; der Knopf „Rückgängig“ geht immer.
- **C:** Block in leere Spalte ziehen (ok), Trennlinie in Spalte abgelehnt mit Hinweis (ok), Fremd-Ziehen ignoriert (ok), „Verschieben nach …“ (ok, Hauptseite „hinter dem Spalten-Block“, Spalten „ans Ende“).
