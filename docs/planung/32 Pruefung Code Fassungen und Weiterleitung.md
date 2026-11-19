# Prüfung 32: Code „20 Fassungen“ (R1) und „Weiterleitung bei Adressänderung“ (R3)

Stand: 19.11.2026. Prüfer: Claude Opus 5.5. Geprüft: Commit `73a872c` (Migration 11 `slug_history`, `Builder::KEEP`/`KEEP_BACKUPS` = 20, Weiterleitungen in `Renderer::redirects()`, Hinweise in den Formularen und auf „Veröffentlichen“). Maßstab: Bericht 31 Abschnitt 3 (R1, R3), Entwurf 13 (Weiterleitungen des Importers), Bericht 21 M2 (Rückfallzeit) und der Auftrag an den Bau. **Nichts hochgeladen, nichts am Server geändert.**

**Ergebnis:** Der Bau erfüllt R1 und R3. Die Migration ist harmlos für vorhandene Daten. Ketten, Schleifen in den Daten, Vorrang einer neuen Seite, reservierte Adressen, Entwurf, Löschen und die Zusammenführung mit den Import-Weiterleitungen stimmen und sind getestet. **Ein Befund mit Risiko mittel** (Schleife bzw. unerreichbare Seite im Browser, sobald `LK_REDIRECTS_PERMANENT=1` gesetzt ist) ist behoben. Freigabe für den Testserver aus meiner Sicht: ja.

## 1. Wie geprüft

- Code gelesen: `Auth/Schema.php` (Migration 11 und `migrate()`), `Site/Pages.php`, `Media/Galleries.php`, `Site/Renderer.php` (`redirects()`), `Site/Builder.php` (`plan()`, `prune()`, `backupDatabase()`, `rollback()`), `Site/Server.php` (`redirectFor()`), `Backup/Restore.php`, die drei Vorlagen, `tests/weiterleitung.php`.
- Tests in Docker: vorher **1 320**, nachher **1 324 Prüfungen, 0 Fehler** (`docker run --rm -v "$PWD":/app -w /app lichtkammer-test php tests/run.php`).
- Durchgängig lokal (Docker, `php -S` mit `tests/devrouter.php`, eigene Datenbank in `/tmp`), je einmal mit `LK_REDIRECTS_PERMANENT=0` und `=1`: Seite „Über Uns“ online (Adresse `ueber-uns`), veröffentlicht; Adresse auf `Ueber-Mich` geändert, Plan zeigt `/ueber-uns/ → /ueber-mich/`, veröffentlicht; dann zurück auf `ueber-uns` (A→B→A). Mit curl:
  - `/ueber-uns/` nach der Änderung: **301 → `/ueber-mich/`**, auch `/UEBER-UNS` ohne Schrägstrich;
  - nach der Rücknahme: `/ueber-uns/` **200**, `/ueber-mich/` **301 → `/ueber-uns/`**, keine Schleife;
  - `/_redirects.json`, `/_redirects_auto.json`, `/ueber-mich//evil.com`: **404** (keine offene Weiterleitung, Steuerdateien nicht abrufbar);
  - im eingebauten Browser: `http://localhost:8091/ueber-mich/` landet auf „Über uns“ unter `/ueber-uns/`.

## 2. Befunde und Behebung

**B1 – Mit `LK_REDIRECTS_PERMANENT=1` merken sich Browser umkehrbare Weiterleitungen für immer (Risiko mittel, behoben).**
Die automatischen Weiterleitungen liefen über denselben Weg wie die Import-Weiterleitungen. Nach der Rückfallzeit (Bericht 21, M2) wird `LK_REDIRECTS_PERMANENT=1` gesetzt, dann gibt es 301 **ohne Ablaufzeit**, und der Browser fragt nie wieder nach. Für WordPress-Adressen ist das richtig, für Adressänderungen nicht, denn die sind umkehrbar:
- A→B→A: Ein Besucher hat sich `/a → /b` gemerkt. Nach der Rücknahme leitet `/b` auf `/a`, der Browser springt aus seinem Speicher wieder auf `/b` → **Schleife** („zu viele Weiterleitungen“).
- Neue Seite unter einer früher benutzten Adresse: Wer sich `/team → /about` gemerkt hat, sieht die neue Seite `/team` **nie**.
*Wiederholbar:* immer, sobald `LK_REDIRECTS_PERMANENT=1` gesetzt ist und eine Adresse zurückgenommen oder neu vergeben wird; im Test nachgestellt (vor der Behebung kam für `/umbenannt` kein `Cache-Control`).
*Behebung:* Der Bau schreibt zusätzlich `_redirects_auto.json` (Liste der alten Adressen, die aus einer Adressänderung stammen; gewinnt ein Importeintrag, steht die Adresse nicht darin). `Server` schickt für diese Adressen **immer** `Cache-Control: public, max-age=3600`, auch mit `LK_REDIRECTS_PERMANENT=1`. Import-Weiterleitungen bleiben unverändert. Statuscode bleibt 301 (Suchmaschinen übernehmen die neue Adresse), Browser fragen nach höchstens einer Stunde neu. Ältere Fassungen ohne die Datei verhalten sich wie bisher. Tests in `tests/haertung.php` und `tests/weiterleitung.php`.

**B2 – Hinweis im Galerie-Formular galt auch für Entwürfe (Risiko niedrig, behoben).**
Dort stand ohne Einschränkung „Die alte Adresse leitet … weiter“; bei einer Galerie im Entwurf entsteht aber (richtig) keine Weiterleitung. Neu: „Steht die Galerie online, gilt: Die alte Adresse leitet nach dem Veröffentlichen automatisch hierher weiter.“ (wie bei Seiten).

## 3. Geprüft und in Ordnung

**Migration 11 (Schwerpunkt 1).** Nur `CREATE TABLE slug_history` und ein Index, keine Änderung bestehender Tabellen oder Spalten, keine Daten werden umgeschrieben. Läuft wie alle Migrationen in einer Transaktion mit Versionsprüfung (`schema_version`), also wiederholbar und sicher bei zwei gleichzeitigen Anfragen. Auf einer Datenbank mit Daten (Testserver-Stand) startet die Tabelle leer, es entstehen keine Weiterleitungen, bis jemand eine Adresse ändert. Rollback: alter Code ignoriert die Tabelle (`migrate()` bricht bei höherer Version ab, ohne Fehler). Einschränkung (niedrig, nur zur Kenntnis): Eine Sicherung, die **nach** dem Update gemacht wurde, lässt sich mit dem **alten** Code nicht einspielen (`Restore` lehnt neuere Schemata ab, mit klarer Meldung). Vor dem Update also wie üblich eine Sicherung ziehen.

**Weiterleitungslogik (Schwerpunkt 2).**
- Ziel ist immer die **aktuelle** Adresse über die Kennung (`target_id`), daher keine Ketten; A→B→C ergibt `/a → /c` und `/b → /c`.
- A→B→A: die zurückgeholte Adresse wird aus dem Verlauf gelöscht (`claimSlug`), keine Schleife in den Daten (Test, curl).
- Kollision: Eine Adresse, unter der es in dieser Fassung eine Seite oder Galerie gibt, wird nie umgeleitet (`isset($files[…])`); reservierte Adressen (`Pages::RESERVED`, `galerie`) nie. Seiten-Kennungen sind `AUTOINCREMENT`, werden also nicht neu vergeben; das Löschen des Verlaufs beim Löschen ist zusätzliche Vorsicht.
- Groß-/Kleinschreibung und Schrägstrich: Adressen entstehen über `slugify()` (Kleinbuchstaben, Umlaute als ae/oe/ue/ss), `Server` vergleicht klein und ohne Schrägstrich am Ende.
- Offene Weiterleitung: Ziel kommt nur aus der eigenen Datei, wird beim Ausliefern gegen `^/[A-Za-z0-9_/.\-]*$`, `//` und `..` geprüft; die Anfrage-Adresse vorher ebenso. Kein Host aus Eingaben.
- Import: Die Import-Weiterleitungen werden nach dem Verlauf eingetragen und gewinnen bei gleicher Adresse (wie im Auftrag); ihr eigenes Verhalten ist unverändert (`tests/import.php` grün).
- Größe: eine Zeile je Umbenennung, `_redirects.json` bleibt klein; keine Grenze nötig.

**Löschen, Entwurf (Schwerpunkt 3).** Seiten und Galerien haben keinen Papierkorb (nur Fotos); Löschen entfernt den Verlauf. Eine online stehende Seite auf Entwurf: ihre alten Adressen leiten nicht ins Leere (Ziel fehlt in der Fassung → keine Weiterleitung, 404 wie in Entwurf 13); wieder online → Weiterleitungen sind wieder da (Test).

**Fassungen (Schwerpunkt 4).** `prune()` behält die 20 neuesten und immer die aktive, auch wenn sie älter ist. Rollback-Liste neueste zuerst mit Datum und Uhrzeit. Aufräumen läuft unter der Bau-Sperre, `rollback()` nimmt dieselbe Sperre. Platz: je Fassung nur HTML (lokal wenige MB), dazu 20 Datenbankkopien; die Datenbank ist klein (keine Bilder), all-inkl hat genug Platz.

**Texte (Schwerpunkt 5).** Hinweis bei der Seite: „Wird die Adresse einer online stehenden Seite geändert, leitet die alte Adresse nach dem Veröffentlichen automatisch hierher weiter.“ Auf „Veröffentlichen“: Kasten „Neue Weiterleitungen“ mit höchstens 20 Zeilen `alt → neu`. Verständlich.

## 4. Kleinigkeiten, offen (Risiko niedrig, keine Änderung nötig)

- **N1:** Bekommt eine neue Seite eine früher benutzte Adresse und wird später wieder gelöscht, kommt die alte Weiterleitung nicht zurück (Verlauf wurde beim Übernehmen gelöscht). Folge: 404 statt Weiterleitung, selten.
- **N2:** Wird eine Seite umbenannt, die in der Datenbank „online“ ist, aber noch nie veröffentlicht wurde, entsteht eine Weiterleitung von einer Adresse, die es nie gab. Harmlos.
- **N3:** `Pages::delete()` und `Galleries::delete()` löschen Seite und Verlauf in zwei Schritten ohne Transaktion. Scheitert der zweite, bleibt ein Verlaufseintrag ohne Ziel, der beim Bau übersprungen wird. Harmlos.
- **N4:** Der Test „Aufräumen lässt die aktive (auch ältere) Fassung stehen“ prüft den Fall nur mittelbar (die zurückgeholte Fassung liegt noch unter den 20). Das Verhalten stimmt laut Code; ein schärferer Test wäre: zurückholen, dann über 20 Fassungen per Dateisystem anlegen und `prune()` rufen.

## 5. Geänderte Dateien

`app/src/Site/Renderer.php` (`autoRedirects`, `_redirects_auto.json`), `app/src/Site/Server.php` (`isAutoRedirect()`), `app/templates/admin/gallery.php` (Hinweis), `tests/haertung.php`, `tests/weiterleitung.php`.

## 6. Nächster Schritt

Hochladen auf den Testserver nach Freigabe von Lutz (mit Probelauf, vorher Datenbanksicherung wegen Migration 11). Danach im Browser: Adresse einer online stehenden Seite ändern, „Speichern und veröffentlichen“, alte Adresse aufrufen.
