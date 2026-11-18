# WordPress bis zum Umschalten härten (E3)

Stand: 18.11.2026. Entscheidung von Lutz (18.11.2026): WordPress wird **ab sofort** gehärtet, nicht erst kurz vor dem Livegang. Grund (Prüfung 21, H3): Alle Domains eines Kontos laufen unter einem Systembenutzer, `open_basedir` ist leer. Ein PHP-Skript einer beliebigen Domain des Kontos kann `app/.env` und die Datenbank von Lichtkammer lesen. Das Risiko besteht, **solange die Testinstallation echte Konten hat und WordPress öffentlich erreichbar ist**, also jetzt, nicht erst nach dem Umschalten. Echte Trennung gäbe nur ein eigener Systembenutzer (E3 a, Frage an den all-inkl-Support) oder ein anderer Server.

**Das macht Lutz im WordPress und im KAS. Claude ändert an WordPress nichts.** Vor jeder Änderung eine Sicherung (Dateien und Datenbank) außerhalb aller Repositories ablegen.

## Liste (in dieser Reihenfolge)

1. **Sicherung** der WordPress-Dateien und -Datenbank, verschlüsselt auf den Homeserver; einmal ansehen, ob sie sich öffnen lässt. Diese Sicherung ist auch der Rückweg nach Abschnitt 3.8 des Entwurfs.
2. **Aktualisieren:** WordPress-Kern, Theme und **alle** Plugins auf den neuesten Stand. Danach nichts mehr installieren.
3. **Aufräumen:** nicht benutzte Plugins und Themes **löschen** (deaktiviert genügt nicht), nicht benutzte Benutzerkonten löschen, starke Passwörter und, falls vorhanden, zweiten Faktor für die verbleibenden Administratoren.
4. **Angriffsflächen schließen:** XML-RPC abschalten (Plugin „Disable XML-RPC“ oder `.htaccess`-Regel), den REST-Endpunkt für Benutzerlisten (`/wp-json/wp/v2/users`) für Unangemeldete sperren, Dateibearbeitung im Dashboard aus (`define('DISALLOW_FILE_EDIT', true);` in `wp-config.php`), keine Plugin-Installation aus dem Dashboard (`DISALLOW_FILE_MODS`) bis zur Abschaltung.
5. **Rechte:** `wp-config.php` auf 400 oder 440, `wp-content` nicht schreibbar, wo das WordPress-Verhalten es zulässt (Uploads nicht mehr nötig: **keine neuen Beiträge** ab Stichtag).
6. **Beobachten:** Stand der Dateien (Größe und Änderungszeit der `.php`-Dateien im WordPress-Ordner) einmal per FTPS auflisten und nach 7 Tagen vergleichen; unerwartete Änderungen sind ein Alarm. Zugriffs- und Fehlerprotokoll im KAS auf auffällige Abrufe (`wp-login.php`-Häufung, `xmlrpc.php`, unbekannte `.php`-Dateien) ansehen.
7. **Andere Anwendungen des Kontos** (KAS: Domains und ihre Software): Was läuft noch unter Konto A, welche PHP-Anwendungen (WordPress, Nextcloud, Sonstiges), jeweils Stand und Plan. Ergebnis in dieses Dokument.

## Nach dem Umschalten

- Prüfen, dass der WordPress-Ordner **über keinen Host mehr erreichbar** ist (Aliasse, alte Subdomains, `www`). Sein PHP kann dann Lichtkammer nicht mehr angreifen, auch wenn die Dateien bis zu 14 Tage liegen bleiben.
- **Ein Rückfall auf WordPress öffnet das Risiko wieder.** Das ist so beabsichtigt und hier dokumentiert; ein Rückfall ist nur dann zu wählen, wenn Lichtkammer tatsächlich ausfällt.
- Spätestens Tag 14: WordPress entfernen (Entwurf 3.8; Löschen nur nach Bestätigung von Lutz).

## Offene Frage an den all-inkl-Support (E3 a)

Kann `br-foto.com` einen **eigenen Systembenutzer bzw. KAS-Account** bekommen (Kosten, Umzug der Domain zwischen Accounts)? Nicht raten; die Antwort hier eintragen.
