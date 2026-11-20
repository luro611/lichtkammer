# Entwurf: Automatische Sicherung per Token (E11 a)

Stand: 20.11.2026. Entwurf, **noch nichts gebaut, nichts hochgeladen, nichts am Server geändert**.
Grundlage: Entwurf `20` (Abschnitt 2.2, E11 a), Prüfung `21` (M4, M6), Berichte `34` und `35` (Sicherungsweg, Fable-Auflagen), `docs/betrieb/Wiederherstellung.md`, Code von `BackupPages`, `Backup`, `Tar`, `Restore`, `RateLimiter`, Router, `Request`, `public/.htaccess`.
Risiko des Bausteins: **hoch** (neue Anmeldeart ohne Passwort und Code, die an Daten kommt, die Fotos mit GPS und die Datenbank enthalten). **Fable prüft diesen Entwurf vor dem Bau (Pflicht).**

## 0. Kurz gesagt

1. **Was:** Der Homeserver ruft jede Nacht die Sicherung ab, ohne dass jemand Passwort und Code eingibt. Er zeigt dazu einen **Sicherungs-Schlüssel (Token)** vor. Der Schlüssel darf **nur lesen** und nur **vier feste Wege** benutzen (Test, Liste, Paket, ein Original). An Konten, Einstellungen, Passwörter und alles andere im Admin kommt er nicht heran.
2. **Wie sicher:** Der Schlüssel ist 256 Bit lang und zufällig. Die Datenbank kennt nur seinen Hash. Er wird **einmal** angezeigt, kann widerrufen werden, läuft ab, wird nur im Kopf `Authorization: Bearer …` gesendet und hat eine eigene scharfe Sperre. Erzeugen darf ihn nur ein Administrator **mit Passwort und Code** (dieselbe Regel wie für Administrator-Einladungen nach Fable F1).
3. **Empfehlung:** Bauen wie beschrieben, **ohne** Ende-zu-Ende-Verschlüsselung und **ohne** feste IP-Bindung in der ersten Version (Abschnitt 3.5, 3.6). Der Homeserver **holt** ab (Pull); der Server schiebt nichts irgendwohin (Abschnitt 7).
4. **Aufwand:** etwa 3 Arbeitstage Bau (Sonnet), dazu Prüfung (Opus, Fable) und eine Messung auf all-inkl. Reihenfolge: **erst Livegang mit von Hand gezogener Sicherung, dann dieser Baustein** (Abschnitt 8).
5. **Offen für Lutz:** vier Fragen am Ende (Abschnitt 10).

## 1. Ablauf: wer ruft was wann auf

Zwei Ebenen, wie beim Sicherungsweg im Admin (Bericht `34`), aber ohne Cookie und ohne Bestätigungsseite.

**Ebene 1: Paket** (klein, Sekunden): Datenbank (aus `VACUUM INTO`), `MANIFEST.json`, `originals.sha256`, `files/` (PDF, Logos). Das ist **genau dasselbe `.tar`** wie bisher und darum direkt wiederherstellbar.
**Ebene 2: Originale** (groß, Stunden beim ersten Mal): einzeln über die Foto-Nummer, mit Range (Fortsetzen).

Nächtlicher Lauf des Skripts auf dem Homeserver (Skript: Abschnitt 5):

| Schritt | Aufruf (alle `GET`, Kopf `Authorization: Bearer …`) | Zweck |
|---|---|---|
| 1 | `/admin/api/sicherung/ping` | Schlüssel gültig? Antwort: nur der Name des Schlüssels. Das ist auch die **Messung**, ob `Authorization` bei all-inkl ankommt (Abschnitt 3.7). |
| 2 | `/admin/api/sicherung/paket` | Paket holen (`.tar`), in Datei `….part` schreiben, Größe und `tar -tf` prüfen, dann umbenennen. |
| 3 | `/admin/api/sicherung/liste` | Textliste aller Originale: je Zeile `Foto-Nummer`, SHA-256, Größe, Pfad (Tabulator-getrennt, **kein JSON**, damit `curl` und `sh` genügen). |
| 4 | `/admin/api/sicherung/original/<Nr>` mit `Range: bytes=a-b` | Nur Originale, die lokal **noch fehlen**. In Scheiben von 32 MB (bleibt weit unter 60 Sekunden), Fortsetzen nach Abbruch, danach **SHA-256 gegen die Liste prüfen**. Erst dann an den Platz verschieben. |
| 5 | (lokal) | Alte Pakete aufräumen, Erfolgsdatei schreiben, optional einen Wächter-Ping senden. |

Eigenschaften:

- **Nur Neues:** Originale ändern sich nie (die App überschreibt nie, Prüfung `18`/`19`). Was lokal schon liegt, wird nicht noch einmal geholt. Die Liste ist klein (10 000 Fotos sind etwa 1 MB). Ein „Nur ab Nummer …“ ist unnötig.
- **Wiederholung nach Abbruch:** Jede Datei und jedes Paket wird erst nach bestandener Prüfsumme sichtbar. Der nächste Lauf macht dort weiter. Es gibt keinen halben Zustand.
- **Zeitgrenze all-inkl (60 s):** Das Paket ist klein. Originale kommen in Scheiben, jede Scheibe ist eine eigene Anfrage. Das Skript benutzt `--max-time 55`. **Die Zahl ist auf all-inkl noch nicht gemessen** (Fable H1); sie wird mit dem Test dieses Bausteins gemessen (Abschnitt 8).
- **Zeitversatz:** Foto neu zwischen Paket und Liste: steht im Paket, aber nicht in der Liste, kommt nächste Nacht. Foto im Papierkorb gelöscht, aber noch in der Liste: Antwort 404, das Skript überspringt es und meldet es. Beides ist harmlos, weil die Wiederherstellung fehlende Originale ausdrücklich meldet.
- **Nicht jede Nacht Vollständigkeit prüfen:** einmal pro Woche `--pruefen` (alle lokalen Originale gegen die Liste, fängt Datenträgerfehler).

## 2. Die Wege auf dem Server

Neue Datei `app/src/Admin/BackupApi.php` (eigener Trait wie `BackupPages`), **ohne** Sitzung, **ohne** Cookie, **ohne** CSRF (es sind nur `GET`). Registrierung wie die anderen Wege, aber als „öffentlich“ markiert (der Router verlangt dann keine Anmeldung) und die Prüfung geschieht **im ersten Satz des Handlers**.

| Weg | Antwort | Wiederverwendet |
|---|---|---|
| `GET /admin/api/sicherung/ping` | `200`, Text: Name des Schlüssels; kein Datenabfluss | neu, winzig |
| `GET /admin/api/sicherung/paket` | `.tar` wie bisher | `Backup::package()` |
| `GET /admin/api/sicherung/liste` | Text, eine Zeile je Original | `Backup::originals()` |
| `GET /admin/api/sicherung/original/{id}` | Datei mit Range (`200`, `206`, `416`) | `Backup::originalFile()` und der Range-Teil aus `backupOriginal` (wird in eine gemeinsame Hilfsmethode gezogen, kein Kopieren) |

Zur Wahl des Pfads: Vorschlag ist `/admin/api/…`, damit die Regeln für `/admin` (Kopfzeilen, kein Zwischenspeichern, kein Suchindex) schon greifen. Das wird beim Bau gegen den Router geprüft.

**Das Wichtigste gegen Missbrauch ist die Bauweise:**

- Der Handler ruft **nie** `fullSession()` auf und setzt **nie** ein Cookie. Ein Token erzeugt also keine Sitzung, und ein Sitzungs-Cookie hilft an diesen Wegen nicht. Das trennt beide Welten sauber (und macht den Umweg aus Fable F1 und H5 unmöglich).
- Das Token wird **nur** an diesen vier Wegen gelesen. Alle anderen Wege kennen den Kopf `Authorization` nicht.
- **Prüfung per Test (Pflicht):** Ein Test geht **alle** im Router eingetragenen Wege durch und sendet ein gültiges Token. Nur die vier Wege dürfen mit `200`/`206` antworten, alle anderen müssen sich wie ohne Anmeldung verhalten (Weiterleitung oder 404). Das schützt auch vor künftigen Wegen, die jemand versehentlich öffnet.
- Mit Token darf nichts **geschrieben** werden: Die vier Wege ändern nur die Zähler `last_used` und das Protokoll.

## 3. Token-Konzept

### 3.1 Aufbau und Erzeugung

- Form: `lkb_<Kennung>_<Geheimnis>`. Die **Kennung** (8 Hexzeichen) ist nicht geheim und dient nur dem Nachschlagen. Das **Geheimnis** sind 32 Zufallsbytes, base64url (43 Zeichen).
- Erzeugung mit `random_bytes()` (PHP, ohne Shell nötig, funktioniert auf all-inkl).
- **Speicherung nur als Hash:** SHA-256 des Geheimnisses (ein schneller Hash genügt, weil das Geheimnis 256 Bit Zufall ist; ein Raten ist aussichtslos). Es steht **nie** im Klartext in Datenbank, Protokoll, Fehlerprotokoll, Meldungsband oder `.env`.
- **Vergleich zeitkonstant** (`hash_equals`). Gibt es die Kennung nicht, wird trotzdem gegen einen festen Blindwert verglichen, damit Antwortzeit und Antwort gleich sind.

### 3.2 Wo gespeichert: Datenbank statt `.env` (Empfehlung)

Entwurf `20` sah ein einziges `LK_BACKUP_TOKEN` in `.env` vor. Besser ist eine Tabelle:

| | Tabelle `backup_tokens` (Empfehlung) | `LK_BACKUP_TOKEN` in `.env` |
|---|---|---|
| Mehrere Schlüssel mit Namen („homeserver“, „notfall“) | ja | nein |
| Zuletzt benutzt, Widerruf, Ablauf, Anzeige im Admin | ja | nein |
| Wechsel ohne FTPS und ohne `deploy.sh` | ja | nein (Datei ändern) |
| Schlüssel überlebt Wiederherstellung | wird bewusst **verworfen** (3.3) | bleibt in `.env` |
| Auswirkung, wenn jemand die Datenbank hat | sieht nur Hashes | nicht anders |

Spalten (Migration 12): `id`, `name`, `token_id` (eindeutig), `secret_hash`, `created_at`, `created_by`, `expires_at`, `revoked_at`, `last_used_at`, `last_ip_prefix`, `use_count`.

### 3.3 Regeln

- **Höchstens 3 gleichzeitig gültige Schlüssel.** So gibt es Rotation (neuen erzeugen, auf dem Homeserver austauschen, alten widerrufen) und einen Notfallschlüssel, aber keinen Wildwuchs.
- **Ablauf:** wählbar 90 Tage, 1 Jahr (Vorgabe) oder 2 Jahre, **nie „ohne Ablauf“**. 30 Tage vor dem Ablauf zeigt das Hinweisband an, dass der Schlüssel abläuft, damit der Cronjob nicht lautlos stirbt.
- **Widerruf:** sofort wirksam, braucht **keine** Bestätigung mit Passwort und Code (im Notfall zählt Tempo, und Widerruf nimmt nur Rechte weg).
- **Anzeige nur einmal:** Das Token erscheint nur in der Antwort auf das Erzeugen (Seite mit `no-store`, kein Weiterleiten, kein Meldungs-Cookie, **nichts** davon im Protokoll). Danach nie wieder. Verloren heißt: neuen erzeugen.
- **Wiederherstellung:** `Restore` verwirft die Tabelle `backup_tokens` (wie Sitzungen und vertraute Geräte). Auf einem neuen Server beginnt Lutz mit einem neuen Schlüssel. Wer eine Sicherung stiehlt, hat so keinen funktionierenden Schlüssel dazu.
- **Bindung an das Konto des Erzeugers:** Ein Passwortwechsel ändert nichts (sonst bräche der Cronjob bei jedem Wechsel). Wird das Konto des Erzeugers aber gesperrt, gelöscht oder verliert die Rolle Administrator, gilt der Schlüssel nicht mehr (Prüfung von `created_by` bei jedem Gebrauch).
- **Übergabe nur im Kopf `Authorization: Bearer`.** In der Adresse (Query) **nie**, weil sie in Zugriffsprotokollen von all-inkl landet. Der Server lehnt eine Adresse mit `?token=` oder `?key=` nicht ab, er liest sie schlicht nicht.
- **Nur HTTPS.** Der Handler prüft zuerst den Transport (`transportOk` wie die Admin-Wege) und liest den Kopf erst danach. Eine Anfrage über HTTP wird von der `.htaccess` mit 301 umgeleitet, bevor PHP sie sieht; der Schlüssel wäre dabei aber schon im Klartext über das Netz gegangen. Darum ist das Skript mit `--proto '=https'` festgelegt und benutzt nie HTTP. Für lokale Docker-Tests (`LK_HTTP_OK`) gilt die vorhandene Ausnahme.
- **Antworten:** `Cache-Control: private, no-store`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer`, **kein CORS** (kein `Access-Control-…`-Kopf, `Cross-Origin-Resource-Policy: same-origin`), `Content-Disposition: attachment`. Das übernimmt `backupHeaders()`.
- **Fehlantworten sind gleich:** ohne Kopf, falsche Kennung, falsches Geheimnis, abgelaufen, widerrufen: immer `401` mit demselben kurzen Text, ohne Hinweis, was davon zutrifft. Gibt es **gar keinen gültigen Schlüssel**, antworten alle vier Wege mit `404` (der Weg „existiert nicht“, wie in Entwurf `20` vorgesehen).

### 3.4 Sperre und Ratenbegrenzung (scharf)

Wiederverwendet wird `RateLimiter::gate()` (Versuche werden **vor** der Prüfung gebucht und bei Erfolg wieder entfernt):

| Zähler | Gegenstand | Grenze (Fenster 15 Minuten) | Folge |
|---|---|---|---|
| `tokfail` | Fehlversuche **je Absenderadresse** | 10 | 15 Minuten `429` für diese Adresse. Zählt nur Fehlversuche, ein gültiges Token löscht sie nicht aus. |
| `tokglobal` | alle Fehlversuche zusammen (globale Bremse) | 60 | alle Fehlversuche mit `429`. Gültige Schlüssel kommen weiter durch. |
| `tokpkg` | Paketabrufe **je Schlüssel** | 2 | 429 (VACUUM INTO belastet den gemeinsamen Server). |
| `tokorig` | Abrufe von Originalen **je Schlüssel** | 600 | 429. Mit 32-MB-Scheiben deckt das viele GB je Viertelstunde ab. |

Wichtig dabei: Die Sperre für Fehlversuche hängt **an der Adresse des Absenders, nicht an der Kennung**. Sonst könnte ein Fremder, der die (nicht geheime) Kennung kennt, den Schlüssel des Homeservers aussperren. Die Zahlen sind Vorschläge, beim Bau werden sie gegen `RateLimiter` getestet. Bei der ersten Sperre einer Adresse entsteht **ein** Protokolleintrag `backup_token_locked` (nicht je Versuch; Fehlversuche unbekannter Absender werden wie schon heute zusammengefasst in `login_failures`).

### 3.5 Adressbindung: nicht in Version 1

Das Heimnetz hat eine wechselnde Adresse (DynDNS, Mobilfunk-Ausfall, Provider-Wechsel). Eine feste Bindung würde den Cronjob dann lautlos sperren, und der Fehler fiele erst nach Tagen auf. **Empfehlung: nicht bauen.** Stattdessen **zeigt** die Seite die zuletzt benutzte Adresse (nur Präfix, wie im Protokoll) und das Protokoll hält sie fest. Wer will, kann später „bekannte Adresse ändert sich“ als Warnung ergänzen (nicht jetzt: im Heimnetz ständig falscher Alarm).
Aufwand für eine optionale Erlaubnisliste wäre klein (Spalte `allow_cidr`); Risiko: Fehlbedienung. Daher aufgeschoben.

### 3.6 Ende-zu-Ende-Verschlüsselung: Aufwand und Nutzen

Idee: Der Server verschlüsselt jede Datei vor dem Senden an einen öffentlichen Schlüssel des Homeservers (`crypto_box_seal` / `secretstream`), der private bleibt dort. Ein abgefangener oder gestohlener Abruf wäre nutzlos.

- **Nutzen ist klein:** Gegen Mitlesen im Netz schützt schon HTTPS. Bleibt der Fall „Schlüssel gestohlen, Homeserver nicht“: dann holt der Dieb die **Chiffre** und kann nichts damit anfangen. Das stimmt. Wird aber der **Homeserver** gestohlen (der Normalfall, wo der Schlüssel liegt), liegt dort der private Schlüssel neben den Daten. Dann bringt es nichts.
- **Aufwand ist groß:** Streamende Verschlüsselung in PHP, Range und Fortsetzen über einen Strom mit zufälligem Schlüssel gehen nicht mehr einfach (Schlüssel je Datei müsste fest ableitbar sein), die Prüfsummenliste müsste Klartext-Summen kennen, das Skript braucht ein Entschlüsselungsprogramm (nicht nur `curl` und `sha256sum`), und die **Wiederherstellung** bekäme einen Entschlüsselungsschritt. Mehr Teile, mehr Fehlerstellen, gerade in dem Moment, in dem man sie am wenigsten brauchen kann.
- **Empfehlung:** In Version 1 **nicht bauen.** Stattdessen: Schlüssel nur lesend, ablaufend, widerrufbar, Sicherung auf dem Homeserver auf einem verschlüsselten Datenträger (LUKS), wie schon in Entwurf `20` 2.3. Entscheiden, ob es später nötig wird, nach Erfahrung mit dem Betrieb.

### 3.7 `Authorization` bei all-inkl

Bereits vorhanden (Prüfung `21` M4, Bericht `34`): `public/.htaccess` hat `RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]`, `Request::header('Authorization')` liest auch `REDIRECT_HTTP_AUTHORIZATION`, beides ist mit Tests belegt (`tests/haertung.php`). **Ob all-inkl den Kopf wirklich durchreicht, ist nicht gemessen** (Bericht `34`, nur im Container). Dafür gibt es den Weg `ping`: Lutz erzeugt ein Token und ruft `ping` einmal mit `curl` auf. Kommt `401`, obwohl das Token stimmt, wird der **zweite Kopf** `X-Lichtkammer-Token` als Ausweichweg freigeschaltet (kleine Änderung, als Absicherung schon vorgesehen aber **nicht gebaut**; sie wird erst gebaut, wenn die Messung sie braucht). Der Ausweichkopf gilt unter denselben Regeln.

## 4. Admin-Oberfläche

Unter **Verwaltung → Sicherung** (`/admin/sicherung`) kommt ein Abschnitt „Abruf für den Homeserver“, nur sichtbar für Administratoren.

- **Tabelle:** Name, Erstellt (von wem), Läuft ab, Zuletzt benutzt (Zeit und Adressenpräfix), Zahl der Abrufe, Status (aktiv, abgelaufen, widerrufen), Knopf „Widerrufen“.
- **Neuen Schlüssel erzeugen:** Name (Pflicht, z. B. „homeserver“), Ablauf, dazu **Passwort und Code** (`confirmSensitive`, Muster `confirmAdminGrant` aus Fable F1, mit Sperre nach Fehlversuchen). Der Grund ist derselbe wie bei F1: Ein Schlüssel ist ein neuer Zugang zu **allem**, was die Sicherung enthält. Eine gestohlene Sitzung darf sich damit **keinen** eigenen Schlüssel ausstellen.
- **Anzeige des Tokens** einmal, groß, mit Knopf „Kopieren“ und dem Satz: „Dieser Schlüssel wird nur jetzt angezeigt.“ Dazu der fertige Befehl zum Testen (ohne den Schlüssel im Befehl: `curl` liest den Kopf aus einer Datei).
- **Hinweisband** („Sicherung“): gelb, wenn der letzte Abruf per Schlüssel länger als 3 Tage her ist (gleiche Logik wie `last_backup_at`) oder ein Schlüssel in 30 Tagen abläuft. Eintrag auch in der **Systemprüfung**. Das ist die „Benachrichtigung bei Abruf“: **eine Anzeige im Admin, keine E-Mail** (die App versendet bisher gar keine Post, und ein Mailversand wäre eine neue Baustelle; siehe Frage 3).
- Protokoll (nur Zahlen): `backup_token_created`, `backup_token_revoked`, `backup_token_locked`, `backup_package` und `backup_originals` mit Zusatz `via=token`, Kennung des Schlüssels. Eine Scheibe eines Fortsetzens wird wie heute nicht eigens protokolliert (nur der Anfang).

## 5. Seite des Homeservers: `tools/sicherung-abholen.sh`

Ein Skript für `bash` (oder `zsh`), **nur `curl`, `sha256sum`, `tar`, Standardwerkzeuge**. Kein Python, kein `jq`, keine Schlüssel in Argumenten.

**Einstellungen** in `~/.config/lichtkammer/abholen.conf` (Rechte 600, keine Geheimnisse außer der Datei des Schlüssels):

```text
LK_URL=https://br-foto.com
LK_ZIEL=/srv/sicherung/lichtkammer
LK_TOKEN_DATEI=/etc/lichtkammer/token      # eine Zeile, Rechte 600, gehört dem Dienstkonto
LK_PAKETE_TAEGLICH=30
LK_PAKETE_MONATLICH=12
LK_WAECHTER_URL=                           # optional: Ping an einen eigenen Dienst nach Erfolg
```

**Eigenschaften:**

- Das Token kommt **nur aus der Datei**, wird an `curl` per Konfigurationsdatei (`curl -K`, Datei im Arbeitsordner mit Rechten 600) übergeben, **nie als Argument** (sichtbar in `ps`) und **nie in Ausgaben** (`set +x`, kein `-v`). Das Skript lehnt eine Token-Datei mit anderen Rechten als 600 ab.
- `curl` mit `--fail --proto '=https' --tlsv1.2 --max-time 55` je Anfrage; Scheiben von 32 MB; bis zu 3 Wiederholungen mit wachsender Pause.
- **Sperre gegen Doppelstart** (`flock`), damit ein langer erster Lauf nicht vom nächsten Cronlauf überholt wird.
- **Ordner:** Je Lauf `…/pakete/2026-11-21T0300/` mit dem **ausgepackten** Paket (`MANIFEST.json`, `lichtkammer.sqlite`, `originals.sha256`, `files/`) und einer Verknüpfung `originals` → `…/originale/` (gemeinsam für alle Läufe, hier liegen die Dateien `xx/zufallsname.ext`). **Dieser Ordner ist unverändert ein Wiederherstellungsordner** im Format von `Restore` (`Wiederherstellung.md`). Das Skript `tools/restore-probe.sh` nimmt ihn ohne Umbau an; falls nicht, wird die Probe beim Bau angepasst.
- **Aufbewahrung:** 30 tägliche Pakete, dazu je das erste Paket der letzten 12 Monate. Originale werden **nie gelöscht** (der Platz ist billiger als ein Fehler; gelöschte Fotos bleiben so zusätzlich geschützt, Entwurf `20` 2.3 sah 30 Tage vor).
- **Fehler melden:** Rückgabewert ungleich 0 und eine klare Zeile (`FEHLER: Prüfsumme von Foto 1234 stimmt nicht`) auf `stderr` (Cron mailt sie, `systemd` schreibt sie ins Journal). Bei Erfolg: Datei `letzter-erfolg` mit Zeit schreiben und, falls gesetzt, den Wächter anpingen. **Der Wächter auf Lutz' Seite ist der echte Alarm für „der Homeserver ist ausgefallen“**; das Hinweisband im Admin ist der zweite, unabhängige Alarm.
- Weitere Aufrufe: `--pruefen` (alle lokalen Originale gegen die Liste), `--trocken` (zeigt nur, was geholt würde), `--hilfe`.

**Beispiel Cron** (Dienstkonto, täglich 03:15):

```text
15 3 * * *  lichtkammer  /opt/lichtkammer/sicherung-abholen.sh >> /var/log/lichtkammer-abholen.log 2>&1
```

**Beispiel systemd-Timer** (bevorzugt, holt verpasste Läufe nach):

```text
# /etc/systemd/system/lichtkammer-abholen.timer
[Timer]
OnCalendar=*-*-* 03:15:00
Persistent=true
RandomizedDelaySec=15min
[Install]
WantedBy=timers.target
```

Der zugehörige `.service` startet das Skript als Dienstkonto ohne Anmeldung, `ProtectSystem=strict`, `ReadWritePaths` nur der Zielordner. Beide Beispiele kommen als fertige Dateien nach `docs/betrieb/Sicherung-Homeserver.md` (neu, beim Bau).
**Ort:** LXC auf pve10 (Testserver, Regel aus der globalen `CLAUDE.md`: dort darf Claude frei arbeiten; **pve01 erst nach Freigabe**). Der Homeserver braucht nur ausgehendes HTTPS, **keine offene Tür aus dem Netz**.

## 6. Auswirkung auf Wiederherstellung und die Auflagen aus Bericht 35

- **Wiederherstellung:** Das Paket ist unverändert, der Homeserver-Ordner ist ein Wiederherstellungsordner. Neu: `Restore` verwirft `backup_tokens`. Der Test `tests/sicherung.php` (Rundlauf) wird um „Tabelle wird beim Einspielen geleert“ ergänzt.
- **F1 (Konten):** Das Erzeugen eines Schlüssels verlangt Passwort und Code (4). Die Liste der Wege, die Passwort und Code verlangen, wird in `Wiederherstellung.md` und im Test von F1 um „Schlüssel erzeugen“ ergänzt. Der Schlüssel gehört dem Konto, das ihn erzeugt hat; wird das Konto gesperrt oder gelöscht, hört der Schlüssel auf zu gelten (3.3). So bringt auch ein Umweg über neue Konten keinen dauerhaften Zugang.
- **Cookie `lk_bk` (10 Minuten) und GET der Originale:** gelten nur für den Browserweg im Admin und bleiben unverändert. Die Wege mit Token benutzen sie nicht und umgekehrt. Aus H5 (Subdomain mit Fremdcode) wird für die Token-Wege **nichts**, weil sie ohne Cookie funktionieren (kein Anmelde-Cookie, das ein fremder Ursprung mitschicken könnte).
- **Fable H1 (Zeitgrenze 60 s):** Die Messung dort gilt auch hier (Abschnitt 8, Schritt 5).
- **Fable-Hinweis „eigener Schlüssel nur für die Sicherungswege, kein Admin-Recht, eigene Sperre, vorher eigene Prüfung“:** Punkt für Punkt erfüllt (2, 3.3, 3.4, Prüfplan 9).

## 7. Alternativen

| Weg | Pro | Contra | Risiko | Urteil |
|---|---|---|---|---|
| **A. Kein Token, nur von Hand** (`/admin/sicherung`, Passwort und Code) | Keine neue Angriffsfläche. Alles ist schon gebaut und geprüft. | Wird vergessen. Originale in Teilen von Hand, je nach Mediathek viele Klicks. Eine Sicherung, die niemand zieht, gibt es nicht. | niedrig (Datenverlust: hoch, wenn vergessen) | **Als Übergang bis zum Bau und als Notweg beibehalten.** |
| **B. Token-Abruf (dieser Entwurf)** | Homeserver holt selbst, braucht keine offene Tür. Gleiche Technik für all-inkl und Docker. Nur lesen, widerrufbar. | Größte neue Angriffsfläche des Projekts (kein zweiter Faktor). | hoch, begrenzt durch Abschnitt 3 | **Empfohlen.** |
| **C. FTPS-Spiegel** (`lftp mirror`) | Kein neuer Code. | Der FTPS-Benutzer sieht **nur den Webroot** (Bestandsaufnahme `04`, am 15.11.2026 nur lesend geprüft): Datenbank und Originale liegen darüber, **nicht erreichbar**. Man müsste Dateien in den Webroot legen (Klartext-Kopie der Datenbank, Entscheidung vom 15.11. verworfen) und das FTPS-Passwort, das **auch schreiben und den Code ändern darf**, auf dem Homeserver ablegen. | hoch (Schreibrecht auf die Seite) | **Verworfen.** |
| **D. Server schiebt zu Lutz (Push)** (PHP-Cronjob oder Abruf per URL von außen schickt nach WebDAV/S3/SFTP) | Der Homeserver ruft nichts auf. | Der Homeserver müsste **aus dem Internet erreichbar** sein (offene Tür ins Heimnetz) oder ein fremder Speicher kommt dazu. Zugangsdaten zum Ziel lägen auf dem gemeinsamen Webserver. PHP-Erweiterungen für SFTP sind bei all-inkl nicht bekannt. | hoch | **Verworfen.** |
| **E. Zeitgesteuerter Aufruf über KAS** (all-inkl bietet in KAS „Cronjobs“ an; Grenzen für Laufzeit und Häufigkeit habe ich **nicht geprüft**, Zugang nicht benutzt) | Würde die Sicherung nachts auf dem Server **vorbereiten** (Paket erzeugen, ablegen), dann müsste der Homeserver nicht auf `VACUUM INTO` warten. | Hilft beim Übertragen nicht: Die Dateien müssten trotzdem abgeholt werden, also wieder Variante B. Eine vorbereitete Datei im Webroot oder Datenordner ist eine zusätzliche Klartext-Kopie. Die 60-Sekunden-Grenze gilt vermutlich auch hier. | mittel | **Nicht in Version 1.** Nur sinnvoll, wenn die Messung zeigt, dass das Paket zu lange braucht. |
| **F. Ende-zu-Ende-Verschlüsselung** | Gestohlener Abruf nutzlos. | Siehe 3.6: großer Aufwand, kleiner Nutzen. | niedrig | **Später prüfen.** |

## 8. Empfehlung, Reihenfolge, Aufwand

**Empfehlung:** Variante B nach Abschnitt 2 bis 5, in dieser **Reihenfolge**:

1. **Livegang zuerst**, mit von Hand gezogener, frischer Sicherung (Bedingung 6 aus Bericht `35`). Dieser Baustein ist **keine Bedingung** für den Livegang, er ist eine Betriebsverbesserung danach.
2. Beim Livegang nebenbei **Zeit und Durchsatz messen** (Fable H1) und die Sicherung auf all-inkl einmal komplett von Hand ziehen. Die Zahlen bestimmen die Scheibengröße des Skripts.
3. Dann diesen Entwurf von **Fable prüfen lassen**, Befunde einarbeiten, **Lutz gibt frei**, dann bauen.

**Aufwand** (Sonnet baut, je Teil ein kurzer Auftrag):

| Teil | Inhalt | Dauer |
|---|---|---|
| T1 | Migration 12 (`backup_tokens`), Erzeugen und Widerrufen, Admin-Abschnitt, Hinweisband, Systemprüfung, `Restore` verwirft die Tabelle | etwa 1 Tag |
| T2 | `BackupApi` (4 Wege), Prüfung des Tokens, Sperren (3.4), Protokoll, gemeinsame Range-Hilfe | etwa 1 Tag |
| T3 | Tests: Gegenprobe über **alle** Router-Wege, Token abgelaufen/widerrufen/falsch/Konto gesperrt, Range und 416, Sperre je Adresse (und nicht je Kennung), Fehlantworten gleich, HTTPS-Pflicht, kein Token im Protokoll, `no-store`, Wiederherstellung verwirft Tabelle | etwa ½ Tag |
| T4 | `tools/sicherung-abholen.sh` (Pull, Prüfsummen, Scheiben, Fortsetzen, Aufbewahrung), Test gegen Docker-Container, Cron-/Timer-Beispiele, `docs/betrieb/Sicherung-Homeserver.md` | etwa 1 Tag |
| T5 | Messung auf all-inkl (`Authorization` kommt an, 60 Sekunden, Durchsatz): **nur nach Freigabe von Lutz**, mit einem Wegwerf-Schlüssel, danach widerrufen | etwa ½ Tag, Lutz dabei |
| Prüfung | Opus (`/code-review high`), danach **Fable** auf den Code (Anmeldeart neu: Schlüssel und Rechte) | je Runde ½ Tag |

**Risikoeinstufung:** **hoch** vor Abschnitt 3 (kein zweiter Faktor, Zugang zu Datenbank und Originalen mit GPS), **mittel** mit den beschriebenen Gegenmaßnahmen: Was ein gestohlener Schlüssel kann, ist auf **Lesen der Sicherung** begrenzt (keine Konten, keine Einstellungen, kein Schreiben, keine Sitzung); die Datenbank enthält Passwort-Hashes und **verschlüsselte** zweite Faktoren, aber nicht den Schlüssel dazu (`LK_APP_KEY` steht nie in der Sicherung). Offen bleibt der Datenabfluss der Originale (Fotos, unveröffentlichte Entwürfe, GPS); er wird durch Ablauf, Widerruf, Sperre und Anzeige „zuletzt benutzt“ begrenzt und erkannt, nicht verhindert.

## 9. Prüfplan

1. **Fable prüft diesen Entwurf vor dem Bau (Pflicht).** Fragen an Fable: (a) Reicht Abschnitt 3.3/3.4 gegen Abfluss bei gestohlenem Schlüssel? (b) Ist die Sperre je Adresse richtig (Aussperren des Homeservers durch Fremde unmöglich, Brute-Force trotzdem ausgeschlossen)? (c) Gilt „Erzeugen nur mit Passwort und Code“ als ausreichend gegen die Lücke aus F1? (d) Ist ein Widerruf ohne Bestätigung vertretbar? (e) Sollte die Ende-zu-Ende-Verschlüsselung doch in Version 1? (f) Ist das Verwerfen der Tabelle bei der Wiederherstellung richtig?
2. Danach Bau, **Opus** prüft den Code (`/code-review high`), eine Runde, eine zweite nur bei Befunden ab mittel.
3. **Fable prüft den Code** (Schlüssel, Rechte, Gegenprobe über alle Wege), vor dem ersten Einsatz mit echtem Token.
4. Praktisch: Wegwerf-Instanz im Container, Skript gegen sie, dann auf all-inkl (T5, nur nach Freigabe).
5. Auf pve10 zuerst, **pve01 erst nach ausdrücklichem „wir können jetzt auf pve01“ und Zustimmung von Lutz.**

## 10. Offene Fragen an Lutz (mit Empfehlung)

1. **Wo liegen die Schlüssel: in der Datenbank mit Verwaltungsseite (Empfehlung) oder als ein Eintrag in `app/.env` wie in Entwurf `20`?** Datenbank erlaubt mehrere benannte Schlüssel, Widerruf und „zuletzt benutzt“ ohne FTPS. `.env` wäre einfacher, kann aber nichts davon.
2. **Wann bauen: nach dem Livegang (Empfehlung) oder vorher?** Vorher heißt: Livegang verschiebt sich um etwa eine Woche (Bau, Prüfung durch Opus und Fable, Messung). Nachher heißt: die ersten Wochen sichert Lutz von Hand (nach Livegang eine frische Sicherung ist ohnehin Bedingung).
3. **Wie soll gemeldet werden, wenn ein Abruf ausbleibt: Hinweisband im Admin plus Wächter-Ping vom Homeserver (Empfehlung) oder zusätzlich E-Mail?** E-Mail aus der App gibt es bisher nicht (neue Baustelle, wäre eigene Prüfung). Hast du schon einen Wächter (z. B. Uptime Kuma) im Heimnetz, an den das Skript einen Ping schicken kann?
4. **Ohne Ende-zu-Ende-Verschlüsselung und ohne feste Adressbindung starten (Empfehlung)?** Der Homeserver liegt auf einem verschlüsselten Datenträger, die Wege sind nur lesend. Beides lässt sich später nachrüsten, wenn es sich als nötig zeigt.
