# Entwurf: Design der öffentlichen Seite wählbar

Stand 18.11.2026, Wunsch von Lutz (Variante 1). Risiko insgesamt: **niedrig**. Es ändern sich nur Aussehen und eine Einstellung. Keine Datenbank-Migration, keine Rechte, keine Bilder, keine Adressen.

## Was gebaut wird

Unter **Einstellungen** gibt es das Feld „Design der öffentlichen Seite“ mit den vier Designs **Warm, Klar, Studio, Blau**. Beim Veröffentlichen steht das gewählte Design fest im erzeugten HTML. Besucher sehen immer dieses eine Design und haben keinen Design-Umschalter.

| Nr. | Entscheidung | Empfehlung |
|---|---|---|
| D1 | **Wo gespeichert:** in der vorhandenen Tabelle `site_settings` unter dem Schlüssel `public_design` (Werte `warm`, `klar`, `studio`, `blau`). Keine Migration nötig. Fehlt der Wert oder ist er unbekannt, gilt `warm`. | So umsetzen. |
| D2 | **Wie es ins HTML kommt:** das Layout setzt `<html lang="de" data-design="…">`. Es gibt weiterhin nur ein gemeinsames `site.css`; darin stehen die Farbsätze und Formen aller vier Designs, gewählt über `data-design`. Das Design hängt also an der **Fassung**, nicht an der Datei. | So umsetzen. Grund: Zurückrollen auf eine ältere Fassung bringt automatisch auch deren Design zurück. |
| D3 | **Standard bleibt der jetzige Zustand.** „Warm“ im öffentlichen Theme ist genau das heutige br-foto-Aussehen (Papierfarben, Terrakotta, runde Rahmen im Apple-Stil, Serifenüberschriften, Schatten). Fehlt `data-design` (alte Fassungen), gilt dasselbe. Ein Test prüft, dass die Warm-Werte unverändert sind. | So umsetzen. |
| D4 | **Hell und dunkel:** je Design ein heller und ein dunkler Farbsatz, gewählt nach `prefers-color-scheme` des Besuchers. Der vorhandene Knopf Sonne/Mond (Hell/Dunkel) bleibt wie er ist, weil er kein Design-Umschalter ist und nur hell/dunkel merkt (localStorage, kein Cookie). | Bleibt. Soll auch er weg, ist das eine kleine Folgeänderung, bitte sagen. |
| D5 | **Formen je Design:** Warm 16/28/10 px und Knöpfe als Pille (wie heute), Klar 14/28/10 px, Studio 18/30/12 px, Blau 6/10/4 px und kleine Knopfradien. Überschriften: Warm und Blau in Instrument Serif mit kursivem Akzentwort, Klar und Studio in Inter, halbfett, Akzentwort aufrecht (wie im Admin). Das Logo-Wortzeichen „luro“ in der Fußzeile bleibt immer in Instrument Serif. | So umsetzen. |
| D6 | **Farben:** dieselben Werte wie im Admin (`app.css`), damit die Familie zusammenpasst. Ausnahmen nur beim Schatten (Warm behält seinen heutigen Schatten, Blau bekommt einen leichten). `tools/kontrast.py` prüft künftig beide Dateien (Admin und öffentlich) auf WCAG AA, ein Test ruft es auf. Knöpfe nutzen `--btn-bg`/`--btn-fg` (bei Studio blau). | So umsetzen. |
| D7 | **Vorschau:** `/admin/vorschau` erzeugt die Seiten wie das Veröffentlichen, mit dem **gespeicherten** Design. Wer das Design wechselt und „Speichern“ drückt, sieht es sofort in der Vorschau, ohne dass etwas live geht. | So umsetzen. |
| D8 | **Bereits veröffentlichte Seiten:** ändern sich erst beim nächsten Veröffentlichen. Da `data-design` im HTML steht, zeigt der Probelauf nach dem Wechsel alle Seiten als „geändert“; Veröffentlichen erzeugt sie neu. Bis dahin sieht die Welt weiter das alte Design. Das Speichern der Einstellung markiert die Seite als „ungeveröffentlichte Änderungen“ (wie bisher bei Einstellungen). | So umsetzen. Kein Zwang zum sofortigen Veröffentlichen. |
| D9 | **Gleiches `site.css` für alle Fassungen:** Es wird als eine Datei ausgeliefert (mit Version `?v=` nach Änderungszeit). Eine neue Datei bringt die vier Designs auch für alte Fassungen mit (sie kennen nur `warm`). | Unkritisch. |

## Risiken

- **Kontrast:** Neue Farbsätze im öffentlichen Theme. Abgesichert durch `tools/kontrast.py` (4,5:1 Fließtext, 3:1 Akzent nur für große Schrift) und den Test.
- **Warm darf sich nicht ändern:** Abgesichert durch den Test auf die alten Werte und Ansicht im Browser vor und nach der Änderung.
- **Titelbild-Text** steht immer weiß auf dunklem Verlauf und hängt nicht vom Design ab.

## Offene Punkte

Keine, außer D4 (Hell/Dunkel-Knopf bleibt, solange Lutz nichts anderes sagt).

## Nachtrag: zwei weitere Designs, Rot und Grün (18.11.2026)

Wunsch von Lutz. Es gibt jetzt sechs Designs: Warm, Klar, Studio, Blau, **Rot** und **Grün** (Werte `rot` und `gruen`, ohne Umlaut). Sie gelten an beiden Stellen: im Admin-Umschalter (`app.css`, `design.js`) und als Design der öffentlichen Seite (`site.css`, `Settings::DESIGNS`). Risiko: niedrig, es kommen nur Farbsätze und Formen dazu; Speicherformat und Standard bleiben, gespeicherte Werte der alten vier Designs gelten weiter.

| Design | Anmutung | Formen |
|---|---|---|
| Rot | Warmes Rot (`#c8102e`) auf Rosé, im Dunkel Schwarzrot mit hellem Rot | rund, Überschriften in Inter, Akzentwort aufrecht (wie Klar) |
| Grün | Gedecktes Grün (`#2f7d4f`) auf hellem Salbei, im Dunkel Waldgrün | mittlere Radien (8/14 px), Serifenüberschriften mit kursivem Akzentwort |

Kontrast: alle Paare nach WCAG AA mit `tools/kontrast.py` (Admin und Seite), Ergebnis 0 Fehler.
