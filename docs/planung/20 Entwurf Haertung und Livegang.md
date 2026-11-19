# Entwurf: Schritt 8 „Härtung und Livegang“

Stand: 18.11.2026. **Nachtrag 19.11.2026: E3 (WordPress härten) von Lutz gestrichen („Vergiss WordPress“); 8a (Rest) und 8b sind gebaut, siehe `CLAUDE.md` und `CHANGELOG`.** **Entwurf, wartet auf Freigabe von Lutz. Noch kein Code, nichts hochgeladen, nichts an der Live-Seite oder am DNS geändert.** Risiko laut Plan `03`: **hoch** (Livegang, Daten, Geheimnisse). Opus hat den Entwurf am 18.11.2026 geprüft: Befunde und Messungen in `21 Pruefung Entwurf Haertung und Livegang.md`; Kleinigkeiten sind hier direkt korrigiert (mit „Prüfung 21“ markiert).

Grundlage gelesen: Plan `03` (Schritt 8, Abschnitte 4a, 8, 9), `04`, `05`, `07`, `08`, `13`, `15`, `16` bis `19`, `docs/betrieb/Erster-Start-Testserver.md`, Projekt-`CLAUDE.md` und der heutige Code (`SecurityHeaders`, `public/.htaccess`, `Cookies`, `Builder`, `Dockerfile`, `compose.yaml`, `docker/*`, `deploy.sh`, `tools/release-zip.sh`, `.github/workflows/release.yml`).

## 0. Das Wichtigste in Kürze

1. **Die Anwendung ist schon ordentlich gehärtet** (CSP ohne Inline, `__Host-`-Cookies, Sperren, Argon2id, Prüfung am Dateiinhalt, Sicherheitskopfzeilen, gesperrte Ordner). Es fehlt vor allem: **ein Sicherungsweg nach außen**, **eine Lösung für den gemeinsamen Server** (WordPress daneben), **eine geübte Wiederherstellung**, **ein Ablaufplan für den Umschaltabend** und **die Veröffentlichung auf GitHub**.
2. **Empfehlung Livegang:** Die Seite zieht nicht „auf“ die Hauptdomain um, sondern die Hauptdomain wird im KAS **auf den Ordner der Testinstallation umgestellt** (eine Einstellung, in Minuten rückgängig). WordPress bleibt **unverändert aber schreibgeschützt** zurück, höchstens 14 Tage.
3. **Der gemeinsame Server bleibt das größte Restrisiko.** Empfehlung: Daten und Schlüssel liegen in einem **eigenen Ordner mit Rechten 700** und, wenn all-inkl es erlaubt, bekommt br-foto.com **einen eigenen KAS-Account/Benutzer** (Frage E3). Ohne eigenen Benutzer ist die ehrliche Antwort: **WordPress muss vor dem Livegang stillgelegt sein oder die Zeit mit WordPress daneben ist so kurz wie möglich (Plan: 14 Tage, mit Härtung)**.
4. **Sicherung:** Auf all-inkl geht ohne SSH nur ein **geschützter Abholweg** (`/admin/sicherung`, Download per Einmal-Link, plus ein Abruf-Token für den Homeserver). Ohne ihn kommt der Homeserver nicht an `data/` heran (Bestandsaufnahme `04`). Das ist der einzige größere **neue** Baustein des Schritts.
5. **Version:** Empfehlung: `0.2.0` zum Livegang, **`1.0.0` erst mit dem öffentlichen Release** (E5).
6. **Fable prüft** vor dem Livegang: Anmeldung (Rest seit Schritt 2), Dateiannahme und Auslieferung (Medienverwaltung), Geheimnisse und den neuen Sicherungsweg. **Opus prüft** den Rest und diesen Entwurf.

## 1. Härtung für den Echtbetrieb

Jeder Punkt: heutiger Stand → Empfehlung.

### 1.1 Sicherheitskopfzeilen und CSP

- **Heute** (`SecurityHeaders::for`): CSP `default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'`, `nosniff`, `Referrer-Policy strict-origin-when-cross-origin`, `X-Frame-Options DENY`, `Permissions-Policy`, HSTS `max-age=31536000`, bei `LK_NOINDEX` `X-Robots-Tag`.
- **Empfehlung, klein:**
  - ~~Gilt die Kopfzeile auch für fertige HTML-Dateien?~~ **Geklärt (Prüfung 21, gemessen 18.11.2026):** Die veröffentlichten Seiten liegen in `<Datenordner>/site/releases/` und werden **über PHP** ausgeliefert (`Site/Server.php` über `index.php`), nicht direkt von Apache. CSP, HSTS, `nosniff` usw. kommen auf der Startseite, `robots.txt` und 404 an. **Ohne Köpfe** sind nur die Dateien, die Apache selbst ausliefert: `/assets/…` und `/media/…` (dort fehlen `nosniff` und `X-Frame-Options`) sowie Apaches eigene 403-Seiten. Für diese reicht in `public/.htaccess` ein `Header always set X-Content-Type-Options nosniff` (und für `/media/` `Cross-Origin-Resource-Policy`).
  - HSTS: `max-age=31536000` behalten, **ohne `includeSubDomains` und ohne `preload`** (Subdomains wie `lichtkammer.` oder andere Dienste der Domain sollen nicht mitgezogen werden). Erst nach 30 Tagen stabilem HTTPS auf zwei Jahre erhöhen; Preload nicht (nicht umkehrbar).
  - `Cross-Origin-Opener-Policy: same-origin` und `Cross-Origin-Resource-Policy: same-origin` ergänzen (kostenlos, keine Fremdbindung nötig). **Nicht** für `/media/`, wenn Bilder anderswo eingebunden werden dürfen; Standard: `same-site`.
  - Admin zusätzlich `Cache-Control: no-store` (nur Seiten hinter Anmeldung; `SitePages` setzt es für die Vorschau schon).
  - CSP-Verstoßmeldungen **nicht** einrichten (keine Fremdadresse, keine Zusatzlast); in der Abnahme wird die Konsole im Browser auf CSP-Fehler geprüft.
- **Warum:** Kleine, nebenwirkungsfreie Ergänzungen; die CSP selbst ist bereits streng.

### 1.2 `.htaccess` und Ordner

- **Heute:** `public/.htaccess` sperrt `app|data|originals|tests|docker|deploy|import`, Punktdateien (außer `.well-known`) und `*.env|sqlite|sqlite3|db|log`. `app/.htaccess`: `Require all denied`. `media/.htaccess`: kein PHP. `import/` bekommt eine eigene Sperre.
- **Ergänzen:** `*.bak` (die Migrationssicherung `….vor-schema-N.bak` liegt im Datenordner, aber für den Notfall `LK_ALLOW_DATA_IN_WEBROOT`), `*.zip`, `*.sql`, (`import/` als Ganzes ist schon gesperrt, also auch `manifest.json`). `composer.*`, `*.md` im Webroot sperren (Zip enthält `README.md`? Prüfen: nur im Zip-Wurzelordner, nicht im Webroot; im Hochladeplan klarstellen).
- **Prüfung nach jedem Hochladen** (ein Skript `tools/livepruefung.sh`, nur lesende `curl`-Abrufe): 403/404 für `/app/.env`, `/app/bootstrap.php`, `/data/`, `/import/manifest.json`, `/.git/`, `/media/x.php`; 200 für `/health`; Köpfe der Startseite, `/admin/login` und eines Bildes. Das Skript ersetzt die Handarbeit aus `Erster-Start-Testserver.md`.
- **Warum:** Ein Skript, das jedes Mal dasselbe prüft, findet vergessene Regeln nach Updates.

### 1.3 HTTPS, Cookies

- **HTTPS:** all-inkl beendet TLS (Let’s Encrypt). Beim Umschalten muss das Zertifikat der **Hauptdomain** (und `www.`) für den neuen Ordner gelten (Abschnitt 3.4). `LK_BASE_URL=https://br-foto.com` (die Adresse **mit oder ohne `www.`** muss feststehen, E4). Weiterleitung `http` → `https` und `www` → Hauptadresse über `.htaccess` (mit `RewriteCond %{HTTPS}`; auf all-inkl funktioniert `HTTPS` oder `X-Forwarded-Proto`, beim Livegang einmal prüfen).
- **Cookies:** schon richtig (`__Host-`, `Secure`, `HttpOnly`, `SameSite=Strict`, `Path=/`). `__Host-` verlangt `Path=/` ohne `Domain`: gilt nur für die genaue Adresse. **Folge beim Umschalten:** Alle Sitzungen der Testinstallation sind auf `lichtkammer.br-foto.com` beschränkt; auf der Hauptdomain muss man sich neu anmelden (kein Fehler, Hinweis in der Abnahmeliste). Das Cookie `lk_dev` („Gerät vertrauen“) wird ebenfalls neu gesetzt.
- **Admin auf anderer Adresse?** Empfehlung: **Admin unter derselben Adresse** (`br-foto.com/admin`) wie bisher gebaut. Eine eigene Admin-Subdomain wäre sicherer, würde aber Cookie- und CSP-Regeln neu aufmachen; nicht jetzt.

### 1.4 Raten- und Größengrenzen

- **Heute:** Anmeldung je Name+Adresse, je Name, global (20 Hash-Prüfungen in 10 s), TOTP 10 Versuche; Upload in 4-MB-Stücken, Foto `LK_MAX_UPLOAD_MB=100`, PDF 50 MB, SVG 1 MB, Datei-Bild 5 MB; `max_file_uploads` 20 spielt wegen der Stücke keine Rolle.
- **Empfehlung:** Werte so lassen. **Ergänzen:** eine Grenze für **Anfragen je Minute an `/admin/*` je Adresse** (grob 300) als Schutz vor dem Zumüllen des Protokolls und der Sitzungstabelle; öffentliche Seiten brauchen keine, weil sie Dateien sind. Ein **Höchstmaß für den Datenordner** (Warnung im Admin ab 80 % von `disk_free_space` oder ab einer in `LK_DISK_WARN_GB` gesetzten Größe) statt Sperre.
- **Prüfen:** Hinter dem Reverse-Proxy (Docker) zählen die Sperren **nach Client-Adresse**: `LK_TRUSTED_PROXIES` muss stimmen, sonst teilt sich alles eine Adresse und sperrt alle (siehe 4.4). Auf all-inkl ist `REMOTE_ADDR` direkt die Client-Adresse (kein Proxy davor; beim Livegang an einer Probeanmeldung prüfen, dass im Protokoll die echte Adresse steht).

### 1.5 PHP-Einstellungen

- **all-inkl** (gemessen): PHP 8.5, `memory_limit` 512M, `max_execution_time` 60, `open_basedir` leer, `disable_functions` leer. **Per `.user.ini` im Webroot** (ob all-inkl `.user.ini` unter PHP-FPM liest, ist **nicht** belegt, siehe Prüfung 21) setzen: `display_errors=Off`, `log_errors=On`, `session.use_strict_mode` unnötig (eigene Sitzungen). **Zu prüfen im Teilschritt 8a, ob `.user.ini` greift**; sonst im KAS unter PHP-Einstellungen. Die App selbst setzt `display_errors` schon aus dem Code (`public/index.php`).
- **`expose_php` und `allow_url_fopen`** sind `PHP_INI_SYSTEM` und lassen sich **nicht** per `.user.ini` setzen (Prüfung 21). `expose_php` ist auf all-inkl ohnehin schon aus (gemessen: kein `X-Powered-By`). `allow_url_fopen` wird vom Code nicht gebraucht; nur abschalten, wenn der KAS es anbietet.
- **Docker:** `docker/php.ini` hat `expose_php=Off`, `display_errors=Off`, `log_errors=On`. Zusätzlich `session.cookie_*` unnötig. `opcache.validate_timestamps` bleibt an (Updates per Image-Tausch).
- **Warum:** Fehlerdetails im Browser sind ein klassischer Informationsabfluss.

### 1.6 Fehlerseiten ohne Details

- **Heute:** Unbehandelte Fehler → `error`-Vorlage, Text nur in `error_log`; Debug nur bei `LK_ENV=development`. Die **Konfigurationsfehlerseite für Unangemeldete nennt den Grund** (Befund C6 aus `08`, offen).
- **Empfehlung:** Im Echtbetrieb (`LK_ENV=production`) für **nicht angemeldete** Besucher nur „Der Admin ist gerade nicht verfügbar“; Grund nur im Protokoll. **Achtung:** ohne SSH braucht Lutz die Meldung (Abwägung aus `08`). Lösung: Grund bleibt sichtbar, **solange `LK_SETUP_TOKEN` gesetzt ist oder `LK_ENV=development`**; danach neutral. Zusätzlich ein Admin-Eintrag „Systemprüfung“ (nur angemeldet), der dieselbe Liste zeigt wie die Fehlerseite (Datenordner, Rechte, `.env`-Rechte, Erweiterungen, Uhr, Speicher).
- **404/403 der öffentlichen Seite:** eigene schlichte Seite (es gibt `site/notfound.php`). Apache-eigene Fehlerseiten für 403/500 über `ErrorDocument` auf eine statische `/404.html` bzw. `/fehler.html` lenken, damit kein Apache-Text mit Serverkennung erscheint (all-inkl zeigt eigene Seiten, trotzdem setzen).

### 1.7 `LK_NOINDEX`, Suchmaschinen

- **Heute:** `LK_NOINDEX=1` → `X-Robots-Tag` und `robots.txt` „Disallow: /“; sonst `Allow` mit Sitemap. Die Testinstallation steht auf `1`.
- **Empfehlung (Livegang-Reihenfolge):**
  1. Testinstallation bleibt `1`.
  2. **Beim Umschalten** wird der Ordner mit der Hauptdomain verbunden: `LK_BASE_URL` auf `https://br-foto.com`, **`LK_NOINDEX=0`** und **neu veröffentlichen**, damit `robots.txt`, Sitemap und `canonical` stimmen. Reihenfolge wichtig: erst `.env` ändern, dann veröffentlichen, dann DNS/KAS umstellen (siehe 3.5).
  3. Die Subdomain `lichtkammer.br-foto.com` danach **entweder abschalten oder dauerhaft auf `noindex`** setzen, damit keine Dubletten im Index entstehen (E4).
  4. In der **Google Search Console** die Domain bestätigen (DNS-TXT) und die Sitemap einreichen (optional, keine Pflicht, kein Cookie).
- Beim Umschalten ändert sich **nicht** die URL-Struktur außer den Weiterleitungen (Abschnitt 3.6).

### 1.8 Einrichtungs- und Notfall-Token, Testwege

- `LK_SETUP_TOKEN` ist auf dem Testserver nach der Einrichtung zu löschen (`./deploy.sh --token-entfernen`); der Admin zeigt ein Warnband, solange er steht. **Vor dem Livegang: Prüfpunkt „Warnband weg“.** Auf der Hauptdomain wird **keine neue Ersteinrichtung** nötig, weil dieselbe Datenbank weitergenutzt wird (3.3). Falls doch (frische Datenbank): neues Token, Einrichtung, Token löschen, am selben Abend.
- `LK_RESET_TOKEN` bleibt leer. Der Notfallweg (Administrator ausgesperrt) ist in der Anleitung beschrieben und wird in der Wiederherstellungsprobe geübt (Abschnitt 2.5).
- **Ungenutzte Testwege entfernen oder sperren:** `/health` zeigt die Version: behalten, aber **ohne Version nach außen** wäre noch ruhiger (E8: Empfehlung „bleibt, zeigt nur `ok`“; die Versionsprüfung von `deploy.sh` braucht sie, daher Version nur mit gültigem Abrufschlüssel oder nur im Admin). `tests/`, `docker/`, `deploy/` sind schon gesperrt und nicht im Zip. `LK_ALLOW_DATA_IN_WEBROOT` darf in der Produktion **nicht gesetzt** sein (Prüfpunkt).
- **Entwickleroberfläche:** `tests/devrouter.php` ist nur im Repo; nicht im Zip, nicht im Image.

### 1.9 Abhängigkeiten, Lizenzen, Notices

- **Keine Composer-Pakete.** Eingebunden sind: die Schriften (OFL-1.1, `LIZENZEN.txt` im Webroot und im Zip, `otf/` nicht im Zip), die Liste häufiger Passwörter (`app/resources/common-passwords.txt`: **geprüft in 21**, 145 Zeilen, in Schritt 2 selbst zusammengestellt, Kopf „Häufige Passwörter und Grundwörter“, keine fremde Liste, also keine Fremdlizenz) und die QR-Code-Erzeugung (`app/src/Auth/Qr.php`: eigener Code ohne Bibliothek, steht im Kopf der Datei). Das Exportskript nutzt nur die Standardbibliothek von Python.
- **Empfehlung:** Eine Datei `NOTICE`/`THIRD-PARTY.md` im Repo und im Zip: je Fremdbestandteil Name, Version, Lizenz, Fundort. Im Docker-Image kommen PHP-Image, Imagick/libheif usw. dazu; die Lizenz des Images wird am Image-Label genannt (AGPL-3.0-or-later). **AGPL §13:** Wer Lichtkammer verändert **und** im Netz betreibt, muss den Quelltext anbieten. Empfehlung: ein dezenter Link „Quellcode“ im Admin-Fuß auf das Repo (Admin, nicht öffentliche Seite; die öffentliche Fußzeile behält nur die luro-Wortmarke wie in der CLAUDE.md geregelt).
- **Sicherheitsaktualisierungen:** PHP und Imagick pflegt all-inkl; im Docker-Betrieb das Basisimage monatlich neu bauen (`docker compose pull` auf ein gebautes Image mit aktuellem PHP-Patch). `tools/` und Tests brauchen kein Update.

### 1.10 Protokolle und Aufbewahrung

- **Admin-Protokoll** (`audit`, Hash-Kette, nur Kennungen und Zahlen, nie Namen): wächst unbegrenzt, ist klein. Empfehlung: **24 Monate** aufbewahren, älter archivieren **nur durch Export** (CSV-Download, nach dem Löschen bleibt die Kette ab dem Ankerpunkt prüfbar). Das ist eine Entscheidung (E9); bis dahin nichts löschen.
- **Apache-/PHP-Protokolle:** auf all-inkl im KAS einsehbar (Zugriffsprotokolle laut KAS-Einstellung); **IP-Adressen** dort gelten als personenbezogen: Aufbewahrungsdauer und Hinweis in der **Datenschutzerklärung** (der Inhalt wird vom Importer übernommen; Lutz prüft, ob die Aussagen noch stimmen: Hosting all-inkl, Protokolle, **keine** Cookies für Besucher, **Admin-Cookies** nur für Angemeldete). Besucher bekommen **keinen** Cookie (Besucherseite ist statisch; Theme-Wahl hell/dunkel in `localStorage`: in der Datenschutzerklärung erwähnen, kein Banner nötig, weil rein technisch notwendig und lokal).
- **Docker:** Container-Logs über Docker (`json-file` mit `max-size: 10m`, `max-file: 5` in `compose.yaml` ergänzen, sonst wachsen sie ohne Grenze).
- **Anmeldeadressen im Admin-Protokoll:** geprüft (Prüfung 21): `Audit` speichert nur ein **Adress-Präfix** (`ip_prefix`, Netz mit Präfixlänge), nie die volle Adresse. So in die Datenschutzerklärung.

### 1.11 Schlüssel und Geheimnisse

- **`LK_APP_KEY`:** liegt in `app/.env` (600) und im Passwort-Manager **getrennt von jeder Datenbanksicherung**. Rotation nur geplant (mit `LK_APP_KEY_PREVIOUS`); **Befund C7 (Umschlüsselungslauf fehlt)** bleibt offen: Wer jetzt rotiert, beendet alle Sitzungen und macht Wiederherstellungscodes und Einladungen ungültig. **Empfehlung:** Rotation ist **nicht Teil von Schritt 8**; sie wird nur im Verdachtsfall gemacht (Anleitung im Betriebsdokument: neuen Schlüssel erzeugen, alten in `LK_APP_KEY_PREVIOUS`, alle Nutzer melden sich neu an, neue Wiederherstellungscodes erzeugen). Der Umschlüsselungslauf wird nachgebaut, wenn Lutz es will (E10).
- **Sicherung des Schlüssels:** Passwort-Manager + ausgedruckte Kopie im Tresor/Aktenordner (ohne Datenbank). Ohne Schlüssel sind TOTP-Geheimnisse unlesbar, die Konten müssten neu eingerichtet werden (Wiederherstellung: Abschnitt 2.6).
- **Zugangsdaten:** FTP-Passwort im Schlüsselbund (Dienst `web-ftp-<DOMAIN>`), **eigener FTP-Benutzer nur für br-foto.com** (Plan 03). Das Passwort wird **nach dem Livegang einmal erneuert**, weil es in der Testphase öfter benutzt wurde. Abruf-Token der Sicherung (2.3) ist ein **zweiter, getrennter Schlüssel** (`LK_BACKUP_TOKEN`, Hash in `.env`, nicht der Klartext).

### 1.12 Geheimnis-Scan (Vorbereitung für 5.)

Siehe Abschnitt 5; hier nur: kein Geheimnis im Repository, `deploy/.env` und `app/.env` stehen in `.gitignore` und sind es auch in `git log --all`.

## 2. Sicherung und Wiederherstellung

### 2.1 Was gesichert werden muss

| Was | Wo (all-inkl) | Wo (Docker) | Hinweis |
|---|---|---|---|
| SQLite-Datenbank (Konten, Fotos, Galerien, Seiten, Protokoll, Einstellungen) | `<Datenordner>/lichtkammer.sqlite` | Volume `lk-data` | **nie die laufende Datei kopieren**, sondern `VACUUM INTO` (macht der Code schon) |
| Datenbankkopien | `<Datenordner>/backups/` (zehn, vor jedem Veröffentlichen), `….vor-schema-N.bak` | `lk-data` | liegen auf demselben Server: **zählen nicht als Sicherung**, nur als Schnellrückgriff |
| Originale | `LK_ORIGINALS_DIR`; **ohne diese Zeile liegt der Ordner auf all-inkl im Webroot** (`<Webroot>/originals`, nur per `.htaccess` gesperrt; so heute auf dem Testserver, Prüfung 21 H1) | Volume `lk-originals` | der eigentliche Wert; Varianten lassen sich neu erzeugen |
| Private Dateien (PDF, Logos) | `<Datenordner>/files/` | `lk-data` | seit der Medienverwaltung (siehe `CHANGELOG`) |
| Veröffentlichte Fassungen | `<Datenordner>/site/releases/` (5 Stück) | `lk-data` | aus der Datenbank neu erzeugbar; nicht sichern, spart Platz |
| Erzeugte Varianten | `public/media/` | Volume `lk-media` | aus den Originalen neu erzeugbar; **nicht** sichern |
| `app/.env` | all-inkl `app/.env` | `.env` neben `compose.yaml` | enthält den Schlüssel; **getrennt** und verschlüsselt sichern (Passwort-Manager/Tresor), **nicht** zusammen mit der Datenbank |
| Importpaket | `import/` | `<Datenordner>/import` | nach dem Import gelöscht, nicht nötig |

### 2.2 Wie (Empfehlung)

**Docker (Volumes, kein Problem):** Ein kleines Skript auf dem Docker-Host (oder im Dockhand-Stack ein zweiter Container) macht nachts `docker exec lichtkammer php app/bin/dump` … **Es gibt kein Konsolenprogramm** in der App. Daher zwei gleichwertige Wege, Empfehlung **A**:

- **A. Gleicher Abholweg wie auf all-inkl** (2.3), ein Skript auf dem Homeserver holt per `curl` mit Token; funktioniert in beiden Betriebsarten gleich, **eine Methode, ein Test**.
- B. Nur Docker: Volumes mit `restic`/`rsync` direkt vom Host (`/var/lib/docker/volumes/…`) und die Datenbank vorher mit `sqlite3 .backup` im Container. Schneller, aber nur für Docker.

**all-inkl (nur FTPS):** Der FTP-Benutzer sieht nur den Webroot. Der Datenordner liegt darüber, also **nicht per FTPS erreichbar** (Bestandsaufnahme `04`, bestätigt). Optionen:

1. **Geschützter Abholweg in der App** (Empfehlung): Im Admin „Sicherung“ (nur Administratoren, mit Passwort-Bestätigung) gibt es
   - einen Knopf „Sicherung jetzt herunterladen“: erzeugt die Datenbank per `VACUUM INTO`, packt `files/` dazu und liefert **einen Strom als `.tar` ohne Originale**, und eine Liste der Originale mit Prüfsumme (SHA-256);
   - **einzelne Originale** über `GET /admin/sicherung/original/<id>` mit **Abrufschlüssel** (Header `Authorization: Bearer …`), **schreibgeschützt** und mit Bereichsanfragen (Range), damit der Homeserver nur **neue** Originale holt (Vergleich mit der Prüfsummenliste).
   - Der Schlüssel (`LK_BACKUP_TOKEN`, mindestens 32 Zeichen, in `.env`, 600) ist **nur** für diese zwei Pfade gültig, **kein** Admin-Zugriff, **IP-Begrenzung** optional (`LK_BACKUP_IPS`), eigene Raten-Sperre, jeder Abruf im Protokoll (Art `backup_pulled`, nur Zahlen). Ohne gesetzten Schlüssel ist der Weg **abgeschaltet** (404).
   - **Warum nicht FTPS?** Der Datenordner ist per FTPS unerreichbar; die Alternative, den Datenordner in den Webroot zu legen, hat die Entscheidung von Lutz vom 15.11. verworfen.
2. Manuell im Admin: der Knopf aus Punkt 1 für Lutz vor jedem Update („Sicherung vor Update“, ersetzt die Handarbeit in `INSTALL.txt`).
3. KAS-Datensicherung des Webspace (all-inkl-eigene Sicherung): gibt es, **ersetzt aber nicht** die eigene, weil Wiederherstellung über den Support läuft und der Stand unbekannt ist; sie ist nur ein zweites Netz. Im KAS prüfen, ob sie aktiv ist, und das hier festhalten (Aufgabe für Lutz, E11).
- **Warum der Aufwand?** Ohne ihn gibt es nach dem Livegang **keine Sicherung außerhalb des Servers**, also keine echte. Das Risiko „hoch“ des Schritts liegt hier.

### 2.3 Wie oft, wie lange

- **Täglich** (nachts) die Datenbank und **neue** Originale holen (Homeserver, Dienst wie das rsync-Backup-Projekt oder einfaches Skript mit `launchd`/Cron).
- Aufbewahrung: **30 Tage** täglich, danach monatlich 12 (Datenbank klein; Originale werden nie gelöscht, nur ergänzt, **gelöschte Originale** bleiben 30 Tage im Homeserver-Bestand, weil der Papierkorb 30 Tage kennt).
- **Zusätzlich vor jedem Update, Import und Umschalten** eine manuelle Sicherung (Knopf).
- **Frische prüfen:** Wochenprüfung (Plan 03 Abschnitt 9) meldet, wenn die letzte Sicherung älter als 36 Stunden ist; der Server meldet `last_backup_pulled` im Admin („Letzte Sicherung abgeholt: …“, gelb nach 3 Tagen).
- **Verschlüsselung:** Sicherungen auf dem Homeserver liegen auf einem verschlüsselten Volume (FileVault/LUKS); die Übertragung nur über HTTPS.

### 2.4 Wiederherstellung (Anleitung, kommt als `docs/betrieb/Wiederherstellung.md`)

Fälle und Schritte (alle vorher geübt, siehe 2.5):

1. **Eine Seite/Galerie versehentlich gelöscht:** Papierkorb (30 Tage) im Admin.
2. **Veröffentlichte Seite fehlerhaft:** Admin → Veröffentlichen → „Rückgängig“ (5 Fassungen).
3. **Datenbank beschädigt oder falscher Stand:** neueste Kopie aus `backups/` (Admin-Funktion „Datenbank aus Kopie wiederherstellen“ gibt es **nicht**; Empfehlung: einfach als Admin-Knopf mit Doppelbestätigung und automatischer Sicherung des aktuellen Standes; sonst nur über Dateizugriff).
4. **Server verloren / Umzug / Totalausfall:** neuer Webspace oder Docker-Host →
   1. Version installieren (Zip oder Image **in derselben Version wie die Sicherung**, steht im `meta` der Datenbank),
   2. `app/.env` aus dem Passwort-Manager mit **demselben `LK_APP_KEY`**,
   3. Datenordner anlegen, Datenbank und `files/` einspielen, Originale zurückkopieren (Docker: in die Volumes; all-inkl: ohne SSH **nur über ein Wiederherstellungsskript**, das als Einmal-PHP-Datei per FTPS in den Webroot kommt, mit Einmalschlüssel, den Datenordner füllt und sich selbst löscht, wie in `Erster-Start-Testserver.md` für die alte Datenbank beschrieben; **besser: Admin-Funktion „Sicherung einspielen“** mit Upload in Stücken, siehe E12),
   4. `/health` prüfen, im Admin anmelden, **„Medienvarianten neu erzeugen“** (Knopf `Bildpipeline`) und **Veröffentlichen**,
   5. Weiterleitungen und `robots.txt` sind in der Veröffentlichung und kommen mit.
5. **Schlüssel verloren:** Datenbank bleibt lesbar bis auf die verschlüsselten TOTP-Geheimnisse und Wiederherstellungsdaten. Dann: Konten neu einrichten (`LK_RESET_TOKEN`), Fotos, Galerien, Seiten bleiben. Deshalb Schlüssel getrennt sichern.
6. **Konto ausgesperrt (TOTP-Gerät verloren):** Wiederherstellungscode; wenn auch der fehlt: zweiter Administrator setzt zurück; wenn keiner: `LK_RESET_TOKEN`-Weg.

### 2.5 Wiederherstellungsprobe (Pflicht vor dem Livegang)

Auf **pve10** (Testserver, frei) mit Docker, **nicht** auf der Live-Seite:

1. Sicherung der Testinstallation `lichtkammer.br-foto.com` über den neuen Abholweg ziehen (inklusive Originale).
2. Frischer Container aus dem **Image der Zielversion** (Image lokal gebaut; erst nach Freigabe veröffentlicht), **eine neue `.env` mit demselben Schlüssel**, Volumes leer.
3. Einspielen nach Anleitung 2.4 Nr. 4; Zeit stoppen.
4. Prüfen: Anmeldung mit Passwort + TOTP, Wiederherstellungscodes gehen, Fotozahl, Galerien, Seiten, Veröffentlichen, Bilder werden ausgeliefert, Prüfsummen der Originale stimmen mit der Liste.
5. **Zweite Probe mit falschem Schlüssel** (zeigt, was ohne Schlüssel noch geht, siehe 2.4 Nr. 5).
6. Ergebnis (Dauer, Probleme, Anleitung angepasst) als Abschnitt im Betriebsdokument. **Ohne bestandene Probe kein Livegang.**
- **Keine echten privaten Fotos** ins Repository; die Probe liegt außerhalb.

## 3. Livegang br-foto.com

### 3.1 Ausgangslage

- Hauptdomain `br-foto.com` (all-inkl Konto A): WordPress, Ordner z. B. `/www/htdocs/vXXXXXX/br-foto.com/` (genauer Pfad im KAS prüfen).
- Testinstallation `lichtkammer.br-foto.com`: eigener Ordner `/www/htdocs/vXXXXXX/lichtkammer.br-foto.com/`, Daten **über** dem Webroot in `/www/htdocs/vXXXXXX/lichtkammer-data/` (bereits so geplant, `04`).
- Import vom WordPress-Bestand ist noch nicht gelaufen (offen bei Lutz); die vier Fragen (Welche Galerien/Seiten, bessere Originale, Kamera öffentlich, Schreibweise Namen) sind in `13` schon beantwortet (alles, größte Auflösung, EXIF an, Schreibweise wie alt), **Lutz bestätigt in E1**, dass das so bleibt.

### 3.2 Empfehlung: Hauptdomain im KAS auf den Ordner der Testinstallation legen

Zwei Wege:

| | A. Hauptdomain zeigt auf den vorhandenen Ordner (Empfehlung) | B. Dateien in den Ordner der Hauptdomain verschieben |
|---|---|---|
| Ablauf | Im KAS den **Zielordner** der Domain `br-foto.com` (und `www.`) vom WordPress-Ordner auf den Ordner der Testinstallation stellen | Code in den Ordner der Hauptdomain hochladen, Daten verschieben |
| Rückfall | **eine Einstellung** zurückstellen, Sekunden bis Minuten | WordPress-Dateien müssten vorher gesichert/verschoben werden; Rückfall = Rückverschieben |
| Risiko | Cookie/Adresse ändern sich (Neuanmeldung); `LK_BASE_URL` muss vorher passen | Datenordner liegt neben dem falschen Domain-Ordner, Rechte/Pfade ändern sich |
| Vorbedingung | Domain-Zielordner im KAS **frei wählbar** (ist es bei all-inkl für Domains und Subdomains üblich, noch nicht von uns geprüft, Aufgabe E2) | nur FTPS |

**Empfehlung A.** Dazu gehört: Der Testordner bleibt der Betriebsordner. Der Name `lichtkammer.br-foto.com` bleibt als Ordnername (Anzeigename egal); **die Subdomain wird nach dem Umschalten auf Hauptdomain umgeleitet (301)** oder abgeschaltet (E4).
Alternative C falls Zielordner nicht frei wählbar: `public/` unter der Hauptdomain als **Ordner `br-foto.com/`** neu füllen (Weg B) und den Datenordner **nicht verschieben** (er liegt ohnehin über dem Webroot; nur `LK_DATA_DIR` zeigt weiter dorthin). Dann ist der Rückfall: zuvor umbenannter WordPress-Ordner (`br-foto.com-wp`) zurückbenennen. Auch das ist in Minuten machbar, nur wegen der Dateimengen (1 158 Medien) langsamer beim Aufbau, nicht beim Rückfall.

### 3.3 Daten: dieselbe Datenbank, kein zweiter Import

Der Import (Schritt 7) läuft **auf der Testinstallation** (Lutz startet ihn unter `/admin/import`), die Durchsicht und Veröffentlichung auch dort. Zum Livegang wird **nichts kopiert**: dieselben `data/`, `originals/`, `media/` und dieselbe Datenbank. Nur `LK_BASE_URL`, `LK_NOINDEX` und ein neuer Veröffentlichungslauf ändern sich. **Vorteil:** keine Datenmigration am Umschaltabend, die Wiederherstellungsprobe testet genau die Daten, die live gehen.
- **Nachteil/Risiko:** Testdaten (Probeeinträge, Testkonten, Probe-Fotos) sind dann live. **Vorher aufräumen:** Testkonten löschen (Konten prüfen), Papierkorb leeren, Entwürfe prüfen, Protokoll bleibt (Nachweis). **Frage E6:** Soll das Protokoll der Testphase mit hinüber? (Empfehlung: ja; es ist ein Nachweis und enthält keine Namen.)

### 3.4 DNS und TLS

- **Gemessen (Prüfung 21, 18.11.2026, nur lesend):** Nameserver `ns5/ns6.kasserver.com` (DNS bei all-inkl), `br-foto.com`, `www.br-foto.com` und `lichtkammer.br-foto.com` zeigen alle auf dieselbe IPv4-Adresse, kein AAAA, TTL 7 200 s. Zertifikat der Hauptdomain: `br-foto.com` und `www.br-foto.com`, gültig bis 25.12.2026. **WordPress leitet heute `br-foto.com` → `https://www.br-foto.com/` um (www ist die Hauptadresse)**, HSTS sendet WordPress nicht.
- **DNS ändert sich nicht**, wenn br-foto.com ohnehin bei all-inkl liegt (A/AAAA zeigen auf den Webserver des Kontos); nur der Zielordner im KAS wechselt. **Zu prüfen (nur lesend):** `dig br-foto.com` und `dig www.br-foto.com`, TTL, ob die Domain bei all-inkl verwaltet wird oder nur der Webspace (dann DNS beim Registrar). **Nichts ändern.**
- **TLS:** Das Let’s-Encrypt-Zertifikat der Hauptdomain besteht schon (WordPress läuft über HTTPS). Nach dem Ordnerwechsel bleibt es gültig; im KAS prüfen, dass „SSL für Domain und `www.`“ aktiv ist. **24 Stunden vorher** die TTL der Einträge ansehen (bei einer reinen Ordnerumstellung ohne DNS-Änderung unkritisch).
- **HSTS:** WordPress hat evtl. schon HSTS gesetzt; unsere Köpfe ersetzen es. `includeSubDomains` nicht (1.1).
- **E-Mail** (MX) und andere Dienste der Domain **nicht berühren**.

### 3.5 Ablauf (Zeitplan, grob 60 bis 90 Minuten Arbeit, 14 Tage Bereitschaft)

**Wochen vorher**
- Schritt 8a–8e fertig, Fable- und Opus-Prüfung durch, Wiederherstellungsprobe bestanden, Abnahmeliste (Abschnitt 6) mit Lutz **und der zweiten Person** auf der Testinstallation durchgegangen.
- Weiterleitungsliste geprüft (3.6), Datenschutz und Impressum durchgesehen (keine Dubletten).
- WordPress **eingefroren**: Updates, Plugins aktuell, **keine neuen Beiträge** ab Stichtag, XML-RPC und REST eingeschränkt (Plan 03 Abschnitt 8), Sicherung des WordPress (Dateien + Datenbank) **außerhalb aller Repos** abgelegt und der Rückweg einmal angesehen.

**Umschaltabend** (ruhiger Tag, nicht freitags, zwei Stunden Zeitfenster, beide erreichbar)
1. Sicherung ziehen (Knopf), Datenbankkopie prüfen.
2. `app/.env` ändern: `LK_BASE_URL=https://br-foto.com`, `LK_NOINDEX=0` (Datei per FTPS, 600; nur Lutz, keine Zugangsdaten in Chats). **Achtung:** Ab jetzt zeigt die Testadresse eine andere Basis; das ist gewollt, die Subdomain wird gleich umgestellt.
3. **Im Admin neu veröffentlichen** (Weiterleitungen, `robots.txt`, Sitemap, Verknüpfungen mit der neuen Adresse).
4. **Im KAS Zielordner der Domain `br-foto.com` (und `www.`) umstellen.** Ab hier ist die neue Seite live.
5. Sofortprüfung (`tools/livepruefung.sh` und Hand): Startseite, 3 Galerien, Impressum, Datenschutz, eine alte Adresse (301), `robots.txt`, Sitemap, `/admin/login` (Neuanmeldung mit TOTP, Wiederherstellungscode **nicht** verbrauchen), Köpfe, Zertifikat, Mobil.
6. **Entscheidung nach 30 Minuten:** passt es? Wenn nein: Rückfall (3.7).
7. Subdomain `lichtkammer.br-foto.com`: **301 auf Hauptdomain** (Pfad bleibt) oder abschalten (E4).

**Nach dem Livegang** (Tag 1 bis 14)
- Tag 1: Server-Fehlerprotokoll und Zugriffsprotokoll auf 404-Häufungen (fehlende Weiterleitungen) ansehen, fehlende Weiterleitungen ergänzen (Admin → Weiterleitungen; Veröffentlichen).
- Tag 3 und 7: Stichproben, Sicherung frisch?, Google Search Console (falls eingerichtet) auf Fehler.
- **Tag 14 höchstens:** WordPress abschalten und entfernen (3.8).

### 3.6 Weiterleitungen (aus `_redirects.json`)

- Entstehen beim Import (`13`) und gehen beim Veröffentlichen in die Fassung. Nicht abgedeckt: `?p=123`, Feeds, alte Bild-URLs `/wp-content/uploads/…jpg`.
- **Empfehlung für den Livegang:** Eine **Liste aller URLs des alten Bestands** aus der WordPress-Sitemap (`/wp-sitemap.xml`) und der REST-API ziehen (nur lesend, `tools/wp-export` kann es) und **jede einzeln mit `curl -I` gegen die neue Seite testen**: Soll-Ergebnis 200 oder 301 → 200, nie 404. Die Liste liegt in der Abnahmeprüfung; Lücken kommen in die Weiterleitungstabelle.
- **Alte Bild-URLs** (`/wp-content/uploads/…`): sie tauchen in Suchmaschinen und externen Verweisen auf. Optional eine Regel in `public/.htaccess`: `/wp-content/uploads/YYYY/MM/<name>` → Foto mit gleichem Dateinamen (Tabelle `redirects` kann Bilder nicht abbilden). **Empfehlung:** nicht bauen; Verlust ist gering, WordPress-Bildadressen werden selten verlinkt. Nur wenn die Auswertung nach 7 Tagen viele 404 auf `wp-content` zeigt (E13).
- **Seitenzahl:** Nach dem Umschalten Anzahl der URLs in der Sitemap mit der alten vergleichen.

### 3.7 Rückfall auf WordPress (bis höchstens 14 Tage)

- **Voraussetzung:** Der alte WordPress-Ordner bleibt **unverändert** und die WordPress-Datenbank (MySQL im KAS) unangetastet. Nichts wird gelöscht.
- **Rückfall:** Im KAS den Zielordner der Domain zurück auf den WordPress-Ordner stellen. WordPress läuft wie vorher.
- **Was dabei verloren geht:** Alles, was **seit dem Umschalten** neu in Lichtkammer entstand (Fotos, Texte). Deshalb in den 14 Tagen **keine großen neuen Inhalte nur in Lichtkammer ohne Hinweis**, und Sicherung weiter täglich. Neue Inhalte lassen sich nach der Rückkehr zu Lichtkammer wieder einspielen, weil die Daten unberührt bleiben (nur Domain-Zeiger wechselt).
- **Cookies/Anmeldung:** Besucher merken nichts. Weiterleitungen gelten nicht mehr (WordPress-Adressen leben wieder).
- **Rückfall-Auslöser** (vorher festlegen): Startseite oder Galerien liefern 5xx; Admin lässt sich nicht anmelden und Wiederherstellungsweg versagt; Bilder fehlen großflächig; Datenverlust. **Nicht** Auslöser: einzelne fehlende Weiterleitungen oder Schönheitsfehler (werden behoben).

### 3.8 WordPress abschalten und entfernen (spätestens Tag 14)

1. Letzte Sicherung des WordPress (Dateien, Datenbank-Export) auf den Homeserver, **verschlüsselt**, **ein Jahr** aufbewahren (Kopie der alten Inhalte, falls später etwas fehlt); nicht ins Repo.
2. WordPress-Ordner **umbenennen** (z. B. `br-foto.com-wp-abgeschaltet`), **Zielordner bleibt Lichtkammer**; zwei Tage warten.
3. Danach WordPress-Datenbank (MySQL) im KAS **löschen** und den Ordner löschen. Löschen ist **nicht rückholbar**: nur nach Bestätigung von Lutz.
4. Dienste, die WordPress nutzte, prüfen: E-Mail-Versand aus Formularen, Plugin-Konten (Jetpack, Analytics), Cron-Aufträge im KAS, Kontaktformular (wird Lichtkammer ein Kontaktformular brauchen? In `03` Phase 2).

### 3.9 Der gemeinsame Server (Systembenutzer, WordPress neben Lichtkammer)

**Das Problem:** Alle Domains des Kontos laufen unter einem Systembenutzer, `open_basedir` ist leer. Jedes PHP-Skript einer Domain kann `app/.env` und die SQLite-Datei von Lichtkammer lesen (Befund M8 aus `07`). Ein WordPress mit einer Lücke (veraltetes Plugin) reicht. Auch **andere Domains des Kontos** (rothe-it, lurohome, weitere) sind betroffen, nicht nur WordPress.

**Maßnahmen in Reihenfolge der Wirksamkeit:**

1. **Zeitfenster minimieren (Plan 03):** WordPress wird **innerhalb von 14 Tagen** entfernt. Bis dahin: WordPress und alle Plugins aktualisieren, Benutzerkonten prüfen, überflüssige Plugins löschen, XML-RPC aus, REST-Benutzer-Endpunkt aus, `wp-config.php` und `wp-content` nicht schreibbar wo möglich, Dateiänderungsüberwachung (z. B. Zeitstempel-Vergleich per FTPS vor/nach).
2. **Eigener KAS-Account bzw. eigener Systembenutzer für br-foto.com** (E3, **Empfehlung: klären und wenn möglich umsetzen**). all-inkl trennt Benutzer je **KAS-Zugang/Account**; „Konto A“ bündelt mehrere Domains. Ein eigener Account (ggf. mit eigener Rechnung) für br-foto.com **nach Abschaltung** von WordPress wäre die saubere Lösung, ist aber Kosten und Umzugsaufwand. Aufgabe: Lutz fragt den all-inkl-Support, ob mehrere Systembenutzer je Domain möglich sind (die Information liegt nicht vor, **nicht raten**).
3. **Rechte auf `.env` und Datenordner:** Dateien 600, Ordner 700. Das hilft **nicht** gegen denselben Systembenutzer (er ist der Besitzer). Es hilft nur gegen andere Benutzer und gegen Fehlkonfiguration. Trotzdem setzen (billig).
4. **Schlüssel nicht lesbar ablegen:** Die Idee, den Schlüssel nicht in `app/.env` zu halten, sondern als **Umgebungsvariable** (all-inkl: im KAS unter PHP-Einstellungen/„Umgebung“? gibt es meines Wissens nicht für FPM; **zu prüfen**), schützt vor Dateilesern, nicht vor `getenv` im gleichen Pool. **Kein Gewinn bei gleichem Benutzer.**
5. **Abhängigkeit verringern:** Selbst bei Zugriff eines Angreifers auf `app/.env` und die Datenbank gilt: Passwort-Hashes sind Argon2id, TOTP-Geheimnisse mit dem Schlüssel verschlüsselt, **also ist mit dem Schlüssel auch der zweite Faktor offen**. Eine Verbesserung wäre, den Schlüssel aus **einem Teil in der `.env` und einem Teil in der Datenbank/Datei** zu leiten (hilft nicht, beides ist lesbar). **Echte Trennung gibt es nur über einen anderen Benutzer oder einen anderen Server.**
6. **Ehrliche Aussage für den Betrieb:** Auf Konto A ist Lichtkammer **nur so sicher wie die schwächste PHP-Seite des Kontos**. Das ist ein bewusst getragenes Risiko für die Zeit, in der Lutz alle anderen Domains (rothe-it, lurohome usw.) ebenfalls auf aktuellem Stand hält und kein WordPress mehr läuft. **Alternative, wenn das nicht reicht:** Lichtkammer im **Docker-Betrieb** auf pve01 (eigener Container, eigene Volumes, kein Fremdcode daneben), mit `br-foto.com` per Reverse-Proxy. Das trennt sauber, kostet aber den Betrieb zu Hause (Erreichbarkeit, DynDNS/Tunnel, Strom, Ausfallzeiten). **Entscheidung E3.**
7. **Prüfpunkt vor dem Livegang:** Im KAS die Liste der Domains und ihrer Software durchgehen (nur Lutz): Was läuft noch unter Konto A, welche PHP-Anwendungen (WordPress, Nextcloud, Sonstiges)? Je Anwendung Stand und Plan. Ergebnis kommt in das Betriebsdokument.

## 4. Docker-Betriebsart

### 4.1 Stand

`Dockerfile` (PHP 8.5-Apache, Imagick mit AVIF/HEIC, `pdo_sqlite`, Version als Build-Argument, Healthcheck auf `/health`), `compose.yaml` (Image `ghcr.io/luro611/lichtkammer:latest`, Port `127.0.0.1:8080:80`, drei Volumes, `env_file: .env`), `docker/entrypoint.sh` (nimmt `X-Forwarded-For` nur von `LK_TRUSTED_PROXIES`), `docker/php.ini`. Der Release-Ablauf (`.github/workflows/release.yml`) baut Zip und Image bei einem Tag. Der Test des Image-Baus auf pve10 und der Port stehen aus (`compose.yaml`-Kommentar „OFFEN“).

### 4.2 Empfehlungen

- **Image:** gebaut aus dem vorhandenen `Dockerfile`; Basisbild auf **festen PHP-Patchstand** (`php:8.5-apache` rollt; für Wiederholbarkeit zusätzlich `@sha256`-Pin und monatliche Aktualisierung als Aufgabe). **Nutzer:** Apache läuft als `www-data`; OK. **Schreibgeschützt** wäre besser: `read_only: true` mit `tmpfs` für `/tmp` und `/run/apache2`, Volumes beschreibbar. Wird in 4c getestet, nur übernommen, wenn es ohne Umbauten läuft.
- **`compose.yaml`:** `restart: unless-stopped`, **`logging`** (10 MB × 5), **`security_opt: [no-new-privileges:true]`**, **`cap_drop: [ALL]`** + `cap_add: [CHOWN, SETUID, SETGID, NET_BIND_SERVICE]` (Apache braucht Port 80 im Container; ab Port ≥1024 im Container wäre `NET_BIND_SERVICE` unnötig, Aufwand wegen Apache-Konfiguration, nicht jetzt), Speichergrenze (`mem_limit: 1g`, wegen Imagick, mit Test an 100-MB-Foto), keine Veröffentlichung an alle Schnittstellen.
- **Reverse-Proxy:** Empfehlung **Caddy** im selben Docker-Netz (automatisches TLS, kurze Konfiguration: `reverse_proxy lichtkammer:80`, HSTS-Kopf **nicht** doppelt setzen, die App setzt ihn schon; `header_down -Server`). Läuft im Heimnetz bereits ein Proxy (Nginx Proxy Manager/Traefik), den nehmen. Wichtig: `LK_TRUST_PROXY=1`, `LK_TRUSTED_PROXIES=<Adresse des Proxys im Docker-Netz>`; **die Anwendung nimmt nur den letzten Eintrag von `X-Forwarded-Proto`/`-For`** (Befund H2).
- **Port-Entscheidung (offen im Kommentar von `compose.yaml`): Empfehlung `127.0.0.1:8080:80`** (Proxy auf demselben Host), und **ohne `ports`**, wenn der Proxy im selben Docker-Netz läuft (dann Netzwerk `lk-net`, Proxy tritt bei). Nie `0.0.0.0`. Port 8080 ist Vorgabe; wenn belegt, frei wählbar (E14).
- **Test auf pve10 (Testserver, frei):** LXC mit Docker (oder vorhandener), Image **lokal bauen** (`docker compose build`), Compose-Datei, Proxy mit **Testdomain** im Heimnetz (`lichtkammer.pve10.lan` o. ä., TLS mit selbst signiertem oder interner CA, dann auch HTTPS-Cookies testbar). Prüfen: Einrichtung `/admin/setup`, Anmeldung, Upload eines 100-MB-Fotos, Veröffentlichen, Rückgängig, `docker compose down`/`up` (Daten bleiben), Neustart des Hosts, **Wiederherstellungsprobe (2.5)**, Update-Probe (Image-Tausch mit Migration), `docker stats` unter Last.
- **pve01 (Produktion) erst nach GitHub-Freigabe** (veröffentlichtes, getaggtes Image) und **ausdrücklichem „wir können jetzt auf pve01“ von Claude mit Zustimmung von Lutz** (globale Regel). Für br-foto.com ist Docker **nicht** der Weg (all-inkl bleibt Hauptweg); Docker ist die zweite Betriebsart (Test, Fremdnutzer, späterer Umzug).
- **Dockhand/Stack:** Der Stack bekommt `.env` über Dockhand-Secrets; **`LK_APP_KEY` nicht in der Compose-Datei**.
- **PostgreSQL-Regel:** gilt nicht (SQLite, keine Datenbank-Container).
- **Mehrere Plattformen:** `linux/amd64,linux/arm64` im Workflow; beim Probebau auf pve10 reicht amd64.

## 5. GitHub-Veröffentlichung und erste Version

### 5.1 Zählweise

- Stand: `Config::VERSION = '0.1.0-rc1'`, Tag `v0.1.0-rc1` (Probelauf des Release-Ablaufs), `CHANGELOG` hat „Unveröffentlicht“ mit Medienverwaltung.
- **Lutz fragen (E5):** „`0.1.0-rc1` so lassen?“ **Empfehlung:** **Nein, vorwärts zählen:** `0.2.0` für den Stand mit Medienverwaltung und Härtung = **Livegang-Version** (Tag `v0.2.0`, **privates** Repo, Release als Entwurf), danach `0.2.x` für Korrekturen. **`1.0.0`** setzt Lutz, **wenn das Repo öffentlich wird** und der Passkey (Schritt 6) gebaut ist **oder bewusst weggelassen**. Begründung: `0.1.0-rc1` suggeriert „Release Candidate für 0.1.0“, passt nicht mehr zum Umfang; „rc“ nur bei einem echten Kandidaten für 1.0.0 (`1.0.0-rc1`) verwenden. Der Tag `v0.1.0-rc1` und seine Pakete bleiben als Probelauf stehen oder werden gelöscht (E5).
- Der Workflow prüft, dass Tag und `Config::VERSION` übereinstimmen (vorhanden).

### 5.2 Vom privaten zum öffentlichen Repo (Prüfliste, nur nach Freigabe von Lutz)

1. **Geheimnis-Scan der gesamten Historie** (nicht nur HEAD): `gitleaks detect --source . --log-opts="--all"` und `trufflehog git file://. --since-commit …` (beide lokal in Docker, kein Upload); zusätzlich Handsuche nach `br-foto`-Zugangsdaten, die Kundennummer im Webspace-Pfad (**nicht veröffentlichen**; steht in `04` und `Erster-Start-Testserver.md`, in `.env.example` nur als Platzhalter; in `docs/` ersetzen), echten Namen/Adressen/Telefon, `lutz.rothe@…`-Gmail-Adresse (verboten laut globaler Regel), `luro611`-noreply ist erlaubt.
2. **Historie bereinigen oder neu anfangen?** Ist etwas gefunden, das nie hätte hineingehören dürfen, gibt es zwei Wege: `git filter-repo` (Historie umschreiben, danach neu pushen, alte Klone ungültig) oder **ein frisches öffentliches Repo mit einem einzigen Anfangs-Commit** (Historie bleibt im privaten Archiv-Repo). **Empfehlung E7:** Erst scannen, dann entscheiden; ist die Historie sauber, **mit Historie veröffentlichen**; die `docs/planung/` enthalten teils Bezug auf Lutz und br-foto (Namen der Fotografen?), das **vorher durchsehen** und ggf. in `docs/intern/` auslagern, das **nicht** ins öffentliche Repo kommt (oder `docs/planung` insgesamt privat halten). **Zu entscheiden:** Planungsdokumente öffentlich (Transparenz) oder nicht (E7).
3. **Beispielinhalte ohne private Fotos:** Die Screenshots in `docs/anleitung/bilder/t_*.png` und das PDF `Lichtkammer-Anleitung.pdf` **einzeln ansehen**: Zeigen sie echte Namen, Foto-Inhalte, Domains, Benutzernamen? Wenn ja: ersetzen durch Aufnahmen mit eigenen Beispielfotos (CC0/selbst erzeugte Verläufe). Für README und Demo: **Beispielbilder erzeugen** (Verläufe/Muster, kein Foto Dritter) in `docs/beispiel/`.
4. **Lizenzdateien:** `LICENSE` (AGPL-3.0, vollständiger Text, vorhanden), SPDX-Zeile `AGPL-3.0-or-later` im Kopf jeder PHP-/JS-/CSS-Datei (kleines Skript prüft, dass jede Datei sie trägt), `NOTICE`/`THIRD-PARTY.md` (1.9), Schriften-Lizenzen (OFL-1.1, im Webroot und Zip vorhanden), Passwortliste ist eigener Bestandteil (geprüft in 21).
5. **README:** mit luro-Logo (Siegel `luro-siegel-kompakt-hell/-dunkel` aus `logos/png|svg`, `<picture>`-Umschaltung hell/dunkel), Kurzbeschreibung, Funktionsliste, Installation (Zip, Docker), Betriebsarten, Sicherheit (Hinweis auf `SECURITY.md`), Lizenz, Link zu `lurohome.de`. Wortmarke `luro` am Ende.
6. **Weitere Dateien:** `SECURITY.md` (Meldeweg: `info@lurohome.de`, nicht Gmail; Support-Versionen), `CONTRIBUTING.md` (kurz: Fehlerberichte willkommen, Pull-Requests nach Absprache), `CODE_OF_CONDUCT.md` optional, Issue-Vorlagen, `.github/dependabot.yml` für Actions und Docker-Basis-Image.
7. **GitHub-Einstellungen:** Branch-Schutz auf `main` (Tests als Pflicht-Check), **Secret-Scanning und Push-Protection** an, Dependabot-Warnungen, **kein** Wiki, Discussions aus (Pflegeaufwand), Releases nur durch Tag.
8. **Paket öffentlich:** `ghcr.io/luro611/lichtkammer` auf öffentlich stellen **erst nach** der Repo-Freigabe; `latest` nur für stabile Versionen (bei `0.x` zeigt `latest` auf die jüngste 0.x: in der README sagen, dass `0.x` im Aufbau ist).
9. **Aktionen prüfen:** Der Workflow nutzt `actions/checkout@v4`, `docker/*-action@v3/v6` (auf feste Commit-Hashes pinnen, nach Prüfung).
10. **Ablauf:** Lutz gibt frei → Repo auf öffentlich → Paket öffentlich → Tag. **Nicht** rückholbar (Inhalte können gespiegelt werden), deshalb zuletzt und nach dem Livegang (Entwurf `15`, D5: Empfehlung bleibt).

### 5.3 Releases und Änderungsverlauf

- `CHANGELOG.md`: „Unveröffentlicht“ wird beim Livegang zu `## [0.2.0] – <Datum>` mit **Hinweisen zum Update** (Migrationen 9 und 10, `LK_BACKUP_TOKEN`, neue `.htaccess`-Regeln, `.user.ini`).
- Sicherheitskorrekturen: eigene Patch-Version, im Verlauf markiert; **Hinweise zum Update** nennen immer die Datenbank-Migration.
- Release-Notizen werden aus dem `CHANGELOG` gezogen (Workflow vorhanden).

## 6. Abnahmeliste für Lutz (auf der Testinstallation vor dem Livegang, danach in Kurzform live)

Mit Lutz und der zweiten Person, **ein Durchgang auf Mac/Safari, einer auf Windows/Chrome oder Edge, einer auf dem Handy (iPhone/Android)**:

**A. Besucherseite (ohne Anmeldung)**
1. Startseite lädt, Titelbild, Kacheln, Menü, Fußzeile mit luro-Wortmarke.
2. Galerienübersicht, Filter, eine Galerie mit Raster, Lightbox (Tastatur, Wischen, Bildinfo mit Kamera/Objektiv), Zurück.
3. Alle Seiten: Über uns, Fotografen, Ausstellungen (Flyer-PDF öffnet), **Impressum und Datenschutz je genau einmal** (keine Dubletten im Menü, in der Fußzeile, in der Sitemap, unter den Seiten im Admin).
4. Hell/Dunkel (System und Umschalter), beide Designs der Verwaltung, **reduzierte Bewegung**.
5. Handy: Menü, Raster, Lightbox, Texte lesbar, kein seitliches Scrollen.
6. 404-Seite, eine alte WordPress-Adresse leitet um (3 Stichproben), `robots.txt`, Sitemap.
7. Browser-Konsole **ohne** CSP-Fehler, keine Fremdadressen im Netzwerk-Tab (Schriften, Skripte lokal).
8. Geschwindigkeit: Lighthouse (Leistung, Zugänglichkeit, Beste Praktiken, SEO) auf Start, Galerie, Seite; Ziel Zugänglichkeit ≥ 95, Rest ≥ 90 (Richtwert, kein Muss).

**B. Verwaltung (Anmeldung und Konten)**
9. Anmeldung mit Passwort + TOTP; falsches Passwort/Code (Sperrmeldung, nicht aussperrend); Abmelden; Leerlauf-Abmeldung; „Gerät vertrauen“ an/aus.
10. **Wiederherstellungscodes:** einen Code einlösen (verbraucht), neue Codes erzeugen (verlangt Passwort + TOTP), ausdrucken/in Passwort-Manager.
11. Zweite Person: Einladung, Einrichtung des Authenticators, Rolle (Redakteur ohne Verwaltungsrechte kommt nicht an Konten/Import/Sicherung).
12. Passwort ändern, Sitzungen beenden, Protokoll zeigt alles richtig.
13. **Warnbänder** (Setup-Token, Daten im Webroot) **fehlen**.

**C. Inhalte**
14. Foto-Upload (ein Foto, 20 Fotos, ein sehr großes, Abbruch und Fortsetzen), Ordner-Upload per Ziehen, Dublette, Alt-Text-Pflicht.
15. Galerie anlegen, sortieren, Titelbild, EXIF-Schalter, Mehrfachbearbeitung, Papierkorb und Wiederherstellen (einschließlich Ordner).
16. Seite bearbeiten (alle Blockarten, Vorschau), Menü, Logos, Einstellungen.
17. **Veröffentlichen** (Liste der Änderungen, Fortschritt), **Rückgängig**, Ergebnis auf der Besucherseite.
18. Medienverwaltung: Ziehen auf Ordner, PDF/SVG hochladen, öffentlich schalten, Bildleiste auf Blockseite.
19. **Sicherung:** Knopf „Sicherung herunterladen“ liefert eine Datei; Abruf mit Token vom Homeserver klappt; ohne Token 404.
20. Impressum/Datenschutz im Wortlaut gelesen und von Lutz bestätigt (Rechtstext bleibt Verantwortung des Betreibers).

**D. Betrieb**
21. Wiederherstellungsprobe bestanden (2.5), Sicherung frisch, Wochenprüfung eingerichtet.
22. `tools/livepruefung.sh` ohne rote Zeile, Köpfe (securityheaders.com-ähnliche Prüfung lokal mit `curl -I`), Zertifikat gültig.
23. Rückfallweg einmal **trocken durchgesprochen** (wer, wie, in welcher Reihenfolge).

Ergebnis der Abnahme: Lutz schreibt „Abnahme bestanden“ in den Chat, **erst dann** nennt Claude „wir können jetzt umschalten“.

## 7. Prüfplan

| Gegenstand | Prüfer | Wann |
|---|---|---|
| **Dieser Entwurf** | **Opus** (`/code-review high` auf den Entwurf, Fokus Livegangablauf, Rückfall, gemeinsamer Server, Sicherungsweg) | vor dem Bauen |
| Sicherungsweg (neu: Token, Download, Range, Raten) | **Fable** (Geheimnisse, Authentifizierung, neue Angriffsfläche) | nach dem Bauen, vor dem Livegang |
| Anmeldung (seit Schritt 2: Gerät vertrauen, Codeerneuerung, Admin-Sperren) | **Fable** (Restprüfung `/code-review max`, falls Fable im Konto fehlt: Opus `max`) | vor dem Livegang |
| Dateiannahme und Auslieferung (Upload in Stücken, `Media/Uploads`, `SvgCheck`, `PdfCheck`, `Files`, Range, Köpfe, `media/.htaccess`) | **Fable** | vor dem Livegang |
| Geheimnisse (`.env`-Weg, `deploy.sh`/`einrichten.sh`, `LK_APP_KEY`-Umgang, Repo-Scan) | **Fable** | vor dem Livegang und vor dem Öffentlichschalten |
| `.htaccess`, Köpfe, Docker-/Compose-Änderungen, Skripte, Workflow, Dokumente | **Opus** | je Block |
| Browser-Abnahme (Abschnitt 6) | Lutz und zweite Person | vor dem Livegang |

Nach der Modellregel: **eine** Opus-Runde je Block, zweite nur bei Befunden mittel oder höher; veröffentlicht wird gesammelt am Ende (Teilblöcke zusammen). Fable nur bei den Punkten oben (alle kritisch).

## 8. Teilschritte, Aufwand, Reihenfolge

Schätzung in „Sitzungen“ (eine kurze Sonnet-Sitzung ≈ ein Arbeitsblock). Bauen: Sonnet; Prüfung: Opus/Fable; **jeder Block einzeln beauftragt**.

| Nr. | Teilschritt | Inhalt | Aufwand | Risiko |
|---|---|---|---|---|
| **8a** | **Härtung** | Köpfe auch für Direktauslieferung (`.htaccess`), COOP/CORP, `.htaccess`-Ergänzungen, `.user.ini`, Fehlerseiten (`ErrorDocument`), neutrale Konfig-Fehlerseite, Systemprüfung im Admin, Admin-Ratenbegrenzung, Speicherwarnung, `tools/livepruefung.sh`, Docker-Härtung (`compose.yaml`), `NOTICE`, SPDX-Köpfe | 2 | mittel |
| **8b** | **Sicherung und Wiederherstellung** | `/admin/sicherung`, Abrufschlüssel, Abruf der Datenbank und Originale, Prüfsummenliste, Protokolleinträge, Anzeige „letzte Sicherung“; Homeserver-Skript (außerhalb des Repos oder `tools/sicherung-holen.sh`); `docs/betrieb/Wiederherstellung.md`; optional „Sicherung einspielen“ (E12) | 2 bis 3 | **hoch** (Zugang zu allen Daten) |
| **8c** | **Docker-Probe auf pve10** | Image bauen, Compose/Proxy/Port, Neustart-, Update- und Lasttests, **Wiederherstellungsprobe (2.5)** | 1 | mittel |
| **8d** | **Livegang-Vorbereitung** | Weiterleitungsprüfung gegen alte URL-Liste, `LK_BASE_URL`-Umstellung als Skript/Anleitung, Betriebsdokument `docs/betrieb/Livegang.md` (Ablauf 3.5 mit Rückfall), Abnahmeliste als Datei, Kontrolle der Testdaten | 1 | mittel |
| **8e** | **Prüfungen** | Opus (Block 8a, 8c, 8d), **Fable** (8b, Anmeldung, Dateiannahme/Auslieferung, Geheimnisse), Befunde beheben | 2 bis 3 | – |
| **8f** | **Abnahme durch Lutz** | Abschnitt 6 auf der Testinstallation; erster Import (Lutz) und Durchsicht liegen davor | Lutz | – |
| **8g** | **Livegang** | Abschnitt 3.5, **nur nach Freigabe durch Lutz**, zwei Stunden Fenster | Lutz + 1 | **hoch** |
| **8h** | **Nach dem Livegang** | Beobachtung 14 Tage, WordPress entfernen (3.8), FTP-Passwort erneuern, Version `0.2.0` taggen (privat), `CHANGELOG` | 1 | niedrig |
| **8i** | **GitHub öffentlich** | Abschnitt 5.2 (nach Livegang und Stabilität; getrennt freigeben) | 1 bis 2 | mittel (Geheimnisse) |

**Reihenfolge:** 8a → 8b → 8c (8a und 8b können parallel, **nicht** in denselben Dateien) → 8d → 8e → 8f → 8g → 8h → 8i. Der Import auf dem Testserver (Lutz) und die Durchsicht der Inhalte laufen **parallel** ab sofort; sie sind Voraussetzung für 8f.
**Passkey (Schritt 6)** kommt danach, nicht Teil dieses Entwurfs.

**Risiken**
- *Sicherungsweg wird neue Angriffsfläche* → Token getrennt, nur lesend, abschaltbar, Raten-Sperre, Fable-Prüfung, kein Admin-Recht.
- *Gemeinsamer Server* → 3.9; Restrisiko wird Lutz ausdrücklich bestätigt (E3).
- *Umschalten bricht Weiterleitungen oder Bilder* → Prüfskript, Rückfall in Minuten.
- *Daten nicht wiederherstellbar* → Probe ist Pflicht vor dem Livegang.
- *Schlüssel verloren* → Passwort-Manager + Papier, getrennt von der Sicherung.
- *Zugangsdaten in Chats/Repo* → Regeln der globalen CLAUDE.md, Geheimnis-Scan.
- *`.user.ini` oder Kopfzeilen wirken auf all-inkl anders als gedacht* → in 8a wird **gemessen**, nicht angenommen.

## 9. Testplan (nur in Docker, siehe Projekt-`CLAUDE.md`)

Alle automatischen Tests: `docker run --rm -v "$PWD":/app -w /app lichtkammer-test php tests/run.php`. Neue Tests (Datei `tests/haertung.php`, `tests/sicherung.php`):

- **Köpfe:** Antwortköpfe von Admin, Veröffentlichung (fertiges HTML über die Dev-Auslieferung), 404, Bild; CSP/HSTS/COOP/CORP vorhanden; `X-Robots-Tag` nur bei `LK_NOINDEX=1`; `robots.txt` je Schalter.
- **`.htaccess`-Regeln:** Ein Test mit echtem Apache im Container (`docker/` baut es schon): `/app/.env`, `/data/`, `/import/manifest.json`, `/x.sqlite`, `/.git/HEAD`, `/media/x.php` → 403/404; Weiterleitung `http→https`, `www→apex` (Test mit `X-Forwarded-Proto`).
- **Fehlerseiten:** Im Produktivbetrieb enthält die Antwort bei Ausnahme **keinen** Pfad/Meldungstext; bei `LK_ENV=development` schon; Konfigurationsfehler neutral ohne Token, mit Grund bei gesetztem Setup-Token.
- **Sicherung:** ohne Schlüssel 404; falscher Schlüssel 401 und Sperre nach N Versuchen; richtiger Schlüssel liefert Datenbank (per `VACUUM INTO`, **liest sich als gültige SQLite**, enthält Konten), Originalliste mit SHA-256, Einzelabruf mit Range; Kein Pfadwechsel (`../`), nur numerische IDs; Admin-Sitzung **ohne** Passwortbestätigung bekommt keinen Download; Protokolleintrag ohne Geheimnis; Schlüssel nie in Antwort/Protokoll/Fehlertext.
- **Wiederherstellung:** Test baut aus Sicherung eine zweite Instanz (anderer `LK_DATA_DIR`), prüft Anmeldung mit demselben Schlüssel, Fotozahl, Prüfsummen; mit falschem Schlüssel: Anmeldung scheitert sauber, Fotos bleiben lesbar.
- **Livegang-Umstellung:** `LK_BASE_URL`-Wechsel → neues Veröffentlichen erzeugt Sitemap/canonical/`robots.txt` mit neuer Adresse; alte Cookies unter anderem Host werden nicht angenommen; Weiterleitungsliste aus Beispiel-Manifest: jede alte URL → 301 → 200.
- **Docker:** Healthcheck, Neustart behält Daten, `read_only`-Test, `LK_TRUSTED_PROXIES` leer → `X-Forwarded-For` ignoriert (Test vorhanden aus Schritt 2, erweitern um den Proxy-Pfad).
- **Skripte:** `shellcheck` für `deploy.sh`, `tools/*.sh`; `release-zip.sh --pruefen` darf nichts Neues finden (enthält `NOTICE`, kein `.user.ini` mit Geheimnissen).
- **Handtests** (nicht automatisierbar, Abnahmeliste 6): Browser, Handy, Lighthouse.

## 10. Was dieser Entwurf bewusst nicht tut

Kein Passkey (Schritt 6, danach). Kein Kontaktformular, keine Phase-2-Funktionen. Keine eigene Admin-Domain. Keine Besucherstatistik. Keine Änderung der Anwendungsstruktur. Kein Hochladen, kein Import, kein DNS, kein KAS-Eingriff vor der Freigabe.

## 11. Entscheidungen von Lutz (mit Empfehlung)

- **E1 Umzug laut `13` bestätigen:** Alles zieht um, größte WordPress-Auflösung, Kamera/Objektiv öffentlich (GPS nie), Namen wie auf der alten Seite. **Empfehlung: ja** (Lutz hat am 16.11. so entschieden; bitte nur kurz bestätigen, nachdem der Import auf dem Testserver gelaufen ist und das Ergebnis gesehen wurde).
- **E2 KAS-Zielordner der Domain frei wählbar?** Lutz prüft im KAS (Domain → Zielordner ändern) **ohne etwas zu ändern**, ob `br-foto.com` auf den Ordner `lichtkammer.br-foto.com` zeigen darf. **Empfehlung:** Weg A (3.2); sonst Weg C.
- **E3 Gemeinsamer Server (von Lutz gestrichen, 19.11.2026: „Vergiss WordPress“; nichts dazu bauen oder vorbereiten, `docs/betrieb/WordPress-haerten.md` bleibt nur als optionale Anleitung liegen):** (a) all-inkl-Support fragen, ob br-foto.com einen **eigenen Systembenutzer/KAS-Account** bekommen kann (Kosten?), (b) sonst WordPress **früh stilllegen** (Plan: 14 Tage) und das Restrisiko des gemeinsamen Benutzers bewusst tragen, (c) oder Docker auf pve01 (aufwendiger, trennt sauber). **Empfehlung: (a) fragen, bis dahin (b).** Bitte bestätigen, dass (b) für die Übergangszeit in Ordnung ist.
- **E4 Adresse:** `br-foto.com` **mit oder ohne `www.`** als Hauptadresse (Empfehlung: **ohne `www.`**, `www.` → 301) und Subdomain `lichtkammer.br-foto.com` nach dem Livegang (Empfehlung: **301 auf die Hauptdomain** für vier Wochen, dann abschalten).
- **E5 Version:** `0.1.0-rc1` nicht beibehalten, stattdessen **`0.2.0` zum Livegang**, `1.0.0` mit dem öffentlichen Release (Empfehlung). Tag `v0.1.0-rc1` und seine Pakete behalten oder löschen (Empfehlung: **behalten**, es ist ein Probelauf).
- **E6 Testdaten:** Protokoll der Testphase mitnehmen, Testkonten vor dem Livegang löschen (Empfehlung: **ja, ja**).
- **E7 Öffentliches Repo:** (a) mit Historie veröffentlichen, wenn der Scan sauber ist (Empfehlung), (b) `docs/planung/` bleibt öffentlich oder wird in ein privates Archiv verschoben (Empfehlung: **`docs/planung` privat halten**, weil sie Bezug auf Personen und den Server enthalten, öffentlich bleiben `docs/betrieb` und die Anleitung ohne Personenbezug).
- **E8 `/health`:** Version nicht öffentlich zeigen, nur `ok` (Empfehlung); `deploy.sh` prüft die Version über einen Header mit Abrufschlüssel oder über `/admin`-Systemprüfung.
- **E9 Aufbewahrung Protokoll:** 24 Monate, danach Export und Kürzung (Empfehlung) oder unbegrenzt (Datenschutzerklärung dann entsprechend).
- **E10 Schlüsselrotation (Umschlüsselungslauf, Befund C7):** erst nach dem Livegang bauen (Empfehlung), jetzt nur Anleitung für den Notfall.
- **E11 Sicherungsweg:** (a) Abruf mit Token über die App (Empfehlung), (b) nur manueller Download im Admin durch Lutz (weniger Angriffsfläche, aber vergessliche Sicherung). Zusätzlich KAS-eigene Datensicherung des Webspaces prüfen und ob sie aktiv ist.
- **E12 „Sicherung einspielen“ im Admin** bauen (Empfehlung: **ja, nur für Administratoren, mit Passwort + TOTP, Upload in Stücken**) oder nur Einmal-Skript per FTPS (weniger Code, mehr Handarbeit im Ernstfall).
- **E13 Alte Bild-URLs** (`/wp-content/uploads/…`): nicht umleiten (Empfehlung), nur nach 7 Tagen Auswertung entscheiden.
- **E14 Docker-Port:** `127.0.0.1:8080:80` mit Proxy auf demselben Host, oder gar kein Port bei Proxy im selben Docker-Netz (Empfehlung: **ohne Port im gemeinsamen Netz mit Caddy**, `127.0.0.1:8080` als Alternative). Test auf pve10, Produktion pve01 erst nach GitHub-Freigabe und deinem ausdrücklichen Ja.
- **E15 Zeitfenster Livegang:** Wochentag und Uhrzeit (Empfehlung: **Dienstag bis Donnerstag, vormittags**, beide Personen erreichbar, nicht vor Reisen/Feiertagen), und wer den Rückfall auslösen darf (Empfehlung: Lutz allein).
- **E16 Quellcode-Link (AGPL §13)** im Admin-Fuß (Empfehlung: **ja**) und `SECURITY.md` mit `info@lurohome.de`.
- **E17 Prüfung dieses Entwurfs:** Opus prüft ihn **vor** dem Bauen (Empfehlung, Risiko hoch). Danach gibst du den Block 8a und 8b zum Bauen frei, **einzeln**.
- **E18 Offenes aus Entwurf `16`** nach dem Livegang als Phase-2-Aufgaben (Abschnitt 12, Empfehlung: ja).
- **E19 Ordner der Originale** (neu aus Prüfung 21 H1): `LK_ORIGINALS_DIR` oberhalb des Webroots setzen, **bevor** der Import läuft (Empfehlung: ja).

## 12. Offene Punkte aus früheren Prüfungen, die diesen Schritt berühren

- `08` C6 (Fehlerseite nennt Grund) → 1.6; C7 (Schlüsselrotation) → 1.11/E10; B3 (Wettlauf-Tests nacheinander statt Prozesse) → in 8e mitprüfen.
- `19`/`CLAUDE.md`: Browser-Test der Medienverwaltung durch Lutz und Fable-Prüfung von Dateiannahme und Auslieferung stehen aus → Abschnitte 6 und 7.
- Entwurf `15` (Verteilung) bleibt gültig; dieser Entwurf ergänzt ihn nur bei Version (5.1) und Öffentlichschalten (5.2).
- Offen aus Entwurf `16` (Detailfeld, Verknüpfungen „Knopf-Block wählt Datei“, „Logo aus Medien“, „Verwendet in“, Einzel-Upload ohne Skript): **nicht** Bedingung für den Livegang; werden nach dem Livegang als Phase-2-Aufgaben gesammelt (E18: einverstanden?).
