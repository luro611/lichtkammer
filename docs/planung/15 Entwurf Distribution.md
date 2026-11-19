# Entwurf: Verteilung von Lichtkammer (Release für Webspace und Docker)

Stand: 19.11.2026. **D1 bis D5 freigegeben (Lutz, 19.11.2026). Abschnitt 10 (Update im Admin) ist Entwurf und wartet auf Freigabe.** Frage: Wie kommt Lichtkammer an Interessenten, die nur FTP haben (wie bei all-inkl) oder die es mit Docker aufsetzen, und wie bleibt beides ohne doppelte Arbeit gepflegt? Grundlage: Plan `03` (zwei Betriebsarten), Schritt 8 (Härtung und Livegang). Nicht Teil: das Livegehen von br-foto.com (eigener Ablaufplan).

## 1. Ausgangslage

- Code: `app/` (PHP) und `public/` (Webroot). Daten, Originale und erzeugte Bilder liegen außerhalb des Webroots (`LK_DATA_DIR`, `LK_ORIGINALS_DIR`, `LK_MEDIA_DIR`), Einstellungen in `app/.env` bzw. Umgebungsvariablen.
- Wer FTP hat: `deploy.sh` ist ein Skript für Lutz (Schlüsselbund, lftp), nichts für Dritte.
- Docker: `Dockerfile` und `compose.yaml` gibt es; das Image ist nicht veröffentlicht, `compose.yaml` baut noch lokal.
- Es gibt keine Versionstags, kein Release, kein Zip, keinen Änderungsverlauf. Version steht in `Config::VERSION` (`0.1.0-dev`). Das Repo ist privat.
- Datenbank-Migrationen laufen beim ersten Aufruf nach einem Update selbst (mit Sicherung der Datenbank vorher). Das macht Updates per Überspielen möglich.

## 2. Grundsatz

**Eine Quelle, zwei Pakete, ein Auslöser.** Ein Versionstag (`v0.2.0`) erzeugt automatisch beide Pakete aus demselben Stand. Es gibt keinen Handbau von Zip oder Image. Fehlschlagende Tests verhindern das Release.

## 3. Paket A: Zip für Webspace (nur FTP)

- Datei `lichtkammer-<Version>.zip` am GitHub-Release, daneben `lichtkammer-<Version>.zip.sha256`.
- Inhalt (entspricht dem, was `deploy.sh` überträgt): `public/` (kommt in den Webroot), `app/`, `.env.example` (zeigt `LK_APP_KEY`, `LK_BASE_URL`, `LK_DATA_DIR`), `INSTALL.txt`, `LICENSE`, `VERSION`. Ohne `tests/`, `docs/`, `tools/`, `deploy/`, `otf/`, ohne `data/` und Fotos.
- **Installation** (`INSTALL.txt`, deutsch, in Schritten für Laien): Zip entpacken, alles per FTP/FTPS hochladen (`public/` in den Webroot, `app/` daneben), `.env` aus der Vorlage ausfüllen, Schlüssel erzeugen (ohne SSH, siehe Entscheidung D2), `/admin/setup` öffnen. Die Voraussetzungsprüfung (PHP-Version, Bildbibliothek, Schreibrechte) gibt es schon.
- **Update:** Neues Zip entpacken und über die alte Installation hochladen. `.env`, `data/`, Originale und `media/` sind nicht im Zip und bleiben unberührt. Beim ersten Aufruf migriert die Datenbank (mit Sicherung). `INSTALL.txt` sagt: vorher Sicherung der Daten ziehen.
- **Für Technische:** `deploy.sh` wird verallgemeinert dokumentiert (eigene `deploy/.env`), bleibt aber der Weg von Lutz.
- Auf dem Webspace ohne SSH kann der Admin kein `composer` und kein Build brauchen: Das Zip enthält alles fertig (ist heute schon so, kein Composer).

## 4. Paket B: Docker-Image

- Image `ghcr.io/luro611/lichtkammer` mit den Tags `<Version>` (z. B. `0.2.0`), `0.2`, `latest`; Plattformen `linux/amd64` und `linux/arm64`.
- Gebaut aus dem vorhandenen `Dockerfile` (PHP 8.5, Imagick mit AVIF/HEIC), Version wird als Build-Argument eingetragen und im Image als Beschriftung (`org.opencontainers.image.version`, `.source`, `.licenses`) hinterlegt.
- `compose.yaml` zeigt künftig `image: ghcr.io/luro611/lichtkammer:<Version>` als Standard; `build: .` bleibt als Kommentar für Entwickler.
- **Update:** `docker compose pull && docker compose up -d`. Volumes für Daten, Originale und Medien bleiben, Migration beim Start.
- Das Paket ist öffentlich, sobald das Repo öffentlich ist (Einstellung im GitHub-Paket).

## 5. Auslöser und Ablauf (GitHub Actions)

1. Lutz (oder Claude auf Auftrag) setzt `Config::VERSION` und `CHANGELOG.md`, committet, setzt das Tag `vX.Y.Z` und pusht es.
2. Der Workflow läuft: Tests (`tests/run.php` im vorhandenen Test-Image) → Zip bauen (Skript `tools/release-zip.sh`, lokal ebenso aufrufbar) → Image bauen und nach GHCR schieben → GitHub-Release mit Änderungsverlauf aus `CHANGELOG.md` und dem Zip.
3. Stimmt das Tag nicht mit `Config::VERSION` überein, bricht der Workflow ab.

Zwei Dateien kommen neu ins Repo: `.github/workflows/release.yml` und `tools/release-zip.sh`. Dazu `CHANGELOG.md`, `INSTALL.txt` und eine Installationsanleitung im README.

## 6. Pflege

- Versionszählung: 0.x bis zum Livegang von br-foto.com, dann 1.0.0 (bestätigt in CLAUDE.md, Lutz bestätigt hier noch einmal).
- Änderungsverlauf in `CHANGELOG.md` je Version, nach Art „Neu / Geändert / Behoben / Hinweise zum Update“. Migrationen werden darin immer genannt.
- Sicherheitsfixes: eigene Patch-Version, im Änderungsverlauf deutlich markiert.
- Unterstützte PHP-Version steht in `INSTALL.txt` und in `README` (heute PHP 8.3+, Image mit 8.5).

## 7. Vor dem ersten öffentlichen Release (Prüfliste)

- Repo auf Geheimnisse, EXIF-Reste, echte Adressen und personenbezogene Daten durchsehen (CLAUDE.md-Regeln), auch den Git-Verlauf.
- `README` und `INSTALL.txt` ohne Bezug auf br-foto-Zugangsdaten; `deploy/env.beispiel` nur mit Platzhaltern.
- Schriftenlizenzen (OFL) mitliefern (`LIZENZEN.txt` ist schon im Webroot).
- Repo öffentlich schalten, Paket öffentlich schalten, ersten Tag setzen. Erst nach ausdrücklicher Freigabe von Lutz.

## 8. Entscheidungen (D1 bis D5 freigegeben von Lutz am 19.11.2026, jeweils wie empfohlen)

- ✔ freigegeben 19.11.2026 – **D1 Zip und Image wie beschrieben?** (Empfehlung: ja.)
- ✔ freigegeben 19.11.2026 – **D2 Schlüssel auf Webspace ohne SSH:** Wie kommt eine Person ohne Konsole an `LK_APP_KEY`? Empfehlung: ein Hinweis in `INSTALL.txt` mit einer Zeile für jedes Betriebssystem und als Rückfall eine kleine Hilfsseite `/admin/setup` mit Token, die den Schlüssel erzeugt und in `app/.env` schreibt (wie `deploy.sh --einrichten` es für Lutz tut). Bitte freigeben oder anders entscheiden.
- ✔ freigegeben 19.11.2026 – **D3 Sprache der Anleitung:** Deutsch reicht für den Anfang (Empfehlung), Englisch später.
- ✔ freigegeben 19.11.2026 – **D4 Update-Hinweis im Admin** („neue Version verfügbar“): Empfehlung zunächst nicht, weil das einen Abruf bei GitHub bräuchte. Später als Option, standardmäßig aus.
- ✔ freigegeben 19.11.2026 – **D5 Zeitpunkt:** Erstes öffentliches Release erst nach dem Livegang von br-foto.com (Empfehlung), Vorarbeit (Skript, Workflow, Anleitung) schon vorher auf dem privaten Repo testen.

## 9. Bauplan nach Freigabe

1. `tools/release-zip.sh` und Test, dass das Zip nichts Unerwünschtes enthält (kein `data/`, keine `.env`, keine Fotos).
2. `INSTALL.txt`, `CHANGELOG.md`, Anleitung im README.
3. `.github/workflows/release.yml` (Tests, Zip, Image, Release); Probelauf auf einem privaten Tag.
4. `compose.yaml` auf das veröffentlichte Image umstellen.
5. Prüfung durch Opus (Release-Skript und Workflow), danach Prüfliste aus Abschnitt 7 und Freigabe zum Öffentlichschalten durch Lutz.

## 10. Update im Admin für Webspace-Nutzer

Stand: 19.11.2026. **Entwurf, wartet auf Freigabe. Nichts davon ist gebaut.** Ziel: Wer auf all-inkl (oder ähnlichem Webspace) hostet und kein FTP-Wissen hat, soll Lichtkammer mit wenigen Klicks aktualisieren. Das Update nach Abschnitt 3 (Zip entpacken, per FTP überspielen) bleibt als Rückfall.

**Das ist die sicherheitskritischste Funktion der Software.** Wer hier etwas einschleusen kann, führt eigenen PHP-Code auf dem Server aus. Darum gilt: strenge Prüfung vor dem Einspielen, Signatur, nur Administratoren, erneute TOTP-Bestätigung, und **eine Fable-Prüfung vor dem ersten Release ist Pflicht** (nicht Opus allein).

### 10.1 Was im Code schon da ist (geprüft am Stand 93c7404)

- **Migration:** `Auth/Database.php` ruft `Schema::migrate()` bei jedem Öffnen der Datenbank auf. Vorher legt es schon eine Sicherung ab (`VACUUM INTO`, nur wenn Migrationen anstehen). Ein Update muss also nur den Code tauschen; die erste Anfrage danach migriert selbst.
- **Veröffentlichen:** `Builder::publish()` hat eine Sperre (`site/.lock`, nicht blockierend), räumt Reste (`building-*`) auf, baut in einen Ordner und schaltet mit einer Umbenennung um (`current`); die alte Fassung bleibt (20 Fassungen). Ein Fehler lässt die alte Seite online.
- **Merker „noch nicht veröffentlicht“:** `meta.site_dirty` und `site_dirty_n` (Hinweisband). Dieser Merker wird vor dem Bau gesetzt und erst nach Erfolg gelöscht (`MediaPages::saveThenPublish`). Das Muster lässt sich wiederverwenden.
- **Version:** `Config::VERSION` (heute `0.1.0-rc1`). Es gibt **keinen** Merker „mit welcher Version wurde zuletzt gebaut“.
- **Zeitgrenze:** all-inkl hat `max_execution_time` 60 s; der Bau setzt 120 s, was dort nur wirkt, wenn der Host es erlaubt. Gemessene Bauzeit lokal 0,3 s bei 755 Dateien, auf all-inkl **noch nicht gemessen**.
- **Aufbau auf dem Server (wichtig für den Tausch):** Der Webroot ist die oberste FTP-Ebene und enthält die Dateien aus `public/`; `app/` liegt darin als Ordner `/app` (per `.htaccess` gesperrt), siehe `deploy.sh`. Daneben liegen `media/` und bei manchen Installationen weitere Nutzerdaten. Folge: **`app/` lässt sich als Ganzes umbenennen, der Webroot nicht.** Die Dateien aus `public/` (`index.php`, `assets/`, `.htaccess`) müssen einzeln ersetzt werden.

### 10.2 Nach einem Update einmal neu veröffentlichen

Die öffentlichen Seiten sind fertiges HTML. Änderungen an Design, `site.css` oder Vorlagen wirken darum erst beim nächsten Veröffentlichen. Prüfung: Ein Codeupdate ändert nichts an `site/current`; bis zum nächsten Bau bleibt alles beim Alten (kein Schaden, aber neue Funktionen wie neue Designs oder Blöcke sehen die Besucher nicht).

**Möglichkeiten:**

| | Beschreibung | Pro | Contra |
|---|---|---|---|
| **A** Hinweisband | Neuer Merker `meta.built_version` (Version beim letzten erfolgreichen Bau). Weicht er von `Config::VERSION` ab, zeigt der Admin das bestehende Band „Seite neu veröffentlichen, damit das Update sichtbar wird“. Nach dem Bau wird `built_version` gesetzt. | Sehr einfach, kein Risiko für die Besucherseite, Nutzer behält die Kontrolle (z. B. wenn gerade Unfertiges gespeichert ist). | Nutzer kann es übersehen. |
| **B** Automatisch nach dem Update | Der Update-Ablauf ruft am Ende `Builder::publish()` selbst auf (nach der Migration, mit der vorhandenen Sperre). Schlägt der Bau fehl, bleibt die alte Seite online, das Update gilt trotzdem als erfolgreich, und es erscheint Variante A als Hinweis mit „Erneut versuchen“. | Der Nutzer muss nichts tun. | Veröffentlicht auch Entwürfe aus „noch nicht veröffentlicht“ (der Bau nimmt nur, was als veröffentlicht markiert ist – das ist vor der Freigabe zu prüfen); Bauzeit zählt zur Zeitgrenze des Update-Aufrufs. |
| **C** Beim ersten Aufruf nach der Migration | Wie B, aber in `Database::open`. | – | **Nicht empfohlen:** Bau mitten in einer beliebigen Besucher- oder Admin-Anfrage, Zeitgrenze und Fehler treffen irgendwen. |

**Empfehlung: A und B zusammen.** Der Update-Ablauf versucht den Bau am Ende (eigener Schritt, eigene Meldung, eigene Zeitgrenze); gelingt er, steht `built_version` auf der neuen Version, gelingt er nicht, zeigt A den Hinweis. A allein deckt auch das Update per FTP ab (Abschnitt 3), wo kein Update-Ablauf läuft: Beim nächsten Admin-Aufruf fällt die Abweichung auf. Der Merker wird im selben Zug wie `site_dirty` gesetzt, damit nichts doppelt gebaut wird. **Risiko niedrig:** Fehlerweg ist der bestehende (Sperre, alte Fassung bleibt, `building-*` wird aufgeräumt). Offen vor dem Bau: Verhalten bei noch ungespeicherten Entwürfen prüfen (Test), und `built_version` bei Rückfall auf die alte Version nicht „zurückdrehen“, sondern das Band erneut zeigen.

### 10.3 Stufe 1: Zip hochladen unter „Verwaltung → Update“

**Skizze (Bildschirm):**

```
Verwaltung → Update
Installiert: 0.2.0        Datenbankstand: 11
[ Zip-Datei wählen ]  [ Prüfen ]

Nach dem Prüfen:
  Neue Version: 0.3.0   (signiert, Prüfsumme stimmt)
  Änderungen: <Text aus CHANGELOG im Zip>
  Es werden 214 Dateien in app/ und public/ ersetzt. Ihre Daten bleiben unberührt.
  Sicherheitsfrage: Code aus Ihrer Authenticator-App  [______]
  [ Jetzt aktualisieren ]

Danach: Schritte mit Haken (Sicherung ✔, Code getauscht ✔, Datenbank ✔, Seite veröffentlicht ✔) oder klare Fehlermeldung mit „Zurück zur alten Version“.
```

**Wie der Nutzer an das Zip kommt:** Er lädt `lichtkammer-<Version>.zip` von der GitHub-Release-Seite auf seinen Rechner und lädt es im Admin hoch. (Stufe 2 spart diesen Schritt.) Die Seite zeigt einen Link auf die Release-Seite und die Hilfe „So bekommen Sie die Datei“.

**Ablauf im Einzelnen (Server):**

1. **Anfrage prüfen:** Nur Rolle Administrator, CSRF-Schutz, erneute TOTP-Bestätigung (Code darf nicht schon in dieser Sitzung benutzt sein, vorhandene Wiederholschutz-Logik nutzen), Ratenbegrenzung. Docker: Seite zeigt nur „Update per `docker compose pull`“ (Erkennung über Umgebungsvariable, die das Image setzt), kein Hochladen.
2. **Upload:** Nur in ein privates Arbeitsverzeichnis unter `LK_DATA_DIR/update/`, Größenlimit aus `upload_max_filesize` (all-inkl meist 64 MB bis 256 MB, das Zip ist Schätzung 3 bis 6 MB mit Schriften). Passt es nicht, klare Meldung.
3. **Signatur prüfen** (vor jedem Entpacken): Das Zip hat eine Begleitdatei `MANIFEST.json` mit allen Dateien samt SHA-256 und `MANIFEST.sig`. Prüfung mit `sodium_crypto_sign_verify_detached` (ed25519, libsodium ist ohnehin Voraussetzung, siehe `Auth/Keys.php`). Der **öffentliche** Schlüssel steht fest im Code (`app/src/Update/PublicKey.php`). Nur wer den privaten Schlüssel hat, kann gültige Pakete erzeugen. Ohne gültige Signatur: Abbruch, nichts wird entpackt.
4. **Manifest gegen Inhalt:** Jede Datei im Zip muss im Manifest stehen, mit passender Prüfsumme und Größe; nichts darf fehlen oder zusätzlich sein.
5. **Versionsprüfung:** `VERSION` im Manifest (signiert) muss **größer** sein als die installierte (`version_compare`); gleiche oder kleinere Version wird abgelehnt (Schutz vor Herabstufung, siehe 10.6). Zusätzlich: Mindestversion, ab der ein Sprung erlaubt ist (z. B. wenn eine Zwischenversion Migrationen braucht), und Mindest-PHP-Version, beides im Manifest.
6. **Inhaltsliste prüfen:** Nur Pfade unter `app/` und `public/` (plus Wurzeldateien `VERSION`, `LICENSE`, `CHANGELOG.md`). Verboten: `data/`, `.env`, `media/`, `originals`, `site/`, `restore/`, Pfade mit `..`, absolute Pfade, Laufwerksbuchstaben, Nullbytes, Backslashes, Symlinks und andere Sondertypen (über Unix-Modus im Zip erkennen), doppelte Namen, Dateien ohne erlaubte Endung (nur `.php`, `.css`, `.js`, `.svg`, `.woff2`, `.otf`, `.json`, `.txt`, `.md`, `.html`, `.htaccess` in festen Pfaden), Obergrenzen für Anzahl, Einzelgröße und entpackte Gesamtgröße (Zip-Bomben).
7. **Entpacken in ein neues Verzeichnis** (`LK_DATA_DIR/update/neu-<id>/`), jede Datei einzeln, Zielpfad nach `realpath` erneut gegen das Verzeichnis geprüft, danach Prüfsumme der entpackten Datei noch einmal vergleichen. Zum Entpacken wird `ZipArchive` verwendet, falls vorhanden; sonst Fehlermeldung (Voraussetzungsprüfung nennt es vorab).
8. **Sicherung:** Datenbankkopie (`VACUUM INTO`, wie bei Migration) und Kopie der alten Code-Ordner als `app.alt` bzw. Liste der alten `public/`-Dateien.
9. **Sperre und Wartungsmodus:** Exklusive Sperre (`flock` auf `update/.lock`); Der Admin und die Veröffentlichungsfunktionen antworten währenddessen mit „Wartung, bitte gleich neu laden“ (Statuscode 503 mit `Retry-After`). Die **öffentliche Seite bleibt erreichbar**: Sie ist fertiges HTML aus `site/current` und braucht PHP nur für Admin und Auslieferung von Medien.
10. **Tausch:** (a) `app/` → `app.alt` umbenennen, `neu/app` → `app` umbenennen (zwei schnelle Umbenennungen, Lücke von Millisekunden); (b) Dateien aus `public/` in kleinen Schritten ersetzen, jeweils in eine `.neu`-Datei schreiben und per `rename` darüberlegen (atomar je Datei), `index.php` zuletzt; `.htaccess` mit Sorgfalt, damit ein Fehler dort nicht aussperrt (Rückfall: alte Datei bleibt als `.alt`).
11. **OPcache:** Nach dem Tausch `opcache_reset()` und für die geänderten Dateien `opcache_invalidate($f, true)`, soweit erlaubt. Wo OPcache Zeitstempel prüft (`validate_timestamps`), greift die neue Version nach spätestens `revalidate_freq` Sekunden. Die Seite sagt: „Bitte in einer Minute neu laden“, und eine Versionsanzeige prüft, dass `Config::VERSION` des **laufenden** Codes die neue Version ist.
12. **Migration:** Nächster Aufruf (oder gleich eine Anfrage an sich selbst) öffnet die Datenbank; die vorhandene Migration mit Sicherung läuft. Schlägt sie fehl: Rückfall (10.5).
13. **Neu veröffentlichen** nach 10.2.
14. **Audit-Eintrag** (`update_installed`: alte und neue Version, Prüfsumme des Zips, Benutzer); Fehlversuche mit eigenem Ereignis (`update_rejected` mit Grund, ohne Inhalte aus dem Zip). Aufräumen: `update/neu-*` und das Upload-Zip werden gelöscht, `app.alt` bleibt bis zum nächsten erfolgreichen Update.

**Pro Stufe 1:** Kein FTP nötig; kein Abruf bei Dritten (Privatsphäre, kein Fremdzugriff vom Server aus); kleine Angriffsfläche (nur Dateien, die der Admin selbst hochlädt); Signatur schützt auch dann, wenn jemand die GitHub-Release-Datei manipuliert.
**Contra Stufe 1:** Nutzer muss das Zip trotzdem herunterladen und hochladen; Admin-Konto, das gekapert wird, kann (nach TOTP) nur **signierte** Pakete einspielen – das ist gewollt, der Schaden bleibt begrenzt; Zeit- und Größenlimits des Webspace.

### 10.4 Berechtigungen auf all-inkl und der sichere Test

All-inkl lässt PHP und FTP meist unter **demselben Benutzerkonto** laufen; dann darf PHP seine eigenen Dateien ersetzen und umbenennen. Das ist **nicht gewiss** (Dateien, die per FTP angelegt wurden, gehören oft demselben Konto, aber `open_basedir` und Verzeichnisrechte können abweichen). Es muss auf dem Testserver `lichtkammer.br-foto.com` gemessen werden, bevor Stufe 1 gebaut wird.

**Harmloser Test (nichts Vorhandenes wird verändert):** Ein kleines Hilfsskript (nur für den Test, danach entfernt) im Admin oder als einmalige Seite, das ausschließlich Folgendes tut:
1. `app/` und `public/` auf Schreibbarkeit prüfen (`is_writable`, `fileperms`, `fileowner` gegen `posix_geteuid`, wenn verfügbar).
2. Im Elternordner von `app/` einen **neuen, leeren** Testordner `lk-update-test/` anlegen, darin eine Datei schreiben, den Ordner umbenennen und wieder löschen (zeigt: Anlegen, Umbenennen, Löschen im selben Ordner wie `app/` erlaubt?).
3. In einer **Kopie** (nicht im echten `app/`) eine Datei per `rename` ersetzen.
4. Prüfen, ob ein Verzeichnis mit Inhalt umbenannt werden kann (Kopie eines kleinen Testordners, nicht `app/`).
5. `opcache_get_status()`, `opcache.validate_timestamps`, `revalidate_freq`, `opcache_reset`-Erlaubnis (`disable_functions`), `ZipArchive`, `sodium`, `upload_max_filesize`, `post_max_size`, `max_execution_time`, freier Platz (`disk_free_space`).
Ergebnis wird als kurzer Bericht festgehalten. Der echte `app/`-Ordner wird beim Test **nicht** angefasst. Gleichzeitig ein zweiter Test von Konto B (anderes Konto auf all-inkl, falls vorhanden), weil Interessenten ebenfalls dort sind.

**Rückfall, wenn PHP nicht schreiben darf:** Die Seite „Update“ zeigt dann: „Auf diesem Webspace darf das Programm sich nicht selbst aktualisieren“ mit dem Verweis auf das Update per FTP (Abschnitt 3). Nichts geht kaputt, die Funktion bleibt nur unbenutzbar.

### 10.5 Abbruch, Rückfall, Wiederholbarkeit

- **Phasen mit Zustandsdatei** (`update/state.json`, atomar geschrieben): `geprueft → entpackt → gesichert → getauscht → migriert → veroeffentlicht`. Bei jedem Aufruf der Update-Seite wird der Zustand gelesen. Bricht PHP nach 60 s ab oder die Verbindung reißt, steht dort die letzte abgeschlossene Phase, und die Seite bietet „Fortsetzen“ oder „Zurück zur alten Version“ an.
- **Nicht in einem Aufruf alles:** Jede Phase ist ein eigener Aufruf (Weiterleitung oder kleine Fortschrittsanzeige per Skript), damit nie mehr als wenige Sekunden laufen. Der Tausch von `app/` ist wenige Umbenennungen; `public/` einige Dutzend Dateien.
- **Halber Tausch:** Weil `public/`-Dateien einzeln ersetzt werden, kann bei Abbruch eine Mischung aus alt und neu stehen. Gegenmaßnahme: Die Zustandsdatei hält die Liste der noch offenen Dateien; „Fortsetzen“ macht sie fertig. Zusätzlich liest `index.php` nur `app/` – steht `app/` neu und `public/assets` alt, zeigt der Admin höchstens falsche Formatierung, nichts Sicherheitsrelevantes. **Die Reihenfolge ist: erst `public/assets`, dann `app/`, zuletzt `index.php`.**
- **Zurück zur alten Version:** `app.alt` zurückbenennen, `public/`-Dateien aus den `.alt`-Kopien, OPcache leeren. **Datenbank:** Nach einer erfolgten Migration ist die Datenbank neuer als der alte Code. Darum Rückfall bei bereits migrierter Datenbank nur **mit Rückspielen der Sicherung** (Datenverlust seit dem Update ist dann möglich, die Seite sagt das vorher in einem Satz). Daher gilt: Migrationen müssen vorwärtskompatibel sein, wo möglich (neue Spalten, keine Löschungen), und `CHANGELOG` nennt „nicht rückwärts“ ausdrücklich.
- **Wiederholbar:** Gleicher Zip-Hash zweimal einspielen schlägt bei der Versionsprüfung fehl (gleiche Version); mit Zustandsdatei ist „Fortsetzen“ der einzige Weg weiter. Ein bewusstes „Gleiche Version erneut einspielen (Reparatur)“ ist **nicht** vorgesehen (Frage nicht nötig, FTP bleibt als Reparaturweg).
- **Laufende Anfragen während des Tauschs:** Anfragen, die schon laufen, haben ihre PHP-Dateien bereits geladen (oder laden Teile nach – daher die kurze Lücke bei `app/`). Das Fenster ist sehr klein und betrifft Admin und Medienauslieferung, nicht die fertige Seite; die Wartungssperre verhindert neue Admin-Anfragen. Mehr als eine Fehlerseite für einen Besucher in Millisekunden ist nicht zu erwarten.
- **Platzbedarf:** Zip + entpackt + `app.alt` + Sicherung: Schätzung unter 30 MB; `disk_free_space` vorher prüfen (Faktor 3 der entpackten Größe).

### 10.6 Signaturschlüssel, Herabstufung, Rechte

- **Privater Schlüssel:** Wird einmal erzeugt (`sodium_crypto_sign_keypair`), liegt **nie im Repo**, nie in GitHub-Secrets im Klartext der Allgemeinheit zugänglich gedacht. Vorschlag: auf Lutz' Rechner im Schlüsselbund bzw. auf einem Hardware-Schlüssel, das Zip wird lokal signiert (`tools/release-sign.sh`) und die Signatur am Release angehängt. Der Workflow aus Abschnitt 5 baut das Zip, die Signatur kommt in einem zweiten, **handgesteuerten** Schritt. Dadurch genügt ein gekapertes GitHub-Konto allein nicht, um Schadcode zu signieren. (Preis: Release braucht einen Handgriff von Lutz.)
- **Rotation:** Das Manifest nennt eine `key_id`. Der Code enthält eine Liste öffentlicher Schlüssel (aktuell und Nachfolger). Ein neuer Schlüssel wird in einer Version eingeführt, die noch mit dem alten signiert ist; später wird der alte entfernt. Bei Verlust oder Verdacht auf Kompromittierung: Widerruf nur durch FTP-Update, wo der neue Schlüssel eingebaut ist (Hinweis im Release). Das ist unbequem und deshalb vorab zu beschreiben.
- **Herabstufung (Rollback-Angriff):** Ein Angreifer legt ein altes, **echt signiertes** Zip mit bekannter Sicherheitslücke vor. Schutz: Versionsvergleich nur aufwärts, plus Merker `meta.max_version_seen` (höchste je eingespielte Version, nie sinkend). Das Manifest hat zusätzlich `min_allowed_from`, damit wir Versionen mit schweren Lücken gesperrt halten können.
- **Rechte:** Nur Rolle Administrator sieht und nutzt „Update“; CSRF; erneute TOTP-Bestätigung unmittelbar vor „Jetzt aktualisieren“; Ratenbegrenzung der Fehlversuche; Audit-Eintrag (Kette). Der Upload-Endpunkt bearbeitet nur Admins, vor dem Lesen des Zips.
- **Was Signatur nicht schützt:** Ein signiertes Paket mit Fehler (Bug) bleibt ein Paket mit Fehler; darum vor jedem Tag die Tests, Probe auf dem Testserver und das Zip-Inhaltstest-Skript aus Abschnitt 9.

### 10.7 Stufe 2 (später, standardmäßig aus)

- **Hinweis „neue Version verfügbar“:** Der Server ruft einmal am Tag `https://api.github.com/repos/luro611/lichtkammer/releases/latest` ab (kleiner Aufruf, nur Version und Link, **keine** Daten über die Installation, kein Cookie, neutraler User-Agent). Antwort wird zwischengespeichert; Fehler stillschweigend. Einstellung „Nach neuen Versionen suchen“, **standardmäßig aus**, mit Hinweis: Dabei erfährt GitHub die IP-Adresse des Servers. Voraussetzung: `allow_url_fopen` oder cURL.
- **Knopf „Jetzt aktualisieren“:** Lädt das Zip und die Signatur von der festen GitHub-Adresse (nur `https://github.com/luro611/lichtkammer/releases/download/…`, Host fest, Weiterleitungen nur auf `objects.githubusercontent.com`, TLS-Prüfung an, Größenlimit, Zeitlimit) in das Arbeitsverzeichnis und läuft dann **genau durch die Prüfungen und Schritte von Stufe 1**. Der Download vertraut nur der Signatur, nicht der Adresse.
- **Pro:** Wirklich einfach (ein Klick). **Contra:** Abruf nach außen vom Server (Datenschutz, Ausfall von GitHub, `allow_url_fopen` oft gesperrt); mehr Angriffsfläche (Umleitungen, SSRF-Muster); Hosting-Anbieter (all-inkl) blockiert manche ausgehende Verbindungen. Stufe 2 baut nur auf Stufe 1 auf und fügt keine neuen Rechte dazu.

### 10.8 Bauteile und Aufwand (grobe Schätzung, nur nach Freigabe)

| Teil | Inhalt | Aufwand |
|---|---|---|
| U0 | Berechtigungstest auf dem Testserver (10.4), Bericht | ½ Tag |
| U1 | Schlüsselerzeugung, `tools/release-sign.sh`, Manifest- und Signaturformat, Test mit absichtlich kaputten Paketen | 1 Tag |
| U2 | Prüfer: Signatur, Manifest, Inhaltsliste, Versionsregeln (reiner Code, viele Tests, auch gegen Zip-Bomben, `..`, Symlinks) | 1½ Tage |
| U3 | Ablauf: Entpacken, Sicherung, Tausch, OPcache, Zustandsdatei, Rückfall, Wartungsmodus | 2 Tage |
| U4 | Oberfläche „Verwaltung → Update“, Rechte, TOTP-Bestätigung, Audit, Hilfetexte | 1 Tag |
| U5 | Merker `built_version`, Hinweisband, Bau nach Update (10.2) | ½ Tag |
| U6 | Fable-Prüfung (Pflicht), Behebung, Probe auf Testserver mit Update 0.x → 0.x+1 | 1 bis 2 Tage |
| U7 | Stufe 2 (Hinweis und Download) | 1½ Tage, erst später |

Stufe 1 gesamt: etwa 7 bis 9 Arbeitstage einschließlich Prüfung. Reihenfolge: U0 zuerst; fällt der Test negativ aus, entfällt Stufe 1 auf diesem Webspace.

### 10.9 Offene Fragen an Lutz

1. **Wohin mit dem privaten Signaturschlüssel?** Empfehlung: nur auf deinem Mac (Schlüsselbund) und eine verschlüsselte Sicherungskopie getrennt aufbewahren; Signieren von Hand beim Release (Schritt nach dem Workflow). Alternative: GitHub-Secret und vollautomatisch (bequemer, aber ein gekapertes GitHub-Konto könnte Schadcode signieren).
2. **Nach dem Update automatisch neu veröffentlichen (10.2 B) oder nur Hinweis (A)?** Empfehlung: beides: automatisch versuchen, bei Fehler Hinweisband.
3. **Wann bauen?** Empfehlung: erst nach dem Livegang von br-foto.com und nach dem Berechtigungstest U0 (kostenlos, auf dem Testserver); Stufe 1 vor dem ersten öffentlichen Release, Stufe 2 danach und nur auf Wunsch.
4. **Rückfall mit Datenbank:** Soll „Zurück zur alten Version“ nach erfolgter Migration die Datenbank-Sicherung automatisch einspielen (Empfehlung: nein, nur anbieten und vorher ausdrücklich warnen, weil neuere Eingaben verloren gehen)?
