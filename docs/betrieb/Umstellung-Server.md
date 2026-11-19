# Server-Umstellung Schritt für Schritt (Stand 8a)

Stand: 18.11.2026. Gilt für den Testserver `lichtkammer.br-foto.com` (Teil A) und den Livegang auf `www.br-foto.com` (Teil B).
**Nichts davon ist ausgeführt.** Jeder Schritt mit „Server“ braucht die ausdrückliche Freigabe von Lutz im Chat (Regeln: Entwurf 20, Projekt-`CLAUDE.md`). Alle Befehle laufen auf dem Mac im Projektordner; der FTPS-Zugang kommt aus `deploy/.env` und dem Schlüsselbund, nie aus dem Chat.

## Teil A: Testserver auf Stand 8a bringen (Originale aus dem Webroot)

Warum: Auf dem Testserver liegen die Originale (mit GPS) heute noch in `<Webroot>/originals`. Details: `Originale-verschieben.md`.

**Vorher (nur lesend, kein Server nötig):**
1. Tests grün: `docker run --rm -v "$PWD":/app -w /app lichtkammer-test php tests/run.php`.
2. In `deploy/.env` zwei Zeilen ergänzen (Platzhalter in `deploy/env.beispiel`): `LK_DATA_DIR` und `LK_ORIGINALS_DIR`, beide oberhalb des Webroots, z. B. `…/lichtkammer-originals` neben `…/lichtkammer-data`.
3. Probelauf des Hochladens (zeigt nur die Dateiliste, überträgt nichts): `./deploy.sh`
4. Probelauf der Änderung der `.env` (liest die Server-`.env` lesend, ändert nichts, zeigt keinen Schlüssel):
   ```bash
   ./deploy.sh --env-setzen LK_ORIGINALS_DIR=/www/htdocs/vXXXXXX/lichtkammer-originals --probe
   ```

**Dann (Server, nach Freigabe von Lutz; Teil 1 und 2 kurz hintereinander):**
5. Code hochladen (vorher committen): `./deploy.sh --jetzt`. Ab jetzt zeigt der Admin „nicht verfügbar“ (Originale im Webroot), die öffentliche Seite läuft weiter.
6. Sofort danach: `./deploy.sh --env-setzen LK_ORIGINALS_DIR=/www/htdocs/vXXXXXX/lichtkammer-originals`
7. `/admin/login` aufrufen und anmelden: Der erste Aufruf verschiebt die Originale (ein Umbenennen, Sekundenbruchteile). Prüfen: Fotozahl, ein Foto öffnen, Vorschau der Startseite, Warnband „liegen noch im alten Ordner“ ist weg.
8. `tools/livepruefung.sh https://lichtkammer.br-foto.com --noindex`: `/originals/` liefert 403/404, alle Kopfzeilen vorhanden, `http` leitet auf `https` um, Dateien von Apache tragen `nosniff`.

**Rückfall Teil A:** nur nötig, wenn Schritt 7 Fehler zeigt. Die Datenbank wurde nie berührt, die Originale nie überschrieben. Wegen der Zeilen siehe `Originale-verschieben.md` (Abschnitt Rückfall).

## Teil B: Livegang auf `www.br-foto.com`

**Entscheidungen von Lutz (18.11.2026):** Weg A (Domain im KAS auf den vorhandenen Ordner stellen), Hauptadresse **`https://www.br-foto.com`** (WordPress leitet heute schon `br-foto.com` → `www` um), `br-foto.com` und `lichtkammer.br-foto.com` leiten danach in einem Schritt dorthin. Version `0.2.0`.

### Voraussetzungen (alle erfüllt, bevor ein Termin genannt wird)

- Schritte 8b bis 8e fertig: Sicherungsweg gebaut, **Fable-Prüfung** (Sicherungsweg, Anmeldung, Dateiannahme und Auslieferung, Geheimnisse), Opus-Prüfungen, Befunde behoben.
- **Wiederherstellungsprobe A (Docker auf dem Testserver) und B (all-inkl, Wegwerf-Subdomain)** bestanden (`Wiederherstellung.md`).
- Abnahmeliste (Entwurf 20, Abschnitt 6) mit Lutz und der zweiten Person durchgegangen; Lutz schreibt „Abnahme bestanden“.
- **E2:** Lutz hat im KAS **ohne etwas zu ändern** geprüft, dass der Zielordner der Domain frei wählbar ist (und dass „SSL für Domain und www“ aktiv ist). Sonst Weg C (Entwurf 3.2).
- **E3:** WordPress gehärtet (`WordPress-haerten.md`), Sicherung der WordPress-Dateien und -Datenbank abgelegt, Rückweg einmal angesehen. Antwort des all-inkl-Supports zum eigenen Systembenutzer notiert.
- Testdaten aufgeräumt (E6): Testkonten gelöscht, Papierkorb geleert, Entwürfe geprüft; Protokoll bleibt. `LK_SETUP_TOKEN` vom Server entfernt (Warnband weg), `LK_ALLOW_DATA_IN_WEBROOT` nicht gesetzt.
- Liste der alten WordPress-Adressen (`/wp-sitemap.xml`, REST, nur lesend) liegt als Datei vor (eine Adresse je Zeile).
- Termin: Dienstag bis Donnerstag vormittags, nicht vor Reisen oder Feiertagen, beide erreichbar, zwei Stunden Zeitfenster (E15). Den Rückfall löst Lutz allein aus.

### Ablauf am Umschalttag (Server, nur nach Freigabe)

Alle `./deploy.sh --env-setzen …` zuerst mit `--probe` ansehen, dann ohne.

1. **Sicherung ziehen** (Abholknopf, Schritt 8b) und die Datenbankkopie prüfen.
2. **Adresse und Suchmaschinen in der Server-`.env`:**
   ```bash
   ./deploy.sh --env-setzen LK_BASE_URL=https://www.br-foto.com
   ./deploy.sh --env-setzen LK_NOINDEX=0
   ```
   **Noch nicht** `LK_REDIRECT_HOSTS` setzen: Sonst würde die Testadresse sofort auf `www.br-foto.com` (noch WordPress) umleiten und der Admin wäre nicht mehr erreichbar.
3. **Im Admin neu veröffentlichen** (über `lichtkammer.br-foto.com`): erzeugt `robots.txt`, Sitemap, `canonical` und Weiterleitungen mit der neuen Adresse.
4. **Im KAS** den Zielordner der Domain `br-foto.com` **und** `www.br-foto.com` auf den Ordner der Testinstallation stellen. Ab hier ist die neue Seite live (DNS ändert sich nicht, das Zertifikat bleibt).
5. **Nebenadressen umleiten:**
   ```bash
   ./deploy.sh --env-setzen LK_REDIRECT_HOSTS=br-foto.com,lichtkammer.br-foto.com
   ```
6. **Sofortprüfung** (nur lesend):
   ```bash
   tools/livepruefung.sh https://www.br-foto.com --index --neben-host br-foto.com --neben-host lichtkammer.br-foto.com --urlliste <alte-adressen.txt>
   ```
   Dazu von Hand: Startseite, drei Galerien, Impressum, Datenschutz, eine alte Adresse (301 → 200), `/admin/login` (Neuanmeldung mit Passwort und TOTP nötig, weil die `__Host-`-Cookies je Adresse gelten; **keinen** Wiederherstellungscode verbrauchen), Mobil, Zertifikat.
7. **Entscheidung nach 30 Minuten:** passt es? Sonst Rückfall.
8. Die Weiterleitungen der Umzugs-Adressen gelten in den ersten 14 Tagen im Browser nur eine Stunde (`LK_REDIRECTS_PERMANENT` ist aus), damit ein Rückfall nicht an gemerkten 301 hängt.

### Rückfall (bis Tag 14)

Auslöser (vorher festgelegt): Startseite oder Galerien liefern 5xx; Admin lässt sich nicht anmelden und der Wiederherstellungsweg versagt; Bilder fehlen großflächig; Datenverlust. **Nicht** Auslöser: einzelne fehlende Weiterleitungen oder Schönheitsfehler.

1. Im KAS den Zielordner von `br-foto.com` und `www.br-foto.com` **zurück auf den WordPress-Ordner** stellen. Die Datenbank von WordPress und sein Ordner wurden nie angefasst.
2. Damit die Testadresse weiter funktioniert: `./deploy.sh --env-setzen LK_REDIRECT_HOSTS=br-foto.com` (die Testadresse aus der Liste nehmen; leer lässt sich der Wert nicht setzen, `br-foto.com` ist dann wirkungslos, weil die Domain wieder auf WordPress zeigt) und `LK_BASE_URL=https://lichtkammer.br-foto.com`, `LK_NOINDEX=1` zurückstellen.
3. **Was dabei verloren gehen kann:** alles, was seit dem Umschalten nur in Lichtkammer entstand; die Daten bleiben aber unberührt und lassen sich nach der Rückkehr wieder verwenden. **Das Risiko des gemeinsamen Systembenutzers lebt mit WordPress wieder auf** (`WordPress-haerten.md`).

### Nach dem Livegang (Tag 1 bis 14)

- Tag 1: Zugriffs- und Fehlerprotokoll im KAS auf 404-Häufungen ansehen; fehlende Weiterleitungen im Admin ergänzen, neu veröffentlichen.
- Tag 3 und 7: Stichproben (`tools/livepruefung.sh`), Sicherung frisch?
- **Tag 14 höchstens:** `./deploy.sh --env-setzen LK_REDIRECTS_PERMANENT=1` (Weiterleitungen werden dauerhaft), WordPress abschalten (umbenennen, zwei Tage warten, erst dann löschen; Löschen nur nach Bestätigung von Lutz), FTP-Passwort erneuern, `LK_BASE_URL`-Subdomain `lichtkammer.br-foto.com` nach vier Wochen abschalten.
