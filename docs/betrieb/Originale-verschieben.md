# Originale aus dem Webroot nehmen (Schritt 8a, H1/E19)

Stand: 18.11.2026. Gilt für alle Installationen auf all-inkl, die vor Schritt 8a eingerichtet wurden (Testserver `lichtkammer.br-foto.com`).

## Warum

Originale enthalten **alle Metadaten, auch GPS**. Bisher lagen sie auf all-inkl im Webroot (`<Webroot>/originals`) und waren nur durch eine `.htaccess`-Regel gesperrt. Jetzt liegen sie wie der Datenordner **oberhalb des Webroots**. Dort kommt weder der Webserver noch der FTP-Benutzer hin; nur PHP kann lesen und schreiben. Die App liefert Originale nicht aus (die Besucher sehen nur die erzeugten Varianten in `media/`); ein Abholweg für Sicherungen kommt mit Schritt 8b, nur für Administratoren.

## Was die App jetzt tut

- **Startprüfung:** Liegt `LK_ORIGINALS_DIR` im Webroot (oder fehlt die Zeile, sodass der Standard im Webroot liegt), startet der Admin nicht und nennt den Grund. Die öffentliche Seite läuft weiter. Nur mit `LK_ALLOW_DATA_IN_WEBROOT=1` geht es trotzdem, mit dauerhafter Warnung (nicht für die Produktion).
- **Automatischer Umzug:** Beim ersten Admin-Aufruf nach der Umstellung verschiebt die App die vorhandenen Originale aus `<Webroot>/originals` nach `LK_ORIGINALS_DIR`.
  - Gleiches Dateisystem (der Normalfall): der ganze Ordner wird mit **einem** Umbenennen verschoben, in Sekundenbruchteilen, ohne Kopie.
  - Sonst Datei für Datei: kopieren, SHA-256 vergleichen, erst dann die alte Datei löschen. Ein Durchgang dauert höchstens etwa 25 Sekunden, der nächste Admin-Aufruf macht weiter. Das Warnband im Admin nennt die Zahl der noch wartenden Dateien.
  - **Nie überschreiben:** Liegt am Ziel schon eine Datei gleichen Namens mit anderem Inhalt, bleibt die alte Datei liegen (Konflikt, nichts geht verloren). Symlinks werden nicht angefasst.
- Die Datenbank ändert sich nicht (sie speichert nur den relativen Pfad).

## Reihenfolge für den Testserver (nur nach Freigabe durch Lutz)

1. Sicherung der Datenbank im Admin ist nicht nötig (die Datenbank wird nicht berührt), aber ein Blick in den Admin vorher: Fotozahl notieren.
2. In `app/.env` auf dem Server die Zeile `LK_ORIGINALS_DIR=/www/htdocs/vXXXXXX/lichtkammer-originals` ergänzen (mit `./deploy.sh --env-setzen LK_ORIGINALS_DIR=…`, siehe `Umstellung-Server.md`).
3. Neuen Code hochladen (`./deploy.sh --jetzt`, vorher Probelauf ohne `--jetzt`). **Beide Schritte kurz hintereinander:** Der alte Code würde mit der neuen Zeile den leeren neuen Ordner sehen; der neue Code ohne die Zeile startet den Admin nicht.
4. `/admin/login` aufrufen, anmelden. Der Umzug läuft dabei. Danach prüfen:
   - Fotozahl unverändert, ein Foto öffnen, eine Galerie veröffentlichen (Vorschau) und Bilder werden angezeigt.
   - Das Warnband „liegen noch im alten Ordner“ ist weg.
   - `https://<Domain>/originals/` liefert weiter 403 oder 404 (der Ordner ist ohnehin weg).
5. Rückfall, falls etwas nicht stimmt: `LK_ORIGINALS_DIR`-Zeile entfernen **und** `LK_ALLOW_DATA_IN_WEBROOT=1` setzen, nachdem die Dateien zurück in den Webroot verschoben wurden (nur per Einmal-PHP-Skript nach Absprache; die App verschiebt nie zurück). Praktisch ist das nicht nötig, solange der Umzug nur umbenennt.

## Prüfen, ob etwas im Webroot übrig ist

Per FTPS im Webroot nachsehen: Es darf **keinen** Ordner `originals/` mehr geben (leere Reste entfernt die App selbst). Liegt dort noch etwas, meldet das Warnband die Anzahl; ein Konflikt (gleicher Name, anderer Inhalt) wird nicht aufgelöst, sondern an Claude gemeldet.
