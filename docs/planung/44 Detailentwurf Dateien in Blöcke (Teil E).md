# Detailentwurf 44: Dateien in Blöcken, „Verwendet in“, Detailfeld, Logo aus Medien (Teil E)

Stand: 20.11.2026. **Nur Entwurf, nichts gebaut, nichts hochgeladen, keine Migration.** Risiko **hoch** (Datenformat, öffentliche Dateien, Auslieferung, neue Annahmestelle in Stufe 6). **Opus prüft diesen Entwurf vor dem Bau, Fable prüft den Code vor dem Hochladen** (Abschnitt 11).

Grundlage: Entwurf `29` (Teil E, Abschnitte 2b, 4, 5), Entwurf `16` (Teile D und F) mit Prüfungen `17`, `18`, `19`, Entwurf `12` (Blockformat), Prüfungen `35` (Fable: Dateiannahme und Auslieferung), `42`, `43`. Gelesen im Code: `Site/Blocks.php`, `Site/Renderer.php`, `Site/Builder.php`, `Site/Settings.php`, `Media/Files.php`, `Admin/MedienPages.php`, `Admin/SitePages.php` (`editorPayload`), `Backup/Restore.php`, `Auth/Schema.php`, `page-editor.js`, `page-material.js`, Vorlagen `admin/medien.php`, `admin/logos.php`.

Entscheidung von Lutz (19.11.2026): **Datei-Download ist der einzige neue Blocktyp.** Alles andere unten baut auf vorhandenen Bausteinen auf.

**Stand nach Opus-Prüfung (Bericht `45`, 20.11.2026): baubar mit Auflagen.** Vor dem Bau von Stufe 1 festzuhalten: (1) `repairPublicCopies` darf `public` nie auf 0 setzen und muss den SVG-Selbsttest und das Namensmuster beachten (B1, B2); (2) Prüfung vor dem Umbenennen des Bauordners (B3, schon in Abschnitt 5 eingearbeitet); (3) `Blocks::fileRefs` mit Blocknummer statt nur `fileIds` (B4); (4) Bestätigungsseite setzt `dirtyHandled` (B5); (5) Stufe 1 teilen in 1a und 1b (Abschnitt 12 im Bericht). Kleinigkeiten sind unten im Text eingearbeitet und mit „Prüfung 45“ markiert.

## 1. Kurzfassung der Empfehlung

1. **Dateien werden in Blöcken nur über ihre Nummer (`file` = Datei-Id) gespeichert, nie als Adresse.** Die Adresse setzt der Server bei jedem Bau neu aus der Datenbank ein. Alte Blöcke mit `href` bleiben gültig und im gespeicherten Format **byte-gleich** (das Feld `file` steht nur im Block, wenn es gesetzt ist).
2. **Neue Dateien werden nicht in die Fassung kopiert.** Die öffentlichen Kopien liegen weiter in `media/dateien/` (wie Bildvarianten in `media/`); eine Fassung enthält nur HTML.
3. **Fehlt eine benutzte Datei beim Veröffentlichen** (privat gestellt, gelöscht, Kopie weg), **stoppt der Probelauf** mit Namen von Datei und Seite, genau wie bei einem gelöschten Foto. Es entsteht nie ein toter Link und nie ein Link auf eine private Datei.
4. **Schutz davor, dass es überhaupt so weit kommt:** Vor dem Privatstellen und Löschen zeigt eine Bestätigungsseite „wird verwendet in …“. Dafür gibt es einen Dienst „Verwendet in“, der auch das Detailfeld und die Spalte in der Medienliste speist.
5. **Keine Migration.** Alles ist Block-JSON, Einstellungen oder Manifest. Das **Sicherungsformat bleibt unverändert**; neu ist nur, dass fehlende öffentliche Kopien nach einer Wiederherstellung automatisch aus den privaten Originalen nachgezogen werden (Abschnitt 6; das ist heute eine Lücke).
6. **Sechs Stufen mit Haltepunkt nach jeder** (Abschnitt 9). Der Server-Kern (Stufe 1 und 2) kommt zuerst, die Oberfläche danach.

## 2. Datenformat

### 2.1 Bestand (belegt)

- `Blocks::norm` baut jeden Block aus einer **festen Liste von Schlüsseln** neu auf; Unbekanntes wird verworfen (`Blocks.php`). Der Knopf-Block speichert `{t:'button', label, href, style}`, Version `v:1` (`Blocks::encode`).
- `Blocks::parse($json, $db)` prüft mit Datenbank, wenn `$db` gegeben ist (Fotos: „gibt es nicht (mehr)“); `decode()` liest gespeicherte Seiten **ohne** Datenbank. Der Renderer fängt Fehler beim Lesen ab und macht daraus einen Fehler im Probelauf.
- Der Editor schickt den ganzen Zustand zurück (`sync()` serialisiert unbekannte Felder mit; nur `_`-Felder fallen heraus). Ein Block mit `file` überlebt also einen Editor, der das Feld noch nicht kennt.

### 2.2 Neu

**Knopf (`button`)** bekommt das optionale Feld `file` (ganze Zahl > 0):

```
{ "t":"button", "label":"Preisliste", "href":"", "style":"normal", "file":12 }   // neu
{ "t":"button", "label":"Preisliste", "href":"/media/dokumente/preise.pdf", "style":"normal" }   // alt, unverändert
```

- Ist `file` gesetzt, wird `href` beim Speichern auf `''` gesetzt (eine Quelle der Wahrheit). Ohne `file` gilt alles wie bisher, inklusive `Markdown::safeUrl`.
- `file` wird in `norm` **nur dann** in das Ergebnis geschrieben, wenn es > 0 ist, und zwar **hinter** den bisherigen Schlüsseln. Alte Blöcke ergeben dadurch exakt dieselbe gespeicherte Zeichenkette wie heute (Test: beliebige vorhandene Seiten laden, `parse` → `encode`, Vergleich Byte für Byte, auch für die WordPress-Importseiten).
- Beschriftung bleibt Pflicht (wie heute). Der Editor füllt sie bei leerem Feld mit dem Dateinamen ohne Endung vor. **Reihenfolge im Code beachten:** Die bisherige Regel „Beschriftung und Adresse leer = Block entfällt“ darf bei `file` > 0 nicht greifen (sonst verschwindet der Block beim Speichern still); dort gilt „Beschriftung fehlt“ als Fehler. Die Meldung für Spalten („In Spalten gehen nur …“) bekommt den Download-Block dazu.

**Datei-Download (`download`, neuer Typ):**

```
{ "t":"download", "file":12, "title":"", "style":"normal" }
```

| Feld | Regel |
|---|---|
| `file` | Pflicht, > 0. Fehlt es: „Bitte eine Datei auswählen.“ (wie bei Bildblöcken) |
| `title` | optional, höchstens 120 Zeichen. Leer = Anzeigename der Datei **zum Zeitpunkt des Baus** (Umbenennen in Medien wirkt also beim nächsten Veröffentlichen) |
| `style` | `normal` (Vorgabe) oder `flaeche` (`Blocks::STYLES`, unbekannt = Vorgabe) |

- `download` kommt in `Blocks::SIMPLE` (darf also in Spalten stehen) und ist auch auf der Startseite erlaubt. Keine Beschreibung, kein Vorschaubild, kein Zähler in dieser Fassung.
- **Bilddateien (PNG, JPEG, WebP, SVG) werden als Download-Block eingefügt, nicht als Bild** (Frage 3). Es gibt keinen „Bild-Datei-Block“; Bilder auf der Seite bleiben Fotos mit Bildpipeline. Grund: Bilddateien haben keine Varianten und kein GPS-Entfernen, und Lutz hat nur einen neuen Blocktyp freigegeben.

**Prüfung beim Speichern (`parse` mit `$db`):** `file` muss eine Datei sein, die **existiert und nicht im Papierkorb liegt**. Sonst Meldung „Block N: Die Datei gibt es nicht (mehr). Bitte eine andere wählen.“ (die Seite bleibt mit allen Eingaben im Editor, wie bei Fotos). **Ob die Datei öffentlich ist, wird beim Speichern bewusst nicht verlangt**, sondern beim Bau: Sonst ließe sich eine Seite nicht mehr speichern, nur weil jemand eine Datei privat gestellt hat. Eingabe ist nur eine Zahl (`is_int` oder Ziffernfolge unter 10 Stellen, wie `photoId`); alles andere zählt als „keine Datei“. Es gibt **kein** Feld, in das eine Adresse oder ein Pfad für eine Datei eingegeben werden kann.

`Blocks::fileIds(array $blocks): list<int>` (rekursiv durch Spalten, wie `photoIds`) liefert die benutzten Dateien für Bau, Manifest und „Verwendet in“.

### 2.3 Was gespeichert und was beim Bau aufgelöst wird

| Gespeichert im Block | Beim Bau aus der Datenbank |
|---|---|
| Datei-Id | Adresse (`/media/dateien/<token8>-<slug>.<ext>` oder `/media/dokumente/<name>.pdf` bei übernommenen Altdateien), Anzeigename, Größe in Bytes, Art |

Die Adresse kommt **nur** aus `files.public_name` bzw. `files.legacy_public_path`, und zwar erst, nachdem sie gegen dasselbe Muster geprüft wurde, das `Files::removeCopy` und `Files::purge` schon benutzen (`^[0-9a-f]{8}-[a-z0-9-]*\.(pdf|svg|png|jpg|webp)$`, bzw. `^dokumente/[A-Za-z0-9][A-Za-z0-9._-]{0,120}\.pdf$`), **mit Modifikator `D`** (ohne `D` lässt `$` einen Zeilenumbruch am Ende durch; gemessen, Prüfung 45). Passt der Wert nicht (handverändert oder aus einer fremden Sicherung), gilt die Datei als nicht verfügbar. Neue Methode `Files::publicUrlOrNull(array $row): ?string`; die bisherige `publicUrl` bleibt für die Medienansicht.

### 2.4 Verhalten, wenn sich die Datei später ändert

| Was geschieht | Folge für Blöcke |
|---|---|
| **Umbenennen** (Anzeigename) | Adresse bleibt (so schon gebaut). Titel im Block, wenn leer, wechselt beim nächsten Bau auf den neuen Namen. |
| **In anderen Ordner verschieben** | keine Folge (Ordner sind nur Datenbank). |
| **Privat stellen** | Datei nicht mehr verfügbar. Vorher Bestätigungsseite (Abschnitt 4.2). Beim nächsten Probelauf **harter Fehler** für veröffentlichte Seiten, Warnung für Entwürfe; in der Vorschau ein sichtbarer Platzhalter. |
| **In den Papierkorb** (auch als Teil eines Ordners) | wie privat stellen. Wichtig: `Folders::trash` ändert nur Datenbankzeilen, `public=1` und die Kopie bleiben bis zum Aufräumen stehen. Der Bau verlangt deshalb **immer `deleted_at IS NULL` und `public = 1`**, nicht nur `public`. |
| **Wiederherstellen** | Die Datei ist wieder da, aber **privat** (so gebaut, Prüfung 19). Blöcke, die sie benutzen, bleiben Fehler, bis jemand wieder „Öffentlich“ schaltet. Die Meldung nach dem Wiederherstellen sagt das, wenn die Datei benutzt wird. |
| **Endgültig löschen** | `parse` meldet beim nächsten Speichern „gibt es nicht (mehr)“; Bau: harter Fehler. |
| **Kopie fehlt auf der Platte** (z. B. nach Wiederherstellung auf neuem Server) | Der Bau prüft `is_file` der öffentlichen Kopie; fehlt sie, gilt die Datei als nicht verfügbar mit eigener Meldung („Kopie fehlt, wird beim nächsten Öffnen von Medien automatisch ergänzt“). Nachziehen siehe Abschnitt 6. |

**Anzeige im Block, wenn nicht verfügbar:** Im Bau (`!isPreview()`) wird nichts ausgegeben, kann aber nicht auf eine Live-Seite gelangen, weil der Fehler den Bau sperrt. In der Vorschau steht statt des Links ein klar erkennbarer Hinweis „Datei nicht verfügbar (privat gestellt oder gelöscht)“, ohne Link, damit Matthias es sieht. **Nie** wird ein Link auf einen privaten Pfad, auf `/admin/medien/datei/…` oder auf eine geratene Adresse erzeugt.

**Warum harter Fehler und nicht „Block weglassen“:** Eine stillschweigend verschwundene Preisliste ist schlimmer als ein gestopptes Veröffentlichen mit klarer Meldung („Seite ‚Preise‘, Block 4: Datei ‚Preisliste 2026.pdf‘ ist nicht mehr öffentlich“). Das entspricht dem heutigen Umgang mit gelöschten Fotos (`Renderer::data`). Der Preis: Ein vergessenes Privatstellen blockiert das Veröffentlichen anderer Änderungen, bis es behoben ist. Das fängt Abschnitt 4 vorher ab (Frage 1).

## 3. Sicherheit

| Punkt | Regelung |
|---|---|
| **Id-Auflösung** | nur serverseitig, nur über die Datenbank. Aus der Eingabe kommt nur eine Zahl. Kein Pfad, kein Name, keine Adresse. |
| **Welche Dateien** | beim Bau nur `public = 1`, `deleted_at IS NULL`, Adresse nach Muster gültig, Kopie vorhanden. Dasselbe gilt für alle Ausgaben (Knopf, Download-Block, Vorschau). |
| **Ausgabe** | Titel und Anzeigename immer mit `Renderer::h()` (kein Markdown im Titel). Adresse mit `Renderer::h()`, beginnt immer mit `/media/`; nie `javascript:`, `data:` oder `//`. Link ohne `target`, ohne `download`-Attribut (PDF öffnet im Browser, wie in Entwurf 16 beschrieben). |
| **Endung und Größe** | Endung aus der Dateiendung der **gespeicherten** Datei (`path`/`public_name`, erzeugt aus dem erkannten Typ), Art aus `files.kind`; Größe aus `files.bytes` der Datenbank, formatiert mit fester Funktion („1,2 MB“). Nichts davon kommt vom Browser oder aus dem Block. |
| **Vorschau-Adresse** | `Renderer::url()` setzt in der Vorschau das Präfix `/admin/vorschau` davor. Dateiadressen dürfen **nicht** durch `url()` laufen (Medien liegen nicht unter dem Präfix). Für `file` wird die Adresse direkt gebaut. *Gemessen (Prüfung 45):* Alte Knöpfe mit `href="/media/dokumente/…"` laufen heute in der Vorschau durch `url()` und bekommen das Präfix (`/admin/vorschau/media/dokumente/x.pdf`); das Ziel liefert 404 (die Vorschau-Route kennt höchstens zwei Pfadteile). Vorschlag: `link()` setzt das Präfix für Adressen unter `/media/` nicht (kleine Korrektur in Stufe 1, mit Test). |
| **Auslieferung** | Es kommt **keine neue Auslieferungsstelle** dazu. Öffentliche Dateien kommen weiter vom Webserver aus `media/dateien/` mit der geprüften `.htaccess` (`nosniff`, feste Typen, SVG mit `sandbox`-Kopf, kein PHP; Fable-Prüfung `35` hat das praktisch bestätigt). Private Dateien nur über `/admin/medien/datei/{id}` (unverändert). Der Download-Block verlinkt nur auf Öffentliches. |
| **SVG** | Bleibt öffentlich nur nach dem Selbsttest des Schutzkopfes (E13, `Files::setPublic`). Ohne bestandenen Test erscheint die SVG nicht in der Dateiliste des Editors; der Hinweis sagt warum. |
| **Neue Lese-Routen** | `GET /admin/dateien/auswahl` (Liste für den Editor, nur Metadaten öffentlicher Dateien: Id, Name, Art, Größe, Endung, Ordnerpfad; keine Adressen privater Dateien) und `GET /admin/medien/detail` (Detailfeld). Beide: angemeldet, nur lesen, `no-store`, Ausgabe als JSON und im Browser nur per `textContent`. (`/admin/dateien/auswahl` beginnt mit `/admin/dateien/`, das `setSiteDirty` nur bei POST betrifft; kein Problem.) |
| **Ratenbegrenzung** | *Korrigiert (Prüfung 45):* `photoPick`, `galleryPick` und `galleryPickPhotos` haben **keine** Ratenbegrenzung (nur `previewLive` hat eine; Entwurf 43 N3 betrifft die Vorschau). Die neuen Lese-Routen brauchen auch keine: Anmeldung, höchstens 60 Zeilen je Antwort, Abfrage über Indizes. Keine Verstärkung durch Blöcke: Der Bau löst jede Datei-Id pro Lauf einmal auf (Zwischenspeicher je Renderer wie `blockPhotos`). |
| **Rollen** | Editor darf Dateien in Blöcken verwenden und öffentlich schalten (E7 aus Entwurf 16, unverändert). Der Dienst „Verwendet in“ zeigt Seitentitel; die sind für beide Rollen sichtbar. |
| **Protokoll** | keine neuen Ereignisse nötig. `file_public_changed` und `media_trashed` gibt es; die Bestätigung ändert nur, **wann** sie ausgelöst werden. Details weiter nur Zahlen, nie Namen. |

## 4. „Verwendet in“

### 4.1 Dienst (`Media/Usage`, neu)

Eine Klasse, die **nur liest** und eine Quelle der Wahrheit hat:

- `forFile(int $id): list<array{kind,label,link}>` und `forPhoto(int $id)` für das Detailfeld.
- `usedFileIds(): array<int,true>` und `usedPhotoIds()` für Mengen (Liste, Ordner-Löschen): **ein** Durchlauf über alle Seiten, nicht einer je Eintrag.
- Gefunden wird:
  - **Datei:** Seiten (Titel, Link `/admin/seiten/{id}`) und Startseite (`home_blocks`), je mit der Blocknummer, wenn `file` im Block steht; dazu **Altverweise:** Seiten, deren Blöcke oder Markdown-Text die öffentliche Adresse der Datei als Zeichenfolge enthalten (nötig für die übernommenen PDFs aus dem WordPress-Import, siehe unten); die Logos (Rolle hell, dunkel, Favicon, ab Stufe 5).
  - **Foto:** Galerien (Titel, Status, Link `/admin/galerien/{id}`, über `gallery_photos`), Seitenblöcke und Startseite (`Blocks::photoIds`), Titelbild einer Galerie (`cover_photo_id`), Startseiten-Foto (`hero_photo_id`, `on_home`).
  - Zusätzlich je Eintrag „**auf der Live-Seite**“ ja/nein: für Dateien aus dem Manifest der aktiven Fassung (`file_ids`, Abschnitt 5), für Fotos wie `Builder::isLive`.
- Rechenaufwand: Seiten decodieren ist billig (100 Seiten, je höchstens 200 KB). Vorbild ist `MediaPages::trashUsage`. Bei sehr vielen Seiten bleibt es ein Durchlauf je Anfrage.
- *Warum Zeichenfolgensuche für Altverweise:* Die importierten Knöpfe stehen als `href="/media/dokumente/x.pdf"` im Block und werden **nicht** automatisch auf `file` umgestellt (das würde die gespeicherten Seiten ändern). Sie sollen aber trotzdem beim Löschen warnen. Treffer werden als „(Adresse, nicht verknüpft)“ gekennzeichnet.

### 4.2 Warnung vor Privatstellen, Löschen, Verschieben in den Papierkorb

Ohne Skript lösbar, ohne neue Bibliothek: eine **Bestätigungsseite** statt `data-confirm`.

1. „Wieder privat“, „In den Papierkorb“ (auch Ordner) und „Endgültig löschen“ rufen weiter dieselbe POST-Route auf. Wird dabei etwas benutzt und fehlt `bestaetigt=1`, antwortet der Server **nicht mit der Aktion**, sondern mit der Seite „Das wird noch verwendet“: Liste je Datei/Foto („Preisliste 2026.pdf: Seite ‚Preise‘, Block 4; auf der Live-Seite“) mit Knöpfen „Trotzdem ausführen“ (POST mit `bestaetigt=1` und derselben Auswahl) und „Abbrechen“.
2. Ordner mit vielen Einträgen: Die Menge benutzter Einträge kommt aus `usedFileIds`/`usedPhotoIds` ∩ Auswahl (ein Durchlauf); die Seite zeigt höchstens 50 Zeilen und „und 130 weitere“.
3. Wird nichts benutzt, ändert sich nichts (keine zusätzliche Frage).
4. Das schließt auch die offene Lücke O1 aus Prüfung `19` („Rückfrage beim Löschen ohne Zahlen“) für Fotos mit ab.
5. Keine Sperre: Wer bewusst trotzdem löscht, darf. Der Probelauf stoppt danach das Veröffentlichen (Abschnitt 2.4), die Änderung geht nicht still online.

### 4.3 Anzeige

- **Medienliste:** In der Spalte „Details“ steht „in 2 Seiten“ (Zahl, kein Text je Zeile; ein Durchlauf je Anfrage), anklickbar öffnet das Detailfeld.
- **Detailfeld:** komplette Liste mit Links (Abschnitt 7).
- **Editor:** Am Knopf- und Download-Block steht der Name der Datei und, wenn nicht verfügbar, ein roter Hinweis.

## 5. Veröffentlichen, Fassungen, Rollback

**Beim Bau.** `Renderer` bekommt `blockFile(int $id): ?array` (wie `blockPhoto`, mit Zwischenspeicher je Renderer) und `fileIds()` (wie `photoIds`, Seiten und Startseite). In `data()` wird je benutztem `file` geprüft; Fehler wie in Abschnitt 2.4 über `issue($msg, $hard)`. Der Probelauf nennt Seite, Blocknummer und Dateinamen (Dateiname erscheint im Admin nur maskiert, wie alle Texte aus Datenbanknamen).

**Dateien werden nicht in die Fassung kopiert.** Begründung: Fassungen sind reines HTML in `data/site/releases/<ID>/` (höchstens 20, wenige MB); `media/` ist wie bei den Bildvarianten gemeinsam und wird vom Webserver direkt geliefert. Würde man die Dateien in jede Fassung kopieren, hätte man bis zu 20 Kopien von 50-MB-PDFs, und große Dateien müssten über PHP statt direkt vom Webserver ausgeliefert werden. Der Preis ist die bekannte Schwäche aller Medien:

**Rollback.** Eine alte Fassung verlinkt auf die Adressen von damals. Ist die Datei inzwischen privat oder gelöscht, führt der Link nach einem Zurücksetzen ins Leere (bei Fotos genauso). Gegenmittel, klein:

- Das Manifest bekommt `file_ids` (neben `photo_ids`). Es dient „auf der Live-Seite“ (4.1).
- **Vor dem Zurücksetzen** zeigt die Seite „Veröffentlichen“ einen Hinweis „2 Dateien dieser Fassung sind inzwischen nicht mehr öffentlich; Links dorthin laufen ins Leere“ (Hinweis, keine Sperre; Zurücksetzen ist die Notbremse und muss immer gehen). Fassungen von **vor** diesem Stand haben kein `file_ids` und werden ohne Hinweis zurückgesetzt.

**Rennen: Datei wird privat gestellt, während veröffentlicht wird.** Der Bau läuft unter der Sperre `.lock`; Privatstellen nimmt sie nicht. Folge ohne Gegenmaßnahme: Die neue Fassung kann einen Link auf eine Kopie enthalten, die kurz vorher entfernt wurde (404 bis zum nächsten Veröffentlichen). Gegenmaßnahme: **kurz vor dem Umbenennen des Bauordners nach `releases/` (nicht erst vor `activate()`, sonst bleibt eine nie aktivierte, aber wählbare Fassung zurück; Prüfung 45, B3) werden alle `file_ids` der Fassung noch einmal frisch gegen die Datenbank und die Platte geprüft** (neue Abfrage, nicht der Zwischenspeicher des Renderers); ist eine inzwischen nicht verfügbar, bricht der Bau mit der neuen Meldung `datei` ab („Eine Datei wurde gerade privat gestellt oder gelöscht. Bitte noch einmal veröffentlichen.“, Eintrag in `Builder::FAIL_TEXT`), die bisherige Fassung bleibt. **Ehrlich:** Es bleibt ein Rest von Millisekunden zwischen Prüfung und Umschalten; er ist harmlos (404 auf eine Datei, die gerade gewollt entfernt wurde) und das Hinweisband „Veröffentlichen nötig“ steht ohnehin, weil jede Änderung in `/admin/medien` den Merker setzt (`AdminController::add`). Privatstellen mit der Sperre zu koppeln würde Medien bis zu 120 Sekunden blockieren und ist deshalb nicht vorgesehen.

**Rennen: Löschen einer Datei, die in Blöcken steht.** Löschen legt in den Papierkorb (30 Tage, wiederherstellbar). Die Seiten selbst bleiben unverändert gespeichert; der Probelauf meldet es. Wer die Datei zurückholt und wieder öffentlich schaltet, hat alles wie vorher (gleiche Id, gleicher `public_name` entsteht neu aus Token und Anzeigenamen; die **Adresse kann sich dabei ändern**, wenn die Datei zwischendurch umbenannt wurde. Für Blöcke mit `file` ist das egal, nur für Altverweise nicht, die aber `legacy_public_path` haben und dauerhaft öffentlich bleiben).

## 6. Wiederherstellung und Sicherungsformat

**Format (`docs/betrieb/Wiederherstellung.md`): bleibt unverändert** (`MANIFEST` Format 1, Schema 11). `files/` im Paket enthält die privaten Originale; die öffentlichen Kopien sind **abgeleitete Daten** wie Bildvarianten und gehören nicht ins Paket. Block-JSON, Einstellungen und Manifest stecken in der Datenbank.

**Heutige Lücke (gelesen, nicht gemessen):** `Restore` stellt Datenbank und `files/` her, aber nichts legt `media/dateien/<name>` wieder an. Nach einer Wiederherstellung auf neuem Server steht `public = 1` in der Datenbank, die Kopie fehlt: **jeder Link auf eine öffentliche Datei wäre tot** (auch die schon heute benutzten Altverweise auf `media/dokumente/`). Für Bildvarianten gibt es den Sammel-Knopf „Bildvarianten“; für Dateien noch nichts.

**Behebung (Stufe 1):** neue Methode `Files::repairPublicCopies(int $limit = 100)`:

- Für Zeilen mit `public = 1`, `deleted_at IS NULL`, gültigem Namen und vorhandenem privatem Original, aber **fehlender Kopie**: Kopie neu anlegen (Temporärdatei, `rename`, wie `setPublic`); für Altdateien nach `media/dokumente/<name>`.
- Fehlt auch das private Original: `public` auf 0 setzen und zählen (damit keine Zeile „öffentlich“ ohne Datei bleibt), Meldung in der Systemprüfung.
- Aufruf in `medienHousekeeping` (bei jedem Öffnen von Medien, billig: nur `is_file` je öffentlicher Datei) und als Zeile „Öffentliche Dateikopien“ in der Systemprüfung (`/admin/system`). Nach dem Einspielen einer Sicherung nennt die Abschlussseite den Weg („Öffentliche Dateien werden beim ersten Öffnen von Medien ergänzt“).
- `docs/betrieb/Wiederherstellung.md`: ein Satz zur Ableitung und ein Prüfpunkt in den Proben A und C („Datei-Download-Seite öffnet die PDF“).

## 7. Detailfeld in der Medienverwaltung (Teil D aus Entwurf 16)

**Inhalt** (ein Eintrag gewählt): Vorschau (Foto: 480-px-Variante; SVG und PNG/JPEG/WebP als `<img src=/admin/medien/datei/{id}>`; PDF: Symbol und Link „Öffnen“, keine Vorschau, bewusst kein Ghostscript), Name, Art und Typ (`mime`), Größe, Maße (Fotos), Datum, hochgeladen von (Benutzername aus `uploaded_by`), Ort (Brotkrumenpfad), **öffentlich/privat** mit dem vorhandenen Schalter, **Adresse kopieren** (volle Adresse mit `LK_BASE_URL`, sonst Pfad; Knopf mit `navigator.clipboard`, Rückfall: schreibgeschütztes Textfeld zum Markieren), „Verwendet in“ (Abschnitt 4), „Herunterladen“, bei Fotos der Link zu „Foto bearbeiten“.

**Aufbau ohne und mit Skript:**

- **Ohne Skript:** Link „Details“ je Zeile → `/admin/medien?ordner=…&detail=d12` (oder `f7` für Foto). Die Seite zeigt das Detailfeld unter der Liste. Das ist die Grundlage.
- **Mit Skript:** Klick auf eine Zeile (nicht auf Name oder Haken) lädt `GET /admin/medien/detail?art=datei&id=12` als JSON und baut das Feld **per `textContent`** in eine Seitenleiste; Esc schließt. (Kein `innerHTML` mit Serverausgabe; das hält die Regel der Medienseite „Ausgabe nur per `textContent`“ ein.) Tastatur: Enter auf der Zeile öffnet, Fokus wandert ins Feld, Esc bringt ihn zurück.
- Gleiche Logik für beide: eine PHP-Funktion `MedienPages::detailData(art, id)` liefert das Datenarray; Vorlage und JSON-Route benutzen es.

**Nicht enthalten:** Ersetzen des Inhalts einer Datei (stabiler Link, neue Fassung), Beschreibung/Alt-Text für Dateien. Beides eigener Entwurf (Abschnitt 12).

## 8. Oberfläche im Seiten-Editor

### 8.1 Knopf-Block „Datei aus Medien“

Beim Ziel-Feld zwei Möglichkeiten (Auswahl als Reiter oder zwei Knöpfe, per Tastatur erreichbar):

- **„Adresse eintippen“** (heutiges Feld, unverändert).
- **„Datei aus Medien“:** Knopf öffnet den vorhandenen Auswahldialog (`pick()`-Bauart) mit Suche, Liste öffentlicher Dateien (Name, Art, Größe, Ordner), „Mehr laden“. Gewählt: Der Block zeigt „PDF · Preisliste 2026 · 1,2 MB“ mit „Andere wählen“ und „Entfernen“ (zurück zu Adresse). Die Beschriftung wird vorbelegt, wenn sie leer ist.
- Quelle: `GET /admin/dateien/auswahl?q=&seite=&n=60` (Metadaten, nur `public = 1`, nicht im Papierkorb, SVG nur bei bestandenem Selbsttest; höchstens 60 je Seite; Suche über Name und Ordnerpfad).
- Der Editor bekommt wie bei Fotos eine Tabelle `files` im Startdaten-JSON (`editorPayload`): Id → `{n: Name, k: Art, b: Bytes, x: 0|1}` für alle in Blöcken benutzten Dateien (Server baut sie aus der Datenbank; `x=1` = nicht verfügbar). Der Editor vertraut dem nicht für Sicherheit, nur für Anzeige.

### 8.2 Datei-Download-Block

Neue Karte „Datei zum Herunterladen“ (Name, ein Satz) in `TYPES`, `ALL`, `SIMPLE`. Formular: „Datei wählen“ (wie oben), „Titel (optional, sonst Dateiname)“, „Stil“ (Normal, Auf heller Fläche). Ein neuer Block ohne Datei öffnet sofort den Dateidialog (wie beim Bildblock).

**Ausgabe (Server, `Blocks::one`):**

```html
<p class="bfile s-flaeche"><a class="bfile-link" href="/media/dateien/ab12cd34-preisliste.pdf"><span class="bfile-title">Preisliste 2026</span><span class="bfile-meta">PDF · 1,2 MB</span></a></p>
```

Gestaltet in `site.css` mit den vorhandenen Variablen aller sechs Designs (Haarlinie, 2-px-Radius je Design wie die übrigen Blöcke), hell und dunkel, Kontrast mit `tools/kontrast.py`. Die Linkfläche ist die ganze Zeile (Tastaturfokus sichtbar), keine Symbolschrift, kein Emoji.

### 8.3 Seitenstreifen „Material“, Reiter „Dateien“

- Dritter Reiter neben Fotos und Galerien in `page-material.js`, gleiche Suche und „Mehr laden“ (60 je Seite), Quelle `/admin/dateien/auswahl`.
- **Ziehen:**
  - Datei auf einen **Knopf-Block** oder **Download-Block**: setzt/ersetzt `file` (beim Knopf zusätzlich `href` leeren und leere Beschriftung vorbelegen).
  - Datei auf einen **freien Einfügepunkt**: neuer **Download-Block** (für alle Arten, auch Bilddateien).
  - Datei auf einen anderen Blocktyp: abgelehnt mit Hinweis, wie bei Fotos („Das geht nicht in einen Text-Block“).
- **Ohne Maus („Wohin?“):** Enter oder Antippen auf der Kachel → Liste: „Als Ziel von Knopf in Block N“, „Datei im Block N ersetzen“, „Neuer Datei-Block hier / am Ende / hinter Block N“. Spalten werden wie bei Fotos behandelt.
- **Rückgängig** gilt wie bei Fotos (Strukturänderung, Stapel höchstens 30).
- **Private Dateien erscheinen nicht.** Ist die Liste leer oder sucht jemand ohne Treffer, steht dort: „Hier erscheinen nur öffentliche Dateien. In Medien bei der Datei ‚Öffentlich schalten‘ wählen.“ mit Link „Medien öffnen“ (neuer Tab). Eine private Datei lässt sich nicht einfügen, auch nicht per Tastatur.
- Fremde Dateien aus dem Finder bleiben ausgeschlossen (nur Zieh-Daten der eigenen Kacheln, wie in Entwurf 29 Abschnitt 4).
- Handy: Kachel antippen, „Wohin?“; HTML5-Ziehen am Touch ist weiter nicht Teil.

## 9. Logo aus Medien (Teil F aus Entwurf 16)

**Vorgehen.** Die drei Auswahlfelder auf `/admin/logos` (hell, dunkel, Favicon) bekommen zusätzlich eine Gruppe „Aus Medien“ mit geeigneten Dateien. **Ohne Skript, ohne neue Route**: ein `<select>`, wie heute. Der Wert wird `f<id>` (z. B. `f12`); die bisherigen 16-stelligen Kennungen bleiben gültig und unverändert. Die bisherigen Logos (Data-URL-Upload) und ihr Test bleiben.

**Geeignet:** Art `svg`, `png`, `webp` (JPEG nicht: kein GPS-Entfernen, und jedes Logo steckt in jeder Seite), **höchstens 300 KB** (`Settings::LOGO_MAX`), nicht im Papierkorb. **Privat genügt**: Das Logo wird wie heute als Data-URL in das HTML eingebettet; dafür braucht es keine öffentliche Kopie und keinen SVG-Selbsttest. Gelesen wird die private Datei über `Files::absolute()` (Muster-geprüfter Pfad aus der Datenbank), nie über einen Pfad aus der Eingabe. SVG wurde beim Hochladen bereits durch `SvgCheck::sanitize` bereinigt und läuft für Logos nicht anders als bisher (`<img>`-Einbettung, kein Inline-SVG).

**Umsetzung.** `Settings` bekommt `Files` als **optionalen** letzten Konstruktor-Parameter (`null` = „aus Medien“ ausgeschaltet; `Services.php` setzt es, Tests und alte Aufrufe bleiben gültig). `logoChoice`, `chooseLogos`, `logoUri` (und damit `logoPair`, `favicon`, `footerLogo`) erkennen `f<id>`. Wird die gewählte Datei später gelöscht/ungeeignet, fällt `logoChoice` für diese Rolle auf „keines“ zurück (Seitenname als Schrift, wie ohne Logo) und der Probelauf nennt eine **Warnung** (kein harter Fehler, anders als bei Blöcken: Es gibt einen sinnvollen Ersatz, und das Logo steht auf jeder Seite).

„Verwendet in“ zählt die Rollen auf („Logo für den hellen Modus“, mit Link auf `/admin/logos`); die Bestätigungsseite aus 4.2 gilt auch hier.

## 10. Einzel-Upload ohne Skript

Heute braucht jedes Hochladen JavaScript (Upload in Stücken). Für Browser ohne Skript:

- **Formular** auf `/admin/medien`: `<form method="post" enctype="multipart/form-data" action="/admin/medien/hochladen">` mit einem Feld `datei` (**eine** Datei), `ordner`, `_csrf`. Nur sichtbar, wo es nützt (im `noscript`-Block, der Hinweis „braucht JavaScript“ wird zu „Ohne JavaScript ist nur eine Datei je Mal möglich, höchstens X MB“).
- **Grenze:** `min(ini upload_max_filesize, ini post_max_size, App-Grenze der Art)`, angezeigt. Größere Dateien lehnt PHP ab, bevor Lichtkammer läuft; die Seite erkennt das (`CONTENT_LENGTH` über `post_max_size`, `$_POST` leer) und zeigt eine verständliche Meldung statt „CSRF ungültig“. Auf all-inkl ist die Grenze nicht gemessen; die Systemprüfung zeigt sie schon (`SystemCheck`).
- **Annahme:** Nur `UPLOAD_ERR_OK`, `is_uploaded_file()`, Größe aus `filesize`, **Name nur aus `$_FILES['name']` und nur als Anzeigename** (`Names::fileName`, nie in einen Pfad). Die Datei wird nach `move_uploaded_file` in den vorhandenen Upload-Zwischenordner im Datenordner verschoben und geht dann durch **dieselben Annahmefunktionen** wie der Stückweg: Art per Inhalt (`Files::detect`), Fotos über `Library::ingest` (dann „wartet auf Aufbereiten“, das vorhandene Formular „Aufbereiten“ geht ohne Skript), Dateien über `Files::ingest`. Dieselben Protokoll-Ereignisse (`photo_uploaded`, `file_uploaded`), dieselbe Rolle, dieselbe CSRF-Prüfung.
- **Zu klären vor dem Bau (nicht belegt):** wie `Request` und `Router` mit `multipart/form-data` umgehen (PHP füllt `$_POST` und `$_FILES`, aber nicht `php://input`; ein Router, der den Körper roh liest, würde nichts sehen) und ob die Ratenbegrenzung der Upload-Routen passt. Daraus kann ein kleiner Zusatz in `Request` folgen (`Request::upload(string $field)` mit allen Prüfungen an **einer** Stelle).
- **Nutzen ehrlich:** gering (Matthias arbeitet mit Browser mit Skript; Barrierefreiheit und Grundsatz „ohne Skript funktioniert die Verwaltung“ aus Entwurf 16). Deshalb als **letzte, optionale Stufe**, und es ist die einzige Stufe, die eine **neue Annahmestelle für Dateien** schafft (Fable-Pflicht).

## 11. Tests und Prüfpunkte

Neue Datei `tests/dateiblocks.php`, Ergänzungen in `tests/bloecke.php`, `tests/medien.php`, `tests/startseite.php`, `tests/sicherung.php`.

| Gruppe | Prüft |
|---|---|
| **Format** | Alte Seiten (Beispielseiten, WordPress-Import): `parse` → `encode` byte-gleich, auch nach Hin- und Herladen in den Editor-JSON; Knopf mit `file` ohne `href`; Knopf mit beidem: `href` wird `''`; `file` als Text (nicht nur Ziffern), Fließkomma, negativ, riesig, Liste, `null`: wird „keine Datei“, nie ein Fehler außer bei Pflicht (Download-Block); `title` 121 Zeichen gekürzt; unbekannter `style` = Vorgabe; `download` in Spalte erlaubt, `fileIds` rekursiv, `toMarkdown` gibt `null` |
| **Speichern** | unbekannte Id → Meldung und Eingaben bleiben; Papierkorb-Datei → Meldung; **private Datei wird gespeichert** (Prüfung erst beim Bau) |
| **Bau** | öffentliche Datei: Link, Titel, Endung, Größe aus der Datenbank (nicht aus dem Block, auch wenn dort Fremdes steht); privat, im Papierkorb, gelöscht, Kopie fehlt, `public_name` mit Sonderzeichen oder `../`, `legacy_public_path` ungültig: **harter Fehler** (veröffentlicht), Warnung (Entwurf), Platzhalter in der Vorschau, **nie** ein Link; Datei in Ordner im Papierkorb mit noch gesetztem `public=1`: nicht verfügbar; Titel mit `<script>` und Markdown: maskiert; kein `javascript:`/`data:`/`//` möglich; Vorschau-Adresse ohne Präfix; `link()` setzt für `/media/` kein Präfix |
| **Manifest und Rollback** | `file_ids` im Manifest; Zurücksetzen mit nicht mehr öffentlicher Datei: Hinweis, Zurücksetzen klappt; Fassung ohne `file_ids`: kein Hinweis |
| **Rennen** | Datei zwischen Probelauf und Umschalten privat gestellt: Bau bricht mit `datei` ab, alte Fassung bleibt (Test über Hook im Bau); privat stellen setzt den Merker „Veröffentlichen nötig“ |
| **Verwendet in** | Datei in Seite, Spalte, Startseite; Altverweis (Adresse im Block und im Markdown-Text); Foto in Galerie, Block, Titelbild; Logo-Rolle; Live-Kennzeichen; Menge mit 200 Einträgen in einem Durchlauf (Zahl der Abfragen begrenzt) |
| **Warnung** | Privat/Papierkorb/Ordner ohne Verwendung: sofort ausgeführt; mit Verwendung: Bestätigungsseite, nichts geändert; `bestaetigt=1`: ausgeführt; ohne CSRF 403; Ausgabe maskiert |
| **Wiederherstellung** | Einspielen einer Test-Sicherung mit öffentlicher Datei: Kopie fehlt, nach `repairPublicCopies` da; fehlendes privates Original: `public=0`; Altdatei: Kopie in `media/dokumente`; Probe A und C im Skript `tools/restore-probe.sh` um die Dateilinks erweitern |
| **Logo** | `f<id>` wählbar, Data-URL im HTML; zu groß, JPEG, Papierkorb, andere Art: nicht wählbar; Datei später gelöscht: Seitenname als Schrift und Warnung; alte 16-stellige Logos und die bisherigen Logo-Tests unverändert grün; `Settings` ohne `Files` verhält sich wie bisher |
| **Auswahl und Detail** | nur öffentliche, nur angemeldet, `no-store`, Seitengröße, Ratenbegrenzung; Detail für Foto und Datei, ungültige Id, fremde Art; JSON enthält keine Adresse einer privaten Datei |
| **Einzel-Upload** | (Stufe 6) Annahme je Art, falscher Typ, zu groß, `UPLOAD_ERR_*`, Name mit `../`/Bidi, ohne CSRF 403, nur Rolle erlaubt, Dublette |
| **Browser (Prüfliste, Chromium und Firefox, echte Maus)** | Datei auf Knopf ziehen, auf freie Stelle, auf Textblock (abgelehnt), „Wohin?“ per Tastatur, Rückgängig nach Einsetzen (Fehler B1 aus Prüfung 42 betraf Fotos; hier gleich mitprüfen), private Datei nicht ziehbar, Hinweistext, Leere Liste, Bestätigungsseite ohne Skript, Detailfeld mit und ohne Skript, Handy 375 px, alle sechs Designs hell/dunkel für den Download-Block. **Safari am Mac: Lutz** (wie bei D und F) |

**Fable prüft vor dem Hochladen:** (1) Datenformat und Id-Auflösung (`Blocks::norm`, `Renderer::blockFile`, Muster der Adresse, Abbruch vor `activate`), (2) Wiederherstellungsweg `repairPublicCopies` (schreibt in `media/` nach Namen aus der Datenbank), (3) Logo aus Medien (liest eine private Datei in jede Seite), (4) falls gebaut: die neue Annahmestelle aus Stufe 6, (5) die beiden neuen Lese-Routen. **Opus prüft:** diesen Entwurf vor dem Bau; danach den Diff einmal je Block (Stufen 1 bis 5), Stufe 6 gesondert, falls sie gebaut wird.

## 12. Stufen, Reihenfolge, Aufwand

Jede Stufe lässt Admin und Seite lauffähig; nach jeder kann man **stoppen**. Veröffentlicht (auf den Testserver) wird gesammelt am Ende des Blocks. Aufwand grob für Sonnet inklusive Tests; ein Testlauf dauert wenige Minuten im Docker-Image.

| Stufe | Inhalt | Risiko | Umfang |
|---|---|---|---|
| **1 Kern** | `Blocks` (`file` im Knopf, Typ `download`, `fileIds`), `Files::publicUrlOrNull`, `Renderer::blockFile` + Fehler im Probelauf + Vorschau-Platzhalter, Ausgabe und CSS, Manifest `file_ids`, Abbruch vor `activate` (`datei`), `link()` ohne Präfix für `/media/`, `Files::repairPublicCopies` + Housekeeping + Systemprüfung, Tests (Format, Bau, Rennen, Wiederherstellung). **Editor noch unverändert**, es gibt also keine neuen Blöcke von Hand; getestet über JSON. | hoch | mittel (ca. 350 Zeilen PHP, 40 Tests) |
| **2 Verwendet in und Warnungen** | `Media/Usage`, Bestätigungsseite für Privat/Papierkorb/Ordner/Endgültig, Spalte „in N Seiten“, Rollback-Hinweis, Meldung nach Wiederherstellen einer benutzten Datei | mittel | mittel |
| **3 Detailfeld** | `detailData`, Route `GET /admin/medien/detail`, Vorlage ohne Skript, Seitenleiste mit Skript, Adresse kopieren | mittel | mittel |
| **4 Editor** | Dateidialog, Knopf-Block „Datei aus Medien“, Download-Block, Reiter „Dateien“ im Material, Ziehen und „Wohin?“, `files`-Tabelle im Startdaten-JSON, Route `/admin/dateien/auswahl`, Browser-Prüfliste | mittel | groß (JS ca. 250 Zeilen, Test der Route) |
| **5 Logo aus Medien** | `Settings` mit `Files`, `f<id>`, Auswahlgruppe, Warnung, „Verwendet in“ für Logos | mittel | klein bis mittel |
| **6 Einzel-Upload ohne Skript** (optional) | Formular, Route, `Request::upload`, Meldungen, Tests | hoch | klein bis mittel, aber neue Annahmestelle |

**Reihenfolge und Begründung.** 1 vor 4, weil der Editor nur etwas einsetzen darf, was der Server prüfen und bauen kann. **2 vor 4**, damit niemand Dateien in Seiten ziehen kann, bevor das Privatstellen davor warnt (sonst baut man Matthias eine Falle). 3 kann auch nach 4 kommen; es steht vorn, weil das Detailfeld den „Verwendet in“-Dienst zeigt und ihn so in der Praxis prüfbar macht. 5 ist unabhängig von 4. 6 zuletzt.

**Kleinster sinnvoller Halt:** nach Stufe 1 bis 4 haben Lutz und Matthias den Kern (Datei in Knopf und Block per Ziehen, Warnung beim Löschen). 3, 5, 6 sind einzeln streichbar.

## 13. Risiken (zusammengefasst)

| Risiko | Stufe | Maßnahme |
|---|---|---|
| Datei wird privat/gelöscht, Seite baut tote Links | 1, 2 | harter Fehler im Probelauf; Bestätigungsseite; Prüfung vor `activate` |
| Rollback führt auf Link ins Leere | 1 | Hinweis mit `file_ids`; Zurücksetzen bleibt erlaubt |
| Veröffentlichen blockiert wegen vergessener Datei | 1, 2 | klare Meldung mit Seite und Block; Warnung vorher; Frage 1 |
| Alte Blöcke ändern sich | 1 | `file` nur wenn gesetzt, Test byte-gleich auf Importseiten |
| Kopie fehlt nach Wiederherstellung (heute schon) | 1 | `repairPublicCopies`, Prüfung im Bau, Systemprüfung |
| Falsche Adresse aus handveränderter Datenbank | 1 | Muster vor jeder Ausgabe (`publicUrlOrNull`) |
| Altverweise (`media/dokumente`) bleiben ohne Id | 2 | Zeichenfolgensuche in „Verwendet in“; keine automatische Umstellung |
| Logo aus Medien vergrößert jede Seite | 5 | höchstens 300 KB, nur SVG/PNG/WebP |
| Rechenaufwand „Verwendet in“ bei vielen Seiten | 2 | ein Durchlauf je Anfrage, Mengen statt Einzelabfragen |
| Downgrade auf älteren Code: `download` und `file` unbekannt | alle | 0.x, nur Testserver; Hinweis in den Release-Notizen: vorher Datei-Blöcke entfernen |
| Neue Annahmestelle ohne Skript | 6 | gleiche Annahmefunktionen, Fable-Prüfung, optional |
| Datei „Ersetzen“ fehlt (neue PDF unter gleicher Adresse) | – | bewusst nicht Teil; Umweg: neue Datei hochladen, Block neu wählen |

**Nicht Teil dieses Entwurfs:** Ersetzen des Inhalts einer Datei, Alt-Text und Beschreibung für Dateien, Vorschaubild für PDFs, Zähler für Downloads, Bilddateien als Bildblock, ZIP/Office, Datei-Upload per Ziehen vom Finder in den Editor, „Alte Adressen automatisch auf Ids umstellen“.

## 14. Offene Fragen an Lutz (höchstens vier, mit Empfehlung)

1. **Fehlt eine benutzte Datei beim Veröffentlichen: stoppen oder Block weglassen?** *Empfehlung:* **Stoppen** mit klarer Meldung (wie bei gelöschten Fotos), und vorher warnen. Alternative: Block still weglassen mit Warnung; weniger Ärger, aber eine PDF kann unbemerkt von der Seite verschwinden.
2. **Warnung beim Privatstellen und Löschen nur als Bestätigung oder als Sperre?** *Empfehlung:* **Bestätigung** („Trotzdem ausführen“). Eine Sperre würde Aufräumen und Papierkorb behindern; der Probelauf fängt den Rest ab.
3. **Bilddateien (PNG, SVG, JPEG, WebP) in Seiten nur als Download-Block?** *Empfehlung:* **Ja.** Einbinden als Bild bleibt Fotos vorbehalten (Bildpipeline, GPS-Entfernen); es gibt keinen zweiten neuen Blocktyp. Logos kommen über die Logo-Einstellung (Stufe 5).
4. **Stufe 6 (Einzel-Upload ohne Skript) mitbauen oder streichen?** *Empfehlung:* **Zunächst streichen**, bei Bedarf später; sie ist die einzige Stufe mit neuer Annahmestelle und hat für Matthias kaum Nutzen.

## 15. Entscheidungen von Lutz (20.11.2026)

Alle vier Fragen aus Abschnitt 14 sind **wie empfohlen** entschieden:

1. Fehlt beim Veröffentlichen eine benutzte Datei: **Stoppen** mit klarer Meldung (Probelauf), nie ein Block still weglassen.
2. Warnung beim Privatstellen und Löschen als **Bestätigung** („Trotzdem ausführen“), keine Sperre.
3. Bilddateien (PNG, SVG, JPEG, WebP) in Seiten **nur als Download-Block**; Bilder in Seiten bleiben den Fotos vorbehalten; Logos über die Logo-Einstellung (Stufe 5).
4. **Stufe 6 (Einzel-Upload ohne Skript) entfällt** vorerst.

**Bauplan:** Block 1 = Stufen 1a, 1b und 2 (Server: Datenformat, Bau, Reparatur, „Verwendet in“, Warnungen; Editor noch unverändert). Block 2 = Stufen 3, 4 und 5 (Detailfeld, Editor, Logo). Je Block eine Opus-Prüfung; **Fable prüft vor dem Hochladen des Codes** (Dateiauslieferung, Datenformat). Auflagen B1 bis B5 aus Bericht 45 gelten vor dem Bau von Stufe 1.

