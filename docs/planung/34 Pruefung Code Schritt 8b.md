# Prüfung Code Schritt 8b (Opus)

Stand: 19.11.2026. Geprüft: Commit `bca8b42` (Schritt 8b Sicherungsweg und Rest von 8a). Maßstab: Entwurf `20`, Prüfung `21`, Prüfung `22`, `docs/betrieb/Wiederherstellung.md`. E3 (WordPress härten) ist von Lutz gestrichen und wurde nicht geprüft.
Tests vorher: 1 442 Prüfungen grün. Nach den Nachbesserungen: **1 444 Prüfungen, 0 Fehler** (`docker run --rm -v "$PWD":/app -w /app lichtkammer-test php tests/run.php`).
Nichts hochgeladen, nichts am Server geändert.

## Ergebnis in Kürze

Der Sicherungsweg ist sorgfältig gebaut: nur für Administratoren, mit frischer Bestätigung (Passwort und Code), an die Sitzung gebunden, ratenbegrenzt, protokolliert nur mit Zahlen, Pfade nur aus der Datenbank, Symlinks werden nicht ausgeliefert, alles wird gestreamt. Das Paketformat passt zu `Restore`; der Rundlauf (erzeugen, entpacken, auf frischer Installation einspielen, Originale zurückholen) ist getestet.

Gefunden: **zwei Fehler mit Risiko mittel** (B1 Fehlerseite fehlt im Repository, B2 Datenbankkopie bleibt nach Abbruch liegen) und **zwei kleine** (N1, N2). Alle vier sind behoben und getestet. Nichts Größeres zurückzugeben.

## Praktische Prüfung im echten Container

Zusätzlich zu den Tests habe ich das Image aus dem Dockerfile gebaut und **mit den Härtungsoptionen aus `compose.yaml`** gestartet (`no-new-privileges`, `cap_drop: ALL` plus `CHOWN/SETUID/SETGID/NET_BIND_SERVICE`, 1 GB Speicher). Dann mit `curl` und einem frischen Testkonto (Einrichtung mit Token, TOTP aus dem Schlüssel berechnet), echtem Apache und echter `.htaccess`:

| Prüfung | Ergebnis |
|---|---|
| Container startet mit eingeschränkten Fähigkeiten, Foto-Upload und Bildverarbeitung laufen | ja |
| Ohne Anmeldung: `/admin/sicherung`, `/originale`, `/original/1`, `/admin/system`, POST `/paket` | alle 303 zur Anmeldung |
| Angemeldet, ohne Bestätigung: Paket, Teil, Einzelabruf | alle 403 |
| Paket ohne CSRF-Token (bestätigt) | 403 |
| Bestätigung: Cookie `lk_bk` mit `HttpOnly; SameSite=Strict; Max-Age=600` | ja |
| Derselbe Code ein zweites Mal (Wiederholschutz, kontoweit über `totp_last_step`) | abgelehnt („Der Code stimmt nicht“) |
| Paket: 200, `attachment`, `no-store`, `nosniff`, `no-referrer`, Länge stimmt; `tar -t` zeigt nur `MANIFEST.json`, `originals.sha256`, `lichtkammer.sqlite` (keine Originale) | ja |
| Teil `?nach=0`: ein Original unter `originals/xx/…`, `sha256sum -c` mit der Liste aus dem Paket | OK |
| `?nach=<letzte Nummer>` und `?nach=abc` | 404 |
| Einzelabruf `Range: bytes=0-99` und `bytes=-10` | 206 mit richtigem `Content-Range` |
| Range hinter dem Ende, Mehrfach-Range | 416 |
| Abbruch (`--max-time 3`) und Fortsetzen (`curl -C -`): Datei byte-gleich mit dem Original | ja |
| `lk_bk`-Cookie in einer zweiten Sitzung desselben Kontos | 403 |
| Protokoll: nur `bytes=…,files=…,orig=…`, `after=…,n=…`, `photo=…` | ja |
| Systemprüfung: keine Schlüssel, Token oder absoluten Pfade | ja |
| `/app/.env`, `/.user.ini` → 403 mit eigener Seite „Nicht verfügbar“ (ErrorDocument greift) | ja |
| COOP und CORP an PHP-Antworten | ja |
| Abbruch mitten im Paket (200 MB private Datei, 500 kB/s, nach 3 s abgebrochen) | **vorher: Datenbankkopie blieb liegen (B2); nachher: gelöscht** |

## Befunde

### B1 – `public/fehler.html` ist nicht im Repository · Risiko **mittel** · **behoben**

- **Wo:** `.gitignore` sperrt `/public/*.html` (für fertige Seiten). Die neue Fehlerseite fiel darunter und wurde nie eingecheckt; sie lag nur lokal. `.dockerignore` hatte die Ausnahme, `.gitignore` nicht.
- **Folge:** `tools/release-zip.sh --pruefen` (baut aus `git archive`) bricht mit „public/fehler.html fehlt“ ab; ein Image, das aus einem frischen Klon gebaut wird (z. B. später in GitHub Actions), hat keine Fehlerseite. Dann zeigt Apache für 403/500 statt der eigenen Seite einen Hinweis, dass auch das `ErrorDocument` fehlt. `deploy.sh` hätte sie hochgeladen, weil es den lokalen Ordner spiegelt; deshalb wäre es auf dem Testserver nicht aufgefallen.
- **Wiederholbarkeit:** immer (`git check-ignore -v public/fehler.html` → `.gitignore:27`).
- **Behebung:** `!/public/fehler.html` in `.gitignore`, Datei eingecheckt. Test ergänzt (`.gitignore` enthält die Ausnahme).

### B2 – Abgebrochener Paket-Download lässt die Datenbankkopie liegen · Risiko **mittel** · **behoben**

- **Wo:** `Response::send()` (Zweig `emit`) und `Backup::package()`.
- **Was:** Das Löschen der `VACUUM INTO`-Kopie stand in einem `finally`. Bricht der Browser ab, beendet PHP das Skript aber beim nächsten Schreiben sofort (Standard `ignore_user_abort = Off`); `finally` läuft dann nicht. Außerdem prüfte `emitEntry` den Abbruch erst nach einem ganzen Eintrag.
- **Folge:** Eine vollständige Kopie der Datenbank (alle Konten, Protokoll) bleibt als `sicherung-….tmp` im Datenordner liegen, bis jemand frühestens eine Stunde später das nächste Paket erzeugt (`sweepTemp`). Rechte 0600 und außerhalb des Webroots, also keine zusätzliche Angriffsfläche gegenüber der laufenden Datenbank, aber gegen den Entwurf („nach dem Senden gelöscht“) und auf all-inkl Platz.
- **Wiederholbarkeit:** immer bei Abbruch, sobald das Paket größer ist als der Puffer des Netzes (im Container mit 200 MB nachgestellt).
- **Behebung:** Im `emit`-Zweig `ignore_user_abort(true)`; Ausnahmen nach gesendeten Kopfzeilen werden nur noch protokolliert („Download beendet: abgebrochen“), statt als unbehandelter Fehler zu enden. `emitEntry` prüft den Abbruch jetzt nach jedem Block (64 KB). Im Container nachgeprüft: keine Restkopie mehr.

### N1 – „Zuletzt heruntergeladen“ auch bei abgebrochenem Paket · Risiko **niedrig** · **behoben**

- **Was:** `noteTaken()` lief, bevor das Paket gesendet war. Ein abgebrochener Download setzte den Merker, Systemprüfung und Seite zeigten „frisch“.
- **Behebung:** Der Merker wird erst nach dem letzten Block gesetzt und nur, wenn die Verbindung noch steht. Test ergänzt („erst nach vollständigem Senden“). Im Container nachgeprüft: nach Abbruch kein Merker.
- **Bleibt:** Das Paket allein (ohne Originale) zählt als Sicherung. Für die Anzeige ist das in Ordnung; wer genau sein will, merkt sich später „Originale bis Foto-Nummer …“ (für den Token-Abruf E11 a ohnehin nötig).

### N2 – `Wiederherstellung.md` beschreibt die Verschlüsselung der Sicherung irreführend · Risiko **niedrig** · **behoben**

Der Satz „mit dem Schlüssel `LK_APP_KEY` … wäre sie voll lesbar“ legt nahe, ohne Schlüssel sei sie unlesbar. Die SQLite-Datei ist unverschlüsselt; nur die TOTP-Geheimnisse sind verschlüsselt. Satz richtiggestellt. Für Lutz wichtig: Die Sicherung gehört deshalb wirklich auf einen verschlüsselten Datenträger.

## Beurteilung der Schwerpunkte

### 1. Sicherungsweg

- **Rolle:** Alle fünf Wege sind im Router mit Rolle `admin` eingetragen; Redaktion bekommt 403 (Test), Unangemeldete die Anmeldung.
- **Bestätigung:** `confirmSensitive` nutzt `reauthenticate`: Zähler der zweiten Stufe (Sperre nach Fehlversuchen), Passwort, Code mit kontoweitem Wiederholschutz (`UPDATE … WHERE totp_last_step < ?`). Cookie-Wert `<bis>.<HMAC>` über Sitzungs-Hash, Konto und Ablauf; eine Verlängerung oder ein Cookie aus einer anderen Sitzung gilt nicht (Test und Container). Abmelden beendet die Sitzung und damit die Bestätigung.
- **CSRF beim Paket:** POST mit dem allgemeinen CSRF-Token der Sitzung (Router, `hash_equals`). In Ordnung.
- **GET für Originale (Frage aus dem Auftrag):** **vertretbar, kein POST/Token nötig.** Begründung: Sitzungs-Cookie und `lk_bk` sind beide `SameSite=Strict`; eine fremde Seite kann den Abruf also nicht mit Anmeldung auslösen. Selbst wenn, landet die Datei im Browser von Lutz, nicht beim Angreifer: Die Antwort ist `attachment`, `Cross-Origin-Resource-Policy: same-origin`, kein CORS. Der GET verändert nichts außer Protokoll und Zähler. Die Adresse enthält nur die Foto-Nummer, kein Geheimnis; `Referrer-Policy: no-referrer` und `Cache-Control: private, no-store` verhindern Weitergabe und Zwischenspeicher. GET ist zudem nötig, damit Browser und Download-Programme mit Range fortsetzen können.
- **Pfade:** nur aus der Datenbank, nur nach dem Muster `xx/<24 hex>.<ext>` (`Restore::ORIG_REL`), `realpath` muss im Ordner der Originale liegen, Symlinks werden weder einzeln noch im Teil ausgeliefert (Tests). Private Dateien nur mit sicheren Zeichen, ohne versteckte Namen, ohne Symlinks.
- **Speicher und Zeit:** alles in 64-KB-Blöcken gestreamt, Länge vorab berechnet; wird eine Datei unterwegs kürzer, wird mit Nullen aufgefüllt (Archiv bleibt gültig, die Prüfsummenliste deckt es auf). `set_time_limit(0)`; die eigentliche Grenze auf all-inkl ist die des Webservers (siehe Fable-Liste). Teile standardmäßig 150 MB.
- **Ratenbegrenzung:** 6 Pakete bzw. 120 Original-Abrufe je 15 Minuten und Konto; jeder Abruf zählt, auch jede Range-Anfrage. Für Lutz reicht das; ein Download-Programm mit vielen parallelen Stücken kann die 120 erreichen (niedrig, siehe offene Hinweise).
- **Köpfe:** `Content-Type: application/octet-stream`, `Content-Disposition: attachment` mit festem, ungefährlichem Namen, `nosniff`, `no-store`, `no-referrer`, `Accept-Ranges` beim Einzelabruf. In Ordnung.

### 2. Format und Rundlauf

`MANIFEST.json` (Format 1, Schema, Version, Zeit), `originals.sha256` im Format von `Restore`, `files/`, `lichtkammer.sqlite` aus `VACUUM INTO` (nie die laufende Datei). Der Test spielt das Paket samt Originalen auf einer frischen Installation ein, meldet sich mit altem Konto und zweitem Faktor an, vergleicht Prüfsummen, und lehnt einen falschen `LK_APP_KEY` sauber ab. Im Container: Paket und Teil passen zusammen (`sha256sum -c` OK). Der TAR-Schreiber ist ustar mit Präfix für lange Namen; GNU tar und bsdtar lesen ihn.

### 3. Fehlerseite C6

`showConfigDetails()` zeigt den Grund nur bei `LK_ENV=development`, gesetztem `LK_SETUP_TOKEN` oder ganz ohne `LK_APP_KEY`. Die Meldungen der `ConfigError`s enthalten keine Pfade oder Werte, nur Namen von Einstellungen. Die drei Ausnahmen sind nicht missbrauchbar: Wer `.env` nicht ändern kann, kann sie nicht auslösen, und sie verraten nur, welche Einstellung fehlt. Ohne Schlüssel erscheint wie bisher die Einrichtungshilfe mit zufällig erzeugten Werten (nichts wird gespeichert). Restpunkt: Bleibt `LK_SETUP_TOKEN` nach der Einrichtung stehen, nennt die Seite Gründe; die Systemprüfung warnt davor (`./deploy.sh --token-entfernen`).

### 4. `.htaccess`, `.user.ini`, `fehler.html`, COOP/CORP, Docker

- `ErrorDocument 403/500/503 /fehler.html`: greift im Container (eigene Seite bei `/app/.env`). Die Seite ist statisch, ohne Skript, nutzt nur `/assets/css/app.css`. Auf all-inkl ist `ErrorDocument` in `.htaccess` üblich erlaubt; **auf dem Testserver mit `tools/livepruefung.sh` nachsehen** (die Prüfung ist dort eingebaut).
- `.user.ini` setzt nur `display_errors=Off`, `log_errors=On` und einen wirkungslosen Merker; sie überschreibt keine Hostereinstellung, die Lutz braucht (Speicher, Laufzeit, Upload bleiben). Sie ist per `.htaccess` gesperrt (403 im Container).
- COOP/CORP `same-origin` an allen PHP-Antworten; `/media` mit CORP `same-site`. Für eine Seite ohne Einbettung in fremde Seiten passend.
- `compose.yaml`: Container läuft lokal mit den Einschränkungen (Upload, Bildverarbeitung, Sicherung geprüft). Auf pve10 mit Volumes und Proxy bleibt es Teil von 8c; `read_only` ist zu Recht noch aus.
- `.dockerignore` und jetzt auch `.gitignore` nehmen `fehler.html` aus.

### 5. Systemprüfung

Nur für Administratoren (Rolle im Router, Test für Redaktion). Zeigt Zustände, PHP-Version, Grenzen, Rechte als Oktalzahl, Speicherplatz, letzte Sicherung, Version. Keine Schlüssel, Token oder absoluten Pfade (Test und Container). Die PHP-Version ist für einen Administrator unbedenklich.

### 6. Geheimnisse im Diff

Keine Schlüssel, Token oder Passwörter. `compose.yaml` ohne Werte, `.env.example` nur mit auskommentierten Vorgaben. Testwerte nur in den Tests.

## Offene Hinweise (niedrig, nicht behoben)

- **N3 – Range zählt gegen die Grenze:** Jede Range-Anfrage zählt als einer der 120 Original-Abrufe. Mit Browser oder `curl -C -` unkritisch; ein Download-Programm, das eine Datei in viele Stücke teilt, kann die Grenze erreichen. Für den Token-Abruf (E11 a) neu bedenken.
- **N4 – TAR-Kopf wird erst beim Senden geprüft:** Ein zu langer Name in `files/` oder eine Datei über 8 GB würde erst mitten im Strom eine Ausnahme werfen (Download bricht ab, B2-Behebung räumt auf). Die App erzeugt nur kurze Namen, Fotos über 8 GB gibt es nicht; vorab prüfen wäre sauberer.
- **N5 – Systemprüfung zählt den Platz bei jedem Aufruf neu** (`usedBytes(..., true)`), das Warnband einmal täglich mitten in einer normalen Admin-Anfrage. Bei einigen Zehntausend Dateien auf all-inkl ein paar Sekunden; bei Bedarf in einen eigenen Knopf auslagern.
- **N6 – Farbe „gelb nach 3 Tagen“:** `Wiederherstellung.md` sagt gelb, die Seite nutzt die Fehlerfarbe (`notice error`). Nur Wortwahl.
- **N7 – `nosniff` doppelt** (wie `22` N4): an den Sicherungsantworten setzt PHP den Kopf zweimal (`backupHeaders` und Standardköpfe) plus Apache. Unschädlich.

## Für Fable: besonders prüfen

1. **Zeitgrenze auf all-inkl:** Bricht der Webserver (FastCGI/FPM, nicht PHP) lange Downloads nach 60 s ab? Mit einem Teil von 150 MB messen; ggf. `LK_BACKUP_PART_MB` kleiner. Fortsetzen geht nur beim Einzelabruf, ein Teil wird ganz neu geladen.
2. **Bestätigungs-Cookie:** HMAC-Bindung an `id_hash`, Konto und Ablauf; Verhalten bei Sitzungsrotation (Rechtewechsel, Passwortänderung), ob die Bestätigung danach erlischt. Ob ein Zeitfenster von 10 Minuten ohne Einmal-Nutzung reicht (der Entwurf sprach von „Einmal-Link“).
3. **`ignore_user_abort(true)` im `emit`-Zweig (B2):** dass ein abgebrochener Download nicht unnötig lange weiterläuft (Abbruch wird je 64 KB geprüft) und keine Ausnahme nach gesendeten Köpfen etwas preisgibt.
4. **GET mit Cookie statt POST/Token** für Originale: meine Begründung oben gegenlesen, besonders unter der Annahme, dass `SameSite=Strict` greift (alle aktuellen Browser).
5. **Inhalt des Pakets:** Die Datenbankkopie enthält Sitzungs-Hashes, vertraute Geräte, offene Einladungen und Wiederherstellungscodes (Hashes). `Restore` verwirft Sitzungen, Geräte und Einladungen (`21` M6); prüfen, ob das für alle Tabellen mit Geheimnis-Bezug gilt.
6. **`ErrorDocument` und `.user.ini` auf all-inkl** messen (`tools/livepruefung.sh` und Systemprüfung); keine 500 für die ganze Seite, falls `ErrorDocument` dort nicht erlaubt wäre.
7. **Token-Abruf E11 a**, falls Lutz ihn freigibt: eigener Schlüssel nur für die Sicherungswege, eigene Sperre, kein Admin-Recht, Range-Zählung (N3).

## Geändert in dieser Prüfung

- `.gitignore`: Ausnahme `!/public/fehler.html`; `public/fehler.html` eingecheckt (B1).
- `app/src/Response.php`: `ignore_user_abort(true)` und Fehlerprotokoll im `emit`-Zweig (B2).
- `app/src/Backup/Backup.php`: Abbruchprüfung je Block; Merker „zuletzt“ erst nach vollständigem Paket (B2, N1).
- `app/src/Admin/BackupPages.php`: `noteTaken()` vor dem Senden entfernt (N1).
- `docs/betrieb/Wiederherstellung.md`: Satz zur Verschlüsselung (N2).
- `tests/sicherung.php`: zwei Prüfungen (B1, N1).
