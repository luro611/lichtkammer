# Entwurf: Startseite als Blöcke, Blockstile, Karussell, Menü und Fußzeile

Stand: 16.11.2026. Auslöser: Wünsche von Lutz nach dem WordPress-Import auf `lichtkammer.br-foto.com`. Grundlage: Entwurf 12 (Seiten-Editor mit Blöcken). Keine neue Migration nötig (Begründung unten).

## 1. Was es schon gab

- Seiten haben schon `nav` (`main` = Menü oben, `footer` = Fußzeile, `none`) und `sort_order`; die Liste hatte Pfeile. Das war nur schlecht zu finden (eine gemeinsame Liste, Verlinkung nur im Bearbeiten-Formular, Fußzeile und Menü liefen in einer Reihenfolge).
- Die Startseite kam fest aus `site/home.php` (Titelbild aus den Einstellungen, Galerienkacheln).

## 2. Entscheidungen

| Nr. | Frage | Entscheidung |
|---|---|---|
| S1 | Wo liegt die Startseite? | Als Block-JSON in `site_settings` (Schlüssel `home_blocks`). Keine neue Tabelle, keine Migration: Die Tabelle `site_settings` ist ein Schlüssel-Wert-Speicher. Eigene Adresse im Admin: `/admin/startseite` (mit demselben Editor wie Seiten). Ist `home_blocks` leer, bleibt die bisherige feste Startseite. Beim ersten Öffnen im Editor ist sie als Titelbild-Block plus Galerien-Block vorbefüllt, sieht also genauso aus. |
| S2 | Neue Blocktypen | **Titelbild** (nur Startseite): Foto, Alt-Text, Überschrift darauf. **Karussell**: 2 bis 12 Fotos, Alt-Text je Foto, optional automatisch (nie bei „weniger Bewegung“). **Galerien-Kacheln**: die Galerienkarten der Startseite, mit Titel und Anzahl. **Bild + Text**: Foto neben Text, links oder rechts. |
| S3 | Blockstile | Jeder Typ bekommt ein Feld `style` aus einer festen Liste (Positivliste im Server, unbekannt = normal). Text: normal, groß (Einleitung), zentriert, Fläche. Überschrift: links, zentriert. Zitat: normal, groß, Fläche. Knopf: links, zentriert. Bild + Text: normal, Fläche. Bild: Breite schmal/normal/voll wie bisher. Kein freies CSS, keine Farbwahl: Stile sind Klassen `s-…` mit den Farben der Designsprache. |
| S4 | Karussell-Technik | Reines HTML/CSS (`scroll-snap`) als Grundlage, funktioniert so auch ohne Skript (wischen/scrollen). Dazu ca. 80 Zeilen in `site.js`: Zurück/Weiter-Knöpfe, Punkte, Pause-Knopf bei Automatik, Pfeiltasten, `aria-roledescription`, Ansage „Folie 2 von 5“. Automatik nur, wenn gewählt und nicht `prefers-reduced-motion`; hält an bei Hover, Fokus und Pause. Kein Rahmenwerk, kein Lightbox-Aufruf. |
| S5 | Reihenfolge Menü | Liste der Seiten in drei Gruppen (Menü oben, Fußzeile, nicht verlinkt); Pfeile bewegen innerhalb der Gruppe. Die Zuordnung ist direkt in der Liste umschaltbar. Menü und Fußzeile haben damit getrennte Reihenfolgen (dieselbe `sort_order`, aber nach Gruppe geordnet). „Galerien“ steht im Menü fest an erster Stelle. Ziehen mit der Maus kommt nicht dazu (Pfeile reichen und sind barrierefrei). |
| S6 | Fußzeile | Gibt es schon (`nav = footer`). Neu: in der Liste sichtbar und einstellbar; Impressum und Datenschutz aus den Vorlagen landen dort. |
| S7 | Impressum/Datenschutz doppelt | Löschen ohne Rückfrage entfällt (ich sehe die Daten der Installation nicht). Der Seitenliste fällt neu auf: gleicher Titel oder gleiche Adresse mit Zusatz (`impressum-2`) wird als „Doppelt?“ markiert. Die Vorlagen „Impressum“ und „Datenschutz“ folgen dem Aufbau von lurohome.de, mit Platzhaltern statt echter Adresse. |

## 3. Datenschutz und Sicherheit

- Alles weiter nur über Positivlisten und feste Vorlagen (Entwurf 12). Neue Felder: `style`, `side`, `auto`, `interval` (ursprünglich 3 bis 15 Sekunden; seit Commit `ac101c0` vom 17.11.2026 ein Zahlenfeld 2 bis 30 Sekunden, Standard 6, `Blocks::MIN_INTERVAL`/`MAX_INTERVAL`), `ratio` (nur Listenwerte).
- Hero-Foto und Karussell-Fotos kommen aus der Fotobibliothek, werden bei jedem Speichern gegen vorhandene, nicht gelöschte Fotos geprüft und beim Veröffentlichen wie die anderen Blockfotos geprüft (Alt-Text-Pflicht, Foto fertig).
- Rückwärtsverträglich: ältere gespeicherte Blöcke ohne `style` lesen sich als „normal“.
