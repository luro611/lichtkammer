# Sicherheitsprüfung: Code Schritt 2 (Anmeldung)

Stand: 15.11.2026. Prüfung des gebauten Codes aus Commit `e8e385f` (Version 0.1.0-dev) nach Gutachten `07`, Abschnitt 5. Modell: Fable (nach der Modellregel für Risiko „hoch“). Grundlage: Entwurf `05`, Gutachten `07`, der gesamte Code unter `app/`, `docker/`, `compose.yaml`, `public/.htaccess`, `.env.example`, `tests/`. Die Testsuite lief vor der Prüfung mit 265 Prüfungen grün; eigene Nachweise liefen mit echten parallelen PHP-Prozessen im Testcontainer. Nichts wurde hochgeladen, der Testserver nicht berührt, keine Geheimnisse ausgegeben.

## 1. Ergebnis vorab

**Kein Befund mit Risiko hoch, kein Weg an Passwort plus TOTP vorbei.** Alle Befunde aus Gutachten `07` (H1, H2, M1 bis M10, N1 bis N9) sind im Code umgesetzt. Gefunden wurden zwei Befunde mit Risiko mittel (Benutzerauflistung über die Antwortzeit; Aussperren eines Kontos über den Namenszähler) und sieben niedrige. Alle eindeutigen Befunde sind behoben, jeder mit Test: A1, A3 bis A9 und B1 in Commit `b30ef3e`, A2 nach der Entscheidung von Lutz (Variante 1) im Folgecommit; 288 Prüfungen, 0 Fehler.

**Schritt 2 ist aus Sicht dieser Prüfung abgenommen.** Nächster Schritt: Upload auf den Testserver nach Freigabe, dort C2 und C3 (Abschnitt 3) prüfen.

## 2. Befunde und Korrekturen

### Mittel

**A1. Unbekannter Benutzername brauchte doppelt so lange.** `Passwords::verifyDummy` erzeugte den Scheinhash je Anfrage neu (ein Argon2-Lauf) und prüfte dann dagegen (zweiter Lauf); ein bekannter Name brauchte nur einen Lauf. Gemessen mit den echten Parametern (64 MiB, 3 Durchläufe): bekannt 138 ms, unbekannt 270 ms. Eine einzige Anfrage reichte, um gültige Namen zu erkennen. Der Code folgte dem Wortlaut des Entwurfs („einmal je Anfrage-Prozess“), verfehlte aber dessen Ziel.
*Behoben:* Scheinhash einmal erzeugt und in `meta.dummy_hash` abgelegt, bei geänderten Argon2-Parametern erneuert (`Auth::verifyDummy`). Test: zweiter unbekannter Versuch kostet genau eine Prüfung, Scheinhash unverändert; alte Parameter werden erneuert. Entwurf `05`, Abschnitt 3, angepasst.

**A2. Zähler „Name allein“ wirkt als Sperre (Aussperr-Falle wieder offen).** `RateLimiter`, Regel `user`: Nach 100 Fehlversuchen auf einen Namen in 15 Minuten (verteilt auf 10 Adressen, also unter der Sperre je Name+Adresse) gilt 30 s Wartezeit ab dem **letzten** gebuchten Versuch. Ein Angreifer, der jede Sekunde anfragt, belegt den freien Platz praktisch immer. Nachweis: Nach 100 Fehlversuchen aus verschiedenen /64-Netzen bekam der echte Benutzer von einer anderen Adresse mit richtigem Passwort `blocked`; in 5 simulierten Minuten (Angreifer 1/s, Benutzer alle 7 s) kam er 0 von 43 Mal durch. Der Entwurf wollte hier „nur Verzögerung, keine Sperre“. Kein Eindringen, aber Aussperrung mit kleinerem Aufwand als bei der globalen Bremse.
*Behoben (Entscheidung von Lutz, 15.11.2026, Variante 1):* Die Regel `user` weist nie mehr ab (`delay => false`, keine Sperre). Sie zählt nur und schreibt beim 100., 200., … Versuch im 15-Minuten-Fenster ein Ereignis `account_under_attack` (`attempts=100`, kein Name), also gedrosselt. Test: 100 Fehlversuche auf den Namen, der echte Benutzer kommt von neuer Adresse sofort durch, genau ein Ereignis, der 101. Versuch erzeugt kein weiteres.

### Niedrig

**A3. Fremde Seite konnte das Sitzungs-Cookie löschen.** `POST /admin/login/totp` ohne `password_ok`-Sitzung antwortete immer mit `__Host-lk_sid=; Max-Age=0`. Wegen `SameSite=Strict` kam von einer fremden Seite kein Cookie mit, der Browser nahm das Löschen bei der Navigation aber an: erzwungene Abmeldung. *Behoben:* Lösch-Cookie nur, wenn ein Cookie mitkam. Test.

**A4. „Sitzungen beenden“ für nicht vorhandenes Konto endete mit Fehler 500** (Fremdschlüssel im Protokolleintrag). *Behoben:* Konto prüfen, sonst 404. Test.

**A5. Nur-Anhängen-Trigger mit `INSERT OR REPLACE` umgehbar.** SQLite feuert den DELETE-Trigger bei REPLACE nur mit `recursive_triggers=ON`. Kein Code im Repo benutzt REPLACE; der Trigger soll aber gerade vor solchen Programmfehlern schützen. *Behoben:* `PRAGMA recursive_triggers = ON` in `Database::open`. Test: REPLACE wird abgewiesen, Kette bleibt stimmig.

**A6. Schutz „Datei ohne Schlüssel“ (N1 aus `07`) galt nur für das TOTP-Geheimnis.** Wiederherstellungscodes waren HMAC ohne `user_id`; Sitzungs-, Zwischenzeilen- und Einladungs-Kennungen reines SHA-256; der HKDF-Kontext `ids` war angelegt, aber unbenutzt. Wer die SQLite-Datei schreiben, aber `LK_APP_KEY` nicht lesen kann (Docker-Volume, Sicherung), konnte den `code_hash` seines Codes in ein anderes Konto kopieren (angenommen) oder eine Sitzungszeile mit `sha256(eigener Wert)` einfügen (`/admin` 200). Der Entwurf verlangte für Sitzungen nur SHA-256, also keine Abweichung, aber die N1-Annahme hielt so nicht. *Behoben:* Codes `HMAC(recovery, user_id|code)`; Sitzungs-, Zwischenzeilen- und Token-Kennungen `HMAC(ids, raw)`. Die Markierung „Notfall-Token verbraucht“ in `meta` bleibt bewusst schlüsselfrei (SHA-256), sonst gälte ein verbrauchtes `LK_RESET_TOKEN` nach einer Schlüsselrotation wieder; Test dafür. Folge: Eine Schlüsselrotation ohne `LK_APP_KEY_PREVIOUS`-Umschlüsselung beendet alle Sitzungen, offene Einladungen und Wiederherstellungscodes (siehe C7). Entwurf `05`, Abschnitte 2 und 5, angepasst.

**A7. Einladungen eines gesperrten Administrators blieben gültig.** Ein gesperrter oder herabgestufter Admin konnte über einen zuvor erzeugten Link binnen 24 Stunden noch einen neuen Administrator anlegen lassen. *Behoben:* Sperren und Herabstufen entwerten seine offenen Einladungen in derselben Transaktion. Test.

**A8. „Letzter aktiver Administrator“ zählte Konten ohne TOTP mit.** Admin A setzt Admin B zurück (B ohne TOTP) und stuft sich dann herab: kein Admin kann sich mehr anmelden, bis Bs Link verbraucht oder abgelaufen ist. *Behoben:* nur Admins mit bestätigtem TOTP zählen. Test.

**A9. Kommentar hinter einem Wert in `app/.env` machte den Wert unwirksam.** `LK_SESSION_IDLE=3600   # Leerlauf` ergab keine Zahl, also stillschweigend den Standard; `.env.example` lädt genau dazu ein. *Behoben:* `Config::parseEnvFile` schneidet ` #…` bei Werten ohne Anführungszeichen ab; `#` ohne Leerzeichen davor (Adressen) und in Anführungszeichen bleibt. Tests.

### Abweichungen vom Entwurf

- **B1. Ende der Sperre.** Die Sperre endet, wenn der erste der 10 Versuche aus dem Fenster fällt; Entwurf §14 sagte „15 Minuten nach dem letzten“, und die angezeigte Restzeit rechnete entsprechend falsch (899 s statt rund 806 s). Unkritisch. *Behoben:* Restzeit ab dem ersten Versuch (`MIN(at)`), Dokumentation angepasst, Test.
- **B2.** Scheinhash: Wortlaut eingehalten, Ziel verfehlt (A1); Entwurf geändert.
- **B3. Pflichttests nur nacheinander.** „Zwei gleichzeitige Einrichtungen“ und „20 gleichzeitige Passwortversuche“ laufen in `tests/auth_flow.php` nacheinander in einem Prozess, nicht mit echten Prozessen wie in §11 gemeint. Die Prüfung hat beides mit echten Prozessen ausgeführt (Abschnitt 5), die Tests im Repo sollten nach dem Muster von `tests/auth_race.php` nachgezogen werden. *Offen, Aufgabe für Schritt 3 oder einen kleinen Folgeauftrag.*
- **B4.** Schon in §14 dokumentiert, zur Kenntnis: Liste häufiger Passwörter mit rund 150 statt 10.000 Einträgen; Notfall-Link auch bei unbekanntem Namen angezeigt (Token dann nicht verbraucht); gemeinsame Seite `/admin/enroll` für Einrichtung, Einladung und Rücksetzung.
- **B5.** Ohne Sicherheitswirkung: Route `POST /admin/users/{id}/role` hat kein Formular; `display_name` wird nie gesetzt; ein Konto kann sein TOTP nicht selbst neu einrichten, nur über den Rücksetz-Link (Frage 4 des Entwurfs offen).

## 3. Unsicher oder erst auf dem Ziel prüfbar

- **C1. Schreiblast vor der Zählerprüfung (niedrig).** Jeder `POST /admin/login` schreibt mehrere eigene Transaktionen (zweimal `purge`, dann Zählerprüfung), auch wenn er abgewiesen wird. Eine Anfrageflut könnte bei `journal_mode=DELETE` die Schreibsperre so belegen, dass andere Anfragen nach 5 s `busy_timeout` mit 500 enden. Nicht gemessen. Vorschlag: Aufräumen höchstens einmal je Minute (Zeitstempel in `meta`).
- **C2. Cookie-Präfix hängt vom PHP-Patchstand ab.** Ältere PHP-Stände formten Cookie-Namen wie `..Host-lk_sid` zu `__Host-lk_sid` um (CVE-2022-31629, CVE-2024-2756); das WordPress auf br-foto.com könnte so Cookies für die Subdomain unterschieben (Login-CSRF). Im Testimage (PHP 8.5.12) werden solche Namen verworfen. **Auf all-inkl den PHP-Stand prüfen** (mindestens 8.1.28, 8.2.18 oder 8.3.6).
- **C3. `$_SERVER['HTTPS']` auf all-inkl.** Nicht geprüft. Fehlt der Wert, verweigert der Admin jede Anfrage (sichere Richtung). Beim ersten Upload prüfen.
- **C4. `X-Forwarded-Proto` gilt von jedem Absender** bei `LK_TRUST_PROXY=1`; Folge wäre nur, dass eine HTTP-Anfrage als HTTPS gilt, die Cookies bleiben `Secure`. Unkritisch, solange der Port nur lokal gebunden ist (`compose.yaml`: `127.0.0.1:8080`).
- **C5. `http://` in `LK_BASE_URL` ohne Warnung.** Ein Tippfehler in Produktion ergäbe Cookies ohne `Secure` und `__Host-`. Vorschlag: Warnband, wenn der Host nicht `localhost`/`127.0.0.1` ist.
- **C6. Konfigurationsfehlerseite für Unangemeldete** nennt den Grund (z. B. „Im Webroot liegt eine .env-Datei“). Keine Geheimnisse, aber ein Hinweis. Ohne SSH braucht Lutz die Meldung; Abwägung offen.
- **C7. Schlüsselrotation** (noch nicht fertig gebaut): Wiederherstellungscodes, Sitzungen und offene Einladungen werden nach einer Rotation ohne Hinweis ungültig (seit A6 auch Sitzungen und Einladungen); die Kettenprüfung zeigt dauerhaft „Lücke“, sobald `LK_APP_KEY_PREVIOUS` entfernt wird. Vor der ersten echten Rotation klären, am besten in Schritt 3 als Umschlüsselungslauf.
- **C8. Protokollzeilen je Fehlversuch bei bekanntem Namen**, durch Zähler auf grob 130 je 15 Minuten und Name begrenzt; bei Dauerangriff wächst `audit_log` trotzdem, gekürzt wird nie. Aufbewahrung ist Frage 6 des Entwurfs.
- **C9. Administrator setzt anderen Administrator zurück** und erhält dessen Link: Übernahme möglich, liegt im Entwurf (gleiche Rechte), steht im Protokoll. Zur Kenntnis.
- **C10. Docker bei falscher `LK_TRUSTED_PROXIES`:** alle Besucher teilen die Proxy-Adresse, 30 Fehlversuche sperren alle. Sichere Richtung, auf pve10 mit echtem Proxy testen.

## 4. Entscheidung zu A2 (Lutz, 15.11.2026)

Zur Wahl standen (1) nur zählen und melden oder (2) ein Geräte-Cookie `__Host-lk_dev` nach erfolgreicher Anmeldung, das die Regel `user` überspringt. **Lutz hat Variante 1 gewählt**, umgesetzt wie unter A2 beschrieben. Raten bleibt durch Name+Adresse (10/15 min → Sperre), Adresse (30/15 min), globale Bremse (20 Hash-Prüfungen/10 s) und TOTP begrenzt.

## 5. Geprüft und unauffällig

**Wettläufe (echte parallele PHP-Prozesse):** 20 parallele Logins, gleicher Name und Adresse → 3 Hash-Prüfungen, 17 abgewiesen. 40 parallele Logins mit je eigenem Namen und Adresse → genau 20 durch die globale Bremse. Dieselbe `password_ok`-Sitzung mit demselben TOTP-Code 6-mal → 1-mal ok, eine volle Sitzung, alte Zeile weg. 36 parallele falsche Codes über 3 Sitzungen → 10 geprüft. Ersteinrichtung mit 2 Zwischenzeilen, je 3-mal bestätigt → 1 Konto, 1 Sitzung, Kette ok. Einladung mit 3 Zwischenzeilen gleichzeitig → 1 Konto, Einladung verbraucht. Notfall-Token 4-mal gleichzeitig → 1 Link, 1 Einladung, 1 Protokolleintrag. 8 Prozesse mit je 25 Protokolleinträgen → Kette unversehrt. Zähler werden vor dem Hash gebucht; kein Argon2-Lauf liegt in einer Transaktion; `tx()` rollt bei Ausnahme zurück.

**Gleichbehandlung:** Antwort, Status und Zähler für unbekannt, deaktiviert und ohne TOTP gleich; `release` entfernt nur eigene Buchungen; rohe Namen stehen nirgends. Einzige Ausnahme war die Zeit (A1, behoben).

**Sitzung und Cookies:** ID-Wechsel bei beiden Stufen mit Löschen der alten Zeile; mitgeschicktes altes Cookie wird nie übernommen; `__Host-`, `Secure`, `HttpOnly`, `SameSite=Strict`, Pfad `/`, nach `LK_BASE_URL` entschieden, unabhängig von `LK_ENV`; HTTP-Anfrage bei `https://`-Konfiguration auf allen Admin-Routen abgewiesen; zentrale Stufenprüfung mit fester Ausnahmeliste; deaktivierte und TOTP-lose Konten verlieren die Sitzung sofort; Leerlauf, absolute Dauer, 5 Minuten `password_ok`.

**CSRF und Weiterleitung:** Token vor der Anmeldung an `r` aus `__Host-lk_pre` gebunden, fremdes Token abgelehnt; Sitzungs-CSRF mit `hash_equals` auf allen `POST`-Routen; `safeNext` lässt nur `/admin…` zu, ohne `//`, `\` oder Steuerzeichen; keine Kopfzeilen-Injektion gefunden.

**Einrichtung, Einladung, Rücksetzung, Notfall:** Zwischenzeile mit eigenem Cookie und verschlüsseltem Geheimnis (Zusatzdaten `pending:<id>`), ohne sie 404, `POST` 404 statt 405; Konto, `setup_done_at` und Einladungsverbrauch in einer Transaktion; Token nur als Hash; Notfall-Token nach Verbrauch 404; Rücksetzung entwertet TOTP, Codes und Sitzungen sofort; Schutz des letzten Administrators in derselben Transaktion.

**Protokoll:** Trigger gegen `UPDATE`/`DELETE`, HMAC-Kette in `BEGIN IMMEDIATE`, festes Vokabular mit Prüfmuster, `login_failures` getrennt, Redaktion sieht nur eigene Ereignisse.

**Krypto:** XChaCha20-Poly1305 mit Zusatzdaten `users:totp:<id>`, HKDF-Teilschlüssel, Kennung aus HKDF, Startprüfung, `sodium`/`intl`/`pdo_sqlite` Pflicht, TOTP nach RFC mit `hash_equals` und atomarem `UPDATE … WHERE totp_last_step < ?`.

**Sonstiges:** SQL durchgehend mit Parametern; Templates escapen alles, QR und Siegel inline ohne `style`; CSP ohne Inline, `no-store`, `no-referrer`; Fehlerseiten nur im Entwicklungsmodus mit Meldung, nie mit Aufrufliste; keine Geheimnisse im Repo, `.env.example` mit leeren Werten.

**Betriebsarten:** Datenordner im Webroot abgewiesen (Ausnahme mit Warnband); `.env` im Webroot wird nicht gelesen; maßgeblich nur `REMOTE_ADDR`, `mod_remoteip` nur mit `LK_TRUSTED_PROXIES`; `compose.yaml` bindet `127.0.0.1:8080`; `LK_TRUST_PROXY` ohne Proxy-Liste ist ein gemeldeter Fehler; bei `X-Forwarded-Proto` zählt der letzte Eintrag. Nicht geprüft: das Docker-Image der App zur Laufzeit (`mod_remoteip`), der all-inkl-Testserver.

## 6. Nächste Schritte

1. Upload auf `lichtkammer.br-foto.com` nach Freigabe; dort C2 (PHP-Stand) und C3 (`HTTPS`-Variable) prüfen, Zeit der globalen Bremse messen.
2. Für Schritt 3 vormerken: B3 (echte Parallel-Tests), C1 (Aufräumen gedrosselt), C5 (Warnband `http://`), C7 (Umschlüsselungslauf bei Rotation).
