# Entwurf Schritt 4: Bildverwaltung

Stand: 15.11.2026. Entscheidungen E1 bis E7 von Lutz wie empfohlen freigegeben, gebaut (Abschnitt 6). Grundlage: Plan `03` Abschnitte 5, 7, 11 und Schritt 3 (`09`). Risiko laut Plan „mittel“; es gibt aber eine Migration auf Bestandsdaten, deshalb zuerst dieser Entwurf. Die öffentliche Seite (Schritt 5) ist nicht Teil davon.

## 1. Wie Fotos auf die Seite kommen (Kernfrage)

**Begriffe.** Die *Mediathek* ist die Menge aller Fotos (Tabelle `photos`, Upload wie in Schritt 3). Eine *Galerie* ist eine geordnete Auswahl daraus. Ein Foto ohne Galerie liegt nur in der Mediathek und erscheint nirgends öffentlich.

**Zuordnung (Entscheidung E1): ein Foto kann in mehreren Galerien liegen.** Neue Verknüpfungstabelle `gallery_photos(gallery_id, photo_id, position)`. Jede Galerie hat damit ihre eigene Reihenfolge. Titel, Alt-Text, Ort usw. gehören zum Foto und gelten überall. Vorteil: „Beste von Island“ neben „Island 2022“ ohne Kopie des Bildes. Die bisherige Spalte `photos.gallery_id` (1:n) wird in die neue Tabelle übernommen und danach nicht mehr benutzt (bleibt in der Datenbank stehen, nichts wird gelöscht).

**Reihenfolge.** Pro Galerie `position` (0, 1, 2 …). Ziehen im Browser schickt die neue Reihenfolge der sichtbaren Fotos; der Server schreibt sie in einer Transaktion. Zusätzlich zwei Knöpfe „Jetzt sortieren nach Aufnahmedatum (alt → neu / neu → alt)“ und „nach Dateiname“. Sie schreiben die Positionen einmalig neu (kein dauerhafter Modus); danach lässt sich weiter von Hand ziehen. Neue Fotos kommen ans Ende. Fotos ohne Aufnahmedatum stehen bei Datumssortierung am Ende.

**Reihenfolge der Galerien.** `galleries.sort_order`, ebenfalls per Ziehen. Das ist die Reihenfolge der Kacheln auf Start und Galerienübersicht.

**Titelbild.** `galleries.cover_photo_id`, per Klick auf ein Foto der Galerie („Als Titelbild“). Nur ein Foto, das in der Galerie liegt, ist erlaubt; wird es aus der Galerie entfernt oder gelöscht, fällt das Titelbild automatisch auf das erste Foto zurück. Ohne gesetztes Titelbild gilt das erste Foto.

**Sichtbarkeit.** Eine Galerie ist `draft` oder `published`. Ein Foto ist öffentlich genau dann, wenn es in mindestens einer veröffentlichten Galerie liegt und nicht im Papierkorb ist. In Schritt 4 ist „veröffentlicht“ nur die Markierung; erzeugt wird die Seite erst mit dem Knopf „Veröffentlichen“ in Schritt 5.

**Alt-Text-Pflicht.** Eine Galerie lässt sich nur auf „veröffentlicht“ stellen, wenn alle ihre Fotos (Status `ready`, nicht im Papierkorb) einen Alt-Text haben. Sonst zeigt der Admin die Liste der fehlenden mit Sprung zum Foto. Bereits veröffentlichte Galerien werden nicht rückwirkend gesperrt; fehlende Alt-Texte zeigt dann die Übersicht als Hinweis.

**Raster (Justified) in der späteren Galerieansicht.** Braucht von Schritt 4 nichts außer dem, was schon da ist: `width`, `height` (Seitenverhältnis) und `position`. Die Zeilen werden in Schritt 5 beim Veröffentlichen berechnet. Drehen und Fokuspunkt (Spalten `rotation`, `focus_x`, `focus_y`) verändern das Seitenverhältnis bzw. den Kachelausschnitt; deshalb siehe E5.

## 2. Umfang Schritt 4

1. **Galerien** (`/admin/galerien`): Liste mit Titelbild-Kachel, Anzahl, Status; anlegen, umbenennen, beschreiben, Entwurf/veröffentlicht, EXIF-öffentlich ja/nein (`show_exif`, Spalte besteht), Reihenfolge ziehen, löschen. Löschen einer Galerie löscht nie Fotos: die Verknüpfungen entfallen, die Fotos bleiben in der Mediathek (mit Rückfrage und Zahl der betroffenen Fotos). Adresse (`slug`) wird aus dem Titel gebildet (Umlaute → ae/oe/ue/ss), eindeutig; bei Umbenennung bleibt sie stehen, bis man sie bewusst ändert.
2. **Galerieseite** (`/admin/galerien/<id>`): Raster der Fotos mit Ziehen, Titelbild-Klick, „aus Galerie entfernen“, Sortierknöpfe, Fotos hinzufügen aus der Mediathek (Auswahlfenster mit Suche) oder neu hochladen direkt in diese Galerie (Upload kennt `gallery_id` bereits).
3. **Mediathek** (`/admin/fotos`, bestehende Seite wird erweitert): alle Fotos, Filter (ohne Galerie, Alt-Text fehlt, Fotograf, Jahr, Fehler), **Suche** über Titel, Bildunterschrift, Ort, Jahr, Dateiname und Galerietitel (SQL `LIKE`; SQLite-FTS5 wird nicht vorausgesetzt, da auf all-inkl ungeprüft; bei einigen tausend Fotos reicht `LIKE`).
4. **Foto bearbeiten** (`/admin/fotos/<id>`): Titel, Bildunterschrift, Alt-Text, Fotograf, Ort, Jahr, Galerien-Zugehörigkeit, Vorschau, EXIF-Anzeige (nur lesen). Speichern per Formular (kein Skript nötig, Ziehen und Mehrfachauswahl sind Verbesserung durch Skript).
5. **Mehrfachbearbeitung:** Fotos in Mediathek oder Galerie ankreuzen, dann „Bearbeiten“: Feld je Zeile mit Häkchen „setzen“; nur angehakte Felder werden überschrieben (Fotograf, Ort, Jahr, Bildunterschrift, Alt-Text, Galerie hinzufügen/entfernen). Titel/Alt-Text werden bewusst nicht für viele gleich vorbelegt, sind aber erlaubt (z. B. Serie). Eine Vorschau „betrifft N Fotos“ vor dem Speichern.
6. **Papierkorb** (`/admin/papierkorb`): „Löschen“ setzt `deleted_at`; Fotos verschwinden aus allen Galerien und der Mediathek (Verknüpfungen bleiben bestehen, damit „Wiederherstellen“ alles an den alten Platz zurückbringt). Nach 30 Tagen wird endgültig gelöscht (Datenbankzeile, Varianten **und** Original); dazu E4. „Jetzt endgültig löschen“ gibt es einzeln mit Rückfrage. Die Dublettenprüfung meldet weiter auch Fotos im Papierkorb (so im Schritt 3).
7. **Fotografen** (E3): Liste mit genau den Personen, die Fotos aufgenommen haben können; im Foto wählbar. 
8. **Protokoll:** neue Ereignisse `gallery_saved`, `gallery_deleted`, `photos_saved`, `photos_trashed`, `photos_restored`, `photos_purged`, `gallery_published`; im Detail nur Nummern (wie bisher, kein Freitext).

Rechte: beide Konten dürfen alles in der Bildverwaltung (Rollenunterschied bleibt bei Konten/Einladungen). Alle Änderungen sind POST mit CSRF-Schutz, Eingaben werden gekürzt und geprüft (Längen: Titel 200, Bildunterschrift 1000, Alt-Text 500, Ort 120, Jahr 1800–2100).

## 3. Migration 3 (Schema-Version 3)

Nur additiv, nichts wird gelöscht oder verändert. Läuft in einer Transaktion (wie bisher in `Schema::migrate`).

```sql
CREATE TABLE gallery_photos (
    gallery_id INTEGER NOT NULL REFERENCES galleries(id) ON DELETE CASCADE,
    photo_id   INTEGER NOT NULL REFERENCES photos(id)    ON DELETE CASCADE,
    position   INTEGER NOT NULL DEFAULT 0,
    added_at   INTEGER NOT NULL,
    PRIMARY KEY (gallery_id, photo_id)
);
CREATE INDEX gallery_photos_order ON gallery_photos(gallery_id, position);
CREATE INDEX gallery_photos_photo ON gallery_photos(photo_id);

-- Bestand übernehmen: bisherige 1:n-Zuordnung
INSERT INTO gallery_photos(gallery_id, photo_id, position, added_at)
SELECT gallery_id, id, sort_order, created_at FROM photos WHERE gallery_id IS NOT NULL;

CREATE TABLE photographers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    sort_order INTEGER NOT NULL DEFAULT 0
);
ALTER TABLE photos ADD COLUMN photographer_id INTEGER REFERENCES photographers(id) ON DELETE SET NULL;
-- bereits eingetragener Freitext in photos.photographer wird in photographers übernommen und verknüpft

CREATE INDEX photos_deleted ON photos(deleted_at);
CREATE INDEX photos_year ON photos(year);
```

- Titelbild (`galleries.cover_photo_id`) und Reihenfolge (`galleries.sort_order`) bestehen schon. Die alten Spalten `photos.gallery_id`, `photos.sort_order`, `photos.photographer` bleiben ungenutzt stehen (Rückweg ohne Datenverlust; Aufräumen in einer späteren Migration, falls je gewünscht).
- Vor der Migration legt der Admin wie bei Migration 2 eine Datenbankkopie ab (sofern die Migrationsroutine das schon tut, sonst ergänze ich das und teste es). Tests: Migration auf Datenbank mit Fotos in Galerie, doppelter Lauf, Fremdschlüssel.

## 4. Entscheidungen für Lutz

| Nr. | Frage | Empfehlung |
|---|---|---|
| E1 | Ein Foto in **mehreren Galerien**? | **Ja** (Verknüpfungstabelle). Mehr Aufwand ist gering; spätere Umstellung von 1:n auf n:m wäre viel teurer. Ein Foto hat dann pro Galerie eine eigene Position. |
| E2 | Automatisches Sortieren: einmaliger Knopf oder dauerhafter Modus je Galerie? | **Einmaliger Knopf**, danach frei ziehbar. Ein dauerhafter Modus würde Ziehen und neue Fotos verkomplizieren. |
| E3 | Fotograf: feste Liste (`photographers`, im Admin pflegbar) oder Freitext? | **Feste Liste** (Plan: „eine der beiden Personen“); verhindert Schreibfehler, nötig für Filter auf der Seite. Namen trägst du im Admin ein (Schreibweise offen, siehe Plan 12.5). |
| E4 | Papierkorb: nach 30 Tagen **Original wirklich löschen**? | **Ja**, aber nur beim Aufruf von Papierkorb/Fotos (kein Cron), mit Protokolleintrag; Originale sind sonst unantastbar. Alternativ nie automatisch, nur per Knopf. Zusätzlich: Beim Löschen werden die **Varianten sofort entfernt** (nicht mehr öffentlich abrufbar), beim Wiederherstellen neu erzeugt. |
| E5 | **Drehen, Fokuspunkt, „Foto ersetzen“** aus Plan 7 (Kern): in Schritt 4 oder später? | **Später (Schritt 4b nach Schritt 5)**: Sie beeinflussen die Seitenverhältnisse der Varianten und das Raster; sinnvoll erst, wenn man das Ergebnis in der echten Galerie sieht. Alle Spalten sind vorhanden. Wenn du sie jetzt willst, wird Schritt 4 deutlich größer. |
| E6 | Veröffentlichen einer Galerie nur mit **vollständigen Alt-Texten** (hart gesperrt)? | **Ja**, wie im Plan, mit Liste der fehlenden Fotos. |
| E7 | Löschen einer Galerie: sofort, oder auch über den Papierkorb? | **Sofort mit Rückfrage**; Fotos bleiben in der Mediathek, es geht nur die Zusammenstellung (Titel, Text, Reihenfolge) verloren. |

## 5. Bauplan nach Freigabe

1. Migration 3 + Tests (Bestand mit Fotos, doppelter Lauf).
2. Dienstklassen `Media/Galleries`, `Media/Catalog` (Suche, Mehrfachbearbeitung, Papierkorb, Aufräumen) mit Tests.
3. Controller und Vorlagen, Skript (`gallery.js`: Ziehen mit Tastatur-Ersatz „nach oben/unten“, Mehrfachauswahl); Seiten funktionieren ohne Skript für Formulare.
4. Entwurf der Oberfläche im Browser ansehen (hell/dunkel, beide Designs), alle 389 Tests plus neue grün.
5. Commit und Push, Bericht. Nichts hochladen.

## 6. Gebaut (Stand 15.11.2026)

- Migration 3 wie in Abschnitt 3; vor jeder Migration auf Bestandsdaten legt `Database::open` eine Kopie `lichtkammer.sqlite.vor-schema-<alte Version>.bak` im Datenordner an (`VACUUM INTO`, auch im WAL-Betrieb stimmig).
- Dienste `Media/Galleries` und `Media/Catalog`, Seiten in `Admin/MediaPages` (Galerien, Galerieseite, Hinzufügen aus der Mediathek, Mediathek mit Suche und Filtern, Foto bearbeiten, Mehrfachbearbeitung, Papierkorb, Fotografen), Skript `public/assets/js/admin.js` (Ziehen, „alle auswählen“, Rückfrage). Ohne Skript bleiben Pfeile und Formulare nutzbar.
- Der Upload in eine Galerie legt die Verknüpfung `gallery_photos` an; die alten Spalten `photos.gallery_id`, `sort_order`, `photographer` werden nicht mehr benutzt.
- Papierkorb: Varianten werden sofort gelöscht, Wiederherstellen erzeugt sie neu (synchron in der Anfrage). Endgültiges Löschen nach 30 Tagen läuft beim Öffnen von Fotos oder Papierkorb.
- „Veröffentlicht“ ist in Schritt 4 nur die Markierung samt Alt-Text-Prüfung; die Seite erzeugt Schritt 5.
- Nicht gebaut (E5): Drehen, Fokuspunkt, Foto ersetzen.
- Tests: `tests/bildverwaltung.php` (127 Prüfungen: Migration auf Bestandsdaten, Zuordnung, Reihenfolge, Titelbild, Alt-Text-Pflicht, Mehrfach, Suche, Papierkorb samt 30-Tage-Löschung, Fotografen, Zugriff und CSRF, CSP).
