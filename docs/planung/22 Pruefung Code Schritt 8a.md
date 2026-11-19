# Prüfung Code Schritt 8a (Opus)

Stand: 18.11.2026. Geprüft: `cd8674e..5c2d184` (5 Commits: H1/E19, H2/E12, M1 bis M4, M5/E3/E4, CLAUDE.md). Maßstab: Entwurf `20` und Prüfung `21`.
Tests vorher: 1 186 Prüfungen grün. Nach den Nachbesserungen: **1 191 Prüfungen, 0 Fehler** (`docker run --rm -v "$PWD":/app -w /app lichtkammer-test php tests/run.php`).
Nichts hochgeladen, nichts am Server, an DNS oder WordPress geändert.

## Ergebnis in Kürze

Schritt 8a ist sorgfältig gebaut. Der Grundsatz „nie überschreiben, erst prüfen, dann löschen“ ist in beiden Umzugswegen (alte Originale, Originale aus einer Sicherung) eingehalten, Pfade aus der Sicherung sind streng gefiltert, das Einspielen ist an Einrichtungs-Token, „keine Konten“ und den richtigen Schlüssel gebunden. Gefunden habe ich **eine Lücke mit Risiko mittel** (halbe Datei bei Abbruch über Dateisystem-Grenzen, B1/B2) und **eine Randfalle** (B3). Beide sind behoben und mit Tests abgesichert. Größere Punkte gibt es nicht zurückzugeben; die offenen Hinweise unten sind niedrig.

**Für den Testserver (all-inkl):** Originale und Webroot liegen dort auf demselben Dateisystem; der Umzug ist ein einziges Umbenennen. Die Lücke B1 hätte dort praktisch nicht gegriffen, wohl aber in Docker (Bereitstellungsordner im Volume `lk-data`, Originale im Volume `lk-originals`).

## Befunde

### B1 – Abbruch beim Kopieren hinterlässt eine halbe Datei unter dem richtigen Namen · Risiko **mittel** · **behoben**

- **Wo:** `OriginalsMover::moveOne` und `Restore::moveOriginals`.
- **Was:** Beide riefen zuerst `rename()` auf. Liegen Quelle und Ziel auf verschiedenen Dateisystemen, kopiert PHP in `rename()` selbst, **ohne Prüfung**, direkt unter den endgültigen Namen und löscht erst danach die Quelle. Wird der Prozess dabei beendet (Zeitgrenze des Webservers, Neustart des Containers), liegt am Ziel eine abgeschnittene Datei. Der eigene Kopierweg mit Prüfsumme dahinter wurde in diesem Fall nie erreicht. Außerdem blieb im eigenen Kopierweg eine leere Zieldatei liegen, wenn das Öffnen der Quelle scheiterte.
- **Folge:** Beim alten Umzug: dauerhafter „Konflikt“, die Quelle bleibt (kein Verlust, aber festgefahren). Beim Zurückholen aus der Sicherung schlimmer, siehe B2.
- **Wiederholbarkeit:** nur bei getrennten Dateisystemen und Abbruch mitten in einer Datei; in Docker realistisch, auf all-inkl kaum.
- **Behebung:** neue Klasse `app/src/Media/SafeMove.php`. Gleiches Dateisystem (per `stat()['dev']`): `rename()` wie bisher, atomar. Sonst: Kopie in eine Teildatei `<Ziel>.lk-teil` (Rechte 0600), SHA-256 prüfen, Teildatei umbenennen, erst dann die Quelle löschen. Ist das Ziel inzwischen belegt, wird nichts überschrieben. Reste eines Abbruchs ersetzt der nächste Durchgang. Beide Umzugswege nutzen jetzt diese Klasse.
- **Tests:** Kopierweg mit altem Rest einer Teildatei, falsche Prüfsumme (nichts am Ziel, Quelle bleibt), vorhandenes Ziel auf beiden Wegen nie überschrieben.

### B2 – Abweichende Datei am Ziel wurde beim Zurückholen still übergangen · Risiko **mittel** · **behoben**

- **Wo:** `Restore::moveOriginals`, Zweig „schon da“.
- **Was:** Lag am Ziel schon eine Datei mit dem Namen, aber anderer Prüfsumme (z. B. die halbe Kopie aus B1), wurde sie weder als Fehler gezählt noch gemeldet. `pendingOriginals()` sah die Datei als erledigt, das Warnband verschwand, die Seite meldete „Zu allen Fotos gibt es ein Original“. Hätte Lutz danach wie in `Wiederherstellung.md` beschrieben den Bereitstellungsordner geleert, wäre das einzige gute Exemplar weg gewesen.
- **Behebung:** Weicht das Ziel von der Liste ab und liegt die Quelle noch da, zählt das als „falsche Prüfsumme oder Konflikt“ (wird angezeigt und protokolliert); die Quelle bleibt liegen. Überschrieben wird weiterhin nie.
- **Test:** vorhandener Test erweitert: abweichende Datei am Ziel → `bad = 1`, Quelle bleibt.
- **Hinweis für Lutz:** Bereitstellungsordner erst leeren, wenn die Seite „0 warten, 0 mit falscher Prüfsumme“ zeigt.

### B3 – `LK_ORIGINALS_DIR` innerhalb des alten Ordners · Risiko **niedrig** · **behoben**

- **Was:** Mit `LK_ALLOW_DATA_IN_WEBROOT=1` und z. B. `LK_ORIGINALS_DIR=<Webroot>/originals/neu` hätte der Umzug Dateien aus `originals/` nach `originals/neu/` verschoben und beim nächsten Lauf von dort nach `originals/neu/neu/` usw. Kein Verlust, aber die Datenbank fände die Originale nicht mehr.
- **Behebung:** `OriginalsMover::legacyDir()` meldet keinen Umzug, wenn einer der beiden Ordner im anderen liegt (Pfade aufgelöst, auch wenn der Zielordner noch nicht existiert). Test ergänzt.

### N1 – Reihenfolge Hochladen / `.env` in zwei Anleitungen verschieden · niedrig · **behoben**

`Originale-verschieben.md` sagte „erst `.env`, dann Code“, `Umstellung-Server.md` „erst Code, dann `.env`“. Richtig ist „erst Code“: Der alte Code würde mit der neuen Zeile den leeren Ordner benutzen. Angeglichen; dazu der Hinweis, dass ein späteres Ändern von `LK_ORIGINALS_DIR` nicht automatisch umzieht (die App zieht nur aus `<Webroot>/originals` um). `--env-setzen` erlaubt diese Änderung weiterhin; das ist gewollt, nur eben ohne automatischen Umzug.

### N2 – Kommentar an der falschen Methode · niedrig · **behoben**

In `Request.php` stand der Kommentar zu `remoteAddr()` über `host()`. Verschoben.

## Offene Hinweise (niedrig, nicht behoben)

- **N3 – Kundennummer in der Doku:** Aus `deploy/einrichten.sh` ist die Vorgabe `vXXXXXX` entfernt (gut). In `Erster-Start-Testserver.md`, `04` und `20` steht sie noch. Kein Geheimnis, das Repo ist privat; vor dem öffentlichen Release durch `vXXXXXX` ersetzen.
- **N4 – `nosniff` doppelt:** `Header always set X-Content-Type-Options` in der `.htaccess` kommt zu dem Kopf hinzu, den PHP schon setzt. Browser kommen mit dem doppelten Wert zurecht. Wer es sauber will: `Header always setifempty …` (Apache 2.4.7+). Beim Livegang mit `curl -I` ansehen.
- **N5 – Reste im Bereitstellungsordner:** Nach dem Einspielen löscht die App nur `lichtkammer.sqlite`. `files/`, `MANIFEST.json` und Originale mit falscher Prüfsumme bleiben im (gesperrten) Ordner im Webroot, bis Lutz ihn per FTPS leert. So steht es auch in `Wiederherstellung.md` Schritt 8. Ein Knopf „Bereitstellungsordner leeren“ wäre eine Erleichterung für 8b.
- **N6 – Schlüsselprüfung beim Einspielen:** `validate()` prüft `LK_APP_KEY` nur an TOTP-Geheimnissen. Eine Sicherung ganz ohne eingerichteten zweiten Faktor würde mit falschem Schlüssel eingespielt. Da jedes Konto TOTP braucht, kommt das praktisch nicht vor.
- **N7 – Warnband zählt bei jedem Admin-Aufruf:** `pendingOriginals()` liest die Prüfsummenliste und prüft je Eintrag zwei Dateien. Bei einigen Tausend Originalen sind das Millisekunden; erst bei sehr großen Beständen spürbar.

## Geprüft und in Ordnung

- **Umzug der Originale (H1):** Sperre gegen gleichzeitige Durchgänge (`flock` im Datenordner); Schnellweg per Umbenennen des ganzen Ordners nur, wenn das Ziel nicht existiert (`rename()` ersetzt nie einen nicht leeren Ordner); Datei für Datei mit „gleicher Inhalt → Quelle weg, anderer → Konflikt“; Symlinks werden weder verfolgt noch verschoben; Zeitgrenze 25 s zwischen Dateien; leere Reste werden erst entfernt, wenn keine Datei mehr übrig ist. Die Datenbank wird nicht angefasst. Startprüfung blockiert den Admin vor dem Umzug, wenn der Zielordner im Webroot läge.
- **Auslieferung:** Es gibt keinen Weg, über den die App Originale ausliefert (auch nicht über `../`-Pfade, Test vorhanden). Der alte Ordner bleibt in der `.htaccess` gesperrt. Ein Abholweg für Administratoren kommt erst mit 8b und braucht dann die Fable-Prüfung.
- **Einspielen (H2):** Kein Zip, also kein Zip-Slip. Pfade aus `originals.sha256` nur nach `xx/<24 hex>.<ext>`; Dateien aus `files/` nur mit sicheren Zeichen, ohne `..` und ohne versteckte Namen; Symlinks werden übersprungen (`RecursiveDirectoryIterator` folgt ihnen nicht). Nur mit `setupOpen()`, CSRF, Ratenbremse, Token-Vergleich mit `hash_equals` und erneuter Prüfung „keine Konten“. Die Datenbank wird erst als Kopie geprüft (Integrität, Tabellen, Schema nicht neuer als der Code, Schlüssel), die leere Datenbank wird beiseitegelegt statt gelöscht, Sitzungen, vertraute Geräte und offene Einladungen werden verworfen (M6 aus `21`). Schritt 2 nur für Administratoren, mit CSRF und Protokolleintrag.
- **Konfiguration und `.htaccess` (all-inkl, nur FTPS):** `restore/` gesperrt (Regel und eigene `.htaccess`), `.bak/.sql/.zip/.md` gesperrt (die Medienverwaltung kennt nur PDF, SVG und Bilder, also kein Konflikt), HTTP→HTTPS nur bei der ersten Anfrage mit Ausnahmen für localhost, `/health` und `.well-known`. Host-Umleitung nur für eingetragene Hosts, Ziel immer `LK_BASE_URL` (kein offener Umleiter), Steuerzeichen werden aus der Adresse entfernt. `--env-setzen` lässt nur eine feste Liste ohne Schlüssel und Token zu, prüft Werte, liest die Schlüsselzeile nie aus und lädt nur hoch, wenn sie danach noch da ist.
- **Geheimnisse:** Keine Schlüssel, Token oder Passwörter im Diff; Testschlüssel nur in den Tests.

## Nächste Schritte

- Teil 8a kann aus meiner Sicht auf den Testserver, nach Freigabe von Lutz und in der Reihenfolge aus `Umstellung-Server.md` (erst Code, dann `LK_ORIGINALS_DIR`).
- Vor dem Livegang wie geplant: Fable-Prüfung von Einspielen und Auslieferung zusammen mit 8b (Sicherungsweg).
