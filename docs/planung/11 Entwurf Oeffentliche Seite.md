# Entwurf Schritt 5: Öffentliche Seite

Stand: 15.11.2026. Zur Freigabe, noch kein Code. Grundlage: Plan `03` (Abschnitte 4, 4a, 5, 7, 11), Schritt 3 (`09`), Schritt 4 (`10`). Nicht Teil davon: Passkey, Umzug, Ausstellungen (Phase 2), Drehen/Fokuspunkt/Foto ersetzen (Schritt 4b), Bilder und PDF-Anhänge in Seiten.

## 1. Seitenstruktur und Adressen

| Adresse | Inhalt |
|---|---|
| `/` | Start: großes Titelbild (Hero) mit Kurztext, darunter die veröffentlichten Galerien als Kacheln in der Reihenfolge `sort_order` |
| `/galerien/` | Übersicht aller veröffentlichten Galerien als Kacheln, filterbar nach Jahr, Ort, Fotograf |
| `/galerien/<slug>/` | Galerie: Titel, Beschreibung, Raster mit gleich hohen Zeilen, Lightbox |
| `/<seiten-slug>/` | einfache Seiten: Über uns, Fotografen, Impressum, Datenschutz und weitere (Abschnitt 5) |
| `/404.html` (intern) | Fehlerseite im Theme, wird bei unbekannten Adressen mit Status 404 ausgeliefert |
| `/sitemap.xml`, `/robots.txt` | nur wenn `LK_NOINDEX` aus ist; mit `LK_BASE_URL` als absolute Adressen |

- Reservierte Seiten-Adressen (nicht vergebbar): `admin`, `galerien`, `assets`, `media`, `health`, `sitemap`, `robots`, `404`.
- Kein eigener Adressbereich für Einzelfotos. Eine Kachel verlinkt ohne Skript direkt auf die große Bilddatei; mit Skript öffnet sie die Lightbox. Tiefer Link auf ein Foto: `/galerien/island-2022/#foto-123` öffnet die Lightbox beim Laden.
- Galerien-Filter ohne eigene Seiten: reine Anzeige-Filter im Browser (Abschnitt 4), Zustand im `#`-Teil der Adresse (`#jahr=2022&fotograf=3`), damit Links teilbar bleiben. Ohne Skript sind alle Galerien sichtbar.
- Eine Galerie erscheint auf der Seite nur, wenn `status = published`, mindestens ein sichtbares Foto vorhanden ist und das Foto `ready`, nicht im Papierkorb ist. Fotos außerhalb veröffentlichter Galerien kommen nirgends vor.
- Start-Hero: gewählt in „Seite → Einstellungen“ (Foto aus der Mediathek, nur ein Foto aus einer veröffentlichten Galerie ist zulässig); ohne Wahl das Titelbild der ersten veröffentlichten Galerie. Einstellungen: Seitenname, Kurztext für den Hero, Hero-Foto.
- Galerie-Filter und Kennzeichen werden aus den Fotos abgeleitet (Jahr, Ort, Fotograf der enthaltenen Fotos). Es gibt dafür keine neuen Felder an der Galerie.

## 2. Wie das statische HTML entsteht und wie Veröffentlichen mit Rückgängig funktioniert

Auf all-inkl gibt es kein SSH, keine garantierten Symlinks und kein Cron. Der Ablauf braucht nur PHP-Schreibrechte im Datenordner:

1. **Bauen.** `SiteBuilder` liest die Datenbank (nur veröffentlichte Galerien und Seiten) und rendert alle Seiten mit denselben Vorlagen wie die Vorschau. Das Ergebnis wird in einen neuen Ordner `data/site/releases/<ID>/` geschrieben (Dateien erst unter einem Arbeitsnamen, am Ende ein einziges `rename`). `<ID>` ist Zeitstempel plus Kurzzeichen, z. B. `20261115-142530-a3f9`. Dazu `manifest.json`: Liste der Dateien mit Prüfsumme, Anzahl Galerien und Fotos, Konto, Zeit.
2. **Umschalten.** Die Datei `data/site/current` enthält die ID der aktiven Fassung. Sie wird über temporäre Datei und `rename` geschrieben (atomar auf demselben Dateisystem). Besucher sehen immer entweder die alte oder die neue Fassung, nie eine halbe.
3. **Ausliefern.** `public/index.php` kennt den Zweig „öffentliche Seite“: Pfad → Datei `releases/<aktuell>/<Pfad>/index.html`, dann mit `ETag` (Prüfsumme aus dem Manifest), `Last-Modified`, `Cache-Control: public, max-age=300, must-revalidate` und Kompression durch den Webserver. Fehlt die Datei, 404 mit der Fehlerseite der Fassung. Kein Zugriff auf Datenbank oder Anmeldung auf diesem Weg (Konfiguration wird gelesen, sonst nichts; getestet).
4. **Rückgängig.** Die letzten fünf Fassungen bleiben als Ordner liegen (HTML ist klein). „Auf Fassung vom 15.11., 14:25 zurücksetzen“ schreibt `current` um, sonst nichts. Ältere Fassungen räumt der nächste Veröffentlichen-Lauf weg. Bilder liegen gemeinsam in `media/` und gehören zu keiner Fassung.
5. **Sicherung vor dem Veröffentlichen.** Wie in Plan 9: Datenbankkopie `data/backups/…` (`VACUUM INTO`), letzte zehn bleiben.
6. **Sperre.** `flock` auf `data/site/.lock`; zweiter gleichzeitiger Lauf wartet nicht, sondern meldet „läuft gerade“.

**Ablauf im Admin** (`/admin/veroeffentlichen`):
- Prüfliste vorab: veröffentlichte Galerien mit Foto ohne Alt-Text, fehlgeschlagene Verarbeitung, Seiten ohne Inhalt, Hero ungültig. Harte Fehler sperren den Knopf, Hinweise nicht.
- Änderungsliste: Vergleich der neuen Dateien mit dem Manifest der aktiven Fassung, gruppiert und mit Namen: „Neu: Galerie Island 2022“, „Geändert: Seite Impressum“, „Entfernt: Galerie Berlin (jetzt Entwurf)“.
- Knopf „Veröffentlichen“ (POST, CSRF), danach Ergebnis mit Link zur Seite und Knopf „Rückgängig“ (zurück auf die vorherige Fassung).
- Gebaut wird in einer Anfrage. Bei einigen hundert Seiten sind das einige Sekunden; `max_execution_time` wird gelesen (`set_time_limit`), Fotos werden nicht verarbeitet, nur HTML geschrieben. Ein Baulauf mit über 2.000 Dateien ist nicht vorgesehen und würde als Fehler gemeldet (kein Absturz in der Mitte, da die aktive Fassung unberührt bleibt).
- Protokoll: `site_published`, `site_rolled_back` mit Fassungs-ID.
- Während der Arbeit sieht man nur im Admin, dass „unveröffentlichte Änderungen“ da sind: Hinweis in der Navigation, berechnet durch einen Probe-Lauf des Bauens im Speicher (nur auf Übersicht und Veröffentlichen-Seite).

**Vorschau** (`/admin/vorschau/…`): rendert dieselben Vorlagen auf Anfrage aus der Datenbank, einschließlich Entwürfen (Galerie `draft`, Seiten `draft`), mit Leiste „Vorschau, nicht veröffentlicht“, `noindex`, `Cache-Control: no-store`. Links innerhalb der Vorschau führen in der Vorschau weiter (Adress-Präfix wird beim Rendern gesetzt). Von jeder Galerie und Seite im Admin gibt es einen Knopf „Vorschau“. Die Ausgabe ist dieselbe Funktion wie beim Bauen; es gibt keinen zweiten Code-Pfad, der abweichen könnte.

**Kompatibilität Betriebsarten:** Der Ablauf braucht nur `data/` und `rename`, also identisch auf all-inkl und Docker. `deploy.sh` überträgt weiter nie `data/`; die Fassungen liegen dort und werden nicht überschrieben. Neue Codeversion und Seite: erst Code hochladen, dann veröffentlichen (neue Vorlagen wirken erst bei der nächsten Fassung). Das steht in der Anleitung.

## 3. Sichtbarkeit, Datenschutz und Sicherheit

- Aufgenommen wird ausschließlich: Titel, Bildunterschrift, Alt-Text, Ort, Jahr, Fotografenname, Bilddateien und bei Galerien mit `show_exif = 1` die Felder Kamera, Objektiv, Blende, Belichtungszeit, ISO, Brennweite und Aufnahmedatum (Positivliste im Code). GPS ist in der Datenbank nie vorhanden; ein Test sucht zusätzlich nach Koordinatenmustern im erzeugten HTML. Dateinamen der Originale (`original_name`) erscheinen nie.
- Alle Ausgaben HTML-maskiert; Seitentext durch einen eigenen kleinen Markdown-Umsetzer (Abschnitt 5) ohne rohes HTML.
- Eigene Content-Security-Policy für öffentliche Seiten: `default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'`, wie bisher, ohne `unsafe-inline`. Folge: kein `style="…"` im HTML (Abschnitt 4: Raster über Klassen). Keine externen Ressourcen. Kein Cookie auf öffentlichen Seiten (Hell/Dunkel merkt sich `localStorage`).
- **Öffentliche URL unveröffentlichter Fotos** (offener Punkt aus Schritt 3): siehe Entscheidung E4.
- Vorschau nur mit Anmeldung (Admin-Router), nie statisch erreichbar.

## 4. Gestaltung, Raster, Lightbox, Barrierefreiheit, Performance

**Theme.** Eigene Datei `public/assets/css/site.css` (der Admin behält `app.css`; ein Umschalter für zwei Designs gibt es öffentlich nicht). Farben und Schriften der Designsprache (Variablen, hell und dunkel nach Systemeinstellung, kleiner Umschalter ☀/☾ in der Kopfleiste, gemerkt in `localStorage`; ein kleines blockierendes Skript im `<head>` setzt das Schema vor dem ersten Zeichnen, gegen Aufblitzen). Rahmen im Apple-Stil wie im Plan: Kacheln 14 bis 18 px Radius mit weichem Schatten, Hero 28 px, Knöpfe Pillen, Kopfleiste halbtransparent mit `backdrop-filter`, kurze Bewegungen, bei „Bewegung reduzieren“ keine. Schriften lokal (Instrument Serif, Inter, Archivo für Knöpfe, Geist Mono für Angaben), nur die tatsächlich benutzten Schnitte werden geladen, `font-display: swap`. Kopfmarke: Wortmarke aus dem Seitennamen in Instrument Serif; Logo-Dateien der Installation siehe E6. Fußzeile: Seitenname, Links auf Impressum/Datenschutz, Wortmarke luro („Website: luro“). Siegel kompakt bleibt auf der Admin-Anmeldeseite.

**Raster mit gleich hohen Zeilen ohne Bibliothek und ohne Skript.** Reines CSS (Flex): Jede Kachel bekommt eine Klasse für ihr Seitenverhältnis, auf 0,01 gerundet und zwischen 0,50 und 3,00 begrenzt (`r85`, `r150` …); eine feste Datei mit diesen Regeln liegt im Theme und setzt `--ar`. Die Kachel hat `flex: calc(var(--ar) * 100) 1 calc(var(--ar) * var(--row-h))`, das Bild füllt sie mit `aspect-ratio`. Weil Breite und Wachstum proportional zum Seitenverhältnis sind, haben alle Kacheln einer Zeile dieselbe Höhe; ein leeres letztes Element mit großem `flex-grow` verhindert, dass die letzte Zeile gestreckt wird. Zielhöhe `--row-h`: 160 px (klein), 220 px (mittel), 260 px (groß) per Medienabfrage. Kein Layoutsprung: `width`/`height`-Attribute und `aspect-ratio` sind im HTML gesetzt. Vorteil gegenüber Berechnen beim Veröffentlichen: stimmt bei jeder Breite, auch beim Drehen des Geräts. Platzhalter beim Laden: Kachelfläche in `--soft`. Der Unschärfe-Platzhalter aus Schritt 3 steht nur beim Hero (ein `data:`-Bild darunter); im Raster würde er bei großen Galerien das HTML um mehrere hundert KB aufblähen.

**Bilder.** `<picture>` mit `srcset`/`sizes`, Reihenfolge AVIF, WebP, JPEG, jeweils nur Breiten, die als Variante existieren. `loading="lazy"` und `decoding="async"` im Raster, Hero mit `fetchpriority="high"`. Bilder in Rastern nutzen die Breiten 480/960, die Lightbox 1600/2400.

**Lightbox** (`public/assets/js/site.js`, rund 250 Zeilen, ohne Fremdbibliothek). Progressive Verbesserung: Ohne Skript öffnet die Kachel die große Datei. Mit Skript:
- Dialog-Overlay, schwarzer Grund, Bild randlos; wählt aus dem `srcset` die passende Breite; lädt Nachbarbilder vor.
- Tastatur: `←`/`→` blättern, `Esc` schließt, `Pos1`/`Ende`, `I` schaltet Bildinfo; Tab bleibt im Dialog (Fokusfalle), beim Schließen springt der Fokus zur Kachel zurück; Seite dahinter ist `inert`.
- Wischen links/rechts blättert, Wischen nach unten schließt; Pfeilknöpfe (44 px) für Maus; Zähler „3 / 24“ als `aria-live`.
- Bildinfo: Titel, Bildunterschrift, Ort · Jahr, Fotograf, bei `show_exif` die freigegebenen Angaben in Geist Mono. Die Daten stehen als `data-`Attribute an den Kacheln (kein eingebettetes JSON, keine zweite Quelle).
- Adresse `#foto-<id>` wird beim Blättern gesetzt (`replaceState`) und beim Laden ausgewertet.
- Bei „Bewegung reduzieren“ keine Überblendung.

**Galerien-Filter** (gleiche Datei): Auswahlfelder Jahr, Ort, Fotograf (echte `<select>`, beschriftet), „Zurücksetzen“, Trefferzahl als `aria-live`. Die Kachel trägt `data-jahre`, `data-orte`, `data-fotografen`. Ohne Skript sind die Filter ausgeblendet (`<noscript>` nicht nötig; sie werden erst vom Skript eingeblendet).

**Barrierefreiheit.** `lang="de"`, Sprunglink „Zum Inhalt“, Landmarken (`header`, `nav`, `main`, `footer`), eine `h1` je Seite, Alt-Text aus der Pflichtangabe (Fotos ohne Alt-Text kommen wegen der Sperre nicht auf die Seite; im Vorschau-Modus Warnleiste), sichtbarer Fokus, Kontrast AA in hell und dunkel (Test mit berechneten Kontrasten für die benutzten Farbpaare), Ziele mindestens 44 px, Hell/Dunkel und Filter per Tastatur bedienbar, `prefers-reduced-motion`. Lighthouse-Barrierefreiheit und -Leistung werden im Schritt 8 gemessen; hier wird nur darauf gebaut.

**Performance und Caching.** Seiten klein (kein Blur im Raster, keine Fremdskripte), HTML komprimiert durch den Webserver (`mod_deflate`, in `.htaccess`), `site.css` und `site.js` mit Versions-Anhang (`?v=`, wird beim Bauen gesetzt) und `Cache-Control` für `/assets/` lang, `/media/` unveränderlich (`max-age=31536000, immutable`; sicher, weil Dateinamen Zufallskennung plus Breite enthalten; wenn später „Drehen“ dieselbe Datei neu schreibt, bekommt die Variante dann einen neuen Namen, das ist in Schritt 4b zu beachten). HTML kurz und mit `ETag` revalidierbar.

## 5. Einfache Seiten

Neue Tabelle `pages` (Migration 4, additiv): `id, slug UNIQUE, title, body, status draft|published, nav ('main'|'footer'|'none'), sort_order, created_at, updated_at`. Dazu `site_settings(k, v)` für Seitenname, Hero-Text und Hero-Foto.

- **Admin „Seiten“** (`/admin/seiten`): Liste, Neu, Bearbeiten, Reihenfolge, Vorschau, Löschen (mit Rückfrage), Status. Beim ersten Öffnen bietet der Admin vier Vorlagen an („Über uns“, „Fotografen“, „Impressum“, „Datenschutz“) mit Hinweistext, keine fertigen Rechtstexte. Impressum und Datenschutz liefert Lutz bzw. br-foto (Inhalt gehört zur Installation, nicht ins Repo).
- **Editor.** Ein Textfeld mit kleiner Markdown-Teilmenge: Absätze, `##`/`###`, Fett, Kursiv, Listen, Links (`http`, `https`, `mailto`, interne Adressen), Zitat, Trennlinie. Eigener Umsetzer (rund 120 Zeilen), nimmt kein rohes HTML an, maskiert alles, Links mit `rel="noopener"`, externe mit `target` aus. Daneben Vorschau per Knopf (Vorschau-Seite, kein Skript nötig). Kurze Hilfe unter dem Feld. Warum Markdown statt Wortverarbeitung im Browser: kein Skript-Editor mit Fremdcode, kein Risiko durch eingeschleustes HTML, genügt für Fließtext; die zweite, nicht-technische Person bekommt die Hilfe dazu.
- **Navigation:** Kopfleiste zeigt „Galerien“ und alle Seiten mit `nav = main`; Fußzeile zeigt `nav = footer`.
- **Fotografen:** Eine normale Seite je Person (Text), keine automatische Seite. Die Fotografennamen aus Schritt 4 erscheinen als Filter in der Übersicht und in der Bildinfo.
- Bilder und PDF-Anhänge (Flyer) in Seiten: nicht in Schritt 5, siehe E8.
- Alt-Text-Pflicht gilt für Fotos; Seiten ohne Bilder haben nichts dergleichen.

## 6. Bauplan nach Freigabe

1. Migration 4 (`pages`, `site_settings`), Dienste `Site/Pages`, `Site/Settings`, Markdown-Umsetzer, Tests.
2. `Site/Renderer` (Vorlagen, Raster-Klassen, `picture`-Ausgabe, Positivliste), `Site/Builder` (Fassungen, Manifest, Umschalten, Rückgängig, Aufräumen, Sicherung, Sperre), `Site/Server` (Ausliefern aus der aktiven Fassung), Tests (u. a. GPS-Suche, Papierkorb-Fotos, Entwürfe, atomares Umschalten, Rückgängig, Ausliefern ohne Datenbank).
3. Admin: Seiten, Einstellungen, Veröffentlichen, Vorschau, Navigation, Hinweis „unveröffentlichte Änderungen“.
4. Theme (`site.css`, `site.js`, Raster-Regeln), lokal im Browser prüfen (hell/dunkel, Telefon/Tablet/Desktop, Tastatur, Wischen).
5. Alle bisherigen Tests und neue grün; Commit, Push, Bericht. Nichts hochladen.

## 7. Entscheidungen für Lutz

| Nr. | Frage | Empfehlung |
|---|---|---|
| E1 | **Fassungen und Umschalten:** aktive Fassung in `data/site/releases/<ID>/` mit Zeiger `current`, PHP liefert die fertigen Dateien aus (A), oder fertige Dateien direkt in den Webroot kopieren, Apache liefert (B)? | **A.** Umschalten und Rückgängig sind eine einzige atomare Dateioperation, im Webroot bleiben nie halbe Stände oder Altlasten stehen. Kosten: ein PHP-Start je Seitenaufruf (wenige Millisekunden, ohne Datenbank), mit `ETag`/Cache. B wäre schneller, aber bei Abbruch inkonsistent und das Zurücksetzen müsste Dateien einzeln wiederherstellen. |
| E2 | **Raster:** reines CSS-Flex (gleich hohe Zeilen bei jeder Breite, kein Skript, Klassen für Seitenverhältnisse auf 0,01 gerundet) oder serverseitig feste Zeilen beim Veröffentlichen? | **CSS-Flex.** Passt zur Content-Security-Policy ohne `unsafe-inline`, kein Sprung beim Drehen des Geräts. Bildern mit extremen Formaten (breiter als 3:1) werden minimal beschnitten. |
| E3 | **Seiteneditor:** Markdown-Teilmenge im Textfeld (empfohlen) oder ein Editor mit Schaltflächen (Fremdcode, deutlich mehr Aufwand und Risiko)? | **Markdown-Teilmenge** mit Hilfe und Vorschau, kein rohes HTML. Ein Schaltflächen-Editor kann später in Phase 2 nachgerüstet werden. |
| E4 | **Unveröffentlichte Fotos über ihre Adresse:** Bilddateien in `media/` sind per 24-stelliger Zufallskennung abrufbar, auch wenn das Foto in keiner veröffentlichten Galerie liegt (z. B. Entwurf oder Fassung zurückgesetzt). Akzeptieren oder strenger? | **Akzeptieren.** Die Kennung ist nicht erratbar und taucht in keiner öffentlichen Seite auf. Strenger hieße: Entwurfsbilder über PHP mit Anmeldung ausliefern und beim Veröffentlichen kopieren, deutlich mehr Aufwand und Last. In der Datenschutzerklärung ist das kein Thema, solange die Adressen nicht weitergegeben werden. Papierkorb-Fotos sind weiter sofort weg (Schritt 4). |
| E5 | **Foto im Papierkorb, aber noch auf der Live-Seite:** Die Varianten werden sofort gelöscht (Entscheidung E4 in Schritt 4), die Live-Seite zeigt dort bis zum nächsten Veröffentlichen ein fehlendes Bild. | **So lassen**, aber der Admin warnt beim Löschen („Dieses Foto ist auf der Live-Seite sichtbar. Danach neu veröffentlichen.“) und zeigt „unveröffentlichte Änderungen“. Alternative: Varianten von Live-Fotos erst beim nächsten Veröffentlichen löschen, kompliziert. |
| E6 | **Logo und Marke br.foto:** Das Repo enthält keine Installationsdateien. Die Kopfmarke ist zunächst eine Textmarke aus dem Seitennamen (Instrument Serif, Punkt in Terrakotta). Liegen `public/brand/logo.svg` (hell), `logo-dark.svg` und `favicon.svg` vor, werden sie statt der Textmarke benutzt. Die B3-Dateien (liegen in `~/Nextcloud/Documents/BR-FOTO/Logo-neu/`) legst du fürs Testen selbst in diesen Ordner ab, im Repo `br-foto` später mit. | **So.** Das System bleibt personenneutral, die Marke kommt aus dem Installationsordner. Ich teste mit einer Platzhalter-Textmarke. |
| E7 | **Hero-Text und Seitenname:** Eingabe unter „Seite → Einstellungen“ (Seitenname, Kurztext, Hero-Foto) statt in der `.env`. | **Ja**, die zweite Person soll den Text selbst ändern können. `LK_SITE_NAME` bleibt Vorgabewert. |
| E8 | **Bilder und PDF (Flyer) in Seiten:** in Schritt 5 weglassen und für die Ausstellungsseite später (Schritt 4b oder Phase 2 „Ausstellungen“) einplanen? | **Weglassen.** Ausstellungen sind laut Auftrag nicht Teil von Schritt 5, und der Flyer gehört zum Typ „Ausstellung“. Bis dahin Links auf Galerien und externe Adressen im Seitentext. |
| E9 | **EXIF öffentlich** (Plan 12.8): bei `show_exif` Kamera, Objektiv, Blende, Zeit, ISO, Brennweite, Aufnahmedatum, oder kleinerer Satz? | **Alle diese sieben**, je Galerie abschaltbar wie bisher; GPS nie. |
| E10 | **Seitenschalter für Besucher:** Hell/Dunkel mit Umschalter in der Kopfleiste (Standard: Systemeinstellung), merkt sich per `localStorage` ohne Cookie. | **So.** Kein Cookie, keine Einwilligung nötig. |

Nach der Freigabe (E1 bis E10 wie empfohlen oder mit deinen Änderungen) baue ich in der Reihenfolge von Abschnitt 6.
