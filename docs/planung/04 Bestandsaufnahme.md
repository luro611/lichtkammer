# Bestandsaufnahme (Schritt 0)

Stand: 15.11.2026. **Teil A (WordPress) ist fertig, Teil B (all-inkl-Server) wartet auf FTP-Zugang und Freigabe für eine Testdatei.**

## A. WordPress br-foto.com (per REST-API, nur lesend)

- **1.158 Mediendateien:** 1.136 JPEG, 20 PNG, 1 TIFF, 1 PDF (Flyer).
- **Größe der ausgelieferten Fassung:** meist lange Kante 1.900 px (917 Bilder), 96 Bilder um 1.000 px, 5 um 600 px.
- **116 Bilder mit langer Kante 2.560 px** (`-scaled`-Fassung von WordPress). Für **114** davon liefert die API das **ungeskalte Original** (`original_image`), erreichbar unter derselben Adresse ohne `-scaled`. Beispiel: `DSC04334.jpg` ca. 4,1 MB statt 2.560 px (HTTP 200). Das sind die größten Quellen, die WordPress hergibt.
- **Folgerung:** Für etwa 90 % der Bilder ist 1.920 px die größte verfügbare Auflösung. Die Varianten 2400 px aus dem Plan (Abschnitt 4) sollten dort nicht hochskaliert werden; die Pipeline erzeugt nur Varianten bis zur Originalbreite. Bessere Originale vom NAS (Lutz: „teilweise vorhanden“) ersetzen die WordPress-Fassungen später im Import.
- 12 Seiten à 100 Einträge in der Mediathek (`x-wp-total: 1158`).

## B. Server all-inkl (Konto A)

Testinstallation unter **`lichtkammer.br-foto.com`** (statt `neu.br-foto.com` aus Plan 03). Zugangsdaten stehen nur in `.env` und im Schlüsselbund, nicht im Repo.

| Punkt | Ergebnis |
|---|---|
| FTPS-Zugang | funktioniert (TLS erzwungen, Zertifikat geprüft), nur lesend getestet |
| Ordner über dem Webroot | **nicht erreichbar.** Der FTP-Benutzer ist auf den Ordner der Subdomain beschränkt: Die FTP-Wurzel ist zugleich der Webroot (enthält nur eine `index.htm`, 85 KB, Platzhalter von all-inkl). `app/`, `data/` und `originals/` können deshalb nicht außerhalb liegen. |
| Folgerung | Diese Ordner liegen **im** Webroot und müssen per `.htaccess` (`Require all denied`) gesperrt werden; das ist zu testen (Zugriff von außen muss 403 liefern). Alternative: im KAS die Subdomain auf einen Unterordner (z. B. `public/`) zeigen lassen, dann liegen die übrigen Ordner daneben. Empfehlung: das im KAS einstellen, falls der Ziel-Ordner frei wählbar ist. Gilt später ebenso für `br-foto.com`. |
| Subdomain | `neu.br-foto.com` wird nicht verwendet; es gilt `lichtkammer.br-foto.com` |
| PHP-Version, Imagick/GD, AVIF, HEIC, pdo_sqlite | offen, braucht Testdatei |
| upload_max_filesize, post_max_size, memory_limit, max_execution_time | offen, braucht Testdatei |
| Schreibrechte | offen, braucht Schreibtest (Testdatei) |

### Noch nötig
Ausdrückliche Freigabe von Lutz, eine `phpinfo`-Testdatei (plus einen kurzen Schreibtest und AVIF/HEIC-Prüfung) auf `lichtkammer.br-foto.com` hochzuladen. Die Dateien werden direkt nach dem Auslesen gelöscht. Zusätzlich im KAS prüfen, ob der Ziel-Ordner der Subdomain frei wählbar ist.
