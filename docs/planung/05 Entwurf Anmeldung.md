# Entwurf Schritt 2: Anmeldung (Risiko hoch)

Stand: 15.11.2026. Entwurf zur Prüfung, noch kein Code. Grundlage: Plan 03, Abschnitt 6. Gebaut wird erst nach der Prüfung (Opus, bei Bedarf Fable) und Freigabe durch Lutz. Passkey ist Schritt 6 und hier nur als Erweiterungspunkt vorgesehen.

## 1. Ziel und Abgrenzung

- Zwei Personen melden sich mit **Passwort und TOTP** an. Keine Selbstregistrierung, keine Passwort-per-E-Mail-Wege, kein „Angemeldet bleiben“.
- Rollen: **admin** (Lutz: Konten anlegen und sperren, alles) und **editor** (zweite Person: Inhalte). Rechte werden zentral in einer Stelle geprüft, nicht verstreut.
- Läuft unverändert auf all-inkl und in Docker (Betriebsarten, Plan 4a). Keine Bibliotheken außer einer kleinen QR-Bibliothek, lokal eingebunden.

## 2. Speicher (SQLite, Datei im `LK_DATA_DIR`, Rechte 0600)

| Tabelle | Inhalt |
|---|---|
| `users` | `id`, `username` (eindeutig, klein), `display_name`, `role`, `password_hash` (Argon2id), `totp_secret_enc`, `totp_confirmed_at`, `totp_last_step` (Schutz gegen Wiederverwendung), `failed_count`, `locked_until`, `disabled_at`, `created_at` |
| `recovery_codes` | `user_id`, `code_hash`, `used_at` |
| `sessions` | `id_hash` (SHA-256 der Sitzungs-ID), `user_id`, `stage` (`password_ok` oder `full`), `csrf_token`, `created_at`, `last_seen_at`, `ip_prefix`, `ua_hash` |
| `login_attempts` | `kind` (`user` oder `ip`), `key_hash`, `at` |
| `invites` | `token_hash`, `role`, `expires_at`, `used_at`, `created_by` |
| `audit_log` | `at`, `user_id` (leer erlaubt), `event`, `detail`, `ip_prefix`. Nur Anhängen, im Code keine Änderung und kein Löschen. |

- **Geheimschlüssel `LK_APP_KEY`** (32 Byte, Base64) in `app/.env` bzw. Umgebung, beim Einrichten erzeugt. Er verschlüsselt die TOTP-Geheimnisse (libsodium `secretbox`, Nonce je Eintrag) und dient als HMAC-Schlüssel für Wiederherstellungscodes und IP-Hashes. Er steht nie in der Datenbank. Fehlt er, startet der Admin nicht (Fehlerseite statt Standardwert).
- IP-Adressen werden nur als Präfix gespeichert (IPv4 /24, IPv6 /48), damit das Protokoll datensparsam bleibt.

## 3. Passwort

- Argon2id über `password_hash` (`memory_cost` 64 MiB, `time_cost` 3, `threads` 1; passt zu 512 MB `memory_limit` bei all-inkl). Bei Änderung der Parameter `password_needs_rehash` beim nächsten Login.
- Mindestens 12 Zeichen, höchstens 256 (Schutz vor Überlast). Abgelehnt werden: Benutzername im Passwort, Einträge einer lokalen Liste häufiger Passwörter (ca. 10.000, im Repo, `app/data/`). Es gibt keine Kompositionsregeln (Sonderzeichen, Ziffern), die Länge zählt.
- Unbekannter Benutzer: gleiche Antwort und gleicher Zeitaufwand (Scheinvergleich gegen einen festen Hash).

## 4. TOTP (RFC 6238)

- HMAC-SHA1, 6 Stellen, 30 s, Fenster ±1 Schritt (Uhrabweichung). Vergleich mit `hash_equals`.
- **Wiederverwendung:** Ein Schritt `<= totp_last_step` wird abgelehnt; nach Erfolg wird `totp_last_step` im selben Update gesetzt (`UPDATE … WHERE totp_last_step < :step`, Trefferzahl prüfen), damit zwei gleichzeitige Anfragen nicht beide durchgehen.
- **Einrichtung:** Geheimnis (20 Byte, Base32) wird erzeugt und verschlüsselt als „unbestätigt“ gespeichert. QR-Code als lokal erzeugtes SVG mit `viewBox` und ohne feste Breite/Höhe (Regel), dazu das Geheimnis als Text zum Abtippen. Aktiv wird TOTP erst, wenn ein gültiger Code eingegeben wurde; danach werden die Wiederherstellungscodes angezeigt.
- TOTP ist **Pflicht**. Ein Konto ohne bestätigtes TOTP kann sich nicht anmelden, außer in der Einrichtungsfolge.

## 5. Wiederherstellungscodes

- Zehn Codes zu je 10 Zeichen (Base32 ohne Verwechslungszeichen, in Gruppen), einmalig angezeigt, druckbar.
- Gespeichert als `HMAC-SHA256(LK_APP_KEY, code)`. Jeder Code gilt einmal; Verbrauch im selben Update wie die Prüfung (`WHERE used_at IS NULL`).
- Nutzung wird protokolliert und führt zur Aufforderung, neue Codes zu erzeugen (alte werden ungültig).

## 6. Anmeldeablauf und Sitzung

1. `POST /admin/login` mit Benutzername und Passwort (CSRF-Token Pflicht). Bei Erfolg: neue Sitzung mit `stage = password_ok`, Sitzungs-ID neu erzeugt (keine Fixierung), Weiterleitung zur TOTP-Seite.
2. `POST /admin/login/totp` mit Code oder Wiederherstellungscode. Bei Erfolg: `stage = full`, Sitzungs-ID **erneut** erneuert.
3. Nur `stage = full` darf Admin-Seiten sehen. `password_ok` verfällt nach 5 Minuten.

- **Sitzungs-ID:** 32 Zufallsbytes, Cookie mit der ID, in der Datenbank nur der SHA-256-Hash. Eigene Sitzungsverwaltung in SQLite (nicht PHP-Dateisitzungen), damit sie in beiden Betriebsarten gleich funktioniert und alle Sitzungen eines Kontos widerrufbar sind.
- **Cookie:** `__Host-`-Präfix (`Secure`, Pfad `/`, ohne `Domain`), `HttpOnly`, `SameSite=Strict`. Nur lokal ohne HTTPS (`LK_ENV=development`) ohne Präfix und `Secure`.
- **Lebensdauer:** Leerlauf 30 Minuten, absolut 12 Stunden. Beides einstellbar, Standard wie genannt.
- **Abmeldung** löscht die Sitzung serverseitig. Passwortänderung und TOTP-Neueinrichtung beenden alle anderen Sitzungen des Kontos. Ein Administrator kann Sitzungen anderer Konten beenden.
- **CSRF:** Token je Sitzung (zusätzlich zu `SameSite=Strict`), bei jedem `POST` geprüft, `hash_equals`. Zustandsändernde Aktionen nie per `GET`.
- **Anmeldeseite:** Sie bekommt Siegel und Wortmarke von luro, wie vereinbart. `Cache-Control: no-store` auf allen Admin-Seiten.

## 7. Schutz gegen Raten

- Zähler je Konto (`failed_count`) und je IP-Präfix (`login_attempts`, gleitendes Fenster 15 Minuten).
- Ab dem 3. Fehlversuch zunehmende Verzögerung (1, 2, 4, 8 s, maximal 30 s), ab 10 Fehlversuchen in 15 Minuten Sperre des Kontos bzw. der Quelle für 15 Minuten. Die Sperre gilt auch für die TOTP- und Wiederherstellungscode-Eingabe (gemeinsamer Zähler).
- Meldungen bleiben allgemein („Anmeldung nicht möglich“), sie verraten nicht, ob Benutzer, Passwort oder Code falsch war. Die Sperre wird dem Konto-Inhaber nicht anders angezeigt, den Administratoren aber im Protokoll.
- Verzögerung ohne Blockieren von PHP-Prozessen: Wartezeit als Zeitstempel (`locked_until`) und Antwort „bitte später“, kein `sleep`.

## 8. Ersteinrichtung und weitere Konten

- **Erstes Konto (Lutz):** Die Seite `/admin/setup` ist nur erreichbar, wenn (a) `users` leer ist **und** (b) ein Einrichtungstoken `LK_SETUP_TOKEN` in `app/.env` gesetzt ist und im Formular eingegeben wird. Lutz legt es per FTPS ab, da es kein SSH gibt. Nach dem ersten Konto liefert die Route dauerhaft 404; das Token wird zusätzlich als verbraucht vermerkt.
- **Zweites Konto:** Der Administrator erzeugt im Admin eine **Einladung** (Token, 24 h, einmal nutzbar, nur als Hash gespeichert, Link wird angezeigt und von Lutz weitergegeben). Die eingeladene Person setzt Benutzername, Passwort und TOTP selbst. Mehr als zwei aktive Konten sind technisch möglich, der Plan sieht zwei vor; die Zahl wird nicht fest eingebaut.
- **Konto sperren/entsperren** durch Administrator; ein Administrator kann sich nicht selbst sperren oder den letzten Administrator herabstufen.
- **Zurücksetzen:** Verliert jemand Gerät und Wiederherstellungscodes, setzt der Administrator das TOTP der Person zurück (neue Einrichtung beim nächsten Login nach Passwortprüfung). Für den Fall, dass Lutz selbst ausgesperrt ist, siehe Offene Frage 3.

## 9. Protokoll

Ereignisse: Anmeldung erfolgreich/fehlgeschlagen, TOTP-Fehler, Sperre, Wiederherstellungscode benutzt, Abmeldung, Passwort-/TOTP-Änderung, Konto angelegt/gesperrt/zurückgesetzt, Einladung erzeugt/benutzt, Einrichtung. Im Admin sichtbar für beide Konten (nur eigene Ereignisse für `editor`, alle für `admin`). Keine Passwörter, Codes oder Token im Protokoll.

## 10. Erweiterungspunkt Passkey (Schritt 6)

`users` bekommt später eine Tabelle `credentials`. Der Ablauf bleibt: Passkey ersetzt Schritt 1 und 2 zusammen, Passwort plus TOTP bleibt als Ersatzweg. Der Sitzungs-Code kennt dafür nur `stage`, keine Annahme über die Art der Anmeldung.

## 11. Tests (vor dem Bauen festgelegt)

- TOTP gegen die Testvektoren aus RFC 6238; Fenster ±1; Wiederverwendung desselben Schritts; gleichzeitige Prüfung (zweiter Aufruf scheitert).
- Passwortregeln (Länge, häufige Passwörter, Benutzername); Rehash bei geänderten Parametern.
- Wiederherstellungscodes: einmalig, nach Verbrauch ungültig, Neuerzeugung entwertet alte.
- Sitzung: ID wechselt bei Login und TOTP, Leerlauf- und Absolutablauf, `password_ok` verfällt, Abmeldung wirkt serverseitig, Cookie-Flags.
- CSRF: fehlend, falsch, richtig; `GET` ändert nichts.
- Sperre: Zähler, Verzögerung, Aufhebung nach Ablauf; gleiche Antwort für unbekannten Benutzer.
- Einrichtung: nur mit leerem `users` und Token; danach 404; Einladung einmalig und mit Ablauf.
- QR-SVG hat `viewBox`, keine feste Breite/Höhe, und wird im Browser angesehen.
- Audit: nur anhängen, keine Geheimnisse im Protokoll.

## 12. Offene Fragen an Lutz

1. **Sitzungsdauer:** Leerlauf 30 Minuten und absolut 12 Stunden. Passt das für die zweite Person beim Hochladen vieler Bilder (lange Sitzungen), oder soll der Leerlauf länger sein?
2. **Einladung:** Link, den du der zweiten Person schickst (z. B. per Nachricht), statt E-Mail-Versand vom Server. Einverstanden?
3. **Notfall für Lutz:** Wenn Passwort-Manager und Wiederherstellungscodes weg sind, bleibt nur ein manueller Weg. Vorschlag: Ein kleines Rücksetzskript, das du per FTPS hochlädst, mit `LK_RESET_TOKEN` aus `app/.env` und das sich nach einmaliger Nutzung selbst löscht. Oder lieber ohne diesen Weg und Neueinrichtung über die Datenbank? Das Skript ist eine zusätzliche Angriffsfläche; ich empfehle es nur auf Zeit (Token wird danach entfernt).
4. **Editor-Rechte:** Darf die zweite Person ihr eigenes TOTP und Passwort selbst ändern (Vorschlag: ja, mit aktuellem Passwort)?

## 13. Was geprüft werden soll

Besonders: Sitzungsablauf und Fixierung, Rennen bei TOTP und Wiederherstellungscodes, Gleichbehandlung bei unbekanntem Benutzer, Umgang mit `LK_APP_KEY` (Verlust, Rotation), Ersteinrichtung ohne SSH, Rücksetzweg, Protokoll-Unveränderbarkeit, Verhalten hinter Reverse-Proxy (IP-Präfix aus `X-Forwarded-For` nur bei `LK_TRUST_PROXY`).
