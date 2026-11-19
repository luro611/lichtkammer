# Prüfung 30: Code Seitenbau, Teile A bis C (Entwurf 29)

Stand: 19.11.2026. Geprüft von Opus. Gegenstand: Commit `096e0a7` (Bausteinleiste und Einfügepunkte, Rückgängig, Ziehen und „Verschieben nach …“ zwischen Hauptseite und Spalten). Maßstab: Entwurf `29` (mit Abschnitt 7) und Entwurf `12`.

**Ergebnis:** Der Bau ist ordentlich und erfüllt den Entwurf weitgehend. Beim **echten Mausziehen** im Browser sind drei Fehler mit Risiko **mittel** aufgefallen, die die simulierte Prüfung des Baus nicht zeigen konnte; dazu drei kleine. Alle sechs sind behoben und im Browser nachgeprüft. Zurück an den Bau gehen nur Kleinigkeiten (Risiko niedrig). Nichts ist auf dem Server.

## 1. Wie geprüft wurde

- Diff gelesen (`page-editor.js`, `app.css`, `page_edit.php`, `home_edit.php`, `tests/bloecke.php`).
- Tests in Docker: vorher und nachher **1 239 Prüfungen, 0 Fehler**. `tools/kontrast.py`: 0 Fehler (alle sechs Designs, hell und dunkel).
- Browser (eingebauter Browser, Chromium): Testseite mit dem echten Skript und `app.css`, fünf Blöcke einschließlich „Zwei Spalten“. Geprüft per Skript **und mit echtem Mausziehen** (Griff → Einfügepunkt, Baustein → Einfügepunkt, Baustein → leere Spalte, Trennlinie → Spalte). Breite 1024 px und 375 px, dunkel (Warm) und hell (Blau).

## 2. Befunde und was damit passiert ist

| Nr | Risiko | Befund | Stand |
|---|---|---|---|
| B1 | mittel | Verbotene Ablage fiel durch | behoben |
| B2 | mittel | Rückgängig verwarf Getipptes | behoben |
| B3 | mittel | Leere Spaltenfläche war keine Ablage | behoben |
| B4 | niedrig | Handy: Liste „Verschieben nach …“ links abgeschnitten, Löschen-Leiste zu schmal | behoben |
| B5 | niedrig | Knopf in der Löschen-Leiste ohne Rand | behoben |
| B6 | niedrig | Rückgängig sprang an den Seitenanfang | behoben |

**B1 – Verbotene Ablage fiel durch zur Spalten-Karte.** Eine Trennlinie per echter Maus auf eine Spalte gezogen wurde nicht abgelehnt, sondern landete **vor dem Spalten-Block** auf der Hauptseite. Grund: Die Spalte prüfte beim Ablegen nur „erlaubt?“ und ließ das Ereignis sonst weiterlaufen; die umgebende Karte „Zwei Spalten“ (dort ist alles erlaubt) nahm es an. Ob der Browser überhaupt ablegt, hing allein daran, dass `dragover` nicht freigab – darauf ist kein Verlass.
*Behebung:* Verbotene Ziele lehnen jetzt ausdrücklich ab (`dropEffect = 'none'`) und halten das Ablegen-Ereignis an (`stopPropagation`). Nachgeprüft mit echter Maus: Trennlinie auf Spalte → nichts ändert sich, Hinweis erscheint.

**B2 – Rückgängig verwarf, was danach getippt wurde.** Der Stapel speichert den ganzen Zustand vor jedem Strukturschritt. Beispiel: Block löschen, dann in einem anderen Block einen Absatz schreiben, dann „Rückgängig“ → der gelöschte Block kommt zurück, **der neue Absatz ist weg**, ohne Hinweis. Das widerspricht „Tippen zählt nicht als Schritt“ (Entwurf 29, c).
*Behebung:* `undo()` stellt nur noch die **Struktur** zurück (welche Blöcke, wo). Blöcke, die es noch gibt, behalten ihren aktuellen Inhalt (Abgleich über die interne Kennung `_id`, auch in Spalten). Gelöschte Blöcke kommen mit dem Inhalt zum Zeitpunkt des Löschens zurück. Nachgeprüft im Browser.

**B3 – Die sichtbar leere Fläche einer Spalte nahm nichts an.** Die Ablagefläche einer Spalte war nur so hoch wie ihr Inhalt (mindestens 64 px); die Spalte daneben ist oft viel höher. Wer einen Text in die große leere Fläche der rechten Spalte zog, bekam ihn **hinter dem Spalten-Block auf der Hauptseite**, nicht in der Spalte.
*Behebung (CSS):* Die Liste füllt jetzt die ganze Spaltenhöhe (`.bcol` als Flex-Spalte, `.blist` wächst mit). Nachgeprüft mit echter Maus: Text landet in der rechten Spalte.

**B4 – Handy-Breite.** Bei 375 px ragte die Liste „Verschieben nach …“ bis 20 px über den linken Rand (Text abgeschnitten). Die Löschen-Leiste war wegen `left:50%` nur halb so breit wie der Bildschirm, der Satz brach dreimal um.
*Behebung (CSS):* Unter 600 px öffnet die Liste nach rechts und bleibt im Bild; die Leiste nimmt ihre natürliche Breite bis Bildschirm minus 32 px. Kein seitliches Scrollen (gemessen: Seitenbreite = 375 px).

**B5 – Knopf „Rückgängig“ in der Löschen-Leiste ohne Rand.** `.btn` hat `border:0`, die Regel setzte nur `border-color`. Jetzt `border:1px solid var(--bg)`.

**B6 – Rückgängig sprang nach oben.** Nach „Rückgängig“ ging der Fokus auf den Knopf oben in der Werkzeugleiste, der Browser scrollte dorthin; auf langen Seiten war die Stelle weg. Jetzt `focus({ preventScroll: true })`; die Ansage über `say()` bleibt.

## 3. Zurück an den Bau (Risiko niedrig, nicht behoben)

- **N1 – Gewählter Baustein nur an Einfügepunkten.** Ein angetippter Baustein (Tastatur, Handy) lässt sich nur vor einem vorhandenen Block ablegen. Ans **Ende** einer Liste, in eine **leere Spalte** oder auf eine **leere Seite** geht es so nicht; „+ Block hinzufügen“ am Ende beachtet die Wahl nicht. Vorschlag: Ist ein Baustein gewählt, legt „+ Block hinzufügen“ ihn direkt am Ende ab (Beschriftung wie bei den Einfügepunkten).
- **N2 – Fokus nach Ziehen aus der Bausteinleiste.** In Chromium bleibt der Fokus nach dem Ablegen auf der Karte in der Leiste statt im neuen Feld (der Browser setzt ihn nach `drop` zurück). Mit Tastatur/Antippen stimmt der Fokus. Vorschlag: Fokus im `dragend` der Leistenkarte nachziehen.
- **N3 – 20 Einträge je Spalte.** Der Editor lässt mehr als 20 Blöcke in eine Spalte schieben; der Server lehnt dann beim Speichern mit Fehlermeldung ab (nichts geht still verloren). Vorschlag: Ziel „Spalte …“ ab 20 Einträgen nicht anbieten bzw. Ablage mit Hinweis ablehnen.
- **N4 – Hinweis bleibt stehen.** Wird ein gewählter, für Spalten verbotener Baustein an einem Einfügepunkt in einer Spalte abgelegt, bleibt der Ablehnungstext oben stehen, bis das nächste Ziehen endet.
- **N5 – Löschen-Leiste verschwindet unter dem Fokus.** Steht der Fokus auf ihrem Knopf, verschwindet die Leiste trotzdem nach 8 Sekunden, der Fokus fällt auf die Seite. Vorschlag: Frist anhalten, solange die Leiste den Fokus hat oder die Maus darüber ist. Der Knopf „Rückgängig“ oben bleibt immer erreichbar.
- **N6 – Markierung beim Ziehen.** Ohne `dragleave` bleibt die Markierung eines Ziels stehen, bis das nächste Ziel oder das Ende des Ziehens sie löscht. Nur Aussehen.

## 4. Prüfpunkte aus dem Auftrag

1. **Zustand beim Ziehen:** Kein Block ging verloren oder wurde doppelt (Griff → anderer Platz, Hauptseite ↔ Spalte, „Verschieben nach …“ hin und zurück). Index nach dem Entfernen in derselben Liste stimmt (`from < idx` → `idx--`). `SIMPLE` wird an drei Stellen geprüft (Ablageziel, `insertAt`, `moveBlock`) und vom Server (`Blocks::parse`, `$inColumn`) noch einmal. Spalten in Spalten: abgelehnt (Bausteinleiste und Griff). Schwachstelle war B1 und B3, behoben.
2. **Rückgängig:** höchstens 30 Schritte (`shift`), nur im Speicher, kein Schritt beim Tippen, Strg/Cmd+Z nur außerhalb von Eingabefeldern und nicht bei offenem Fotodialog, Löschen-Leiste 8 s mit Knopf, nach Speichern leer (`submit`). Getipptes bleibt jetzt erhalten (B2).
3. **Zieh-Daten:** Nur eigene Kennung `application/x-lichtkammer` **und** ein eigener `dragstart` gelten. Datei aus dem Finder und eine fremde Quelle mit derselben Kennung wurden ignoriert (kein `preventDefault`). Zieh-Inhalte werden nie gelesen (`getData` kommt nicht vor), Ausgabe nur über `textContent`/Attribute, kein `innerHTML`.
4. **Datenformat:** `v:1`, `Blocks.php` nicht geändert; interne Felder (`_id`, `_c`) entfernt `replacer` beim Speichern wie bisher. Alte Seiten und Import bleiben damit unverändert (Tests „Alte Seite“ und Import grün).
5. **Barrierefreiheit:** Jede Zieh-Handlung hat eine Knopf-Entsprechung (Einfügepunkt, Antippen + Einfügepunkt, Pfeile, „Verschieben nach …“), Ansagen über `say()`, Fokus nach Einfügen/Verschieben/Löschen gesetzt. Rest in N1, N2, N5.
6. **Kontrast/Designvariablen:** Nur Variablen, keine festen Farben. `kontrast.py` deckt die verwendeten Paare ab (u. a. `accent-strong` auf `bg`/`surface`/`soft`, `bg` auf `ink` für die Leiste): 0 Fehler.
7. **Verlassen mit ungespeicherten Änderungen:** `beforeunload` unverändert; Rückgängig setzt `dirty`.

## 5. Nicht geprüft

- Echtes Mausziehen in **Safari und Firefox** (nur Chromium im eingebauten Browser).
- Echtes Gerät mit Finger (Touch); geprüft nur die Handy-Breite 375 px.
- Ausgabe eines echten Screenreaders (nur die Texte im Statusfeld).
- Neuer Bildblock mit echtem Fotodialog und Fotoliste; Startseite (`home_edit.php`, Titelbild) mit dem Editor.

Diese Punkte gehören in den Browser-Test von Lutz auf dem Testserver.
