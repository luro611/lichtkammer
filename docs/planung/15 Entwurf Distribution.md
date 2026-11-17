# Entwurf: Verteilung von Lichtkammer (Release für Webspace und Docker)

Stand: 17.11.2026. **Entwurf, wartet auf Freigabe von Lutz.** Frage: Wie kommt Lichtkammer an Interessenten, die nur FTP haben (wie bei all-inkl) oder die es mit Docker aufsetzen, und wie bleibt beides ohne doppelte Arbeit gepflegt? Grundlage: Plan `03` (zwei Betriebsarten), Schritt 8 (Härtung und Livegang). Nicht Teil: das Livegehen von br-foto.com (eigener Ablaufplan).

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

## 8. Entscheidungen, die ich von Lutz brauche

- **D1 Zip und Image wie beschrieben?** (Empfehlung: ja.)
- **D2 Schlüssel auf Webspace ohne SSH:** Wie kommt eine Person ohne Konsole an `LK_APP_KEY`? Empfehlung: ein Hinweis in `INSTALL.txt` mit einer Zeile für jedes Betriebssystem und als Rückfall eine kleine Hilfsseite `/admin/setup` mit Token, die den Schlüssel erzeugt und in `app/.env` schreibt (wie `deploy.sh --einrichten` es für Lutz tut). Bitte freigeben oder anders entscheiden.
- **D3 Sprache der Anleitung:** Deutsch reicht für den Anfang (Empfehlung), Englisch später.
- **D4 Update-Hinweis im Admin** („neue Version verfügbar“): Empfehlung zunächst nicht, weil das einen Abruf bei GitHub bräuchte. Später als Option, standardmäßig aus.
- **D5 Zeitpunkt:** Erstes öffentliches Release erst nach dem Livegang von br-foto.com (Empfehlung), Vorarbeit (Skript, Workflow, Anleitung) schon vorher auf dem privaten Repo testen.

## 9. Bauplan nach Freigabe

1. `tools/release-zip.sh` und Test, dass das Zip nichts Unerwünschtes enthält (kein `data/`, keine `.env`, keine Fotos).
2. `INSTALL.txt`, `CHANGELOG.md`, Anleitung im README.
3. `.github/workflows/release.yml` (Tests, Zip, Image, Release); Probelauf auf einem privaten Tag.
4. `compose.yaml` auf das veröffentlichte Image umstellen.
5. Prüfung durch Opus (Release-Skript und Workflow), danach Prüfliste aus Abschnitt 7 und Freigabe zum Öffentlichschalten durch Lutz.
