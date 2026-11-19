# 38 Aufräumen 8i: Geheimnisse, Bilder, Namen und Historie

Stand: 19.11.2026 (Sonnet). Auftrag: Entwurf 20 und Prüfung 21, Teil 8i. Nur zerstörungsfreie Arbeiten; es wurde nichts umgeschrieben, nichts gepusht, nichts am Server oder an GitHub geändert. Geheimnisse selbst stehen nirgends in diesem Bericht.

## Kurzfassung

- **Keine echten Geheimnisse** im Arbeitsbaum und in der ganzen Historie (115 Commits, alle Zweige, Tag `v0.1.0-rc1`). Keine Rotation nötig.
- **Wirkliche Funde sind nur Kennungen, kein Zugang:** Rechnername als Autor (3 Commits), all-inkl-Kundennummer (Dateien und 8 Commits), lokale Pfade (`/Users/luro/...`, 5 Commits).
- **Bildschirmfotos sind sauber:** `t_codes.png` zeigt `XXXXX-XXXXX`, `t_enroll.png` und `t_totp.png` zeigen die Beispielzahl `123456`, QR-Code und Schlüssel sind unkenntlich gemacht.
- **Erledigt im Arbeitsbaum:** Kundennummer ersetzt, `.mailmap` angelegt, Rechnername in Prüfung 21 neutralisiert. Tests 1 522 Prüfungen grün.
- **Braucht Freigabe von Lutz:** ob die Historie vor dem öffentlichen Release umgeschrieben wird (Empfehlung in Abschnitt 7).

## 1. Geheimnis-Scan

Werkzeuge: `gitleaks` (Docker-Image `zricethezav/gitleaks`, Befehle `detect` mit `--log-opts=--all` und `dir`) und `trufflehog` (Docker-Image `trufflesecurity/trufflehog`, mit `--network none`, also ohne Netz und ohne Prüfung gegen Dienste). Nichts installiert, nur Images gezogen.

| Werkzeug | Umfang | Treffer |
| --- | --- | --- |
| trufflehog | ganze Historie, 1 518 Stücke, 2,6 MB | **0** (0 bestätigt, 0 unbestätigt) |
| gitleaks Historie | 115 Commits | 16, alle Regel `generic-api-key` |
| gitleaks Arbeitsbaum | alle Dateien auf der Platte | 15, dieselben Stellen |

Bewertung der gitleaks-Treffer (alle **Fehlalarm**, Risiko **niedrig**):

- `tests/support.php` (Commit `e8e385f`, Zeilen 15 bis 17): Konstanten `TEST_PASSWORD`, `TEST_SETUP_TOKEN`, `TEST_RESET_TOKEN`. Fest eingebaute Testwerte der Prüfungen, mit keinem Server verbunden.
- `tests/auth_flow.php` (Commits `e8e385f`, `21ea47b`, `ac101c0`): Felder `password`, `current_password`, `new_password` in simulierten Formularaufrufen. Testwerte.
- Empfehlung: nichts tun. Wer Ruhe vor GitHub-Secret-Scanning will, kann später eine `.gitleaks.toml` mit Erlaubnis für `tests/` anlegen (nicht gemacht, die Meldung ist bei Testwerten richtig und nützlich).

Zusätzliche eigene Suche in der ganzen Historie (`git log -G`):

| Muster | Ergebnis |
| --- | --- |
| Gmail-Adresse | 0 Commits |
| privater Schlüssel (`BEGIN ... PRIVATE KEY`) | 0 |
| TOTP-Adresse `otpauth://` mit Schlüssel | 0 |
| `LK_APP_KEY` mit langem Wert | 1 Commit (`81035be`), der Wert ist der Testwert aus lauter `A` in `tests/einrichten.sh`: Fehlalarm |
| FTP-Passwort | 3 Commits (`81035be`, `b560107`, `8b33266`): nur Zeilen, die das Passwort aus dem Schlüsselbund lesen (`LFTP_PASSWORD` aus `security find-generic-password`), kein Wert: Fehlalarm |
| `.env`, `*.pem`, `*.key`, `*.sqlite`, `*.db`, `*.zip` als hinzugefügte Datei | keine |

## 2. Bilder und PDFs (Anzeige angesehen)

Im Repo: 60 PNG/JPG unter `docs/anleitung/bilder/`, 12 SVG, 1 PDF (`Lichtkammer-Anleitung.pdf`); in der Historie dieselben Dateien, nichts darüber hinaus.

| Datei | Befund | Risiko |
| --- | --- | --- |
| `t_codes.png` | Wiederherstellungscodes als `XXXXX-XXXXX` (Platzhalter) | niedrig, sauber |
| `t_enroll.png` | QR-Code und Schlüssel unscharf gemacht, Code `123456` | niedrig, sauber |
| `t_totp.png`, `t_login.png`, `t_setup_form.png` | `123456`, Token und Passwort als Punkte | niedrig, sauber |
| `t_konto.png`, `t_konten.png`, `t_protokoll.png`, `t_import.png`, `t_einstellungen.png`, `t_fotografen.png`, `t_veroeffentlichen_fehler.png`, `t_dashboard_token.png`, `t_oeffentlich_start.jpg` | Beispielkonto `beispiel`, „Beispielfoto“, erfundene Namen (Mara Beispiel, Jonas Muster), Netz `127.0.0.0/24` | niedrig, sauber |
| übrige Bildschirmfotos | stammen aus derselben Wegwerf-Instanz (alle Zeitstempel 17.11.2026, Konto `beispiel`); nicht einzeln durchgesehen, die Stichprobe war überall sauber | niedrig |
| EXIF/Metadaten aller PNG, JPG, PDF (`exiftool`) | kein GPS, kein Autor, keine Kamera; PDF nur Titel und Erzeuger `WeasyPrint` | niedrig, sauber |
| PDF, Text durchsucht | kein `@`, keine Kundennummer, keine privaten Adressen, kein Rechnername | niedrig, sauber |

Empfehlung: Das Bild `t_protokoll.png` und `t_dashboard_token.png` zeigen kein Geheimnis. Nichts ändern. Vor dem öffentlichen Release nur noch einmal ansehen, wenn neue Bilder dazukommen (die Bildskripte in `docs/anleitung/skripte/` sollten weiter nur mit der Beispiel-Instanz laufen).

## 3. Kundennummer (all-inkl)

- **Arbeitsbaum:** in 5 Dokumenten durch `vXXXXXX` ersetzt: `docs/betrieb/Erster-Start-Testserver.md`, `docs/planung/04 Bestandsaufnahme.md`, `docs/planung/20 …`, `21 …`, `22 …` (`.env.example` und `deploy/einrichten.sh` hatten schon den Platzhalter bzw. keine feste Nummer). `git grep` findet die Nummer im Arbeitsbaum nicht mehr. `deploy/.env` (nicht im Repo) blieb unberührt.
- **Historie:** die Nummer steht noch in 8 Commits (`663d56a`, `02acfc3`, `cd8674e`, `b906ee7`, `81035be`, `b560107`, `e8e385f`, `9c8bf12`). Risiko **niedrig bis mittel**: Sie ist die Kennung des all-inkl-Kontos, allein aber kein Zugang (KAS-Anmeldung braucht Passwort). Sie hilft nur bei gezielter Social Engineering gegenüber dem Anbieter. Wird sie öffentlich, bleibt sie sichtbar, solange die Historie nicht umgeschrieben wird.
- Auch in der Datei steht „Kundennummer“ nur noch als Wort.

## 4. Autoren und `.mailmap`

`git log --all --format='%an <%ae> | %cn <%ce>'` (Autor und Committer, plus Commit-Texte nach Adressen durchsucht):

| Befund | Fundstelle | Risiko | Empfehlung |
| --- | --- | --- | --- |
| Rechnername als Autor **und** Committer | Commits `874c4f2`, `7107823`, `390ca48` | niedrig (Kennung, kein Zugang; verrät Mac-Namen und Benutzer `luro`) | `.mailmap` (erledigt), Historie später ggf. umschreiben |
| alle anderen 112 Commits | Autor und Committer = noreply-Adresse von `luro611` | kein Befund | ok |
| private Gmail-Adresse | in keinem Feld, in keinem Commit-Text, in keiner Datei | kein Befund | ok |
| `noreply@anthropic.com` | nur in `Co-Authored-By`-Zeilen | kein Befund | ok |

`.mailmap` angelegt (Repository-Identität aus `git config`): bildet die Rechner-Adresse auf `Lutz Rothe <…@users.noreply.github.com>` ab. `git shortlog -sne --all` zeigt jetzt eine einzige Person mit 115 Commits. Die Commits selbst bleiben unverändert (GitHub zeigt sie weiter mit dem Rechnernamen, `.mailmap` wirkt nur in git-Befehlen). Die Datei ist nicht im Release-Zip. Hinweis: `.mailmap` nennt den Rechnernamen selbst einmal; wird die Historie umgeschrieben, die Datei danach löschen.

## 5. Ausschlusslisten, Release-Zip, `git archive`

- `tools/release-zip.sh --pruefen` ausgeführt: bestanden. `lichtkammer-0.1.0-rc1.zip`, 183 Einträge, 1,7 MB. Inhalt nur `app/`, `public/`, `.env.example`, `LICENSE`, `NOTICE`, `README.md`, `INSTALL.txt`, `VERSION`. Der Zip wurde auf verbotene Namen (`.env`, `*.sqlite`, `*.db`, `*.pem`, `*.key`, `data/`, `originals/`, `tests/`, `docs/`, `deploy/`, `public/media/*`) und per Suche auf Kundennummer, Gmail, Rechnername, `/Users/`, private IP-Bereiche und Heimnetz-Namen geprüft: keine Treffer (nur die erlaubten Links auf `luro611/lichtkammer`).
- `.gitignore`: deckt `.env*` (außer `.env.example`), `deploy/.env`, `*.pem`, `*.key`, `id_*`, `/data/`, `/originals/`, `public/media/*`, `*.sqlite`, `*.db`, `*.log`, `/dist/` ab. `git status --ignored` zeigt `deploy/.env`, `dist/` und `.DS_Store` als ignoriert; im Index liegen von `deploy/` nur `einrichten.sh` und `env.beispiel`, von `dist/` nichts. In der Historie wurde nie eine solche Datei hinzugefügt.
- `.dockerignore`: schließt `.git`, `.env`, `data`, `originals`, `otf`, `docs`, `tests`, `deploy`, Schlüssel und Datenbanken aus. `dist` und `tools` stehen nicht drin, sind aber auch nicht im Image-Build gebraucht (Dockerfile kopiert nur `app` und `public`): niedrig, optional `dist` ergänzen.
- Lücke, die nicht im Repo ist: `~/lichtkammer-import` liegt außerhalb des Repos und kann nicht hineingelangen; `.gitignore` nennt den Pfad nicht (kein Risiko, Hinweis im Import-Ablauf genügt).
- `git archive HEAD` (Quellpaket von GitHub/Tag) enthält dagegen **alles Versionierte**, auch `docs/`, `tests/`, `CLAUDE.md`: Das ist gewollt, siehe Liste unter 6.

## 6. Namen und Daten in Dokumenten: Liste für das öffentliche Release

Gesucht: E-Mail-Adressen, Telefon- und Kontonummern, Kundennummern, private IP-Bereiche, Heimnetz-Namen, Personennamen. **Nicht gefunden:** Telefonnummern, IBAN, Gmail, echte private IP-Adressen (nur Beispielbereiche `192.0.2.x`, `198.51.100.x`, `203.0.113.x` und Netzbereiche in `.env.example`, `restore-probe.sh`, Tests), eine zweite Person namens Matthias.

Stellen, die vor dem öffentlichen Release geändert oder bewusst entschieden werden sollten:

| Nr. | Stelle | Was dort steht | Risiko | Empfehlung |
| --- | --- | --- | --- | --- |
| 1 | `CLAUDE.md` (2 Zeilen) | lokale Pfade `/Users/luro/...` (MediaDB, Nextcloud-Logo-Ordner), Rolle „Lutz“, interner Stand | mittel (Privatsphäre, nicht gefährlich) | vor dem Release kürzen oder in eine nicht öffentliche Datei verlagern |
| 2 | `docs/planung/` (37 Dateien) | interne Entscheidungen, Namen „Lutz“, Heimnetz-Werkzeuge `pve10`/`pve01`, „Homeserver“, `luro611/br-foto`, Hinweis auf Konto, `ns6.kasserver.com` (in 20) | niedrig bis mittel | entscheiden: ganzen Ordner nicht veröffentlichen (eigener privater Zweig) oder nur ausgewählte Entwürfe bereinigt zeigen |
| 3 | `docs/betrieb/Wiederherstellung.md` (9 Treffer), `Umstellung-Server.md`, `compose.yaml` (Kommentare), `tools/restore-probe.sh` | `pve10`/`pve01` als Name des Testservers im Heimnetz | niedrig | durch „Testserver“ oder „eigener Docker-Host“ ersetzen |
| 4 | `docs/betrieb/Erster-Start-Testserver.md`, `deploy/env.beispiel` (`DOMAIN=lichtkammer.br-foto.com`), `.env.example` (Beispiele mit `br-foto.com`) | Adresse der Testinstallation (öffentlich im Netz, kein Geheimnis) | niedrig | bleibt; in den Handbüchern später allgemein halten |
| 5 | `docs/planung/11`, `13`, `20`; `docs/betrieb/WordPress-haerten.md`; `tools/wp-export/wp_export.py` (je 1 bis 2 Treffer) | lokale Ordner (`~/lichtkammer-import`, Nextcloud) als Beispielpfad | niedrig | Pfade als `<Ordner>` schreiben |
| 6 | `tests/*.php`, `tools/wp-export/test_wp_export.py` | Benutzername `lutz`, Name „Lutz“ als Beispiel, `a@b.de` | niedrig | harmlos; optional auf `beispiel` umstellen |
| 7 | Quelltext-Kommentare (`app/src/Auth/RateLimiter.php`, `public/assets/css/*.css`, u. a.) | „Entscheidung von Lutz“ mit Datum | niedrig | als Projektnutzer in Ordnung, vor 1.0 durch „Entscheidung des Projekts“ ersetzen |
| 8 | `README.md`, `NOTICE`, `Dockerfile`, `compose.yaml`, Workflow `release.yml`, `docs/planung/15` | `github.com/luro611/lichtkammer` und `ghcr.io/luro611/...` | kein Risiko | gewollt (Quelle der AGPL, Image) |
| 9 | `docs/planung/20`, `.env.example` | `info@lurohome.de` als Kontakt | kein Risiko | gewollt |
| 10 | `docs/anleitung/` und PDF | Beispielinstanz, keine echten Daten | kein Risiko | sauber |

## 7. Empfehlung zur Historie (braucht Freigabe von Lutz)

Es wurde **kein echtes Geheimnis** gefunden. Die Historie enthält nur Kennungen: Rechnername (3 Commits, Autor und Committer), Kundennummer (8 Commits), lokale Pfade (5 Commits), Heimnetz-Namen in Planungsdokumenten. Das Repo ist privat; für den Livegang von br-foto.com und für den Testserver ist **keine Umschreibung nötig**.

Vor dem ersten **öffentlichen** Release gibt es zwei Wege:

| | A: Historie behalten | B: Historie umschreiben (`git filter-repo` mit Autorenabbildung und Textersetzung auf einem frischen Klon) | C: Repo neu beginnen (ein Anfangscommit, später fortlaufend) |
| --- | --- | --- | --- |
| Aufwand | keiner | mittel (ein Nachmittag: Klon, Skript, Prüfung, Tests, Vergleich der Dateibäume) | klein |
| Vorteil | alle Hashes und Prüfberichte stimmen, Tag `v0.1.0-rc1` bleibt | Rechnername, Kundennummer, lokale Pfade sind überall weg, Entwicklungsverlauf bleibt erhalten | sauberste Lage, nichts Altes sichtbar |
| Nachteil | Kennungen bleiben öffentlich sichtbar; wer die Historie liest, sieht auch die internen Planungsdokumente | **alle Hashes ändern sich**: die Prüfberichte (`docs/planung/*`) nennen Hashes, die dann ins Leere zeigen; Tag `v0.1.0-rc1` und alle privaten Releases/Pakete müssen neu gesetzt werden; wer das Repo geklont hat, muss neu klonen (Force-Push nötig) | Entwicklungsverlauf, `git blame` und die Hashes der Berichte gehen verloren; Tag und Releases neu |
| Risiko für private Tags/Releases | keins | Tag und Release zeigen nach dem Umschreiben auf neue Commits; das Image `ghcr.io/luro611/lichtkammer:0.1.0-rc1` müsste neu gebaut oder die Beziehung dokumentiert werden | wie B |

**Empfehlung:** Jetzt nichts umschreiben (Repo privat). **Kurz vor dem öffentlichen Release Weg B** auf einem frischen Klon, dabei: Autor der drei Commits auf die noreply-Identität setzen, die Kundennummer und `/Users/luro` im Text ersetzen, `docs/planung/` (Liste 6, Nr. 2) und `CLAUDE.md` entweder in einem nicht öffentlichen Zweig lassen oder bereinigen, danach `.mailmap` löschen, Tests laufen lassen, Dateibaum mit dem alten Stand vergleichen, erst dann nach Freigabe force-pushen und Tag neu setzen. Weg C ist nur dann besser, wenn Lutz `docs/planung/` und den Verlauf ohnehin nicht öffentlich haben will. Die Entscheidung gehört Lutz; wichtig ist nur, sie **vor** dem Öffentlichstellen zu treffen: Danach lässt sich das Gelesene nicht mehr zurückholen.

## 8. Erledigt und offen

Erledigt (Arbeitsbaum, ein Commit):

- Kundennummer in 5 Dokumenten durch `vXXXXXX` ersetzt.
- Rechnername in Prüfung 21 durch `<Rechnername>@<Rechner>.local` ersetzt (die Commit-Nummern bleiben als Fundstelle).
- `.mailmap` angelegt.
- Dieser Bericht, `CLAUDE.md` nachgeführt.
- Tests: 1 522 Prüfungen, 0 Fehler.

Braucht Freigabe von Lutz (nicht getan):

1. Historie umschreiben oder Repo neu beginnen (Abschnitt 7) und der Zeitpunkt.
2. Was von `docs/planung/` und `CLAUDE.md` öffentlich wird (Liste 6, Nr. 1 und 2).
3. Namen `pve10`/`pve01` und lokale Pfade in `docs/betrieb/` und `compose.yaml` auf neutrale Worte (Liste 6, Nr. 3 und 5), kann Sonnet nach Zustimmung umsetzen.
4. Optional: `.gitleaks.toml` mit Ausnahme für `tests/`, `dist` in `.dockerignore`, GitHub Secret Scanning und Push Protection beim Öffentlichstellen (steht schon in Entwurf 20, 8i).
