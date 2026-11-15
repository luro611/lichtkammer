# Schritt 3: Datenmodell und Bildpipeline

Stand: 15.11.2026. Gebaut (Version 0.1.0-dev), Risiko laut Plan 03 „mittel“, daher ohne eigenen Vorentwurf. Nummerierung: Plan `03` (Abschnitt 11) ist maßgeblich. Das Ausgangskonzept `01` zählt anders (dort ist Schritt 3 nur die Bildpipeline, Anmeldung gibt es nicht).

## Was es kann

- **Schema, Migration 2** (zu den Anmeldetabellen hinzu, nichts Bestehendes geändert): `galleries`, `photos`, `photo_variants`, `uploads`. Galerien haben noch keine Oberfläche (Schritt 4), Fotos können schon ohne Galerie (`gallery_id` leer) hochgeladen werden.
- **Upload in Stücken** (`Media/Uploads.php`, Seite `/admin/fotos`, `public/assets/js/upload.js`): start, Stücke zu je 2 MB der Reihe nach, finish; danach stößt der Browser die Verarbeitung je Foto an. Falscher Versatz liefert 409 mit dem richtigen Stand (Fortsetzen nach Abbruch). Teilstücke liegen im Datenordner und werden nach 24 Stunden aufgeräumt. Fällt ein Bild aus, laufen die anderen weiter.
- **Prüfung am Inhalt** (`Media/Library::ingest`): erlaubt JPEG, PNG, WebP, HEIC (HEIC nur, wenn die Bibliothek es liest), Größe bis `LK_MAX_UPLOAD_MB` (Standard 100), höchstens 100 Megapixel. PHP, SVG, GIF, Text und beschädigte Bilder werden mit deutscher Meldung abgewiesen.
- **Originalschutz:** Zufallsname unter `originals/<xx>/…`, mit Modus `x` angelegt (überschreibt nie), Rechte 0600, Ordner mit `.htaccess` gesperrt. Auch der erzwungene Neulauf verändert das Original nicht (getestet).
- **Dublettenprüfung:** SHA-256 über das Original, eindeutig in der Datenbank; gemeldet, auch wenn das Foto im Papierkorb liegt (`deleted_at` ist vorbereitet).
- **Varianten:** Breiten 480, 960, 1600, 2400, dazu die Originalbreite, wenn sie dazwischen liegt (nie vergrößern; Bild unter 480 px bekommt nur die eigene Breite). Formate JPEG und WebP, AVIF nur, wenn die Bibliothek es wirklich kodiert (Probekodierung zur Laufzeit). Dateien unter `media/<xx>/<Kennung>-<Breite>.<Endung>`; die Kennung ist zufällig, nicht aus dem Dateinamen abgeleitet. Atomar geschrieben (temporäre Datei, dann umbenennen).
- **Ausrichtung** (EXIF) wird angewendet, **alle Metadaten (EXIF, GPS, Profile) fehlen in jeder Variante** (getestet mit eingebautem GPS-Segment, mit Imagick und GD).
- **EXIF in der Datenbank:** nur Kamera, Objektiv, Blende, Belichtungszeit, ISO, Brennweite, Aufnahmedatum (`exif_json`, `taken_at`, `year`). GPS-Koordinaten werden nie gespeichert, nur `has_gps` als Hinweis.
- **Unschärfe-Platzhalter:** 20-px-JPEG als Daten-Adresse in `photos.blur_data_uri` (unter 3 KB).
- **Idempotent:** zweiter Lauf meldet `unchanged`, fasst keine Datei an; fehlt eine Variante, wird nur sie ersetzt; veraltete Varianten werden mit Datei und Zeile entfernt; `pipeline_version` für spätere Änderungen.
- **Bildbibliothek:** Imagick (Normalfall), GD als Ersatz (ohne HEIC). Beide mit denselben Tests geprüft.
- **Protokoll:** Ereignis `photo_uploaded` (nur Foto-Nummer) in der Protokollkette.

## Bewusst nicht in diesem Schritt

Galerien-Oberfläche, Bearbeiten von Titel/Alt-Text, Sortieren, Papierkorb-Bedienung, Suche (Schritt 4); Drehen und Fokuspunkt (Spalten `rotation`, `focus_x`, `focus_y` sind angelegt); öffentliche Seiten (Schritt 5).

## Offen, für den Testserver zu prüfen

- **AVIF und HEIC:** Im Testimage kodiert Imagick kein AVIF (nur JPEG und WebP). Auf all-inkl meldet die Bestandsaufnahme AVIF/HEIC als registriert; ob Kodieren und Lesen wirklich gehen, zeigt die Fotoseite („Verarbeitung mit Imagick (jpeg, webp, …)“). Fehlt AVIF, bleibt es bei JPEG und WebP.
- **Speichergrenze:** Verarbeitung eines 24-MP-Fotos auf all-inkl (512 MB, 60 s) mit einem echten Bild messen.
- **Öffentliche URL unveröffentlichter Fotos:** Varianten liegen in `media/` und sind per Kennung (24 Hex-Zeichen, nicht erratbar) abrufbar, auch wenn das Foto noch in keiner veröffentlichten Galerie ist. Für den Entwurfsbetrieb in Schritt 5 (Vorschau, Veröffentlichen) zu entscheiden: Varianten erst beim Veröffentlichen sichtbar machen oder Entwürfe über PHP ausliefern.
- **Upload-Grenze:** Stücke zu 2 MB liegen weit unter `post_max_size`; `max_file_uploads` spielt keine Rolle, da kein Formular-Upload.
