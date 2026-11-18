# Prüfung Code: Design der öffentlichen Seite (Entwurf 25)

Stand 18.11.2026, Prüfung durch Opus. Geprüft: Commit `f3d5943` gegen die Entwürfe `25 Entwurf Oeffentliches Design.md` und `11 Entwurf Oeffentliche Seite.md`.

**Ergebnis:** Der Bau entspricht dem Entwurf. Ein Fehler mit Risiko **mittel** wurde gefunden und direkt behoben (B1). Sonst nur Hinweise mit Risiko niedrig. Nach der Korrektur: 1 229 Prüfungen, 0 Fehler (Docker), `tools/kontrast.py` 0 Fehler.

## Was geprüft wurde

| Punkt | Ergebnis |
|---|---|
| Eingabe nur aus erlaubten Werten | In Ordnung. Das Formular prüft gegen `Settings::DESIGNS`, ein unbekannter Wert wird abgelehnt und es wird nichts gespeichert. `saveDesign()` prüft noch einmal. Beim Lesen bildet `Settings::design()` alles Unbekannte auf `warm` ab, auch aus einer eingespielten Sicherung oder einer veränderten Datenbank. |
| Kein Einschleusen ins HTML/CSS | In Ordnung. Ins HTML kommt nur der geprüfte Wert, zusätzlich maskiert (`data-design="<?= $e(...) ?>"`). Das CSS wird nicht aus Daten erzeugt. Test mit `rosa"><script>` vorhanden. |
| Alte Installationen ohne Einstellung | In Ordnung. Fehlt `public_design`, gilt `warm`. Die Warm-Werte (Farben, Schatten, Radien 16/28/10, Pillen-Knöpfe) sind unverändert, `--btn-bg`/`--btn-fg` entsprechen den bisherigen `--ink`/`--bg`. Test vorhanden. Ausnahme siehe B1. |
| Vorschau und Veröffentlichen gleich | In Ordnung. Beide laufen über denselben Renderer mit `settings->all()['design']`. Test vergleicht Vorschau und erzeugte Seiten für alle vier Designs. |
| Neu erzeugen beim nächsten Veröffentlichen | In Ordnung. Da `data-design` im HTML steht, zeigt der Probelauf nach dem Wechsel alle Seiten als geändert. Zurück auf Warm ergibt wieder keine Änderung. Die Live-Seite bleibt bis zum Veröffentlichen beim alten Design (Test). |
| Keine Besucher-Skripte, kein localStorage für das Design | In Ordnung. Es kam kein Skript dazu. Das vorhandene `site-theme.js` (Hell/Dunkel-Knopf, localStorage) bleibt wie in D4 beschlossen und kennt kein Design. |
| Hell/dunkel nach `prefers-color-scheme` | In Ordnung für alle vier Designs, dazu der Knopf (`data-theme`). Die Reihenfolge der Regeln und ihre Gewichtung stimmen: Dunkel schlägt Hell, `data-theme="light"` schlägt die Geräteeinstellung. |
| Kontrast | In Ordnung. `tools/kontrast.py` prüft jetzt `app.css` und `site.css`, 0 Fehler. Der Test ruft es im Docker-Bild auf (Python ist dort vorhanden, der Test läuft also wirklich). |
| Content-Security-Policy | In Ordnung. Kein Inline-Stil, kein Inline-Skript, keine neuen Quellen. Der vorhandene Test auf Inline-Stil und Ereignisattribute läuft weiter. |
| Sicherung und Wiederherstellung | In Ordnung. Die Einstellung liegt in `site_settings` und reist mit der Datenbank; keine Migration nötig. |
| Schriften und Marke | In Ordnung. Wortmarke `luro` bleibt in Instrument Serif. Klar und Studio setzen Überschriften in Inter mit aufrechtem Akzentwort, Warm und Blau bleiben Serif und kursiv (D5). |

## Befunde

### B1 – Alte Fassungen: Mond-Knopf schaltet die Farben nicht mehr auf dunkel (Risiko mittel, behoben)

Vorher galt `:root[data-theme="dark"]{…dunkle Farben…}` für jede Seite. Im neuen `site.css` hing der dunkle Farbsatz beim Knopf nur noch an `:root[data-design="warm"][data-theme="dark"]`. Seiten **ohne** `data-design` – also alles, was vor diesem Stand veröffentlicht wurde, und jede Fassung, auf die man zurückrollt – bekamen beim Klick auf den Mond nur das Mond-Symbol und das dunkle Logo, die Farben blieben hell. Weil `site.css` für alle Fassungen gemeinsam ist (D9), hätte das die Live-Seite schon nach dem Hochladen getroffen, noch vor dem nächsten Veröffentlichen. Die Geräteeinstellung „dunkel“ war richtig abgedeckt, nur der Knopf nicht.

**Behoben:** Der Warm-Dunkel-Satz gilt jetzt auch für `:root:not([data-design])[data-theme="dark"]`. Der Test in `tests/design.php` prüft diese Regel mit.

### H1 – Fehlerhafte Eingabe verwirft die anderen Felder (Risiko niedrig, nicht geändert)

Schickt jemand ein unbekanntes Design, zeigt die Seite den Hinweis und das Formular mit den gespeicherten Werten; andere Eingaben im selben Absenden gehen verloren. Über die Oberfläche kommt das nicht vor (nur Auswahlknöpfe), daher bewusst so gelassen.

### H2 – Lightbox bleibt immer dunkel (Risiko niedrig, kein Fehler)

Die Lightbox hat feste dunkle Farben und runde Knöpfe in allen Designs, auch bei Blau mit kleinen Radien. Das ist gewollt (Fotos auf Schwarz) und war vorher schon so. Wenn Blau dort eckigere Knöpfe haben soll, ist das eine kleine Folgeänderung.

### H3 – Im Browser ansehen (offen)

Die Prüfung ist rechnerisch (Tests, Kontrast, Regelgewichtung). Vor dem Livegang sollte Lutz auf dem Testserver jedes Design in der Vorschau einmal hell und dunkel ansehen, auch mit dem Mond-Knopf.

## Freigabe

Aus Sicht der Prüfung kann der Stand nach Freigabe von Lutz auf den Testserver. Hochgeladen wurde nichts.
