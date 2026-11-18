# Entwurf: Medienverwaltung (Ordner wie im Finder, Ordner-Upload, PDFs und Logos)

Stand: 18.11.2026. **Entwurf, wartet auf Freigabe von Lutz. Risiko „hoch“** (Dateiannahme, Pfade, aktive Inhalte in SVG und PDF, Auslieferung). Deshalb prüft Opus diesen Entwurf, bevor gebaut wird. Nichts davon ist gebaut, keine Migration geschrieben, nichts auf dem Testserver.

Grundlage: Entwürfe `10` (Bildverwaltung), `11`, `12`, `14`, `15`, Plan `03`, Code unter `app/src/Media`, `Site/Settings.php` (Logos), `Import/Importer.php` (PDF), `Auth/Audit.php`, `SecurityHeaders.php`, `public/media/.htaccess`.

## 1. Wunsch und Kern der Empfehlung

Lutz will einen Bereich im Admin, der wie die Ordnerstruktur im macOS-Finder aussieht. Ganze Ordner (mit Unterordnern) vom Mac, Windows oder Linux sollen per Drag and Drop hochladbar sein. Beim Hochladen wählt man, ob ein Ordner auch eine Galerie wird. Daneben liegen PDFs und Logos, die mit Galerien nichts zu tun haben und ebenfalls verwaltet werden müssen.

**Empfehlung in einem Satz:** Ordner sind *nur Einträge in der Datenbank* (Baum aus Ordnername und Eltern). Auf der Festplatte des Servers liegt jede Datei weiter unter einem Zufallsnamen. Der Name oder Pfad aus dem Browser wird nie zu einem Pfad auf dem Server. Damit sind Pfadangriffe (`..`, absolute Pfade, Windows-Namen) strukturell ausgeschlossen, und Umbenennen, Verschieben und Papierkorb sind reine Datenbankvorgänge.

Neu im Admin: Menüpunkt **Medien** (`/admin/medien`). **Fotos** (Mediathek, Filter, Bearbeiten) und **Galerien** bleiben unverändert; Medien ist die Ordneransicht auf dieselben Fotos plus Dateien.

## 2. Datenmodell (Migration 10, nur additiv)

Bestehende Tabellen und Spalten bleiben unverändert. Fotos, Galerien, `gallery_photos`, Bildpipeline und Import laufen wie bisher.

### 2.1 Neue Tabelle `folders`

| Spalte | Bedeutung |
|---|---|
| `id` | Schlüssel |
| `parent_id` | Eltern-Ordner, `NULL` = oberste Ebene („Medien“) |
| `name` | Anzeigename (NFC-normalisiert, geprüft, siehe 5.1) |
| `name_key` | Vergleichsschlüssel (NFC, kleingeschrieben) |
| `sort_order` | Reihenfolge, Standard alphabetisch (natürliche Sortierung, „Bild 2“ vor „Bild 10“) |
| `gallery_id` | optional: Galerie, die aus diesem Ordner entstand (Merkhilfe, siehe 3.4) |
| `created_at`, `updated_at`, `deleted_at` | Papierkorb wie bei Fotos |

Eindeutig: `(parent_id, name_key)` als **Teilindex `WHERE deleted_at IS NULL`**; die oberste Ebene ist `parent_id = 0` (`NOT NULL DEFAULT 0`, ohne Fremdschlüssel, kein `COALESCE`). Ordner im Papierkorb blockieren so keinen Namen (Prüfung 17, H1). Zwei Ordner gleichen Namens in einem Ordner gibt es also nicht, auch nicht bei „Island“ und „island“ oder mit Mac-Zerlegung (NFD) und Windows-Zusammensetzung (NFC) von „ü“.

Grenzen: Tiefe höchstens 8, höchstens 5 000 Ordner insgesamt, Namenslänge höchstens 255 Zeichen (Zeichen, nicht UTF-16-Einheiten wie macOS; folgenlos, da Namen nie Pfade sind).

### 2.2 Zuordnung Foto → Ordner

`ALTER TABLE photos ADD COLUMN folder_id INTEGER REFERENCES folders(id) ON DELETE SET NULL`, dazu Index. `NULL` = oberste Ebene. Alle heutigen Fotos (auch die aus dem WordPress-Import) bleiben dort; nichts wird automatisch umsortiert.

**Ein Foto liegt in genau einem Ordner, aber weiter in beliebig vielen Galerien** (Entwurf `10`, E1). Der Ordner ist „wo es abgelegt ist“, die Galerie „wo es gezeigt wird“. Titel, Alt-Text und die übrigen Fotodaten gehören weiter dem Foto.

### 2.3 Neue Tabelle `files` (alles, was kein Foto ist)

`id`, `token` (Zufall, 24 Hex), `folder_id`, `name` (Anzeigename), `kind` (`pdf` oder `svg` oder `image`), `mime` (am Inhalt festgestellt), `bytes`, `sha256` (eindeutig, Dubletten wie bei Fotos), `path` (relativ im Dateiordner, Zufallsname), `public` (0/1), `public_name` (nur wenn öffentlich), `uploaded_by`, `created_at`, `updated_at`, `deleted_at`.

`kind = image` sind Bilder, die ausdrücklich **keine Fotos** sein sollen (Logo als PNG, Grafik): sie laufen *nicht* durch die Bildpipeline, bekommen keine Varianten und kein GPS-Entfernen (wird beim Hochladen im Dialog gewählt, siehe 3.2; Fotos sind der Standard).

### 2.4 Änderung `uploads`

Zwei neue Spalten: `folder_id` und `intent` (`photo` oder `file`). Der vorhandene Upload in Stücken (`start`, `chunk`, `finish`, `.part`-Dateien im Datenordner, 24 Stunden Aufräumen) wird weiterverwendet. `Library::ingest()` bekommt einen **optionalen** letzten Parameter `folderId` (Standard `null`), damit alte Aufrufer (Import) unverändert laufen.

### 2.5 Ablageorte

| Was | Ort | Öffentlich |
|---|---|---|
| Fotos: Originale | `originals/` (wie bisher, nie überschrieben) | nein |
| Fotos: Varianten | `media/<xx>/<token>-<breite>.<format>` (wie bisher) | ja |
| Dateien (PDF, SVG, Bild): Original | `<LK_DATA_DIR>/files/<xx>/<token>.<ext>` (neu, Zufallsname, Endung aus dem erkannten Typ, nie aus dem Hochladenamen) | nein |
| Dateien, wenn „öffentlich“ | Kopie in `media/dateien/<token8>-<name-als-slug>.<ext>` | ja |

Begründung: Nicht jedes PDF und Logo soll öffentlich sein (Entwürfe, Rechnungen, Quellen). Privat ist der Standard; erst der Schalter „Öffentlich“ legt eine Kopie in `media/` ab, die der Webserver direkt ausliefert (schnell, keine Datenbank, wie die Bildvarianten). Zurückschalten löscht die Kopie. Der Dateiordner liegt unter `LK_DATA_DIR` (kein neues Volume in Docker, keine neue Einstellung auf all-inkl) und gehört in die Datensicherung.

Der vorhandene öffentliche Ordner `media/dokumente/` (PDF aus dem WordPress-Import, Adressen `/media/dokumente/<name>.pdf` sind schon in Seiten und Weiterleitungen verwendet) bleibt **unverändert**; ein einmaliger, bewusst ausgelöster Knopf „Bestand übernehmen“ trägt diese PDFs als öffentliche Dateien in einen Ordner „Dokumente“ ein, ohne sie zu verschieben (Teilschritt F).

### 2.6 Logos

Die Logos aus den Einstellungen (`site_settings`, Ordner `data/brand`) bleiben unverändert und funktionsfähig. Erst in Teilschritt F kann die Auswahl „Logo für hell / dunkel / Favicon“ zusätzlich aus Medien wählen (SVG und PNG). Bis dahin liegen Logos in Medien als normale Dateien (z. B. zur Ablage für das Material), ohne die Einstellungen zu berühren.

### 2.7 Papierkorb

Wie Fotos: `deleted_at`, 30 Tage, Aufräumen beim Aufruf (`Catalog::TRASH_DAYS`). Löschen eines Ordners legt den Ordner und alles darunter (Unterordner, Fotos, Dateien) in den Papierkorb und trägt in allen die Kennung des gelöschten Ordners als `trash_root_id` ein (nicht nur den Zeitstempel), damit **Wiederherstellen** genau diesen Zweig zurückholt. Löschen und Wiederherstellen laufen in Stapeln (z. B. 100 Fotos je Anfrage); wiederhergestellte Fotos werden auf `pending` gesetzt und über den vorhandenen Verarbeitungsweg nachgezogen, nicht in einer Anfrage verarbeitet. Namenskonflikt beim Wiederherstellen: Zusammenführen, nie scheitern. Hochladen oder Verschieben in einen Ordner im Papierkorb wird abgelehnt. Einzelheiten und offene Punkte: `17 Pruefung Entwurf Medienverwaltung.md`, H1. Endgültiges Löschen (nach 30 Tagen oder per Knopf) entfernt Datei, öffentliche Kopie und Zeile; bei Fotos wie heute die Originale und Varianten.

## 3. Ordner und Galerie

### 3.1 Begriffe

Ordner = Ablage. Galerie = öffentliche Auswahl. Ein Ordner kann eine Galerie *erzeugen*; danach sind beide unabhängig (Galerien bleiben n:m, mit eigener Reihenfolge, Titelbild und Status).

### 3.2 Auswahl beim Hochladen

Nach dem Ablegen eines Ordners zeigt ein Fenster **vor dem Start** die Zusammenfassung („3 Ordner, 212 Dateien, 1,4 GB; 4 Dateien werden übersprungen: nicht erlaubter Typ“) und die Wahl:

1. **Nur ablegen, keine Galerie** (Vorgabe, merkt sich die letzte Wahl).
2. **Jeder Ordner mit Fotos wird eine Galerie.** Ein Ordner „Island“ mit Unterordnern „Tag 1“ und „Tag 2“ ergibt Galerien „Island“ (nur die Fotos direkt darin), „Tag 1“ und „Tag 2“.
3. **Nur der oberste Ordner wird eine Galerie**, alle Fotos aus allen Unterordnern kommen hinein (flach, Reihenfolge nach Ordner, dann Dateiname).

Darunter eine Liste der Ordner mit Haken je Ordner und dem vorgeschlagenen Galerietitel (der Ordnername, im Fenster änderbar). Wer nur einzelne Ordner als Galerie will, hakt ab. Zusätzlich ein Schalter **„Bilder als Fotos“ (Vorgabe) oder „als Dateien (Logos, Grafiken)“**. SVG und PDF sind immer Dateien, nie Fotos.

Regeln:

- Neue Galerien sind immer **Entwurf**; nichts wird durch den Upload öffentlich. Die Alt-Text-Pflicht (Entwurf `10`) gilt wie sonst beim Veröffentlichen.
- Adresse (`slug`) wie bisher aus dem Titel, eindeutig; gleiche Titel bekommen Zähler (`island-2`).
- Nur Fotos kommen in Galerien. PDFs und Dateien nie.
- **Reihenfolge:** Dateien werden vor dem Start nach natürlicher Namenssortierung (ohne Groß-/Kleinschreibung, Zahlen als Zahlen) geordnet und nacheinander hochgeladen (immer ein Foto zugleich); die Position in der Galerie ergibt sich aus dieser Reihenfolge. Danach lässt sich wie gewohnt per Ziehen oder Sortierknopf ändern.
- **Dubletten** (gleicher Inhalt, SHA-256, vorhandene Prüfung): kein zweites Foto. Es bleibt an seinem alten Platz, wird aber in die neue Galerie aufgenommen. Die Abschlussmeldung nennt „schon vorhanden in Ordner …“ (Name des Ordners, nur Anzeige). Dublette im Papierkorb: Hinweis mit Weg zur Wiederherstellung, wie bisher.
- Ordner mit dem gleichen Namen auf der Zielebene werden **zusammengeführt** (Inhalt dazu), nicht doppelt angelegt; die Zusammenfassung sagt das („Ordner ‚Island‘ gibt es schon: Inhalt wird ergänzt“).

### 3.5 Name des Ordners = Name im Medienbereich (Entscheidung von Lutz, 18.11.2026)

- **Übernahme:** Der Name des hochgeladenen Ordners wird unverändert der Name im Medienbereich. Keine Nachfrage, kein Umbenennen. Auch bei Unterordnern gilt je Segment der Originalname.
- **Unicode:** Auf dem Mac (APFEL-Dateisystem, Finder) ist „ü“ oft zerlegt (NFD), unter Windows und Linux zusammengesetzt (NFC). Der Server speichert **NFC**; das sieht identisch aus, ändert also nichts Sichtbares, und gleiche Namen aus verschiedenen Systemen werden als gleich erkannt.
- **Zeichen:** Nur Unsichtbares und Täuschendes wird entfernt (5.1). Alles andere bleibt, auch Zeichen, die Windows nicht mag. Geändertes steht in der Zusammenfassung vor dem Start („Name enthielt unsichtbare Zeichen, entfernt“) und im Abschlussbericht.
- **Groß-/Kleinschreibung:** „Island“ und „island“ gelten als derselbe Ordner (wie im Finder und unter Windows); beim Vergleich wird nicht unterschieden, **angezeigt wird die Schreibweise, mit der der Ordner zuerst angelegt wurde**.
- **Gleicher Name wie ein vorhandener Ordner** auf der Zielebene: der Inhalt wird **zusammengeführt** (Dateien kommen dazu, Unterordner gleichen Namens ebenso), der vorhandene Ordner behält seinen Namen. Die Zusammenfassung sagt es vorher („Ordner ‚Island‘ gibt es schon, Inhalt wird ergänzt“). Dateien gleichen Inhalts entfallen als Dublette (3.2), gleichnamige Dateien verschiedenen Inhalts bleiben nebeneinander bestehen (Dateinamen müssen nicht eindeutig sein, wie bei Fotos; im Anzeigenamen kein Zusatz).
- **Galeriename:** Der **Titel der Galerie ist genau der Ordnername** (NFC, wie angezeigt), in der Zusammenfassung vorausgefüllt und dort änderbar, aber ohne Änderung wird er so übernommen. Die **Adresse (`slug`)** wird wie bisher daraus gebildet (Umlaute zu `ae`, `oe`, `ue`, `ss`, Kleinbuchstaben, Sonderzeichen zu Bindestrich) und bei Kollision mit einer vorhandenen Galerie durch Zähler eindeutig (`island-2`). Ein Name aus lauter Zeichen ohne Umsetzung (z. B. nur Emojis) bekommt die Adresse `galerie`/`galerie-2`. Gibt es schon eine Galerie mit **gleichem Titel**, wird trotzdem eine neue angelegt (Titel dürfen doppelt sein, die Adresse ist eindeutig), die Zusammenfassung warnt davor und bietet „zu vorhandener Galerie hinzufügen“ an (Empfehlung: Warnung, kein stilles Zusammenführen, da Galerien öffentlich werden).
- **Nachträgliches Umbenennen** des Ordners ändert den Titel der verknüpften Galerie **nicht** (und umgekehrt), die Verknüpfung ist nur eine Merkhilfe (3.4).

### 3.3 Nachträglich

Im Ordner: Knopf **„Aus diesem Ordner Galerie machen“** (mit der Wahl: nur direkte Fotos oder auch Unterordner) und umgekehrt in der Galerie ein Link zum Ordner. Auch später wirkt dieselbe Logik wie beim Upload.

### 3.4 Was `folders.gallery_id` bedeutet

Nur eine **Merkhilfe**: Der Ordner zeigt ein Etikett „Galerie: Island 2022 (Entwurf)“ mit Link. Neue Fotos, die man *später in diesen Ordner hochlädt*, kommen zusätzlich in diese Galerie (Frage E3b). Es gibt **keinen dauerhaften Abgleich**: Verschiebt man Fotos aus dem Ordner, bleiben sie in der Galerie; löscht man die Galerie oder den Ordner, bleibt das jeweils andere bestehen (nur die Merkhilfe wird `NULL`). Das ist vorhersehbar und vermeidet, dass ein Verschieben ungewollt eine öffentliche Galerie verändert.

## 4. Oberfläche (`/admin/medien`)

Design wie der übrige Admin (beide Designs, hell und dunkel, Haarlinien, vorhandene Klassen), keine externen Bibliotheken, Ausgabe nur per `textContent`.

- **Aufbau:** links Ordnerbaum („Medien“, „Papierkorb“), rechts Inhalt, oben Pfadleiste (Brotkrumen), Werkzeugleiste („Neuer Ordner“, „Hochladen“, „Ordner hochladen“, Ansicht Liste/Symbole, Suche, Sortierung nach Name, Datum, Größe, Art), unten oder rechts ein Detailfeld zur Auswahl.
- **Listenansicht:** Spalten Name, Art, Größe, Datum, „verwendet in“. **Symbolansicht:** Kacheln; bei Fotos die 480-px-Variante (vorhanden), bei PDF und Datei ein Symbol; Ordner als Ordnersymbol mit Anzahl. Lazy Loading, Seiten zu je 200 Einträgen.
- **Detailfeld:** Name, Größe, Typ, Ort (Pfad), Hochgeladen von, Datum; Fotos: Link zu „Foto bearbeiten“ (kein zweiter Editor); Dateien: Schalter „Öffentlich“ mit kopierbarer Adresse, „Herunterladen“, „Verwendet in“ (Galerien und Seiten, soweit Lichtkammer das erkennt, 2.5/F).
- **Auswahl:** Klick, Umschalt-Klick (Bereich), Strg/Cmd-Klick (einzeln), Strg/Cmd+A, Aufziehrahmen entfällt (Tastatur und Haken reichen).
- **Verschieben:** Ziehen auf einen Ordner im Baum oder eine Ordner-Kachel (HTML5-Ziehen). **Gleichwertig ohne Maus:** Knopf „Verschieben nach …“ mit Ordnerauswahl (Pflicht für Tastatur und Screenreader). Mit der Alt-Taste kein Kopieren (Dateien werden nicht dupliziert).
- **Umbenennen:** Enter oder F2 in der Zeile, oder Knopf; Fehlermeldung inline („Name schon vergeben“, „Zeichen nicht erlaubt“). Dateien umbenennen ändert nur den Anzeigenamen (und bei öffentlichen den Namen der öffentlichen Kopie nicht, die Adresse bleibt stabil, siehe 5.4).
- **Löschen:** Rückfrage mit Zahlen („Ordner ‚Island‘ mit 120 Fotos, 2 Unterordnern. Die Fotos kommen in den Papierkorb und aus 1 veröffentlichten Galerie. Wiederherstellung 30 Tage.“). Nichts wird sofort endgültig gelöscht. Bei öffentlichen Dateien in Verwendung zusätzlich Hinweis „wird in Seite X als Knopf benutzt“; die Datei wird erst nach Bestätigung entfernt.
- **Tastatur und Barrierearmut:** Baum mit `role="tree"`/`treeitem`, `aria-expanded`, Pfeile; Inhalt als Liste mit `aria-selected`; alle Aktionen auch als Knöpfe; Statusmeldungen über `aria-live`; sichtbarer Fokus.
- **Ohne Skript** (Grundlage, wie bei den anderen Seiten): Navigation per Links (`?ordner=ID`), Auswahl per Haken, Aktionen („Verschieben nach“, „Umbenennen“, „Löschen“, „Neuer Ordner“) per Formular, Einzel-Upload per `<input type=file>`. Ziehen, Mehrfachauswahl mit Umschalt, Symbolansicht und Ordner-Upload sind Verbesserung durch Skript.
- Kein Kontextmenü mit rechter Maustaste in der ersten Fassung (Knöpfe reichen, weniger Fehlerquellen).

## 5. Sicherheit (Risiko hoch)

### 5.1 Namen und Pfade

Grundsatz aus Abschnitt 1: Ordnernamen und Dateinamen sind **Daten**, nie Pfade. Auf dem Server wird aus Namen nie ein Dateipfad gebildet (Ablage unter Zufallsnamen, Endung aus dem erkannten Typ). Der öffentliche Name (5.4) entsteht aus einem strengen Slug.

Der Browser schickt keine Pfade mit Dateien. Er schickt **einen Ordnerplan** (Liste der Ordnerpfade als Segmentlisten, z. B. `["Island","Tag 1"]`); der Server prüft jedes Segment und legt die Ordner an. Jede Datei hängt danach nur an einer `folder_id`. `webkitRelativePath` und die Einträge aus `webkitGetAsEntry()` werden im Browser in Segmente zerlegt und **auf dem Server noch einmal vollständig geprüft** (der Browser ist nie die Sicherheitsgrenze).

**Entscheidung von Lutz (18.11.2026): Der Ordnername vom Mac, Windows oder Linux ist direkt der Name im Medienbereich, ohne Nachfrage und ohne Umbenennen** (Abschnitt 3.5). Deshalb werden Namen so wenig wie möglich verändert; nur was unsichtbar oder gefährlich ist, wird still entfernt, und die Zusammenfassung vor dem Start nennt jede Änderung. Weil Namen nie Pfade sind, brauchen sie die strengen Dateisystem-Regeln nicht.

Prüfung je Segment (Ordner- und Dateiname):

- Unicode **NFC** (Finder liefert NFD); Vergleichsschlüssel NFC und kleingeschrieben. Braucht die PHP-Erweiterung `intl`; `Preflight` verlangt sie schon (Passwörter), ein Rückfallweg ist nicht nötig.
- **Still entfernt** (unsichtbar oder täuschend, im Namen nie nötig): Steuerzeichen (`U+0000` bis `U+001F`, `U+007F`), Bidi-Steuerzeichen (`U+202A` bis `U+202E`, `U+2066` bis `U+2069`), Zeichen ohne Breite (`U+200B` bis `U+200F`, `U+FEFF`, Tag-Zeichen), Leerzeichen und Punkte am Anfang und Ende. Ungültiges UTF-8 wird mit `U+FFFD` ersetzt oder die Datei abgelehnt.
- **Unverändert erlaubt:** alle übrigen Zeichen, auch `: * ? " < > | \`, Umlaute, Emojis, Leerzeichen innen, und **Windows-reservierte Namen** (`CON`, `NUL`, `COM1` …), weil der Name nur angezeigt und nie als Dateiname benutzt wird. Ausgabe immer maskiert. Ein Name, der danach leer wäre, oder `.`/`..` (gibt es als Ordnername nicht) heißt „Unbenannter Ordner“.
- Länge je Segment höchstens 255 Zeichen (längere werden gekürzt und gemeldet); dieselbe Namensfunktion benutzt künftig auch `Library::cleanName` (heute `basename`, nur Steuerzeichen, 200 Zeichen; Prüfung 17, H4), Gesamtpfad höchstens 800, Tiefe höchstens 8.
- Versteckte und Systemdateien werden beim Ordner-Upload **still übersprungen und gezählt**: `.DS_Store`, `._*`, `__MACOSX/`, `Thumbs.db`, `desktop.ini`, Dateien und Ordner mit führendem Punkt.
- Verschieben: Verbot, einen Ordner in sich selbst oder einen Nachkomme zu legen (Zyklus), Tiefenprüfung nach dem Verschieben, alles in einer Transaktion; Namenskonflikt am Ziel ergibt eine Meldung, kein stilles Überschreiben.

### 5.2 Dateityp nur am Inhalt

- Der Typ kommt nur aus `finfo` (Inhalt) und zusätzlichen Strukturprüfungen. Dateiendung, vom Browser gemeldeter Typ und Name werden ignoriert; ein `.pdf`, das HTML ist, wird abgelehnt.
- Positivliste (Entscheidung E4): JPEG, PNG, WebP, HEIC (nur als Foto, bestehende Pipeline), PDF, SVG, PNG/JPEG/WebP als Datei. **Nicht** erlaubt: HTML, JS, PHP, ZIP, Office, ausführbare Dateien, Video, Archive. Die Liste ist eine Konstante und wird nur mit Entwurf und Tests erweitert.
- Foto-Annahme bleibt unverändert (`Library::ingest`: Typ, Pixelgrenze 100 MP, SHA-256).
- Die Endung der gespeicherten Datei folgt dem erkannten Typ (`.pdf`, `.svg`, `.png`, …), nie dem Hochladenamen.

### 5.3 SVG und PDF (aktive Inhalte)

**SVG** kann Skripte, fremde Adressen und Ereignisse enthalten und führt sie aus, wenn man die Datei direkt aufruft. Maßnahmen:

1. **Annahme per Positivliste** (nicht Sperrliste): Einlesen mit `DOMDocument` (`LIBXML_NONET`, Entitäten nicht ersetzen, kein externer Zugriff); nur UTF-8 ist erlaubt (UTF-16 wird abgelehnt); **nach** dem Lesen wird `$doc->doctype` geprüft (kein DOCTYPE, oder genau das W3C-Standard-DOCTYPE ohne internen Teil), alles andere samt `ENTITY` wird abgelehnt (nicht per Muster auf den Rohbytes). Wurzelelement muss `svg` im SVG-Namensraum sein. Erlaubt sind nur bekannte Grafik-Elemente und -Attribute (Formen, Pfade, Verläufe, `g`, `defs`, `use` nur mit Verweis auf `#id` der Datei; Elemente und Attribute werden nach Namensraum (`namespaceURI`) und `localName` geprüft, nicht nach Präfix, `clipPath`, `mask`, `text`, `style` mit streng geprüftem CSS).
2. **Ablehnen** (Datei ist dann kein Logo): `script`, `foreignObject`, `iframe`, `object`, `embed`, `animate`/`set` auf Verweisattribute, jedes `on…`-Attribut, `href`/`xlink:href` auf etwas anderes als `#id`, das Element `image` und jedes `data:` (wie der vorhandene Logo-Prüfer und sein Test „eingebettetes Bild“), CSS mit `@import`, `url(` auf Nicht-`#`, `expression`, `javascript:`.
3. **Entfernen statt Ablehnen:** Metadaten fremder Editoren (`sodipodi:`, `inkscape:`, Adobe `i:`/`x:`-Namensräume, `metadata`), Kommentare. Hinweis nach dem Hochladen: „Editor-Metadaten wurden entfernt“. Gespeichert wird nur die bereinigte Fassung (`saveXML`), die noch einmal durch dieselbe Prüfung läuft; eine Original-SVG wird nicht aufgehoben.
4. Größe höchstens 1 MB (Standard, einstellbar).
5. **Auslieferung nur als Bild und mit Schutzkopf:** in Admin und Seiten nur per `<img>` (dort laufen Skripte nie). Wird die SVG direkt aufgerufen (Admin-Route, öffentliche Kopie), liefert der Server `Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox` und `X-Content-Type-Options: nosniff`. Für `media/dateien/` kommt das per `.htaccess` (`mod_headers`, wie die vorhandene Cache-Regel).
6. **Keine Verarbeitung auf dem Server:** SVG wird nie von Imagick oder GD gelesen oder umgewandelt (Imagick-SVG- und Ghostscript-Wege sind ein bekannter Angriffsweg).

Der vorhandene Logo-Prüfer in `Settings::checkSvg` wird zu **einer gemeinsamen Klasse** (z. B. `Media/SvgCheck`) zusammengeführt, die Logos und Medien benutzen; die vorhandenen Logo-Tests müssen danach unverändert grün sein, die Prüfung darf nie schwächer werden.

**PDF** kann JavaScript, Startaktionen und eingebettete Dateien enthalten.

1. Annahme nur bei `%PDF-` am Anfang, Typ `application/pdf` per `finfo`, Endmarke `%%EOF` im letzten Kilobyte (nur Plausibilität), Größe höchstens 50 MB (Standard, `LK_MAX_FILE_MB`).
2. Suche nach den Markern `/JavaScript`, `/JS`, `/OpenAction`, `/AA`, `/Launch`, `/EmbeddedFile`, `/RichMedia`, `/XFA`, `/SubmitForm`, auch in der Namenskodierung (`#4A…`). Treffer = Ablehnung mit Meldung. **Ehrlich:** In komprimierten Objektströmen lässt sich das nicht vollständig prüfen. Es ist ein Filter gegen die gängigen Fälle, kein Beweis. Die eigentliche Absicherung: nur angemeldete Personen laden hoch, PDFs werden nie auf dem Server gerendert oder umgewandelt (keine Ghostscript-Wege), Auslieferung nur mit `nosniff` und festem Typ, im Admin Standard als Download (`Content-Disposition: attachment`), das Öffnen im Browser-Betrachter ist eine bewusste Aktion.
3. Öffentliche PDFs werden vom Webserver mit `Content-Type: application/pdf` (aus der Endung `.pdf`, die wir erzeugen) und `nosniff` ausgeliefert.

**Bilder als Dateien** (PNG, JPEG, WebP): Typ und Größe per `getimagesize` geprüft, Pixelgrenze wie bei Fotos, höchstens 5 MB. **Keine** Verarbeitung, **kein** Entfernen von GPS; deshalb steht im Dialog, dass solche Bilder unverändert veröffentlicht werden. Für echte Fotos ist die Fotopipeline der richtige Weg (entfernt GPS aus den öffentlichen Varianten).

### 5.4 Auslieferung

- **Privat:** nur über eine Admin-Route `/admin/medien/datei/<id>` (Anmeldung Pflicht, `GET` ohne Nebenwirkung). Antwort mit festem `Content-Type` aus der Datenbank, `X-Content-Type-Options: nosniff`, `Content-Disposition: attachment; filename*=UTF-8''…` (nur beim Herunterladen, der Name wird als Kopfwert gebaut mit Entfernen von Steuerzeichen und Anführungszeichen), `Cache-Control: private, no-store`, Unterstützung für `Range` (große PDFs). Vorschauen von Fotos und SVG im Admin laufen über `<img>`.
- **Öffentlich:** Kopie unter `media/dateien/…`, vom Webserver direkt ausgeliefert. Name `<token8>-<slug>.<ext>`: Zufallsanteil verhindert Raten, Slug (`a–z0–9-`) hält die Adresse lesbar. Die Adresse ändert sich beim Umbenennen **nicht** (stabile Links in Seiten). Im Ordner `media/dateien/` gilt eigene `.htaccess`: kein PHP (erbt die Sperre von `media/`), `nosniff`, bei SVG der CSP-Kopf aus 5.3, PDF mit `Content-Disposition: inline`. Die Wartezeit für die Zwischenspeicherung ist kurz (1 Tag, nicht „immutable“ wie bei den Varianten), weil man eine Datei ersetzen können soll.
- Einen Ordner „öffentlich“ gibt es nicht: Ordner sind keine Zugriffsschutzgrenze. Öffentlich ist immer eine Eigenschaft der einzelnen Datei. **Hinweis im Entwurf zu Fotos:** Die Varianten von Fotos liegen wie bisher unter Zufallsadressen in `media/` und sind für jeden mit der Adresse abrufbar, auch wenn das Foto in keiner Galerie liegt; das bleibt (Ordner ändern daran nichts).
- Beim Löschen oder Zurückstellen auf „privat“ wird die öffentliche Kopie sofort gelöscht (nicht erst beim Veröffentlichen); die Datenbank bleibt Quelle der Wahrheit.

### 5.5 Rechte (Rollen `admin` und `editor`)

| Aktion | Editor | Administrator |
|---|---|---|
| Ordner anlegen, umbenennen, verschieben; hochladen | ja | ja |
| Dateien und Ordner in den Papierkorb, wiederherstellen | ja | ja |
| Datei „öffentlich“ schalten | ja (Frage E7) | ja |
| Papierkorb endgültig leeren, einzelnes endgültiges Löschen | wie bisher bei Fotos | wie bisher bei Fotos |
| Dateityp-Liste und Größenlimits ändern | nein | nur über Konfiguration (`.env`), nicht im Admin |

Alle Routen laufen über die vorhandene Anmeldung (`AdminController::add`, Rolle). Es gibt keine Datei- oder Ordner-Kennung, die ohne Anmeldung etwas offenlegt: Private Dateien haben nur die Admin-Route.

### 5.6 Protokoll (Audit-Kette)

Neue Ereignisse im festen Wortschatz (`Audit::EVENTS`): `folder_created`, `folder_renamed`, `media_moved`, `media_trashed`, `media_restored`, `media_purged`, `file_uploaded`, `file_public_changed`, `folder_gallery_created`. Details nur Kennungen und Zahlen im erlaubten Zeichensatz (`folder=12,to=5,count=40`), **nie Datei- oder Ordnernamen** (die können beliebig sein und passen nicht in das feste Zeichenformat). Ein Ordner-Upload schreibt je Ordner **eine** Sammelzeile (`folder=12,count=212`), nicht 212 Zeilen. Löschen und Verschieben sind immer protokolliert.

### 5.7 CSRF, Missbrauch, Mengen

- Alle ändernden Aufrufe sind `POST` mit dem vorhandenen CSRF-Schutz (Header oder Feld wie bei `upload.js`); `GET` ändert nie etwas. JSON-Aufrufe verlangen den passenden Inhaltstyp. Die Same-Origin-Prüfung der Anwendung gilt auch hier.
- Nie Zugriff auf Ordner- oder Datei-Kennungen anderer Art: jede ID wird gegen die Datenbank geprüft (existiert, nicht im Papierkorb, richtiger Typ); Listen von IDs aus dem Browser höchstens 500.
- **Mengen:** höchstens 5 000 Dateien je Ordner-Upload (Hinweis, in Teilen zu laden), 5 000 Ordner insgesamt, Tiefe 8, Foto 100 MB (wie heute), PDF 50 MB, SVG 1 MB, Bild als Datei 5 MB; freier Speicher wird vor dem Start geprüft (`disk_free_space`, mindestens Gesamtgröße plus Reserve), sonst klare Meldung.
- Ordner-Zähler, Papierkorb und Listen sind paginiert (200), damit die Seite bei tausenden Einträgen nicht erlahmt.
- Gleichzeitigkeit: Eindeutigkeitsindex fängt doppelte Ordner bei parallelen Aufrufen ab (Fehler wird abgefangen, vorhandener Ordner wird verwendet); Verschiebe- und Löschvorgänge je in einer Transaktion.
- Fehlerausgabe ohne Pfade und ohne rohe Eingaben; Anzeige nur per `textContent`/`htmlspecialchars`.

## 6. Ordner-Upload im Browser

- **Ablegen:** `drop`-Ereignis, je Eintrag `DataTransferItem.webkitGetAsEntry()`; Ordner werden rekursiv mit `createReader().readEntries()` gelesen, **in einer Schleife bis das Ergebnis leer ist** (der Browser liefert höchstens 100 Einträge je Aufruf, klassischer Fehler). Leere Ordner werden mit angelegt. Symbolische Verknüpfungen folgen Browser nicht.
- **Rückfall:** „Ordner hochladen“ per `<input type="file" webkitdirectory multiple>` (Chrome, Edge, Safari, Firefox neu) und „Dateien hochladen“ per `multiple`. Dort liefert `webkitRelativePath` die Pfade; leere Ordner gibt es nicht. Das Ablegen von Ordnern funktioniert in den gängigen Browsern am Mac, unter Windows und Linux; Nicht-Unterstützung wird ehrlich gemeldet und auf den Knopf verwiesen.
- **Ablauf:**
  1. Browser sammelt, sortiert natürlich, überspringt Versteck- und Systemdateien, zeigt die Zusammenfassung (3.2).
  2. `POST /admin/medien/plan` mit Zielordner, Ordnerliste (Segmente), Galerieoption, Titeln und je Datei `name` und `size`. Server prüft alles (5.1), legt Ordner an (Zusammenführen mit vorhandenen), legt bei Bedarf die Galerien im Entwurf an und antwortet mit `Ordnerpfad → folder_id` (und `gallery_id`) und je Datei einem Vorschlag „bereits vorhanden“ (gleicher Ordner, gleicher Name, gleiche Größe → überspringen, siehe Fortsetzen).
  3. Je Datei `start` (mit `folder_id`, `intent`, optional `gallery_id`) → `chunk` → `finish` wie heute; danach bei Fotos die Verarbeitung (Varianten), je ein Foto nach dem anderen. Fehler einer Datei stoppen die anderen nicht.
  4. Abschlussbericht: neu, Dublette (wo), übersprungen (Grund), fehlgeschlagen (Grund), angelegte Galerien (Link).
- **Fortschritt:** Gesamtbalken (Dateien und Bytes) plus Zeile je Datei, Schätzung der Restzeit.
- **Abbruch:** Knopf „Abbrechen“ beendet nach der laufenden Datei. Ordner und Galerien, die dieser Lauf angelegt hat und die **leer** blieben, werden entfernt (Server-Aufruf `plan/abbrechen`); alles Fertige bleibt.
- **Fortsetzen:** Die `.part`-Dateien auf dem Server bleiben 24 Stunden. Ein Browser kann nach dem Neuladen die `File`-Objekte nicht behalten; deshalb legt man den Ordner **erneut** ab. Der Browser schickt je Datei zusätzlich `lastModified` und einen Fingerabdruck (SHA-256 über die ersten und letzten 64 KB plus Größe, `crypto.subtle`); Name und Größe allein genügen nicht (eine neu exportierte Datei gleicher Größe würde sonst übersprungen oder mit Teilen der alten gemischt; Prüfung 17, H2). Der Server erkennt dann (a) schon fertige Dateien am gleichen Ordner, Namen, Größe und Fingerabdruck und überspringt sie, (b) unfertige Uploads am gleichen Nutzer, Ordner, Namen, Größe und Fingerabdruck und liefert die vorhandene Upload-Kennung mit dem Stand `received`, sodass ab dort weitergeschickt wird. Verlorene Netzverbindung während des Laufs: Wiederholung mit dem vom Server gemeldeten Versatz (409-Antwort, wie heute).
- **Parallelität:** eine Datei zugleich (all-inkl-Prozessgrenzen, Reihenfolge der Galerie). Stückgröße 4 MB (Obergrenze der Anwendung bleibt 8 MB).
- Kein Hashen im Browser (spart Speicher); die Dublettenprüfung läuft am Ende des Hochladens jeder Datei wie bisher.

## 7. Grenzen des Webspaces und Docker

- **all-inkl, nur FTPS, PHP:** Es gibt kein SSH und kein Cron; alle Arbeiten laufen als kurze Anfragen. Der Upload in Stücken umgeht `upload_max_filesize` und `post_max_size` (die Stücke kommen als rohe Anfrage), das Zeitlimit je Anfrage ist damit kein Problem; die Bildverarbeitung läuft schon heute als eigene kleine Anfrage je Foto. `memory_limit` spielt nur bei Bildern (Pipeline, besteht) und beim SVG-Lesen (1 MB) eine Rolle; PDFs werden **gestreamt gelesen** (Marker in Blöcken suchen, nicht ganz in den Speicher laden). 5 000 Dateien über die Browser-Verbindung dauern lange, aber sie sind fortsetzbar; die Hochladedauer begrenzt die Anschlussgeschwindigkeit, nicht der Server.
- **Speicher:** Das Webspace-Kontingent ist die Grenze; die Medienseite zeigt belegten Platz (Fotos, Dateien) und warnt unter 10 % frei. Papierkorb belegt weiter bis zur Löschung.
- **Docker:** Gleich, kein neues Volume (Dateien unter `LK_DATA_DIR/files`, öffentliche Kopien in `media/`, die schon ein Volume ist). `compose.yaml` bleibt. Der Ordner `media/dateien/` und seine `.htaccess` legt die Anwendung beim ersten Bedarf selbst an (Rechte 0755, Dateien 0644); ohne `mod_headers` fehlt nur der zusätzliche Schutzkopf, die SVG-Prüfung bleibt (Defense in Depth); der Admin-Hinweis auf fehlendes `mod_headers` wird in die Voraussetzungsprüfung aufgenommen.
- **Datensicherung:** `files/` gehört in die Sicherung (Handbuch nachziehen).

## 8. Teilschritte

Jeder Schritt wird einzeln beauftragt, danach berichten und auf Freigabe warten. Eine Opus-Prüfung je Block (nicht je Teilblock), veröffentlicht wird gesammelt.

| Block | Inhalt | Risiko |
|---|---|---|
| **A** Ordner (Daten und Logik) | Migration 10 (`folders`, `files`, `photos.folder_id`, `uploads`-Spalten), `Media/Folders` (Prüfung der Namen, Baum, Anlegen, Umbenennen, Verschieben mit Zyklusprüfung, Papierkorb, Zähler), Audit-Ereignisse, Tests | hoch (Namen, Papierkorb, Migration) |
| **B** Dateiarten und Auslieferung | `Media/Files` (Annahme PDF, SVG, Bild-als-Datei), gemeinsame `SvgCheck`, PDF-Filter, Ablage unter `files/`, Admin-Auslieferungsroute, öffentliche Kopie mit `.htaccess` für `media/dateien/`, Tests mit Schädlingskorpus | hoch |
| **C** Oberfläche ohne Skript | `/admin/medien` mit Baum, Liste, Pfadleiste, Formulare für Neu/Umbenennen/Verschieben/Löschen, Papierkorb-Erweiterung, Menüpunkt, Einzel-Upload in den Ordner | mittel |
| **D** Oberfläche mit Skript | Mehrfachauswahl, Ziehen zum Verschieben, Symbolansicht, Tastatur, Detailfeld | mittel |
| **E** Ordner-Upload | Plan-Aufruf, Ziehen und `webkitdirectory`, Zusammenfassungsfenster, Galerieoptionen, Fortschritt, Abbruch, Fortsetzen, Galerie aus Ordner machen | hoch (Plan-Aufruf, Mengen) |
| **F** Verknüpfungen | „Bestand übernehmen“ (`media/dokumente`), Knopf-Block wählt Datei aus Medien, Logo-Auswahl aus Medien, „Verwendet in“ | mittel |

Reihenfolge: A und B zuerst (Sicherheitskern), dann Opus-Prüfung; danach C und D, E, F. Wegen Risiko „hoch“ prüft **vorab Opus diesen Entwurf**; vor einem Kundenrelease von Lichtkammer zusätzlich Fable (oder Opus `max`), weil hier Dateiannahme und Auslieferung neu hinzukommen.

## 9. Testplan (nur in Docker, `docker run … php tests/run.php`; neue Datei `tests/medien.php`)

1. **Namen:** Tabelle mit `..`, `.`, `a/b` (als Segmentgrenze), `a\b`, absolute Pfade, `NUL` im Namen, `CON.txt` und `a:b*c` (bleiben unverändert), NFD gegen NFC (gleicher Ordner, Anzeige NFC), `Island` gegen `island` (zusammengeführt, erste Schreibweise bleibt), Bidi-Zeichen (`U+202E`) und Zeichen ohne Breite (entfernt, gemeldet), 256 Zeichen (gekürzt), Tiefe 9, Endpunkt und Endleerzeichen; Galerietitel = Ordnername, Slug und Kollision (`island-2`), Name nur aus Emojis; erwartet: nie ein Dateipfad, Anzeige immer maskiert.
2. **Ordnerlogik:** Anlegen, Eindeutigkeit (auch parallel), Umbenennen, Verschieben, **Zyklus verboten**, Tiefenüberschreitung beim Verschieben, Papierkorb eines Zweigs und Wiederherstellen, Zähler, Zusammenführen gleicher Namen.
3. **Migration:** Bestandsdatenbank mit Fotos, Galerien und Importzuordnung wird migriert; danach alle bisherigen Tests (616) unverändert grün; Rückwärtsverträglichkeit von `Library::ingest` ohne `folderId`.
4. **Typprüfung:** HTML als `.pdf`, GIF-Kopf als `.png`, PHP als `.svg`, Polyglot (PDF und HTML), leere Datei, zu große Datei, abgeschnittenes PDF; Endung der Ablage folgt dem erkannten Typ.
5. **SVG-Korpus:** Skript, `onload`, `foreignObject`, `href` auf `http:` und `javascript:`, `data:text/html`, Entity-Bombe, externe Entität, fremdes DOCTYPE, CSS `@import` und `url(http…)`, `use` auf Fremddatei, `animate` auf `href`; gültiges Inkscape- und Illustrator-Logo (Metadaten werden entfernt, Bild bleibt). Die bisherigen Logo-Tests bleiben unverändert grün.
6. **PDF-Filter:** `/JavaScript`, `/OpenAction`, `#4A…`-Tarnung, eingebettete Datei; gültiges harmloses PDF wird angenommen.
7. **Auslieferung:** Admin-Route nur angemeldet (ohne Sitzung: Weiterleitung/403), Kopfzeilen (`nosniff`, CSP bei SVG, `attachment`, `Cache-Control: private`), Range; öffentliche Kopie entsteht und verschwindet mit dem Schalter; `.htaccess` in `media/dateien/` enthält Sperren und Köpfe (Inhaltstest); Pfadangriffe auf Kennungsrouten (`../`, Zahlen als Text, SQL-Zeichen).
8. **Upload-Plan:** 2 000 Einträge, tiefe Ordner, Mehrfach-Unicode, Dubletten, Fortsetzen (gleiche Datei erneut: überspringen oder ab `received`), Abbruch räumt leere Ordner und Galerien auf, Galerieoptionen „keine / je Ordner / nur oberster“ mit Slug-Kollision und Reihenfolge, Fotos in Galerien nur als Entwurf, PDFs nie in Galerien, Dublette landet in der neuen Galerie, Grenzwerte (Dateizahl, Speicher voll).
9. **Rechte, CSRF, Audit:** Aufruf ohne CSRF = 403; Rolle `editor` darf das Erlaubte und nicht mehr; ungültige IDs; Audit-Zeilen vorhanden, enthalten keine Namen, Kette bleibt gültig (`verify`).
10. **Browser (nicht automatisierbar in Docker):** Der Ordner-Upload per Ziehen vom Finder und das Ziehen zum Verschieben werden von Lutz (oder mit Chrome-Erweiterung, soweit möglich) an einem Testordner mit Unterordnern, Umlauten und Windows-Namen von Hand geprüft; Ergebnis kommt als kurze Prüfliste in den Block-Bericht.

## 10. Offene Fragen an Lutz (mit Empfehlung)

**Entschieden von Lutz am 18.11.2026 wie empfohlen: E1, E3 (mit E3b), E4, E5, E7.** Noch offen: E2, E6, E8 bis E12 (jeweils mit Empfehlung; gelten als angenommen, wenn Lutz nichts anderes sagt).

| Nr. | Frage | Empfehlung |
|---|---|---|
| **E1** | Ordner nur in der Datenbank (Dateien weiter unter Zufallsnamen), oder echte Ordner auf der Festplatte? | **Nur Datenbank.** Sicher gegen Pfadangriffe, Umbenennen und Verschieben sind sofort und ohne Dateiarbeit, funktioniert gleich auf all-inkl und Docker. Nachteil: Man kann die Ordner nicht per FTP durchsehen (die Dateien tragen Zufallsnamen); dafür gibt es später bei Bedarf einen Export. |
| **E2** | Ein Foto liegt in genau einem Ordner, aber in beliebig vielen Galerien? | **Ja.** Passt zu Entwurf `10`; kein Kopieren von Bildern. |
| **E3** | Galerie beim Ordner-Upload: drei Wahlen (keine / je Ordner / nur oberster mit allem darin), Vorgabe „keine“, Galerien immer als Entwurf, Titel = Ordnername, im Fenster änderbar? | **Ja.** E3b: Neue Fotos, die später in einen mit Galerie verknüpften Ordner hochgeladen werden, kommen automatisch auch in diese Galerie (Empfehlung **ja**, im Fenster abschaltbar), sonst kein dauerhafter Abgleich. |
| **E4** | Erlaubte Dateiarten in der ersten Fassung? | **Fotos (JPEG, PNG, WebP, HEIC), PDF, SVG, PNG/JPEG/WebP als „Datei“ (Logos, Grafiken).** Nicht: ZIP, Office, Video, HTML. Mehr (z. B. ZIP für Downloads) später mit eigenem Entwurf. |
| **E5** | Wo liegen Nicht-Fotos? Größenlimits? | **Privat unter `LK_DATA_DIR/files`; öffentlich nur auf Schalter als Kopie in `media/dateien/`.** PDF 50 MB, SVG 1 MB, Bild-als-Datei 5 MB (einstellbar per `.env`). |
| **E6** | Papierkorb auch für Ordner und Dateien, 30 Tage wie bei Fotos, ganzer Zweig wiederherstellbar? | **Ja.** |
| **E7** | Rechte: Editor darf hochladen, ordnen, löschen (in den Papierkorb) und Dateien öffentlich schalten; endgültig löschen wie heute bei Fotos? | **Ja.** Jede dieser Handlungen steht im Protokoll. Wenn die zweite Person Dateien nicht selbst veröffentlichen soll, wäre „öffentlich schalten nur Administrator“ die Alternative. |
| **E8** | Bestand: Fotos bleiben auf oberster Ebene, `media/dokumente`-PDFs werden per Knopf übernommen, Logos in den Einstellungen bleiben unverändert bis Teilschritt F? | **Ja.** Optional später „Ordner aus Galerien anlegen“ (je Galerie ein Ordner mit ihren Fotos); nicht in der ersten Fassung. |
| **E9** | Dubletten beim Ordner-Upload: kein zweites Foto, vorhandenes bleibt am alten Ort, kommt aber in die neue Galerie? | **Ja.** |
| **E10** | SVG mit Editor-Metadaten: Metadaten still entfernen, aktive Inhalte ablehnen? | **Ja**, mit Hinweis nach dem Hochladen. |
| **E11** | Ablauf: Opus prüft diesen Entwurf zuerst, danach Block A und B, Prüfung, dann C bis F? | **Ja.** Veröffentlicht wird gesammelt am Ende der Blöcke. |
| **E12** | Menü: neuer Punkt „Medien“ neben „Fotos“ und „Galerien“? | **Ja**; „Fotos“ bleibt die Foto-Ansicht mit Filtern. |

## 11. Nicht Teil dieses Entwurfs

PDF-Vorschau (Seite 1 als Bild, würde Ghostscript brauchen, bewusst nicht), Versionen und Ersetzen von Dateien, Ordnerfreigaben für Dritte, Ordner-Zugriffsschutz, Video, Zip-Download eines Ordners, Kontextmenü mit rechter Maustaste, Zugriff per WebDAV/FTP auf die Ordner. Jedes davon wäre ein eigener Entwurf.
