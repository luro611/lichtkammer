# Prüfung: Code Medienverwaltung (Diff `110413b..9766bc5`)

Stand: 18.11.2026. Geprüft von Fable/Opus-Prüfsitzung (Aufwand „max“) am Diff der Commits `dc9c5bf`, `119b951`, `9766bc5` (Block A bis E, Teile von D und F). Grundlage: Entwurf `16`, Prüfung des Entwurfs `17`. Gelesen wurden alle neuen Klassen (`Names`, `SvgCheck`, `PdfCheck`, `Files`, `Folders`, `Branch`, `Browser`, `Uploads`, `Library`-Änderung, `Response`, `Request`), `MedienPages`, die Vorlagen, `medien.js`, Migration 10, `.htaccess`-Regeln und die Tests. Zusätzlich lief ein eigener Angriffskorpus (über 60 SVG-Fälle, 18 PDF-Fälle, Namen) gegen den echten Code in Docker. Ausgangslage: 1 096 Prüfungen grün.

## Gesamturteil

**Tragfähig, mit zwei echten Lücken, die jetzt behoben sind.** Der Sicherheitskern hält: Namen werden nie zu Pfaden (Ablage unter Zufallsnamen, Endung nur aus dem erkannten Typ, `absolute()` nur nach festem Muster), der Typ kommt nur aus dem Inhalt, die SVG-Prüfung (Positivliste nach Namensraum, bereinigt über das DOM, Rundlauf, nur UTF-8, DOCTYPE nur das W3C-Standard-DOCTYPE) hat im Angriffskorpus nichts durchgelassen, alle ändernden Anfragen laufen über die CSRF-Prüfung, „Bestand übernehmen“ ist Administratoren vorbehalten, das Protokoll enthält keine Namen. **Alle vier Namensstellen** (`Library::cleanName`, Ordner, Dateien, öffentlicher Slug samt Download-Name) benutzen die gemeinsamen Regeln. M3 (Cache-Kopf `Header set`, `AddType`, Kopie über temporäre Datei und `rename`) ist umgesetzt.

Die beiden echten Lücken lagen im Papierkorb von Zweigen: öffentliche Dateien blieben abrufbar (M1 unten), und ein Rennen konnte das endgültige Löschen dauerhaft blockieren (M2). Beides ist mit Tests behoben. Danach 1 102 Prüfungen grün.

## Befunde nach Risiko

### Mittel (behoben)

**M1. Öffentliche Dateien blieben nach dem Löschen eines Ordners abrufbar.** `Folders::trash` ändert nur Datenbankzeilen; `Files::trash` (einzelne Datei) nimmt die öffentliche Kopie sofort weg, der Zweig-Weg tat das nicht. Eine PDF, die versehentlich öffentlich geschaltet und dann mit ihrem Ordner gelöscht wurde, blieb unter `/media/dateien/…` bis zum endgültigen Löschen (30 Tage) abrufbar. Das widerspricht Entwurf 5.4 („beim Löschen wird die Kopie sofort gelöscht“).
Fundstelle: `app/src/Media/Folders.php` `trash()`, `app/src/Admin/MedienPages.php` `medienTrash()`.
Behoben: neue `Files::unpublishTrashed()` (entfernt Kopien aller Dateien im Papierkorb, setzt `public = 0`; übernommene Altdateien bleiben wie bisher bewusst erreichbar), aufgerufen nach jedem Löschen und beim Aufräumen. Wiederherstellen macht die Datei nicht wieder öffentlich. Test: „Prüfung 18: Ordner mit öffentlicher Datei …“.

**M2. Ein Rennen konnte das endgültige Löschen und damit jeden Aufruf der Medienseite dauerhaft stören.** `create()` und `ensurePath()` prüften „Ordner benutzbar“ vor der Transaktion; ein Upload prüft den Ordner beim Beenden, speichert aber erst danach. Wer in dem Moment einen Ordner löscht (zweite Person), konnte lebenden Inhalt unter einem Ordner im Papierkorb hinterlassen. `purgeRows` warf dann „Ordner nicht leer“, und weil `purgeExpired` bei jedem Aufruf von `/admin/medien` läuft, hätte die Medienseite jedes Mal einen Fehler gezeigt, bis der Rest von Hand gefunden wäre.
Fundstelle: `Folders::create`, `ensurePath`, `purgeRows`.
Behoben: Prüfung innerhalb der Transaktion; `purgeRows` wirft nicht mehr, sondern setzt lebende Fotos, Dateien und Unterordner in die Wurzel (nie löschen, Name mit „(gerettet 2)“ bei Gleichheit). Tests dazu.

### Niedrig (behoben)

**N1. Gelöschte Fotos eines großen Zweigs blieben länger über ihre Bildadressen abrufbar.** `finishTrash` entfernte je Aufruf nur 100 Fotos (Stapel), und nichts rief es wiederholt auf. Behoben: `finishTrashWithin()` läuft in Stapeln bis zum Ende oder bis zur Zeitgrenze (15 s beim Löschen, 3 s beim Aufräumen). Test mit mehr als einem Stapel.

**N2. Zeichen, die wie Leere oder Umkehr der Leserichtung wirken, blieben in Namen:** U+061C (arabische Textrichtungsmarke), U+180E, U+034F, U+115F/U+1160, U+3164, U+FFA0, U+2065. Rein optisch, nie ein Pfad. Behoben in `Names::INVISIBLE`; ein Name nur daraus wird zum Ersatznamen.

**N3. Neuer Ordner mit „/“ im Namen:** Umbenennen verbot den Schrägstrich, Anlegen nicht. Behoben (`create` weist ab).

**N4. Warnung „Es gibt schon eine Galerie mit diesem Titel“ kam im Browser nie an** (der Server schickte sie, `medien.js` zeigte sie nicht). Jetzt im Abschlussbericht unter „Übersprungen oder geändert“.

### Niedrig (offen, bewusst nicht geändert)

- **O1.** `Range`-Kopf mit ungültiger Schreibweise oder mehreren Bereichen gibt 416, RFC 9110 erlaubt (und empfiehlt) hier eine volle 200-Antwort. Kein Sicherheitsproblem, Betrachter schicken einfache Bereiche.
- **O2.** `Settings::setLogo` speichert Logos jetzt in bereinigter Fassung und nimmt SVG mit W3C-DOCTYPE an (vorher abgelehnt); das ist eine Lockerung nur für harmlose Standardköpfe von Editoren, die Prüfung selbst ist strenger geworden.
- **O3.** Es gibt keine Speicherplatzprüfung vor dem Ordner-Upload (`disk_free_space` ist auf all-inkl wertlos, Prüfung 17 M5). Schreibfehler werden je Datei gemeldet. Ein `LK_QUOTA_MB` fehlt.
- **O4.** Ein Plan darf bis zu 5 000 Galerien (eine je Ordner) anlegen und 20 Pläne je Stunde. Nur für Angemeldete, nur Entwürfe; erwägenswert wäre eine Obergrenze je Plan.
- **O5.** `finfo` erkennt manche PDF mit Vorspann; `PdfCheck` verlangt `%PDF-` ganz vorn und lehnt sonst ab (sicher, aber ein PDF mit Textvorspann wird abgelehnt).
- **O6.** Bilder als Dateien behalten GPS und alle Metadaten; das steht im Dialog, ist so entschieden.

## Prüfung der Auflagen aus Entwurf 16 und Prüfung 17

| Auflage | Stand |
|---|---|
| Vier Stellen mit denselben Namensregeln (Fotos `cleanName`, Ordner, Dateien, öffentlicher Slug und Download-Name) | umgesetzt, geprüft; N2 und N3 ergänzt |
| H1 Papierkorb: Teilindex, `trash_root_id`, Zweig nur ganz, Zusammenführen, Stapel, Wiederherstellen ohne Bildverarbeitung | umgesetzt; M1, M2, N1 oben waren Lücken daneben |
| H2 Fingerabdruck beim Fortsetzen und „schon vorhanden“ | umgesetzt (Browser und Server) |
| H3 SVG-Prüfung (UTF-8, DOCTYPE nach dem Lesen, Namensraum, Rundlauf, Grenzen, kein `image`/`data:`) | umgesetzt; im Korpus nichts durchgekommen |
| H4 gemeinsame `Names::clean` | umgesetzt |
| M1 Migration 10 | additiv, Teilindex, Wurzel `0`; unauffällig |
| M2 Bestand übernehmen: gleiche Prüfungen, `legacy_public_path`, Schalter gesperrt | umgesetzt, nur Administrator |
| **M3 Schutzkopf für PDF** | **Abweichung, siehe unten (vertretbar)** |
| M3 Cache-Kopf `Header set`, `AddType`, Kopie über `rename` | umgesetzt |
| M4 Datei-Antwort mit Range 206/416, CSP der Route ersetzt den allgemeinen | umgesetzt, geprüft (O1 nur Schönheit) |
| M5 JSON-Körper mit Grenze, Mengen in der Transaktion, Rate-Limit, Plan-Aufräumen | umgesetzt; Speicherprüfung fehlt (O3) |
| M6 `setSiteDirty` für `/admin/medien`, Audit ohne Namen, Zielzustand | umgesetzt |
| N7 Ablageordner mit Sperre (`prepareDenyDir`) | umgesetzt |

## Bewertung der bekannten Abweichungen

1. **PDFs ohne `sandbox`-Schutzkopf: vertretbar.** Chrome zeigt PDFs in einem Dokument mit `Content-Security-Policy: sandbox` nicht an (der eingebaute Betrachter wird blockiert). Ein PDF läuft im Betrachter des Browsers in dessen eigener Abschottung, nicht als Seite von Lichtkammer; JavaScript, Startaktionen und eingebettete Dateien werden vorher am Inhalt abgelehnt (Filter, im Korpus auch bei Namenstarnung und über Blockgrenzen von 64 KB geprüft). Wichtig bleibt: Der Filter sieht komprimierte Objektströme nicht (bekannt, im Code ehrlich benannt). Die übrigen Köpfe (`nosniff`, fester Typ) sind gesetzt. Empfehlung: so lassen, Grenze im Handbuch nennen.
2. **SVG öffentlich nur nach Selbsttest des Kopfes (E13): richtig umgesetzt und schließt sicher** (`false` bei jedem Fehler, Ergebnis 1 Stunde gemerkt, privat bleibt privat). Für Logo und Bild in Seiten bleibt SVG nutzbar. Die Probedatei `_probe.svg` bleibt in `media/dateien/` liegen (harmlos).
3. **„Zu vorhandener Galerie hinzufügen“ fehlt: vertretbar.** Entwurf 3.5 empfiehlt Warnung statt stillem Zusammenführen; die Warnung kommt jetzt wirklich an (N4).

## Tests

Neue Prüfungen in `tests/medien.php` (Abschnitt „Prüfung 18“): öffentliche Datei im gelöschten Ordner, Wiederherstellen bleibt privat, Rennen beim endgültigen Löschen, Anlegen unter Papierkorb und mit Schrägstrich, unsichtbare Zeichen, Varianten in mehreren Stapeln. Ergebnis: **1 102 Prüfungen, 0 Fehler** (vorher 1 096).

## Zweite Prüfrunde

Die Befunde mit mittlerem Risiko (M1, M2) sind klein, lokal und mit Tests belegt. Eine zweite Runde ist nach den Regeln des Projekts (nur bei Befunden mit Risiko mittel oder höher in der ersten Runde) formal fällig, aber **kurz genug für einen Blick auf den Fix-Commit allein** (`Files::unpublishTrashed`, `Folders::purgeRows`, `finishTrashWithin`); der Rest des Diffs muss nicht noch einmal gelesen werden. Vor dem Livegang bleibt der Browser-Test von Lutz (Ziehen vom Finder, Papierkorb) offen.
