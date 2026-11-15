# Sicherheitsgutachten: Entwurf Schritt 2 (Anmeldung)

Stand: 15.11.2026. Zweite Prüfung des Entwurfs `05 Entwurf Anmeldung.md` vor dem Bauen, nach der Opus-Prüfung in `06`. Modell: Fable (Sicherheitsgutachter, nach der Modellregel für Risiko „hoch“). Grundlage: Plan `03` (Abschnitte 4a, 6, 11), Bestandsaufnahme `04`, Prüfung `06`, das Gerüst aus Schritt 1 (`app/src/*`, `public/index.php`, `public/.htaccess`, `docker/apache.conf`, `compose.yaml`, `deploy.sh`, `.env.example`). Kein Anwendungscode geschrieben, nichts hochgeladen.

Alle eindeutigen Befunde sind **direkt im Entwurf eingetragen** und dort mit **(Gutachten)** markiert; Entscheidungen für Lutz stehen in Abschnitt 4. Die Nummern unten sind die Befunde, nicht die Abschnitte des Entwurfs.

## 1. Ergebnis vorab

Der Entwurf ist nach der Opus-Prüfung in der Substanz richtig: Argon2id, TOTP mit Wiederverwendungsschutz und atomarem Update, Wiederherstellungscodes als HMAC, Sitzung in SQLite mit zwei ID-Wechseln, `__Host-`-Cookie nach Konfiguration, Zähler je Name + Adresse, Konto erst nach bestätigtem TOTP, Rücksetzweg mit einmaligem Token, Protokoll mit Trigger und Kette. Dieses Gutachten fand **keinen Fehler, der die Anmeldung umgeht**, aber zwei Lücken mit Risiko hoch, die Sonnet beim Bauen in eine unsichere Richtung gelenkt hätten (nicht spezifizierte Einrichtungssitzung; fälschbare Quelladresse im Docker-Standard), einen inneren Widerspruch (Zusammenfassen von Fehlversuchen gegen den Nur-Anhängen-Trigger), eine unvollständig beschriebene Schutzmaßnahme (Login-CSRF ohne Bindung ans Cookie wäre wirkungslos) und einige Festlegungen, die später eine Datenmigration erspart hätten. Alles ist eingearbeitet.

**Der Entwurf kann mit den Eintragungen gebaut werden**, sobald Lutz die vier offenen Entscheidungen (Abschnitt 4) getroffen hat. Nach dem Bauen prüft Fable den fertigen Code noch einmal (`/code-review max`), vor allem Wettläufe, Gleichbehandlung, Sitzungswechsel und die Einrichtungs- und Rücksetzwege.

## 2. Befunde

### Hoch

**H1. Einrichtungssitzung war nicht spezifiziert.** Der Entwurf sagte „kurzlebige Einrichtungssitzung“, ohne zu sagen, wo Passwort-Hash und TOTP-Geheimnis zwischen „Passwort gesetzt“ und „TOTP bestätigt“ liegen und wie die TOTP-Seite an die Tokenprüfung gebunden ist.
*Fehlerfall:* Sonnet baut das mit `$_SESSION`. Auf all-inkl liegt `session.save_path` für alle Domains des Kontos A gemeinsam; das unbestätigte TOTP-Geheimnis läge im Klartext in einer Datei, die WordPress lesen kann. Oder: Die TOTP-Bestätigungsseite prüft nur „Zwischenstand vorhanden“ und nicht, dass die Zwischenzeile aus einem Formular mit richtigem Token entstand.
*Korrektur (eingetragen, Abschnitte 2 und 8):* Tabelle `pending_enrollments` mit eigenem `__Host-`-Cookie (32 Zufallsbytes, Hash in der DB), verschlüsseltem Geheimnis, 15 Minuten Ablauf, Aufräumen mit den Sitzungen; Ablauf in drei Schritten festgelegt, Token nur in Schritt 2 geprüft, Schritt 3 nur mit gültiger Zwischenzeile, sonst 404; Zwischenzeile im selben Vorgang wie die Kontoanlage gelöscht. Benutzername auf `[a-z0-9._-]{3,32}` begrenzt.

**H2. Quelladresse im Docker-Standard fälschbar.** `docker/apache.conf` vertraut `X-Forwarded-For` aus dem ganzen privaten Netz, `compose.yaml` veröffentlicht Port 8080 auf allen Schnittstellen. Der Entwurf wollte das „vor dem Livegang“ einschränken.
*Fehlerfall:* Jemand im LAN (oder ein kompromittiertes Gerät dort) schickt Anfragen direkt an Port 8080 mit beliebigem `X-Forwarded-For`. `REMOTE_ADDR` wechselt mit jeder Anfrage, die Zähler `ip` und `userip` greifen nie, es bleibt nur „je Name ab 100 Versuchen Verzögerung“ und die globale Bremse. Passwortraten läuft fast ungebremst, auf pve10 schon in der Testphase.
*Korrektur (eingetragen, Abschnitt 2):* In Schritt 2, nicht erst vor dem Livegang: `RemoteIPInternalProxy` aus `LK_TRUSTED_PROXIES`, leer = `mod_remoteip` aus; `compose.yaml` bindet standardmäßig `127.0.0.1:8080:80` oder gar keinen Port (Proxy im selben Docker-Netz); `LK_TRUST_PROXY=1` ohne `LK_TRUSTED_PROXIES` ist ein gemeldeter Konfigurationsfehler. Zusätzlich: `X-Forwarded-Proto` nimmt den letzten Eintrag (Proxy), nicht den ersten (Client), anders als `Request::isHttps()` heute.

### Mittel

**M1. Zusammenfassen von Fehlversuchen widerspricht dem Nur-Anhängen-Trigger.** Abschnitt 9 verlangte „ein Eintrag je Adresse und Minute mit Zähler“ in `audit_log`, Abschnitt 2 verbietet dort `UPDATE`.
*Fehlerfall:* Beim Bauen fällt der Widerspruch auf, und die naheliegende Lösung ist, den Trigger zu lockern oder je Fehlversuch eine Zeile zu schreiben; dann füllt ein Angreifer die Datenbank, oder die Kette verliert ihren Sinn.
*Korrektur (eingetragen):* eigene Tabelle `login_failures` (`ip_hash`, `minute`, `count`), nicht Teil der Kette, 30 Tage; `audit_log` nur für Ereignisse zu bekannten Konten und Sicherheitsereignisse.

**M2. Login-CSRF-Token ohne Bindung ist wirkungslos.** Der Entwurf nannte „zustandsloses Token plus Double-Submit-Cookie“, ohne die Bindung zu beschreiben.
*Fehlerfall:* Der Angreifer holt sich selbst ein gültiges signiertes Token von der Anmeldeseite, legt es in ein Formular auf seiner Seite und lässt das Opfer es absenden: Das Opfer ist im Konto des Angreifers angemeldet (Login-CSRF). `SameSite=Strict` auf dem Cookie allein hilft nur, wenn der Server das Cookie wirklich verlangt und mit dem Token verrechnet.
*Korrektur (eingetragen, Abschnitt 6):* Cookie `__Host-lk_pre` mit Zufall `r`; Feld `exp|HMAC(csrf, r|exp)`; Server rechnet mit `r` aus dem Cookie nach; ohne Cookie oder bei Abweichung ablehnen und frisches Formular zeigen.

**M3. Zähler nach dem Hash gebucht.** „Erst Zähler, dann Argon2“ zählt den Fehlversuch erst nach dem Ergebnis.
*Fehlerfall:* 50 gleichzeitige Anfragen passieren alle die Zählerprüfung, bevor die erste gebucht ist: 50 Argon2-Läufe à 64 MiB auf all-inkl, und die Sperre „10 in 15 Minuten“ wirkt erst für die nächste Welle.
*Korrektur (eingetragen, Abschnitt 3):* Eintrag in `login_attempts` **vor** `password_verify`, bei Erfolg wieder entfernen; dieselbe Buchung speist die globale Bremse.

**M4. Globale Bremse ist selbst ein Hebel.** 8 Hash-Prüfungen in 10 s für alle zusammen.
*Fehlerfall:* Ein Angreifer schickt dauerhaft eine Anfrage je Sekunde mit beliebigen Namen; Lutz und die zweite Person bekommen immer „bitte später“. Kein Eindringen, aber Aussperrung.
*Korrektur (eingetragen, Entscheidung 7):* Zähler je Adresse zuerst, globale Bremse mit höherer Schwelle (Vorschlag 20 in 10 s, auf dem Testserver messen) und als eigenes Protokollereignis sichtbar.

**M5. Widerspruch bei der zweiten Stufe.** Abschnitt 7 sagte zuerst „gemeinsamer Zähler“ für TOTP/Code und Passwortstufe, dann „zweite Stufe zählt je Konto streng“.
*Fehlerfall:* Gebaut wird eine der beiden Lesarten; mit gemeinsamem Zähler sperrt die TOTP-Stufe auch die Passwortstufe je Konto, und die Aussperr-Falle aus `06` ist wieder offen.
*Korrektur (eingetragen):* eigener Zähler `user2fa` je Konto (TOTP und Code zusammen), getrennt von `userip`/`ip`/`user`; eine Sperre dort ist ein Alarm („Passwort vermutlich bekannt“).

**M6. QR-Code als Adresse mit Geheimnis.** „Lokal erzeugtes SVG“ ließ offen, ob es per `<img src="/admin/qr?secret=…">` geladen wird.
*Fehlerfall:* Das TOTP-Geheimnis steht im Webserver-Protokoll von all-inkl, im Browserverlauf und in `Referer`-Kopfzeilen.
*Korrektur (eingetragen, Abschnitt 4):* SVG inline im HTML, `otpauth://` nur in den QR-Code gezeichnet, nie geloggt; SVG ohne `style`-Attribute wegen CSP; `totp_last_step` beim Bestätigen setzen; Serverzeit auf der Seite anzeigen (falsche Uhr erkennbar).

**M7. Keine Schema-Versionierung in Schritt 2.** Migrationen sind erst für Schritt 3 vorgesehen, aber Schritt 2 legt die ersten Tabellen an.
*Fehlerfall:* Testserver und Docker-Volumes haben nach Schritt 2 eine Datenbank ohne Version; Schritt 3 muss sie von Hand oder mit Sonderfall nachziehen, und jede Änderung an `users` (Passkey, Rotation) wird zur Handarbeit.
*Korrektur (eingetragen, Abschnitt 2):* `meta.schema_version` und ein kleiner Migrationsläufer (nummerierte Schritte, je eine Transaktion) schon jetzt.

**M8. Vertrauensannahme Shared Hosting nicht dokumentiert.** Bestandsaufnahme: `open_basedir` leer, alle Domains des Kontos A unter demselben Benutzer, WordPress läuft noch.
*Fehlerfall:* Eine Lücke in einem WordPress-Plugin von br-foto.com liefert `app/.env` (Schlüssel) und die SQLite-Datei von Lichtkammer; damit lassen sich TOTP-Geheimnisse entschlüsseln und Sitzungen fälschen. Im Code nicht abwehrbar.
*Korrektur (eingetragen, Abschnitt 2 und Entscheidung 8):* Annahme festgehalten; Vorschlag an Plan 03, Schritt 8: WordPress nach dem Umschalten höchstens 14 Tage als Rückweg behalten.

**M9. Audit-Kette: Nebenläufigkeit und Ende.** Zwei Prozesse schreiben gleichzeitig ans Ende; Abschneiden der letzten Einträge ist mit einer Kette allein nicht erkennbar.
*Fehlerfall:* Zwei Anmeldungen im selben Moment verketten denselben Vorgänger, die Prüfung meldet eine „Lücke“, obwohl nichts passiert ist; umgekehrt löscht jemand die letzten zehn Zeilen, und die Kette ist weiterhin stimmig.
*Korrektur (eingetragen):* Einträge in `BEGIN IMMEDIATE`; Grenze dokumentiert, Admin zeigt Anzahl und Zeit des letzten Eintrags, Sicherung behält Stände; nach Rotation Prüfung mit `LK_APP_KEY_PREVIOUS` und Ereignis `key_rotated`.

**M10. Datenordner im Webroot (aus `06`, Entscheidung 7, noch offen).** Standard ohne `LK_DATA_DIR` ist `<Webroot>/data`, nur durch eine Rewrite-Regel geschützt.
*Fehlerfall:* `.htaccess` wird bei einem Deployment nicht übertragen oder `AllowOverride` ändert sich: Die SQLite-Datei ist per HTTPS abrufbar.
*Korrektur (eingetragen als Empfehlung):* Start auf all-inkl nur mit `LK_DATA_DIR` außerhalb von `public/` (`realpath`), sonst Fehlerseite, Ausnahme nur mit `LK_ALLOW_DATA_IN_WEBROOT=1`. Bleibt Entscheidung von Lutz.

### Niedrig

**N1. Verschlüsselung ohne Zusatzdaten.** `secretbox` bindet den Geheimtext nicht an das Konto; wer die Datei, aber nicht den Schlüssel hat, kann seinen eigenen TOTP-Geheimtext in eine andere Zeile kopieren (braucht dann noch das Passwort). *Eingetragen:* AEAD (`xchacha20poly1305_ietf`) mit Zusatzdaten `users:totp:<user_id>`; `kid` aus HKDF-Kontext, nicht aus dem Rohschlüssel.

**N2. NFC nur „wo `intl` da ist“.** Ein Passwort mit Umlaut gälte je Umgebung einmal und einmal nicht. *Eingetragen:* `intl` Pflicht wie `sodium`, NFC immer.

**N3. Rücksetz-Route bleibt nach Verbrauch „lebendig“.** Mit verbrauchtem Token antwortete die Route weiter mit Formular. *Eingetragen:* Hash in `meta` → 404 wie ohne Token; unbekannter Name liefert dieselbe Seite; Ablauf des Rücksetz-Links dokumentiert.

**N4. Vergessenes `LK_SETUP_TOKEN`.** Nur ein einmaliger Hinweis nach der Einrichtung. *Eingetragen:* dauerhaftes Warnband im Admin, solange ein verbrauchtes Setup- oder Reset-Token in `.env` steht.

**N5. Letzter Administrator.** „Nicht herabstufen“ deckte „deaktivieren“ nicht ab, und die Prüfung muss in derselben Transaktion laufen. *Eingetragen.* Dazu: Konten werden nie gelöscht, nur deaktiviert (Fremdschlüssel, Trigger).

**N6. Kopfzeilen und Formulare.** `Referrer-Policy: no-referrer` auf Seiten mit Token in der Adresse; `autocomplete`-Attribute (`username`, `current-password`, `new-password`, `one-time-code`); `Clear-Site-Data` bei Abmeldung. *Eingetragen.*

**N7. `.env.example` mit Beispielschlüssel.** Ein ausgefüllter Beispielwert bleibt gern stehen; die Startprüfung fängt nur genau diesen Wert. *Eingetragen:* leere Werte mit Erzeugungsbefehl.

**N8. Passkey-Vorbereitung.** `users.webauthn_handle` (32 Zufallsbytes) jetzt anlegen, RP-ID aus `LK_BASE_URL`, Challenge serverseitig wie `pending_enrollments`, `user2fa` zählt später auch Passkey-Fehler, Passkey hinzufügen nur mit Passwort und TOTP, `Permissions-Policy` nicht versehentlich sperren. *Eingetragen, Abschnitt 10.*

**N9. Protokolltexte.** `event`/`detail` nur aus festem Vokabular, nie aus Eingaben oder Kopfzeilen. *Eingetragen.*

## 3. Geprüft und unauffällig

- **Passwort:** Argon2id 64 MiB/3/1 über OWASP-Minimum, Rehash bei Parameteränderung, Längen 12 bis 256 Zeichen / 1024 Byte, Liste häufiger Passwörter, Scheinhash mit echten Parametern, gleiche Pfade für unbekannt/gesperrt/deaktiviert/ohne TOTP.
- **TOTP:** RFC 6238, SHA1/6/30, Fenster ±1, alle drei Schritte berechnet, `hash_equals`, `UPDATE … WHERE totp_last_step < :step` in `BEGIN IMMEDIATE` mit Trefferzahl 1 (Wiederverwendung und Wettlauf), 5 Fehlcodes beenden `password_ok`, TOTP Pflicht ohne „Passwort reicht zum Einrichten“.
- **Wiederherstellungscodes:** ~47 Bit je Code, keyed HMAC (offline nicht ratbar ohne Schlüssel), Verbrauch im selben Update, Normalisierung der Eingabe, Neuerzeugen nur mit voller Sitzung und Passwort.
- **Sitzung:** 32 Zufallsbytes, Hash in der DB, ID-Wechsel bei Passwort und bei TOTP mit Löschen der alten Zeile, `password_ok` 5 Minuten, `stage`-Prüfung zentral mit Ausnahmeliste, Join auf `users` (Deaktivierung wirkt sofort), Leerlauf/absolut, `last_seen_at` alle 60 s, Aufräumen bei Anmeldung, Abmeldung serverseitig, Passwortänderung beendet andere Sitzungen, Bindung an IP/UA nur Protokoll.
- **Cookie:** `__Host-`, `Secure`, `HttpOnly`, `SameSite=Strict`, Entscheidung nach `LK_BASE_URL` statt Anfrage oder `LK_ENV`, HTTP-Anfrage bei `https://`-Konfiguration abgelehnt. Alte Cookies werden nie übernommen.
- **CSRF:** Token je Sitzung, `hash_equals`, nichts Zustandsänderndes per `GET`, `form-action 'self'` in der CSP, Weiterleitung nur auf lokale Pfade.
- **Schlüssel:** von Lutz erzeugt, HKDF-Teilschlüssel, versioniertes Format, Startprüfung, Verlust und Rotation beschrieben, Sicherung getrennt von der Datenbank.
- **Einrichtung/Einladung/Rücksetzung:** Konto erst nach TOTP in Transaktion mit erneuter Leerprüfung, 404 statt 405, Einladung nur Hash, `GET` verbraucht nichts, Verbrauch atomar, Rücksetz-Link entwertet sofort TOTP/Codes/Sitzungen, Reset-Token einmalig über `meta`.
- **Protokoll:** Trigger, HMAC-Kette, keine Geheimnisse, keine rohen Namen aus Fehlversuchen, IP nur als Präfix.
- **Gerüst Schritt 1:** `.htaccess`-Sperren für `app/`, `data/`, `.env`, `*.sqlite`; `View` escaped mit `EXTR_SKIP`; CSP ohne Inline; `display_errors` früh aus; `deploy.sh` überträgt nie `.env`, Datenordner, Medien.

## 4. Entscheidungen für Lutz (aus Entwurf Abschnitt 12, neu oder noch offen)

1. **Globale Bremse** (Frage 7): 20 Hash-Prüfungen in 10 s als Schwelle, Angreifer kann die Anmeldung zeitweise für beide blockieren, aber nicht eindringen. Einverstanden?
2. **WordPress auf Konto A** (Frage 8): nach dem Umschalten höchstens 14 Tage als Rückweg, dann entfernen. Einverstanden?
3. **Datenordner** (Frage 7 aus `06`): Admin startet auf all-inkl nur mit `LK_DATA_DIR` außerhalb des Webroots. Einverstanden?
4. **Docker-Port**: `compose.yaml` bindet 8080 nur an `127.0.0.1` (Proxy auf demselben Host) oder gar nicht (Proxy im Docker-Netz). Welche Form läuft auf pve10?

Die Fragen 1 bis 6 des Entwurfs (Sitzungsdauer, Einladung als Link, Rücksetzweg als Route, Editor-Rechte, Schlüssel lokal erzeugen, Aufbewahrung) bleiben wie in `06` gestellt.

## 5. Für den Bau (Schritt 2) und die Prüfung danach

- Bauen nach Entwurf `05` einschließlich aller **(Prüfung)**- und **(Gutachten)**-Stellen; die Testliste in Abschnitt 11 des Entwurfs ist Pflicht, nicht Vorschlag.
- Änderungen am Gerüst, die Schritt 2 mitbringt: `Router::post()` und 404 statt 405 für gesperrte Routen; `Request::isHttps()` nimmt den letzten `X-Forwarded-Proto`-Eintrag; `docker/apache.conf` und `compose.yaml` nach H2; `.env.example` nach N7.
- Nach dem Bauen: Fable prüft den Code (`/code-review max`), Schwerpunkte Wettläufe (zwei PHP-Prozesse), Gleichbehandlung bekannt/unbekannt (Antwort, Status, Zeit, Zähler), Sitzungswechsel, Einrichtungs- und Rücksetzwege, Protokoll ohne Geheimnisse. Erst danach Upload auf den Testserver, erst nach Freigabe von Lutz.
