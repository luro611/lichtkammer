# Prüfung vor dem Livegang (Fable)

Stand: 19.11.2026. Geprüft: Commit `5b43794` (dieser Stand steht laut Auftrag auch auf dem Testserver).
Maßstab: Entwurf `20`, Prüfungen `21`, `22`, `34`, `18`/`19`, `docs/betrieb/Wiederherstellung.md`, `Umstellung-Server.md`.
Tests vorher: 1 444 Prüfungen grün. Nach den Nachbesserungen: **1 455 Prüfungen, 0 Fehler**.
Nichts hochgeladen, nichts am Server, an DNS oder WordPress geändert. Der Testserver wurde nur lesend und ohne Anmeldung abgefragt.

## Ergebnis in Kürze

**Livegang: ja, mit Bedingungen** (Liste am Ende).

Der Code ist sorgfältig gebaut. Aus dem Netz ohne Konto habe ich keinen Weg zu Originalen, Datenbank, Schlüsseln oder Verwaltung gefunden, weder im Code noch am Testserver. Die Redaktion kommt nicht an Sicherung, Systemprüfung, Konten oder Import.

Gefunden habe ich **einen Fehler mit Risiko hoch (F1)** und **einen kleinen (F2)**. Beide sind behoben und mit Tests abgesichert. Dazu kommen Hinweise mit Risiko niedrig, die ich nicht geändert habe (Abschnitt „Offene Hinweise“), und zwei Punkte, die sich nur auf dem echten Server klären lassen (Zeitgrenze, Wiederherstellungsprobe).

**Wichtig:** Der Stand `5b43794` auf dem Testserver enthält F1 noch. Vor dem Livegang muss der neue Stand hochgeladen werden.

## Wie geprüft wurde

- **Code gelesen:** Sicherung (`BackupPages`, `Backup`, `Tar`, `Response`), Bestätigung (`Auth::reauthenticate`, Cookies, Sitzungen, vertraute Geräte), Wiederherstellung (`Restore`, `RestorePages`, `SafeMove`), Auslieferung (`Site/Server`, `public/index.php`, `App`, `Request`, `SecurityHeaders`, beide `.htaccess`, `.user.ini`), Dateiannahme (`Uploads`, `Library`, `Files`, `SvgCheck`, `PdfCheck`, `Names`, Logos), Bildvarianten (`ImagickSource`, `ExifReader`), Veröffentlichen (`Builder`, `saveThenPublish`), Konfiguration (`Config`, `Preflight`), Router mit allen Rollen.
- **Praktisch, im Testrahmen (Docker):** Angriff „gestohlene Sitzung“ Schritt für Schritt nachgestellt (siehe F1).
- **Praktisch, im echten Container** (Image aus dem `Dockerfile`, Härtung wie in `compose.yaml`, echter Apache): Auslieferung aus `/media` und `/media/dateien` mit präparierten Dateien, Sperren, Seiten vor der Anmeldung.
- **Testserver, nur lesend und ohne Anmeldung:** 40 Adressen und die Kopfzeilen abgefragt, ein veröffentlichtes Bild in drei Formaten heruntergeladen und auf Metadaten untersucht.

## Befunde

### F1 – Gestohlene Admin-Sitzung konnte sich ein eigenes Administrator-Konto anlegen und damit die Sicherung holen · Risiko **hoch** · **behoben**

- **Wo:** `AdminController::usersInvite` und `usersAction` (`reset`, `role`, `enable`).
- **Was:** Die Sicherung verlangt zu Recht Passwort und Code. Das Erzeugen einer Einladung für ein Administrator-Konto verlangte aber nur die laufende Sitzung. Wer das Sitzungs-Cookie eines Administrators hat (ohne Passwort, ohne Code), konnte:
  1. unter „Konten“ eine Einladung mit Rolle Administrator erzeugen,
  2. den Link im eigenen Browser öffnen, eigenes Passwort und eigenen zweiten Faktor einrichten,
  3. sich mit dem neuen Konto anmelden, die Sicherung mit den **eigenen** Zugangsdaten bestätigen und alles herunterladen (Datenbank und Originale mit GPS).
  
  Derselbe Umweg ging über „Zurücksetzen“ eines anderen Administrator-Kontos (der Link übernimmt das Konto), über die Rolle (Redaktion → Administrator) und über das Entsperren eines gesperrten Administrator-Kontos.
- **Folge:** Der Schutz „Sicherung nur mit Passwort und Code“ war für genau den Angreifer wirkungslos, gegen den er gedacht ist.
- **Wiederholbarkeit:** immer. Im Testrahmen nachgestellt: Sitzung kopiert (andere Adresse), Paket ohne Bestätigung 403, Administrator-Einladung erzeugt, neues Konto eingerichtet, Paket mit Status 200 geladen.
- **Behebung:** Alles, woraus ein nutzbares Administrator-Konto entstehen kann, verlangt jetzt Passwort **und** Code aus der App (gleiche Prüfung wie bei der Sicherung, mit Wiederholschutz und Sperre nach Fehlversuchen):
  - Einladung mit Rolle Administrator,
  - Zurücksetzen eines Administrator-Kontos,
  - Rolle auf Administrator setzen,
  - Entsperren eines Administrator-Kontos.
  
  Fehlen Passwort und Code, erscheint eine Bestätigungsseite (neue Vorlage `admin/confirm.php`); es wird nichts erzeugt und nichts verändert. Einladungen und Zurücksetzen für die **Redaktion** gehen wie bisher ohne Rückfrage.
- **Tests:** 9 neue Prüfungen in `tests/sicherung.php` („F1: …“).
- **Für Lutz zu wissen:** Beim Einladen eines Administrators fragt die Seite jetzt einmal nach Passwort und Code. Jeder Code gilt nur einmal; direkt nach dem Anmelden also auf den nächsten Code warten (30 Sekunden).
- **Bleibt (bewusst, Risiko niedrig):** Eine gestohlene Admin-Sitzung kann weiterhin alles, was die Verwaltung ohne Rückfrage erlaubt: Inhalte ändern und veröffentlichen, Redaktion einladen, andere Konten sperren, Sitzungen beenden. An Originale, Datenbank und zweite Faktoren kommt sie nicht mehr. Die Sitzung endet nach 30 Minuten Leerlauf.

### F2 – Seite „Sicherung einspielen“ zeigte vor der Anmeldung den absoluten Serverpfad · Risiko **niedrig** · **behoben**

- **Wo:** `RestorePages::restoreSetupForm`, Vorlage `admin/restore_setup.php`.
- **Was:** Solange die Ersteinrichtung offen ist (kein Konto, `LK_SETUP_TOKEN` gesetzt), konnte jeder Besucher `/admin/setup/wiederherstellen` öffnen und sah den vollen Pfad des Bereitstellungsordners. Auf all-inkl steht darin die Kontonummer (`/www/htdocs/w0…/`).
- **Wiederholbarkeit:** immer in diesem Zeitfenster (im Container nachgestellt). Auf dem Testserver ist die Einrichtung geschlossen (404).
- **Behebung:** Die Seite nennt nur noch die Lage (`<Webroot>/restore`, `<Datenordner>/restore` oder „der Ordner aus LK_RESTORE_DIR“). Die Seite für angemeldete Administratoren zeigt den Pfad weiter. 2 neue Prüfungen.
- **Bleibt:** Datum, Version und Zahl der Originale einer bereitgestellten Sicherung sind in diesem Zeitfenster sichtbar. Unkritisch; das Fenster ist kurz und endet mit dem Einspielen.

## Was geprüft wurde und in Ordnung ist

### Aus dem Netz, ohne Konto (Testserver gemessen)

| Prüfung | Ergebnis |
|---|---|
| `/app/.env`, `//app/.env`, `/APP/.env`, `/deploy/.env`, `/.htaccess`, `/.user.ini`, `/.git/config` | 403 |
| `/app/bootstrap.php`, `/app/src/Config.php`, `/app/templates/error.php`, `/tests/run.php`, `/index.php/app/.env` | 403 |
| `/data/`, `/data/lichtkammer.sqlite`, `/data/site/current`, `/originals/`, `/restore/`, `/import/`, `/README.md` | 403 |
| Original zu einem veröffentlichten Bild (Name aus der Bildadresse abgeleitet) unter `/originals/…` und `/data/originals/…` | 403 |
| `/admin/sicherung`, `/originale`, `/original/1`, `/admin/system` | 303 zur Anmeldung |
| `/admin/setup`, `/admin/setup/wiederherstellen`, `/admin/reset` | 404 (geschlossen) |
| `/media/`, `/media/14/`, `/assets/` (Verzeichnislisten) | 403 |
| `/_redirects.json`, `/index.php`, `/composer.json` | 404 |
| Fehlerseite bei 403 | eigene Seite (745 Byte), keine Serverkennung, keine Pfade |
| Köpfe der Startseite und der Anmeldung | CSP, `nosniff`, HSTS, `X-Frame-Options`, COOP/CORP, `no-store` im Admin, Cookie `__Host-…; HttpOnly; SameSite=Strict; Secure` |
| Veröffentlichtes Bild (JPEG, WebP, AVIF je 1280 px) | kein EXIF, kein GPS, kein XMP, kein Farbprofil |
| HTTP statt HTTPS auf `/admin/login` | 301 auf HTTPS |

### Sicherungsweg

- **Rollen:** Alle Wege der Sicherung, Wiederherstellung, Systemprüfung, Konten und des Imports sind im Router auf `admin` gestellt. Für die Redaktion gibt es keinen Umweg (kein Weg liefert Originale aus, außer `/admin/sicherung/…`).
- **Bestätigungs-Cookie `lk_bk`:** HMAC über Sitzungs-Hash, Konto und Ablauf; Format streng geprüft; Ablauf höchstens 10 Minuten in der Zukunft. Ein Cookie aus einer anderen Sitzung, einem anderen Konto oder mit verlängerter Zeit gilt nicht. Fixierung ist nicht möglich: Der Wert lässt sich ohne Schlüssel nicht erzeugen, und das `__Host-`-Präfix verhindert, dass eine Subdomain ein Cookie unterschiebt. Abmelden und Passwortwechsel beenden die Sitzung bzw. die anderen Sitzungen; damit erlischt die Bestätigung.
- **Kein Einmal-Link, sondern 10 Minuten:** vertretbar. Wer in diesen 10 Minuten beide Cookies stiehlt, kommt an die Sicherung; wer das kann, sitzt aber ohnehin im Browser von Lutz.
- **Passwort vor Code:** Das Passwort wird zuerst geprüft; ohne Passwort lässt sich der Code nicht durchprobieren. Fehlversuche zählen auf den Zähler der zweiten Stufe.
- **GET für Originale:** Ich bestätige die Begründung aus Bericht `34`. Fremde Seiten lösen keinen Abruf mit Anmeldung aus (`SameSite=Strict`). Selbst von einer Subdomain derselben Domain (siehe H5) kommt nichts beim Angreifer an: Antwort ist `attachment`, kein CORS, `Cross-Origin-Resource-Policy: same-origin`. Der Abruf ändert nichts außer Protokoll und Zähler.
- **Pfade und Symlinks:** Pfade nur aus der Datenbank, Muster `xx/<24 hex>.<ext>`, `realpath` im Ordner der Originale, keine Symlinks. Range-Angaben mit übergroßen Zahlen, hinter dem Ende oder mehrfach: 416.
- **TAR:** Namen mit `..`, führendem `/` oder Nullbyte werden abgelehnt; Namen in `files/` kommen nur mit sicheren Zeichen ins Archiv.
- **Abbruch (`ignore_user_abort`):** Der Abbruch wird je 64 KB geprüft, die Datenbankkopie wird gelöscht; nach gesendeten Köpfen wird nur noch protokolliert, nichts ausgegeben.
- **Köpfe:** `attachment`, `application/octet-stream`, `nosniff`, `private, no-store`, `no-referrer`, fester Dateiname.

### Wiederherstellung

- Nur solange es kein Konto gibt, nur mit `LK_SETUP_TOKEN` (zeitgleicher Vergleich, Sperre nach Fehlversuchen, CSRF-Schutz vor der Anmeldung). Nach dem Einspielen ist der Weg geschlossen (404).
- Die Datenbank wird vor dem Tausch geprüft (Integrität, Tabellen, Schema, Konten, Schlüssel); die alte wird beiseitegelegt, nie gelöscht.
- **Tabellen mit Geheimnis-Bezug (Frage 5 aus Bericht `34`):** Verworfen werden `sessions`, `trusted_devices`, `pending_enrollments`, `login_attempts`, `login_failures` und offene `invites`. Es bleiben `users` (Passwort-Hash, verschlüsseltes TOTP-Geheimnis), `recovery_codes` (Hashes) und `audit_log`; das ist gewollt. `uploads` und `plan_items` bleiben stehen, zeigen aber nur auf Teilstücke, die es auf dem neuen Server nicht gibt, und werden nach 24 Stunden aufgeräumt. Unkritisch.
- Originale: jede Datei gegen die Prüfsummenliste, nie überschreiben, keine Symlinks.

### Dateiannahme und Auslieferung von Dateien

- **Typ nur am Inhalt**, Name auf dem Server immer Zufallskennung plus feste Endung. Der hochgeladene Name ist nur Anzeigetext (bereinigt, immer maskiert ausgegeben, im Download-Kopf ohne Sonderzeichen).
- **SVG:** Positivliste für Elemente und Attribute, keine Skripte, keine Ereignisse, keine Verweise nach außen, keine Entities, keine eingebetteten Inhalte; `href` nur `#id`. Zusätzlich beim Ausliefern `Content-Security-Policy: … sandbox` (im Container gemessen: auch eine von Hand abgelegte SVG mit `<script>` bekommt den Kopf). SVG wird nie von Imagick gelesen.
- **PDF:** Filter gegen aktive Inhalte (ehrlich als Filter beschrieben), fester Typ, `nosniff`.
- **Kein PHP im Medienordner** (im Container gemessen): `a.php`, `a.phtml` 403; `a.php.pdf`, `a.PHP`, `x.pHp` werden als Text bzw. PDF ausgeliefert und **nicht ausgeführt**.
- **Private Dateien** (`/admin/medien/datei/…`): nur angemeldet, `no-store`, `no-referrer`, Range sauber, SVG mit Sandbox.
- **Upload in Stücken:** an das Konto gebunden, Kennung zufällig, Größe und Versatz geprüft, CSRF-Kopf Pflicht. Es gibt kein Entpacken von Zip-Dateien auf dem Server (Ordner-Upload schickt einzelne Dateien; Ordnernamen sind Daten, nie Pfade).
- **Bildvarianten:** `stripImage()` vor jeder Ausgabe; am Testserver bestätigt. Auf der Seite erscheinen nur Kamera, Objektiv, Belichtung und Datum, und nur wenn die Galerie es erlaubt. GPS wird nie gespeichert.

### Öffentliche Seite und Veröffentlichen

- `Site/Server` liest nur aus der aktiven Fassung, prüft Zeichen, `..`, versteckte Namen und `realpath`. Weiterleitungen nur auf lokale Pfade mit erlaubten Zeichen (kein offenes Weiterleiten, auch nicht über `//` oder Backslash).
- **Zwei gleichzeitige Veröffentlichungen:** Sperrdatei mit `flock`; der zweite Lauf bricht mit Meldung ab, die Fassung wird atomar umgeschaltet. Es geht nichts halb online.
- Entwürfe gehen nur mit ausdrücklichem Haken online.

### Schlüssel

- `LK_APP_KEY`, `LK_SETUP_TOKEN`, `LK_RESET_TOKEN` stehen nur in `app/.env` (gesperrt, gemessen). Keine Ausgabe in Seiten, Protokoll oder Systemprüfung. Tokens müssen mindestens 32 Zeichen haben. Im Repository und im Diff keine Geheimnisse.

## Offene Hinweise (nicht geändert)

| Nr. | Risiko | Hinweis | Empfehlung |
|---|---|---|---|
| H1 | **mittel** | **Zeitgrenze auf all-inkl ist nicht gemessen.** Ob der Webserver einen Teil von 150 MB nach 60 Sekunden kappt, lässt sich nur angemeldet am Server messen (war mir nicht erlaubt). | Nach dem Hochladen einmal messen; wenn Teile abbrechen, `LK_BACKUP_PART_MB` kleiner stellen. Bedingung 2. |
| H2 | **mittel** | **Wiederherstellungsproben A, B, C stehen aus** (`Wiederherstellung.md`). Eine Sicherung, die nie eingespielt wurde, ist keine. | Bedingung 3. |
| H3 | niedrig | **Eine Sicherung wird beim Einspielen als vertrauenswürdig behandelt.** Die Schlüsselprüfung greift nur, wenn ein Konto mit zweitem Faktor enthalten ist. Pfade aus der Datenbank (`photos.original_path`, `photo_variants.path`) werden bei Sicherung und Dateiabruf gegen ein Muster geprüft, an zwei anderen Stellen nicht (`Library::process`, endgültiges Löschen in `Catalog`). Eine von Hand veränderte Datenbank könnte dort auf Dateien außerhalb der Ordner zeigen. Wer eine Sicherung einspielen kann, hat aber schon FTPS und das Einrichtungs-Token. | Nur eigene Sicherungen einspielen. Später: beim Einspielen alle Pfadspalten gegen die Muster prüfen und sonst ablehnen (kleiner Baustein, aber erst prüfen, ob Importdaten abweichen). |
| H4 | niedrig | **Der Name des Originals ist aus der öffentlichen Bildadresse ableitbar** (gleiche Kennung). Schutz ist allein der Ort (außerhalb des Webroots) bzw. die Sperre. Am Testserver (Originale noch im Webroot, E19) hält die Sperre: 403. | Live nur mit `LK_ORIGINALS_DIR` außerhalb des Webroots (der Admin startet sonst nicht). Bereitstellungsordner `restore/` nach dem Einspielen leeren. Bedingung 4. |
| H5 | niedrig | **Eine Subdomain von br-foto.com gilt für den Browser als „dieselbe Seite“.** Läuft dort etwas Angreifbares (altes WordPress), kann es GET-Anfragen mit der Anmeldung von Lutz auslösen. Lesen kann es die Antworten nicht, und alles Ändernde braucht das CSRF-Token. | Das alte WordPress nach dem Umschalten nicht unter einer Subdomain von br-foto.com weiterlaufen lassen (abschalten wie in `Umstellung-Server.md`, Tag 14). |
| H6 | niedrig | **Sitzung ist nicht an Adresse oder Browser gebunden.** Eine kopierte Sitzung von anderer Adresse fällt im Protokoll nicht eigens auf. | So lassen (mobile Netze wechseln die Adresse). Mit F1 ist der Schaden begrenzt. |
| H7 | niedrig | **Bestätigung als Störmittel:** Wer eine Admin-Sitzung hat, kann mit falschen Bestätigungen die zweite Stufe dieses Kontos zeitweise sperren. | Hinnehmen. |
| H8 | niedrig | **`/media` liefert jede Datei aus, die dort liegt**, eine `.html` auch als HTML ohne eigenen Schutzkopf. Die App legt dort nur Bilder, PDF und SVG mit festen Namen ab; der Fall träte nur bei einem Fehlgriff per FTP ein. | Später in `public/media/.htaccess` `.html`, `.htm`, `.xml` sperren (auf all-inkl testen). |
| H9 | niedrig | **`X-Forwarded-Proto: https` umgeht die Umleitung auf HTTPS** für die eigene Anfrage (am Testserver gemessen). Der Admin verweigert dann den Dienst, öffentliche Seiten kommen unverschlüsselt. Betrifft nur den, der den Kopf selbst setzt. | Hinnehmen; HSTS deckt Browser ab. |
| H10 | niedrig | **Hinweisband nach gleichzeitigem Speichern:** Scheitert „Speichern und veröffentlichen“ an der Sperre eines anderen Laufs, kann dessen Abschluss das Band „nicht veröffentlicht“ löschen. Die Seite „Veröffentlichen“ rechnet es richtig nach. Kein Datenabfluss. | Bei Gelegenheit: Band nur löschen, wenn seit Baubeginn nichts gespeichert wurde. |
| H11 | niedrig | N3 bis N7 aus Bericht `34` gelten weiter (Range zählt gegen die Grenze, TAR-Kopf erst beim Senden geprüft, Platz zählen, Wortwahl, doppelter Kopf). | wie dort. |
| H12 | Hinweis | Im Datenordner liegen bis zu 20 Datenbankkopien vom Veröffentlichen (`backups/`), dazu Kopien vor Migration und Wiederherstellung. Sie sind so schutzwürdig wie die Datenbank selbst und liegen am selben, nicht öffentlichen Ort. | Kein Handlungsbedarf; beim Aufräumen des Servers daran denken. |

## Antworten auf die Liste aus Bericht 34 („Für Fable“)

1. **Zeitgrenze all-inkl:** nicht messbar ohne Anmeldung, bleibt Bedingung (H1).
2. **Bestätigungs-Cookie:** Bindung stimmt; 10 Minuten ohne Einmal-Nutzung sind vertretbar. Die eigentliche Lücke lag nicht im Cookie, sondern bei den Konten (F1).
3. **`ignore_user_abort`:** in Ordnung, Abbruch je 64 KB, keine Ausgabe nach den Köpfen.
4. **GET mit Cookie für Originale:** Begründung bestätigt, auch für den Fall „Subdomain derselben Domain“.
5. **Inhalt des Pakets und Verwerfen beim Einspielen:** vollständig für alles, was eine Anmeldung ersetzen könnte.
6. **`ErrorDocument` und `.user.ini` auf all-inkl:** `ErrorDocument` greift am Testserver (eigene Seite bei 403), keine 500. `.user.ini` ist gesperrt (403); ob PHP sie liest, zeigt die Systemprüfung (nur angemeldet, von Lutz anzusehen).
7. **Token-Abruf E11 a:** nicht gebaut, nicht geprüft. Falls er kommt: eigener Schlüssel nur für die Sicherungswege, kein Admin-Recht, eigene Sperre, und vorher eine eigene Prüfung.

## Urteil: Livegang ja, mit Bedingungen

1. **Der Stand mit F1 und F2 ist hochgeladen** (Testserver und danach live), nicht `5b43794`. Danach einmal ausprobieren: Administrator einladen fragt nach Passwort und Code.
2. **Sicherung auf all-inkl gemessen:** Paket und ein Teil der Originale laufen vollständig durch; sonst `LK_BACKUP_PART_MB` kleiner.
3. **Wiederherstellungsproben A (Docker auf pve10), B (all-inkl, Wegwerf-Subdomain) und C (falscher Schlüssel) bestanden** und in `Wiederherstellung.md` eingetragen.
4. **Originale liegen außerhalb des Webroots** (`LK_ORIGINALS_DIR`, Umzug abgeschlossen, Warnband weg), `restore/` und `import/` sind leer.
5. **`LK_SETUP_TOKEN` und `LK_RESET_TOKEN` stehen nicht mehr in `app/.env`**; `tools/livepruefung.sh` läuft ohne Fehler gegen die Liveadresse.
6. **Eine frische Sicherung** (Paket und alle Teile) liegt vor dem Umschalten auf einem verschlüsselten Datenträger, `LK_APP_KEY` getrennt im Passwort-Manager.
7. **Altes WordPress** bleibt nach dem Umschalten nicht dauerhaft unter einer Subdomain von br-foto.com erreichbar (H5).
8. Der noch offene **Browser-Test der Medienverwaltung durch Lutz** ist gemacht.

Nicht Bedingung, aber empfohlen nach dem Livegang: H3 und H8.

## Geändert in dieser Prüfung

- `app/src/Admin/AdminController.php`: Bestätigung mit Passwort und Code für Administrator-Einladung, Zurücksetzen und Entsperren von Administrator-Konten und Rolle Administrator (`confirmAdminGrant`) (F1).
- `app/templates/admin/confirm.php`: neue Bestätigungsseite (F1).
- `app/src/Admin/RestorePages.php`: kein absoluter Pfad auf der Seite vor der Anmeldung (`restoreDirLabel`) (F2).
- `tests/sicherung.php`: 11 neue Prüfungen (F1, F2).
