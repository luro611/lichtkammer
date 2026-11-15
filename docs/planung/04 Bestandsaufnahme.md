# Bestandsaufnahme (Schritt 0)

Stand: 15.11.2026. Teil A (WordPress) und Teil B (Server) sind erledigt.

## A. WordPress br-foto.com (per REST-API, nur lesend)

- **1.158 Mediendateien:** 1.136 JPEG, 20 PNG, 1 TIFF, 1 PDF (Flyer).
- **Größe der ausgelieferten Fassung:** meist lange Kante 1.900 px (917 Bilder), 96 Bilder um 1.000 px, 5 um 600 px.
- **116 Bilder mit langer Kante 2.560 px** (`-scaled`-Fassung von WordPress). Für **114** davon liefert die API das **ungeskalte Original** (`original_image`), erreichbar unter derselben Adresse ohne `-scaled`. Beispiel: `DSC04334.jpg` ca. 4,1 MB statt 2.560 px (HTTP 200). Das sind die größten Quellen, die WordPress hergibt.
- **Folgerung:** Für etwa 90 % der Bilder ist 1.920 px die größte verfügbare Auflösung. Die Varianten 2400 px aus dem Plan (Abschnitt 4) sollten dort nicht hochskaliert werden; die Pipeline erzeugt nur Varianten bis zur Originalbreite. Bessere Originale vom NAS (Lutz: „teilweise vorhanden“) ersetzen die WordPress-Fassungen später im Import.
- 12 Seiten à 100 Einträge in der Mediathek (`x-wp-total: 1158`).

## B. Server all-inkl (Konto A)

Testinstallation unter **`lichtkammer.br-foto.com`** (statt `neu.br-foto.com` aus Plan 03). Zugangsdaten stehen nur in `.env` und im Schlüsselbund, nicht im Repo. Gemessen am 15.11.2026 mit einer einmaligen Testdatei (hochgeladen, per HTTP abgerufen, sofort gelöscht; Kontrolle: Ordner enthält wieder nur die `index.htm`, Abruf liefert 404).

| Punkt | Ergebnis |
|---|---|
| PHP | **8.5.12** (php-fpm), Plan verlangt 8.3+: erfüllt |
| Datenbank | `pdo_sqlite` und `sqlite3` vorhanden, SQLite **3.45.1** |
| Bildbibliotheken | **Imagick** (ImageMagick 6.9.12) mit registrierten Formaten JPEG, PNG, WEBP, **AVIF, HEIC, HEIF**, TIFF, GIF. GD 2.3.3 mit WebP, **ohne AVIF**. |
| Weitere Erweiterungen | `exif`, `fileinfo`, `mbstring`, `intl`, `sodium`, `openssl`, `curl`, `zip`, `gmp`, `bcmath`; `PASSWORD_ARGON2ID` verfügbar |
| Limits | `upload_max_filesize` 512M, `post_max_size` 512M, `memory_limit` 512M, `max_execution_time` 60 s, `max_input_time` unbegrenzt, `max_file_uploads` 20 |
| Einschränkungen | `open_basedir` leer, `disable_functions` leer |
| Speicherplatz | rund 1,5 TB frei |
| Ordner über dem Webroot | Per **FTP nicht erreichbar**: Der FTP-Benutzer ist auf den Ordner der Subdomain beschränkt (Wurzel = Webroot). **PHP kann aber** oberhalb schreiben: Docroot `/www/htdocs/v119013/lichtkammer.br-foto.com/`, der Elternordner `/www/htdocs/v119013` ist für PHP les- und schreibbar. |
| Schreibtest im Webroot | erfolgreich (Datei anlegen und löschen) |
| HTTPS für die Subdomain | **aktiv** (Let's Encrypt, gültig bis 13.02.2027, geprüft am 15.11.2026). `Secure`-Cookies, HSTS und Anmeldung sind damit testbar. |

### Folgerungen für den Plan

1. **PHP 8.5 statt 8.3:** Code auf PHP 8.3 bis 8.5 testen; die Mindestversion bleibt 8.3.
2. **Bildverarbeitung:** Imagick ist da, AVIF und HEIC sind registriert. Ob Kodieren und Dekodieren wirklich funktionieren (Delegate-Bibliotheken), prüft ein Praxistest mit echten Bildern in Schritt 3. GD fällt für AVIF aus; Fallback: WebP und JPEG.
3. **Die Zeitgrenze von 60 s und `max_file_uploads` 20** bestätigen den Plan: Hochladen in Stücken, Verarbeitung Bild für Bild.
4. **Daten außerhalb des Webroots:** FTP kann dort nichts ablegen, PHP aber schon. Vorschlag: `data/` (SQLite, Sitzungen, Protokolle) und `originals/` legt die Anwendung beim ersten Start selbst oberhalb des Webroots an. Dafür ist ein eigener Unterordner nötig (z. B. `/www/htdocs/v119013/lichtkammer-data/`), weil im Elternordner auch die Ordner anderer Domains des Kontos liegen. Zusätzlich sperrt eine `.htaccess` `app/` und alles Nichtöffentliche im Webroot. Das Deployment des Codes geht weiter per FTPS in den Webroot; der Datenordner wird nie deployt. Alternative im KAS: Zielordner der Subdomain auf `public/` setzen, falls frei wählbar (noch nicht geprüft).
5. **Wichtig:** Backups vom Homeserver per FTPS erreichen den Datenordner über dem Webroot **nicht**. Entweder ein Export in den Webroot (geschützt) oder ein Abruf über einen geschützten Admin-Endpunkt. Das ist in Schritt 3 und 8 zu entscheiden.

### Noch offen von Lutz
- Optional: im KAS prüfen, ob der Zielordner der Subdomain frei wählbar ist (z. B. `public/`).
