# Offene Fragen

Stand: 2026-10-19. Zu klären vor oder in Schritt 0. Antworten hier eintragen, Entscheidungen später in eine eigene Datei übernehmen.

## A. Abweichungen zwischen Konzept und realem Stand

1. **Aufbau der Media-DB:** Das Konzept geht von *einer* SQLite-Datei aus, in die neue Foto-Tabellen kommen könnten. MediaDB hat aber getrennte Dateien je Medienart (`musik.db`, `filme.db`, `serien.db`, `buecher.db`, `verleih.db`), Fotos kommen dort nicht vor. Vorschlag: eigene SQLite-Datei für das Foto-CMS, MediaDB höchstens lesend. Welche Verbindung zur MediaDB ist überhaupt gewünscht?
2. **PHP oder Python:** Das Konzept nennt PHP. MediaDB und PermLens sind Python/FastAPI in Docker. Beim Generator-Ansatz braucht all-inkl gar keine Laufzeit, außer evtl. für das Kontaktformular. Soll der Admin/Generator in Python gebaut werden (gleicher Stack, gleiche Designsprache) und nur das Formular in PHP?
3. **Betrieb auf dem Homeserver:** eigener LXC mit Docker und Dockhand wie MediaDB und PermLens, oder ohne Docker (Konzept: „nicht zwingend von Docker abhängen“)?

## B. Fragen aus dem Konzept (Abschnitt 14)

4. Welche PHP-Version und Bildbibliotheken gibt es bei all-inkl? Sind SSH, rsync, Composer und Cron nutzbar?
5. Ressourcen im Homeserver-LXC: CPU, RAM, Speicher, Backup, Erreichbarkeit (auch für die zweite Person von außen?).
6. Öffentlicher Betrieb rein statisch oder geschützte Bereiche gleich als Hybrid?
7. Weitere Referenzseiten für Navigation, Typografie und Galerieabläufe?
8. Welche EXIF-Felder dürfen öffentlich erscheinen (insbesondere GPS)?
9. Benutzer- und Backup-Regeln für Admin, Redakteur und Originaldateien. Wo liegen die Originale?

## C. Projekt-Eckdaten

10. **Name** des Projekts (Repo, Image, Kopfzeile)?
11. **Lizenz:** MIT, AGPL oder GPL-3.0-or-later wie PermLens?
12. **Sprachen:** Admin nur Deutsch oder Deutsch/Englisch mit Umschalter wie MediaDB? Öffentliche Seite mehrsprachig erst in Phase 2?
13. **Designsprache und Marke:** Admin im MediaDB-Design mit luro-Wortmarke und Siegel?
14. Wer ist die zweite Person, und auf welchem Gerät arbeitet sie hauptsächlich (Mac, iPad, Windows)?

## D. Antworten zu Plan 03, Abschnitt 12 (Lutz, 15.11.2026)

1. **Apple-Rahmen:** nur br-foto. Die allgemeine Regel (2 px) bleibt, br-foto ist eine begründete Ausnahme.
2. **Repos:** getrennt. `lichtkammer` = System ohne Inhalte, `br-foto` = Einstellungen, Weiterleitungen, Importskript, `deploy.sh`.
3. **Passkey:** Schritt 6, vor dem Livegang. TOTP zum Start.
4. **Marke:** einverstanden (BR-Foto öffentlich, luro nur kleine Wortmarke in der Fußzeile, Siegel auf der Anmeldeseite).
5. **Namen der Fotografen:** offen, wird vor dem Umzug (Schritt 7) mit beiden geprüft.
6. **Umzug:** alles (alle Galerien und Seiten).
7. **Originale:** teilweise vorhanden, Ort und Umfang noch zu nennen (Klärung spätestens in Schritt 7). WordPress selbst hält für 114 Bilder die ungeskalte Originaldatei vor, siehe `04 Bestandsaufnahme.md`.
8. **EXIF öffentlich:** je Galerie einstellbar, Standard aus. GPS nie.
9. **Gerät der zweiten Person:** Mac.
10. **Projektname:** **Lichtkammer** (Arbeitsname PHOTO-CMS entfällt). Ordner und Repo am 15.11.2026 umbenannt (`~/Documents/Projekte/lichtkammer`, `luro611/lichtkammer`).
11. **Lizenz:** AGPL-3.0-or-later (Entscheidung Lutz, 15.11.2026; beantwortet C.10 und C.11).
