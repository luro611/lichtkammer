# Prüfung Code Bildvarianten neu erzeugen (Opus)

Stand: 19.11.2026. Geprüft: Commit `98a3f13` (Verwaltung → Bildvarianten: `Media/VariantRebuild`, `Admin/VariantPages`, `varianten.js`, Warnband, Systemprüfung, `tools/restore-probe.sh`, Doku). Maßstab: Auftrag der Bau-Sitzung, Berichte `34` und `35`.
Tests: **1 505 Prüfungen, 0 Fehler** (vorher und nachher gleich, es wurde kein Code geändert). Nichts hochgeladen, nichts am Server geändert.

## Ergebnis in Kürze

Der Knopf ist sauber gebaut und **für die Probe und den Livegang brauchbar**. Alle sieben Schwerpunkte sind in Ordnung bis auf zwei Lücken im Ablauf (B1 mittel, B2 niedrig bis mittel). Keine Lücke bei Sicherheit: nur Administratoren, CSRF, Protokoll nur mit Zahlen, Pfade geprüft, keine Metadaten in den Varianten, Originale werden nie verändert. Es gibt **keinen Befund mit Risiko hoch**.

Ich habe nichts selbst geändert: beide Befunde sind kein „kleiner eindeutiger Fehler“, sondern brauchen eine Entscheidung zum Ablauf (siehe Empfehlung). Beide sind für den Livegang **nicht zwingend**, B1 sollte aber vor dem Lauf mit den echten Fotos auf all-inkl behoben sein, weil dort ein hängendes Foto sonst den Weg blockiert.

## Befunde

| Nr. | Risiko | Befund | Stand |
|---|---|---|---|
| B1 | mittel | Ein Foto, das die Anfrage abstürzen lässt (Zeitüberschreitung, Speichergrenze), blockiert den ganzen Durchgang für immer | offen |
| B2 | niedrig bis mittel | Keine Sperre gegen zwei gleichzeitige Läufe (zwei Tabs, zwei Personen) | offen |
| N1 | niedrig | Zwischenspeicher des Warnbands (5 Minuten) kann nach Uploads oder Löschen kurz falsch stehen | offen, harmlos |
| N2 | niedrig | Fotos mit fehlendem Original halten das Warnband dauerhaft an | offen, gewollt, nur anders erklären |
| N3 | niedrig | Der Skript-Wiederholversuch nach einem Serverfehler verliert `neu` und `erzwingen` | offen |
| N4 | niedrig | Ein misslungenes „Erzwingen“ markiert das Foto als fehlgeschlagen, obwohl gute alte Varianten da sind | offen |

### B1 (mittel): ein „Giftfoto“ blockiert den Durchgang

`VariantRebuild::step()` setzt den Zeiger (`variants_cursor`) erst **nach** dem Foto weiter. Beendet PHP die Anfrage mitten im Foto (Zeitgrenze von all-inkl, Speicher, Prozess beendet), bleibt der Zeiger vor diesem Foto. Der nächste Aufruf beginnt wieder mit demselben Foto und stürzt wieder ab. Das Skript versucht es dreimal und hört dann auf; „Weiter“ macht dasselbe noch einmal. Alle Fotos dahinter kommen nie dran. Das trifft nur Fotos, die allein länger brauchen als die Grenze (Größe bis 100 Megapixel sind erlaubt, `MAX_PIXELS`), auf all-inkl am ehesten bei großen Originalen. Die Bildbibliothek fängt normale Fehler selbst ab (`process()` fängt `Throwable`, das Foto wird „fehlgeschlagen“ und der Durchgang geht weiter); es geht nur um das harte Abbrechen von außen.
Wiederholbar bleibt alles: nichts wird zerstört, es hängt nur.
**Empfehlung:** vor jedem Foto `variants_inflight = <id>` und Zeit merken. Findet ein Aufruf beim Start dieselbe Nummer als „in Arbeit“ und ist die Zeit älter als die Zeitgrenze (z. B. 90 s), das Foto als fehlgeschlagen mit „Zeitüberschreitung, bitte kleiner exportieren“ markieren, zählen und weitermachen. Das gehört zusammen mit B2 in einen Auftrag (Sperre und „in Arbeit“ hängen zusammen).

### B2 (niedrig bis mittel): keine Sperre

Zwei Aufrufe gleichzeitig (zwei Tabs, ein Klick plus Skript, zwei Administratoren) lesen denselben Zeiger und arbeiten dieselben Fotos doppelt ab. **Was dabei nicht kaputtgeht:** Dateien werden in eine Temp-Datei geschrieben und mit `rename` ersetzt (`writeAtomic`), die Datenbankzeilen sind Upserts (`ON CONFLICT … DO UPDATE`), Originale werden nur gelesen. Es gibt also keine halben Dateien und keine doppelten Zeilen. **Was passiert:** doppelte Rechenzeit (auf all-inkl gefährlich nahe an der Grenze, weil zwei Läufe die CPU teilen), die Zähler im Ergebnis („neu erzeugt“) stimmen nicht, und eine Zeile `variants_run` kann überschrieben werden (zuletzt gewinnt). Das Skript auf der Seite sperrt sich selbst (`running`), nur mehrere Tabs und Personen kommen durch.
**Empfehlung:** Sperre in `meta` mit Zeitstempel (z. B. `variants_lock` = Zeit, gilt 90 s); ein zweiter Aufruf antwortet `busy` und das Skript wartet 3 s und fragt neu.

### N1: Zwischenspeicher des Warnbands

`missingCount()` speichert 5 Minuten. Das Einspielen einer Sicherung leert ihn (`Restore` löscht `variants_%`) und jeder Schritt auch, das ist richtig. Nicht geleert wird er bei Uploads und beim Löschen: In meiner Browser-Probe stand das Band nicht, weil kurz vorher „0 von 0“ gespeichert war (leerer Stand beim Anmelden), bis ich den Eintrag löschte. Für den echten Weg unkritisch (Wiederherstellung leert), nur beim Ausprobieren verwirrend. Kein Eingriff nötig.

### N2: fehlende Originale

Fotos ohne Original zählen immer als „ohne Bildvarianten“, das Band bleibt, bis die Originale zurück sind. Gewollt und im Text erklärt („Probleme“, Hinweis auf Sicherung). Der Bandtext sagt aber nur „… Bildvarianten erzeugen“; besser wäre „davon N ohne Original“. Kosmetik.

### N3: Wiederholversuch im Skript

Nach einem 5xx-Fehler der ersten Runde ruft `varianten.js` mit leerer Abfrage erneut auf (`next('', retries - 1)`). War die erste Runde mit `?neu=1&erzwingen=1` noch gar nicht angekommen, beginnt der Server selbst einen **Nicht-Erzwingen-Durchgang**: der Nutzer bekommt nicht, was er wollte, ohne Hinweis. Selten, harmlos (es wird nichts zerstört).

### N4: Erzwingen mit Fehler

Schlägt `process(force)` für ein Foto fehl, setzt `fail()` den Status auf `failed`; die guten alten Varianten bleiben auf der Platte, aber das Foto gilt als fehlgeschlagen und fehlt auf der Seite, bis ein normaler Lauf es wieder repariert (der Lauf „Fehlende erzeugen“ macht das, weil `variantsComplete` bei `failed` falsch ist). Wiederholbar, daher niedrig.

## Prüfung der Schwerpunkte

1. **Zustand, Abbruch, halbe Dateien, Wiederholbarkeit:** Der Stand liegt in `meta` (`variants_cursor`, `variants_run`), nach jedem Foto gesichert; Neuladen oder Abbruch setzt am nächsten Foto fort (Test „Fortsetzen“ und Browser-Probe mit künstlichem Stand: Seite zeigt „3 von 6 … lässt sich fortsetzen“, Knopf „Weiter“). Dateien entstehen als `….tmpXXXX` und werden umbenannt, bei Fehler gelöscht. Wiederholbar: ein zweiter Lauf überspringt gültige Varianten (Zähler „schon fertig“). Offen: B1 und B2.
2. **60 Sekunden:** Budget 20 s je Aufruf, vor jedem Foto wird geprüft, ob das bisher längste Foto noch hineinpasst; mindestens ein Foto je Aufruf. `process()` setzt `set_time_limit(120)`. Das reicht, solange kein einzelnes Foto über etwa 40 s braucht (sonst B1). Speicher: bestehende Grenze `MAX_PIXELS` (100 MP); Bilder werden einzeln geöffnet und mit `close()` freigegeben. Nicht gemessen auf all-inkl (gehört zu Probe B).
3. **Pfade, Symlinks, Rechte, CSRF, Protokoll:** `originalPath()` verlangt den Namen `xx/<24 hex>.<ext>` (`Restore::ORIG_REL`), `is_file`, kein Link, und `realpath` unterhalb des Originalordners. Das ist dieselbe Regel wie beim Sicherungsweg. Die Routen sind nur für die Rolle `admin` und POST prüft den CSRF-Token (Tests: Redaktion 403, ohne Anmeldung 303, ohne Token 403, GET löst nichts aus). Protokoll: `variants_start` und `variants_done` nur mit Zahlen, kein Name, kein Pfad (Test prüft auf `/` und `.jpg`). Ein kleiner Hinweis: `process()` selbst baut den Pfad ohne diese Regel (`originalsDir/original_path`), `VariantRebuild::one()` prüft aber vorher, sodass dieser Weg nie unsichere Pfade erreicht.
4. **Metadaten und GPS:** Es läuft dieselbe Pipeline wie beim Upload (`ImagickSource::encode` mit `stripImage()`, `GdSource` schreibt ohne Metadaten). Der Test lädt fünf Fotos mit EXIF **und GPS**, erzwingt alle Varianten neu und sucht in jeder JPG/WebP-Datei nach `Exif`, `TestCam`, `XMP` und liest `exif_read_data` (GPS, Hersteller): **0 Treffer**. `has_gps` bleibt als Zahl in der Datenbank. Farbprofile: `stripImage()` entfernt sie wie sonst auch, nichts Neues.
5. **Warnband und Systemprüfung:** Band nur für Administratoren (Test: Redaktion sieht es nicht), erscheint nach dem Einspielen, verschwindet nach dem Lauf. Die Systemprüfung rechnet frisch („N von N Fotos haben Bildvarianten“, Stufe `warn` sonst). Im Browser gesehen: Band mit Text und Verweis, Seitenleiste „Bildvarianten“ aktiv. Hinweis nach der Wiederherstellung steht in `restore_done.php` und in der Doku.
6. **Erzwingen:** ersetzt nur Dateien in `media/` (Test: alle Dateien neu geschrieben, Originale unverändert nach Prüfsumme **und** Änderungszeit). Fehlt dabei das Original, bleiben vorhandene Varianten und der Status unangetastet (Test), nur die Meldung „fehlt“ wird gezählt. Bestätigungsfrage über `data-confirm` (Klick-Ereignis, bei „Abbrechen“ wird das Absenden verhindert, das Skript läuft dann nicht).
7. **Doku und `tools/restore-probe.sh`:** `Wiederherstellung.md` beschreibt den Weg wie gebaut (Band → Bildvarianten → Fertig → Veröffentlichen, Fenster offen lassen, Fortsetzen). Die Probe benutzt dieselben Aufrufe wie das Skript der Seite (`/admin/varianten/schritt`, erste Runde `?neu=1`), prüft Band, „N von N“ und die längste Runde (< 60 s). Probe A lief sauber (siehe unten).

## Seite im Browser (lokal, Docker, Testkonto, 6 Fotos ohne Varianten)

| Ansicht | Ergebnis |
|---|---|
| Desktop, hell (Warm) | Aufbau ruhig, Überschrift mit Akzentwort, Seitenleiste markiert „Bildvarianten“, Fortschrittsbalken und Knöpfe sauber |
| Blau, dunkel (stichprobe) | Kontrast gut, Knopf „Weiter“ und Kasten lesbar |
| Handy 375 px | kein waagerechter Überlauf (`scrollWidth` 375), Navigation und Designwahl umbrechen sauber |
| Klick „Fehlende Bildvarianten erzeugen“ | beide Knöpfe gesperrt, Knopf zeigt „Läuft …“, nach wenigen Sekunden Seite neu geladen: „6 von 6 Fotos haben Bildvarianten“, „Letzter Durchgang: 6 neu erzeugt“ |
| Fortsetzen nach Neuladen | mit künstlichem Stand („3 von 6“): Fortschrittsbalken 3/6, Knopf „Weiter“, Text „lässt sich fortsetzen“ |
| Tastatur | Knöpfe sind echte `<button>` im Formular, Tab/Enter wie üblich; Fortschritt als `role="status"`, Protokoll als `aria-live` |

Nicht einzeln durchgesehen: alle sechs Designs in beiden Helligkeiten (Stichprobe: Warm hell, Blau dunkel; die Seite verwendet nur vorhandene Bausteine `card`, `notice`, `btn`, `progress`, daher kein eigener Farbsatz).

## Probe A mit dem neuen Weg

Von mir wiederholt (19.11.2026, lokal in Docker, frische Test-Sicherung mit `tests/erzeuge_testsicherung.php`, Anmeldung mit Passwort und Code): **25 von 25 bestanden, 0 fehlgeschlagen**, Gesamtzeit 10 s. Warnband erscheint nach dem Einspielen, Bildvarianten über `/admin/varianten` erzeugt (4 von 4 Fotos, 1 Runde, 4,7 s, **längste Runde unter der 60-Sekunden-Grenze**), Band danach weg, Veröffentlichen, **8 von 8 Bildadressen liefern 200**, Probe C (falscher Schlüssel) lehnt ab und ändert nichts. Die Test-Sicherung hat nur 4 Fotos: die Rundenlänge bei vielen und großen Fotos zeigt erst Probe B auf all-inkl.

## Was vor dem Livegang noch gilt

Unverändert aus Bericht `35`: Probe B auf all-inkl (Zeitmessung der Runden bei echten Fotos), Sicherung mit echten Daten einspielen. **Neu aus diesem Bericht:** B1 vorher beheben, damit ein einzelnes zu großes Foto nicht den ganzen Lauf blockiert; oder bei Probe B beobachten, ob ein Foto an die Grenze kommt.
