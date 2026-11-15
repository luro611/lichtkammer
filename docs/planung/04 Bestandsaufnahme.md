# Bestandsaufnahme (Schritt 0)

Stand: 15.11.2026. **Teil A (WordPress) ist fertig, Teil B (all-inkl-Server) wartet auf FTP-Zugang und Freigabe für eine Testdatei.**

## A. WordPress br-foto.com (per REST-API, nur lesend)

- **1.158 Mediendateien:** 1.136 JPEG, 20 PNG, 1 TIFF, 1 PDF (Flyer).
- **Größe der ausgelieferten Fassung:** meist lange Kante 1.900 px (917 Bilder), 96 Bilder um 1.000 px, 5 um 600 px.
- **116 Bilder mit langer Kante 2.560 px** (`-scaled`-Fassung von WordPress). Für **114** davon liefert die API das **ungeskalte Original** (`original_image`), erreichbar unter derselben Adresse ohne `-scaled`. Beispiel: `DSC04334.jpg` ca. 4,1 MB statt 2.560 px (HTTP 200). Das sind die größten Quellen, die WordPress hergibt.
- **Folgerung:** Für etwa 90 % der Bilder ist 1.920 px die größte verfügbare Auflösung. Die Varianten 2400 px aus dem Plan (Abschnitt 4) sollten dort nicht hochskaliert werden; die Pipeline erzeugt nur Varianten bis zur Originalbreite. Bessere Originale vom NAS (Lutz: „teilweise vorhanden“) ersetzen die WordPress-Fassungen später im Import.
- 12 Seiten à 100 Einträge in der Mediathek (`x-wp-total: 1158`).

## B. Server all-inkl (Konto A)

| Punkt | Ergebnis |
|---|---|
| Domain `br-foto.com` | Apache, löst auf 85.13.150.49 auf, antwortet (HTTP/2) |
| Subdomain `neu.br-foto.com` | löst auf dieselbe IP auf und antwortet mit **301** (Ziel nicht ausgewertet). Ob sie im KAS als eigene Subdomain mit eigenem Ordner angelegt ist, ist **noch offen**. |
| FTP-Zugang | **fehlt.** Im Schlüsselbund gibt es keinen Eintrag `web-ftp-br-foto.com`; im Repo `br-foto` gibt es keine `.env`. |
| PHP-Version, Imagick/GD, AVIF, HEIC, pdo_sqlite | offen (braucht Testdatei) |
| upload_max_filesize, post_max_size, memory_limit, max_execution_time | offen |
| Schreibrechte oberhalb des Webroots | offen (per FTPS prüfbar) |

### Nötig von Lutz
1. FTP-Benutzer nur für br-foto.com im KAS anlegen, Host und Benutzer nennen und das Passwort selbst in den Schlüsselbund legen: `security add-generic-password -U -a <FTP_USER> -s web-ftp-br-foto.com -w`
2. Ausdrückliche Freigabe, eine `phpinfo`-Datei auf `neu.br-foto.com` hochzuladen (wird sofort nach dem Auslesen wieder gelöscht).
3. Subdomain `neu.br-foto.com` im KAS mit eigenem Ordner anlegen bzw. bestätigen, falls noch nicht geschehen.
