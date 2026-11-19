# Prüfung: Entwurf „Update im Admin“ (Entwurf 15, Abschnitt 10)

Stand: 19.11.2026. Prüfer: Opus. Geprüft: `docs/planung/15 Entwurf Distribution.md`, Abschnitt 10 (Commit `e328259`), gegen den Code am selben Stand: `app/src/Config.php`, `Auth/Database.php`, `Auth/Schema.php`, `Auth/Keys.php`, `Auth/Auth.php` (Bestätigung mit Passwort und Code), `Auth/Audit.php`, `Site/Builder.php`, `Site/Server.php`, `App.php`, `public/index.php`, `public/.htaccess`, `deploy.sh`, `deploy/einrichten.sh`, `tools/release-zip.sh`, das echte Paket `dist/lichtkammer-0.1.0-rc1.zip`, `Dockerfile`, `docs/betrieb/Wiederherstellung.md`, Entwurf 20.
Nichts gebaut, nichts hochgeladen, am Server nichts geändert.

## Ergebnis in einem Satz

Der Entwurf ist gründlich und in der Richtung richtig (Signatur vor dem Entpacken, Schritte statt einem langen Aufruf, Rückweg, Docker ausgenommen), hat aber **zwei sachliche Fehler am echten Aufbau** (die `.env` liegt in `app/`, die Besucherseite braucht PHP) und mehrere Lücken, die zu Aussperrung oder Ausfall führen würden. **Empfehlung: bauen mit Bedingungen** (unten). Die Ergänzungen stehen im Entwurf als Abschnitt 10.10.

## Entscheidungen eingetragen

Die vier Fragen aus 10.9 sind im Entwurf als freigegeben (Lutz, 19.11.2026) eingetragen: F1 Schlüssel nur auf dem Mac mit verschlüsselter Sicherung, Signieren von Hand; F2 automatisch neu veröffentlichen, bei Fehler Hinweisband; F3 bauen nach Livegang und U0, Stufe 1 vor dem ersten öffentlichen Release, Stufe 2 später auf Wunsch; F4 Datenbank-Sicherung nach Migration nur anbieten, vorher warnen. Zwei Ergänzungen berühren diese Entscheidungen, ohne sie zu ändern: V1 (zu F2) und Frage W (Wurzelschlüssel, zu F1).

## Befunde

| Nr. | Risiko | Befund | Im Entwurf |
|---|---|---|---|
| P1 | **hoch** | **`app/.env` geht beim Tausch verloren.** Auf all-inkl liest `Config::load` nur `app/.env`; `deploy/einrichten.sh` legt sie dort ab. Der Entwurf benennt `app/` als Ganzes in `app.alt` um und legt den neuen Ordner hin, der keine `.env` hat (sie ist im Paket verboten). Folge: Admin startet nicht mehr, kein Weg zurück über die Oberfläche. Eindeutiger Fehler. | korrigiert in 10.1, T1 |
| P2 | **hoch** | **Die öffentliche Seite hängt an PHP.** Die fertigen Seiten liegen unter `<Datenordner>/site/releases/` und werden über `index.php` → `App` → `Site/Server` ausgeliefert, nicht direkt von Apache. Die Aussage „öffentliche Seite bleibt erreichbar, braucht PHP nur für Admin“ ist falsch. Ein kaputter neuer Code nimmt **auch br-foto.com offline**, und der Rückweg-Knopf liegt im kaputten Code. | korrigiert in 10.1 und 10.3; A2, A3 |
| P3 | **hoch** | **Mischcode in einer Anfrage.** Der eigene Autoloader lädt Klassen bei Bedarf. Läuft nach dem Umbenennen von `app/` in derselben Anfrage noch Update-Code, kommen neue Klassen zu schon geladenen alten. Auch „Migration per Anfrage an sich selbst“ ist auf Webspace unsicher (Loopback, Host-Kopf). | T5 |
| P4 | **hoch** | **Signieren von Hand schützt nur, wenn nicht blind signiert wird.** Der Workflow baut das Zip auf GitHub; signiert Lutz dessen Manifest ungeprüft, genügt ein gekapertes GitHub-Konto doch. Außerdem fehlt, wie die Signatur ins Zip kommt (Zip aus CI ≠ signiertes Zip). | S1 |
| P5 | **hoch** | **Neue Pflicht-Einstellungen sperren aus.** Schritt 8a hat `LK_ORIGINALS_DIR` zur Pflicht gemacht; ein solches Update per Klick würde danach mit der Konfig-Fehlerseite enden, Admin weg. Der Entwurf prüft nur PHP-Version. | A1 |
| P6 | mittel | **Bestätigung:** Der Code hat `Auth::reauthenticate()` mit Passwort **und** Code; der Entwurf nennt nur den Code. Der Wiederholschutz ist kontoweit (`totp_last_step`), nicht „je Sitzung“ wie im Entwurf. Sitzungen von „vertrauten Geräten“ sind ohne zweiten Faktor entstanden und müssen den Code trotzdem eingeben. | R1 |
| P7 | mittel | **Zustandsdatei und TOCTOU.** Zwischen Prüfen, Entpacken und Tauschen liegen getrennte Anfragen. Stehen Pfade oder Dateilisten in `state.json`, wird eine Schreiblücke im Datenordner zur Schreiblücke im Code. Vor dem Tausch muss neu gehasht werden. | Z1, Z2 |
| P8 | mittel | **`app.alt`, `*.neu`, `*.alt` im Webroot sind nicht gesperrt.** Die `.htaccess`-Regel `^(app|data|…)(/|$)` trifft `app.alt` nicht; PHP-Dateien darin wären aufrufbar, `index.php.alt` würde als Text ausgeliefert. Liegt der Datenordner im Webroot, wären auch vorbereitete PHP-Dateien aufrufbar. | T2 |
| P9 | mittel (hoch, wenn U0 Fremdzeilen findet) | **`.htaccess` überschreiben** löscht Zeilen von Hoster oder Betreiber (z. B. Wahl der PHP-Version). „Mit Sorgfalt“ reicht als Vorgabe nicht. Hinweis: `deploy.sh` überschreibt sie heute ebenfalls; für Lutz unkritisch, für Fremde nicht. | T3 |
| P10 | mittel | **Entfernte Dateien bleiben liegen**, und es gibt keine Erkennung, ob installierter Code verändert wurde. | T4 |
| P11 | mittel | **Wartungsmodus ohne Ablauf** sperrt bei Abbruch dauerhaft aus; er darf die Besucherseite nicht sperren (P2) und muss die Sperre des Builders mitnehmen. | A4 |
| P12 | mittel | **Paketformat passt nicht zu `tools/release-zip.sh`:** Wurzelordner `lichtkammer-<Version>/`, dazu `README.md`, `INSTALL.txt`, `.env.example`; kein `CHANGELOG.md` (die Skizze zeigt aber „Änderungen aus CHANGELOG im Zip“). | S4 |
| P13 | mittel | **`min_allowed_from` vermischt zwei Dinge.** Ein altes, echt signiertes Paket kann sich nicht selbst sperren. Zudem senkt ein Rückspielen der Datenbank `meta.max_version_seen`. | S5 |
| P14 | mittel | **Zip-Prüfung unvollständig:** Größenangaben im Zip nicht vertrauen, nie `extractTo`, Namen normalisieren, Groß/Klein-Dubletten, verschlüsselte Einträge; `.php` nur an bekannten Stellen. Die Endungsliste im Entwurf würde das echte Paket ablehnen (Schriften sind `.ttf`, nicht `.woff2`/`.otf`). | S6 (korrigiert) |
| P15 | mittel | **Ein einziger Schlüssel:** Diebstahl oder Verlust ist nur per FTP bei jeder Installation zu beheben, und ein gestohlener Schlüssel kann per Update die Schlüsselliste ändern. Muss vor dem ersten öffentlichen Release entschieden sein, weil jede Installation die Liste fest enthält. | S7, Frage W |
| P16 | mittel | **Audit:** `Audit::EVENTS` ist fest, unbekannte Ereignisse werfen eine Ausnahme. Ein Rückspielen der Datenbank löscht die Update-Einträge. | R3 |
| P17 | mittel | **Datenbank-Sicherung:** `….vor-schema-N.bak` wird nur angelegt, wenn es sie nicht gibt, und wäre nach einem früheren Versuch veraltet. Das Rückspielen in eine laufende Installation gibt es bisher bewusst nicht (`Wiederherstellung.md`); es ist eine neue sicherheitsrelevante Funktion. | D1, D2, D3 |
| P18 | mittel | **Automatisch veröffentlichen (F2)** nimmt seit Entwurf 28 auch Änderungen mit, die mit „Nur speichern (noch nicht online)“ bewusst zurückgehalten wurden. | V1 |
| P19 | niedrig | **Docker-Erkennung:** Das `Dockerfile` setzt heute keine Variable, an der man das Image erkennt; Entwicklungsstände (`.git`) müssen ebenso ausgenommen sein. Abschalter `LK_UPDATE=0` fehlt. | R2 |
| P20 | niedrig | **Stufe 2:** Die erlaubten Hosts für die Umleitung des Downloads stimmen nicht mehr sicher; GitHub begrenzt Abrufe ohne Anmeldung je IP (geteilte Webspace-Adressen). Datenschutzhinweis (IP an GitHub) und „standardmäßig aus“ sind richtig. Keine SSRF-Lücke, solange die Adresse fest im Code steht. | Stufe 2 in 10.10 |
| P21 | niedrig | **Kleinigkeiten:** Zip ist heute 1,7 MB (nicht 3 bis 6), entpackt 3,6 MB; Platzschätzung passt trotzdem. Text aus dem Paket im Admin nur als Klartext ausgeben (CSP `script-src 'self'` ist da). | – |

## Was zusammenpasst

- **Migration:** stimmt mit dem Code (`Database::open` sichert per `VACUUM INTO` und migriert; jede Migration in eigener Transaktion, „anderer Prozess war schneller“ abgefangen).
- **Veröffentlichen:** Sperre, `building-*`, Umschalten per `rename` und 20 Fassungen wie beschrieben; Fehler lassen die alte Fassung online.
- **Entwurf 20 / `LK_APP_KEY`:** Das Update braucht keinen neuen HKDF-Kontext (der Signaturschlüssel ist unabhängig von `LK_APP_KEY`), es fasst `.env` nur zum Übernehmen an (T1). Originale, `media/`, `restore/` sind tabu.
- **Sicherungs- und Wiederherstellungsformat (`docs/betrieb/Wiederherstellung.md`) bleibt unberührt.** Die Update-Sicherung ist eine eigene Datei im Update-Ordner, nicht das Format aus 8b. Neu ist nur das Rückspielen im laufenden Update-Vorgang (D2); es steht neben dem Einspielen bei der Ersteinrichtung und ändert dessen Regeln nicht.
- **Abschnitt 3 bis 5 / Bauplan 9:** passt, wenn `release-zip.sh` das Manifest gleich mit erzeugt und reproduzierbar baut (S1, S4). Das gehört in Schritt 1 von Abschnitt 9, also **bevor** der Release-Workflow gebaut wird, sonst wird er zweimal angefasst.
- **Signaturgrundlage** (ed25519 mit libsodium, Signatur vor jedem Entpacken, Manifest mit Prüfsumme je Datei, nichts darf fehlen oder dazukommen) ist richtig gewählt.

## Empfehlung: bauen mit Bedingungen

Bauen erst nach dem Livegang und nach U0 (F3). Bedingungen:

1. **Vor U1:** Frage W (Wurzelschlüssel) entschieden; Manifestfelder nach S3 festgelegt; `release-zip.sh` erzeugt reproduzierbar Zip und Manifest (S1, S4).
2. **U0 mit den Erweiterungen aus 10.10** (Umbenennen über Ordnergrenzen, `flock`, `set_time_limit`, Abrufbarkeit eines Geschwisterordners, Fremdzeilen in der `.htaccess` nur lesen). Fällt U0 negativ aus, entfällt Stufe 1 auf diesem Webspace.
3. **U3 enthält T1 bis T6, A1 bis A4, Z1, Z2, D1 bis D3** – vor allem `.env`-Übernahme, Selbstheilung in `index.php` und Vorabprüfung. Ohne diese Punkte kein Probelauf auf dem Testserver.
4. **Tests mit absichtlich kaputten Paketen** (falsche Signatur, Manifest manipuliert, zusätzliche Datei, Zip-Slip, Symlink, Zip-Bombe mit gelogenen Größen, Groß/Klein-Dublette, ältere Version, `revoke_below`, fehlende Pflicht-Einstellung, Syntaxfehler in PHP, Abbruch nach jeder Phase).
5. **Probelauf auf `lichtkammer.br-foto.com`** mit Update 0.x → 0.x+1, Abbruch mitten im Tausch und Selbstheilung, danach der FTP-Rettungsweg (A3) einmal von Hand durchgespielt. Erst nach Freigabe von Lutz.
6. **Fable-Prüfung vor dem ersten Release ist Pflicht** (wie im Entwurf).

Aufwand: eher 9 bis 11 Arbeitstage statt 7 bis 9 (Selbstheilung, `.htaccess`-Block, Wurzelschlüssel, Rückspielen der Datenbank).

## Was Fable besonders prüfen soll

1. **Reihenfolge Prüfen → Signatur → JSON → Entpacken:** Wird vor der Signaturprüfung irgendetwas aus dem Zip außer den zwei Manifestdateien gelesen? Grenzen beim Lesen dieser zwei Einträge.
2. **Signatur:** genaue Bytes, Vorsilbe, Auswahl des Schlüssels über `key_id` nur aus der festen Liste, Wurzelsignatur bei geänderter Schlüsselliste, kein Zurückfallen auf „ohne Signatur“.
3. **Entpacken:** Pfadprüfung vor dem Schreiben (nicht erst `realpath` danach), gezählte Bytes, Rechte, Symlinks, `.php` nur an erlaubten Stellen.
4. **Zustand über mehrere Anfragen:** keine Pfade aus `state.json`, Neuprüfung direkt vor dem Tausch, Verhalten bei zwei parallelen Update-Vorgängen.
5. **Tausch und Selbstheilung in `index.php`:** `.env`-Übernahme, keine Lücke, in der weder alter noch neuer Code liegt, Verhalten bei Abbruch an jeder Stelle, kein Weg, über `pending.json` beliebige Ordner umzuschalten.
6. **Bestätigung und Rechte:** Passwort und Code, kontoweiter Wiederholschutz, nur Rolle Administrator vor dem Lesen des Uploads, `LK_UPDATE=0`, Docker/Entwicklung sicher ausgenommen.
7. **Rückspielen der Datenbank (D2):** nur die Sicherung des laufenden Vorgangs, Sitzungen verworfen, kein Weg, damit gesperrte Konten oder alte Passwörter gezielt zurückzuholen, Audit danach.
8. **Herabstufung:** `max_version_seen` und `revoke_below` aus Datenbank **und** Datei, Versionsvergleich mit Vorabversionen (`-rc1`, `-dev`).

## Offene Frage an Lutz

**W – Wurzelschlüssel:** Soll es neben dem Release-Schlüssel auf dem Mac einen zweiten, offline aufbewahrten Wurzelschlüssel geben, der nur nötig ist, wenn sich die Liste der Schlüssel ändert? Empfehlung: ja, vor dem ersten öffentlichen Release. Ohne ihn hilft bei Verlust oder Diebstahl des Mac-Schlüssels nur ein FTP-Update bei jeder einzelnen Installation.
