# Wiederherstellung einer Sicherung (auch auf all-inkl ohne SSH)

Stand: 19.11.2026 (Schritt 8a und 8b, Entwurf 20 Abschnitt 2.4, Prüfung 21 H2). Gilt für all-inkl (nur FTPS) und Docker.
Dieses Dokument beschreibt das **Erzeugen** einer Sicherung (Verwaltung → Sicherung, `/admin/sicherung`), ihr **Format** und das **Einspielen**.

## Sicherung herunterladen (nur Administratoren)

1. Verwaltung → **Sicherung**. Zuerst mit **Passwort und Code aus der App** bestätigen; danach sind die Downloads 10 Minuten freigeschaltet (Cookie, an die Sitzung gebunden). Wer nicht bestätigt hat, bekommt nichts, auch nicht mit der Adresse.
2. **Paket herunterladen** (`.tar`): Datenbank (aus `VACUUM INTO`, nie die laufende Datei), `MANIFEST.json`, `originals.sha256`, `files/`. Ohne Originale. Doppelklick entpackt es auf Mac, Windows und Linux zu dem Ordner aus der Tabelle unten.
3. **Originale** in Teilen herunterladen (je Teil ein `.tar`, Standard etwa 150 MB, `LK_BACKUP_PART_MB` 10 bis 500). Alle Teile **in denselben Ordner** entpacken: `originals/xx/…` kommt zum Paket dazu. Bricht ein Download ab oder kappt der Webserver ihn (all-inkl: 60 Sekunden Laufzeit), den Teil einfach noch einmal laden; die Seite führt über „nach Foto-Nummer“ weiter. Einzelne Originale mit Fortsetzen (HTTP-Range): `/admin/sicherung/original/<Foto-Nummer>`.
4. Fotos, deren Original als Datei fehlt oder deren Pfad ungültig ist, nennt die Seite; sie sind nicht in der Sicherung.
5. Jeder Abruf steht im Protokoll (`backup_confirmed`, `backup_package`, `backup_originals`, nur Zahlen), die Seite zeigt „Zuletzt heruntergeladen“ (gelb nach 3 Tagen). Es gibt Grenzen: höchstens 6 Pakete und 120 Original-Abrufe je 15 Minuten und Konto.
6. **Ablegen:** auf einem verschlüsselten Datenträger (FileVault/LUKS). Die Sicherung enthält alle Konten samt zweitem Faktor; mit dem Schlüssel `LK_APP_KEY` (getrennt im Passwort-Manager) wäre sie voll lesbar. Originale enthalten alle Aufnahmedaten, auch GPS.
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
7. **Fotos neu verarbeiten** („Medienvarianten neu erzeugen“, Bildpipeline) und **Veröffentlichen**. Weiterleitungen und `robots.txt` kommen mit der Veröffentlichung.
8. **`LK_SETUP_TOKEN` entfernen** (`./deploy.sh --token-entfernen`), Bereitstellungsordner leeren (nach Prüfung der Fotozahl), `tools/livepruefung.sh` laufen lassen.

## Sonderfälle

- **Schlüssel verloren:** Die Datenbank ist ohne den Schlüssel nicht einspielbar (Schutz gegen Aussperren). Dann: frische Einrichtung (neues Konto), Fotos/Galerien/Seiten sind in diesem Fall nur über die Importmöglichkeiten wiederzuholen. Deshalb den Schlüssel doppelt sichern (Passwort-Manager und Ausdruck). **Offen, mit Lutz zu entscheiden:** ein Einspielen „trotzdem mit anderem Schlüssel“ (Konten müssten dann alle neu eingerichtet werden) ist bewusst **nicht** gebaut.
- **Sicherung aus älterem Schema:** wird beim Öffnen migriert; vorher legt die App `…vor-schema-N.bak` an.
- **Sicherung aus neuerem Schema:** abgelehnt mit Hinweis auf die Version.
- **Einspielen auf einem Server mit Konten:** nicht möglich (nur bei der Ersteinrichtung). Wer eine laufende Installation ersetzen will, setzt sie vorher zurück (Datenordner leeren) und beginnt bei Schritt 2.
- **Docker:** gleiche Schritte; Bereitstellungsordner und Datenordner liegen im Volume `lk-data`, die Originale im Volume `lk-originals`.

## Wiederherstellungsprobe (Pflicht vor dem Livegang)

1. Sicherung der Testinstallation ziehen (Verwaltung → Sicherung: Paket und alle Teile der Originale in denselben Ordner entpacken).
2. **Probe A, Docker auf pve10:** frischer Container, Volumes leer, derselbe Schlüssel → Schritte 2 bis 7. Zeit stoppen. Prüfen: Anmeldung mit Passwort **und** TOTP, Wiederherstellungscode einlösbar, Fotozahl, Galerien, Seiten, Veröffentlichen, Bilder werden ausgeliefert, SHA-256 der Originale stimmen.
3. **Probe B, all-inkl in einer Wegwerf-Subdomain** (eigener Datenordner und Originalordner, nur nach Freigabe von Lutz; FTPS, kein SSH, 60 Sekunden Laufzeit, Rechte): derselbe Ablauf. Das erst belegt den Weg, den br-foto.com wirklich nutzt. Danach Subdomain samt Ordnern entfernen.
4. **Probe C mit falschem Schlüssel:** Das Einspielen muss mit der Meldung „LK_APP_KEY passt nicht“ scheitern und nichts verändern.
5. Ergebnis (Dauer, Probleme, Anpassungen) hier unten eintragen. **Ohne bestandene Probe A und B kein Livegang.**

### Ergebnisse der Proben

(noch keine; die automatischen Tests in `tests/haertung.php` und `tests/sicherung.php` prüfen den Ablauf (Sicherung erzeugen, entpacken, auf frischer Installation einspielen, Originale zurückholen, Prüfsummen vergleichen), ersetzen die Proben auf echten Servern aber nicht)
