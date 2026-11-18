# Prüfung Code: Designs Rot und Grün

Stand: 18.11.2026. Geprüft wurde Commit `09f3e07` (Nachtrag zu Entwurf 25: zwei neue Designs Rot und Grün, Werte `rot` und `gruen`). Maßstab: Entwurf 25 mit Nachtrag, Entwurf 23, Bericht 26.

## Ergebnis in einem Satz

Keine Befunde mit Risiko hoch oder mittel. Die beiden Designs sind sauber nach dem Muster der vier alten gebaut, der Kontrast stimmt, und im Browser verhalten sie sich wie die anderen. Der Stand kann nach Freigabe von Lutz auf den Testserver.

## Was geprüft wurde

| Punkt | Ergebnis |
|---|---|
| Nur erlaubte Werte | `Settings::DESIGNS`, die Liste in `design.js` und der Umschalter in `designtools.php` kennen dieselben sechs Werte. Unbekanntes wird weiter zu Warm, `lurohome` und `rothe` werden weiter zu Warm und Klar. Das Einstellungsformular nimmt nur Schlüssel aus `Settings::DESIGNS` an (Test mit einem bösen Wert ist da). |
| Kontrast WCAG AA | `python3 tools/kontrast.py`: 0 Fehler, hell und dunkel, Admin und öffentliche Seite. Der Test ruft das Werkzeug auf und prüft, dass `site:rot/hell` und `app:gruen/dunkel` wirklich mitgerechnet werden. |
| Dunkel nach Gerät und Mond-Knopf | Jeder neue Farbsatz hat drei Regeln wie bei Blau: hell, `[data-theme="dark"]` und `prefers-color-scheme: dark` mit `:not([data-theme="light"])`. Im Browser nachgesehen (siehe unten). |
| Alte Seiten ohne `data-design` | Alle neuen Regeln hängen an `[data-design="rot"]` oder `[data-design="gruen"]`. Die Regel für das aufrechte Akzentwort ist nur um `rot` erweitert. Für Seiten ohne `data-design` ändert sich nichts. |
| CSP und Inline-Stil | Es kommen nur Zeilen in `app.css` und `site.css` dazu, kein `style=`, kein Skript, keine fremde Adresse. Die CSP bleibt gültig. |
| Tests | `docker run --rm -v "$PWD":/app -w /app lichtkammer-test php tests/run.php`: 1 239 Prüfungen, 0 Fehler. |

## Im Browser angesehen

Lokal mit einer Ansicht aus dem Testgerüst (Einrichtung, Seite „Über“ mit kursivem Akzentwort, Einstellungen gespeichert, Seiten als HTML erzeugt), Breite 1 200 und 375 Pixel.

- **Öffentliche Seite Rot:** hell Rosé mit rotem Akzent, Überschriften in Inter, Akzentwort aufrecht. Gerät auf dunkel: Schwarzrot (`#1c1213`) mit hellem Rot. Sonnen-Knopf schaltet zurück auf hell (`data-theme="light"`).
- **Öffentliche Seite Grün:** hell Salbei, Serifenüberschriften, Akzentwort kursiv. Mond-Knopf schaltet auf Waldgrün (`#101a14`).
- **Admin-Umschalter:** Klick auf Rot und Grün setzt `data-design`, markiert den Knopf mit `aria-pressed="true"` (die anderen `false`) und merkt den Wert. Hell und Dunkel schalten in beiden Designs. Die sechs Knöpfe stehen in der Seitenleiste in drei Zeilen zu je zwei, auf dem Handy oben ebenso, ohne seitliches Scrollen.
- Ohne gemerktes Farbschema folgt der Admin in Rot dem Gerät (dunkel).

## Befunde

### N1 – Designliste steht an drei Stellen (Risiko niedrig)

Die Werte stehen in `Settings::DESIGNS`, in `design.js` und noch einmal fest in `app/templates/partials/designtools.php`. Kommt ein siebtes Design dazu, muss man an alle drei Stellen denken. Die Tests fangen ein Vergessen bei `design.js` und den Knöpfen ab. Man könnte den Umschalter aus `Settings::DESIGNS` füllen; das verbindet aber Admin-Designs und Designs der öffentlichen Seite fest miteinander. Das ist eine Entscheidung, darum nicht geändert.

### N2 – Galerie und Lightbox nicht im Browser angesehen (Risiko niedrig)

Die lokale Ansicht hatte keine veröffentlichte Galerie mit Bildern. Farben und Formen kommen dort aus denselben Variablen (`--r-img`, `--soft`, `--accent`), die Lightbox ist absichtlich in allen Designs dunkel (Bericht 26, H2). Lutz sollte auf dem Testserver in der Vorschau einmal eine Galerie in Rot und Grün ansehen.

### N3 – Kleinigkeiten ohne Wirkung (Risiko niedrig)

In `site.css` stehen bei Rot und Grün Farben und Formen in einer Regel, bei Klar, Studio und Blau in zwei. Das wirkt gleich und wird von `kontrast.py` richtig gelesen. Grün im Admin übernimmt Schrift und Navigation von Warm (Serife, Archivo), Rot die von Klar; das passt zur Beschreibung im Nachtrag.

## Geändert

Nichts am Code. Nur dieser Bericht.

## Freigabe

Aus Sicht der Prüfung kann der Stand nach Freigabe von Lutz auf den Testserver. Hochgeladen und am Server geändert wurde nichts.
