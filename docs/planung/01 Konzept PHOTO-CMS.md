# Photo-CMS: Konzept und Umsetzungsplan

> **Hinweis für Claude Code:** Zuerst die bestehende Media-DB analysieren und **Schritt 0** ausführen. Vor der Freigabe keine Implementierung und keinen Code schreiben.

## 1. Ziel

Ziel ist ein eigenes, schlankes Open-Source-Foto-CMS für ausschließlich eigene Bilder. Es soll:

- auf einem Homeserver in einem LXC-Container sowie auf all-inkl.com laufen;
- mit den dort verfügbaren Mitteln (PHP, SQLite, nur FTP/SSH) auskommen;
- keine Lizenzkosten verursachen;
- für eine nicht-technische Person einfach bedienbar sein;
- moderne, austauschbare Themes unterstützen;
- klassische Darstellungsformen für Fotos und Alben anbieten, ohne unnötige Komplexität.

Die technische Person kennt Claude Code und übernimmt Einrichtung, Wartung und Veröffentlichungen. Die zweite Person soll Projekte, Alben und Fotos ohne technische Kenntnisse verwalten können.

## 2. Kontext

Es gibt bereits eine SQLite-basierte Media-DB mit ungefähr 10.000 Alben, 2.000 Filmen, 800 Serien und 5.000 Büchern. Diese Datenbank darf auf keinen Fall beschädigt werden. Das Foto-CMS muss sich deshalb vorsichtig und nachvollziehbar an die vorhandene Struktur anbinden.

Die Zielumgebungen sind ein eigener Homeserver-LXC und all-inkl.com. Auf all-inkl stehen voraussichtlich PHP, SQLite sowie FTP/SSH zur Verfügung; Serverzugriff und installierbare Bildbibliotheken müssen vor der Umsetzung geprüft werden. Die Bedienoberfläche soll deutschsprachig, klar und fehlertolerant sein. Gewünscht sind klassische Ansichten wie Kacheln, Raster, Masonry, Justified Gallery, Slider, Vollbild und Lightbox.

## 3. Architektur

### Empfohlener Ansatz: Generator

Empfohlen wird ein Generator-Ansatz:

1. **Admin und Build auf dem Homeserver:** Projekte, Alben, Metadaten und Uploads werden dort verwaltet und verarbeitet.
2. **Bildverarbeitung:** libvips oder Imagick erzeugt AVIF-, WebP- und JPEG-Varianten, Thumbnails und Blur-Placeholder; EXIF-Daten werden ausgelesen.
3. **Statischer Output:** Der Generator schreibt eine vollständige Website nach `dist/`.
4. **Veröffentlichung:** Nur geänderte Dateien werden per rsync oder SFTP nach all-inkl übertragen.
5. **Optionales PHP-Kontaktformular:** Falls benötigt, bleibt nur das Formular als kleines serverseitiges PHP-Modul dynamisch.

**Vorteile:** Die öffentliche Seite ist schnell, sicher und ressourcenschonend. Es gibt keine Datenbankabfragen und keine Bildverarbeitung auf dem Webspace. Der Upload der gesamten Website ist nicht nötig, weil inkrementell veröffentlicht wird.

**Nachteil:** Änderungen werden erst nach dem Build und Veröffentlichen sichtbar. Dafür wird eine lokale oder serverseitige Vorschau vor dem Veröffentlichen vorgesehen.

### Alternative: Gemeinsamer PHP-Code

Als Alternative kann derselbe PHP-Code auf Homeserver und all-inkl laufen. Bilder würden bei Bedarf per Lazy-Thumbnailing verarbeitet. Das vereinfacht möglicherweise die Veröffentlichung, benötigt aber mehr Laufzeit, Datenbankzugriff und Sicherheitskonfiguration im öffentlichen Betrieb.

### Hybrid-Fall

Für passwortgeschützte Kundengalerien, Downloads, ZIP-Erzeugung oder Wasserzeichen kann ein Hybrid sinnvoll sein: Die öffentliche Präsentation bleibt statisch, während geschützte Bereiche und Aktionen über PHP mit Authentifizierung bereitgestellt werden.

**Entscheidung durch Claude Code in Schritt 0:** Die bestehende Media-DB, die verfügbaren PHP-Versionen/Bildbibliotheken, Ressourcen und Anforderungen an geschützte Galerien sind zuerst zu prüfen. Danach ist der Architekturvorschlag zu bestätigen, bevor Code entsteht.

## 4. Anbindung an die Media-DB

Die bestehende Media-DB wird nur lesend analysiert und darf in ihren vorhandenen Tabellen unverändert bleiben.

Grundsätze:

- Keine doppelte Pflege bereits vorhandener Medienmetadaten.
- Neue Foto-CMS-Tabellen können in derselben SQLite-Datei angelegt werden, sofern die Analyse das sicher zulässt; andernfalls wird eine eigene SQLite-Datei verwendet.
- Bestehende Tabellen, Spalten, Indizes und Beziehungen werden nicht umbenannt, gelöscht oder inhaltlich verändert.
- Eine Migration erfolgt erst nach einem verifizierten Backup der gesamten Datenbank.
- Neue Tabellen erhalten ein eindeutiges Präfix oder einen eindeutig dokumentierten Namensraum, falls dies zur Vermeidung von Kollisionen nötig ist.
- Pfade und IDs aus der Media-DB werden referenziert statt kopiert, wo dies möglich ist.
- Jede Migration ist reversibel oder besitzt einen klar dokumentierten Rollback.

Vor jeder Migration müssen Schema, Tabellenbelegung, Schreibrechte, Dateipfade, Zeichencodierung und Backup-/Restore-Verfahren dokumentiert werden.

## 5. Stack

- **Backend:** PHP, möglichst kompatibel mit der niedrigsten in beiden Zielumgebungen verfügbaren Version.
- **Datenbank:** SQLite mit PDO und vorbereiteten Statements.
- **Bildverarbeitung:** libvips bevorzugt, Imagick als Alternative, GD als Fallback.
- **Frontend:** Vanilla-JavaScript, HTML und CSS; kein SPA-Framework.
- **Admin:** leichtgewichtige serverseitige PHP-Oberfläche, ergänzt um htmx oder Alpine.js, wo es die Bedienung vereinfacht.
- **Bilder:** AVIF und WebP mit JPEG-Fallback, responsive `srcset` und `sizes`.
- **Deployment:** `dist/` per FTP/SFTP oder rsync; SSH wird für Build, Wartung und Rollback genutzt.

Der Stack soll bewusst klein bleiben. Abhängigkeiten werden nur aufgenommen, wenn sie auf Homeserver und all-inkl realistisch installierbar oder ohne Build-Schritt auslieferbar sind.

## 6. Datenmodell – SQL-Entwurf

Der folgende Entwurf ist ein Ausgangspunkt. Vor der Implementierung muss er gegen die bestehende Media-DB und die in Schritt 0 festgelegte Architektur geprüft werden.

```sql
CREATE TABLE photo_collection (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    slug TEXT NOT NULL UNIQUE,
    title TEXT NOT NULL,
    description TEXT,
    cover_photo_id INTEGER,
    sort_order INTEGER NOT NULL DEFAULT 0,
    published INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE photo_album (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    collection_id INTEGER NOT NULL,
    parent_id INTEGER,
    slug TEXT NOT NULL,
    title TEXT NOT NULL,
    description TEXT,
    layout TEXT NOT NULL DEFAULT 'grid'
        CHECK (layout IN ('grid', 'masonry', 'justified', 'slider')),
    theme TEXT,
    cover_photo_id INTEGER,
    sort_order INTEGER NOT NULL DEFAULT 0,
    published INTEGER NOT NULL DEFAULT 0,
    UNIQUE (collection_id, parent_id, slug)
);

CREATE TABLE photo (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    album_id INTEGER NOT NULL,
    file_path TEXT NOT NULL,
    title TEXT,
    alt_text TEXT NOT NULL,
    caption TEXT,
    width INTEGER,
    height INTEGER,
    exif_json TEXT,
    taken_at TEXT,
    sort_order INTEGER NOT NULL DEFAULT 0,
    blur_hash TEXT
);

CREATE TABLE photo_tag (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    slug TEXT NOT NULL UNIQUE,
    title TEXT NOT NULL UNIQUE
);

CREATE TABLE photo_tag_map (
    photo_id INTEGER NOT NULL,
    tag_id INTEGER NOT NULL,
    PRIMARY KEY (photo_id, tag_id)
);

CREATE TABLE page (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    slug TEXT NOT NULL UNIQUE,
    title TEXT NOT NULL,
    content_md TEXT NOT NULL DEFAULT '',
    in_menu INTEGER NOT NULL DEFAULT 0,
    sort_order INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE site_setting (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
);
```

`alt_text` ist ein Pflichtfeld und muss im Admin sichtbar markiert werden. Fremdschlüssel, Indizes, Zeitstempel, Benutzer/Rollen und Migrationsmetadaten werden nach der Analyse ergänzt, ohne die bestehende Media-DB zu beschädigen.

## 7. Layout-Typen

| Layout/Element | Verwendung |
|---|---|
| Kategorie-Einstieg | Kacheln mit Hover-Effekt als Einstieg in Kollektionen oder Alben |
| Grid | Gleichmäßiges responsives Raster für allgemeine Galerien |
| Masonry | Unregelmäßiges Raster, das unterschiedliche Bildhöhen sichtbar lässt |
| Justified | Zeilen mit einheitlicher Höhe und variabler Breite |
| Slider/Fullscreen | Große Einzelbilder mit Navigation, optional als Vollbild |
| Hero-Block | Vollbreiter Einstieg mit einem Titelbild und Text |
| Lightbox | Großansicht mit Navigation, EXIF-Daten und Caption |

Alle Bildlisten sollen Blur-Placeholder und Lazy Loading verwenden. Die Auslieferung nutzt `srcset` und `sizes` mit AVIF-, WebP- und JPEG-Varianten. Ohne JavaScript soll zumindest eine brauchbare Einzelbildansicht erreichbar bleiben.

## 8. Theme-System

Themes liegen unter:

```text
themes/<name>/
├── theme.json
├── templates/
│   ├── home.php
│   ├── collection.php
│   ├── album.php
│   ├── photo.php
│   └── page.php
└── assets/
    ├── css/
    ├── js/
    └── images/
```

`theme.json` enthält mindestens Name, Autor, Version und Optionen für Farben, Schriften, Abstände sowie Layout-Defaults. Templates werden für Startseite, Kollektion, Album, Einzelbild und allgemeine Seite bereitgestellt. Das Admin-UI zeigt die im Theme definierten Optionen als verständliche Formularfelder an; Theme-spezifische Werte dürfen nicht hart in der Anwendung verdrahtet sein.

### Start-Themes

- **Studio:** Kategorie-Kacheln, gemischtes Raster und Vollbreite-Hero-Blöcke.
- **Minimal:** ruhige Typografie, viel Weißraum und zurückhaltende Navigation.
- **Editorial:** bildstarke Seiten mit Text-/Bild-Kompositionen und redaktionellem Charakter.
- **Archiv:** Filter nach Tags und Jahr, sachliche Informationsdarstellung und strukturierte Navigation.

`krentzphotography.com` dient als Designreferenz für Prinzipien wie klare Bildhierarchie, großzügige Darstellung und einfache Navigation. Die Referenz wird nicht im Detail kopiert; Gestaltung, Texte, Assets und Code bleiben eigenständig.

## 9. Bildpipeline

Die Pipeline arbeitet sicher, wiederholbar und inkrementell:

1. Originaldatei übernehmen und niemals überschreiben.
2. Dateityp, Größe und Inhalt validieren; keine vertrauenswürdige Verarbeitung allein aufgrund der Dateiendung.
3. EXIF-Daten auslesen und die Orientierung korrekt anwenden.
4. Varianten mit Breiten von 400, 800, 1600 und 2400 Pixeln erzeugen.
5. Jede Variante als AVIF, WebP und JPEG bereitstellen, soweit die Bibliothek dies unterstützt.
6. Einen Blur-Placeholder beziehungsweise Blur-Hash erzeugen.
7. EXIF und normalisierte Metadaten in der Datenbank speichern; sensible Daten später konfigurierbar ausblenden.
8. Ausgabedateien deterministisch benennen und mit Hash/Quellmetadaten verknüpfen.
9. Bei unveränderter Quelle nichts erneut verarbeiten; bei geänderter Quelle nur betroffene Varianten aktualisieren.
10. Veraltete Varianten kontrolliert und nachvollziehbar entfernen.

Die Verarbeitung muss idempotent sein: Mehrfaches Ausführen erzeugt kein Datenchaos und verändert unveränderte Originale nicht. Fehler einzelner Bilder werden protokolliert, ohne den gesamten Batch unverständlich abzubrechen.

## 10. Admin-UI

Die Bedienung folgt dem Ziel **maximal drei Klicks pro häufige Aufgabe**. Das Dashboard bietet prominent:

- **Neues Projekt**
- **Fotos hochladen**
- **Veröffentlichen**

Erforderliche Funktionen:

- Drag-and-drop-Upload und manuelle Dateiauswahl.
- Drag-and-drop-Sortierung von Fotos und Alben.
- Titelbild per Klick festlegen.
- Pflichtfeld `Alt-Text` deutlich markieren und vor Veröffentlichung prüfen.
- Titel, Caption, Beschreibung, Tags und optionale EXIF-Anzeige bearbeiten.
- Live- oder lokale Vorschau vor dem Veröffentlichen.
- Klarer Veroeffentlichen-Button mit Status, Fortschritt und Fehlermeldungen.
- Deutsche, verständliche Fehlermeldungen ohne technische Stacktraces.
- Login mit den Rollen **Admin** und **Redakteur**; Rechte werden minimal vergeben.

Upload-Limits, Bildfortschritt, Tastaturbedienung, Fokuszustände und verständliche Bestätigungen gehören zur Grundfunktion. Das Admin-UI soll auch bei vielen Fotos übersichtlich bleiben.

## 11. Deployment

Der Generator schreibt ausschließlich in `dist/`. Für die Veröffentlichung werden nur geänderte Dateien per rsync oder SFTP/SSH nach all-inkl übertragen.

Vorgaben:

- Zugangsdaten und Tokens liegen in `.env` beziehungsweise in einer sicheren Umgebung und niemals im Repository.
- Das Repository enthält eine `.env.example` ohne echte Geheimnisse.
- Vor jedem Deployment wird ein Build-Artefakt beziehungsweise eine Release-ID protokolliert.
- Das Ziel erhält eine nachvollziehbare Versionsdatei.
- Rollback auf die vorherige Release-Version muss möglich sein.
- Ein Dry-Run und eine lokale Vorschau werden unterstützt.
- Uploads und generierte Dateien werden getrennt von Quellcode und Konfiguration behandelt.
- Optional werden ein LXC-Setup und ein Docker-Compose-Beispiel dokumentiert; der Betrieb darf nicht zwingend von Docker abhängen.

## 12. Roadmap

### Phase 1 – MVP

- Datenmodell und sichere Migrationen.
- Admin-Grundgerüst mit Login und Rollen.
- Upload und idempotente Bildpipeline.
- Layouts Kategorie-Einstieg, Justified und Grid mit Lightbox.
- Studio-Theme.
- Seiten About, Kontakt und Impressum.
- Build und Deployment nach `dist/`.
- Veröffentlichung und Rollback.

### Phase 2 – Ausbau

- Theme-Konfigurations-UI.
- Drei weitere Themes: Minimal, Editorial und Archiv.
- Vorschau vor Veröffentlichung.
- Tags, Filter und EXIF-Anzeige.
- Kartenansicht, sofern belastbare Ortsdaten vorhanden sind.
- Mehrsprachigkeit.
- Masonry und Slider.

### Phase 3 – Erweiterungen

- Passwortgeschützte Kundengalerien.
- ZIP-Downloads und Wasserzeichen.
- WordPress-Import.
- Hooks beziehungsweise Erweiterungspunkte für Integrationen.

## 13. Open Source

Die Codebasis wird in klar getrennte Bereiche aufgeteilt:

- **Core:** Datenmodell, Konfiguration, Routing und gemeinsame Dienste.
- **Admin:** Authentifizierung, Rollen und Bearbeitungsoberfläche.
- **Generator:** Build, Bildpipeline und `dist/`-Erzeugung.
- **Themes:** unabhängig austauschbare Themes und deren Assets.

Als Lizenz wird MIT oder AGPL gewählt; die Entscheidung wird in Schritt 0 anhand des gewünschten Beitrags- und Verteilungsmodells bestätigt. Das Repository enthält:

- ein README für Installation und Betrieb auf LXC und all-inkl;
- ein Demo-Theme mit Beispieldaten ohne private Bilder;
- eine gepflegte `CLAUDE.md` mit Architekturregeln, Sicherheitsvorgaben und Arbeitsablauf;
- Migrationen, Tests und Release-Hinweise;
- keine privaten Fotos, Zugangsdaten, EXIF-Geheimnisse oder sonstige personenbezogene Daten.

## 14. Offene Fragen

Vor der Implementierung sind mindestens diese Fragen zu klären:

1. Wie ist die bestehende Media-DB aufgebaut, welche Tabellen und IDs gibt es, und wie werden Medienpfade gespeichert?
2. Welche PHP-Version und welche Bildbibliotheken sind bei all-inkl verfügbar? Sind SSH, rsync, Composer und Cron nutzbar?
3. Welche Ressourcen stehen im Homeserver-LXC zur Verfügung (CPU, RAM, Speicher, Backup und Erreichbarkeit)?
4. Soll der öffentliche Betrieb vollständig statisch sein oder werden geschützte/individuelle Bereiche direkt als Hybrid umgesetzt?
5. Welche weiteren Referenzseiten beschreiben gewünschte Navigation, Typografie oder Galerieabläufe?
6. Welche EXIF-Felder dürfen öffentlich erscheinen, und welche müssen entfernt oder verborgen werden?
7. Welche Benutzer- und Backup-Regeln gelten für Admin, Redakteur und Originaldateien?

## 15. Prompt-Plan für Claude Code

Jeder Schritt wird einzeln beauftragt. Claude Code berichtet zuerst über Analyse und Tests; der nächste Schritt beginnt erst nach Freigabe.

### Schritt 0 – Analyse und Architekturvorschlag

**Prompt:** Analysiere die bestehende Media-DB, Verzeichnisstruktur, PHP-/Serverumgebungen, Bildbibliotheken, Zugriffswege, Ressourcen und Sicherheitsanforderungen. Schreibe noch keinen Code. Vergleiche Generator, gemeinsames PHP und Hybrid und lege einen begründeten Architekturvorschlag vor.

**Akzeptanzkriterium:** Ein nachvollziehbarer Analysebericht mit Schemaübersicht, Risiken, Backupplan, offenen Fragen und bestätigter Architektur liegt vor; es wurde kein Anwendungscode verändert.

### Schritt 1 – Projektgerüst

**Prompt:** Erzeuge nach Freigabe ein minimales Projektgerüst mit Core, Admin, Generator, Themes, Tests, `CLAUDE.md`, `.env.example` und gewählter Open-Source-Lizenz.

**Akzeptanzkriterium:** Das Gerüst lässt sich lokal starten, enthält keine Geheimnisse, dokumentiert Regeln in `CLAUDE.md` und besteht einen ersten Smoke-Test.

### Schritt 2 – Datenmodell und Migrationen

**Prompt:** Implementiere die Foto-CMS-Tabellen und Migrationen getrennt von der bestehenden Media-DB. Erstelle vor jeder Migration ein verifiziertes Backup und dokumentiere Rollback und Wiederherstellung.

**Akzeptanzkriterium:** Migrationen sind reproduzierbar, nur neue Tabellen werden angelegt, bestehende Tabellen bleiben unverändert, und Backup/Restore wurde testweise geprüft.

### Schritt 3 – Bildpipeline

**Prompt:** Implementiere die sichere, idempotente Pipeline mit Originalschutz, EXIF/Orientierung, Varianten 400/800/1600/2400, AVIF/WebP/JPEG, Blur-Placeholder und inkrementeller Verarbeitung.

**Akzeptanzkriterium:** Ein Testbild erzeugt die erwarteten Varianten; ein zweiter Lauf verändert unveränderte Dateien nicht; fehlerhafte oder nicht erlaubte Uploads werden verständlich abgewiesen.

### Schritt 4 – Admin-Grundgerüst

**Prompt:** Baue Login, Rollen, Dashboard und die grundlegenden deutschen Admin-Komponenten für Admin und Redakteur.

**Akzeptanzkriterium:** Anmeldung, Abmeldung und Rechteprüfung funktionieren; das Dashboard zeigt die Aktionen Neues Projekt, Fotos hochladen und Veröffentlichen; unberechtigte Zugriffe werden blockiert.

### Schritt 5 – Projekte, Upload, Sortierung und Alt-Text

**Prompt:** Implementiere Projekte/Kollektionen, Alben, Upload, Drag-and-drop-Sortierung, Titelbildauswahl, Metadaten und die sichtbare Alt-Text-Pflicht.

**Akzeptanzkriterium:** Ein Album mit 20 Fotos kann eine nicht-technische Person in unter 5 Minuten anlegen, hochladen, sortieren, mit Alt-Text versehen und für die Vorschau vorbereiten.

### Schritt 6 – Generator und Studio-Theme

**Prompt:** Implementiere Build nach `dist/`, responsive Bildauslieferung mit `srcset`, Kategorie-Einstieg, Justified/Grid, Lightbox und das Studio-Theme.

**Akzeptanzkriterium:** `dist/` ist lokal ohne laufende Datenbank öffnbar, alle Testbilder werden korrekt dargestellt, Links funktionieren, und ein Lighthouse-Lauf ist für Performance, Accessibility und Best Practices solide.

### Schritt 7 – Seiten und Navigation

**Prompt:** Ergänze Seitenverwaltung und Navigation für About, Kontakt und Impressum sowie das optionale PHP-Kontaktformular.

**Akzeptanzkriterium:** Seiten sind im Admin bearbeitbar, in der Navigation korrekt verknüpft, mobil lesbar und das Kontaktformular validiert Eingaben ohne Geheimnisse zu protokollieren.

### Schritt 8 – Vorschau, Deploy und Rollback

**Prompt:** Implementiere Vorschau, Release-IDs, inkrementelles rsync/SFTP-Deployment und einen dokumentierten Rollback.

**Akzeptanzkriterium:** Ein Dry-Run zeigt nur Änderungen, ein Testdeployment überträgt nur geänderte Dateien, die Vorschau ist erreichbar und ein Rollback stellt die vorherige Release-Version wieder her.

### Schritt 9 – Härtung

**Prompt:** Führe eine Sicherheitsprüfung durch und ergänze CSRF-Schutz, sichere Session-Konfiguration, Upload-Validierung, Rechteprüfung, Ausgabe-Escaping, Rate-Limits wo nötig und geprüfte Backups.

**Akzeptanzkriterium:** Sicherheits- und Negativtests decken CSRF, manipulierte Uploads, unberechtigte Admin-Aktionen, Pfad-Traversal und XSS ab; die Ergebnisse und verbleibenden Risiken sind dokumentiert.

### Schritt 10 – Dokumentation, Demo und Release

**Prompt:** Vervollständige README für LXC und all-inkl, Betriebs- und Backup-Dokumentation, Demo-Theme, Beispieldaten, `CLAUDE.md`, Changelog und ein reproduzierbares Release.

**Akzeptanzkriterium:** Eine dritte Person kann die Demo ohne private Daten installieren, ein Foto veröffentlichen, ein Rollback ausführen und die Architektur verstehen; Lizenz, Version, Tests und bekannte Einschränkungen sind dokumentiert.
