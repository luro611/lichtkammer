# Prüfung 31: Code „Speichern und Veröffentlichen“ (Entwurf 28) und Kleinigkeiten N1 bis N6

Stand: 19.11.2026. Prüfer: Claude Opus 5.5. Geprüft: Commits `16d7cfc` (N1 bis N6 aus Bericht 30) und `5ca8ef2` (Bau von Entwurf 28), Diff `9cc2c13..5ca8ef2`. **Nichts hochgeladen, nichts am Server geändert.**

**Ergebnis:** Der Bau erfüllt Entwurf 28. Reihenfolge, Fehlerweg, Sperre, CSRF, Anmeldung und Protokoll stimmen. Ein Befund mit Risiko **mittel bis hoch** fiel im Browser auf: Der Ausgangsfehler von Lutz ließ sich über das neue Hinweisband **wiederholen**. Er ist behoben, ebenso vier kleinere Punkte. Zurück an Lutz gehen zwei Entscheidungen (Zahl der Fassungen, Rechenzeit der Zeile „Dabei …“) und die Bauzeitmessung auf all-inkl.

## 1. Wie geprüft

- Code gelesen: `AdminController`, `MediaPages::saveThenPublish()`, `SitePages` (Seite, Startseite, Logos, Einstellungen, Veröffentlichen, `alsoLive()`), `Builder::publish()`, die Vorlagen, `admin.js`, `page-editor.js`.
- Tests in Docker: vorher 1 284, nachher **1 288 Prüfungen, 0 Fehler** (`docker run --rm -v "$PWD":/app -w /app lichtkammer-test php tests/run.php`).
- Lokal in Docker (Image `lichtkammer:dev` mit dem aktuellen Code als Bind-Mount, Testkonto mit TOTP), eingebauter Browser (Chromium), 1024 px und 375 px. Geprüft: Einstellungen (Design), Seite (Entwurf → online → Entwurf), Startseite, Galerie, Hinweisband, Knöpfe, echtes Mausziehen im Editor.
- Mit curl: Kombiweg ohne CSRF-Token (403), ohne Anmeldung (Weiterleitung zur Anmeldung), Galerie-Status ohne Token (403), gefälschter Grund in `r=` (wird nicht ausgegeben).
- Kontrast: `python3 tools/kontrast.py` meldet 0 Fehler; das Band nutzt `--ink` auf `--soft` (in allen Designs hell und dunkel 11,7 bis 15,9).

## 2. Befunde und Behebung

**B1 – Ausgangsfehler über das Hinweisband wiederholbar (Risiko mittel bis hoch, behoben).**
Unter Einstellungen ein Design wählen, nicht speichern, oben im Band „Jetzt veröffentlichen“ klicken: Die Seite wird veröffentlicht, die Designwahl geht **ohne Warnung** verloren. Das ist genau der Fehler, der Entwurf 28 ausgelöst hat; das Band steht jetzt sogar auf jeder Seite direkt über dem Formular. *Wiederholbar:* immer, im Browser nachgestellt. Der Seiten-Editor und die Startseite waren schon geschützt (eigene Abfrage „Seite verlassen?“), Einstellungen, Logos und Galerie nicht.
*Behebung:* Formulare mit `data-guard` (Einstellungen, Logo-Auswahl, Galerie-Text) merken Eingaben; verlässt man die Seite, ohne dieses Formular abzuschicken (Band, Seitenleiste, „Galerie online stellen“), fragt der Browser nach. `admin.js`, drei Vorlagen, Test. Im eingebauten Browser lässt sich der Dialog selbst nicht sehen (der Bereich bestätigt ihn selbst); geprüft ist, dass die Abfrage nach einer Eingabe greift und vorher nicht.

**B2 – Zeitüberschreitung im Bau ließ das Band verschwinden (Risiko mittel, behoben).**
Der Merker „nicht veröffentlicht“ wurde erst **nach** dem Bau gesetzt. Bricht PHP mitten im Bau ab (`max_execution_time` 60 s auf all-inkl), ist das Gespeicherte in der Datenbank, aber der Merker bleibt auf „alles online“: kein Band, kein Hinweis. *Wiederholbar:* nur bei Abbruch durch PHP; lokal nicht nachgestellt.
*Behebung:* `saveThenPublish()` setzt den Merker **vor** dem Bau und löscht ihn nur bei Erfolg; der Zähler zählt dabei genau eins dazu (Test).

**B3 – Zähler im Band wechselte die Bedeutung (Risiko niedrig, behoben).**
Speichern zählte Speichervorgänge, das Öffnen von „Veröffentlichen“ überschrieb die Zahl mit der Zahl geänderter **Dateien** (nach einer Designänderung z. B. hunderte). *Behebung:* Die Seite „Veröffentlichen“ entscheidet nur noch, ob etwas offen ist (sonst 0), die Zahl bleibt „gespeicherte Änderungen“ (Test).

**B4 – Zeile „Dabei gehen auch N weitere Änderungen online“ nicht wahr (Risiko niedrig, behoben).**
Die Liste zählt geänderte Teile der Seite, nicht Änderungen. Eine geänderte Menüzeile oder ein Design ändert jede Seite, die Zeile meldete dann z. B. „30 weitere Änderungen“, obwohl es eine war. *Neue Zeile:* „Dabei geht alles Gespeicherte online. Noch nicht online sind außerdem: Startseite, Seite „Impressum“, … und 26 weitere Teile der Seite.“ (in Einstellungen und Logos ohne „außerdem“).

**B5 – Knopftext widersprach der Statusauswahl (Risiko niedrig, behoben).**
Bei einer Entwurfsseite hieß der Zweitknopf „Speichern (Entwurf, nicht online)“. Wählt man oben „Online sichtbar“ und klickt ihn, wird der Status trotzdem „online“ (geht mit dem nächsten Veröffentlichen live). Jetzt „Nur speichern (noch nicht online)“, wie überall.

**B6 – Doppelte Aussage bei technischem Fehler (Risiko niedrig, behoben).**
„… online ist weiter die alte Fassung: Das Veröffentlichen ist fehlgeschlagen; die bisherige Seite bleibt unverändert.“ Jetzt: „… alte Fassung: Ein technischer Fehler ist aufgetreten (Einzelheiten im Fehlerprotokoll des Servers).“ Auch für unbekannte Gründe aus der Adresse.

**B7 – N3 nicht ganz: Duplizieren füllte eine volle Spalte (Risiko niedrig, behoben).**
Einfügen und Verschieben in eine Spalte mit 20 Blöcken werden abgelehnt, „Duplizieren“ in dieser Spalte nicht (21 Blöcke, Fehler erst beim Speichern). Jetzt gleicher Hinweis. Im Browser nachgeprüft.

## 3. Zurück an Lutz bzw. den Bau

**R1 – Fassungen werden schnell verdrängt (Risiko mittel, Entscheidung Lutz).** Bisher wurde selten veröffentlicht; jetzt ist „Speichern und veröffentlichen“ der Hauptknopf. Es bleiben nur **5 Fassungen** (`Builder::KEEP`) und 10 Datenbanksicherungen. Fünf Klicks an einem Nachmittag, und die Fassung von gestern ist zum Zurückholen weg. Vorschlag: mehr Fassungen (z. B. 20) oder zusätzlich die letzte Fassung jedes Tages behalten; Platzbedarf ist klein (nur HTML). Jeder Klick macht außerdem eine Datenbanksicherung (`VACUUM INTO`), das gehört in die Zeitmessung.

**R2 – Zeitgrenze all-inkl (Risiko mittel, offen bis zur Messung).** Lokal 0,3 s bei 755 Dateien; auf all-inkl unbekannt. Zusätzlich rechnet die Zeile „Dabei …“ beim **Öffnen** von Seite, Startseite, Einstellungen und Logos die ganze Seite durch (`plan()`), sobald etwas nicht veröffentlicht ist, und das ist beim Arbeiten fast immer. Ist `plan()` auf all-inkl langsam, werden diese Seiten zäh. Vorschlag: bei der Messung `plan()` mitmessen; wenn über etwa 1 s, die Zeile nur auf Klick („Was geht mit online?“) berechnen.

**R3 – Adressänderung ohne Weiterleitung (Risiko mittel nach dem Livegang, unverändert als Vorschlag).** Ändert man die Adresse einer online stehenden Seite oder Galerie und veröffentlicht, ist die alte Adresse 404 (Test in `tests/speichern.php`). Mit dem Kombiknopf passiert das jetzt in einem Klick. Folge: tote Links von außen und in Suchmaschinen, Verlust von Besuchern; kein Datenverlust. Vorschlag bleibt: bei Adressänderung einer online stehenden Seite automatisch eine Weiterleitung anlegen. Bis dahin Hinweis neben dem Feld „Adresse“.

**Niedrig, nur zur Kenntnis:**
- **N1 Band zählt auch Änderungen ohne Wirkung.** Jede Speicherung (auch neue Entwurfsgalerie, Logo hochladen) zählt; das Band sagt dann „1 Änderung ist noch nicht online“, obwohl der Probelauf nichts findet. Das Öffnen von „Veröffentlichen“ stellt es richtig. Bekannte Folge der gewählten Lösung ohne `plan()` (Entwurf 28).
- **N2 Zwei Personen gleichzeitig.** Speichert B, während A gerade baut, kann As Erfolg den Merker löschen, obwohl Bs Änderung nicht im Bau war. Fenster: Bauzeit. Wird beim Öffnen von „Veröffentlichen“ richtiggestellt.
- **N3 Galerie auf Entwurf, Bau scheitert.** Oben steht „Entwurf (nicht online)“, online ist sie aber noch; die rote Meldung sagt es richtig („online ist weiter die alte Fassung“).
- **N4 „Galerie online stellen“ nimmt alles andere mit,** ohne die Zeile „Dabei …“ (bewusst nicht ergänzt, wegen R2).
- **N5 Safari/Firefox:** echtes Ziehen und der Dialog „Seite verlassen?“ nur in Chromium geprüft.

## 4. Prüfpunkte aus dem Auftrag

| Punkt | Ergebnis |
|---|---|
| Speichern vor Bauen | ja, Datenbank = online nach Erfolg (Test) |
| Fehler im Bau | Gespeichertes bleibt, alte Fassung online, Meldung mit Grund aus fester Liste und „Erneut versuchen“ (Test, curl) |
| Sperre, Doppelklick | Sperre `flock` meldet „anderes Veröffentlichen“; Knöpfe werden nach dem Absenden gesperrt, Knopfwert kommt an (im Browser: Einstellungen mit echtem Klick → `savedpub`) |
| Zeitgrenze | Merker jetzt vor dem Bau (B2); Messung offen (R2) |
| Rechte, CSRF, Anmeldung | gleiche Routen, CSRF vom Dispatcher; ohne Token 403, ohne Anmeldung Weiterleitung (curl, Test) |
| Protokoll | `page_saved` bzw. `settings_saved`/`gallery_*` und danach `site_published` je eine Zeile, Kette unversehrt (DB im Container angesehen, Test) |
| Entwürfe | Entwurf bleibt nicht online; „Speichern und online stellen“ stellt online; Status Entwurf + Hauptknopf nimmt die Seite vom Netz (curl: 404) |
| Band, Zähler | nach Speichern, Veröffentlichen, Fehler, Sperre, mehreren Änderungen richtig (B2, B3); Rollback rechnet über den Probelauf neu |
| Zeile „Dabei …“ | jetzt wahr (B4); Rechenzeit offen (R2) |
| Galerie | „online stellen“ ohne Fotos abgelehnt (Browser), mit Fotos baut sofort, „auf Entwurf setzen“ nimmt sie vom Netz (Tests) |
| Weiterleitungen | keine bei Adressänderung (R3) |
| Design-Ausgangsfehler | Kombiknopf in Einstellungen und Logos; Verlust über Band/Seitenleiste jetzt mit Nachfrage (B1) |
| Texte | einfach; zwei Widersprüche behoben (B5, B6) |
| Kontrast | 0 Fehler |

**N1 bis N6 (Commit `16d7cfc`):** alle umgesetzt. Im Browser mit echter Maus geprüft: Baustein „Zitat“ auf Einfügepunkt gezogen → Fokus im neuen Feld (N2), keine Markierung übrig (N6). Baustein angetippt → „Hier am Ende einfügen: Trennlinie“ legt am Ende ab (N1). Volle Spalte lehnt Einfügen mit Hinweis ab (N3, Duplizieren ergänzt, B7); der Hinweis verschwindet beim nächsten Schritt (N4). N5 (Leiste bleibt bei Maus/Fokus) nur im Code geprüft: Frist wird bei `mouseenter`/`focusin` angehalten und danach neu gestartet; kein neuer Zustandsfehler gefunden.

## 5. Geänderte Dateien

`app/src/Admin/MediaPages.php`, `AdminController.php`, `SitePages.php`, `app/templates/admin/page_edit.php`, `settings.php`, `logos.php`, `gallery.php`, `partials/also.php`, `public/assets/js/admin.js`, `page-editor.js`, `tests/speichern.php`.

## 6. Vor dem Hochladen

Browser-Test von Lutz auf dem Testserver (Band, Kombiknopf, Nachfrage beim Verlassen in Safari), dann Bauzeitmessung auf all-inkl mit `plan()` und `publish()` (R2), Entscheidung zu R1.
