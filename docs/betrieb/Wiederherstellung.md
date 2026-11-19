# Wiederherstellung einer Sicherung (auch auf all-inkl ohne SSH)

Stand: 19.11.2026 (Schritt 8a und 8b, Entwurf 20 Abschnitt 2.4, Prüfung 21 H2). Gilt für all-inkl (nur FTPS) und Docker.
Dieses Dokument beschreibt das **Erzeugen** einer Sicherung (Verwaltung → Sicherung, `/admin/sicherung`), ihr **Format** und das **Einspielen**.

## Sicherung herunterladen (nur Administratoren)

1. Verwaltung → **Sicherung**. Zuerst mit **Passwort und Code aus der App** bestätigen; danach sind die Downloads 10 Minuten freigeschaltet (Cookie, an die Sitzung gebunden). Wer nicht bestätigt hat, bekommt nichts, auch nicht mit der Adresse.
2. **Paket herunterladen** (`.tar`): Datenbank (aus `VACUUM INTO`, nie die laufende Datei), `MANIFEST.json`, `originals.sha256`, `files/`. Ohne Originale. Doppelklick entpackt es auf Mac, Windows und Linux zu dem Ordner aus der Tabelle unten.
3. **Originale** in Teilen herunterladen (je Teil ein `.tar`, Standard etwa 150 MB, `LK_BACKUP_PART_MB` 10 bis 500). Alle Teile **in denselben Ordner** entpacken: `originals/xx/…` kommt zum Paket dazu. Bricht ein Download ab oder kappt der Webserver ihn (all-inkl: 60 Sekunden Laufzeit), den Teil einfach noch einmal laden; die Seite führt über „nach Foto-Nummer“ weiter. Einzelne Originale mit Fortsetzen (HTTP-Range): `/admin/sicherung/original/<Foto-Nummer>`.
4. Fotos, deren Original als Datei fehlt oder deren Pfad ungültig ist, nennt die Seite; sie sind nicht in der Sicherung.
5. Jeder Abruf steht im Protokoll (`backup_confirmed`, `backup_package`, `backup_originals`, nur Zahlen), die Seite zeigt „Zuletzt heruntergeladen“ (gelb nach 3 Tagen). Es gibt Grenzen: höchstens 6 Pakete und 120 Original-Abrufe je 15 Minuten und Konto.
6. **Ablegen:** auf einem verschlüsselten Datenträger (FileVault/LUKS). Die Datenbank in der Sicherung ist **nicht verschlüsselt**: Inhalte, Protokoll und Konten (Passwörter nur als Argon2id-Hash) lassen sich ohne Schlüssel lesen. Nur die Geheimnisse des zweiten Faktors sind mit `LK_APP_KEY` verschlüsselt; mit dem Schlüssel (getrennt im Passwort-Manager) wäre also auch der zweite Faktor offen. Originale enthalten alle Aufnahmedaten, auch GPS.
7. **Nicht gebaut (offen, mit Lutz zu entscheiden):** ein Abruf mit Token vom Homeserver (E11 a, `LK_BACKUP_TOKEN`), damit die Sicherung nachts von allein kommt. Bis dahin ist die Sicherung eine Handarbeit von Lutz (am besten nach jedem Veröffentlichen von neuen Fotos und vor jedem Update).

## Was eine Sicherung ist

Ein Ordner mit diesem Inhalt:

| Datei / Ordner | Inhalt | Pflicht |
|---|---|---|
| `MANIFEST.json` | `{"format":1,"schema_version":10,"app_version":"0.2.0","created_at":1790000000}` | ja |
| `lichtkammer.sqlite` | Datenbank, erzeugt mit `VACUUM INTO` (nie die laufende Datei kopieren) | ja |
| `files/` | private Dateien (PDF, Logos) wie unter `<Datenordner>/files/` | nein |
| `originals.sha256` | je Zeile `<SHA-256>␣␣<xx/zufallsname.ext>` (genau zwei Leerzeichen) | nein |
| `originals/xx/…` | die Originale, Pfade wie in der Datenbank (`original_path`) | nein |

Nicht gesichert werden: erzeugte Bildvarianten (`media/`, werden neu erzeugt), veröffentlichte Fassungen (werden neu veröffentlicht), `app/.env`. **Die `.env` mit `LK_APP_KEY` gehört getrennt in den Passwort-Manager**, nie zusammen mit der Datenbank. Wer Datenbank **und** Schlüssel hat, kann Konten samt zweitem Faktor öffnen; Sicherungen sind deshalb wie Zugangsdaten zu behandeln (verschlüsselter Speicher).

## Totalverlust: neuer Server, Schritt für Schritt

Vorher bereithalten: die Sicherung (Ordner), `LK_APP_KEY` aus dem Passwort-Manager, FTPS-Zugang.

1. **Dieselbe Version installieren** (oder eine neuere; eine ältere als die Sicherung lehnt das Einspielen ab). Docker: Image der Version. all-inkl: `./deploy.sh --jetzt` (Probelauf zuerst).
2. **`app/.env` mit dem alten `LK_APP_KEY`** anlegen, plus `LK_BASE_URL`, `LK_DATA_DIR`, `LK_ORIGINALS_DIR` (beide oberhalb des Webroots), einem neuen `LK_SETUP_TOKEN` (`openssl rand -hex 24`) und auf all-inkl `LK_RESTORE_DIR=<Webroot>/restore`. Nicht den Schlüssel neu erzeugen: Mit einem anderen Schlüssel sind die zweiten Faktoren unlesbar und das Einspielen wird abgelehnt (es ändert dann nichts).
3. **Sicherung bereitstellen:** den Ordner per FTPS in den Bereitstellungsordner kopieren (all-inkl: `restore/` im Webroot, wird von der App gesperrt; Docker: `<Datenordner>/restore` im Volume). Die Dateien dürfen nach und nach hochgeladen werden, solange `lichtkammer.sqlite` und `MANIFEST.json` komplett sind.
4. **`/admin/setup`** öffnen, den Link „Stattdessen eine Sicherung einspielen“ nehmen, das Einrichtungs-Token eingeben. Die App prüft die Datenbank (Integrität, Schema, Konten, **passt der Schlüssel zu den zweiten Faktoren**) und übernimmt sie erst danach. Eine vorhandene leere Datenbank wird beiseitegelegt (`lichtkammer.sqlite.vor-wiederherstellung-<Zeit>`), nie gelöscht.
   - **Verworfen werden:** alle Sitzungen, vertraute Geräte, offene Einladungen und angefangene Einrichtungen (alle melden sich neu an).
   - **Bleibt:** Konten mit Passwort und zweitem Faktor, Wiederherstellungscodes, Fotos, Galerien, Seiten, Einstellungen, Protokoll (mit neuem Eintrag `backup_restored`).
   - Die Datenbankdatei wird danach aus dem Bereitstellungsordner gelöscht.
5. **Anmelden** mit dem bisherigen Konto. Ein Warnband meldet, dass Originale warten.
6. **Originale zurückholen:** Verwaltung → `/admin/wiederherstellung` → „Weiter“. Jede Datei wird gegen `originals.sha256` geprüft, dann verschoben (nie überschrieben). Ein Durchgang dauert höchstens etwa 25 Sekunden; „Weiter“ so oft drücken, bis nichts mehr wartet. Die Seite nennt Dateien mit falscher Prüfsumme, fehlende Dateien und Fotos ohne Original.
7. **Bildvarianten neu erzeugen** und **Veröffentlichen**. Die Varianten (`media/`) sind nicht in der Sicherung. **Stand 19.11.2026 (Probe A, Test-Sicherung): Es gibt dafür noch keinen Knopf für alle Fotos.** Ohne Varianten läuft das Veröffentlichen, die Seiten haben aber keine auslieferbaren Bilder (0 von 8 Bild-Adressen gaben 200). Bis ein Sammel-Knopf gebaut ist, ruft die Probe `POST /admin/fotos/<Nummer>/verarbeiten` je Foto auf (derselbe Aufruf wie nach einem Upload); auf all-inkl ist das ohne Hilfsmittel nicht möglich. Weiterleitungen und `robots.txt` kommen mit der Veröffentlichung.
8. **`LK_SETUP_TOKEN` entfernen** (`./deploy.sh --token-entfernen`), Bereitstellungsordner leeren (nach Prüfung der Fotozahl), `tools/livepruefung.sh` laufen lassen.

## Sonderfälle

- **Schlüssel verloren:** Die Datenbank ist ohne den Schlüssel nicht einspielbar (Schutz gegen Aussperren). Dann: frische Einrichtung (neues Konto), Fotos/Galerien/Seiten sind in diesem Fall nur über die Importmöglichkeiten wiederzuholen. Deshalb den Schlüssel doppelt sichern (Passwort-Manager und Ausdruck). **Offen, mit Lutz zu entscheiden:** ein Einspielen „trotzdem mit anderem Schlüssel“ (Konten müssten dann alle neu eingerichtet werden) ist bewusst **nicht** gebaut.
- **Sicherung aus älterem Schema:** wird beim Öffnen migriert; vorher legt die App `…vor-schema-N.bak` an.
- **Sicherung aus neuerem Schema:** abgelehnt mit Hinweis auf die Version.
- **Einspielen auf einem Server mit Konten:** nicht möglich (nur bei der Ersteinrichtung). Wer eine laufende Installation ersetzen will, setzt sie vorher zurück (Datenordner leeren) und beginnt bei Schritt 2.
- **Docker:** gleiche Schritte; Bereitstellungsordner und Datenordner liegen im Volume `lk-data`, die Originale im Volume `lk-originals`.

## Wiederherstellungsprobe (Pflicht vor dem Livegang)

1. Sicherung der Testinstallation ziehen (Verwaltung → Sicherung: Paket und alle Teile der Originale in **einen** Ordner legen, die `.tar`-Dateien müssen nicht entpackt werden).
2. **Probe A, Docker (lokal oder auf pve10):** frischer Container, Volumes leer, derselbe Schlüssel → Schritte 2 bis 7. Zeit stoppen. Prüfen: Anmeldung mit Passwort **und** TOTP, Fotozahl, Galerien, Seiten, Veröffentlichen, Bilder werden ausgeliefert, SHA-256 der Originale stimmen. **Das macht `tools/restore-probe.sh` selbst** (Anleitung unten). Nicht automatisch geprüft: das Einlösen eines Wiederherstellungscodes (einmal von Hand).
3. **Probe B, all-inkl in einer Wegwerf-Subdomain** (eigener Datenordner und Originalordner, nur nach Freigabe von Lutz; FTPS, kein SSH, 60 Sekunden Laufzeit, Rechte): derselbe Ablauf von Hand. Das erst belegt den Weg, den br-foto.com wirklich nutzt. Danach Subdomain samt Ordnern entfernen.
4. **Probe C mit falschem Schlüssel:** Das Einspielen muss mit der Meldung „LK_APP_KEY passt nicht“ scheitern und nichts verändern. Auch das macht das Skript (`--probe c`, es nimmt von allein einen falschen Schlüssel).
5. Ergebnis (Dauer, Probleme, Anpassungen) unten eintragen. **Ohne bestandene Probe A und B kein Livegang.**

### Anleitung: Probe A und C mit der echten Sicherung (Lutz)

**1. Sicherung ablegen.** In der Verwaltung unter Sicherung (`/admin/sicherung`) das Paket und alle Teile der Originale laden und in **einen** Ordner **außerhalb des Repos** legen, auf einem verschlüsselten Datenträger (FileVault), zum Beispiel:

```text
~/Documents/Projekte/lichtkammer-sicherung/2026-11-xx/
    lichtkammer-paket.tar
    lichtkammer-originale-teil-1.tar  (und weitere Teile)
```

Der Ordner wird vom Skript nur gelesen (es entpackt in einen Wegwerf-Ordner und löscht ihn am Ende). Die Sicherung nie ins Repo legen.

**2. Schlüssel geben.** Der `LK_APP_KEY` der Testinstallation steht in deren `app/.env` und im Passwort-Manager. Nie als Befehlszeilen-Argument. Drei Wege, der einfachste zuerst:

- Nichts angeben: das Skript fragt im Terminal nach dem Schlüssel (verdeckte Eingabe).
- Eine Datei mit einer Zeile (nur der Schlüssel, Rechte 600, außerhalb des Repos): `--schluesseldatei ~/Documents/Projekte/lichtkammer-sicherung/schluessel.txt`
- Umgebungsvariable `LK_APP_KEY` für diesen einen Aufruf.

Das Skript gibt den Schlüssel nie aus und schreibt ihn in keine Datei (nur in die Umgebung des Containers, der danach entfernt wird).

**3. Starten** (im Projektordner; Docker muss laufen; beim ersten Mal baut es das Image, etwa 2 bis 3 Minuten):

```bash
tools/restore-probe.sh ~/Documents/Projekte/lichtkammer-sicherung/2026-11-xx
```

Das führt Probe A und Probe C hintereinander aus. Nur eine: `--probe a` oder `--probe c`. Weitere Schalter: `--behalten` (Container und Volumes nicht entfernen, damit man sich ansehen kann, was entstanden ist; das Skript nennt den Port und den Befehl zum Aufräumen), `--ohne-bauen` (Image von einem früheren Lauf nutzen).

**4. Anmeldung prüfen lassen** (sonst übersprungen und so genannt). Benutzername, Passwort und ein frischer Code aus der Authenticator-App, ohne dass etwas im Terminal-Verlauf landet: Das Skript fragt, wenn `LK_PROBE_USER` gesetzt ist:

```bash
LK_PROBE_USER=lutz tools/restore-probe.sh ~/Documents/Projekte/lichtkammer-sicherung/2026-11-xx
```

Es fragt dann verdeckt nach dem Passwort und nach dem Code (der Code gilt nur 30 Sekunden: erst eingeben, wenn die Frage erscheint). Das Passwort und der Code werden nur an den Wegwerf-Container geschickt.

**5. Ausgabe lesen.** Jede Zeile beginnt mit ✓ (bestanden), ✗ (fehlgeschlagen) oder „übersprungen“ mit Grund. Am Ende steht „N bestanden, M fehlgeschlagen, K übersprungen“ und die Zeit; der Befehl endet mit Fehlercode ungleich 0, wenn etwas ✗ ist. Die wichtigsten Zeilen:

| Zeile | Bedeutet |
|---|---|
| Zahl der Originale im Ordner passt zur Liste | Es fehlen keine Teile der Originale |
| Datenbank eingespielt … / Konten, Fotos, Galerien, Seiten … stimmen | Die Zahlen im neuen Container sind dieselben wie in der Sicherung |
| Anmeldung, Stufe 1 und 2 | Passwort **und** TOTP funktionieren im neuen Server, also stimmt der Schlüssel |
| Originale über /admin/wiederherstellung zurückgeholt | Der echte Weg über die Verwaltung (bei „Ersatzweg“ lief es ohne Anmeldung direkt im Container) |
| SHA-256 … stimmen überein | Jede Originaldatei ist byte-gleich zur Prüfsummenliste |
| Bildvarianten …, Veröffentlichen, Bilder werden ausgeliefert | Die Seite lässt sich neu bauen und zeigt Bilder |
| Probe C: abgelehnt mit „LK_APP_KEY passt nicht“ und „unverändert“ | Mit falschem Schlüssel wird nichts eingespielt und nichts zerstört |

Die Probe läuft auf `127.0.0.1` mit zufälligem Port und eigenen leeren Volumes; sie berührt weder den Testserver noch all-inkl noch die Datenbank der Test-Installation. „Härtung wie `compose.yaml`“: dieselben Optionen (`no-new-privileges`, `cap_drop ALL` mit vier Fähigkeiten, 1 GB Speicher, Protokolle begrenzt), also zugleich ein Test dafür.

**Variante pve10.** Der Docker-Host für die Probe ist pve10 (Testserver). Name und Zugang kommen von Lutz; auf diesem Mac ist `pve10` nicht als SSH-Name bekannt, deshalb ist hier nichts eingetragen. Das Skript greift auf den Container über `127.0.0.1` zu, **muss also auf dem Docker-Host selbst laufen**; ein reiner Docker-Kontext (`DOCKER_HOST=ssh://…`) genügt nicht, weil Container und Port dann auf dem anderen Rechner liegen. Ablauf, wenn Lutz den SSH-Zugang `<pve10-Zugang>` (und darauf Docker, `curl`, `python3`, `openssl`, `zsh`) bestätigt hat:

```bash
# auf dem Mac: Repo (ohne .git) und Sicherung kopieren
rsync -a --exclude .git ./ <pve10-Zugang>:/tmp/lk-probe/repo/
rsync -a ~/Documents/Projekte/lichtkammer-sicherung/2026-11-xx/ <pve10-Zugang>:/tmp/lk-probe/sicherung/
# Schlüssel NICHT kopieren: im SSH-Terminal verdeckt eingeben lassen (-t gibt ein Terminal)
ssh -t <pve10-Zugang> 'cd /tmp/lk-probe/repo && tools/restore-probe.sh /tmp/lk-probe/sicherung'
# danach alles entfernen
ssh <pve10-Zugang> 'rm -rf /tmp/lk-probe'
```

Die Sicherung ist unverschlüsselte Kundendaten: auf pve10 nur auf einem Datenträger, dessen Inhalt danach gelöscht wird. Ob die Probe auf pve10 laufen darf, entscheidet Lutz; Probe A mit der Test-Sicherung auf dem Mac ist unten belegt.

### Ergebnisse der Proben

**Mit Test-Sicherung, noch nicht mit der echten Sicherung** (19.11.2026, lokal auf dem Mac in Docker mit OrbStack, `lichtkammer:probe` aus dem Repo-Stand `4f98402`, nicht pve10, nicht all-inkl):

Die Test-Sicherung entsteht mit `tests/erzeuge_testsicherung.php` (Wegwerf-Installation mit zufälligem Schlüssel, Konto mit TOTP, vier Fotos mit echten Originalen von 5,1 MB und eines klein, zwei Galerien, zwei Seiten, eine private Datei; Paket und zwei Originalteile, insgesamt 15 MB, `LK_BACKUP_PART_MB=10`):

```bash
docker run --rm -v "$PWD":/app -v <Ausgabe>:/out -w /app lichtkammer-test php tests/erzeuge_testsicherung.php /out/sicherung /out/zugang
```

| Probe | Ergebnis | Dauer |
|---|---|---|
| **A** (mit Anmeldedaten) | alle 14 Prüfungen bestanden: Zahlen stimmen (1 Konto, 4 Fotos, 2 Galerien, 2 Seiten, 4 Zuordnungen), Anmeldung mit Passwort **und** TOTP, Originale über `/admin/wiederherstellung` (1 Durchgang), SHA-256 aller 4 Originale, Veröffentlichen, Startseite, Galerien, 8 von 8 Bildadressen mit 200 und `image/*` | Einspielen der Datenbank 1,2 s, Originale 0,3 s, Veröffentlichen 3,7 s, **Probe gesamt 22 s** (Image-Bau beim ersten Mal 141 s, nicht eingerechnet) |
| **A** (ohne Anmeldedaten) | Einspielen, Zahlen, Originale (Ersatzweg im Container) und SHA-256 bestanden; Anmeldung, Varianten, Veröffentlichen und Bilder **übersprungen** und genannt | 10 s |
| **A**, ein Original absichtlich verändert (Gegenprobe) | erkannt: das Original wird nicht verschoben, „1 Originale warten“, SHA-256 „nur 3 von 4“ | 47 s |
| **C** (falscher Schlüssel) | abgelehnt mit Status 400 und „LK_APP_KEY passt nicht …“; Datenbank unverändert (0 Konten), keine Reste im Datenordner, Sicherung unangetastet, Originalordner leer, Einrichtung steht weiter offen | wenige Sekunden |

**Gefunden:**

1. **Kein Sammel-Knopf für die Bildvarianten (mittel, nicht behoben).** Nach dem Einspielen fehlen die Varianten in `media/`. Das Veröffentlichen läuft trotzdem, aber **kein Bild wird ausgeliefert** (Gegenprobe `--ohne-varianten`: 0 von 8 Bildadressen). Die Oberfläche hat nur den Aufruf je Foto (nach einem Upload). Die Probe ruft ihn je Foto auf. Für den Livegang auf all-inkl (kein SSH) braucht es einen Sammel-Knopf (zum Beispiel auf `/admin/wiederherstellung` oder in der Systemprüfung), der alle Fotos in kleinen Durchgängen verarbeitet; das ist mehr als ein kleiner Fehler und gehört in einen eigenen Auftrag. Bis dahin ist Probe B nicht ohne Eingriff machbar.
2. **Hinter dem Proxy-Umleiter** (`.htaccess`, HTTP→HTTPS): Anfragen aus dem Docker-Netz haben nicht die Adresse 127.0.0.1 und werden auf `https://` umgeleitet, wenn `X-Forwarded-Proto` fehlt. Das ist gewollt (so ist der Proxy davor), wurde aber ein Stolperstein für das Skript, das sich wie ein Proxy verhält (Kopf `X-Forwarded-Proto: https`, `LK_TRUST_PROXY=1`).
3. Die Prüfung mit echter Sicherung, mit der echten Uhr und mit einem Wiederherstellungscode steht aus (der Code wird nicht eingelöst).

**Noch offen:** Probe A und C mit der **echten** Sicherung der Testinstallation (Lutz zieht sie), Probe A optional auf pve10, **Probe B** auf all-inkl (nach Freigabe von Lutz und nach dem Sammel-Knopf für Varianten). Die automatischen Tests in `tests/haertung.php` und `tests/sicherung.php` prüfen den Ablauf ebenfalls, ersetzen die Proben auf echten Servern aber nicht.
