# Prüfung Code: Admin-Navigation und Designs (Commit 8d14dc8)

Stand 18.11.2026, Opus. Geprüft: Seitenleiste, neue Routen, Liste „Alle Seiten“, vier Designs (CSS, design.js, Kontrast), Tests. Ergebnis: **abgenommen**, ein kleiner Befund behoben. Tests: 1 210 Prüfungen, 0 Fehler. `tools/kontrast.py`: 0 Fehler.

## Ergebnis in einfachen Worten

- **Routen:** `/admin/seiten/neu` steht vor `/admin/seiten/{id}`, es gibt keine Verwechslung. Beide neuen Seiten sind nur mit Anmeldung erreichbar (Test). Verlinken und Bewegen sind weiter POST mit CSRF-Prüfung und gehen jetzt zurück auf `/admin/menue`. Rechte und Datenbank unverändert.
- **Alle Seiten:** liest `Pages::all()` ohne Filter. Es fehlt keine Seite (Test mit Impressum, Datenschutz und weiteren).
- **Designs:** Die Farbsätze stimmen. Alte Werte `lurohome`/`rothe` werden zu `warm`/`klar`, Unbekanntes zu `warm`. Hell/dunkel und die Einstellung des Systems (prefers-color-scheme) sind für alle vier Designs abgedeckt. Kontrast AA bestanden.
- **Befund B1 (klein, behoben):** Wenn beim Anlegen einer Seite ein Fehler kommt (leerer Titel), war in der Seitenleiste „Alle Seiten“ markiert statt „Neue Seite“. Jetzt richtig, Test ergänzt.

## Hinweise (nicht behoben, kein Risiko)

1. Der Kontrast-Test in `tests/design.php` gilt als bestanden, wenn kein Python da ist (im Test-Image ist keines). Er läuft also nur auf dem Mac. Vor Änderungen an Farben `python3 tools/kontrast.py` von Hand starten.
2. `tools/kontrast.py` prüft nur die Blöcke mit Marker (`/*@set …*/`). Die Kopien für die Systemeinstellung „dunkel“ prüft es nicht, sie haben aber dieselben Werte. Bei Farbänderungen beide Stellen anpassen.
3. `/admin/menue` ist in der Liste der Pfade, die „ungespeicherte Änderung“ melden, aber nur Verlinken und Bewegen (unter `/admin/seiten/…`) schreiben. Der Eintrag ist harmlos.
4. Nicht im Browser angesehen: das Aussehen der vier Designs (Abstände, Umschalter mit vier Knöpfen auf schmalem Bildschirm). Das sollte Lutz einmal ansehen.
