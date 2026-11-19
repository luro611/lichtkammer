# Prüfung Code Bildvarianten, Behebung B1 und B2 (Opus)

Stand: 19.11.2026. Geprüft: Commit `384ace0` (Sperre `variants_lock`, „in Arbeit“ `variants_inflight`, N2 bis N4) gegen Bericht `36`.
Tests: **1 522 Prüfungen, 0 Fehler**. Es wurde kein Code geändert. Nichts hochgeladen, nichts am Server geändert.

## Ergebnis in Kürze

B1 und B2 sind richtig behoben. **Kein Befund mit Risiko hoch oder mittel.** Es bleiben vier Kleinigkeiten (niedrig), keine davon hält den Livegang oder Probe B auf. Eine zweite Prüfrunde ist nicht nötig.

## Was ich geprüft habe

| Frage | Ergebnis |
|---|---|
| **Sperre atomar?** | Ja. Ein einziger SQL-Befehl (`INSERT … ON CONFLICT DO UPDATE … WHERE alter Wert <= jetzt − 90`), Erfolg am `rowCount`. Nicht „lesen, dann schreiben“. **Messung:** 6 PHP-Prozesse gleichzeitig, 100 Runden um dieselbe Sperre: genau 100 Gewinner (einer je Runde, nie zwei). |
| **Sperre hängt nicht fest?** | `finally` gibt Sperre und „in Arbeit“ frei (auch bei Ausnahme). Bei hartem Abbruch (Zeitgrenze, Speicher) greift `finally` nicht; dann läuft die Sperre nach 90 s ab (Test: bei 89 s noch gesperrt, bei 91 s arbeitet der nächste Aufruf). Das Skript wartet bis zu 40 × 3 s = 120 s, das reicht über die 90 s. |
| **Zeit, Zeitzonen** | `Clock::now()` ist `time()` (Unix-Sekunden, keine Zeitzone). Nur eine Uhr beteiligt. Ein Rückwärtssprung der Serveruhr würde die Sperre bis zum Ablauf verlängern, praktisch ohne Bedeutung. |
| **„In Arbeit“ bei Neustart und beiden Lauf-Arten** | Ein neuer Durchgang (`start`) löscht den Rest. Wiederholversuch des Beginns (`steps = 0`, gleiche Wahl) behält ihn und behandelt ihn (Test). Bei „Erzwingen“ bleiben gute alte Varianten gültig, das Foto wird nur gezählt (Test N4). Ein Rest hinter dem Zeiger (`pid > cursor`) wird nie doppelt gezählt. |
| **Foto nie dauerhaft übersprungen?** | Ein Giftfoto wird nur in diesem Durchgang als fehlgeschlagen markiert. Der nächste normale Lauf versucht es wieder (Test „versucht es erneut und schafft es“). Es bleibt in der Fehlerliste und im Warnband. |
| **Fortsetzen nach Abbruch** | Zeiger wird nach jedem Foto gesichert; Zähler `steps`/Wiederholversuch ändern daran nichts. Probe A zeigt es durchgehend. |
| **Rechte, CSRF, Protokoll** | Im Diff keine Änderung an Routen, Rolle oder CSRF-Prüfung. `variants_start` loggt nur `force=…,photos=N`; bei `busy` wird nichts protokolliert (Test). |
| **`varianten.js`** | Bei `busy` Warten 3 s, höchstens 40 Mal, dann Meldung und Knopf frei (keine Endlosschleife). `neu`/`erzwingen` bleiben bei Wiederholen erhalten, `waits` zählt nur Warteschleifen. Anzeige per `textContent`. Wartetext erscheint (Test prüft Text und Skript). |
| **Probe A und C** | `tools/restore-probe.sh` mit frischer Test-Sicherung: **25 von 25 bestanden**, längste Runde 4,7 s. Probe-Container danach entfernt. |
| **Zwei Tabs (HTTP statt Browser)** | Der eingebaute Browser durfte `127.0.0.1:20637` nicht öffnen. Ersatz: zwei Aufrufe mit derselben Sitzung gleichzeitig (`?neu=1&erzwingen=1`). Ergebnis: Aufruf 1 arbeitet (4 Fotos), Aufruf 2 bekommt `{"busy":true,"handled":0}`, nichts wird bearbeitet oder zurückgesetzt. Die Oberfläche selbst (Wartetext, Neuladen) ist damit **nicht im Browser gesehen**, sondern nur über Tests und Skripttext belegt. |

## Befunde (alle niedrig, wiederholbar, nichts geht kaputt)

| Nr. | Risiko | Befund |
|---|---|---|
| N1 | niedrig | Sperre (90 s) ist kürzer als das Zeitlimit eines Fotos (`set_time_limit(120)` in `Library::process`). Dauert ein Foto wirklich 90 bis 120 s und der Server lässt es zu (Docker, nicht all-inkl), nimmt ein zweiter Aufruf die Sperre, markiert das noch laufende Foto als „Zeitüberschreitung“ und arbeitet weiter; der erste löscht danach in `finally` die fremde Sperre und setzt den Zeiger zurück. Folge: doppelte Rechenzeit, überschriebene Zähler, ein Foto zu Unrecht als fehlgeschlagen (nächster Lauf holt es nach). Keine halben Dateien (Temp-Datei plus `rename`). Behebung bei Bedarf: Sperre mit Besitzzeichen und nur dann freigeben, oder TTL auf ca. 130 s und Wartegrenze im Skript anheben. Auf all-inkl (Abbruch deutlich früher) praktisch nicht erreichbar. |
| N2 | niedrig | Die Zeit in `variants_inflight` wird gespeichert, aber nie gelesen; die Altersprüfung geschieht allein über die Sperre (gleichwertig, weil beide zusammen geschrieben werden). Nur der Kommentar im Code redet von „Zeit älter als“. Kosmetik. |
| N3 | niedrig | Wartet ein zweiter Tab mit „Neu beginnen“, kann er in der kurzen Lücke zwischen zwei Runden des ersten die Sperre bekommen und dessen Durchgang von vorn beginnen. Das ist, was die Person dort angeklickt hat (mit Bestätigungsfrage); gültige Varianten werden übersprungen. Nur „Erzwingen“ rechnet dann bis zu 20 s doppelt. |
| N4 | niedrig | Geht die Antwort der allerersten Runde verloren, obwohl der Server sie schon gearbeitet hat (`steps = 1`), beginnt der Wiederholversuch (mit `neu`) wirklich neu. Bei „Erzwingen“ ist das höchstens eine Runde doppelte Arbeit. |

## Nicht geprüft

Echte Messung auf all-inkl (Probe B) und das Verhalten bei hartem Abbruch durch den Server; der Abbruch ist nur über Test mit künstlichem „in Arbeit“-Eintrag belegt.

## Ablauf wiederholen

Tests: `docker run --rm -v "$PWD":/app -w /app lichtkammer-test php tests/run.php`. Probe: `tests/erzeuge_testsicherung.php` und `tools/restore-probe.sh --probe ac …` (siehe `docs/betrieb/Wiederherstellung.md`).
