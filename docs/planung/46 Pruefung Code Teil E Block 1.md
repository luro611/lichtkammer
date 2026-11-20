# 46 Prüfung Code Teil E, Block 1 (Dateien in Blöcken, Stufen 1a, 1b, 2)

Prüfung durch Opus am 20.11.2026. Geprüft sind die vier Commits `f3e8d31`, `a343518`, `056ea2c`, `b66690f` gegen Entwurf 44 und Bericht 45. Gelesen: der ganze Diff (Blocks, Renderer, Builder, Files, Usage, MedienPages, Vorlagen, CSS, Tests). Praktisch geprüft: die vorhandene Suite (1 716 Prüfungen vorher, jetzt 1 720, alle grün), die neuen Seiten im Browser (Wegwerf-Instanz in Docker, HTML aus der echten App, Designs Warm, Klar, Studio, Blau, Rot, Grün, hell und dunkel, 375 px). **Nichts hochgeladen, nichts gepusht, kein Server angefasst.**

## Gesamturteil

**Block 1 ist gut gebaut und kann nach der Fable-Prüfung auf den Testserver.** Das Datenformat ist sauber, es entsteht kein Weg zu einem Link auf eine private, gelöschte oder fremde Datei. Einen Fehler mit Risiko **mittel bis hoch** gab es in der Bestätigungsseite: „Trotzdem ausführen“ hätte bei Ordnern und Dateien **nichts getan**. Er ist behoben und mit einem Test abgesichert, der die Regeln eines echten Browsers nachbildet. Dazu zwei kleine Lücken in „Verwendet in“ und eine Schönheitskorrektur, ebenfalls behoben.

## Befunde

| Nr. | Risiko | Befund | Stand |
|---|---|---|---|
| B1 | mittel bis hoch | Bestätigungsseite schickt Listenfelder (`ordner_id`, `datei`, `foto`) ohne `[]` zurück. PHP behält dann nur den letzten Wert als Text, `Request::inputList` liefert eine leere Liste. „Trotzdem ausführen“ löscht also keinen Ordner und keine Datei (Meldung „nichts ausgewählt“). Die alten Tests haben den Fehler sogar festgehalten (`name="ordner_id" value=…`), weil sie das Formular nie wie ein Browser zurückschicken. | **behoben** (`medien_confirm.php`: Name mit `[]` bei Listen), Test schickt die Felder der Seite nach PHP-Regeln zurück (Papierkorb und Endgültig löschen) |
| B2 | mittel | „Verwendet in“ konnte „nicht verwendet“ melden, obwohl die Datei gebraucht wird: (a) Adresse am Satzende mit Punkt („…x.pdf.“), (b) Ziel einer Weiterleitung alter WordPress-Adressen (`redirects.to_path`, der Importer legt solche an), (c) Galerietexte mit Link, (d) JSON mit maskierten Schrägstrichen. Folge: keine Warnung, Datei gelöscht, Weiterleitung führt ins Leere. | **behoben** (`Usage.php`: Weiterleitungen und Galerietexte als weitere Fundstellen, Punkt am Ende, `\/`), 2 neue Tests |
| B3 | niedrig | Reparatur der Kopien erkannte SVG nur an `kind = svg`. Eine handveränderte Zeile mit anderer Art und Endung `.svg` hätte den Schutzkopf-Test umgangen. | **behoben** (auch an der Endung des Ziels) |
| B4 | niedrig | CSS: `.bfile{margin-block:0}` hob den Blockabstand auf, zwei Download-Blöcke hintereinander klebten aneinander. | **behoben** (Regel entfernt, Abstand wie bei den anderen Blöcken, im Browser geprüft) |
| H1 | Hinweis | Die Reparatur läuft auch in der GET-Seite „Veröffentlichen“ und beim Öffnen von Medien (bis 100 bzw. 200 Dateien, SVG-Selbsttest bis 5 s nur wenn eine SVG fehlt). Gewollt (N5), nur wissen. | offen, kein Handlungsbedarf |
| H2 | Hinweis | Der Probelauf nennt Seite, Block und Dateiname, aber noch keinen Link „Seite öffnen / In Medien ansehen“ (Anregung aus Bericht 45, Frage 5). Reine Bequemlichkeit. | offen, Lutz entscheidet |
| H3 | Hinweis | Der Editor kennt `download` noch nicht. Eine Seite mit so einem Block (nur über JSON möglich) würde im alten Editor nicht sauber dargestellt. Bis Block 2 gibt es keine solchen Seiten, nicht auf dem Testserver anlegen. | Block 2 |
| H4 | Hinweis | „Verwendet in“ und die Bestätigungsseite kennen Fotos, Logos und Titelbilder noch nicht (Stufe 5 bzw. späterer Teil, so geplant). Beim Löschen von Fotos gibt es noch keine Rückfrage. | offen, geplant |

## Schwerpunkte einzeln

1. **Nie ein Link auf Privates, Gelöschtes, Fremdes: erfüllt.** Aus der Eingabe kommt nur die Nummer (`fileId`: ganze Zahl über 0, Text nur aus Ziffern unter 10 Stellen, sonst „keine Datei“). Die Adresse entsteht nur in `publicRelOrNull` aus `public = 1`, `deleted_at IS NULL` und Namensmuster mit Modifikator `D` (`dateien/<8 hex>-<slug>.<ext>` oder `dokumente/<name>.pdf`); der Bau verlangt zusätzlich `is_file`. Ausgabe nur über `Renderer::h`, kein `javascript:`/`data:`/`//` möglich, weil keine Eingabe in den Link fließt. Die Adresse läuft nicht durch `link()`/`url()`. Vorschau ohne Link (Platzhalter), im Bau kein Block ohne Fehler. Im Browser geprüft: Platzhalter ohne Link.
2. **`repairPublicCopies`: erfüllt (B1, B2 aus Bericht 45).** Ändert nur Dateien, nie Zeilen (Test: Zeile vor und nach gleich; fehlendes Original: nur gezählt). Muster wie `removeCopy`, mit `D`. SVG nur bei bestätigtem Schutzkopf (jetzt auch nach Endung). Schreibt nur nach `media/dateien/` (mit `prepareCopyDir` samt `.htaccess`) und `media/dokumente/`; ungültige Namen und Altpfade werden nie beschrieben (Test). Temporärdatei plus `rename`; ist das Ziel inzwischen da, wird die Temporärdatei verworfen, gleichzeitige Läufe stören sich nicht. Wiederholbar (zweiter Lauf tut nichts), Limit je Lauf, `onlyIds`.
3. **Bau: erfüllt (B3).** Prüfung mit frischer Abfrage (`recheckFiles`, nicht der Zwischenspeicher) nach dem Schreiben des Manifests und **vor** dem Umbenennen; bei Abbruch wird der Bauordner entfernt, keine wählbare Fassung, aktive Fassung unverändert (Test mit Hook `beforeSwitch`). Rennen „privat gestellt während des Veröffentlichens“: Rest von Millisekunden, harmlos (so im Entwurf beschrieben). Rollback: Zurücksetzen geht immer, Hinweis aus `file_urls` (Adresse der Fassung gegen die Platte), Fassungen ohne `file_urls` ohne Hinweis.
4. **Alte Blöcke byte-gleich: erfüllt.** `file` steht nur im Block, wenn gesetzt, und hinter den bisherigen Schlüsseln; Format `v:1` unverändert; Test auf allen gespeicherten Seiten (decode, encode) und die Import-Tests sind grün. Manifest bekommt nur zusätzliche Schlüssel.
5. **`Usage`: Vollständigkeit.** Seiten, Startseite, Spalten (mit Blocknummer), Altverweise per Regex, ein Durchlauf je Anfrage (nicht je Datei). Zusätzlich jetzt Weiterleitungen und Galerietexte (B2). Nicht enthalten (so geplant): Logos (Stufe 5), Fotos.
6. **Bestätigungsseiten.** Rolle und CSRF kommen vom gemeinsamen Wrapper (Test: ohne CSRF 403). `dirtyHandled` gesetzt (B5 aus Bericht 45), Zähler unverändert (Test). Vor der Bestätigung ändert sich nichts (Tests). Menge aus dem Unterbaum (N1) und aus `trash_root_id`. Ausgabe maskiert (Test mit `<i>`). Doppelklick: zweiter Aufruf trifft schon Gelöschtes und ist wirkungslos. **B1 oben.**
7. **Sicherungsformat und Wiederherstellung unverändert:** `Backup/*` und `Restore` sind im Diff nicht berührt; die Rundlauf-Tests sind grün. Meldung nach dem Wiederherstellen einer benutzten Datei (`zurueck_benutzt`) getestet.
8. **Tests aussagekräftig:** überwiegend ja; die Lücke war B1 (Formular nie wie ein Browser zurückgeschickt). Neu: Rückschicken der Seitenfelder nach PHP-Regeln, Satzende-Adresse, Weiterleitung.

## Browser (Wegwerf-Instanz)

Download-Block und Knopf mit Datei in allen sechs Designs (hell und dunkel stichprobenartig Warm, Studio, Rot, Blau), 375 px: Zeile bricht sauber um, lange Beschriftung umbricht, Fokus und Kontrast sichtbar, Spalte ok. Bestätigungsseite und Medienliste („· in 1 Seite“) ok, Handy-Breite ok. Die Meldung „In app/.env steht noch LK_SETUP_TOKEN“ im Bild ist die Testumgebung, kein Fehler. **Nicht geprüft:** Firefox, Safari, echte Maus (kein Editor in diesem Block), Apache mit echter `.htaccess` (nur Tests und PHP).

## Auflagen aus Bericht 45

| Auflage | Stand |
|---|---|
| B1 Reparatur ändert nie Zeilen | erfüllt, Test |
| B2 Muster mit `D`, SVG-Selbsttest, `prepareCopyDir`, atomar, Zielprüfung | erfüllt (SVG jetzt auch nach Endung) |
| B3 Prüfung vor dem Umbenennen, frische Abfrage, Aufräumen | erfüllt, Test |
| B4 `fileRefs` mit Blocknummer | erfüllt, Test (auch Spalten) |
| B5 `dirtyHandled` | erfüllt, Test |
| N1 Menge aus dem Unterbaum | erfüllt, Test |
| N2 ein Regex je Seite | erfüllt |
| N3 bis N4, N7, N8 | Block 2 und 3 (nicht Teil von Block 1); Stufe 6 entfällt |
| N5 Reparatur auch vor Veröffentlichen | erfüllt (Bau und Seite „Veröffentlichen“) |
| N6 Entwürfe nur in der Vorschau gewarnt | so umgesetzt, Test „Entwurf sperrt nicht“ |

## Wiederholbarkeit

Reparatur und Bau sind wiederholbar (Tests). Bestätigungsaktionen sind nach dem ersten Erfolg wirkungslos. `Usage` und Spalte sind reine Leseaufrufe.

## Was soll Fable besonders prüfen

1. `Blocks::fileId`/`norm` und `Renderer::fileState`/`blockFile`: alle Randfälle der Eingabe, Adresse nur aus Datenbank und Muster.
2. `Files::repairPublicCopies`: Schreiben nach `media/` aus Datenbankwerten (Muster, Symlinks, Rechte, gleichzeitige Läufe), SVG-Weg, `media/dokumente/` ohne eigene `.htaccess`.
3. `Builder::publish`: Reihenfolge Prüfung, Umbenennen, Umschalten; Abbruchpfad; Reparatur unter der Sperre.
4. Bestätigungsseiten: Ausgabe, CSRF, Rolle, Wiederholung der Felder (B1 nur mit Browser-Regeln getestet, nicht mit echtem Browser).
5. `Usage`: kein Fall „fälschlich nicht verwendet“ (neue Fundstellen aus B2 gegenlesen).
6. Echter Apache: Auslieferung der Kopien (`nosniff`, feste Typen, SVG-Schutzkopf) nach Reparatur.
