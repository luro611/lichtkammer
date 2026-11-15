# Prüfung Schritt 1 (Gerüst) und Entwurf Schritt 2 (Anmeldung)

Stand: 15.11.2026. Geprüft von Hand (Opus-Rolle): Entwurf `05` gegen Plan `03` Abschnitt 6 und die Umgebung; Diff `7105817..8b33266`. Tests: 30 von 30 grün (`php:8.5-cli`). Zusätzlich wurde das Gerüst in `php:8.5-apache` gestartet und die `.htaccess`-Regeln mit `curl` geprüft: `/app/.env`, `/app/src/…`, `/.env`, `*.sqlite`, `/media/x.php`, `/media/.htaccess` → 403; `/health`, `/`, CSS → 200; `/index.php/foo` → 404. Nichts hochgeladen, `deploy/.env` nicht gelesen.

## 1. Empfehlung vorab

**Der Entwurf ist nach den eingetragenen Korrekturen baureif, aber das Risiko bleibt hoch.** Vor dem Bauen reicht diese Prüfung; **nach dem Bauen und vor dem Livegang sollte Fable (bzw. Opus mit `/code-review max`) den fertigen Code prüfen**, vor allem: Wettläufe (zwei PHP-Prozesse), Gleichbehandlung bekannt/unbekannt, Sitzungswechsel, Einrichtung/Rücksetzen. Der Entwurf selbst braucht keine zweite Entwurfsrunde, wenn Lutz die Entscheidungen in Abschnitt 4 trifft.

## 2. Befunde zum Entwurf (alle im Entwurf mit „(Prüfung)“ eingearbeitet)

### Hoch
1. **Konten ohne TOTP und TOTP-Einrichtung nur mit Passwort.** „Einrichtungsfolge nach Passwortprüfung“ ließ Passwortdiebe ein eigenes TOTP anlegen (auch nach einem Admin-Reset). Neu: Konto entsteht erst nach bestätigtem TOTP in einer Transaktion (Setup, Einladung); Reset über Rücksetz-Link, der Altgeheimnis, Codes und Sitzungen sofort entwertet.
2. **Benutzer erkennbar an der Sperre.** `users.failed_count`/`locked_until` gab es nur für bekannte Namen; die Sperre verriet das Konto. Neu: alle Zähler in `login_attempts` mit HMAC des Namens, auch für Unbekannte; Scheinhash mit denselben Parametern; gesperrte/deaktivierte Konten laufen durch dieselbe Prüfung.
3. **Konto-Aussperrung durch Fremde.** 10 Fehlversuche sperrten das Konto für 15 Minuten, Fremde konnten Lutz dauerhaft aussperren. Neu: Passwortstufe zählt je Name+Adresse und je Adresse; je Name allein nur Verzögerung. Zweite Stufe streng je Konto.
4. **Login-CSRF fehlte.** Vor der Anmeldung gibt es keine Sitzung, also kein Sitzungs-Token. Neu: zustandsloses signiertes Token plus Double-Submit-Cookie (auch Setup/Einladung/Reset).
5. **Rücksetzweg und `LK_APP_KEY`.** Schlüsselverlust macht alle TOTP und Codes unbrauchbar, Admin kann sich dann nicht mehr anmelden. Neu: eingebaute, normalerweise tote Route `/admin/reset` mit `LK_RESET_TOKEN` (einmalig, Hash in `meta`), Schlüssel getrennt sichern, Schlüssel von Lutz erzeugt (ohne SSH), Teilschlüssel per HKDF, versioniertes Verschlüsselungsformat für spätere Rotation, Startprüfung des Schlüssels.
6. **Zwei gleichzeitige Einrichtungen → zwei Administratoren.** Neu: `BEGIN IMMEDIATE`, `users` in der Transaktion erneut auf leer geprüft; Route liefert 404 (auch für POST, nicht 405).

### Mittel
7. **IP-Präfix:** /24 für Sperren ist bei Mobilfunk (CGNAT) zu grob (Fremde sperren beide Konten). Neu: Zähler mit voller IPv4/IPv6-/64 als HMAC, nur das Protokoll mit /24 und /48. IPv4-in-IPv6 normalisieren.
8. **Quelladresse hinter Proxy:** Der Entwurf wollte `X-Forwarded-For` in PHP lesen (linker Eintrag ist frei erfindbar). Das Docker-Image hat schon `mod_remoteip`; die App nutzt nur `REMOTE_ADDR`. `RemoteIPInternalProxy` ist das ganze private Netz; vor dem Livegang auf den Proxy einschränken.
9. **Cookie-Präfix `__Host-`:** Entscheidung per Anfrage bzw. `LK_ENV` hätte bei vergessenem `LK_TRUST_PROXY` still schwächere Cookies gesetzt. Neu: nach `LK_BASE_URL`; bei Widerspruch Anmeldung verweigern.
10. **Protokoll:** „nur Anhängen im Code“ schützt nicht gegen Programmfehler oder Dritte. Neu: SQLite-Trigger, HMAC-Kette, Prüfanzeige; keine rohen Benutzernamen aus Fehlversuchen; Zusammenfassen von Fehlversuchen gegen Datenbankfüllung. Streng unveränderbar ist es gegen jemanden mit Schlüssel *und* Datei nicht.
11. **Sitzung:** Alte `password_ok`-Zeile muss beim Stufenwechsel gelöscht werden (nicht nur neue ID); Bindung an IP/User-Agent nur protokollieren, nicht erzwingen (Netzwechsel); offene Weiterleitung (`next`) nur auf lokale Pfade; `last_seen_at` nur alle 60 s schreiben; Aufräumen ohne Cron bei Anmeldung; TOTP-Fehlversuche beenden die `password_ok`-Sitzung nach 5.
12. **Argon2 und all-inkl-Speicher:** 64 MiB je Prüfung; viele gleichzeitige Anmeldungen könnten Prozesse beenden. Neu: Zähler vor dem Hash plus globale Bremse.
13. **SQLite auf all-inkl:** `BEGIN IMMEDIATE`, `busy_timeout`, WAL nur nach Test (Netzlaufwerk möglich), Datenordner mit eigener `.htaccess`, Datei 0600.
14. **Recovery-Codes:** Eingabe normalisieren (Groß, Trenner, Verwechslungszeichen); Neuerzeugen nur mit vollem Login und Passwort; vergleichen gegen alle Codes mit `hash_equals`.

### Niedrig
15. `SameSite=Strict`: Links von außen zeigen kurz die Anmeldeseite (dokumentiert). Passwort NFC-normalisieren. Die Liste häufiger Passwörter nicht in `app/data/` (verwechselbar mit dem Datenordner), sondern `app/resources/`. Einladungslink steht im Webserver-Protokoll (24 h, einmalig). Uploads sollen bei ablaufender Sitzung fortsetzbar sein (Schritt 4).

## 3. Befunde zum Diff von Schritt 1

**Behoben (mit Prüfung, Tests grün):**
- **`.gitignore` hatte Schutzmuster verloren** (`.env.*`, `*.pem`, `*.key`, `id_rsa*`, `*.db`, `.claude/settings.local.json`, `._*`). Wiederhergestellt, dazu `/data/`, `/originals/`, `/public/media/*` bleiben.
- **`.dockerignore` schloss lokale Medien nicht aus:** Fotos in `public/media/` (git-ignoriert, aber nicht docker-ignoriert) wären ins Image gewandert. Ergänzt um `public/media/*` (außer `.htaccess`), `public/*.html`, `.env.*`, `*.pem`, `*.key`, `*.sqlite*`, `*.db`, `*.log`.

**Offen (nur gemeldet, kein Fehler in Schritt 1, aber vor oder in Schritt 2 zu erledigen):**
- **Router kennt nur `GET`** und liefert für eine bekannte Route mit falscher Methode 405. Für Schritt 2 braucht er `post()`; gesperrte Routen (`/admin/setup`, `/admin/reset`) müssen 404 liefern, nicht 405. (Mittel, im Entwurf vermerkt.)
- **Standard-`data/` liegt im Webroot** (all-inkl, wenn `LK_DATA_DIR` fehlt) und ist nur durch die Rewrite-Regel geschützt. Empfehlung: Admin startet auf all-inkl nur, wenn der Datenordner außerhalb des Webroots liegt (oder explizit `LK_ALLOW_DATA_IN_WEBROOT=1`) plus eigene `.htaccess` im Datenordner. Außerdem sollte `deploy.sh --jetzt` nach dem Hochladen prüfen, dass `/.env`, `/app/.env` und `/data/` nicht mit 200 antworten. (Mittel.)
- **`public/media/.htaccess` und `deploy.sh`:** `--exclude-glob media/*` könnte `media/.htaccess` mit ausschließen (lftp-Glob-Verhalten nicht belegt). Dann liefe PHP im Medienordner des Testservers. Auf dem Testserver prüfen, dass `/media/.htaccess` existiert; besser: die App legt die `.htaccess` beim Anlegen des Medienordners selbst an (Schritt 4). (Mittel, unverifiziert.)
- **Fehler vor dem `try` in `public/index.php`** (Config/Bootstrap) werden nicht abgefangen; mit `display_errors` des Hosts könnten Pfade erscheinen. Config setzt `display_errors` erst danach. (Niedrig: Grundeinstellung früh setzen.)
- **`X-Forwarded-Proto` wird jedem vertraut, der den Container direkt erreicht** (`ports: 8080`). Folge nur: HSTS/Secure-Cookie ohne TLS. Port 8080 nur lokal/zum Proxy öffnen. (Niedrig.)
- `/health` nennt die Version (für `deploy.sh` gewollt). `HSTS` ohne `includeSubDomains` und 1 Jahr: beim Testserver okay. (Niedrig.)
- Dockerfile: unpinned `pecl install imagick` mit Rückfall; Build wurde hier nicht ausgeführt. (Niedrig.)

Unauffällig: `Config` (Vorrang Umgebung vor `.env`, Parser), `View` (escaped, `EXTR_SKIP`), `Request::path`, CSP, `.htaccess`-Rewrites (praktisch bestätigt), `deploy.sh` (Passwort nur über Umgebung, `--dry-run` Standard, kein `--delete`, Prüfung auf nicht committete Änderungen).

## 4. Entscheidungen für Lutz

1. **Sitzung:** Leerlauf 60 statt 30 Minuten (Empfehlung), absolut 12 Stunden?
2. **Schlüssel und Token:** Du erzeugst `LK_APP_KEY` und `LK_SETUP_TOKEN` lokal (`openssl rand -base64 32`), legst `app/.env` per FTPS ab und sicherst eine Kopie des Schlüssels im Passwort-Manager. Einverstanden?
3. **Rücksetzweg:** Eingebaute Route `/admin/reset` mit `LK_RESET_TOKEN` in `app/.env` (einmalig, protokolliert) statt hochgeladenem Skript. Einverstanden?
4. **Protokoll** vorerst nie kürzen? Oder nach 12 Monaten (mit Kettenabschluss)?
5. **Einladung** als Link, den du weitergibst (kein E-Mail-Versand): wie im Entwurf?
6. **Editor** darf eigenes Passwort ändern (mit Passwort + TOTP) und eigenes TOTP nur über Rücksetz-Link durch dich? (Vorschlag.)
7. **Datenordner auf all-inkl:** Admin startet nur mit `LK_DATA_DIR` außerhalb des Webroots (Empfehlung) oder darf er im Webroot laufen?
8. **Proxy:** Adresse des Reverse-Proxys für `RemoteIPInternalProxy` vor dem Livegang auf pve01 nennen.
