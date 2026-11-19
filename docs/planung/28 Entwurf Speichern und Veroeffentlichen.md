# Entwurf 28: Speichern und Veröffentlichen verständlich machen

Stand: 19.11.2026. **Gebaut (Abschnitt 7), nichts hochgeladen, nichts auf dem Server.** Entwurf vom 18.11.2026. Auslöser: Lutz wählte unter Einstellungen ein Design und drückte „Veröffentlichen“, ohne vorher „Speichern“. Die Auswahl ging verloren. Sein Wunsch: Wer einen Block ändert, soll davon ausgehen können, dass es auch schon online ist.

## 1. Was heute passiert (Bestand, belegt)

**Kernpunkt:** „Veröffentlichen“ ist im Admin **kein Knopf auf der Bearbeitungsseite**, sondern ein **Link** auf eine eigene Seite `/admin/veroeffentlichen` (`app/templates/layout.php:38`, in jeder Seitenleiste, dazu Textlinks in `page_edit.php:7`, `home_edit.php:8`, `dashboard.php:6-7`). Dort steht erst der Knopf „Jetzt veröffentlichen“ (`publish.php:43`). Dieser Knopf gehört zu einem **anderen Formular**, das keine Werte der Seite davor mitnimmt. Deshalb geht ungespeicherte Eingabe verloren: Der Link verlässt die Seite (im Seiten-Editor fragt `page-editor.js:675` wenigstens „Seite verlassen?“, in Einstellungen nicht).

`Builder::publish()` (`app/src/Site/Builder.php:132`) baut **immer die ganze Seite** (`Renderer::all()`: alle Seiten, Galerien, Weiterleitungen `_redirects.json`, `sitemap.xml`, `robots.txt`; `Renderer.php:589-604`). Es gibt kein „nur diese Seite veröffentlichen“.

| Stelle | Knopf / Link | Beschriftung heute | Was er wirklich tut | Was Lutz erwartet |
|---|---|---|---|---|
| Seiten-Editor (`page_edit.php:25-27`, `SitePages::pageSave`) | Knopf | „Speichern“ | Speichert Titel, Adresse, Blöcke, Status in der Datenbank. Online ändert sich nichts. Meldung: „Gespeichert. Die Seite ist aktualisiert.“ (`MediaPages.php:38`) | Dass die Seite online aktualisiert ist (die Meldung sagt es sogar) |
| ebenda | Knopf | „Speichern und zur Liste“ | wie oben, dann Liste | wie oben |
| ebenda | Link | „Vorschau (gespeicherter Stand)“ | Zeigt den **gespeicherten** Stand in `/admin/vorschau/…` (`SitePages::preview`, Renderer im Vorschaumodus) | passt, wenn vorher gespeichert wurde |
| ebenda | Auswahl „Status“ | „Entwurf“ / „veröffentlichen (mit dem nächsten Veröffentlichen)“ | Nur ein Merker. Die Seite geht erst mit dem nächsten Bau live | unklar, klingt wie ein Befehl |
| ebenda | Link | „Veröffentlichen“ | Wechsel zur Seite Veröffentlichen, **ungespeicherte Eingaben weg** | dass jetzt veröffentlicht wird |
| Startseite (`home_edit.php:19-20`, `homeSave`) | Knopf / Link | „Speichern“, „Vorschau (gespeicherter Stand)“, Link „Veröffentlichen“ | wie Seiten-Editor. Meldung: „Sichtbar wird sie mit dem Veröffentlichen.“ | wie oben |
| Einstellungen (`settings.php:24`, `siteSettingsSave`) | Knopf / Link | „Speichern“, „Vorschau“ | Speichert Name, Slogan, Titelbild, Design. Das Design ändert **alle** Seiten, aber erst beim nächsten Bau | dass die Designwahl live geht; hier ist es passiert |
| Menü & Fußzeile (`menu.php`, `pageNav`, `pageMove`) | Auswahl „Verlinkt“, Pfeile hoch/runter | kein Speichern-Knopf | Jede Änderung **speichert sofort** (eigenes Formular je Klick), online erst nach Bau | gemischt: sofort gespeichert, aber nicht sichtbar |
| Logos (`logos.php:31-39`) | Knopf | „Auswahl speichern“ | speichert die Zuordnung, Text sagt: sichtbar „mit dem nächsten Veröffentlichen“ | wie oben |
| Galerie (`gallery.php:30,36`) | Knopf | „Speichern“ | Titel, Text usw. | wie oben |
| ebenda | Knopf | „Als veröffentlicht markieren“ / „Zurück zum Entwurf“ | Setzt nur den Status. Online erst nach einem Bau | klingt, als wäre sie dann online |
| Foto (`photo.php:41`), Fotos, Medien, Import, Papierkorb | Knopf | „Speichern“ u. a. | Datenbank. Alt-Texte, Titel, Fotos in Galerien wirken auf die gebaute Seite, aber erst nach Bau | wie oben |
| Veröffentlichen (`publish.php`, `publishPost`) | Knopf | „Jetzt veröffentlichen“ | Optional alle Entwürfe auf „veröffentlicht“ setzen, dann **die ganze Seite neu bauen**, in `building-<ID>` schreiben, per `rename` und Zeiger `current` umschalten. Bei Fehler bleibt die alte Fassung | der einzige „echte“ Knopf, aber weit weg vom Ort der Änderung |
| ebenda | Knopf | „Zurücksetzen“ auf frühere Fassung | Rollback auf eine alte Fassung | passt |
| Seitenleiste (`layout.php:38`, `publishDirty`) | Link, wird auffällig, wenn es Änderungen gibt | „Veröffentlichen“ | Nur Link. Der Zustand „geändert“ wird bei jedem POST auf Fotos/Galerien/Seiten/Menü/Startseite/Logos/Einstellungen/Import/Medien auf „ja“ gesetzt (`AdminController.php:174-176`) | gut als Hinweis, aber kein Knopf |

**Zusammengefasst:** Es gibt zwei Arbeitsschritte (Speichern, Bauen), aber nur der erste hat überall einen Knopf. Der zweite hat ein Wort, das wie ein Knopf aussieht und ein Link ist. Die Meldungen sind uneinheitlich („Die Seite ist aktualisiert“ vs. „Sichtbar wird sie mit dem Veröffentlichen“).

**Sicherheitsnetz, das schon da ist (belegt):** Der Bau läuft unter einer Sperre (`.lock`) in einem Zwischenordner; schlägt etwas fehl oder läuft die Zeit ab, wird nichts umgeschaltet, die alte Fassung bleibt, Reste werden beim nächsten Lauf gelöscht (`Builder.php:141-190`). Vor jedem Bau wird die Datenbank gesichert. Frühere Fassungen bleiben und lassen sich zurückholen (`publishRollback`). Fehler im Probelauf (`plan()['errors']`) sperren den Knopf. Das Sicherheitsnetz ist die Grundlage für alles Folgende.

## 2. Alternativen

| | Idee | Pro | Contra |
|---|---|---|---|
| **A** | Je Bearbeitungsseite **ein Knopf „Speichern und veröffentlichen“**, die alten Knöpfe entfallen (Vorschlag von Claude) | Einfachstes Bild, entspricht Lutz’ Wunsch. Kein Verlust von Eingaben mehr | Kein Weg, etwas **Halbfertiges zu speichern, ohne dass es live geht**, außer dem Status Entwurf. Bei einer schon veröffentlichten Seite gibt es keine getrennte Arbeitskopie. Die zweite, nicht-technische Person kann so versehentlich Unfertiges live stellen |
| **B** | **Zwei Knöpfe, klar benannt**: „Speichern und veröffentlichen“ und „Nur speichern (noch nicht online)“ | Beide Wünsche möglich, nichts geht verloren | Wieder zwei Knöpfe; nur gut, wenn der Hauptknopf eindeutig hervorgehoben ist |
| **C** | **Hinweisband** „Es gibt unveröffentlichte Änderungen“ mit einem Knopf „Jetzt veröffentlichen“ (oben auf jeder Admin-Seite), Speichern bleibt wie heute | Kleinster Eingriff. Gut für viele kleine Änderungen (Menü-Pfeile, Logos, Alt-Texte) | Löst Lutz’ Fehlbedienung nur halb: Das Band steht nach dem Speichern da, aber „Speichern“ bleibt „nicht online“. Das ist weiter ein zweiter Schritt, den man vergessen kann |
| **D** | **Sofort live beim Speichern**, kein Veröffentlichen-Schritt | Am einfachsten gedacht | Jedes Speichern baut die ganze Seite (Zeitgrenze, siehe Risiken), Menü-Pfeile würden bei jedem Klick bauen, kein Probelauf mit Fehlern vor dem Livegang, kein Halbfertig-Speichern, Rückgängig nur über Fassungen. **Nicht empfohlen** |

## 3. Empfehlung

**B als Kern, C als Ergänzung.** Das weicht in einem Punkt von Claudes erstem Vorschlag ab: Den Weg „nur speichern“ ganz abzuschaffen, geht nicht, weil es für eine schon veröffentlichte Seite keinen Entwurfsstand gibt. Wer an einer Seite länger arbeitet oder abbrechen muss, würde sonst jedes Zwischenergebnis live stellen. Deshalb:

1. **Hauptknopf, hervorgehoben:** „Speichern und veröffentlichen“. Er tut beides, in dieser Reihenfolge, in einer Anfrage. Das erfüllt den Wunsch „Block ändern, dann ist es online“.
2. **Zweitknopf, schlicht:** „Nur speichern (noch nicht online)“, mit einem Satz darunter. Der Text im Seiten-Editor sagt es offen: „Online ist weiterhin die letzte Fassung.“
3. **Hinweisband** auf jeder Admin-Seite, sobald es Unveröffentlichtes gibt: „N Änderung(en) sind noch nicht online. [Jetzt veröffentlichen] [Ansehen]“. Das ersetzt den unscheinbaren Link in der Seitenleiste.
4. **Status** der Seite/Galerie bleibt der einzige Weg, etwas **dauerhaft** nicht zu zeigen („Entwurf“). Neue Beschriftung statt „veröffentlichen (mit dem nächsten Veröffentlichen)“: „Online sichtbar“ / „Entwurf (nicht online)“.
5. **Eine Aussage statt vieler:** Alle Meldungen nach dem Speichern sagen in einem Satz, was Sache ist („Gespeichert. Noch nicht online.“ bzw. „Gespeichert und veröffentlicht.“).
6. **Ehrlicher Hinweis bei „Speichern und veröffentlichen“:** Weil immer die ganze Seite gebaut wird, kommen **andere gespeicherte, aber unveröffentlichte Änderungen mit live** (z. B. Menü, Logo). Wenn es solche gibt, zeigt der Knopf darunter eine Zeile: „Dabei gehen auch 2 weitere Änderungen online: Menü, Logo.“ (aus `Builder::plan()['changes']`, die Liste gibt es schon).

### Ablauf und Beschriftung je Bearbeitungsseite

| Seite | Hauptknopf | Zweitknopf | Wirkung auf „online“ | Besonderheit |
|---|---|---|---|---|
| **Seite bearbeiten** | „Speichern und veröffentlichen“ | „Nur speichern (noch nicht online)“ | Haupt: speichern, Seite auf „Online sichtbar“, bauen, bei Erfolg Meldung „Veröffentlicht“, danach Link „Seite ansehen“ (öffentliche Adresse) | Ist der Status „Entwurf“, heißt der Hauptknopf „Speichern“ und der Text sagt „Entwurf, nicht online“. Zum Livegang einer Entwurfsseite: eigener Knopf „Speichern und online stellen“ |
| **Startseite bearbeiten** | „Speichern und veröffentlichen“ | „Nur speichern (noch nicht online)“ | wie oben | „Auf Vorgabe zurücksetzen“ (`homeSave`, `homereset`) bleibt, ohne Bau, mit Hinweisband |
| **Einstellungen** | „Speichern und veröffentlichen“ | „Nur speichern (noch nicht online)“ | wie oben; Text: „Design und Name betreffen alle Seiten, es werden alle neu erzeugt“ | Das ist die Stelle, an der der Fehler passierte. Vorschau-Link bleibt |
| **Galerie** | Ein Knopf „Speichern“ wie heute für Titeltext | ersetzt „Als veröffentlicht markieren“ durch „Galerie online stellen“ (setzt Status, baut) und „Galerie auf Entwurf setzen“ (setzt Status, baut, nimmt sie vom Netz) | Statuswechsel baut sofort, denn das war immer gemeint | Textänderungen: wie Seite („Speichern und veröffentlichen“ / „Nur speichern“) bei bereits online stehender Galerie |
| **Menü & Fußzeile** | kein Bau je Klick (Pfeile, Auswahl speichern einzeln) | – | Hinweisband mit „Jetzt veröffentlichen“ oben und unten auf der Seite | Würde jeder Pfeilklick bauen, dauert die Bedienung unerträglich |
| **Logos** | „Auswahl speichern und veröffentlichen“ | „Nur speichern“ | wie Seite | Hochladen/Löschen von Logos bauen nicht |
| **Foto, Fotos, Medien, Import, Papierkorb** | „Speichern“ wie heute | – | nur Hinweisband (Alt-Text, Titel, Fotozuordnung wirken auf die Seite, aber das sind viele Kleinigkeiten) | Zustand „unveröffentlicht“ erscheint im Band |
| **Seite „Veröffentlichen“** | „Jetzt veröffentlichen“ (unverändert) | – | Probelauf mit Liste, Fehlern, Entwürfen, Fassungen, Zurücksetzen | Bleibt die Kontrollstelle für alles; Seitenleisten-Link behalten |
| **Vorschau** | – | – | zeigt weiter den gespeicherten Stand | Banner „Vorschau, nicht online“ oben |

Bei Fehler im Bau (Probelauf-Fehler, Sperre, Zeit): **Speichern gilt trotzdem**, die Seite zeigt „Gespeichert. Veröffentlichen ist fehlgeschlagen, online ist weiter die alte Fassung: <Grund>. [Erneut versuchen]“. Nie „alles verloren“.

## 4. Risiken

| Risiko | Einschätzung | Gegenmaßnahme |
|---|---|---|
| **Dauer auf all-inkl (`max_execution_time` 60 s)** | Bau schreibt nur HTML, Entwurf 11 nennt „einige Sekunden“ bei einigen hundert Seiten. Eine **Messung auf br-foto-Größe fehlt** (nicht belegt). `plan()` baut ebenfalls alles, wird auch beim Öffnen von Veröffentlichen und für das Hinweisband gerufen (`AdminController.php:600`) | Vor dem Bauen auf dem Testserver mit Import-Daten **messen** (Dauer von `plan()` und `publish()`). Das Hinweisband nicht bei jeder Seite durch `plan()` berechnen, sondern den Merker `site_dirty` verwenden und erst beim Öffnen von Veröffentlichen genau prüfen |
| **Zeitüberschreitung mitten im Bau** | Alte Fassung bleibt, Reste werden gelöscht, Sperre fällt weg (`Builder.php:141-190`). Ein Rest: kurze Lücke zwischen `rename` und `activate` | Meldung „Zeit überschritten, online ist die alte Fassung“; bei Bedarf Schritt in Stücke teilen (nur falls die Messung es nötig macht) |
| **Doppelklick / zwei Personen** | Sperre sagt „läuft gerade ein anderes Veröffentlichen“ | Knopf nach Klick sperren (Skript), klare Meldung |
| **Halbfertige Änderungen gehen live** | Wichtigstes fachliches Risiko von A und D, bei B gemildert, weil beide Knöpfe benannt sind und die Zeile „dabei gehen auch … online“ steht | Status Entwurf für Unfertiges; Zweitknopf „Nur speichern“; Zeile mit Liste |
| **Weiterleitungen, Sitemap** | Werden bei jedem Bau neu erzeugt (`Renderer.php:589-604`), also immer passend zum Stand. **Geprüft am 19.11.2026:** Eine Adressänderung (Seite oder Galerie) erzeugt **keine** automatische Weiterleitung (`Pages.php` kennt keine Tabelle `redirects`; die Weiterleitungen kommen nur aus dem WordPress-Import). Die alte Adresse ist nach dem Veröffentlichen 404, Test `tests/speichern.php`. Verhalten unverändert gelassen, kein Teil dieses Auftrags; als Vorschlag für später: bei Adressänderung einer **online stehenden** Seite eine Weiterleitung anlegen | Hinweis im Bericht an Lutz; nichts geändert |
| **Vorschau** | Zeigt den gespeicherten Stand, nicht den Formularinhalt. Mit „Speichern und veröffentlichen“ als Standard wird das seltener gebraucht | Banner „Vorschau“; Link „Vorschau“ nur neben „Nur speichern“ |
| **Rückgängig** | Fassungen und Zurücksetzen existieren, Datenbanksicherung vor jedem Bau | Unverändert behalten; im Erfolgstext Link „Rückgängig (vorherige Fassung)“ |
| **Tests und Audit** | Neuer Weg „speichern und bauen“ braucht Tests (Fehler beim Bauen, Sperre, Entwurf, Reihenfolge) und Audit-Zeilen (`page_saved` + `site_published` schon vorhanden) | Kein neues Recht nötig; bisherige Rechteprüfung des Veröffentlichens gilt auch für den Kombiknopf (Redaktion darf veröffentlichen? siehe Frage 2) |

## 5. Aufwand (grob, für Sonnet baubar)

| Teil | Aufwand | Risiko |
|---|---|---|
| Kombiknopf je Seite (Seite, Startseite, Einstellungen, Logos) mit gemeinsamer Hilfsfunktion `saveThenPublish()`, Fehlertexte, Tests | mittel (ein Block) | mittel |
| Galerie: Statusknöpfe umbenennen und bauen lassen | klein | niedrig |
| Hinweisband mit Zeile „dabei gehen auch … online“ und Meldungstexte vereinheitlichen | klein bis mittel | niedrig |
| Beschriftungen, Statusauswahl, Hinweise in Templates | klein | niedrig |
| Messung der Bauzeit auf dem Testserver | klein, **vorher** | – |
| Opus-Prüfung des Diffs (ein Durchgang) | klein | – |

Gesamt: ein Block, kein Eingriff in Datenmodell oder Bildverarbeitung, keine Migration. Das Fassungssystem bleibt unverändert.

## 6. Fragen an Lutz (beantwortet am 19.11.2026: alle Empfehlungen wie vorgeschlagen)

1. **Zwei Knöpfe oder einer?** Empfehlung: Hauptknopf „Speichern und veröffentlichen“ plus kleiner „Nur speichern (noch nicht online)“. Soll es den Zweitknopf wirklich geben, oder darf jede Änderung sofort live gehen?
2. **Wer darf veröffentlichen?** Soll die Redaktion (zweite Person) den Kombiknopf auch haben, oder nur du? Wenn nur du, sieht die Redaktion nur „Speichern“ und das Hinweisband zeigt „wartet auf Freigabe“.
3. **Menü-Pfeile und Foto-Änderungen:** Sind diese Kleinigkeiten mit Hinweisband plus einem Klick auf „Jetzt veröffentlichen“ in Ordnung, oder soll auch hier jeder Klick sofort live gehen (dauert länger, siehe Risiko Zeit)?
4. **Galerien:** Soll „Online stellen“ sofort bauen (Empfehlung), oder zählt der Status wie bisher nur als Merker bis zum nächsten Veröffentlichen?

Nach deiner Antwort: erst die Bauzeit auf dem Testserver messen, dann bauen, danach Opus-Prüfung. Nichts geht auf pve01/br-foto.com, bevor du es freigibst.


## 7. Gebaut (19.11.2026)

**Entscheidungen von Lutz (19.11.2026):** (1) Hauptknopf „Speichern und veröffentlichen“ plus Zweitknopf „Nur speichern (noch nicht online)“; (2) beide Nutzer dürfen veröffentlichen, es gibt keine neue Rollenprüfung (die Routen haben wie das bisherige Veröffentlichen keine Admin-Sperre); (3) Menü-Pfeile und Foto-Änderungen bauen nicht, sondern erscheinen im Hinweisband; (4) „Galerie online stellen“ und „Galerie auf Entwurf setzen“ bauen sofort.

**Umsetzung:**

- `MediaPages::saveThenPublish()` (gemeinsame Hilfsfunktion): wird **nach** dem erfolgreichen Speichern gerufen, baut nur bei Feld `veroeffentlichen=1` (oder erzwungen für Galerie-Status und „online stellen“), schreibt `site_published` ins Protokoll (`page_saved` usw. schreibt der jeweilige Speicherweg wie bisher) und setzt den Merker zurück. Fehler beim Speichern brechen vorher ab (400, nichts gebaut).
- Scheitert der Bau, bleibt das Gespeicherte. Weiterleitung mit `m=savedfail&r=<Code>`; der Grund kommt nur aus der festen Liste `Builder::FAIL_TEXT` (`sperre`, `fehler`, `zuviele`, `ordner`, `technisch`), nie aus freiem Text der Adresse. Meldung: „Gespeichert. Veröffentlichen hat nicht geklappt, online ist weiter die alte Fassung: …“ mit Knopf „Erneut versuchen“ (führt zu „Veröffentlichen“).
- Meldungen einheitlich: „Gespeichert. Noch nicht online.“ / „Gespeichert und veröffentlicht.“ / für die Galerie „Die Galerie ist online. …“ bzw. „… wieder ein Entwurf und nicht mehr online. Veröffentlicht.“
- Knöpfe: Seite (online), Startseite, Einstellungen, Logos, Galerie (online): Haupt- und Zweitknopf. Seite im Entwurf: „Speichern und online stellen“ (Feld `online`, setzt den Status auf „veröffentlicht“ und baut) und „Speichern (Entwurf, nicht online)“. Status heißt jetzt „Online sichtbar“ / „Entwurf (nicht online)“. „Speichern und zur Liste“ bleibt als „Nur speichern, dann zur Liste“.
- Sperre nach Klick: `data-busy` an den Knöpfen, `admin.js` sperrt nach dem Absenden (per `setTimeout`, sonst ginge der Knopfwert verloren) und zeigt „Wird veröffentlicht …“.
- **Hinweisband** auf jeder Admin-Seite außer „Veröffentlichen“: „N Änderung(en) sind noch nicht online. [Jetzt veröffentlichen] [Ansehen]“. Es rechnet **nicht** mit `plan()`, sondern nutzt `site_dirty` und den neuen Zähler `site_dirty_n` (Tabelle `meta`, keine Migration): jeder Speichervorgang zählt eins dazu, die Seite „Veröffentlichen“ setzt die genaue Zahl aus dem Probelauf, ein erfolgreicher Bau setzt auf 0. Die Zahl zählt also Speichervorgänge, nicht geänderte Dateien.
- **Zeile „Dabei gehen auch N weitere Änderungen online: …“** (aus `plan()['changes']`, ohne die eigene Seite) steht unter den Knöpfen der Formulare und rechnet **nur, wenn es Unveröffentlichtes gibt**.
- Vorschau-Band heißt „Vorschau, nicht online“ (mit Link zu „Veröffentlichen“).

**Messung der Bauzeit (nur Docker auf dem Mac, PHP 8.5 CLI; `tests/messung_bauzeit.php`, nicht Teil von `run.php`):**

| Bestand | Dateien | `plan()` | `publish()` | Speicher |
|---|---|---|---|---|
| 40 Galerien × 25 Fotos, 200 Seiten | 245 | 0,06 s | 0,07 s | 15 MB |
| 150 Galerien × 60 Fotos, 600 Seiten | 755 | 0,26 s | 0,30 s | 38 MB |

Das ist **nicht** all-inkl: dort ist die Platte langsamer (FTP-Hosting, Netzwerk-Dateisystem), `max_execution_time` ist 60 s. Die **Messung auf all-inkl (Testserver) steht aus und erfolgt erst nach Freigabe von Lutz.** Die Fotos sind hier 25 bzw. 60 echte Bilder, die mehrfach verwendet werden; der Bau schreibt nur HTML, die Bilddateien werden nicht kopiert.

**Nicht gebaut / offen:** Foto-, Medien- und Papierkorb-Seiten haben weiter nur „Speichern“ und das Hinweisband (wie im Entwurf). Rechte: keine zweite Rolle getestet (die zweite Person ist ein normales Konto mit denselben Routen; die Routen haben keine Admin-Sperre).
