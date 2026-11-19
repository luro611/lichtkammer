# 39 Prüfung Aufräumen 8i (Opus)

Stand: 19.11.2026. Geprüft: Commit `5703aed` (Bericht `38`). Maßstab: Entwurf `20` (Teil 8i), Prüfung `21`, `CLAUDE.md`, globale Regeln (Commit-Identität nur pro Repository, private Mail-Adresse nirgends, Kontakt `info@lurohome.de`). Eigene Prüfung, nicht auf Grund von Bericht 38. Geheimnisse und die echte Kundennummer stehen nirgends in diesem Bericht. Nichts gepusht, keine Historie umgeschrieben, nichts am Server oder an GitHub geändert (GitHub nur gelesen).

## Ergebnis in Kürze

- **Bericht 38 stimmt im Kern.** Kein echtes Geheimnis im Arbeitsbaum, in `main`, im Tag `v0.1.0-rc1` und in keinem Commit. Keine Rotation nötig.
- **Keine Befunde mit Risiko hoch oder mittel.** Fünf Befunde mit Risiko niedrig, zwei davon direkt behoben (P1, P2).
- **Empfehlung zur Historie (Abschnitt 6):** Bericht 38 empfiehlt Weg B (umschreiben und force-pushen). Besser ist **Weg B im neuen Repository**: bereinigte Historie in ein neues, leeres öffentliches Repo; das heutige Repo bleibt privat als Archiv. Das umgeht das Problem, dass GitHub alte Commits nach einem Force-Push noch über ihre Nummer ausliefert.
- Tests: **1 527 Prüfungen, 0 Fehler** (vorher 1 522, 5 neu).

## 1. Eigener Geheimnis-Scan

Umfang: alle Referenzen (`main`, `origin/main`, Tag `v0.1.0-rc1`; kein weiterer Zweig, kein Stash), 116 Commits, Arbeitsbaum samt nicht versionierter Dateien (außer `dist/`, `deploy/.env` nur nach Schlüsselnamen angesehen).

| Muster (`git grep`, `git log --all -G`, `git log --all -p`) | Ergebnis |
| --- | --- |
| `BEGIN … PRIVATE KEY`, AWS-, GitHub-, Slack-, OpenAI-Schlüssel | 0 |
| `LK_APP_KEY`, `LK_SETUP_TOKEN`, `LK_RESET_TOKEN`, `LK_BACKUP_TOKEN`, `*_PASSWORD`, `*_TOKEN` mit Wert ab 16 Zeichen | nur Testwerte: `TEST_PASSWORD`, `TEST_SETUP_TOKEN`, `TEST_RESET_TOKEN` in `tests/support.php` (erkennbare Muster wie `0123…`, `fedc…`) und `LK_APP_KEY` aus lauter `A` in `tests/einrichten.sh` |
| `otpauth://` | nur Code, der die Adresse zusammensetzt, ein Test mit Schlüssel `ABC`, und Text in Entwürfen; kein echter Schlüssel |
| FTP und KAS | nur Zeilen, die das Passwort aus dem Schlüsselbund lesen; `kasserver` nur als Nameserver- und Zertifikatsname (öffentlich) |
| private Gmail-Adresse | 0 in Dateien, Commit-Texten, Autor- und Committer-Feldern |
| Mail-Adressen insgesamt | nur noreply von `luro611`, `noreply@anthropic.com`, `info@lurohome.de`, Beispiele `a@b.de`, `info@beispiel.de` und die Rechner-Adresse (siehe 4) |
| Heimnetz-IPs | nur Netzbereiche (`10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`) und Beispiele (`10.0.0.5`, `172.18.0.1`, `203.0.113.x`); keine echte Adresse |
| Heimnetz-Namen (`pve10`, `pve01`, Homeserver) | in `docs/`, `CLAUDE.md`, `compose.yaml` (Kommentare), `tools/restore-probe.sh` (Kommentar), `CHANGELOG.md` („Homeserver“, in Bericht 38 nicht genannt) |
| `/Users/` | Arbeitsbaum nur `CLAUDE.md` (2 Zeilen) und Bericht 38 (als Wort); Historie 6 Commits |
| Kundennummer | Arbeitsbaum 0; Historie 9 Commits (die 8 aus Bericht 38 und `5703aed` selbst, dessen Diff die alten Zeilen als entfernt enthält) |
| Dateien, die je hinzugefügt und wieder gelöscht wurden | nur `app/templates/home.php`; nie `.env`, Datenbank, Schlüssel, Zip, Bild außerhalb des Baums |

**Bewertung der 16 gitleaks-Treffer:** Alle Fehlalarme. Es sind die drei Testkonstanten und die Formularfelder in `tests/auth_flow.php`, die genau diese Testkonstanten verwenden. Keiner der Werte gehört zu einem Server oder Konto. Eine `.gitleaks.toml` braucht es nicht; beim Öffentlichstellen meldet GitHub-Secret-Scanning diese Muster ohnehin nicht als Anbieter-Schlüssel.

## 2. Bilder und PDF

Eigene Stichprobe angesehen: `t_enroll.png`, `t_codes.png`, `t_setup_form.png`, `t_dashboard_token.png`, `t_protokoll.png`, `t_konten.png`, `t_import.png`, `t_oeffentlich_galerie.jpg`. Alle aus der Wegwerf-Instanz (Konto `beispiel`, „Beispielfoto“, Netz `127.0.0.0/24`, gezeichnete Beispielbilder statt echter Fotos). Codes `XXXXX-XXXXX`, Bestätigungscode `123456`, Token und Passwort als Punkte.

`exiftool` über alle Bilder unter `docs/anleitung/` und `public/assets/`: kein GPS, kein Autor, keine Kamera, keine Software-Angabe; PDF nur Titel und `WeasyPrint`. Text der PDF (`pdftotext`): keine Mail-Adresse, kein Pfad, keine Kundennummer, kein Rechnername.

- **P5 (niedrig, nicht behoben):** In `t_enroll.png` sind QR-Code und Schlüssel nur **weichgezeichnet**; beim Schlüssel lassen sich einzelne Zeichen erahnen. Es ist der TOTP-Schlüssel eines Wegwerf-Kontos einer längst gelöschten lokalen Instanz, also ohne Wert. Für künftige Bildschirmfotos besser eine volle Fläche statt Weichzeichnen (in `docs/anleitung/skripte/` beim nächsten Neuerzeugen umstellen).

## 3. Ersetzung der Kundennummer

- Alle Fundstellen im Baum nutzen jetzt `vXXXXXX` (bzw. `v…`, `vXXXX` in `.env.example` Zeile 60, gleichwertig).
- `deploy/einrichten.sh` und `deploy.sh` haben keine feste Nummer; sie lesen die Pfade aus `deploy/.env` bzw. verlangen sie. `deploy/env.beispiel` und `.env.example` sind nur Vorlagen. Kaputt geht dadurch nichts.
- `deploy/.env` (nicht versioniert) ist unverändert (Datum 15.11.2026) und enthält nur Domain, Host und Benutzer; das Passwort liegt im Schlüsselbund.
- Syntaxprüfung aller Skripte (`zsh -n` bzw. `bash -n`): in Ordnung. Tests mit Pfaden (`tests/run.php`) nutzen `v1` und sind unberührt.
- **P4 (niedrig, nicht behoben, nur Lesbarkeit):** In Prüfung 21 (Zeile 104) und 22 (N3) wurde die Nummer auch in Sätzen ersetzt, die gerade über die Nummer berichten („Vorgabe `vXXXXXX` entfernt“). Der Sinn bleibt erkennbar; ändern lohnt nicht.

## 4. `.mailmap`

- Format richtig (`Richtiger Name <richtige Adresse> <alte Adresse>`). `git check-mailmap` bildet ab; `git shortlog -sne --all` und `git log --format='%aN <%aE> | %cN <%cE>'` zeigen nur noch die noreply-Identität (116 Commits, Autor und Committer). Keine private Adresse.
- Die Datei nennt den Rechnernamen einmal. Das ist nicht vermeidbar (die Abbildung braucht die alte Adresse) und verrät **nichts Neues**, solange die alten drei Commits sichtbar sind. Sie steht nicht im Release-Zip, aber in jedem Quellpaket von GitHub (`git archive` des Tags).
- **P3 (niedrig):** Bericht 38 nennt den Rechnernamen nur als Autor der drei Commits. Er steht außerdem im **Text** von Prüfung 21 im Commit `cd8674e` und jetzt in `.mailmap` (`5703aed`). Beim Umschreiben (Abschnitt 6) muss er also auch per Textersetzung weg, nicht nur per Autorenabbildung; danach `.mailmap` löschen.

## 5. Release-Zip, `.gitignore`, `.dockerignore`

- `tools/release-zip.sh --pruefen`: bestanden, 183 Einträge, nur `app/`, `public/`, `.env.example`, `LICENSE`, `NOTICE`, `README.md`, `INSTALL.txt`, `VERSION`. Eigene Suche im entpackten Zip: keine Kundennummer, keine Gmail, kein Rechnername, kein `/Users/`, kein `pve10`/`pve01`. „Lutz“ steht fünfmal in Kommentaren (bekannt, Bericht 38 Liste Nr. 7).
- Auch der Inhalt des schon veröffentlichten Vorab-Release `v0.1.0-rc1` (aus dem Tag nachgebaut) ist sauber.
- `.gitignore` deckt `deploy/.env`, `.env*`, Schlüssel, Datenbanken, `/data/`, `/originals/`, `public/media/*`, `/dist/`, `.DS_Store` ab; geprüft mit `git check-ignore`.
- **P1 (niedrig, behoben):** `dist/` fehlte in `.dockerignore`. Ins Image kam es nie (das Dockerfile kopiert nur `app`, `public`, `docker/`, `LICENSE`, `NOTICE`), aber die Zips gingen bei jedem Build in den Build-Kontext. `dist` ergänzt, Test dazu.
- **P2 (niedrig, behoben):** `--pruefen` prüfte nur Dateinamen, nicht den Inhalt. Neu: Muster `PRIVAT_MUSTER` (lokale Mac-Pfade, Gmail, Adresse mit `.local>`, privater Schlüssel, echte all-inkl-Kundennummer im Pfad, TOTP-Adresse mit festem Schlüssel; Platzhalter `vXXXXXX` und Beispiele gehen durch). Das Muster enthält keine echte Nummer. Läuft auch im Release-Workflow mit. Tests in `tests/sicherung.php`: Muster erkennt die Spuren, lässt Platzhalter durch, und der heutige Paketinhalt ist sauber.

## 6. Historie: Bewertung von Bericht 38, Abschnitt 7

Lage, gelesen bei GitHub: Repo privat, **0 Forks, 0 Pull-Requests**, ein Vorab-Release `Lichtkammer 0.1.0-rc1` am Tag `v0.1.0-rc1` (65 Commits, davon 4 mit der Kundennummer), dazu das Image `ghcr.io/luro611/lichtkammer:0.1.0-rc1`. In der Historie stehen keine Geheimnisse, nur Kennungen: Rechnername, Kundennummer, lokale Pfade, Heimnetz-Namen, die internen Planungsdokumente.

Was Bericht 38 richtig sagt: Jetzt nichts tun (Repo privat), Entscheidung **vor** dem Öffentlichstellen. Was fehlt:

1. **GitHub vergisst nach einem Force-Push nicht sofort.** Alte Commits bleiben über ihre Nummer abrufbar (Weboberfläche `…/commit/<nummer>` und API), bis GitHub aufräumt; das kann dauern und lässt sich nur über den GitHub-Support beschleunigen. Wird das Repo nach Weg B (Umschreiben im selben Repo) öffentlich, ist jeder alte Commit für jeden lesbar, der seine Nummer kennt, und die Nummern stehen in unseren eigenen Berichten (`docs/planung/*`). Damit wäre Weg B im selben Repo nur halb sauber.
2. **Pull-Requests und Forks:** Es gibt keine. Das ist der günstigste Zeitpunkt; `refs/pull/*` lassen sich bei GitHub nicht umschreiben, und jeder Fork behält die alte Historie.
3. **Tag `v0.1.0-rc1`:** Beim Umschreiben zeigt er auf einen alten Commit. Neu setzen auf den umgeschriebenen Commit ist möglich, aber das Vorab-Release und das Image `0.1.0-rc1` gehören dann zu Commits, die es öffentlich nicht gibt (Kennzeichnung `revision` im Image).
4. **Berichte mit Commit-Nummern** (`docs/planung/*`, `CLAUDE.md`) passen nach jedem Umschreiben nicht mehr.

| | A behalten | B im selben Repo umschreiben | **B' bereinigt in ein neues Repo** | C neu beginnen (ein Commit) |
| --- | --- | --- | --- | --- |
| Kennungen öffentlich | ja | über alte Nummern noch abrufbar, bis GitHub aufräumt | **nein**, das neue Repo hat die alten Objekte nie gesehen | nein |
| Verlauf und `git blame` öffentlich | ja | ja | ja | nein |
| interne Planung | öffentlich | wählbar | wählbar (mit `filter-repo` herausnehmen) | wählbar |
| Hashes in den Berichten | stimmen | stimmen nicht mehr | stimmen im **privaten** Archiv weiter | stimmen im privaten Archiv |
| Force-Push nötig | nein | ja | nein | nein |
| Aufwand | keiner | mittel | mittel | klein |

**Empfehlung für Lutz: Weg B'.** Kurz vor dem öffentlichen Release:

1. Heutiges Repo umbenennen, z. B. in `lichtkammer-intern`, und **privat lassen** (Archiv mit Planung, allen Hashes, Release `0.1.0-rc1`, Image).
2. Frischen Klon mit `git filter-repo` bereinigen: Autor der drei frühen Commits auf die noreply-Identität, Textersetzung für Kundennummer, Rechnername (auch in `.mailmap` und Prüfung 21) und `/Users/luro`; `CLAUDE.md` und `docs/planung/` herausnehmen (oder nur ausgewählte, bereinigte Entwürfe behalten); `pve10`/`pve01` in `docs/betrieb/`, `compose.yaml`, `tools/restore-probe.sh` vorher im Baum neutral benennen; danach `.mailmap` löschen.
3. Prüfen: Tests, `tools/release-zip.sh --pruefen`, eigene Suche wie in Abschnitt 1 über die **ganze** neue Historie, Dateibaum mit dem alten Stand vergleichen.
4. Ein **neues, leeres** Repo `luro611/lichtkammer` anlegen und erst nach Freigabe hinein pushen. Den alten Tag `v0.1.0-rc1` **nicht** mitnehmen; das erste öffentliche Release bekommt einen neuen Tag (nach Plan `0.2.0` beim Livegang bzw. `1.0.0` beim öffentlichen Release) und ein neu gebautes Image. Die Image-Version `0.1.0-rc1` auf ghcr privat lassen oder löschen.

Weg C ist nur besser, wenn der Entwicklungsverlauf öffentlich keinen Wert hat; Weg A nur, wenn die Kennungen Lutz egal sind (Risiko niedrig, aber nicht mehr zurückzuholen). Nach dem Öffentlichstellen ist keiner der Wege mehr möglich.

## 7. Befunde

| Nr. | Befund | Risiko | Stand |
| --- | --- | --- | --- |
| P1 | `dist/` fehlte in `.dockerignore` | niedrig | behoben, Test |
| P2 | `release-zip.sh --pruefen` prüfte den Inhalt nicht auf private Spuren | niedrig | behoben (`PRIVAT_MUSTER`), 4 Tests |
| P3 | Rechnername steht auch im Text (`cd8674e`, `.mailmap`), nicht nur als Autor | niedrig | offen, beim Umschreiben per Textersetzung |
| P4 | ersetzte Nummer in Sätzen über die Nummer (Prüfung 21, 22) | niedrig | bleibt |
| P5 | TOTP-Schlüssel in `t_enroll.png` nur weichgezeichnet (Wegwerf-Konto) | niedrig | offen, beim nächsten Neuerzeugen volle Fläche |
| H1 | Empfehlung Historie: Weg B' (neues Repo) statt B (Force-Push) | Hinweis | Entscheidung von Lutz |
| H2 | `CHANGELOG.md` nennt „Homeserver“, fehlt in Liste 6 von Bericht 38 | Hinweis | zur Liste vor dem Release |
