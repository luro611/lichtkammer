# 43 Prüfung (Fable): Live-Vorschau und Auswahl-Routen

Stand: 20.11.2026. Geprüft: Commit `7c8ca6b` (Route `POST /admin/vorschau-live`, `previewLive`, `previewHeaders`, `page-preview.js`, `Renderer::home`) und die drei lesenden Routen aus `7c945db` (`/admin/fotos/auswahl?n=60`, `/admin/fotos/auswahl-galerien`, `/admin/fotos/auswahl-galerie/{id}`). Nichts hochgeladen, nichts am Server geändert.

## Ergebnis in einem Satz

**Hochladen: ja.** Ich habe keinen Fehler mit Risiko hoch oder mittel gefunden. Drei kleine Dinge (niedrig) sind unten aufgeführt; eines davon ist behoben.

## Wie ich geprüft habe

- Quelltext gelesen: Route, Kopfzeilen, `Blocks::parse` und `Blocks::render`, `Markdown`, Renderer, Router mit Anmeldung/CSRF, die drei Auswahl-Routen, die drei Skripte.
- **Praktisch lokal in Docker** mit Wegwerf-Daten: bösartige Inhalte in jedem Blocktyp, 4 000 zufällige Fehl-Blöcke (falsche Typen, riesige Zahlen, Arrays statt Text), Schreibspuren in Datenbank und Dateien, Sitzungsstufen, Ratenbegrenzung, Kosten mit 5 000 Fotos und 100 Galerien.
- **Im Browser** (eingebauter Browser, echte Antwort mit den echten Kopfzeilen in einem Rahmen wie im Editor): absichtlich rohes `<script>`, `onerror`, `meta refresh`, fremde Bilder/Stile/Rahmen, `<base>` in die Antwort eingeschleust, um die Schutzschichten **unabhängig vom Maskieren** zu prüfen.
- Alle Tests: 1 589 Prüfungen, 0 Fehler (vorher 1 572; 17 neue Prüfungen, siehe unten).

## Was ich ausprobiert habe und was herauskam

| Angriff | Ergebnis |
| --- | --- |
| Überschrift/Text/Zitat/Alt/Beschriftung/Galerie-Titel mit `<script>`, `<img onerror>`, `"><svg onload>`, `[x](javascript:…)`, `data:`, `&#106;avascript:`, Attribut-Ausbruch | Alles maskiert, kein Skript, kein ausgebrochenes Attribut, Links mit gefährlichem Ziel werden zu Klartext |
| Knopf-Ziel `javascript:`, ` javascript:`, `java<TAB>script:`, `data:`, `//evil`, `/\evil`, `vbscript:`, Entitäten | Server lehnt ab (422) |
| Seitentitel mit `<script>` aus der Datenbank | maskiert |
| Rohes Skript absichtlich in die Antwort eingeschleust (Browser) | **Nicht ausgeführt**, die Eltern-Seite blieb unberührt (Titel und Inhalt unverändert). Zwei Sperren, jede für sich ausreichend: Rahmen-`sandbox` (kein `allow-scripts`) blockiert Skripte und `meta refresh`; die CSP blockiert zusätzlich Stile, Bilder, Rahmen und `<base>` von fremden Adressen |
| Fremde Webseite rahmt die Route / Clickjacking | `X-Frame-Options: SAMEORIGIN` und `frame-ancestors 'self'`; die Verwaltungsseiten selbst sind `DENY` |
| CSRF von fremder Seite | Token nötig (403), Cookie `SameSite=Strict`, Antwort nur für den Eingeloggten |
| Ohne Anmeldung, oder Sitzung nur nach dem Passwort (ohne zweiten Schritt) | 303 zur Anmeldung, kein Inhalt |
| Redaktion statt Administrator | erlaubt, nur dasselbe wie die gespeicherte Vorschau; keine Administratordaten in der Antwort |
| Schreiben | Geändert wird **nur** die Tabelle `login_attempts` (Ratenzähler). Keine Seiten, Fotos, Galerien, Fassungen, Merker `site_dirty`, Protokoll, Dateien |
| Fotonummer: gelöscht, nicht fertig, unbekannt, Zeichenkette | abgelehnt oder nicht dargestellt; Pfade kommen nie aus der Eingabe |
| Offenlegung | Weder Originalname, Originalpfad, Prüfsumme, Token, EXIF/GPS noch Ort stehen in der Antwort (Gruppen-Attribute `data-meta` usw. sind leer) |
| Riesige und verschachtelte Eingabe | über 200 KB → 413; 50 000 Klammern tief → 422 in 0,1 s; 4 000 Zufallsblöcke: nur 200 und 422, kein 500, keine Ausnahme |
| Rechenlast | 100 Galerie-Blöcke mit je 12 Fotos: 0,04 s. Seite mit 5 000 Fotos in der Datenbank: 0,06 s je Aktualisierung |
| Auswahl-Routen: SQL-Zeichen, `%`, `_`, Seite `-5`/`abc`/riesig, Galerie `abc`/unbekannt | parametrisiert, kein Treffer, 200 bzw. 404 ohne Daten; JSON mit `application/json`; Antwort enthält nur Nummer, Titel, Alt-Text, Beschriftung und die öffentliche Bild-Adresse |
| Referrer-/Verlaufs-Lecks, Zwischenspeicher | `Cache-Control: no-store`, `Referrer-Policy: no-referrer`, `noindex`; der Token geht im POST-Körper, nie in der Adresse |
| Frühere Funde F1/F2 (Bericht 35) | nicht betroffen: die Route verändert weder Konten noch Rechte |

## Befunde

### N1 (niedrig, **behoben**): riesige Fließkommazahl in Blöcken löst eine PHP-Warnung aus

Ein Block wie `{"t":"galleries","limit":1.5e308}` führte in `Blocks.php` zu `(int)` auf eine zu große Zahl (Warnung „not representable as an int“, Ergebnis nicht festgelegt). Das gilt auch für das normale Speichern, nicht nur für die Vorschau. Auswirkung gering: die Anzeige von Fehlern ist im Betrieb aus, der Wert wurde danach begrenzt. Behoben mit der kleinen Hilfe `Blocks::num()` (nur endliche Zahlen, auf ±1 000 000 begrenzt, sonst der Vorgabewert) für `cols`, `interval`, `limit`. Test: „Zahlen in Blöcken“ in `tests/material.php`.

### N2 (niedrig, Hinweis): `sandbox="allow-same-origin"` ist nötig, aber nur zusammen mit „kein Skript“ sicher

Ich habe geprüft, ob es ohne `allow-same-origin` geht (dann könnte selbst ein entkommenes Skript die Eltern-Seite und das CSRF-Token nie erreichen). **Es geht nicht:** Bilder unter `/media/` tragen `Cross-Origin-Resource-Policy: same-site`; ein Rahmen mit undurchsichtigem Ursprung (`sandbox=""`) lädt sie nicht (im Browser belegt: leerer Rahmen, mit `allow-same-origin` erscheint das Bild). Also bleibt `allow-same-origin`. Die Sicherheit hängt dann daran, dass **nie `allow-scripts` dazukommt** (die Kombination wäre ein vollständiger Ausbruch). Heute doppelt gesichert: Skripte sind sowohl am Rahmen als auch in der CSP der Antwort ausgeschlossen, und die Prüfung `Vorschau-Skript: Rahmen ohne Skripterlaubnis` im Test schlägt an, wenn jemand `allow-scripts` einträgt. Empfehlung: nichts ändern; bei künftigen Änderungen an `page-preview.js` oder `previewHeaders` diese Prüfungen nicht abschwächen.

### N3 (niedrig): Grenze 300 je 15 Minuten und Verstärkung bei vielen Galerie-Blöcken

- Der Editor fragt höchstens alle 2,5 Sekunden, das sind 360 je 15 Minuten. Wer 12,5 Minuten lang ununterbrochen tippt, kann in die Sperre laufen (Meldung „Zu viele Aktualisierungen“, bis zu 15 Minuten). Kein Sicherheitsproblem, nur Bedienung. Empfehlung: Grenze auf 600 setzen oder Mindestabstand auf 4 Sekunden.
- Ein Block „Galerien“ gibt alle Galerien aus; 100 solcher Blöcke ergeben aus 4 KB Eingabe 8 MB Antwort (0,5 s, 59 MB Speicher bei 100 Galerien mit 5 000 Fotos). Das braucht ein Konto mit Anmeldung und zählt auf die 300er-Grenze. Empfehlung (kann warten): höchstens 3 Galerien-Blöcke je Seite zulassen oder das Raster in der Vorschau begrenzen. Rechenzeit auf all-inkl ist weiterhin nicht gemessen (steht schon als offen in Entwurf 29, Abschnitt 9).

## Nicht geprüft

- Safari und Firefox: Verhalten von `sandbox` und `container-type`.
- Ob `<base target="_blank">` in der Antwort tatsächlich greift (die Eigenschaft `base-uri 'none'` betrifft nur ein `href`, ich habe nur ein `<base href>` im Versuch blockiert gesehen). Ohne Wirkung wären Links in der Vorschau nur ein Bedienpunkt: ein Klick auf „Veröffentlichen“ oder „Zurück zur Verwaltung“ lädt eine Verwaltungsseite in den Rahmen, die dort wegen `X-Frame-Options: DENY` nicht erscheint.
- Opus prüft den Diff von `page-editor.js` und `page-material.js` parallel; mein Teil ist die Route und das, was als HTML zurückkommt.
- Hinter einem Reverse-Proxy mit eigener CSP.

## Neue Prüfungen (`tests/material.php`, Abschnitt „Prüfung Fable“)

Bösartige Blöcke in jedem Typ (kein Skript, kein ausgebrochenes Attribut), acht gefährliche Knopf-Adressen abgelehnt, keine Offenlegung von Originalname/-pfad/GPS/Ort in der Vorschau und in den Auswahl-Routen, Sitzung nach dem Passwort wird abgewiesen, Zahlenbegrenzung, SQL-/Platzhalter-/Seitenzahl-Randwerte der Auswahl-Routen, Galerie `abc`/unbekannt.

## Aussage

**Hochladen: ja**, keine Bedingungen aus meiner Prüfung. Offen bleibt die Opus-Prüfung des Editor-Skripts (läuft) und Lutz’ Freigabe. Danach auf dem Testserver: einmal die Vorschau mit einer großen Seite messen (Rechenzeit je Aktualisierung).
