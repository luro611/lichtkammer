# Erster Start auf dem Testserver (lichtkammer.br-foto.com)

Stand: 15.11.2026, Version 0.1.0-dev. Gilt für die erste Einrichtung auf all-inkl (nur FTPS, kein SSH).
Alle Werte unten sind Platzhalter. **Echte Schlüssel und Token stehen nie im Repository, nie im Chat, nie in `deploy.sh`.**

## Ablauf in Kürze

1. Code hochladen (nach Probelauf und Freigabe): `./deploy.sh --jetzt`. Das überträgt nur `public/` und `app/`, nie `.env`, nie Daten.
2. `app/.env` lokal anlegen und per FTPS in den Ordner `app/` auf dem Server legen (Schritt 2 bis 4).
3. `https://lichtkammer.br-foto.com/admin/setup` aufrufen, erstes Konto mit Passwort und TOTP einrichten.
4. `LK_SETUP_TOKEN` aus der Server-`.env` löschen.

## 1. Schlüssel und Token lokal erzeugen

Im Terminal auf dem Mac, Ausgabe nur in den Passwort-Manager bzw. in die Datei, nicht in den Chat:

```bash
openssl rand -base64 32   # → LK_APP_KEY (genau 32 Byte, Base64)
openssl rand -hex 24      # → LK_SETUP_TOKEN (48 Zeichen, Pflicht: mindestens 32)
```

- **`LK_APP_KEY` zuerst in den Passwort-Manager** (getrennt von jeder Datenbank-Sicherung). Geht er verloren, sind TOTP-Geheimnisse und Wiederherstellungsdaten nicht mehr lesbar. Nie ändern ohne Plan (Rotation: `LK_APP_KEY_PREVIOUS`).
- `LK_SETUP_TOKEN` gilt nur für die Ersteinrichtung und wird danach gelöscht.
- `LK_RESET_TOKEN` jetzt **nicht** setzen; nur im Notfall (Administrator ausgesperrt).

## 2. Die Datei `.env` anlegen (nicht im Projektordner)

Die Datei in einem Ordner **außerhalb** des Repositories erstellen, z. B. `~/Desktop/lk-env/.env`. Damit kann sie nicht versehentlich committet oder von `deploy.sh` erfasst werden (`deploy.sh` schließt `.env` ohnehin aus, und `app/.env` steht in `.gitignore`).

```
LK_ENV=production
LK_BASE_URL=https://lichtkammer.br-foto.com
LK_SITE_NAME=Lichtkammer
LK_NOINDEX=1
LK_APP_KEY=<hier den Wert aus Schritt 1>
LK_SETUP_TOKEN=<hier den Wert aus Schritt 1>
LK_DATA_DIR=/www/htdocs/v119013/lichtkammer-data
```

- `LK_BASE_URL` ohne Schrägstrich am Ende, mit `https://` (sonst arbeiten die Cookies nicht, `__Host-`-Präfix).
- `LK_NOINDEX=1` sperrt Suchmaschinen für den Testserver.
- `LK_DATA_DIR` zeigt **oberhalb** des Webroots (Webroot ist `/www/htdocs/v119013/lichtkammer.br-foto.com/`). Der Ordner `lichtkammer-data` liegt neben den Domain-Ordnern; Lichtkammer legt ihn beim ersten Start selbst an (PHP darf dort schreiben, FTPS nicht). Der Pfad stammt aus `04 Bestandsaufnahme.md`; wenn die Fehlerseite etwas anderes meldet, passt Claude ihn mit dir an.
- Die Datei darf keine Anführungszeichen-Überraschungen enthalten: `NAME=wert`, eine Zeile je Wert, keine Leerzeichen um das `=`.

## 3. Datei auf den Server legen

Die `.env` kommt **nur** nach `app/.env` auf dem Server (also `…/lichtkammer.br-foto.com/app/.env`). Eine `.env` im Webroot wird von Lichtkammer absichtlich nicht gelesen und im Admin gemeldet.

Wichtig: `./deploy.sh --jetzt` vorher ausführen, damit der Ordner `app/` existiert (oder den Ordner selbst anlegen).

Mit `lftp` (Passwort kommt aus dem Schlüsselbund, wird nicht angezeigt, `deploy/.env` liefert Host und Benutzer):

```bash
cd ~/Desktop/lk-env
source ~/Documents/Projekte/lichtkammer/deploy/.env
export LFTP_PASSWORD=$(security find-generic-password -a "$FTP_USER" -s "web-ftp-$DOMAIN" -w)
lftp -c "set ftp:ssl-force true; set ftp:ssl-protect-data true; set ssl:verify-certificate true; open --user $FTP_USER --env-password ftp://$FTP_HOST; put -O /app .env; chmod 600 /app/.env"
unset LFTP_PASSWORD
```

Alternativ mit einem FTP-Programm (Cyberduck, Transmit): per FTPS verbinden, in den Ordner `app/` wechseln, `.env` hochladen, Rechte auf 600 stellen, falls das Programm es anbietet. Nach dem Hochladen die lokale `.env` löschen, nachdem der Schlüssel im Passwort-Manager liegt.

Prüfung, dass die Datei nicht abrufbar ist (muss einen Fehler 403 oder 404 liefern, nie den Inhalt):

```bash
curl -s -o /dev/null -w "%{http_code}\n" https://lichtkammer.br-foto.com/app/.env
```

## 4. Erster Start und Einrichtung

1. `https://lichtkammer.br-foto.com/health` zeigt die Version (`0.1.0-dev`).
2. `https://lichtkammer.br-foto.com/admin/setup` öffnen. Dort: `LK_SETUP_TOKEN`, Benutzername (a–z, 0–9, `.`, `_`, `-`, 3 bis 32 Zeichen), Passwort.
3. QR-Code mit der Authenticator-App scannen (oder den Text eingeben), Code bestätigen. Die angezeigte Serverzeit (UTC) zeigt, ob die Uhr stimmt.
4. **Wiederherstellungscodes sofort in den Passwort-Manager** (werden nur einmal gezeigt).
5. **`LK_SETUP_TOKEN` aus `app/.env` auf dem Server löschen** (Datei herunterladen, Zeile entfernen, wieder hochladen). Solange es steht, zeigt der Admin ein Warnband.

Erscheint stattdessen eine Fehlerseite („Konfiguration“), steht dort der Grund (z. B. Schlüssel falsch, Datenordner nicht anlegbar). Dann nichts raten, sondern Claude die Meldung (ohne Geheimnisse) geben.

## 5. Wenn der Datenordner außerhalb des Webroots nicht anlegbar ist

Zeigt die Fehlerseite, dass `LK_DATA_DIR` nicht angelegt oder beschrieben werden kann, gibt es die Ausnahme

```
LK_DATA_DIR=/www/htdocs/v119013/lichtkammer.br-foto.com/data
LK_ALLOW_DATA_IN_WEBROOT=1
```

**Das ist nicht empfohlen**, nur für den Testserver und nur nach Absprache. Ehrlich zum Risiko:

- SQLite-Datei, Sitzungen, Protokoll und Bildverknüpfungen liegen dann im öffentlichen Webroot. Verhindert wird ein Abruf nur durch Sperrregeln: die `.htaccess` im Webroot (`data` und `*.sqlite`/`*.db` werden mit 403 beantwortet) und eine eigene `.htaccess` (`Require all denied`) samt `index.html` im Datenordner. Funktioniert eine Regel nicht (andere Serverkonfiguration, `AllowOverride` geändert, Dateiname wird nicht erfasst), ist die Datenbank **herunterladbar**.
- Die Datenbank enthält Passwort-Hashes und verschlüsselte TOTP-Geheimnisse; ohne `LK_APP_KEY` sind Letztere nicht lesbar, aber die Hashes ließen sich offline angreifen.
- Der Admin zeigt dann dauerhaft eine Warnung.
- Pflichtprüfung nach dem Start (muss 403 oder 404 liefern, nie eine Datei):

```bash
curl -s -o /dev/null -w "%{http_code}\n" https://lichtkammer.br-foto.com/data/
curl -s -o /dev/null -w "%{http_code}\n" https://lichtkammer.br-foto.com/data/lichtkammer.sqlite
```

- Die Ausnahme gehört nicht in die Produktion. Vor einem Live-Betrieb ist ein Datenordner außerhalb des Webroots oder ein anderer Weg (z. B. Zielordner der Subdomain auf `public/` stellen, falls im KAS möglich) zu klären.

## 6. Gemeinsam genutzter Server: Vertrauensannahme

Alle Domains des Kontos laufen unter demselben Systembenutzer, `open_basedir` ist leer. Das noch laufende WordPress von br-foto.com könnte `app/.env` und die SQLite-Datei lesen. Gegen diese Lücke hilft kein Code: Der Testserver ist daher nur so sicher wie das WordPress daneben. Für den Echtbetrieb WordPress zeitnah entfernen (siehe `05 Entwurf Anmeldung.md`, Abschnitt 2).

## Was `deploy.sh` nie überträgt

`.env`, `data/`, `originals/`, `media/` (nur die PHP-Sperre `media/.htaccess`), Tests, Planung, Dockerdateien, `deploy/`. Es löscht auf dem Server nichts.
