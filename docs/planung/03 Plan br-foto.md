# Plan: Lichtkammer für br-foto.com

Stand: 15.11.2026. Entwurf zur Freigabe, noch kein Code. Ersetzt in Teilen das Ausgangskonzept (`01 Konzept PHOTO-CMS.md`), siehe Abschnitt 2.

## 1. Auftrag in einem Satz

Name des Systems: **Lichtkammer** (früher PHOTO-CMS), Lizenz **AGPL-3.0-or-later**, Open Source.

br-foto.com wird von WordPress (Catch Themes „Fotografie“) auf ein eigenes, schlankes PHP-System bei all-inkl.com umgestellt: öffentlich so klar und ruhig wie rothe.it, mit Bildverwaltung für genau zwei Personen, Anmeldung mit Passwort plus TOTP und optional Passkey.

## 2. Was sich gegenüber dem Ausgangskonzept ändert

| Punkt | Konzept 01 | Neu |
|---|---|---|
| Wo läuft der Admin? | Homeserver, Build dort, Upload nach all-inkl | **Alles auf all-inkl**: Admin, Bildverarbeitung, Veröffentlichen. Die zweite Person braucht den Admin von überall, ohne VPN. |
| Server-Zugang | SSH/rsync angenommen | **Nur FTPS** (Konto A hat kein SSH, siehe rothe-it). Deployment des Codes wie bei rothe.it mit `lftp` und Probelauf. |
| Stack | PHP oder Python offen | **PHP 8.3+ und SQLite**, ohne Framework, ohne Composer auf dem Server (Bibliotheken werden lokal eingebunden und mit hochgeladen). |
| Media-DB | Anbindung geprüft | **Keine Anbindung.** Eigene SQLite-Datei, MediaDB bleibt unberührt. |
| Homeserver | Arbeitsplatz | Nur noch **Sicherung**: holt nachts Datenbank und Originale per FTPS ab. |

Die Ideen aus Konzept 01 (idempotente Bildpipeline, Alt-Text-Pflicht, Release-IDs, Rollback, Themes) bleiben, werden aber auf diese Umgebung zugeschnitten.

## 3. Bestand br-foto.com (gelesen am 15.11.2026)

- WordPress mit Theme „Fotografie“ von Catch Themes, REST-API offen lesbar.
- **1.158 Mediendateien**, 9 Seiten, 11 „Featured Content“-Galerien plus einige Portfolio-Galerien (Kiew 2007, Heidelberg 2017, Wuppertal 1979/80, Island 2022, Berlin, Lissabon, Tokio, Amsterdam, Zollverein, Venedig, Schwetzingen, Aserbaidschan, Seidenstraße).
- Seiten: Start, Unsere Ausstellungen (mit Flyer-PDF und Ausstellungsrundgang, ca. 80 Bilder), Über uns, je eine Seite pro Fotograf, Impressum, Datenschutz.
- Bilder liegen meist als JPEG mit 1920 × 1080 und etwa 600 KB vor. Ob es größere Originale gibt, klären wir in Schritt 0.

## 4. Architektur

```
br-foto.com (all-inkl, Konto A)
├── public/            ← Webroot der Domain (im KAS auf diesen Ordner zeigen lassen)
│   ├── index.php      ← kleiner Router: liefert fertige HTML-Seiten aus dem Cache
│   ├── admin/         ← Bildverwaltung (nur angemeldet)
│   ├── media/         ← erzeugte Bildvarianten (öffentlich)
│   └── assets/        ← CSS, JS, Schriften (lokal, keine externen Quellen)
├── app/               ← PHP-Code, Templates, Bibliotheken (nicht öffentlich)
├── data/              ← SQLite, Sitzungen, Protokolle (nicht öffentlich)
└── originals/         ← Originalbilder, nie überschrieben (nicht öffentlich)
```

- **Öffentliche Seiten als fertiges HTML:** Beim Klick auf „Veröffentlichen“ erzeugt PHP alle betroffenen Seiten als statische Dateien. Besucher lösen damit keine Datenbankabfragen aus, die Seite bleibt schnell, sicher und auch bei Last ruhig. Das ist der Generator-Gedanke aus Konzept 01, nur direkt auf dem Webspace.
- **Entwürfe und Vorschau:** Änderungen sind erst nach „Veröffentlichen“ sichtbar. Vorher zeigt der Admin eine Vorschau genau so, wie die Seite später aussieht.
- **Liegen `app/`, `data/` und `originals/` außerhalb des Webroots?** Ob das geht, hängt davon ab, ob der FTP-Zugang über dem Domain-Ordner schreiben darf. Wenn nicht, sperrt eine `.htaccess` diese Ordner zusätzlich. Das wird in Schritt 0 geprüft.
- **Bildverarbeitung auf dem Server mit Imagick, sonst GD.** Damit PHP-Zeitlimits und Speichergrenzen nicht stören, wird jedes Bild als eigener kleiner Auftrag verarbeitet: Der Browser lädt in Stücken hoch und stößt danach Bild für Bild die Verarbeitung an, mit Fortschrittsanzeige. Fällt ein Bild aus, laufen die anderen weiter.
- **Varianten:** Breiten 480, 960, 1600 und 2400 px als WebP und JPEG, dazu AVIF, wenn der Server es kann. Außerdem ein winziger Unschärfe-Platzhalter. GPS-Daten werden aus allen öffentlichen Varianten entfernt.

## 4a. Zwei Betriebsarten: all-inkl und Docker (Entscheidung Lutz, 15.11.2026)

Lichtkammer läuft mit **demselben Code** auf all-inkl (PHP-FPM, Deployment per FTPS) und selbst gehostet im LXC (Docker Compose). Unterschiede stehen nur in der Konfiguration, nie im Code.

- **Konfigurationsschicht:** Pfade für Daten (SQLite, Sitzungen, Protokolle), Originale und erzeugte Bilder sowie die Basis-Adresse kommen aus Umgebungsvariablen oder `.env` (gleiches Schema, `.env.example` im Repo).
- **Webserver-Regeln für beide:** eine `.htaccess` (Apache). Das Docker-Image nutzt ebenfalls Apache, damit dieselben Regeln gelten (umgesetzt in Schritt 1); ein Reverse-Proxy wie Caddy oder nginx steht davor und beendet TLS. Beide liefern fertige HTML-Dateien direkt aus, geben sonst an `index.php` weiter und sperren Nichtöffentliches.
- **Keine Abhängigkeit von Cron, SSH oder Composer auf dem Server.** Bildverarbeitung und Datenbankkopie werden vom Browser angestoßen.
- **Keine festen Annahmen über Limits:** `memory_limit` und `max_execution_time` werden gelesen, Uploads gehen immer in Stücken. Imagick ist der Normalfall, GD der Ersatz (ohne AVIF).
- **HTTPS hinter Reverse-Proxy:** Einstellung „vertraue `X-Forwarded-Proto`“, damit `Secure`-Cookies und HSTS im Container stimmen.
- **PHP 8.3 bis 8.5** werden getestet; Mindestversion 8.3.
- **Docker:** `Dockerfile` (PHP 8.5 mit Imagick inkl. AVIF/HEIC, `pdo_sqlite`) und `compose.yaml` im Repo, Image `ghcr.io/luro611/lichtkammer`. Volumes für Daten, Originale und erzeugte Bilder. Bereitstellung im LXC mit Dockhand. Keine zweite Datenbank nötig (SQLite). Im Container ist `public/` der Webroot, `data/` und `originals/` liegen außerhalb.
- **Test:** Zuerst auf pve10; auf pve01 erst nach Freigabe auf GitHub.
- **Sicherung:** all-inkl per FTPS vom Homeserver (siehe Abschnitt 9, Datenordner ist per FTP nicht erreichbar, Lösung in Schritt 3 und 8); Docker per Volume-Backup.

## 5. Gestaltung

Grundlage ist die Designsprache von rothe.it und lurohome.de (`Webseiten/lurohome/src/style.css`):

- **Farben:** dieselben Variablen, hell und dunkel (`--bg #f5f2ed`, `--ink #242321`, `--accent #c75d3b` usw.). Die Fotos stehen im Mittelpunkt, Terrakotta erscheint nur als Akzent.
- **Schriften lokal:** Instrument Serif (Überschriften mit kursivem Akzentwort), Inter (Text), Archivo (Knöpfe, Beschriftungen), Geist Mono (Daten wie Ort, Jahr, Kamera).
- **Rahmen im Apple-Stil, abweichend von der allgemeinen Regel „2 px Radius“:**
  - Karten und Galerie-Kacheln mit 14 bis 18 px Radius und sehr weichem Schatten statt Haarlinie
  - Knöpfe als Pillen (980 px Radius, wie schon `.btn` auf lurohome)
  - große Flächen (Hero, Hinweise) mit 28 px Radius wie `.feature`
  - Leiste oben halbtransparent mit Unschärfe (`backdrop-filter`), wie bei Apple
  - Bilder in der Lightbox randlos, in Rastern mit leichtem Radius
  - Bewegungen kurz und weich, bei „Bewegung reduzieren“ abgeschaltet
- **Öffentliche Seiten:**
  - **Start:** großes Titelbild und darunter die Galerien als Kacheln
  - **Galerien:** Übersicht, filterbar nach Jahr, Ort und Fotograf
  - **Galerie:** Raster mit gleich hohen Zeilen (Justified), Lightbox mit Tastatur, Wischgesten und Bildinfo
  - **Ausstellungen, Fotografen, Über uns, Impressum, Datenschutz**
- **Marke:**
  - Öffentlich bleibt BR-Foto die Marke, neu gesetzt in Instrument Serif statt des Kamera-Logos.
  - luro-Wortmarke klein in der Fußzeile („Website: luro“), Siegel kompakt auf der Admin-Anmeldeseite. Siehe offene Frage 4.

## 6. Anmeldung und Sicherheit (Risiko hoch)

- **Nur zwei Konten, keine Selbstregistrierung.** Das erste Konto legt eine einmalige Einrichtungsseite an, die sich danach selbst sperrt.
- **Passwort:** Argon2id, mindestens 12 Zeichen, Prüfung gegen eine lokale Liste häufiger Passwörter.
- **TOTP (Pflicht):**
  - nach RFC 6238 und kompatibel mit jeder Authenticator-App (Apple Passwörter, 2FAS, Google Authenticator)
  - Einrichtung per QR-Code, der lokal als SVG erzeugt wird
  - zehn Wiederherstellungscodes zum Ausdrucken
  - Schutz gegen die doppelte Verwendung eines Codes
- **Passkey (empfohlen, eigener Schritt):**
  - WebAuthn mit der kleinen MIT-Bibliothek `lbuchs/WebAuthn`, die ohne Composer läuft
  - Ein Passkey ersetzt dann Passwort und TOTP in einem Schritt (Face ID / Touch ID / Windows Hello).
  - Passwort plus TOTP bleibt als Ersatzweg erhalten.
  - Aufwand: etwa ein Arbeitsschritt mehr, technisch gut machbar, weil die Seite ohnehin HTTPS hat.
- **Schutz im Betrieb:**
  - Sperre und Verzögerung nach Fehlversuchen
  - CSRF-Schutz bei allen Formularen
  - Sitzungs-Cookies mit `Secure`, `HttpOnly` und `SameSite=Strict`; Abmeldung nach Inaktivität
  - Content-Security-Policy, HSTS
  - Protokoll aller Anmeldungen und Änderungen, für beide Konten im Admin sichtbar
- **Uploads:** Der Dateityp wird am Inhalt erkannt, nicht an der Endung. Erlaubt sind nur JPEG, PNG, WebP und HEIC (HEIC nur, wenn der Server es lesen kann). Dateinamen werden neu vergeben, im Upload-Ordner läuft kein PHP.
- Prüfung durch Fable (bzw. Opus mit `/code-review max`) vor dem Bauen und vor dem Livegang, wie in der allgemeinen Modellregel vorgesehen.

## 7. Bildverwaltung: Funktionsumfang

**Kern (Phase 1)**
- **Galerien:** anlegen, umbenennen, beschreiben, Titelbild per Klick, Reihenfolge per Ziehen, Entwurf oder veröffentlicht
- **Fotos hochladen:** viele auf einmal per Ziehen oder Dateiauswahl, mit Fortschritt. Doppelte Fotos werden über eine Prüfsumme erkannt und gemeldet.
- **Sortieren:** per Ziehen; zusätzlich automatisch nach Aufnahmedatum oder Dateiname
- **Fotos bearbeiten:**
  - Titel, Bildunterschrift und Alt-Text (Pflicht, vor dem Veröffentlichen geprüft)
  - Fotograf (eine der beiden Personen), Ort und Jahr
  - Mehrfachauswahl, um diese Felder für viele Fotos auf einmal zu setzen
- **EXIF:** Kamera, Objektiv, Blende, Zeit, ISO und Datum werden ausgelesen. Je Galerie lässt sich einstellen, ob sie öffentlich erscheinen. GPS wird nie öffentlich gezeigt.
- **Bild drehen, Bildausschnitt für Kacheln** (Fokuspunkt antippen statt zuschneiden). Das Original bleibt unverändert.
- **Ein Foto ersetzen:** Adresse, Texte und Position bleiben erhalten.
- **Papierkorb:** Gelöschtes bleibt 30 Tage wiederherstellbar.
- **Suche:** über Titel, Ort, Jahr und Galerie
- **Seiten bearbeiten:** einfacher Editor für Über uns, Fotografen, Ausstellungen, Impressum und Datenschutz, mit Bildern und PDF-Anhängen wie dem Flyer
- **Veröffentlichen:** ein klarer Knopf mit Liste der Änderungen, Fortschritt und Ergebnis. Die letzte veröffentlichte Fassung lässt sich mit einem Klick zurückholen.

**Vorschläge (Phase 2)**
- **Ausstellungen** als eigener Typ mit Datum, Ort, Flyer und Rundgang, damit vergangene und kommende Ausstellungen automatisch richtig einsortiert sind
- **Schlagworte** und eine „Alle Fotos“-Ansicht mit Filter nach Fotograf und Jahr
- **Galerie mit Passwort** für Freunde oder Leihgeber (die Seite bleibt sonst statisch, nur diese Galerie wird über PHP geschützt)
- **Diaschau-Modus** für Ausstellungsbildschirme oder Vorträge
- **Speicherübersicht:** Wie viel Platz belegen Originale und Varianten?
- **Kontaktformular** ohne Fremddienst, mit Schutz gegen Spam ohne CAPTCHA
- **Zweisprachig (DE/EN)**

**Bewusst nicht:**
- keine Kommentare
- keine Besucherstatistik mit Cookies, damit die Datenschutzerklärung kurz bleibt; höchstens die Server-Statistik von all-inkl
- kein Rechtsklickschutz, der nur stört und nichts verhindert

## 8. Umzug von WordPress

1. **Sicherung der WordPress-Seite:** Dateien per FTPS, Datenbank per phpMyAdmin im KAS. Sie wird außerhalb aller Repos abgelegt.
2. **Inhalte über die REST-API einlesen:** Ein lokales Skript lädt Galerien, Seiten, Texte und alle Bilder in größter verfügbarer Auflösung und erzeugt daraus ein Importpaket.
3. **Import im neuen Admin:** Das Paket wird per FTPS in einen Importordner gelegt, der Admin verarbeitet es Bild für Bild. Danach sehen wir alles durch, ergänzen Alt-Texte und den Fotografen.
4. **Weiterleitungen:** Alte Adressen (`/featured-content/island-2022/`, `/portfolio/…`, `/ueber-uns/` usw.) leiten mit 301 auf die neuen um, damit Links und Suchmaschinen weiter funktionieren.
5. **Testbetrieb auf `lichtkammer.br-foto.com`:** mit Passwort und `noindex`. Beide Fotografen probieren den Admin dort aus.
6. **Umschalten im KAS:** Die Domain zeigt danach auf den neuen Ordner. WordPress bleibt **höchstens 14 Tage** unverändert im alten Ordner liegen, damit wir mit einem Klick zurück können, und wird dann entfernt (Entscheidung von Lutz, 15.11.2026). Grund: Alle Domains des Kontos A laufen unter demselben Systembenutzer und `open_basedir` ist leer; ein WordPress mit einer Lücke könnte den Schlüssel (`app/.env`) und die Datenbank von Lichtkammer lesen (Gutachten `07`, Befund M8). Bis zum Entfernen Plugins und Themes aktuell halten und XML-RPC sowie die REST-Schnittstelle einschränken.

## 9. Sicherung und Betrieb

- **Nächtliche Sicherung:** Ein Dienst auf dem Homeserver holt SQLite-Datenbank, Originale und Seiteninhalte per FTPS ab (nur lesen, nie löschen) und behält 30 Tage. Passt zum rsync-Backup-Projekt.
- **Datenbank im laufenden Betrieb:** Der Admin legt vor jedem Veröffentlichen eine Kopie der Datenbank auf dem Webspace ab und behält die letzten zehn.
- **Code-Deployment:**
  - wie bei rothe.it: `deploy.sh` mit Probelauf, `--jetzt` nur nach Freigabe
  - Version = Datum
  - Passwort im Schlüsselbund, eigener FTP-Benutzer nur für br-foto.com
  - Das Deployment überträgt nur Code, nie `data/`, `originals/` oder `media/`.
- **Wöchentliche Prüfung:** br-foto wird in die bestehende „Webseiten-Wochenprüfung“ aufgenommen (erreichbar, Version, Zertifikat, Sicherung frisch).

## 10. Repositories

Vorschlag:
- `luro611/lichtkammer` (dieses Repo): das System selbst, ohne Inhalte und ohne Bezug zu Personen, später Open Source
- `luro611/br-foto` (gibt es schon): Einstellungen, Weiterleitungen, Importskript und `deploy.sh` für br-foto.com. Es spielt eine bestimmte Version von Lichtkammer ein.

## 11. Schritte

Jeder Schritt wird einzeln beauftragt, in einer eigenen, kurzen Sitzung (bauen mit Sonnet, prüfen mit Opus, Anmeldung zusätzlich mit Fable). Nach jedem Schritt Bericht und Freigabe.

| Nr. | Schritt | Ergebnis | Risiko |
|---|---|---|---|
| 0 | **Bestandsaufnahme all-inkl** | PHP-Version, Imagick/GD (AVIF? HEIC?), pdo_sqlite, Limits, Ordner über dem Webroot, Subdomain `lichtkammer.` angelegt. Dazu eine Testdatei hochladen und wieder löschen. Größte vorhandene Bildauflösung bei WordPress. | niedrig |
| 1 | **Projektgerüst** | Ordnerstruktur, Router, Templates, Design-Grundlage (Farben, Schriften, Apple-Rahmen), `.env.example`, Lizenz, erster Test, `deploy.sh` für `lichtkammer.br-foto.com`, Konfigurationsschicht, `Dockerfile`, `compose.yaml`, Webserver-Regeln für Apache und Caddy/nginx, Pfadtest für beide Betriebsarten (Abschnitt 4a) | niedrig |
| 2 | **Anmeldung** | Konten, Passwort, TOTP, Wiederherstellungscodes, Sperren, Protokoll. Vorher Detailentwurf mit Fable-Prüfung. | **hoch** |
| 3 | **Datenmodell und Bildpipeline** | SQLite-Schema mit Migrationen, Upload in Stücken, Varianten, EXIF, GPS-Entfernung, Dublettenprüfung | mittel |
| 4 | **Bildverwaltung** | Galerien, Sortieren, Titelbild, Mehrfachbearbeitung, Alt-Text-Pflicht, Papierkorb, Suche | mittel |
| 5 | **Öffentliche Seite** | Start, Galerien, Justified-Raster, Lightbox, Seiten, hell/dunkel, Vorschau, Veröffentlichen mit Rückgängig | mittel |
| 7 | **Umzug** | Importskript, Import, Weiterleitungen, Durchsicht mit beiden Fotografen auf `lichtkammer.` | mittel |
| 8 | **Härtung und Livegang** | Sicherheitsprüfung, Lighthouse, Sicherung vom Homeserver eingerichtet, Umschalten im KAS, WordPress noch als Rückweg aufbewahrt | **hoch** |
| 6 | **Passkey** | WebAuthn zusätzlich zu TOTP, mit Fable-Prüfung. **Reihenfolge geändert (Lutz, 16.11.2026): erst nach Schritt 8, also nach dem Livegang.** Bis dahin Passwort plus TOTP | **hoch** |
| 9 | **Phase 2** | Ausstellungen, Schlagworte, Galerie mit Passwort usw. nach Wunsch | – |

## 12. Offene Fragen

1. **Apple-Rahmen:** Gelten die runderen Rahmen nur für br-foto, oder soll sich die allgemeine Designregel (2 px) überall ändern?
2. **Repos:** System in `lichtkammer` und Inhalt/Betrieb in `br-foto` getrennt, wie in Abschnitt 10 vorgeschlagen?
3. **Passkey gleich mit oder nach dem Livegang?** Entschieden (Lutz, 16.11.2026): TOTP zum Start, Passkey als Schritt 6 erst nach dem Livegang (Schritt 8). Der Admin ist bis dahin nur mit Passwort und TOTP geschützt.
4. **Marke:** Öffentlich BR-Foto als Marke, luro nur als kleine Wortmarke in der Fußzeile und das Siegel auf der Anmeldeseite. Einverstanden, und ist die zweite Person damit einverstanden?
5. **Namen der Fotografen:** Schreibweise auf der neuen Seite vor dem Umzug prüfen.
6. **Welche Galerien und Seiten ziehen um?** Alles, oder wird beim Umzug ausgemistet?
7. **Gibt es bessere Originale** als die 1920-px-Fassungen bei WordPress, z. B. auf dem NAS?
8. **EXIF öffentlich:** Kamera und Objektiv zeigen ja oder nein? GPS bleibt immer verborgen.
9. **Arbeitsgerät der zweiten Person:** Mac, iPad oder Windows? Davon hängt ab, wie wir Passkeys und Uploads testen.
10. ~~Projektname~~ entschieden: Lichtkammer, AGPL-3.0-or-later (15.11.2026).
