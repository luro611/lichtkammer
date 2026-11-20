# 48 Prüfung Teil E „Dateien in Blöcken“ vor dem Hochladen

Sicherheitsprüfung am 20.11.2026 (Commits `f3e8d31` bis `09fbb7d`, Block 1 Server-Teil und Block 2 Editor), gegen Entwurf 44 und die Berichte 45 bis 47. Gelesen: der Diff von Blocks, Renderer, Builder, Files, Settings, MedienPages, SitePages, Vorlagen und die JS-Änderungen (gezielt auf Ausgabe, Adressen, CSRF). Suite lokal: 1 790 Prüfungen, 0 Fehler. Nichts hochgeladen, nichts gepusht, kein Server angefasst. Nicht gelaufen: echter Apache, Firefox/Safari.

## Urteil

**Hochladen auf den Testserver: ja.** Kein Befund mit Risiko mittel oder höher. Ein Härtungs-Fund (niedrig) ist behoben. Bedingungen unten.

## Schwerpunkte

1. **Link auf Privates, Gelöschtes, Fremdes: nicht möglich.** Aus der Eingabe geht nur eine Zahl (`Blocks::fileId`: ganze Zahl 1 bis unter 10^9 oder reine Ziffernfolge unter 10 Stellen; Float, Text, Array, Leerzeichen, Minus, `0x`, `1e3` ergeben 0). Die Adresse entsteht nur in `Renderer::fileState` über `Files::publicRelOrNull` aus `public = 1`, `deleted_at IS NULL` und den streng mit `D` geprüften Mustern (`dateien/<8 hex>-<slug>.<ext>`, `dokumente/<name>.pdf`, keine Schrägstriche, kein `..` als Pfadteil). Zusätzlich `is_file` der Kopie. Ausgabe nur über `Renderer::h`; Vorschau-Platzhalter ohne Link. `Blocks::link` nimmt `/media/` vom Vorschau-Präfix aus, das betrifft nur Adressen, die schon `/media/` beginnen (kein Sicherheitsproblem).
2. **`Files::repairPublicCopies`.** Schreibt nur nach `media/dateien/` (mit `prepareCopyDir` und `.htaccess`) und `media/dokumente/`; Ziel nur aus dem Muster, nie aus freiem Text. Temporärdatei plus `rename`; ist das Ziel inzwischen da, wird die Temporärdatei verworfen (parallele Läufe harmlos). Ändert nie Zeilen. SVG nur bei bestandenem Schutzkopf-Test, auch nach Endung. Auslieferung: `media/.htaccess` (kein PHP, `nosniff`, Cross-Origin-Policy) gilt auch für `dokumente/`, die `.htaccess` in `dateien/` setzt feste Typen, Inline-PDF und für SVG `sandbox`-CSP. **Fund F1 (niedrig, behoben):** Die Reparatur prüfte nicht, ob die Endung der Kopie zur Endung des privaten Originals passt. Eine handveränderte Zeile (`public_name` oder `legacy_public_path` mit anderer Endung) hätte eine Datei unter falscher Endung öffentlich gemacht. Jetzt: Endung des Originals muss zur Kopie passen, sonst „bad_name“, nichts geschrieben (`Files.php`, Test am Ende von `tests/dateikopien.php`).
3. **`Builder::publish`.** Reihenfolge stimmt: Sperre, Reparatur (unter der Sperre), Bau, Manifest, frische Prüfung `recheckFiles` **vor** `rename`, Abbruch löscht den Bauordner, die aktive Fassung bleibt. Bleibt nach einem Abbruch an anderer Stelle ein `building-*`, räumt der nächste Lauf es unter der Sperre auf. Rollback: nur Hinweis, schaltet nichts ab.
4. **Editor und Bestätigungsseiten.** Alle neuen Schreibwege laufen über den vorhandenen POST-Wrapper (CSRF, Rolle); die Bestätigungsseite ändert nichts selbst, schickt `_csrf` und `bestaetigt` zurück, Felder maskiert (`$e`), Listen mit `[]`. Die neuen GET-Routen (`/admin/medien/detail`, `/admin/dateien/auswahl`) sind nur lesend, `no-store`, ID nur als Ziffern, `art` aus fester Liste, `q` in `LIKE` mit ESCAPE und Parameter (kein SQL-Einbau). JS: kein `innerHTML` bei Namen aus der Datenbank; `href`/`src` nur aus serverseitig gebauten Pfaden (`/admin/medien/datei/<Zahl>`, `/media/…`). Editor-JSON mit `JSON_HEX_*`. Live-Vorschau mit Download-Block nutzt dieselbe `blockFile`-Logik (Adresse nur für öffentliche Dateien, sonst Platzhalter).
5. **Datenformat.** `file` steht nur im Block, wenn gesetzt, hinter den bisherigen Schlüsseln; `v:1` unverändert; Manifest bekommt nur zusätzliche Schlüssel. Alte Fassungen ohne `file_urls` ohne Hinweis.
6. **Logo aus Medien.** Pfad nur über `Files::absolute` (Muster), Endung svg/png/webp aus dem gespeicherten Pfad, Größe geprüft (zusätzlich beim Lesen), Einbettung als `data:`-URL in `<img>`/Favicon (Skripte laufen dort nicht; SVG wurde beim Hochladen durch `SvgCheck::sanitize` gereinigt).

## Hinweise (niedrig, kein Handlungsbedarf vor dem Hochladen)

- H1: `prepareCopyDir` schreibt die `.htaccess` nicht atomar. Zwischen Anlegen und Fertigschreiben könnte eine gleichzeitige Anfrage kurz eine leere Datei sehen (ohne Schutzköpfe). Nur beim ersten Anlegen oder bei geänderter Vorgabe, Muster stammt aus `setPublic` (schon vorher). Bei Gelegenheit mit Temporärdatei und `rename` schreiben.
- H2: Reparatur läuft auch in GET-Anfragen (Medien, Veröffentlichen). Idempotent und begrenzt (100/200 Dateien), nur wissen.
- H3: Das Detailfeld nennt den Benutzernamen des Hochladenden auch der Rolle Redaktion (N8 aus 45), Lutz entscheidet.
- H4: Logos aus Medien tragen eventuell Metadaten (PNG/WebP) in jede Seite (H4 aus 47).
- H5: Ältere importierte PDFs (`legacy_public_path`) bleiben auch nach Papierkorb/Löschen unter `/media/dokumente/` erreichbar (`removeCopy` lässt sie aus, bestehendes Verhalten, bewusst für alte Adressen). Seitenlinks darauf entstehen nicht, `blockFile` prüft `deleted_at`.

## Bedingungen vor dem Livegang (nicht fürs Testserver-Hochladen)

1. Auf dem Testserver mit echtem Apache prüfen: ein PDF, ein PNG und ein SVG als Download-Block veröffentlichen; Antwortköpfe (`curl -I`) enthalten `X-Content-Type-Options: nosniff`, richtigen Typ, bei SVG die CSP mit `sandbox`; Ordner `media/dateien/.htaccess` ist angelegt.
2. Eine Datei privat stellen, dann Veröffentlichen versuchen: Meldung statt Link, kein Abbruch mit halber Fassung.
3. Browser-Test Editor (Ziehen, Dateidialog, Detailfeld) und Firefox/Safari durch Lutz.
