# 45 Prüfung Detailentwurf 44: Dateien in Blöcken (Teil E)

Prüfung durch Opus am 20.11.2026, **vor dem Bau**, gegen den echten Code (Stand `d4c7b9c`). Nur gelesen und gemessen (Wegwerf-Instanz in Docker, Skripte im Scratchpad der Prüfsitzung, nicht im Repo). Nichts gebaut, nichts hochgeladen, nichts gepusht, kein Server angefasst.

## Gesamturteil

**Baubar: ja, mit fünf Auflagen.** Der Entwurf ist sorgfältig und stimmt in den Grundannahmen mit dem Code überein: Das Format bleibt für alte Blöcke byte-gleich, der Editor lässt `file` durch, die Vorschau-Korrektur ist nötig, und die Lücke bei der Wiederherstellung gibt es wirklich. Es gibt keinen Weg, wie eine private, gelöschte oder fremde Datei verlinkt würde, wenn die Auflagen unten eingehalten werden. Die drei Befunde mit Risiko mittel betreffen aber genau die Stellen, an denen der Entwurf neue Schreib- und Umschaltwege baut (Reparatur der Kopien, Prüfung vor dem Umschalten). Die sollten **vor** dem Bau von Stufe 1 festgehalten sein, deshalb ist ein Kasten mit den Auflagen oben in Entwurf 44 eingetragen.

**Auflagen vor Stufe 1:**

1. `repairPublicCopies` darf `public` nie auf 0 setzen (B1).
2. `repairPublicCopies` muss Namensmuster und SVG-Selbsttest einhalten (B2).
3. Die Prüfung der Dateien kommt vor dem Umbenennen des Bauordners nach `releases/`, nicht erst vor `activate()` (B3, im Entwurf schon geändert).
4. `Blocks::fileRefs` mit Blocknummer statt nur `fileIds` (B4).
5. Die Bestätigungsseite setzt `dirtyHandled` (B5).

Außerdem Stufe 1 teilen in 1a und 1b (Abschnitt 6, Frage 4).

Tests: Ich habe die vorhandene Suite nicht neu laufen lassen; alle Messungen unten sind gezielte Proben mit Wegwerf-Daten.

## 1. Befunde nach Risiko

| Nr. | Risiko | Befund | Fundstelle |
|---|---|---|---|
| B1 | mittel | `repairPublicCopies` setzt `public = 0`, wenn das private Original fehlt. Das zerstört Zustand bei einem vorübergehenden Fehler. | Entwurf 44, Abschnitt 6, zweiter Punkt |
| B2 | mittel | Die Reparatur umgeht den SVG-Selbsttest und sagt nichts zum Namensmuster. | Entwurf 44, Abschnitt 6; `Files.php:253`, `295` |
| B3 | mittel | Die Prüfung „kurz vor `activate()`“ liegt nach dem Anlegen der Fassung und hinterlässt bei Abbruch eine wählbare, nie aktive Fassung. | `Builder.php:197-199` |
| B4 | niedrig bis mittel | `Blocks::fileIds` liefert nur Ids; Fehlermeldung und „Verwendet in“ brauchen Blocknummern. | Entwurf 44, 2.2, 4.1, 5 |
| B5 | niedrig | Die Bestätigungsseite ist eine POST-Antwort mit Status 200 und löst daher das Hinweisband „Veröffentlichen nötig“ aus, obwohl nichts geändert wurde. | `AdminController.php:192` |
| N1 | niedrig | Beim Löschen eines Ordners muss die Menge der Dateien und Fotos aus dem Unterbaum kommen, sonst warnt die Seite nicht. | Entwurf 44, 4.2 Punkt 2; `Folders.php:275-289` |
| N2 | niedrig | „Verwendet in“ für Altverweise: eine Suche je Datei ist teuer; ein Durchlauf mit einem Regex je Seite genügt. | Entwurf 44, 4.1 |
| N3 | niedrig | Logo aus Medien: Begründung „JPEG nicht“ ist unvollständig (PNG und WebP tragen auch Metadaten); `files.kind` heißt `image`, die Auswahl braucht `mime`. Außerdem fehlen Logos aus `data/brand/` in der Sicherung (besteht schon). | Entwurf 44, Abschnitt 9; `Settings.php:80`, `Backup/Backup.php:68` |
| N4 | niedrig | Stufe 6: `Request` kennt `$_FILES` gar nicht, `is_uploaded_file` lässt sich im Test nicht ausführen. Der Aufwand ist größer als „klein bis mittel“. | `Request.php:13-20`, `public/index.php:21` |
| N5 | niedrig | Reparatur nur beim Öffnen von Medien: Nach einer Wiederherstellung scheitert „Veröffentlichen“ mit „Kopie fehlt“, und man muss erst woanders hin. | Entwurf 44, Abschnitt 6 |
| N6 | niedrig | „Warnung für Entwürfe“ gibt es nur in der Vorschau; der Bau rendert nur veröffentlichte Seiten. | `Renderer.php:134` |
| N7 | niedrig | Der Selbsttest für SVG (`svgHeadersWork`) macht bei abgelaufenem Merker einen HTTP-Aufruf mit bis zu 5 s und schreibt `_probe.svg`; in einer Lese-Route für den Dialog unerwünscht. | `Files.php:295-325` |
| N8 | niedrig | Detailfeld: Testzeile „JSON enthält keine Adresse einer privaten Datei“ widerspricht der Vorschau über `/admin/medien/datei/{id}` (gemeint ist: keine öffentliche Adresse). Benutzername aus `uploaded_by` geht auch an die Rolle Redakteur. | Entwurf 44, Abschnitte 7 und 11 |
| H1 | Hinweis | Entwurf 29 verlangte beim Speichern die Prüfung „öffentlich markiert“; Entwurf 44 verlegt sie bewusst auf den Bau. Das ist besser, nur einmal in der Hand festhalten. | Entwurf 29, Abschnitt 4 (Risiken, Sicherheit der Block-Daten) |
| H2 | Hinweis | PDFs in `media/dokumente/`, die nie „übernommen“ wurden, haben keine Zeile in `files`. Sie erscheinen nicht in „Verwendet in“ und sind nicht in der Sicherung (`media/` gehört nicht dazu). | `Files.php:340-360` |
| H3 | Hinweis | `media/dateien/` liefert mit `max-age=86400`: Wer eine Datei privat stellt, hat sie bei Besuchern mit Zwischenspeicher bis zu einen Tag. Kein Fehler, nur nicht „sofort weg“. | `Files.php:23-36` |

### B1 (mittel): Reparatur darf `public` nicht zurücksetzen

**Was der Entwurf sagt:** Fehlt auch das private Original, wird `public` auf 0 gesetzt „damit keine Zeile öffentlich bleibt“.

**Warum das ein Fehler ist:** Das läuft bei **jedem Öffnen von Medien**. Folgende Fälle sind realistisch:

- Eine Sicherung ohne `files/` (das Paket-Verzeichnis ist laut `Restore.php` „optional“, `Restore.php:188-195`) wird eingespielt: Beim ersten Öffnen von Medien würde **jede** öffentliche Datei privat, `public_name` geleert, und jeder Block mit Datei bricht den Bau. Zurück gibt es nur durch Einzeln-Umschalten, und die Adresse ändert sich dabei (gemessen: `beb9faa1-preisliste-2026.pdf` wird nach Umbenennen, privat, öffentlich zu `beb9faa1-neu-name.pdf`).
- Ein Datenordner ist kurz nicht lesbar (Mount, Rechte): gleiche Folge, und die Änderung bleibt.

**Der Nutzen der Änderung ist gleich null:** Der Bau behandelt „Kopie fehlt“ ohnehin als nicht verfügbar. Die Zeile auf `public = 0` zu setzen macht den Zustand schlechter, nicht sicherer.

**Auflage:** Die Reparatur **ändert nur Dateien, nie Zeilen.** Fehlt das Original, wird die Datei in der Systemprüfung gezählt und genannt (`/admin/system`), sonst nichts.

### B2 (mittel): Reparatur und die vorhandenen Schutzregeln

Die Reparatur schreibt in `media/` nach Namen aus der Datenbank. Der Entwurf erwähnt „gültigem Namen“, aber nicht, welches Muster. Pflicht:

- **Muster wie `removeCopy` und `purge`**, mit `D` (siehe unten), für `public_name`; für Altdateien `^dokumente/[A-Za-z0-9][A-Za-z0-9._-]{0,120}\.pdf$`. Ohne das könnte eine von Hand veränderte Datenbank aus einer fremden Sicherung die Reparatur zum Schreiben außerhalb von `media/dateien/` bringen (genau der Fall H3 aus Bericht 35).
- **SVG-Selbsttest (E13):** `setPublic` verlangt für SVG, dass der Webserver den Schutzkopf mitschickt (`Files.php:253`). Die Reparatur kopiert nur. Nach einer Wiederherstellung auf einem anderen Server (zum Beispiel Docker ohne `mod_headers`) würde sie eine SVG **ohne** Schutzkopf veröffentlichen. Auflage: SVG nur reparieren, wenn `svgHeadersWork()` wahr ist; sonst überspringen und in der Systemprüfung nennen.
- **Verzeichnis:** `prepareCopyDir` (schreibt die `.htaccess` für `media/dateien/`) wiederverwenden. Für `media/dokumente/` gibt es heute keine eigene `.htaccess`; dort schützt nur die allgemeine in `public/media/.htaccess` (kein PHP). Das reicht für ein Muster, das nur `.pdf` zulässt, ist aber ausdrücklich im Entwurf festzuhalten.
- **Atomar** (Temporärdatei, `rename`) wie `setPublic`; vor dem Kopieren prüfen, ob die Zieldatei schon da ist (zwei Anfragen gleichzeitig).

Nebenbefund, **gemessen**: Das Muster `^[0-9a-f]{8}-[a-z0-9-]*\.(pdf|svg|png|jpg|webp)$` ohne Modifikator `D` lässt `"ab12cd34-x.pdf\n"` durch. Betrifft schon `removeCopy` und `purge`, ist dort harmlos; in `publicUrlOrNull` soll es streng sein. Im Entwurf eingetragen (`D`).

### B3 (mittel): Die Prüfung gehört vor das Umbenennen des Bauordners

`Builder::publish` legt die Fassung in `building-<id>` an, benennt sie nach `releases/<id>` um und ruft dann `activate` (`Builder.php:197-199`). Eine Abbruch-Antwort `datei` „vor `activate`“ (so der Entwurf) lässt die schon umbenannte Fassung in `releases/` liegen. `releases()` und die Seite „Veröffentlichen“ bieten sie dann zum Zurücksetzen an, und sie enthält einen toten Link. Die Prüfung muss **vor** `rename($tmp, …)` laufen; bei Abbruch den Bauordner mit `removeDir` entfernen. Im Entwurf geändert.

Zum Rest des Rennens (der Entwurf ist ehrlich): Das Fenster zwischen Probelauf und Umschalten ist nicht Millisekunden, sondern die **ganze Bauzeit** plus `backupDatabase` (0,3 s lokal, unter `set_time_limit(120)` auf all-inkl nicht gemessen). Die Neuprüfung unmittelbar vor `rename` verkürzt es auf Millisekunden. Die Prüfung braucht eine **frische** Abfrage, nicht den Zwischenspeicher `blockFile` des Renderers; im Entwurf ergänzt.

### B4 (niedrig bis mittel): Blocknummern

Der Entwurf verspricht „Seite ‚Preise‘, Block 4“ (Abschnitt 5) und „je mit der Blocknummer“ (4.1), definiert aber nur `Blocks::fileIds(): list<int>`. Es braucht `Blocks::fileRefs(array $blocks): list<array{pos:string,file:int}>` mit Nummern wie „Block 4“ und „Block 3 (links, Eintrag 2)“ (die Bezeichnung gibt es in `norm` schon als `$where`). `fileIds` bleibt als dünner Mantel.

### B5 (niedrig): Hinweisband

`AdminController::add` setzt nach **jeder** POST-Antwort mit Status unter 400 auf `/admin/medien…` den Merker (`AdminController.php:192`), außer `dirtyHandled` ist gesetzt. Die Bestätigungsseite ist eine 200-Antwort auf einen POST. Ohne `$this->dirtyHandled = true` (die Eigenschaft gehört der Eigenschaft der gemeinsamen Traits und ist in `MedienPages` erreichbar, `MediaPages.php:25`) steht danach „Veröffentlichen nötig“ und der Zähler steigt, obwohl nichts passiert ist. Test ergänzen: Bestätigungsseite ändert den Zähler `site_dirty_n` nicht.

### N1 bis N8 kurz

- **N1:** `usedFileIds ∩ Auswahl` gilt für Dateien und Fotos. Bei Ordnern muss die Auswahl erst über `Folders::subtree` in Datei- und Foto-Ids aufgelöst werden (eine Abfrage je Ordnerbaum, nicht je Eintrag). Das steht so nicht im Entwurf; sonst warnt „Ordner löschen“ nicht, obwohl eine Datei darin benutzt wird.
- **N2:** Pro Seite einmal `preg_match_all('#/media/(?:dateien|dokumente)/[A-Za-z0-9._-]+#', $json)` in eine Menge sammeln, dann je Datei nachschlagen. Eine Zeichenfolgensuche je Datei und Seite wäre bei 300 Dateien in 100 Seiten spürbar. Die Kodierung stimmt: `Blocks::encode` schreibt `/` unmaskiert (`JSON_UNESCAPED_SLASHES`).
- **N3:** Bei Logo aus Medien die Eignung über `mime` (`image/svg+xml`, `image/png`, `image/webp`) und `bytes <= LOGO_MAX` prüfen; `files.kind` ist `pdf|svg|image` (`Schema.php`, Tabelle `files`). Zur Begründung „JPEG nicht“: Auch PNG (`eXIf`) und WebP (`EXIF`) können Standortdaten tragen; ehrlicher ist „nur, was die Logo-Auswahl heute schon akzeptiert, ohne JPEG, weil ein Foto kein Logo ist“. Dazu **gefunden, besteht schon:** Logos aus der Logo-Seite liegen in `data/brand/` (`Settings.php:80`), und die Sicherung nimmt nur `files/` mit (`Backup.php:68`). Nach einer Wiederherstellung hat die Datenbank die Logo-Liste, die Dateien fehlen. Ein Argument für Stufe 5, und ein Satz in `Wiederherstellung.md`.
- **N4:** siehe Abschnitt 6, Frage 4.
- **N5:** Besser als nur „beim Öffnen von Medien“: `repairPublicCopies` einmal am Anfang von `Builder::publish` und beim Öffnen der Seite „Veröffentlichen“ **für die in Seiten benutzten Dateien** ausführen. Dann scheitert das Veröffentlichen nach einer Wiederherstellung nicht an etwas, das das System selbst beheben kann.
- **N6:** Entwürfe werden beim Bau nicht gerendert (`SELECT … WHERE status = 'published'`, `Renderer.php:134`); die „Warnung für Entwürfe“ erscheint nur in der Vorschau. Beschreibung im Entwurf (Abschnitt 2.4 und Tests) entsprechend lesen; kein Codeproblem.
- **N7:** Die Dateiauswahl ruft `svgHeadersWork()` auf (SVG nur bei bestandenem Test, Abschnitt 8.1). Bei abgelaufenem Merker (1 Stunde) macht das einen Selbstaufruf mit bis zu 5 s Wartezeit mitten in einer Lese-Route und schreibt `_probe.svg`. Besser: in der Auswahl nur den gemerkten Wert lesen (nie neu messen) und das Messen dem Schalter „Öffentlich“ überlassen.
- **N8:** Testsatz umformulieren: „JSON enthält keine öffentliche Adresse einer privaten Datei“. Benutzername bei „hochgeladen von“: Rolle Redakteur sieht bisher Namen nur in der Verwaltung der Konten? Falls Redakteure keine Kontenliste sehen, den Namen nur für Administratoren zeigen oder weglassen (Entscheidung von Lutz, klein).

## 2. Antworten auf die fünf Prüffragen

### Frage 1: Stimmen die Aussagen über den Bestand?

**(a) `parse`/`encode` byte-gleich für alte Blöcke: ja, gemessen.** Ein Knopf mit `href="/media/dokumente/x.pdf"` und einer Spalte mit zwei Elementen ergibt nach `Blocks::encode(Blocks::parse($json))` genau dieselbe Zeichenkette (Probe 1, `ok byte-gleich`). Der Aufbau in `Blocks.php:113-121` nennt feste Schlüssel in fester Reihenfolge; ein `file` nur bei > 0 **hinter** `style` ändert nichts für alte Blöcke. Voraussetzung: `file` wird in `norm` zuletzt angehängt und nur bei > 0.

Zusätzlich im Code zu beachten (im Entwurf ergänzt): `norm` gibt `null` zurück, wenn Beschriftung und Adresse leer sind (`Blocks.php:113-121`). Bei `file` > 0 darf das nicht greifen, sonst verschwindet der Block beim Speichern still; dort gilt „Beschriftung fehlt“ als Fehler. Die Meldung für Spalten (`Blocks.php:99`, „In Spalten gehen nur Überschrift, Text, Bild, Zitat und Knopf“) muss den neuen Typ nennen, und `STYLES['download'] = ['normal','flaeche']` und `SIMPLE` brauchen den Eintrag (PHP und JS, `page-editor.js:35-36`).

**(b) Der Editor lässt `file` durch: ja.** `sync()` serialisiert den ganzen Zustand mit `replacer`, der nur Schlüssel entfernt, die mit `_` beginnen (`page-editor.js:1032-1033`). Auch das Duplizieren (`page-editor.js:603`) benutzt `replacer`. `editorPayload` sammelt heute Foto-Ids über `array_walk_recursive` mit Schlüssel `photo` (`SitePages.php:86-90`); für `files` braucht es dieselbe Sammlung mit Schlüssel `file`, wie im Entwurf (Abschnitt 8.1) vorgesehen.

**(c) `Renderer::url()` setzt in der Vorschau das Präfix auch vor `/media/…`: ja, gemessen.** Seite mit Knopf `href=/media/dokumente/x.pdf`, `GET /admin/vorschau/fotografen`: `<a class="knopf" href="/admin/vorschau/media/dokumente/x.pdf">`. Aufruf dieses Ziels: **404** (Probe 1, `vorschau-link-ziel status: 404`). Ursache: `link()` schickt jede Adresse mit `/` am Anfang durch `url()` (`Blocks.php:535-541`); die Vorschau-Routen kennen nur zwei Pfadteile (`AdminController.php:134-136`). Die vorgeschlagene Korrektur in `link()` (für `/media/` kein Präfix) ist richtig; sie ist sicher, weil `media` eine reservierte Seitenadresse ist (`Pages::RESERVED`, `Pages.php:14`), also nie eine Seite meint.

**(d) `Folders::trash` lässt `public = 1` und die Kopie stehen: ja, gemessen,** mit Einschränkung. Nach `Folders::trash($id)` allein ist `deleted_at` gesetzt, `public = 1` und die Kopie liegt noch (Probe 2). In der Praxis räumt es `medienTrash` im selben Aufruf auf (`MedienPages.php:228-230`) und `medienHousekeeping` bei jedem Öffnen von Medien (`MedienPages.php:131`), und zwar **mit Stapeln**: gelingt das nicht in der Frist von 10 s, bleibt ein Rest bis zum nächsten Öffnen von Medien. Die Forderung des Entwurfs, beim Bau immer `deleted_at IS NULL AND public = 1` zu verlangen, ist also richtig und nötig. Ausnahme, die der Entwurf auch nennt: übernommene Altdateien (`legacy_public_path`) bleiben im Papierkorb öffentlich erreichbar (so gewollt, `Files.php:395-398`); für den Bau gelten sie trotzdem als „nicht verfügbar“, was richtig ist.

**(e) Nach `Restore` fehlen die öffentlichen Kopien: ja, gemessen und gelesen.** `Restore` kopiert nur `files/` (`Restore.php:188-210`), nichts schreibt nach `media/dateien/` oder `media/dokumente/`. Probe 2: Datei öffentlich schalten, Kopie löschen (wie auf einem neuen Server): Zeile `public = 1`, `publicUrl()` liefert trotzdem einen Link, `GET /admin/medien` ändert nichts. Die behauptete Lücke besteht.

### Frage 2: Sicherheit

**Kein Weg zu einer privaten, gelöschten oder fremden Datei, wenn die Auflagen gelten.** Im Einzelnen:

- **Id statt Adresse:** `file` wird als Zahl geprüft wie `photoId` (`Blocks.php:248-257`). Ein Feld für Adresse oder Pfad gibt es nicht. Beim Bau kommt die Adresse nur aus `public_name` / `legacy_public_path`.
- **`publicUrlOrNull`:** Das Muster stimmt mit `removeCopy`/`purge` überein (`Files.php:286-291`, `434-454`), **mit `D`** (siehe B2). Das Namensschema ist durch `Galleries::slugify` immer rein ASCII (`Galleries.php:21-31`), das Muster passt also immer auf selbst erzeugte Namen; eine Datei, die gerade öffentlich gemacht wurde, fällt daher nicht fälschlich durch.
- **Verfügbarkeit beim Bau:** `public = 1 AND deleted_at IS NULL AND Muster AND is_file`. Alle vier Bedingungen sind nötig (siehe d, e).
- **Ausgabe:** Titel und Name nur über `Renderer::h()`; die Adresse kommt nach Muster geprüft aus der Datenbank. Ein `javascript:`/`data:`/`//` ist ausgeschlossen, weil keine Eingabe in den Link fließt. Wichtig: die Adresse **nicht** durch `link()` und `url()` schicken (Auflage im Entwurf, korrekt) und `link()` für `/media/` ohne Präfix lassen.
- **Prüfung vor dem Umschalten:** reicht für die Sicherheit, nicht gegen den Rest des Rennens (siehe B3). Was bleibt: Millisekunden zwischen Prüfung und `activate`, ein 404 auf eine Datei, die gerade gewollt entfernt wurde. Das ist harmlos, weil danach das Hinweisband steht.
- **Logo aus Medien:** Der Pfad geht über `Files::absolute()` (Muster `^[0-9a-f]{2}/[0-9a-f]{24}\.(pdf|svg|png|jpg|webp)$`, nur Dateien im Ordner `files/`; `Files.php:201-208`). Die Größe steht in `files.bytes` (aus der bereinigten Datei, `Files.php:109-112`), die Einbettung als Data-URL in `<img>` ist ein Weg, den es heute schon gibt. Zusätzlich vor dem Einbetten `filesize` prüfen, nicht nur `bytes` aus der Datenbank (eine veränderte Datenbank könnte große Dateien melden). Die SVG wurde beim Hochladen von `SvgCheck::sanitize` bereinigt (`Files.php:89`).
- **Bestätigungsseite und Lese-Routen:** CSRF und Rolle gibt der Wrapper `add()` vor (`AdminController.php:166-196`, POST braucht `_csrf`). Die Bestätigungsseite muss `_csrf` in ihrem Formular tragen; Ausgabe mit `$e()`; Ids nur als Zahlen aus `idList`. `bestaetigt=1` ist kein Sicherheitsmerkmal, nur Bedienhilfe. Kosten: ein Durchlauf über alle Seiten (siehe N2).
- **Einzel-Upload (Stufe 6):** Die Annahmen stimmen **nicht** ganz, siehe Frage 4.

### Frage 3: Vollständigkeit und Widersprüche

Geprüft; eingearbeitet sind die Dinge, die der Entwurf gut regelt (Rollback-Hinweis, Papierkorb-Zweige, „Wiederherstellen macht privat“). Offene Stellen:

- **Rollback und Fassungen:** Der Entwurf ist stimmig. Eine Fassung ohne `file_ids` bekommt keinen Hinweis (richtig). Zusätzlich: Nach „privat, dann wieder öffentlich“ **kann sich die Adresse ändern** (gemessen, B1). Dann zeigt eine alte Fassung auch bei jetzt öffentlicher Datei ins Leere. Der Hinweis „nicht mehr öffentlich“ muss deshalb prüfen, ob **die Adresse der Fassung** noch ausgeliefert wird (also `is_file` auf den Namen aus dem Manifest), nicht nur, ob `file_id` öffentlich ist. Daher im Manifest je Datei die Adresse speichern (`file_urls` oder `file_ids` als Liste von `{id, url}`), nicht nur die Id.
- **Papierkorb-Zweige:** siehe (d); Einzel-Papierkorb setzt `public = 0` sofort (`Files.php:379-384`), Zweig-Papierkorb später.
- **Wiederherstellen macht privat:** gestützt (`Files::restore` ändert `public` nicht, `trash` hat es auf 0 gesetzt; Zweig: `unpublishTrashed`).
- **Altverweise `media/dokumente`, `legacy_public_path`:** richtig als Zeichenfolge-Suche gelöst; H2 beachten.
- **SVG-Selbsttest:** B2, N7.
- **Downgrade:** richtig beschrieben, nur Testserver.
- **Sicherungsformat:** unverändert, richtig. Neu zu schreiben ist ein Satz zu `data/brand/` (N3).
- **Spalten (`SIMPLE`):** siehe Frage 1(a).
- **Startseite:** richtig (`home_blocks` wird in `data()` geprüft, `Renderer.php:164-185`); der Bau-Fehler für die Startseite ist immer hart.
- **Vorschau (`previewLive`):** `Blocks::parse($raw, $this->s->db, …)` prüft Existenz und Papierkorb (422 bei Fehler); die Öffentlich-Prüfung geschieht beim Rendern und ergibt den Platzhalter. Die Blöcke aus dem Request laufen **nicht** durch die Prüfung in `data()` (nur gespeicherte Blöcke), also keine Warnung dort; der Platzhalter muss das allein leisten. Passt zum Entwurf.
- **Hinweisband:** B5.

### Frage 4: Stufen und Reihenfolge

Tragfähig, jede Stufe lässt Admin und Seite lauffähig. Anmerkungen:

- **Stufe 1 ist zu groß und mischt zwei Dinge.** Zeilenzahl ehrlicher: Blocks (ca. 60), Files (publicUrlOrNull, `repairPublicCopies`, Hilfsfunktion; ca. 100), Renderer (ca. 70), Builder (ca. 40), Seiten-CSS in sechs Designs hell und dunkel, Tests: eher **500 bis 700 Zeilen** als 350. Vorschlag: **1a** Format, Renderer, Bau, Prüfung vor Umbenennen, Manifest, `link()`-Korrektur, CSS, Tests dazu; **1b** Reparatur der Kopien (`repairPublicCopies`, Systemprüfung, Housekeeping, N5, Tests, Proben A und C). 1b löst ein bestehendes Problem unabhängig von Blöcken und kann **vor** 1a gebaut und geprüft werden; es betrifft die Fable-Pflichtpunkte (schreibt nach `media/`).
- **Stufe 2 vor 4:** richtig, damit niemand Dateien einbaut, bevor das Privatstellen warnt. Stufe 2 enthält außerdem den **Foto**-Teil von „Verwendet in“ (Galerien, Titelbild, Startseite): das ist mehr als „Dateien in Blöcken“ und kann bei Zeitdruck nach hinten.
- **Stufe 3 (Detailfeld)** ist unabhängig und kann nach 4 kommen; als Halt vor dem Editor sinnvoll, weil es „Verwendet in“ sichtbar macht.
- **Stufe 4** (JS ca. 250 Zeilen) ist eher 400 bis 500 Zeilen: Dialog, Reiter, Ziehen, „Wohin?“, `files`-Tabelle, Rückgängig. Als „groß“ ehrlich gekennzeichnet. Die Browser-Prüfliste ist richtig; bei Fotos hat Prüfung 42 den Fehler B1 (Rückgängig nach Einsetzen) gefunden, hier gleich mitprüfen (steht schon drin).
- **Stufe 5** ist unabhängig; Vorschlag: vorziehen vor 4, wenn Lutz die Logo-Sicherung (N3) wichtig findet.
- **Fehlende Tests oder Prüfpunkte:**
  - Bestätigungsseite ändert `site_dirty_n` nicht (B5).
  - Ordner-Zweig mit benutzter Datei warnt (N1).
  - `repairPublicCopies`: Namensmuster mit `../` oder Zeilenumbruch, SVG ohne Schutzkopf wird nicht repariert, fehlendes Original ändert `public` nicht (B1, B2).
  - Prüfung vor `rename`: nach Abbruch kein Ordner in `releases/`, `current` unverändert (B3).
  - Fassung zurücksetzen, deren Datei zwischenzeitlich eine andere Adresse bekommen hat.
  - `Blocks::fileRefs` mit Nummern in Spalten (B4).

### Frage 5: Die vier offenen Fragen

1. **Stoppen oder Block weglassen: Empfehlung stimmt, Stoppen.** Eine still verschwundene Preisliste ist schlimmer. Ergänzung: Die Fehlermeldung im Probelauf soll einen Link zur Seite und zur Datei haben („Seite öffnen“, „In Medien ansehen“), damit Matthias es ohne Suchen beheben kann. Das Argument „vergessenes Privatstellen blockiert anderes Veröffentlichen“ bleibt, wird durch die Bestätigungsseite und B3 gemildert.
2. **Bestätigung statt Sperre: stimmt.**
3. **Bilddateien nur als Download-Block: stimmt.** Keine Bildpipeline, kein Entfernen von Standortdaten, nur ein neuer Blocktyp (Entscheidung von Lutz).
4. **Stufe 6 streichen: stimmt, und der Aufwand ist größer als angegeben.** `Request` hat keinen Weg zu `$_FILES` (`Request.php:13-20`; `public/index.php:21` übergibt nur `$_POST`). Es bräuchte einen neuen Konstruktorparameter, eine Änderung in `index.php` und einen Prüfpunkt für `post_max_size` (bei Überschreitung leert PHP `$_POST` **und** den CSRF-Wert, die Meldung wäre „Anfrage abgelehnt“, wie der Entwurf schon sieht). Zudem lässt sich `is_uploaded_file()` in der Testumgebung nicht erfüllen; man bräuchte eine austauschbare Prüfung wie `svgProbe`. Das ist die einzige Stufe mit neuer Annahmestelle, der Nutzen ist klein (Hinweis „braucht JavaScript“ steht schon in `admin/medien.php:98`). Streichen; bei Bedarf als eigener Entwurf mit Fable-Prüfung.

## 3. Direkt im Entwurf 44 geändert (Kleinigkeiten)

- Kasten „Stand nach Opus-Prüfung“ mit den fünf Auflagen oben im Entwurf.
- Abschnitt 3: „vermutlich … nicht gemessen“ durch den gemessenen Befund ersetzt (Vorschau-Präfix, 404).
- Abschnitt 3: Zeile „Ratenbegrenzung“ korrigiert (`photoPick` hat keine; Entwurf 43 N3 betrifft die Vorschau).
- Abschnitt 2.3: Modifikator `D` am Namensmuster.
- Abschnitt 5: Prüfung vor dem Umbenennen des Bauordners, mit frischer Abfrage (B3).
- Abschnitt 2.2: Hinweis zu Beschriftung (leerer Block entfällt nicht bei `file` > 0) und zur Spalten-Meldung.
- Abschnitt 11: Testzeile „`file` als Text (nicht nur Ziffern)“.

**Größere Änderungen nur als Befund (bitte beim Bau beachten):** B1, B2 (Abschnitt 6 umschreiben), B4 (`fileRefs`), B5, N1, N2, die Manifest-Adresse (Frage 3, Rollback), Stufe 1 teilen.

## 4. Empfehlung: Fable-Prüfpunkte vor dem Hochladen

Zusätzlich zu den fünf Punkten im Entwurf (Abschnitt 11):

1. **`repairPublicCopies` (1b):** Namensmuster, SVG-Selbsttest, Verhalten bei fehlendem Original und Race zwischen zwei Anfragen; schreibt nach `media/` aus Datenbankwerten.
2. **Prüfung und Umschalten im Bau (1a):** `building-*`/`releases/` nach Abbruch, frische Abfrage, kein Weg, dass eine Seite mit totem oder privatem Link `current` wird.
3. **Id-Auflösung in `Blocks::norm` und `Renderer::blockFile`:** `file` als Zahl, alle Randfälle (Text, Float, negativ, riesig), Ausgabe nur aus Datenbank und Muster, Titel maskiert, Vorschau-Platzhalter ohne Link.
4. **Logo aus Medien (5):** liest eine private Datei in jede Seite: Pfad, Größe, `mime` aus der Datenbank gegen den Inhalt, Data-URL.
5. **Bestätigungsseite und die beiden Lese-Routen (2, 3, 4):** Ausgabe, CSRF, Rolle, was das JSON verrät (Name des Hochladenden).
6. **Stufe 6, falls doch gebaut:** neue Annahmestelle (`Request`, `$_FILES`, `post_max_size`).

**Opus:** nach dem Bau den Diff einmal je Block (1a und 1b zusammen mit 2; dann 3 und 4; dann 5), mit Browser-Prüfliste (Chromium, Firefox, echte Maus; Safari am Mac macht Lutz).
