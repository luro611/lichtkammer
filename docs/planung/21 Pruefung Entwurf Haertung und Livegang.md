# Prüfung: Entwurf 20 „Härtung und Livegang“ (Schritt 8)

Stand: 18.11.2026. Prüfer: Opus (nur Entwurf, kein Code gebaut). Geprüft: Entwurf `20` (Commit `b906ee7`) gegen Plan `03`, `04`, `05`, `07`, `08`, `13`, `16` bis `19`, `docs/betrieb/Erster-Start-Testserver.md`, `CLAUDE.md` und den Code (`public/.htaccess`, `public/media/.htaccess`, `app/.htaccess`, `public/index.php`, `SecurityHeaders`, `Request`, `Response`, `Config`, `Preflight`, `Audit`, `Site/Server.php`, `deploy.sh`, `deploy/einrichten.sh`, `tools/release-zip.sh`, `Dockerfile`, `compose.yaml`, `docker/*`).

Gemessen wurde nur lesend: GET/HEAD an `https://lichtkammer.br-foto.com` und `https://br-foto.com`, `dig`, das TLS-Zertifikat und eine Suche in der Git-Historie. Auf keinem Server wurde etwas geändert.

## Gesamturteil

**Der Entwurf taugt nach kleinen Ergänzungen zum Bauen.** Aufbau, Prüfplan, Reihenfolge und Rückfallgedanke stimmen. Drei Befunde mit Risiko hoch müssen **vor 8b bzw. vor dem Import** geklärt sein:

1. **H1:** Die Originale liegen auf all-inkl heute im Webroot.
2. **H2:** Die Wiederherstellung auf all-inkl ist nicht ausführbar beschrieben.
3. **H3:** Beim gemeinsamen Server ist das Zeitfenster falsch gesetzt.

Ein Messpunkt löst sich auf: Die öffentlichen Seiten tragen die Sicherheitskopfzeilen schon (verifiziert).

## Befunde

### H1 (hoch, offen): Originale liegen auf all-inkl im Webroot und sind nur per `.htaccess` geschützt

- **Fundstelle:** Entwurf 2.1 (Tabelle sagte „Standard neben dem Datenordner“, korrigiert). `Config::originalsDir()` liefert ohne `LK_ORIGINALS_DIR` den Ordner `<Wurzel>/originals`. Auf all-inkl ist die Wurzel der Webroot. `deploy/einrichten.sh` und `Erster-Start-Testserver.md` setzen nur `LK_DATA_DIR`. `Preflight` prüft nur den Datenordner auf „im Webroot“, nicht die Originale.
- **Folge:** Originale mit vollem EXIF (auch **GPS**, das öffentlich nie gezeigt wird) liegen öffentlich erreichbar, falls eine `.htaccess`-Regel nicht greift. Gemessen: `/originals/` liefert heute 403, die Regel wirkt also. Das widerspricht aber der Entscheidung vom 15.11. (Nichtöffentliches außerhalb des Webroots). Außerdem kann der FTP-Benutzer die Originale lesen und **schreiben**.
- **Empfehlung (E19 neu):** **Vor dem WordPress-Import** `LK_ORIGINALS_DIR=/www/htdocs/v…/lichtkammer-originals` setzen. Die vorhandenen Test-Originale verschiebt die App (gleiches Dateisystem, `rename`). `Preflight` lehnt Originale im Webroot ab wie beim Datenordner. `einrichten.sh` schreibt die Zeile mit. Nach dem Import kostet derselbe Umzug Gigabytes; jetzt kostet er fast nichts. In 8a einplanen.

### H2 (hoch, offen): Wiederherstellung auf all-inkl so nicht ausführbar

- **Fundstelle:** 2.4 Nr. 4, 2.5, E12.
- **Problem 1:** „Sicherung einspielen“ im Admin setzt ein **angemeldetes Administratorkonto** voraus. Bei Server-Totalverlust ist die Datenbank leer, es gibt also kein Konto. Die Funktion hilft genau im Ernstfall nicht.
- **Problem 2:** Die Originale (rund 1 158 Dateien, mehrere GB) lassen sich auf all-inkl nicht zurückbringen. FTPS erreicht den Ordner über dem Webroot nicht (`04`), ein Hochladen in Stücken über den Admin ist dafür nicht gedacht. Der Entwurf nennt keinen Weg.
- **Problem 3:** Die Pflichtprobe 2.5 läuft nur in Docker auf pve10. Den all-inkl-Weg prüft sie nicht (FTPS, kein SSH, 60 s Laufzeit, Rechte), obwohl br-foto.com dort betrieben wird.
- **Empfehlung:**
  - Einspielen als **Teil der Ersteinrichtung** bauen: `/admin/setup` mit gültigem `LK_SETUP_TOKEN` bietet „Aus Sicherung wiederherstellen“ statt „Erstes Konto anlegen“.
  - Originale über einen **Bereitstellungsordner im Webroot** (wie `import/`, gesperrt, per FTPS befüllbar) zurückholen. Die App verschiebt die Dateien schrittweise und prüft dabei die SHA-256-Summen aus der Sicherungsliste.
  - Zweite Probe auf all-inkl in einer **Wegwerf-Subdomain** mit eigenem Daten- und Originalordner, nur nach Freigabe von Lutz. Das erst ist „Ohne bestandene Probe kein Livegang“.
  - E12 entsprechend umformulieren.

### H3 (hoch, offen): Gemeinsamer Systembenutzer – das Risiko besteht jetzt, nicht nach dem Umschalten

- **Fundstelle:** 0 Nr. 3, 3.7, 3.9 Nr. 1, E3 (b).
- **Problem:** Nach dem Umschalten (Weg A) zeigt keine Domain mehr auf den WordPress-Ordner. Sein PHP ist dann **nicht mehr über das Netz erreichbar** und kann Lichtkammer nicht angreifen, auch wenn die Dateien 14 Tage liegen bleiben. Gefährlich ist die Zeit **bis zum Umschalten**: Ab jetzt hat die Testinstallation echte Konten (Passwort, TOTP) und nach dem Import alle Inhalte, während das öffentliche WordPress daneben läuft. Dazu kommt jede andere erreichbare PHP-Anwendung des Kontos.
- **Widerspruch:** 0 Nr. 3 („WordPress muss vor dem Livegang stillgelegt sein“) widerspricht 3.7 (Rückfall braucht unverändertes WordPress).
- **Empfehlung:**
  - E3 so fassen: WordPress **ab sofort** härten (die Liste aus 3.9 Nr. 1 gleich, nicht erst „Wochen vorher“).
  - Nach dem Umschalten prüfen, dass der WordPress-Ordner **über keinen Host mehr erreichbar** ist (Aliasse, alte Subdomains).
  - Ein Rückfall öffnet das Risiko wieder; das so dokumentieren.
  - Zusätzlich, billig: `open_basedir` für Lichtkammer per `.user.ini` (Webroot, Daten, Originale, `/tmp`) begrenzt den Schaden, falls **Lichtkammer selbst** eine Lücke hat. Es schützt nicht vor fremdem PHP, braucht einen Test mit Imagick und gilt nur, wenn `.user.ini` wirkt (N2).
  - Die Aussage „echte Trennung nur über anderen Benutzer“ ist richtig.
  - Hinweis: all-inkl beschreibt im KAS ein Werkzeug zum Verschieben von Domains zwischen Accounts („Account-Transfer“/„Hosts verschieben“). Ob ein eigener Account für br-foto.com im Tarif möglich ist, bleibt die Support-Frage aus E3 (a).

### M1 (mittel, offen): Hauptadresse ist heute `www.br-foto.com`

- **Gemessen:** `https://br-foto.com/` → 301 `https://www.br-foto.com/` (von WordPress). Suchmaschinen und Verweise kennen also die `www`-Adressen.
- **Fundstelle:** E4, 1.3 (Empfehlung „ohne `www.`“).
- **Folge:** Mit Apex als Hauptadresse wechselt beim Umzug zusätzlich der kanonische Host. Jede alte Adresse läuft dann über zwei Weiterleitungen (`www` → Apex → neuer Pfad).
- **Empfehlung:** `www.br-foto.com` als `LK_BASE_URL` behalten (Empfehlung geändert) oder Apex bewusst wählen. In beiden Fällen leitet `.htaccess` **in einem Schritt** auf `https://<Hauptadresse>` weiter (http und Neben-Host zusammen). Die `__Host-`-Cookies gelten je Host, auch das spricht für einen festen Host.

### M2 (mittel, offen): 301 bei den Umzugs-Weiterleitungen verträgt sich nicht mit dem Rückfall

- **Fundstelle:** `Site/Server.php` (`Response::redirect($to, 301)` ohne `Cache-Control`), 3.6, 3.7.
- **Folge:** Browser speichern 301 dauerhaft. Nach einem Rückfall auf WordPress führen gespeicherte Weiterleitungen Besucher weiter auf neue Lichtkammer-Pfade, die WordPress nicht kennt (404).
- **Empfehlung:** Während der 14 Tage Umzugs-Weiterleitungen mit `Cache-Control: max-age=3600` senden (oder 302) und nach Tag 14 auf dauerhaftes 301 stellen. Das ist eine Einstellung, keine neue Funktion; in 8d einplanen.

### M3 (mittel, offen): HTTP wird heute nicht auf HTTPS umgeleitet

- **Gemessen:** `http://lichtkammer.br-foto.com/` liefert **200 mit Inhalt**, keine Weiterleitung.
- **Folge:** Unter `http` funktioniert die Anmeldung ohnehin nicht (`Secure`-Cookies). Seiten lassen sich dort aber unverschlüsselt abrufen, und HSTS greift erst nach dem ersten HTTPS-Besuch.
- **Empfehlung:** Die Weiterleitung aus 1.3 als **Pflicht** in 8a (nicht „beim Livegang prüfen“). Auf all-inkl ist `%{HTTPS}` direkt nutzbar (kein Proxy davor, `REMOTE_ADDR` ist die Client-Adresse). Ein Test gehört in `tools/livepruefung.sh`.

### M4 (mittel, offen): `Authorization`-Kopf unter PHP-FPM

- **Fundstelle:** 2.2 Nr. 1 (Abruf mit `Authorization: Bearer …`).
- **Problem:** Unter Apache mit PHP-FPM kommt der Kopf `Authorization` häufig **nicht** bei PHP an. Ohne Änderung am Server ist das nicht messbar.
- **Empfehlung:** In `public/.htaccess` `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1` oder `RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]` setzen. Zusätzlich einen eigenen Kopf (`X-Lichtkammer-Token`) als Ausweichweg annehmen und in 8b auf dem Testserver messen. **Nie** als Query-Parameter, weil der in Zugriffsprotokollen landet.

### M5 (mittel, offen): `.env` ändern am Umschaltabend ohne Werkzeug

- **Fundstelle:** 3.5 Schritt 2.
- **Problem:** Von Hand müsste Lutz die Server-`.env` mit `LK_APP_KEY` auf den Mac laden, bearbeiten und zurückspielen. Das ist fehleranfällig unter Zeitdruck.
- **Empfehlung:** `deploy.sh --env-setzen NAME=WERT` (für die erlaubten Namen `LK_BASE_URL`, `LK_NOINDEX`, `LK_ORIGINALS_DIR`, `LK_BACKUP_TOKEN`-Hash) nach dem Muster von `--token-entfernen`: temporärer Ordner 600, Schlüssel nie ausgeben, danach `/app/.env` → 403 prüfen. Probelauf vorher. In 8d einplanen.
- **Geprüft:** Der Admin vergleicht Anfragen nicht mit `LK_BASE_URL` (kein Host- oder Origin-Abgleich). Schritt 3 (Veröffentlichen von der Subdomain aus) funktioniert also nach Schritt 2. Bis die Subdomain umgestellt ist, liefern beide Hosts dieselben Seiten; die 301 der Subdomain braucht eine Host-Regel (`RewriteCond %{HTTP_HOST}`) in `public/.htaccess`, die in 8a vorbereitet und erst nach dem Umschalten aktiv wird.

### M6 (mittel, offen): Wiederherstellungsdaten enthalten Sitzungen; Umfang des Abrufschlüssels

- **Fundstelle:** 2.2, 1.11.
- **Problem:** Die Datenbanksicherung enthält Sitzungen, Gerät-vertrauen-Einträge und Einladungen. Wer eine Sicherung samt Schlüssel hat, hat sehr viel.
- **Empfehlung:** Beim Einspielen Sitzungen, Geräte und offene Einladungen **verwerfen** (alle melden sich neu an). Der Abrufschlüssel ist Lesezugriff auf alles außer `LK_APP_KEY`: im Entwurf so benennen und die Sicherungen auf dem Homeserver wie Zugangsdaten behandeln (steht in 2.3 „verschlüsselt“, gut).

### N1 (niedrig, offen): Köpfe bei Dateien, die Apache selbst ausliefert

- **Gemessen:** `/assets/css/app.css` ohne `nosniff`. Apaches 403-Seite ist der Standardtext (239 Byte, `iso-8859-1`). Der `Server`-Kopf zeigt nur `Apache` ohne Version.
- **Empfehlung:** `Header always set X-Content-Type-Options nosniff` und für `/media/` CORP in `.htaccess` (Entwurf 1.1 angepasst), `ErrorDocument` wie in 1.6.

### N2 (niedrig, teils verifiziert): `.user.ini` und PHP-Einstellungen

- **Verifiziert (PHP-Handbuch):** `expose_php` und `allow_url_fopen` sind `PHP_INI_SYSTEM` und **nicht** per `.user.ini` setzbar (Entwurf 1.5 korrigiert). `display_errors`, `log_errors` und `open_basedir` sind setzbar.
- **Verifiziert (gemessen):** all-inkl sendet kein `X-Powered-By`, `expose_php` ist also schon aus.
- **Weiter offen:** Ob all-inkl `.user.ini` liest, ist nicht dokumentiert gefunden; wenn ja, gilt der Zwischenspeicher von 5 Minuten. Messen in 8a: harmlose Einstellung setzen und in der neuen Systemprüfung per `ini_get` anzeigen. `/.user.ini` liefert heute 403 (Punktdatei-Regel greift).

### N3 (niedrig, offen): Git-Historie vor dem Öffentlichschalten

- **Lokal gesucht (80 Commits, alle Zweige):** keine Gmail-Adresse, kein echter `LK_APP_KEY` (nur der Testwert `AAAA…` in `tests/einrichten.sh`), kein privater Schlüssel, kein FTP-Passwort.
- **Gefunden:**
  - **Drei frühe Commits** (`874c4f2`, `7107823`, `390ca48`) haben als Autor `luro@Mac-Studio-luro.local` (Rechnername statt noreply).
  - Die Kundennummer `v119013` steht in `04`, `Erster-Start-Testserver.md`, `20` und als **Vorgabe im Code** `deploy/einrichten.sh`; `kasserver` steht in `04`.
  - Die Bildschirmfotos `docs/anleitung/bilder/t_codes.png` und `t_enroll.png` zeigen vermutlich Wiederherstellungscodes und ein TOTP-Geheimnis der Testinstallation. Prüfen, ob sie aus einer Wegwerf-Instanz stammen.
- **Empfehlung:** In 5.2 Nr. 1 aufnehmen. Vorgabe in `einrichten.sh` durch Pflichtwert aus `deploy/.env` ersetzen. Bei E7 bedenken: Autor-Adressen ändern geht nur durch Umschreiben der Historie oder ein frisches Repo.
- gitleaks/trufflehog sind **nicht** gelaufen; das bleibt Aufgabe von 8i.

### N4 (niedrig, verifiziert): Passwortliste und QR-Code

`app/resources/common-passwords.txt` (145 Zeilen) ist in Schritt 2 selbst zusammengestellt (Kopfkommentar, ein Commit `e8e385f`), keine fremde Liste. `Qr.php` ist eigener Code ohne Bibliothek. **Keine Fremdlizenz nötig**; in `NOTICE` reichen Schriften (OFL-1.1) und im Docker-Image die Basis-Pakete. Entwurf 1.9 und 5.2 Nr. 4 angepasst.

### N5 (niedrig, offen): Ratengrenze für `/admin/*`

- **Fundstelle:** 1.4.
- **Problem:** Eine Zählung je Anfrage in SQLite bedeutet einen Schreibzugriff bei jedem Admin-Aufruf. Die Begründung „öffentliche Seiten sind Dateien“ stimmt nicht: Sie laufen über PHP (`Site/Server.php`), allerdings ohne Datenbank.
- **Empfehlung:** Die Grenze nur für **nicht angemeldete** Admin-Anfragen (die Anmeldegrenzen gibt es schon) oder ganz weglassen. Die Begründung im Entwurf richtigstellen.

### N6 (niedrig, offen): Zertifikat läuft am 25.12.2026 ab

- **Gemessen:** Das Zertifikat von `br-foto.com`/`www` läuft am 25.12.2026 ab und wird von all-inkl erneuert.
- **Empfehlung:** Fällt der Livegang in die Zeit um Weihnachten, nach dem Ordnerwechsel die nächste Erneuerung beobachten. Die Prüfung über `.well-known` ist durch die Ausnahme in `.htaccess` frei.

## Prüffragen kurz beantwortet

1. **Härtungsliste:** vollständig bis auf H1 (Originale), M3 (HTTP-Weiterleitung als Pflicht), M4 (Authorization), N1. CSP/Köpfe der öffentlichen Seite: **verifiziert vorhanden**. `LK_NOINDEX`, Token-Entfernen, Schlüssel und Rotation (E10) sind passend.
2. **Sicherung:** Abholweg über die App ist auf all-inkl richtig und nötig. Wiederherstellung siehe H2. Den Schlüssel getrennt sichern: ja, gut beschrieben.
3. **Livegang:** Weg A ist richtig. DNS bleibt unverändert (gemessen: DNS bei all-inkl, gleiche Adresse für alle Hosts). Neu: M1, M2, M5, H3.
4. **GitHub:** siehe N3, N4. Die Passwortlisten-Lizenz ist erledigt.
5. **Docker/pve10:** stimmig, pve01 erst nach Ja von Lutz (Regel eingehalten). Hinweis: `cap_drop: ALL` mit Apache braucht zusätzlich `DAC_OVERRIDE`/`FOWNER` nur, falls der Entrypoint Rechte ändert; in 8c testen, wie im Entwurf vorgesehen.
6. **Teilschritte und Prüfplan:** passen. Ergänzungen: H1 gehört an den **Anfang von 8a** (vor dem Import), H2 in 8b, M2/M5 in 8d. Fable-Prüfungen wie geplant.
7. **Messpunkte:**

| Punkt | Stand |
|---|---|
| Köpfe beim fertigen HTML | **verifiziert** (vorhanden, über PHP) |
| Köpfe bei `/assets`, `/media` | **verifiziert** (fehlen, N1) |
| `expose_php` | **verifiziert** (aus) |
| `.user.ini` wird gelesen | **offen** (Messung in 8a) |
| Passwortliste-Lizenz | **verifiziert** (eigene Liste) |
| `Authorization` unter FPM | **offen** (Messung in 8b) |
| HTTP → HTTPS | **verifiziert** (fehlt, M3) |
| DNS, Zertifikat, www | **verifiziert** (M1, N6) |
| Originale-Ort | **verifiziert** (Webroot, H1) |
| Audit speichert IP | **verifiziert** (nur Präfix) |
| KAS-Zielordner frei wählbar | **offen** (E2; all-inkl-Anleitung nennt Domain-Zielordner im KAS, Lutz prüft) |

## Direkt im Entwurf behoben

Statuszeile, 1.1 (Köpfe geklärt), 1.2 (unklarer Satz), 1.5 (`PHP_INI_SYSTEM`), 1.9 und 5.2 Nr. 4 (Passwortliste, QR), 1.10 (IP-Präfix), 2.1 (Ort der Originale), 3.4 (DNS/Zertifikat/www gemessen), 3.5 (Tippfehler), Abschnitt 11: E18 ergänzt (war nur in 12 genannt), E19 neu.

## Entscheidungen für Lutz

E1 bis E18 wie im Entwurf, mit diesen Änderungen:

- **E3** neu gefasst (H3): WordPress ab sofort härten; Risiko besteht bis zum Umschalten.
- **E4** Empfehlung geändert: **`www.br-foto.com` als Hauptadresse behalten** (M1).
- **E12** um „Einspielen in der Ersteinrichtung“ und den Bereitstellungsordner für Originale erweitern (H2).
- **E19 neu:** Originale vor dem Import aus dem Webroot nehmen (H1). Empfehlung: ja.

**Bauen kann beginnen**, sobald Lutz E19 und die geänderte E12 bestätigt; 8a startet mit H1.
