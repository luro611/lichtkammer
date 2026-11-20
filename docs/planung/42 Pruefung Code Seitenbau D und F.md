# 42 Prüfung Code: Seitenbau Teil D und F

Prüfung durch Opus am 20.11.2026. Geprüft: Commit `7c945db` (Teil D, Seitenstreifen „Material“) und `7c8ca6b` (Teil F, Vorschau neben dem Editor), Maßstab Entwurf 29 (Abschnitte 2 b ohne Dateien, 2 d Stufe 2, 4, 7 bis 9) und der Hinweiszettel der Bau-Sitzung. Die Sicherheit der Route `POST /admin/vorschau-live` hat Fable getrennt geprüft (Bericht 43, Commit `d0210fb`); hier geht es um den Editor und um Korrektheit.

**Ergebnis:** Teil D und F tun, was der Entwurf verlangt. Ein Fehler mit Risiko mittel (Rückgängig nach „Foto einsetzen“ war wirkungslos) und vier kleine Fehler sind behoben. Nichts davon braucht eine Entscheidung. Tests: 1 593 Prüfungen, 0 Fehler. Nichts hochgeladen, nichts gepusht.

## 1. Wie geprüft

- **Code gelesen:** ganzer Diff beider Commits (`page-editor.js`, `page-material.js`, `page-preview.js`, `app.css`, `SitePages.php`, `Catalog.php`, `Renderer.php`, Vorlagen, Tests).
- **Echt im Browser:** Wegwerf-Instanz lokal in Docker (`php -S` mit dem Repo-Stand, Daten im Scratchpad), Einrichtung mit Wegwerf-Konto und TOTP, 89 erzeugte Testbilder (75 einzeln, 14 als Galerie „Island“). Playwright mit **echter Maus** (`mouse.down`/`move`/`up`, kein simuliertes Ereignis) in **Chromium und Firefox**. Dazu Tastatur („Wohin?“ mit Enter, Leertaste, Tab, Escape), Handy-Breite 375 px mit Touch und dunkel, alle sechs Designs dunkel, große Seite (100 Blöcke, 192 KB), 429 durch künstlich gefüllten Zähler.
- **Wiederholbar:** Skripte liegen im Scratchpad der Prüfsitzung (nicht im Repo); jeder Befund unten nennt, wie er sich zeigt.

## 2. Befunde

| Nr. | Risiko | Befund | Stand |
|---|---|---|---|
| B1 | mittel | Rückgängig nach „Foto einsetzen“ tut nichts | behoben |
| N1 | niedrig | Doppelklick auf „Mehr laden“ überspringt eine Seite, Fehler lässt die Seitenzahl erhöht | behoben |
| N2 | niedrig | Tippen in der Material-Suche löst Vorschau-Anfragen aus | behoben |
| N3 | niedrig | Fehler und 429 in der Vorschau: Statuszeile sagt trotzdem „Aktualisiert um …“ | behoben |
| N4 | niedrig | „Nur die ersten 12 von 14 Fotos übernommen“ war nur für Screenreader da, nicht sichtbar | behoben |
| N5 | niedrig | Leiste „Vorschau, nicht online. Veröffentlichen · Zurück zur Verwaltung“ und Hell/Dunkel-Knopf im Vorschau-Rahmen tun nichts | offen, Vorschlag |
| N6 | niedrig | Ratenbegrenzung 300 je 15 Minuten gegen automatische Aktualisierung alle 2,5 s | offen, Vorschlag |
| N7 | niedrig | Galerie-Ablage ist asynchron: Ladehinweis kaum sichtbar, Einfügestelle kann sich verschieben | offen, Hinweis |

### B1 (mittel): Rückgängig nach „Foto einsetzen“ war wirkungslos

**Wie es sich zeigte (Chromium und Firefox, jedes Mal):** Foto A auf einen Einfügepunkt ziehen (neuer Bildblock), Foto B auf diesen Bildblock ziehen (ersetzt A), „Rückgängig“: Der Block zeigte weiter B. Erst der zweite Klick entfernte den ganzen Block. Für Matthias heißt das: Ein versehentlich auf ein Bild fallen gelassenes Foto lässt sich nicht zurückholen, und der nächste Klick auf „Rückgängig“ löscht unerwartet den Block.

**Ursache:** `undo()` stellt seit Prüfung 30 (B2) nur die Struktur zurück und kopiert den aktuellen Inhalt jedes noch vorhandenen Blocks hinein (`keepContent`). `setPhoto()` legte zwar einen Rückgängig-Schritt an, aber `keepContent` überschrieb das alte Foto, Alt-Text und die Unterschrift sofort wieder mit den neuen Werten.

**Behebung:** `setPhoto()` markiert den Block im gespeicherten Schritt (`_r`), `keepContent` übernimmt für so markierte Blöcke den alten Inhalt. Die Markierung beginnt mit `_` und geht deshalb nie zum Server (`replacer`). Im Browser nachgeprüft: Rückgängig holt Foto A zurück, die übrigen Schritte bleiben, wie sie waren.

### N1: „Mehr laden“

Ein Doppelklick erhöhte die Seitenzahl zweimal; die erste Antwort wurde als veraltet verworfen, die zweite brachte Seite 3, Seite 2 fehlte. Ein Ladefehler ließ die Seitenzahl ebenfalls erhöht. **Behebung:** Knopf gesperrt, solange geladen wird; die Seitenzahl steigt erst bei Erfolg; bereits gezeigte Ids werden übersprungen (falls zwischendurch neue Fotos hochgeladen wurden und sich die Seiten verschieben). Geprüft: 60 + 29 Kacheln, keine doppelt.

### N2: Suche löst Vorschau aus

Das Suchfeld des Streifens liegt im Editor-Formular; `page-preview.js` hörte auf jedes `input` im Formular. Jeder Suchbegriff kostete eine Vorschau-Anfrage (und zählte gegen die Ratenbegrenzung). **Behebung:** Ereignisse aus `.emat` und `.epreview` lösen keine Vorschau aus.

### N3: Fehler in der Vorschau

Bei 400/413/422/429 zeigte der Rahmen den Fehlersatz klein im Rahmen, die Statuszeile darüber aber „Aktualisiert um …“. **Behebung:** Fehlerseiten der Route tragen `<body data-lk-fehler>`; der Editor liest das (gleiche Herkunft) und zeigt „Vorschau nicht aktualisiert: …“ in der Statuszeile, angesagt über `say()`. Mit künstlich gefülltem Zähler geprüft: „Zu viele Aktualisierungen in kurzer Zeit. In etwa 14 Minute(n) geht es weiter.“ steht oben und wird angesagt. Kleine Änderung in `SitePages::previewFrame()` (eine Attribut-Ergänzung, ohne Wirkung auf Sicherheit; Fable hat die Route vorher geprüft).

### N4: 12-Fotos-Grenze nicht sichtbar

Der Satz „Die Galerie hat 14 Fotos. Ein Raster fasst höchstens 12 …“ ging nur über `say()`. **Behebung:** steht jetzt auch sichtbar im Hinweisfeld (bleibt nach dem Ende des Ziehens stehen), Rückgängig räumt ihn mit ab.

### N5 (offen): tote Bedienelemente im Rahmen

Die Vorschau nutzt den Renderer der gespeicherten Vorschau. Darin stehen die Leiste „Vorschau, nicht online. Veröffentlichen · Zurück zur Verwaltung“ und der Hell/Dunkel-Knopf der Seite. Im Rahmen tun sie nichts (keine Skripte, keine neuen Fenster, so gewollt). „Veröffentlichen“ an dieser Stelle kann Matthias verwirren. **Vorschlag:** Für die Live-Vorschau die Leiste weglassen (eigener Schalter im Renderer); die Kennzeichnung „Vorschau, nicht gespeichert, nicht online“ steht ohnehin über dem Rahmen. Kein Fehler, deshalb nicht hier geändert.

### N6 (offen): Ratenbegrenzung

Die Vorschau fragt bei laufender Arbeit höchstens alle 2,5 s an (nach 1 s Pause). Die Route erlaubt 300 Anfragen in 15 Minuten. Wer 12 bis 13 Minuten ohne Pause tippt, kann an die Grenze kommen; ab jetzt wird das sichtbar gemeldet (N3). **Vorschlag:** Abstand auf 4 s erhöhen oder die Grenze auf 600 setzen. Entscheidung bei Lutz bzw. nach Rücksprache mit Bericht 43.

### N7 (Hinweis): Galerie-Ablage

Die Fotos einer Galerie werden beim Start des Ziehens geholt und beim Ablegen eingesetzt. `listAlive` fängt ab, dass die Liste durch Rückgängig verschwunden ist. Ändert jemand in der kurzen Ladezeit die Reihenfolge, landet die Galerie an der alten Nummer. Bei lokalem Netz unter 100 ms, praktisch ohne Bedeutung. Der Hinweis „Galerie wird geladen …“ wird vom Ende des Ziehens gleich wieder gelöscht; bei langsamer Leitung sieht man also kurz nichts. Nicht geändert.

## 3. Prüfpunkte aus dem Auftrag

**(1) Ziehen aus dem Streifen (echte Maus, Chromium und Firefox):** Foto auf Einfügepunkt → neuer Bildblock mit Foto, kein Auswahldialog. Foto auf Bildblock → ersetzt (Markierung „drop-on“), Alt-Text folgt nur, wenn er leer oder der alte war. Foto auf Kartenhälfte → davor/dahinter. Foto in leere Spalte → Bildblock in der Spalte. Galerie auf freie Stelle → Galerie-Raster mit 12 von 14 Fotos. Galerie in Spalte oder auf Bild in Spalte → abgelehnt, Hinweis sichtbar und angesagt, Zustand unverändert. Nichts doppelt, nichts verloren, Nummern stimmen nach jedem Schritt. Rückgängig nach Einfügen, Ersetzen (nach B1) und Galerie richtig. Fremddatei (DragEvent mit `Files`, ohne eigene Kennung) → ignoriert. Kein `innerHTML`; Kacheln und Dialog nur über `textContent`/Attribute.

**(2) Daten:** Im Block steht nur die Foto-Id (dazu Alt-Text/Unterschrift wie bisher), `v:1` unverändert, Server prüft alles neu (`Blocks::parse`), unbekannte Nummer abgelehnt. Alte Seiten byte-gleich (Tests „gleicher Renderer“, Seite und Startseite). Galerie-Auswahl filtert Papierkorb und nicht fertige Fotos. Suche: veraltete Antworten werden über `seq` verworfen (schnelles Tippen „foto7“ ergab genau 6 Treffer); Doppelungen jetzt ausgeschlossen (N1). Titel wie `Möwe <b>` erscheinen als Text.

**(3) „Wohin?“, Fokus, Handy:** Enter und Leertaste öffnen den Dialog, Fokus auf der ersten Möglichkeit, Tab und Enter fügen ein, Fokus danach auf „Foto ändern“ im neuen Block; Escape schließt und gibt den Fokus an die Kachel zurück. 375 px (Touch, dunkel): keine waagrechte Scrollleiste, Streifen eingeklappt und aufklappbar, Antippen → „Wohin?“ → eingefügt. Vorschau ersetzt auf schmalen Bildschirmen den Editor, „Zurück zum Bearbeiten“ bringt ihn zurück und setzt den Fokus auf den Vorschau-Knopf.

**(4) Live-Vorschau:** Entprellen 1 s, Abstand 2,5 s, nie zwei Anfragen gleichzeitig (Warteschlange), eine veraltete Antwort kann keine neuere überschreiben (das Formular schickt immer in denselben Rahmen; eine laufende Anfrage wird nicht abgebrochen, die nächste folgt erst danach). Scrollstand bleibt (400 px vorher und nachher). 100 Blöcke, 192 KB: 0,34 s, 190 KB HTML. Links im Rahmen öffnen nichts (Chromium meldet „Blocked opening … allow-popups“), Rahmen bleibt auf der Vorschau. Keine CSP-Verstöße bei Stilen, Bildern, Schriften; nur blockierte Skripte (gewollt). Speichern mit offener Vorschau: gespeichert und veröffentlicht wie sonst; das Vorschau-Formular liegt außerhalb und setzt den Merker „ungespeichert“ nicht.

**`sandbox allow-same-origin` ohne Skripte:** nötig und vertretbar. Ohne `allow-same-origin` hätte der Rahmen eine undurchsichtige Herkunft; dann blockiert `Cross-Origin-Resource-Policy: same-site` Bilder und Schriften, die Vorschau sähe anders aus als die echte Seite. Ohne `allow-scripts` kann der Inhalt die gleiche Herkunft nicht nutzen; die Sandbox steht doppelt (Attribut und CSP `sandbox`), dazu `default-src 'none'`, `form-action 'none'`, `base-uri 'none'`. Die gefährliche Kombination wäre `allow-scripts` zusammen mit `allow-same-origin`; ein Test wacht darüber, dass `allow-scripts` nicht dazukommt. Die gleiche Herkunft nutzt jetzt auch der Editor selbst, um Fehlerseiten zu erkennen (N3).

**(5) Designs:** Alle neuen Regeln nutzen nur die Variablen (`--accent`, `--accent-strong`, `--surface`, `--line`, `--soft`, `--muted`, `--ink`, `--bg`). Bildschirmfotos aller sechs Designs dunkel und Warm hell: lesbar, Rahmen der Vorschau in der Akzentfarbe des Designs. Neue Farbpaare gibt es nicht, deshalb kein neuer Lauf von `tools/kontrast.py`.

**(6) Tests:** Die Servertests sind aussagekräftig (Grenzen, Rechte, nichts geschrieben, byte-gleich, CSP, Fehlerfälle; Bericht 43 hat Angriffsfälle ergänzt). Die Tests der Skripte sind nur Textsuchen und können Verhalten nicht prüfen; B1 wäre damit nie aufgefallen. Neu: vier Prüfungen (Fehlerseite trägt die Markierung, auch bei 429; Wächter gegen Rückbau von B1, N1, N2/N3). Ein echter Browser-Test im Repo fehlt weiterhin; **Vorschlag:** die Playwright-Abläufe dieser Prüfung als `tests/browser/` mit Anleitung ins Repo nehmen (Docker, wie `docs/anleitung/skripte/pw_run.sh`).

## 4. Nicht geprüft

- **Safari/WebKit:** Playwright-WebKit unter Linux führt echtes Ziehen per Maus nicht aus (schon der erste Zug bleibt wirkungslos; Werkzeuggrenze, kein Befund). Tastaturweg und Vorschau laufen in WebKit. WebKit meldet zweimal „Refused to apply a stylesheet … unsafe-inline“; die Vorschau enthält aber weder `style=` noch `<style>`, die Quelle ist unklar, sichtbar war nichts. **Lutz bitte in Safari am Mac ziehen** (Foto auf Bild, Galerie in Spalte, Rückgängig).
- Echtes Handy (nur Emulation 375 px mit Touch); echtes Ziehen per Finger gibt es in HTML5 nicht, dort gilt „Wohin?“.
- Screenreader (nur geprüft, dass `say()` die Texte in die Live-Region schreibt).
- all-inkl (nichts hochgeladen).

## 5. Nebenbei

- `tests/medien.php` „Prüfung 19 (Zweig mit über 200 Einträgen)“ schlug einmal fehl, als zwei Testläufe gleichzeitig liefen; einzeln immer grün. Vermutlich zeitabhängig unter Last, alt, nicht Teil dieser Prüfung.

## 6. Geänderte Dateien

`public/assets/js/page-editor.js` (B1, N4), `public/assets/js/page-material.js` (N1), `public/assets/js/page-preview.js` (N2, N3), `app/src/Admin/SitePages.php` (N3, Attribut `data-lk-fehler`), `tests/material.php` (vier Prüfungen). 1 593 Prüfungen, 0 Fehler.
